<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Subscriber;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSource;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSourceDetector;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerState;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Revinners\ShopwareJtlConnectorGuardPlugin\Subscriber\CustomerNumberWriteProtection;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
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
    private GuardConfigProvider&MockObject $configProvider;
    private ConnectorSourceDetector&MockObject $detector;
    private CustomerStateLoader&MockObject $stateLoader;
    private NumberRangeValueGeneratorInterface&MockObject $numberRange;
    private GuardLogger&MockObject $guardLogger;
    private LoggerInterface&MockObject $logger;
    private CustomerNumberWriteProtection $subscriber;
    private Context $connectorContext;

    protected function setUp(): void
    {
        $registry = new StaticDefinitionInstanceRegistry(
            [CustomerTestDefinition::class],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGatewayInterface::class),
        );
        $this->definition = $registry->getByEntityName('customer');

        $this->configProvider = $this->createMock(GuardConfigProvider::class);
        $this->detector = $this->createMock(ConnectorSourceDetector::class);
        $this->stateLoader = $this->createMock(CustomerStateLoader::class);
        $this->numberRange = $this->createMock(NumberRangeValueGeneratorInterface::class);
        $this->guardLogger = $this->createMock(GuardLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new CustomerNumberWriteProtection(
            $this->configProvider,
            $this->detector,
            $this->stateLoader,
            $this->numberRange,
            $this->guardLogger,
            $this->logger,
        );

        $this->connectorContext = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));
    }

    // ---- helpers -----------------------------------------------------------

    private function config(bool $enforce, bool $enabled = true, array $protected = ['customer_number']): GuardConfig
    {
        return new GuardConfig($enabled, $enforce, ['JTL-Connector'], [], $protected);
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
}
