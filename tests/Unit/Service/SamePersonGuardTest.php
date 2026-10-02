<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSource;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerState;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\SamePersonGuard;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\SamePersonGuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Subscriber\CustomerTestDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SamePersonGuardTest extends TestCase
{
    private const GROUP_RETAIL = '0123456789abcdef0123456789abcdef';
    private const GROUP_DEALER = 'fedcba9876543210fedcba9876543210';

    private EntityDefinition $definition;
    private GuardLogger&MockObject $guardLogger;
    private SamePersonGuard $guard;
    private ConnectorSource $connector;
    /** @var list<array{string, string, string, string|null, string|null}> action, field, mode, current, attempted */
    private array $logged = [];

    protected function setUp(): void
    {
        $registry = new StaticDefinitionInstanceRegistry(
            [CustomerTestDefinition::class],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGatewayInterface::class),
        );
        $this->definition = $registry->getByEntityName('customer');
        $this->guardLogger = $this->createMock(GuardLogger::class);
        $this->guardLogger->method('log')->willReturnCallback(function (GuardLogEntry $e): void {
            $this->logged[] = [$e->action, $e->field, $e->mode, $e->currentValue, $e->attemptedValue];
        });
        $this->guard = new SamePersonGuard($this->guardLogger);
        $this->connector = new ConnectorSource('2103c0f8ba934cbdb291287aaa3b5ce8', 'JTL-Connector');
    }

    private function update(string $idHex, array $payload): UpdateCommand
    {
        $existence = new EntityExistence('customer', ['id' => $idHex], true, false, false, []);

        return new UpdateCommand($this->definition, $payload, ['id' => Uuid::fromHexToBytes($idHex)], $existence, '/0');
    }

    private function jsonUpdate(string $idHex, array $payload, string $storageName = 'custom_fields'): JsonUpdateCommand
    {
        $existence = new EntityExistence('customer', ['id' => $idHex], true, false, false, []);

        return new JsonUpdateCommand($this->definition, $storageName, $payload, ['id' => Uuid::fromHexToBytes($idHex)], $existence, '/0');
    }

    /** The retail customer whose account a dealer's Wawi record is wrongly linked to. */
    private function state(string $idHex, array $extra = []): CustomerState
    {
        return new CustomerState($idHex, $extra + [
            'id' => Uuid::fromHexToBytes($idHex),
            'customer_number' => 'C20001',
            'email' => 'retail@example.com',
            'first_name' => 'Vera',
            'last_name' => 'Muster',
            'company' => null,
            'customer_group_id' => Uuid::fromHexToBytes(self::GROUP_RETAIL),
            'custom_fields' => null,
        ]);
    }

    /** @return array<string, mixed> what Wawi pushes for the dealer */
    private function dealerPush(): array
    {
        return [
            'customer_number' => '20001',
            'email' => 'dealer@example.com',
            'first_name' => 'Dora',
            'last_name' => 'Muster',
            'company' => 'Motorrad Dealer',
            'customer_group_id' => Uuid::fromHexToBytes(self::GROUP_DEALER),
            'updated_at' => '2026-09-26 05:48:09.207',
        ];
    }

    public function testADifferentEmailIsADifferentPerson(): void
    {
        $state = $this->state(Uuid::randomHex());

        self::assertTrue(SamePersonGuard::isDifferentPerson(['email' => 'dealer@example.com'], $state));
        self::assertTrue(SamePersonGuard::isDifferentPerson(['email' => null], $state));
    }

    public function testTheSameEmailInAnotherCaseOrWithoutAnEmailIsTheSamePerson(): void
    {
        $state = $this->state(Uuid::randomHex());

        self::assertFalse(SamePersonGuard::isDifferentPerson(['email' => '  Retail@Example.com '], $state));
        self::assertFalse(SamePersonGuard::isDifferentPerson(['company' => 'New GmbH'], $state), 'no e-mail in the write: nothing to judge by');
    }

    public function testEnforceKeepsEveryColumnIncludingTheGroup(): void
    {
        $id = Uuid::randomHex();
        $sent = $this->dealerPush();
        $cmd = $this->update($id, $sent);

        // 001 already wrote the number back
        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id), new SamePersonGuardConfig(true, true), $this->connector, ['customer_number'], $sent);

        $payload = $cmd->getPayload();
        self::assertSame('retail@example.com', $payload['email']);
        self::assertSame('Vera', $payload['first_name']);
        self::assertNull($payload['company']);
        self::assertSame(Uuid::fromHexToBytes(self::GROUP_RETAIL), $payload['customer_group_id'], 'the group of a different person is not applied either');
        self::assertSame('2026-09-26 05:48:09.207', $payload['updated_at'], 'bookkeeping untouched');
        self::assertSame('20001', $payload['customer_number'], 'left to 001, which reverted it on the real command');
        self::assertSame([
            [GuardLogEntry::ACTION_BLOCKED_MISMATCH, 'email', 'enforce', 'retail@example.com', 'dealer@example.com'],
            [GuardLogEntry::ACTION_BLOCKED_MISMATCH, 'first_name', 'enforce', 'Vera', 'Dora'],
            [GuardLogEntry::ACTION_BLOCKED_MISMATCH, 'company', 'enforce', null, 'Motorrad Dealer'],
            [GuardLogEntry::ACTION_BLOCKED_MISMATCH, 'customer_group_id', 'enforce', self::GROUP_RETAIL, self::GROUP_DEALER],
        ], $this->logged, 'last_name is unchanged and therefore neither reverted nor logged');
    }

    public function testANumberTheNumberGuardOnlyObservedIsKeptHere(): void
    {
        $id = Uuid::randomHex();
        $sent = ['customer_number' => '20001', 'email' => 'dealer@example.com'];
        $cmd = $this->update($id, $sent);

        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id), new SamePersonGuardConfig(true, true), $this->connector, [], $sent);

        self::assertSame('C20001', $cmd->getPayload()['customer_number']);
    }

    public function testLogOnlyRecordsThePreWriteValuesAndAppliesTheWrite(): void
    {
        $id = Uuid::randomHex();
        $sent = ['email' => 'dealer@example.com', 'company' => 'Motorrad Dealer'];
        $cmd = $this->update($id, $sent);

        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id), new SamePersonGuardConfig(true, false), $this->connector, [], $sent);

        self::assertSame($sent, $cmd->getPayload());
        self::assertSame([
            [GuardLogEntry::ACTION_OBSERVED_MISMATCH, 'email', 'log_only', 'retail@example.com', 'dealer@example.com'],
            [GuardLogEntry::ACTION_OBSERVED_MISMATCH, 'company', 'log_only', null, 'Motorrad Dealer'],
        ], $this->logged);
    }

    public function testCustomFieldsOfADifferentPersonAreKeptPerKey(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->jsonUpdate($id, ['hinweis_(intern)' => '*Händler', 'anmerkung' => 'alt']);

        $this->guard->guardCustomerCustomFields($cmd, $id, $this->state($id, ['custom_fields' => '{"anmerkung": "alt"}']), new SamePersonGuardConfig(true, true), $this->connector);

        self::assertNull($cmd->getPayload()['hinweis_(intern)'], 'a key the customer did not have is written back as null');
        self::assertSame('alt', $cmd->getPayload()['anmerkung']);
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_MISMATCH, 'custom_fields.hinweis_(intern)', 'enforce', null, '*Händler']], $this->logged);
    }

    public function testAJsonUpdateOfAnotherColumnIsNotOurs(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->jsonUpdate($id, ['x' => 1], 'other_json');

        $this->guard->guardCustomerCustomFields($cmd, $id, $this->state($id), new SamePersonGuardConfig(true, true), $this->connector);

        self::assertSame(['x' => 1], $cmd->getPayload());
        self::assertSame([], $this->logged);
    }
}
