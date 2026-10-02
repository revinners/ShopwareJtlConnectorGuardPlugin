<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\AddressGuard;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSource;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerAddressState;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerAddressStateLoader;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerState;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\SamePersonGuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Subscriber\CustomerAddressTestDefinition;
use Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Subscriber\CustomerTestDefinition;
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
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AddressGuardTest extends TestCase
{
    private const INTEGRATION_ID = '2103c0f8ba934cbdb291287aaa3b5ce8';

    private EntityDefinition $definition;
    private GuardConfigProvider&MockObject $configProvider;
    private CustomerAddressStateLoader&MockObject $addressLoader;
    private CustomerStateLoader&MockObject $customerLoader;
    private GuardLogger&MockObject $guardLogger;
    private AddressGuard $guard;
    private ConnectorSource $connector;
    /** @var list<array{string, string, string|null, string|null, string, string|null}> action, field, current, attempted, entity, entityId */
    private array $logged = [];

    protected function setUp(): void
    {
        $registry = new StaticDefinitionInstanceRegistry(
            [CustomerTestDefinition::class, CustomerAddressTestDefinition::class],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGatewayInterface::class),
        );
        $this->definition = $registry->getByEntityName('customer_address');
        $this->configProvider = $this->createMock(GuardConfigProvider::class);
        $this->addressLoader = $this->createMock(CustomerAddressStateLoader::class);
        $this->customerLoader = $this->createMock(CustomerStateLoader::class);
        $this->guardLogger = $this->createMock(GuardLogger::class);
        $this->guardLogger->method('log')->willReturnCallback(function (GuardLogEntry $e): void {
            $this->logged[] = [$e->action, $e->field, $e->currentValue, $e->attemptedValue, $e->entity, $e->entityId];
        });
        $this->guard = new AddressGuard(
            $this->configProvider,
            $this->addressLoader,
            $this->customerLoader,
            $this->guardLogger,
            $this->createMock(LoggerInterface::class),
            $this->createMock(LoggerInterface::class),
        );
        $this->connector = new ConnectorSource(self::INTEGRATION_ID, 'JTL-Connector');
    }

    private function config(bool $enforce, string $policy = SamePersonGuardConfig::POLICY_LOG, bool $enabled = true): GuardConfig
    {
        return new GuardConfig(true, true, [], ['customer_number'],
            new SamePersonGuardConfig($enabled, $enforce, false, SamePersonGuardConfig::DEFAULT_REROUTE_FIELDS, $policy));
    }

    private function existence(string $idHex, bool $exists): EntityExistence
    {
        return new EntityExistence('customer_address', ['id' => $idHex], $exists, false, false, []);
    }

    private function update(string $idHex, array $payload): UpdateCommand
    {
        return new UpdateCommand($this->definition, $payload, ['id' => Uuid::fromHexToBytes($idHex)], $this->existence($idHex, true), '/0/addresses/0');
    }

    private function jsonUpdate(string $idHex, array $payload): JsonUpdateCommand
    {
        return new JsonUpdateCommand($this->definition, 'custom_fields', $payload, ['id' => Uuid::fromHexToBytes($idHex)], $this->existence($idHex, true), '/0/addresses/0');
    }

    private function insert(string $idHex, array $payload): InsertCommand
    {
        $pk = ['id' => Uuid::fromHexToBytes($idHex)];

        return new InsertCommand($this->definition, $pk + $payload, $pk, EntityExistence::createForEntity('customer_address', ['id' => $idHex]), '/0/addresses/0');
    }

    private function delete(string $idHex): DeleteCommand
    {
        return new DeleteCommand($this->definition, ['id' => Uuid::fromHexToBytes($idHex)], $this->existence($idHex, true));
    }

    /**
     * @param list<WriteCommand> $commands
     */
    private function event(array $commands): EntityWriteEvent
    {
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        return EntityWriteEvent::create(WriteContext::createFromContext($context), $commands);
    }

    private function customer(string $idHex): CustomerState
    {
        return new CustomerState($idHex, [
            'id' => Uuid::fromHexToBytes($idHex),
            'email' => 'reischl@t-online.de',
            'first_name' => 'Martin',
            'last_name' => 'Reischl',
            'sales_channel_id' => Uuid::fromHexToBytes('019b02dbf154717c8d127b5df75c3b7d'),
        ]);
    }

    private function address(string $idHex, string $customerHex, array $extra = []): CustomerAddressState
    {
        return new CustomerAddressState($idHex, $extra + [
            'id' => Uuid::fromHexToBytes($idHex),
            'customer_id' => Uuid::fromHexToBytes($customerHex),
            'street' => 'Nelkenweg 12',
            'zipcode' => '63814',
            'city' => 'Mainaschaff',
            'custom_fields' => null,
            'created_at' => '2026-01-20 16:47:12.680',
            'updated_at' => null,
        ]);
    }

    public function testEnforceRevertsEveryChangedAddressColumn(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->with('019b02dbf154717c8d127b5df75c3b7d')->willReturn($this->config(enforce: true));
        $this->addressLoader->method('load')->with([Uuid::fromHexToBytes($address)])->willReturn([$address => $this->address($address, $customer)]);
        $this->customerLoader->method('load')->with([Uuid::fromHexToBytes($customer)])->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->update($address, ['street' => 'Grasiger Weg 20', 'zipcode' => '93333', 'city' => 'Mainaschaff', 'updated_at' => '2026-09-08 10:00:00.000']);
        $this->guard->guard($this->event([$cmd]), [$cmd], [], [], $this->connector, [$customer]);

        self::assertSame('Nelkenweg 12', $cmd->getPayload()['street']);
        self::assertSame('63814', $cmd->getPayload()['zipcode']);
        self::assertSame('2026-09-08 10:00:00.000', $cmd->getPayload()['updated_at'], 'bookkeeping untouched');
        self::assertSame([
            [GuardLogEntry::ACTION_BLOCKED_MISMATCH, 'street', 'Nelkenweg 12', 'Grasiger Weg 20', 'customer_address', $address],
            [GuardLogEntry::ACTION_BLOCKED_MISMATCH, 'zipcode', '63814', '93333', 'customer_address', $address],
        ], $this->logged);
    }

    public function testLogOnlyRecordsAndAppliesAddressUpdate(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false));
        $this->addressLoader->method('load')->willReturn([$address => $this->address($address, $customer)]);
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->update($address, ['street' => 'Grasiger Weg 20']);
        $this->guard->guard($this->event([$cmd]), [$cmd], [], [], $this->connector, [$customer]);

        self::assertSame('Grasiger Weg 20', $cmd->getPayload()['street']);
        self::assertSame([[GuardLogEntry::ACTION_OBSERVED_MISMATCH, 'street', 'Nelkenweg 12', 'Grasiger Weg 20', 'customer_address', $address]], $this->logged);
    }

    public function testAddressCustomFieldsHaveNoAllowList(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->addressLoader->method('load')->willReturn([$address => $this->address($address, $customer, ['custom_fields' => '{"anmerkung":"alt"}'])]);
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->jsonUpdate($address, ['anmerkung' => 'neu', 'other' => 'x']);
        $this->guard->guard($this->event([$cmd]), [$cmd], [], [], $this->connector, [$customer]);

        self::assertSame('alt', $cmd->getPayload()['anmerkung']);
        self::assertNull($cmd->getPayload()['other']);
        self::assertSame([
            [GuardLogEntry::ACTION_BLOCKED_MISMATCH, 'custom_fields.anmerkung', 'alt', 'neu', 'customer_address', $address],
            [GuardLogEntry::ACTION_BLOCKED_MISMATCH, 'custom_fields.other', null, 'x', 'customer_address', $address],
        ], $this->logged);
    }

    public function testInsertForACustomerInsertedInTheSameWriteIsIgnored(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->expects(self::never())->method('load');
        $this->customerLoader->method('load')->willReturn([]);

        $cmd = $this->insert($address, ['customer_id' => Uuid::fromHexToBytes($customer), 'street' => 'Neu 1']);
        $this->guard->guard($this->event([$cmd]), [$cmd], [$customer], [], $this->connector, [$customer]);

        self::assertSame([], $this->logged);
    }

    public function testInsertForAnExistingCustomerIsRecordedPerColumn(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->customerLoader->method('load')->with([Uuid::fromHexToBytes($customer)])->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->insert($address, ['customer_id' => Uuid::fromHexToBytes($customer), 'street' => 'Grasiger Weg 20', 'city' => 'Neustadt', 'created_at' => '2026-09-08 10:00:00.000']);
        $event = $this->event([$cmd]);
        $this->guard->guard($event, [$cmd], [], [], $this->connector, [$customer]);

        self::assertSame('Grasiger Weg 20', $cmd->getPayload()['street'], 'cannot be blocked');
        self::assertSame([], $event->getWriteContext()->getExceptions()->getExceptions());
        self::assertSame([
            [GuardLogEntry::ACTION_OBSERVED_ADDRESS_CREATE, 'customer_id', null, $customer, 'customer_address', $address],
            [GuardLogEntry::ACTION_OBSERVED_ADDRESS_CREATE, 'street', null, 'Grasiger Weg 20', 'customer_address', $address],
            [GuardLogEntry::ACTION_OBSERVED_ADDRESS_CREATE, 'city', null, 'Neustadt', 'customer_address', $address],
        ], $this->logged, 'id and created_at are bookkeeping');
    }

    public function testDeleteIsRecordedPerColumnFromTheCurrentRow(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->addressLoader->method('load')->willReturn([$address => $this->address($address, $customer)]);
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->delete($address);
        $this->guard->guard($this->event([$cmd]), [$cmd], [], [], $this->connector, [$customer]);

        self::assertSame([
            [GuardLogEntry::ACTION_OBSERVED_ADDRESS_DELETE, 'customer_id', $customer, null, 'customer_address', $address],
            [GuardLogEntry::ACTION_OBSERVED_ADDRESS_DELETE, 'street', 'Nelkenweg 12', null, 'customer_address', $address],
            [GuardLogEntry::ACTION_OBSERVED_ADDRESS_DELETE, 'zipcode', '63814', null, 'customer_address', $address],
            [GuardLogEntry::ACTION_OBSERVED_ADDRESS_DELETE, 'city', 'Mainaschaff', null, 'customer_address', $address],
        ], $this->logged, 'null custom_fields and bookkeeping columns are not logged');
    }

    public function testDeleteOfAnAddressWhoseCustomerIsDeletedInTheSameWriteIsIgnored(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->addressLoader->method('load')->willReturn([$address => $this->address($address, $customer)]);
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);
        $this->configProvider->expects(self::never())->method('load');

        $cmd = $this->delete($address);
        $this->guard->guard($this->event([$cmd]), [$cmd], [], [$customer], $this->connector, [$customer]);

        self::assertSame([], $this->logged);
    }

    public function testRejectWritePolicyAddsAViolationToTheWriteContext(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, policy: SamePersonGuardConfig::POLICY_REJECT_WRITE));
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->insert($address, ['customer_id' => Uuid::fromHexToBytes($customer), 'street' => 'Grasiger Weg 20']);
        $event = $this->event([$cmd]);
        $this->guard->guard($event, [$cmd], [], [], $this->connector, [$customer]);

        $exceptions = $event->getWriteContext()->getExceptions()->getExceptions();
        self::assertCount(1, $exceptions);
        self::assertInstanceOf(WriteConstraintViolationException::class, $exceptions[0]);
        self::assertSame('/0/addresses/0', $exceptions[0]->getPath());
        self::assertSame([[GuardLogEntry::ACTION_REJECTED_WRITE, '*', null, null, 'customer_address', $address]], $this->logged);
    }

    public function testRejectWritePolicyIsInertInLogOnly(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false, policy: SamePersonGuardConfig::POLICY_REJECT_WRITE));
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->insert($address, ['customer_id' => Uuid::fromHexToBytes($customer), 'street' => 'Grasiger Weg 20']);
        $event = $this->event([$cmd]);
        $this->guard->guard($event, [$cmd], [], [], $this->connector, [$customer]);

        self::assertSame([], $event->getWriteContext()->getExceptions()->getExceptions());
        self::assertSame(GuardLogEntry::ACTION_OBSERVED_ADDRESS_CREATE, $this->logged[0][0]);
    }

    public function testDisabledPerSalesChannelDoesNothing(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, enabled: false));
        $this->addressLoader->method('load')->willReturn([$address => $this->address($address, $customer)]);
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->update($address, ['street' => 'Grasiger Weg 20']);
        $this->guard->guard($this->event([$cmd]), [$cmd], [], [], $this->connector, [$customer]);

        self::assertSame('Grasiger Weg 20', $cmd->getPayload()['street']);
        self::assertSame([], $this->logged);
    }

    public function testOneFailingCommandDoesNotStopTheOthers(): void
    {
        $customer = Uuid::randomHex();
        $bad = Uuid::randomHex();
        $good = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->addressLoader->method('load')->willReturn([
            $bad => new CustomerAddressState($bad, ['id' => Uuid::fromHexToBytes($bad), 'customer_id' => Uuid::fromHexToBytes($customer), 'street' => new \stdClass()]),
            $good => $this->address($good, $customer),
        ]);
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $badCmd = $this->update($bad, ['street' => 'x']);
        $goodCmd = $this->update($good, ['street' => 'Grasiger Weg 20']);
        $this->guard->guard($this->event([$badCmd, $goodCmd]), [$badCmd, $goodCmd], [], [], $this->connector, [$customer]);

        self::assertSame('Nelkenweg 12', $goodCmd->getPayload()['street'], 'guarded despite the earlier failure');
    }

    public function testTheSamePersonMayEditCreateAndDeleteAddresses(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, policy: SamePersonGuardConfig::POLICY_REJECT_WRITE));
        $this->addressLoader->method('load')->willReturn([$address => $this->address($address, $customer)]);
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $update = $this->update($address, ['street' => 'Grasiger Weg 20']);
        $insert = $this->insert(Uuid::randomHex(), ['customer_id' => Uuid::fromHexToBytes($customer), 'street' => 'Neue Str. 1']);
        $delete = $this->delete($address);
        $event = $this->event([$update, $insert, $delete]);
        // no different person in this write: same person, or an address-only write
        $this->guard->guard($event, [$update, $insert, $delete], [], [], $this->connector, []);

        self::assertSame('Grasiger Weg 20', $update->getPayload()['street']);
        self::assertSame([], $this->logged);
        self::assertCount(0, $event->getWriteContext()->getExceptions()->getExceptions());
    }

    public function testDeletingTheAccountsDefaultAddressIsAlwaysRejectedInEnforce(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        // policy "log": without the forced reject the default id (kept, it is a customer column) would dangle
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->addressLoader->method('load')->willReturn([$address => $this->address($address, $customer)]);
        $this->customerLoader->method('load')->willReturn([$customer => new CustomerState($customer, [
            'id' => Uuid::fromHexToBytes($customer),
            'email' => 'reischl@t-online.de',
            'default_billing_address_id' => Uuid::fromHexToBytes($address),
            'default_shipping_address_id' => Uuid::randomBytes(),
        ])]);

        $cmd = $this->delete($address);
        $event = $this->event([$cmd]);
        $this->guard->guard($event, [$cmd], [], [], $this->connector, [$customer]);

        self::assertCount(1, $event->getWriteContext()->getExceptions()->getExceptions());
        self::assertSame(GuardLogEntry::ACTION_REJECTED_WRITE, $this->logged[0][0]);
    }

    public function testDeletingTheDefaultAddressIsOnlyRecordedInLogOnly(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false));
        $this->addressLoader->method('load')->willReturn([$address => $this->address($address, $customer)]);
        $this->customerLoader->method('load')->willReturn([$customer => new CustomerState($customer, [
            'id' => Uuid::fromHexToBytes($customer),
            'default_billing_address_id' => Uuid::fromHexToBytes($address),
        ])]);

        $cmd = $this->delete($address);
        $event = $this->event([$cmd]);
        $this->guard->guard($event, [$cmd], [], [], $this->connector, [$customer]);

        self::assertCount(0, $event->getWriteContext()->getExceptions()->getExceptions());
        self::assertSame(GuardLogEntry::ACTION_OBSERVED_ADDRESS_DELETE, $this->logged[0][0]);
    }

    public function testAnAddressOfAThirdCustomerCannotBeMovedOntoTheFlaggedAccount(): void
    {
        $flagged = Uuid::randomHex();
        $third = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->addressLoader->method('load')->willReturn([$address => $this->address($address, $third)]);
        $this->customerLoader->expects(self::once())->method('load')->with(self::callback(
            static fn (array $ids): bool => \in_array(Uuid::fromHexToBytes($flagged), $ids, true) && \in_array(Uuid::fromHexToBytes($third), $ids, true)
        ))->willReturn([$flagged => $this->customer($flagged), $third => $this->customer($third)]);

        $cmd = $this->update($address, ['customer_id' => Uuid::fromHexToBytes($flagged), 'street' => 'Grasiger Weg 20']);
        $this->guard->guard($this->event([$cmd]), [$cmd], [], [], $this->connector, [$flagged]);

        self::assertSame(Uuid::fromHexToBytes($third), $cmd->getPayload()['customer_id'], 'the address stays with its owner');
        self::assertSame('Nelkenweg 12', $cmd->getPayload()['street']);
        self::assertSame([GuardLogEntry::ACTION_BLOCKED_MISMATCH, GuardLogEntry::ACTION_BLOCKED_MISMATCH], array_column($this->logged, 0));
    }

    public function testTheMasterSwitchLeavesAddressesOfAFlaggedCustomerAlone(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn(new GuardConfig(false, true, [], ['customer_number'], new SamePersonGuardConfig(true, true)));
        $this->addressLoader->method('load')->willReturn([$address => $this->address($address, $customer)]);
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->update($address, ['street' => 'Grasiger Weg 20']);
        $this->guard->guard($this->event([$cmd]), [$cmd], [], [], $this->connector, [$customer]);

        self::assertSame('Grasiger Weg 20', $cmd->getPayload()['street']);
        self::assertSame([], $this->logged);
    }
}
