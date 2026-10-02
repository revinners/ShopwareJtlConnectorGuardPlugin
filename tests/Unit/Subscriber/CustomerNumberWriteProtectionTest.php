<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Subscriber;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\AddressGuard;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSource;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSourceDetector;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerRerouter;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerState;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\SamePersonGuard;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\SamePersonGuardConfig;
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
    private AddressGuard&MockObject $addressGuard;
    private SamePersonGuard&MockObject $samePersonGuard;
    private CustomerRerouter&MockObject $rerouter;
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
        $this->configProvider->method('connectorIntegrationIds')->willReturn([self::INTEGRATION_ID]);
        $this->detector = $this->createMock(ConnectorSourceDetector::class);
        $this->stateLoader = $this->createMock(CustomerStateLoader::class);
        $this->numberRange = $this->createMock(NumberRangeValueGeneratorInterface::class);
        $this->guardLogger = $this->createMock(GuardLogger::class);
        $this->addressGuard = $this->createMock(AddressGuard::class);
        $this->samePersonGuard = $this->createMock(SamePersonGuard::class);
        $this->rerouter = $this->createMock(CustomerRerouter::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->fallbackLogger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new CustomerNumberWriteProtection(
            $this->configProvider,
            $this->detector,
            $this->stateLoader,
            $this->numberRange,
            $this->guardLogger,
            $this->addressGuard,
            $this->samePersonGuard,
            $this->rerouter,
            $this->logger,
            $this->fallbackLogger,
        );

        $this->connectorContext = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));
    }

    // ---- helpers -----------------------------------------------------------

    private function config(bool $enforce, bool $enabled = true, array $protected = ['customer_number'], ?SamePersonGuardConfig $samePerson = null): GuardConfig
    {
        return new GuardConfig($enabled, $enforce, [self::INTEGRATION_ID], $protected, $samePerson ?? SamePersonGuardConfig::disabled());
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
        $this->configProvider->expects(self::once())->method('load')
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

    public function testWithoutTheSamePersonCheckAnEmailSwapIsLeftUntouched(): void
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

    // ---- same-person check ----------------------------------------------------

    private function samePerson(bool $enforce): SamePersonGuardConfig
    {
        return new SamePersonGuardConfig(true, $enforce);
    }

    public function testSamePersonWriteStillKeepsTheCustomerNumber(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, samePerson: $this->samePerson(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->guardLogger->expects(self::once())->method('log')->with(self::callback(
            static fn (GuardLogEntry $e): bool => $e->action === GuardLogEntry::ACTION_BLOCKED_UPDATE && $e->field === 'customer_number'
        ));

        $cmd = $this->update($id, ['customer_number' => '10009', 'email' => 'erdoesi@example.com', 'company' => 'Erdösi GmbH']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('C10009', $cmd->getPayload()['customer_number']);
        self::assertSame('Erdösi GmbH', $cmd->getPayload()['company']);
    }

    public function testDifferentPersonWriteHandsANumberTheNumberGuardOnlyObservedToTheSamePersonGuard(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false, samePerson: $this->samePerson(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);

        $this->samePersonGuard->expects(self::once())->method('guardCustomerColumns')->with(self::anything(), $id, self::anything(), self::anything(), self::anything(), [], self::anything());

        $this->subscriber->onEntityWrite($this->event([$this->update($id, ['customer_number' => '39414', 'email' => 'kraft@example.com'])]));
    }




    public function testSamePersonWriteIsAppliedInFull(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, samePerson: $this->samePerson(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->guardLogger->expects(self::never())->method('log');
        $this->samePersonGuard->expects(self::never())->method('guardCustomerColumns');
        $this->samePersonGuard->expects(self::never())->method('guardCustomerCustomFields');
        $this->rerouter->expects(self::never())->method('queue');

        $cmd = $this->update($id, ['email' => 'Erdoesi@Example.com', 'first_name' => 'Ádám', 'company' => 'Erdösi GmbH']);
        $json = $this->jsonUpdate($id, ['anything' => 'x']);
        $address = $this->addressUpdate(Uuid::randomHex(), ['street' => 'Neue Str. 1']);
        $this->addressGuard->expects(self::once())->method('guard')->with(self::anything(), [$address], [], [], self::isInstanceOf(ConnectorSource::class), []);

        $this->subscriber->onEntityWrite($this->event([$cmd, $json, $address]));

        self::assertSame('Ádám', $cmd->getPayload()['first_name']);
        self::assertSame('Erdösi GmbH', $cmd->getPayload()['company']);
    }

    public function testDifferentPersonWriteGoesToTheSamePersonGuardWithItsCustomFieldsAndAddresses(): void
    {
        $id = Uuid::randomHex();
        $other = Uuid::randomHex();
        $created = Uuid::randomHex();
        $state = $this->state($id, 'C10009', Uuid::randomHex());
        $samePerson = $this->samePerson(enforce: true);
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, samePerson: $samePerson));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $state, $other => $this->state($other, 'C10010', Uuid::randomHex())]);

        $sent = ['customer_number' => '39414', 'email' => 'kraft@example.com', 'first_name' => 'Ramona'];
        $cmd = $this->update($id, $sent);
        $json = $this->jsonUpdate($id, ['hinweis_(intern)' => '*Händler']);
        $otherCmd = $this->update($other, ['email' => 'erdoesi@example.com', 'company' => 'x']);
        $otherJson = $this->jsonUpdate($other, ['anmerkung' => 'x']);
        $insert = $this->insert($created, ['customer_number' => '1', 'email' => 'new@example.com']);
        $address = $this->addressUpdate(Uuid::randomHex(), ['street' => 'Grasiger Weg 20']);

        $this->samePersonGuard->expects(self::once())->method('guardCustomerColumns')->with($cmd, $id, $state, $samePerson, self::isInstanceOf(ConnectorSource::class), ['customer_number'], $sent);
        $this->samePersonGuard->expects(self::once())->method('guardCustomerCustomFields')->with($json, $id, $state, $samePerson, self::isInstanceOf(ConnectorSource::class));
        $this->addressGuard->expects(self::once())->method('guard')->with(self::anything(), [$address], [$created], [], self::isInstanceOf(ConnectorSource::class), [$id]);

        $this->subscriber->onEntityWrite($this->event([$cmd, $json, $otherCmd, $otherJson, $insert, $address]));

        self::assertSame('C10009', $cmd->getPayload()['customer_number'], 'the number guard reverted the number itself');
    }

    public function testAddressOnlyWriteStillRunsDetectionAndIsHandedOverWithNoDifferentPerson(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: false, enabled: false, samePerson: $this->samePerson(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->expects(self::never())->method('load');

        $address = $this->addressUpdate(Uuid::randomHex(), ['street' => 'Grasiger Weg 20']);
        $this->addressGuard->expects(self::once())->method('guard')->with(self::anything(), [$address], [], [], self::isInstanceOf(ConnectorSource::class), []);

        $this->subscriber->onEntityWrite($this->event([$address]));
    }

    // ---- review fixes ---------------------------------------------------------

    public function testTheMasterSwitchSwitchesEverythingOff(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, enabled: false, samePerson: new SamePersonGuardConfig(true, true, true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->guardLogger->expects(self::never())->method('log');
        $this->samePersonGuard->expects(self::never())->method('guardCustomerColumns');
        $this->samePersonGuard->expects(self::never())->method('guardCustomerCustomFields');
        $this->rerouter->expects(self::never())->method('queue');

        $cmd = $this->update($id, ['customer_number' => '39414', 'email' => 'kraft@example.com']);
        $event = $this->event([$cmd, $this->jsonUpdate($id, ['x' => 1])]);
        $this->addressGuard->expects(self::once())->method('guard')->with(self::anything(), self::anything(), [], [], self::anything(), []);
        $event2 = $this->event([$cmd, $this->jsonUpdate($id, ['x' => 1]), $this->addressUpdate(Uuid::randomHex(), ['street' => 'x'])]);

        $this->subscriber->onEntityWrite($event);
        $event->success();
        $this->subscriber->onEntityWrite($event2);
        $event2->success();

        self::assertSame('39414', $cmd->getPayload()['customer_number']);
    }

    public function testTheSelectedIntegrationsOfEveryScopeAreHandedToTheDetector(): void
    {
        $this->detector->expects(self::once())->method('resolve')->with(self::anything(), [self::INTEGRATION_ID])->willReturn(null);
        $this->configProvider->expects(self::never())->method('load');
        $this->stateLoader->expects(self::never())->method('load');
        $this->logger->expects(self::never())->method(self::anything());

        $this->subscriber->onEntityWrite($this->event([$this->update(Uuid::randomHex(), ['customer_number' => '1'])]));
    }

    public function testASecondCommandWithoutAnEmailForAFlaggedCustomerIsGuardedToo(): void
    {
        $id = Uuid::randomHex();
        $state = $this->state($id, 'C10009', Uuid::randomHex());
        $samePerson = new SamePersonGuardConfig(true, true, true);
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, samePerson: $samePerson));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $state]);

        // a sync batch with two operations on the same customer: only the first carries the e-mail
        $withEmail = $this->update($id, ['email' => 'kraft@example.com', 'first_name' => 'Ramona']);
        $withoutEmail = $this->update($id, ['customer_group_id' => 'dealer-group']);

        $guarded = [];
        $this->samePersonGuard->expects(self::exactly(2))->method('guardCustomerColumns')
            ->willReturnCallback(static function (UpdateCommand $command) use (&$guarded): void {
                $guarded[] = $command;
            });
        $this->rerouter->expects(self::once())->method('queue')->with(
            $state,
            ['email' => 'kraft@example.com', 'first_name' => 'Ramona', 'customer_group_id' => 'dealer-group'],
            $samePerson,
            self::isInstanceOf(ConnectorSource::class),
        );

        $event = $this->event([$withoutEmail, $withEmail]);
        $this->subscriber->onEntityWrite($event);
        $event->success();

        self::assertSame([$withoutEmail, $withEmail], $guarded, 'both commands of the flagged customer, whatever their order');
    }

    public function testTheRerouteIsQueuedOnlyOnceTheWriteSucceeded(): void
    {
        $id = Uuid::randomHex();
        $state = $this->state($id, 'C10009', Uuid::randomHex());
        $samePerson = new SamePersonGuardConfig(true, true, true);
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, samePerson: $samePerson));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $state]);

        $sent = ['customer_number' => '39414', 'email' => 'kraft@example.com', 'company' => 'Moto Kraft'];
        $queued = 0;
        $this->rerouter->method('queue')->with($state, $sent, $samePerson, self::isInstanceOf(ConnectorSource::class))
            ->willReturnCallback(static function () use (&$queued): void {
                ++$queued;
            });

        $event = $this->event([$this->update($id, $sent)]);
        $this->subscriber->onEntityWrite($event);
        self::assertSame(0, $queued, 'nothing is queued while the write has not been executed');

        $event->success();
        self::assertSame(1, $queued);
    }

    public function testAFailedWriteQueuesNothingAndSaysSo(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, samePerson: new SamePersonGuardConfig(true, true, true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->rerouter->expects(self::never())->method('queue');
        $this->logger->expects(self::once())->method('warning')->with(self::stringContains('rolled back'), ['customerIds' => [$id]]);

        $event = $this->event([$this->update($id, ['email' => 'kraft@example.com', 'company' => 'Moto Kraft'])]);
        $this->subscriber->onEntityWrite($event);
        $event->error();
    }

    public function testAThrowingQueueInTheSuccessCallbackNeverEscapes(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, samePerson: new SamePersonGuardConfig(true, true, true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->rerouter->method('queue')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects(self::once())->method('error');

        $event = $this->event([$this->update($id, ['email' => 'kraft@example.com'])]);
        $this->subscriber->onEntityWrite($event);
        $event->success(); // must not throw: it runs inside the gateway's try block
    }

    public function testNothingIsQueuedInLogOnlyOrForTheSamePerson(): void
    {
        $id = Uuid::randomHex();
        $other = Uuid::randomHex();
        $scLogOnly = Uuid::randomHex();
        $this->configProvider->method('load')->willReturnCallback(fn (?string $sc): GuardConfig => $this->config(
            enforce: true,
            samePerson: new SamePersonGuardConfig(true, $sc !== $scLogOnly, true),
        ));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C1', $scLogOnly), $other => $this->state($other, 'C2', Uuid::randomHex())]);
        $this->rerouter->expects(self::never())->method('queue');

        $event = $this->event([
            $this->update($id, ['email' => 'kraft@example.com']),        // different person, log_only
            $this->update($other, ['email' => 'erdoesi@example.com']),   // same person
        ]);
        $this->subscriber->onEntityWrite($event);
        $event->success();
    }
}
