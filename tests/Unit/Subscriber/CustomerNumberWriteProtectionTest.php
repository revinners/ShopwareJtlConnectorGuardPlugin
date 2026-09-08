<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Subscriber;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\AddressGuard;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSource;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSourceDetector;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerState;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuard;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\IdentityGuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Subscriber\CustomerNumberWriteProtection;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CustomerNumberWriteProtectionTest extends TestCase
{
    private const INTEGRATION_ID = '2103c0f8ba934cbdb291287aaa3b5ce8';

    private EntityDefinition $definition;
    private EntityDefinition $addressDefinition;
    private GuardConfigProvider&MockObject $configProvider;
    private ConnectorSourceDetector&MockObject $detector;
    private CustomerStateLoader&MockObject $stateLoader;
    private NumberRangeValueGeneratorInterface&MockObject $numberRange;
    private GuardLogger&MockObject $guardLogger;
    private FieldGuard&MockObject $fieldGuard;
    private AddressGuard&MockObject $addressGuard;
    private LoggerInterface&MockObject $logger;
    private LoggerInterface&MockObject $fallbackLogger;
    private CustomerNumberWriteProtection $subscriber;
    private Context $connectorContext;

    protected function setUp(): void
    {
        $registry = new StaticDefinitionInstanceRegistry(
            [CustomerTestDefinition::class, CustomerAddressTestDefinition::class],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGatewayInterface::class),
        );
        $this->definition = $registry->getByEntityName('customer');
        $this->addressDefinition = $registry->getByEntityName('customer_address');

        $this->configProvider = $this->createMock(GuardConfigProvider::class);
        $this->detector = $this->createMock(ConnectorSourceDetector::class);
        $this->stateLoader = $this->createMock(CustomerStateLoader::class);
        $this->numberRange = $this->createMock(NumberRangeValueGeneratorInterface::class);
        $this->guardLogger = $this->createMock(GuardLogger::class);
        $this->fieldGuard = $this->createMock(FieldGuard::class);
        $this->addressGuard = $this->createMock(AddressGuard::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->fallbackLogger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new CustomerNumberWriteProtection(
            $this->configProvider,
            $this->detector,
            $this->stateLoader,
            $this->numberRange,
            $this->guardLogger,
            $this->fieldGuard,
            $this->addressGuard,
            $this->logger,
            $this->fallbackLogger,
        );

        $this->connectorContext = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));
    }

    // ---- helpers -----------------------------------------------------------

    private function config(bool $enforce, bool $enabled = true, array $protected = ['customer_number'], ?IdentityGuardConfig $identity = null, ?FieldGuardConfig $fieldGuard = null): GuardConfig
    {
        return new GuardConfig($enabled, $enforce, ['JTL-Connector'], [], $protected, $identity ?? IdentityGuardConfig::disabled(), $fieldGuard ?? FieldGuardConfig::disabled());
    }

    private function identity(bool $enforce, string $protectName = IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP, bool $enabled = true): IdentityGuardConfig
    {
        return new IdentityGuardConfig($enabled, $enforce, $protectName);
    }

    private function fieldGuard(bool $enforce, bool $enabled = true): FieldGuardConfig
    {
        return new FieldGuardConfig($enabled, $enforce, ['customer_group_id'], ['anmerkung', 'hinweis_(intern)'], FieldGuardConfig::POLICY_LOG);
    }

    private function connectorDetected(): void
    {
        $this->detector->method('resolve')->willReturn(new ConnectorSource(self::INTEGRATION_ID, 'JTL-Connector'));
    }

    /**
     * @param array<string, mixed> $payload storage-name keyed, DB-encoded
     */
    private function update(string $idHex, array $payload): UpdateCommand
    {
        $pk = ['id' => Uuid::fromHexToBytes($idHex)];

        // existing row: exists = true (EntityExistence::createForEntity() would mean "does not exist yet")
        $existence = new EntityExistence('customer', ['id' => $idHex], true, false, false, []);

        return new UpdateCommand($this->definition, $payload, $pk, $existence, '/0');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insert(string $idHex, array $payload): InsertCommand
    {
        $pk = ['id' => Uuid::fromHexToBytes($idHex)];

        return new InsertCommand($this->definition, ['id' => $pk['id']] + $payload, $pk, EntityExistence::createForEntity('customer', ['id' => $idHex]), '/0');
    }

    private function jsonUpdate(string $idHex, array $payload): JsonUpdateCommand
    {
        $existence = new EntityExistence('customer', ['id' => $idHex], true, false, false, []);

        return new JsonUpdateCommand($this->definition, 'custom_fields', $payload, ['id' => Uuid::fromHexToBytes($idHex)], $existence, '/0');
    }

    private function addressUpdate(string $idHex, array $payload): UpdateCommand
    {
        $existence = new EntityExistence('customer_address', ['id' => $idHex], true, false, false, []);

        return new UpdateCommand($this->addressDefinition, $payload, ['id' => Uuid::fromHexToBytes($idHex)], $existence, '/0/addresses/0');
    }

    /**
     * @param list<WriteCommand> $commands
     */
    private function event(array $commands, ?Context $context = null): EntityWriteEvent
    {
        return EntityWriteEvent::create(WriteContext::createFromContext($context ?? $this->connectorContext), $commands);
    }

    private function state(string $idHex, string $number, string $salesChannelHex, array $extra = []): CustomerState
    {
        return new CustomerState($idHex, $extra + [
            'id' => Uuid::fromHexToBytes($idHex),
            'customer_number' => $number,
            'email' => 'erdoesi@example.com',
            'first_name' => 'Adam',
            'last_name' => 'Erdösi',
            'sales_channel_id' => Uuid::fromHexToBytes($salesChannelHex),
        ]);
    }

    // ---- tests -------------------------------------------------------------

    public function testSubscribesToEntityWriteEvent(): void
    {
        self::assertSame([EntityWriteEvent::class => 'onEntityWrite'], CustomerNumberWriteProtection::getSubscribedEvents());
    }

    public function testIgnoresWritesWithoutCustomerCommands(): void
    {
        $this->detector->expects(self::never())->method('resolve');
        $this->guardLogger->expects(self::never())->method('log');

        $this->subscriber->onEntityWrite($this->event([]));
    }

    public function testNonConnectorWriteIsLeftUntouched(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->detector->method('resolve')->willReturn(null);
        $this->stateLoader->expects(self::never())->method('load');
        $this->guardLogger->expects(self::never())->method('log');

        $id = Uuid::randomHex();
        $cmd = $this->update($id, ['customer_number' => '10009']);
        $this->subscriber->onEntityWrite($this->event([$cmd], Context::createDefaultContext(new AdminApiSource(Uuid::randomHex(), null))));

        self::assertSame('10009', $cmd->getPayload()['customer_number']);
    }

    public function testDisabledPluginDoesNothing(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, enabled: false));
        $this->detector->expects(self::never())->method('resolve');
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->update(Uuid::randomHex(), ['customer_number' => '10009']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('10009', $cmd->getPayload()['customer_number']);
    }

    public function testEnforceBlocksNumberChangeOnUpdateButKeepsOtherFields(): void
    {
        $id = Uuid::randomHex();
        $sc = Uuid::randomHex();
        $group = Uuid::randomBytes();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->connectorDetected();
        $this->stateLoader->method('load')->with([Uuid::fromHexToBytes($id)])->willReturn([$id => $this->state($id, 'C10009', $sc)]);
        $this->guardLogger->expects(self::once())->method('log')->with(self::callback(
            static fn (GuardLogEntry $e): bool => $e->action === GuardLogEntry::ACTION_BLOCKED_UPDATE
                && $e->mode === 'enforce'
                && $e->field === 'customer_number'
                && $e->customerId === $id
                && $e->email === 'erdoesi@example.com'
                && $e->firstName === 'Adam'
                && $e->lastName === 'Erdösi'
                && $e->currentValue === 'C10009'
                && $e->attemptedValue === '10009'
                && $e->assignedValue === null
                && $e->integrationId === self::INTEGRATION_ID
                && $e->integrationLabel === 'JTL-Connector'
                && $e->salesChannelId === $sc
        ));

        $cmd = $this->update($id, ['customer_number' => '10009', 'customer_group_id' => $group]);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('C10009', $cmd->getPayload()['customer_number'], 'number reverted to the current value');
        self::assertSame($group, $cmd->getPayload()['customer_group_id'], 'other fields untouched');
    }

    public function testLogOnlyRecordsButDoesNotChangeTheUpdate(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->guardLogger->expects(self::once())->method('log')->with(self::callback(
            static fn (GuardLogEntry $e): bool => $e->action === GuardLogEntry::ACTION_BLOCKED_UPDATE && $e->mode === 'log_only'
        ));

        $cmd = $this->update($id, ['customer_number' => '10009']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('10009', $cmd->getPayload()['customer_number']);
    }

    public function testUnchangedNumberIsNotLogged(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->update($id, ['customer_number' => 'C10009', 'first_name' => 'Adam']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('C10009', $cmd->getPayload()['customer_number']);
    }

    public function testUpdateWithoutProtectedFieldsIsNotLogged(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->update($id, ['customer_group_id' => Uuid::randomBytes()]);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertArrayNotHasKey('customer_number', $cmd->getPayload());
    }

    public function testExtraProtectedFieldIsBlockedTooAndLoggedPerField(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, protected: ['customer_number', 'email']));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = $e->field;
        });

        $cmd = $this->update($id, ['customer_number' => '10009', 'email' => 'new@example.com', 'last_name' => 'Neu']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame(['customer_number', 'email'], $logged);
        self::assertSame('C10009', $cmd->getPayload()['customer_number']);
        self::assertSame('erdoesi@example.com', $cmd->getPayload()['email']);
        self::assertSame('Neu', $cmd->getPayload()['last_name']);
    }

    public function testUsesTheCustomersSalesChannelForConfig(): void
    {
        $id = Uuid::randomHex();
        $sc = Uuid::randomHex();
        $this->configProvider->expects(self::exactly(2))->method('load')
            ->willReturnCallback(fn (?string $scId): GuardConfig => $this->config(enforce: $scId === $sc));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', $sc)]);
        $this->guardLogger->expects(self::once())->method('log');

        $cmd = $this->update($id, ['customer_number' => '10009']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('C10009', $cmd->getPayload()['customer_number'], 'per-sales-channel enforce applied');
    }

    public function testEnforceRemapsConnectorCreatedCustomerToTheShopsNumberRange(): void
    {
        $id = Uuid::randomHex();
        $sc = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->connectorDetected();
        $this->stateLoader->expects(self::never())->method('load');
        $this->numberRange->expects(self::once())->method('getValue')
            ->with('customer', $this->connectorContext, $sc)
            ->willReturn('100456');
        $this->guardLogger->expects(self::once())->method('log')->with(self::callback(
            static fn (GuardLogEntry $e): bool => $e->action === GuardLogEntry::ACTION_REMAPPED_CREATE
                && $e->mode === 'enforce'
                && $e->customerId === $id
                && $e->email === 'sauter@example.com'
                && $e->firstName === 'S'
                && $e->lastName === 'Sauter'
                && $e->currentValue === null
                && $e->attemptedValue === '51520'
                && $e->assignedValue === '100456'
                && $e->salesChannelId === $sc
        ));

        $cmd = $this->insert($id, [
            'customer_number' => '51520',
            'email' => 'sauter@example.com',
            'first_name' => 'S',
            'last_name' => 'Sauter',
            'sales_channel_id' => Uuid::fromHexToBytes($sc),
        ]);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('100456', $cmd->getPayload()['customer_number']);
        self::assertSame('sauter@example.com', $cmd->getPayload()['email']);
    }

    public function testLogOnlyDoesNotReserveANumberOnCreate(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false));
        $this->connectorDetected();
        $this->numberRange->expects(self::never())->method('getValue');
        $this->guardLogger->expects(self::once())->method('log')->with(self::callback(
            static fn (GuardLogEntry $e): bool => $e->action === GuardLogEntry::ACTION_REMAPPED_CREATE
                && $e->mode === 'log_only'
                && $e->assignedValue === null
                && $e->salesChannelId === null
        ));

        $cmd = $this->insert($id, ['customer_number' => '51520']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('51520', $cmd->getPayload()['customer_number']);
    }

    public function testInternalFailureNeverBreaksTheWrite(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('left untouched'), self::anything());
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->update(Uuid::randomHex(), ['customer_number' => '10009']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('10009', $cmd->getPayload()['customer_number']);
    }

    public function testUnidentifiedIntegrationWriteIsDebugLogged(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->detector->method('resolve')->willReturn(null);
        $this->logger->expects(self::once())->method('debug')->with(self::stringContains('not identified'), ['integrationId' => self::INTEGRATION_ID]);

        $this->subscriber->onEntityWrite($this->event([$this->update(Uuid::randomHex(), ['customer_number' => '1'])]));
    }

    /**
     * F1 regression: state loader throws AND the channel logger's error() call also throws
     * (the outer catch in onEntityWrite()). The write must still return normally, the payload
     * must stay untouched, and the fallback logger must receive the error exactly once.
     */
    public function testStateLoaderFailureAndChannelLoggerFailureBothFallBackToTheMainLogger(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects(self::once())->method('error')->willThrowException(new \RuntimeException('stream could not be opened'));
        $this->fallbackLogger->expects(self::once())->method('error')->with(self::stringContains('left untouched'), self::anything());
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->update(Uuid::randomHex(), ['customer_number' => '10009']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('10009', $cmd->getPayload()['customer_number'], 'payload untouched, both loggers failing must not corrupt the write');
    }

    /**
     * F1 regression: the "not identified as the connector" debug line must not be able to
     * throw into the write, even when the channel logger itself throws on debug().
     */
    public function testUnidentifiedIntegrationWriteWithThrowingChannelLoggerReturnsNormally(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->detector->method('resolve')->willReturn(null);
        $this->logger->method('debug')->willThrowException(new \RuntimeException('stream could not be opened'));
        $this->fallbackLogger->expects(self::once())->method('debug');

        $cmd = $this->update(Uuid::randomHex(), ['customer_number' => '1']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('1', $cmd->getPayload()['customer_number'], 'unidentified write left untouched even though both loggers were exercised');
    }

    public function testInternalFailureOnOneInsertDoesNotBreakTheOthersInTheBatch(): void
    {
        $id1 = Uuid::randomHex();
        $id2 = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->connectorDetected();

        $calls = 0;
        $this->numberRange->method('getValue')->willReturnCallback(static function () use (&$calls): string {
            ++$calls;
            if ($calls === 1) {
                throw new \RuntimeException('number range service down');
            }

            return '100456';
        });
        $this->guardLogger->expects(self::once())->method('log')->with(self::callback(
            static fn (GuardLogEntry $e): bool => $e->action === GuardLogEntry::ACTION_REMAPPED_CREATE
                && $e->assignedValue === '100456'
        ));
        $this->logger->expects(self::once())->method('error')->with(self::stringContains($id1), self::anything());

        $cmd1 = $this->insert($id1, ['customer_number' => '51520']);
        $cmd2 = $this->insert($id2, ['customer_number' => '51521']);
        $this->subscriber->onEntityWrite($this->event([$cmd1, $cmd2]));

        self::assertSame('51520', $cmd1->getPayload()['customer_number'], 'first insert keeps its supplied number after the failure');
        self::assertSame('100456', $cmd2->getPayload()['customer_number'], 'second insert is still remapped');
    }

    public function testInternalFailureOnOneUpdateDoesNotBreakTheOthersInTheBatch(): void
    {
        $id1 = Uuid::randomHex();
        $id2 = Uuid::randomHex();
        $sc = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([
            $id1 => $this->state($id1, 'C10001', $sc),
            $id2 => $this->state($id2, 'C10002', $sc),
        ]);

        $calls = 0;
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$calls): void {
            ++$calls;
            if ($calls === 1) {
                throw new \RuntimeException('log sink down');
            }
        });
        $this->logger->expects(self::once())->method('error')->with(self::stringContains($id1), self::anything());

        $cmd1 = $this->update($id1, ['customer_number' => '10001-x']);
        $cmd2 = $this->update($id2, ['customer_number' => '10002-x']);
        $this->subscriber->onEntityWrite($this->event([$cmd1, $cmd2]));

        self::assertSame('C10001', $cmd1->getPayload()['customer_number'], 'first update was already reverted before its log call failed');
        self::assertSame('C10002', $cmd2->getPayload()['customer_number'], 'second update is still neutralised and logged');
    }

    public function testLoggedValueIsRenderedByColumnNotByShape(): void
    {
        $id = Uuid::randomHex();
        $sc = Uuid::randomHex();
        $currentGroup = Uuid::randomBytes();
        $attemptedGroup = Uuid::randomBytes();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, protected: ['customer_number', 'last_name', 'customer_group_id']));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', $sc, [
            'last_name' => 'Schröder',
            'customer_group_id' => $currentGroup,
        ])]);

        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[$e->field] = $e;
        });

        $cmd = $this->update($id, [
            'last_name' => 'Schröder-Wagner', // 16 bytes, same length as a binary id, but a plain name
            'customer_group_id' => $attemptedGroup,
        ]);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('Schröder', $logged['last_name']->currentValue);
        self::assertSame('Schröder-Wagner', $logged['last_name']->attemptedValue, 'a 16-byte plain string is logged verbatim, not mistaken for a binary id');
        self::assertSame(Uuid::fromBytesToHex($currentGroup), $logged['customer_group_id']->currentValue);
        self::assertSame(Uuid::fromBytesToHex($attemptedGroup), $logged['customer_group_id']->attemptedValue, 'an *_id column is still rendered as hex');
    }

    // ---- feature 002: identity guard ---------------------------------------

    public function testIdentityGuardDisabledLeavesAnEmailSwapUntouched(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->update($id, ['email' => 'info@motorradgarage-dachau.de']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('info@motorradgarage-dachau.de', $cmd->getPayload()['email']);
    }

    public function testEnforceKeepsTheEmailAndAppliesTheRestOfTheWrite(): void
    {
        $id = Uuid::randomHex();
        $group = Uuid::randomBytes();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false, identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field, $e->currentValue, $e->attemptedValue, $e->mode];
        });

        $cmd = $this->update($id, ['email' => 'info@motorradgarage-dachau.de', 'customer_group_id' => $group]);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('erdoesi@example.com', $cmd->getPayload()['email'], 'email reverted to the current value');
        self::assertSame($group, $cmd->getPayload()['customer_group_id'], 'other fields untouched');
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'email', 'erdoesi@example.com', 'info@motorradgarage-dachau.de', 'enforce']], $logged);
    }

    public function testLogOnlyObservesTheEmailSwapButAppliesIt(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, identity: $this->identity(enforce: false)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field, $e->mode];
        });

        $cmd = $this->update($id, ['email' => 'info@motorradgarage-dachau.de']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('info@motorradgarage-dachau.de', $cmd->getPayload()['email']);
        self::assertSame([[GuardLogEntry::ACTION_OBSERVED_IDENTITY, 'email', 'log_only']], $logged);
    }

    public function testSameEmailInDifferentCaseOrWhitespaceIsNotASwap(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->update($id, ['email' => '  Erdoesi@Example.COM ']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('  Erdoesi@Example.COM ', $cmd->getPayload()['email'], 'not our business; left as sent');
    }

    public function testEmailAndNameSwapInOneWriteKeepsAllThreeInEnforce(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field];
        });

        $cmd = $this->update($id, ['email' => 'info@motorradgarage-dachau.de', 'first_name' => 'Christopher', 'last_name' => 'Kühnel']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('erdoesi@example.com', $cmd->getPayload()['email']);
        self::assertSame('Adam', $cmd->getPayload()['first_name']);
        self::assertSame('Erdösi', $cmd->getPayload()['last_name']);
        self::assertSame([
            [GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'email'],
            [GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'first_name'],
            [GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'last_name'],
        ], $logged);
    }

    public function testNameOnlyChangeIsObservedAndAppliedWithOnEmailSwapPolicy(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field, $e->mode];
        });

        $cmd = $this->update($id, ['last_name' => 'Erdösi-Wagner', 'email' => 'erdoesi@example.com']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('Erdösi-Wagner', $cmd->getPayload()['last_name'], 'ambiguous name-only change is applied');
        self::assertSame([[GuardLogEntry::ACTION_OBSERVED_IDENTITY, 'last_name', 'enforce']], $logged, 'but observed, with the guard mode recorded');
    }

    public function testNameOnlyChangeIsKeptWithAlwaysPolicy(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, identity: $this->identity(enforce: true, protectName: IdentityGuardConfig::PROTECT_NAME_ALWAYS)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field];
        });

        $cmd = $this->update($id, ['first_name' => 'Christopher']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('Adam', $cmd->getPayload()['first_name']);
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'first_name']], $logged);
    }

    public function testNameChangeIsIgnoredWithOffPolicy(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, identity: $this->identity(enforce: true, protectName: IdentityGuardConfig::PROTECT_NAME_OFF)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field];
        });

        $cmd = $this->update($id, ['email' => 'info@motorradgarage-dachau.de', 'last_name' => 'Kühnel']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('erdoesi@example.com', $cmd->getPayload()['email'], 'email still guarded');
        self::assertSame('Kühnel', $cmd->getPayload()['last_name'], 'name neither guarded nor logged');
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'email']], $logged);
    }

    public function testIdentityGuardRunsEvenWhenTheNumberGuardIsDisabled(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, enabled: false, identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field];
        });

        $cmd = $this->update($id, ['customer_number' => '10009', 'email' => 'info@motorradgarage-dachau.de']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('10009', $cmd->getPayload()['customer_number'], 'number guard off: number change applied and not logged');
        self::assertSame('erdoesi@example.com', $cmd->getPayload()['email'], 'identity guard on: email kept');
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'email']], $logged);
    }

    public function testEmailInTheNumberGuardBlockListIsHandledOnceNotTwice(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, protected: ['customer_number', 'email'], identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field];
        });

        $cmd = $this->update($id, ['email' => 'info@motorradgarage-dachau.de']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('erdoesi@example.com', $cmd->getPayload()['email']);
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_UPDATE, 'email']], $logged, 'the 001 block list wins; no second identity entry');
    }

    /**
     * F1 regression: when `email` is also listed in the 001 block list, the number guard alone
     * decides whether the email itself is applied/reverted and logged (once), but the swap it
     * represents must still drive the name policy under `on_email_swap` — it must not be
     * silently treated as "no swap" just because 001 already owns the field.
     */
    public function testEmailInTheNumberGuardBlockListStillDrivesTheNamePolicy(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false, protected: ['customer_number', 'email'], identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field, $e->mode];
        });

        $cmd = $this->update($id, ['email' => 'info@motorradgarage-dachau.de', 'last_name' => 'Kühnel']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('info@motorradgarage-dachau.de', $cmd->getPayload()['email'], '001 owns email, log_only: applied');
        self::assertSame('Erdösi', $cmd->getPayload()['last_name'], 'name reverted: the email swap still drives the policy');
        self::assertSame([
            [GuardLogEntry::ACTION_BLOCKED_UPDATE, 'email', 'log_only'],
            [GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'last_name', 'enforce'],
        ], $logged, 'exactly one entry per field, no double log for email');
    }

    /**
     * F1 round 2: with the number guard `enforce: true`, guardProtectedFields() already reverts
     * `email` (via addPayload()) before the identity guard runs. The swap signal must still be
     * taken from what the connector actually sent, not from the command's now-reverted payload
     * — otherwise the swap looks like "no change" and the name policy is silently disabled.
     */
    public function testEnforcedNumberGuardOwningEmailStillDrivesTheNamePolicy(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, protected: ['customer_number', 'email'], identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field, $e->mode];
        });

        $cmd = $this->update($id, ['email' => 'info@motorradgarage-dachau.de', 'last_name' => 'Kühnel']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('erdoesi@example.com', $cmd->getPayload()['email'], '001 reverts the email it owns');
        self::assertSame('Erdösi', $cmd->getPayload()['last_name'], 'the attempted email swap still drives the name policy');
        self::assertSame([
            [GuardLogEntry::ACTION_BLOCKED_UPDATE, 'email', 'enforce'],
            [GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'last_name', 'enforce'],
        ], $logged, 'exactly two entries, no identity entry for email');
    }

    /**
     * F2: identity `enforce: false` (log_only) with `protectName: always` still observes and
     * logs a name-only change, but never blocks it.
     */
    public function testAlwaysPolicyInLogOnlyObservesTheNameButAppliesIt(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, identity: $this->identity(enforce: false, protectName: IdentityGuardConfig::PROTECT_NAME_ALWAYS)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field, $e->mode];
        });

        $cmd = $this->update($id, ['first_name' => 'Christopher']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('Christopher', $cmd->getPayload()['first_name'], 'log_only: applied');
        self::assertSame([[GuardLogEntry::ACTION_OBSERVED_IDENTITY, 'first_name', 'log_only']], $logged);
    }

    /**
     * F2: default `on_email_swap` policy in `log_only` observes email, first_name and last_name
     * together (email swap plus both names changed in the same write) but applies all three.
     */
    public function testOnEmailSwapPolicyInLogOnlyObservesAllThree(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, identity: $this->identity(enforce: false)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field, $e->mode];
        });

        $cmd = $this->update($id, ['email' => 'info@motorradgarage-dachau.de', 'first_name' => 'Christopher', 'last_name' => 'Kühnel']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('info@motorradgarage-dachau.de', $cmd->getPayload()['email']);
        self::assertSame('Christopher', $cmd->getPayload()['first_name']);
        self::assertSame('Kühnel', $cmd->getPayload()['last_name']);
        self::assertSame([
            [GuardLogEntry::ACTION_OBSERVED_IDENTITY, 'email', 'log_only'],
            [GuardLogEntry::ACTION_OBSERVED_IDENTITY, 'first_name', 'log_only'],
            [GuardLogEntry::ACTION_OBSERVED_IDENTITY, 'last_name', 'log_only'],
        ], $logged);
    }

    public function testIndependentModesNumberLogOnlyIdentityEnforce(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false, identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field, $e->mode];
        });

        $cmd = $this->update($id, ['customer_number' => '10009', 'email' => 'info@motorradgarage-dachau.de']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('10009', $cmd->getPayload()['customer_number'], 'number guard log_only: applied');
        self::assertSame('erdoesi@example.com', $cmd->getPayload()['email'], 'identity guard enforce: kept');
        self::assertSame([
            [GuardLogEntry::ACTION_BLOCKED_UPDATE, 'customer_number', 'log_only'],
            [GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'email', 'enforce'],
        ], $logged);
    }

    public function testIdentityGuardIgnoresInserts(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false, enabled: false, identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->numberRange->expects(self::never())->method('getValue');
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->insert($id, ['customer_number' => '51520', 'email' => 'new@example.com']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('new@example.com', $cmd->getPayload()['email']);
    }

    public function testBothGuardsDisabledSkipDetectionEntirely(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, enabled: false, identity: $this->identity(enforce: true, enabled: false), fieldGuard: $this->fieldGuard(enforce: true, enabled: false)));
        $this->detector->expects(self::never())->method('resolve');
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->update(Uuid::randomHex(), ['email' => 'x@example.com']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('x@example.com', $cmd->getPayload()['email']);
    }

    // ---- feature 003 routing -------------------------------------------------

    public function testFieldGuardReceivesTheUpdateWithHandledAndSent(): void
    {
        $id = Uuid::randomHex();
        $state = $this->state($id, 'C10009', Uuid::randomHex());
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, fieldGuard: $this->fieldGuard(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $state]);

        $cmd = $this->update($id, ['customer_number' => '10009', 'title' => 'Dr.']);
        $this->fieldGuard->expects(self::once())->method('guardCustomerColumns')->with(
            $cmd,
            $id,
            $state,
            self::anything(),
            self::isInstanceOf(ConnectorSource::class),
            ['customer_number'],
            ['customer_number' => '10009', 'title' => 'Dr.'],
        );
        $this->fieldGuard->expects(self::never())->method('guardCustomerCustomFields');

        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('C10009', $cmd->getPayload()['customer_number'], '001 still reverted before 003 ran, but 003 saw the payload as sent');
    }

    public function testJsonUpdateIsRoutedToTheCustomFieldGuardAndNotToTheColumnGuards(): void
    {
        $id = Uuid::randomHex();
        $state = $this->state($id, 'C10009', Uuid::randomHex());
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, protected: ['customer_number', 'email'], identity: $this->identity(enforce: true), fieldGuard: $this->fieldGuard(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->with([Uuid::fromHexToBytes($id)])->willReturn([$id => $state]);
        $this->guardLogger->expects(self::never())->method('log');

        // a custom field that happens to be called "email" must not be mistaken for the email column
        $cmd = $this->jsonUpdate($id, ['email' => 'not-a-column@example.com']);
        $this->fieldGuard->expects(self::once())->method('guardCustomerCustomFields')->with($cmd, $id, $state, self::anything(), self::isInstanceOf(ConnectorSource::class));
        $this->fieldGuard->expects(self::never())->method('guardCustomerColumns');

        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('not-a-column@example.com', $cmd->getPayload()['email']);
    }

    public function testFieldGuardIsSkippedWhenDisabledForTheSalesChannel(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, fieldGuard: $this->fieldGuard(enforce: true, enabled: false)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->fieldGuard->expects(self::never())->method('guardCustomerColumns');
        $this->fieldGuard->expects(self::never())->method('guardCustomerCustomFields');

        $this->subscriber->onEntityWrite($this->event([$this->update($id, ['title' => 'Dr.']), $this->jsonUpdate($id, ['x' => 1])]));
    }

    public function testAddressCommandsGoToTheAddressGuardWithInsertedAndDeletedCustomerIds(): void
    {
        $existing = Uuid::randomHex();
        $created = Uuid::randomHex();
        $deleted = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false, enabled: false, identity: $this->identity(enforce: false, enabled: false), fieldGuard: $this->fieldGuard(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$existing => $this->state($existing, 'C1', Uuid::randomHex())]);

        $addressCmd = $this->addressUpdate($address, ['street' => 'Grasiger Weg 20']);
        $customerInsert = $this->insert($created, ['customer_number' => '1', 'email' => 'new@example.com']);
        $customerDelete = new DeleteCommand($this->definition, ['id' => Uuid::fromHexToBytes($deleted)], new EntityExistence('customer', ['id' => $deleted], true, false, false, []));
        $event = $this->event([$this->update($existing, ['title' => 'x']), $customerInsert, $customerDelete, $addressCmd]);

        $this->addressGuard->expects(self::once())->method('guard')->with($event, [$addressCmd], [$created], [$deleted], self::isInstanceOf(ConnectorSource::class));

        $this->subscriber->onEntityWrite($event);
    }

    public function testAddressOnlyWriteStillRunsDetection(): void
    {
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false, enabled: false, identity: $this->identity(enforce: false, enabled: false), fieldGuard: $this->fieldGuard(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->expects(self::never())->method('load');

        $addressCmd = $this->addressUpdate($address, ['street' => 'Grasiger Weg 20']);
        $this->addressGuard->expects(self::once())->method('guard')->with(self::anything(), [$addressCmd], [], [], self::isInstanceOf(ConnectorSource::class));

        $this->subscriber->onEntityWrite($this->event([$addressCmd]));
    }

    public function testAddressGuardIsNotCalledWhenTheFieldGuardIsGloballyDisabled(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, fieldGuard: $this->fieldGuard(enforce: true, enabled: false)));
        $this->connectorDetected();
        $this->addressGuard->expects(self::never())->method('guard');

        $this->subscriber->onEntityWrite($this->event([$this->addressUpdate(Uuid::randomHex(), ['street' => 'x'])]));
    }

    public function testAllThreeGuardsDisabledSkipDetectionEntirely(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, enabled: false, identity: $this->identity(enforce: true, enabled: false), fieldGuard: $this->fieldGuard(enforce: true, enabled: false)));
        $this->detector->expects(self::never())->method('resolve');

        $this->subscriber->onEntityWrite($this->event([$this->update(Uuid::randomHex(), ['title' => 'x']), $this->addressUpdate(Uuid::randomHex(), ['street' => 'x'])]));
    }
}
