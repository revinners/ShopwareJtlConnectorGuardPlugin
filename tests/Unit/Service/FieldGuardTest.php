<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSource;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerState;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuard;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\IdentityGuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Subscriber\CustomerTestDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class FieldGuardTest extends TestCase
{
    private const INTEGRATION_ID = '2103c0f8ba934cbdb291287aaa3b5ce8';

    private EntityDefinition $definition;
    private GuardLogger&MockObject $guardLogger;
    private FieldGuard $guard;
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
        $this->guard = new FieldGuard($this->guardLogger);
        $this->connector = new ConnectorSource(self::INTEGRATION_ID, 'JTL-Connector');
    }

    private function config(bool $enforce, array $allowedFields = [], array $allowedCustomFields = ['anmerkung', 'hinweis_(intern)'], bool $identityEnabled = true): GuardConfig
    {
        return new GuardConfig(
            true,
            true,
            ['JTL-Connector'],
            [],
            ['customer_number'],
            new IdentityGuardConfig($identityEnabled, true, IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP),
            new FieldGuardConfig(true, $enforce, array_merge(['customer_group_id'], $allowedFields), $allowedCustomFields, FieldGuardConfig::POLICY_LOG),
        );
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

    private function state(string $idHex, array $extra = []): CustomerState
    {
        return new CustomerState($idHex, $extra + [
            'id' => Uuid::fromHexToBytes($idHex),
            'customer_number' => 'C10009',
            'email' => 'reischl@t-online.de',
            'first_name' => 'Martin',
            'last_name' => 'Reischl',
            'title' => null,
            'company' => null,
            'vat_ids' => null,
            'active' => '1',
            'customer_group_id' => Uuid::fromHexToBytes('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1'),
            'sales_channel_id' => Uuid::randomBytes(),
            'custom_fields' => '{"hinweis_(intern)":"Stammkunde","payPalExpressPayerId":"PAYER1"}',
        ]);
    }

    public function testEnforceKeepsEveryNonAllowedColumnAndAppliesTheGroup(): void
    {
        $id = Uuid::randomHex();
        $newGroup = Uuid::randomBytes();
        $cmd = $this->update($id, [
            'customer_group_id' => $newGroup,
            'title' => 'Dr.',
            'company' => 'Moto Kraft',
            'vat_ids' => '["DE123456789"]',
            'updated_at' => '2026-09-08 10:00:00.000',
        ]);

        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id), $this->config(enforce: true), $this->connector, [], $cmd->getPayload());

        $payload = $cmd->getPayload();
        self::assertSame($newGroup, $payload['customer_group_id'], 'allowed');
        self::assertNull($payload['title'], 'kept current (null)');
        self::assertNull($payload['company']);
        self::assertNull($payload['vat_ids']);
        self::assertSame('2026-09-08 10:00:00.000', $payload['updated_at'], 'bookkeeping untouched');
        self::assertSame([
            [GuardLogEntry::ACTION_BLOCKED_FIELD, 'title', 'enforce', null, 'Dr.'],
            [GuardLogEntry::ACTION_BLOCKED_FIELD, 'company', 'enforce', null, 'Moto Kraft'],
            [GuardLogEntry::ACTION_BLOCKED_FIELD, 'vat_ids', 'enforce', null, '["DE123456789"]'],
        ], $this->logged);
    }

    public function testLogOnlyRecordsAndApplies(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->update($id, ['title' => 'Dr.', 'active' => false]);

        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id), $this->config(enforce: false), $this->connector, [], $cmd->getPayload());

        self::assertSame('Dr.', $cmd->getPayload()['title']);
        self::assertFalse($cmd->getPayload()['active']);
        self::assertSame([
            [GuardLogEntry::ACTION_OBSERVED_FIELD, 'title', 'log_only', null, 'Dr.'],
            [GuardLogEntry::ACTION_OBSERVED_FIELD, 'active', 'log_only', '1', '0'],
        ], $this->logged);
    }

    public function testUnchangedValuesAreNeitherRevertedNorLogged(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->update($id, ['first_name' => 'Martin', 'active' => true, 'title' => null]);

        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id), $this->config(enforce: true, identityEnabled: false), $this->connector, [], $cmd->getPayload());

        self::assertSame([], $this->logged);
    }

    /**
     * F1 regression: MySQL re-serialises JSON columns with a space after every "," and ":"; the
     * DAL sends compact json_encode output. A structurally identical vat_ids must not be flagged.
     */
    public function testReserialisedJsonColumnIsNotFlaggedAsChanged(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->update($id, ['vat_ids' => '["DE1","DE2"]']);

        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id, ['vat_ids' => '["DE1", "DE2"]']), $this->config(enforce: true), $this->connector, [], $cmd->getPayload());

        self::assertSame('["DE1","DE2"]', $cmd->getPayload()['vat_ids'], 'not reverted: structurally unchanged');
        self::assertSame([], $this->logged, 'no spurious observed/blocked row');
    }

    public function testColumnsOwnedBy001And002AreSkipped(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->update($id, ['customer_number' => '10009', 'email' => 'ramona.kraft@moto-kraft.de', 'last_name' => 'Kraft', 'title' => 'Dr.']);

        // 001 reports customer_number as handled; identity guard enabled owns email/first_name/last_name
        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id), $this->config(enforce: true), $this->connector, ['customer_number'], $cmd->getPayload());

        self::assertSame('10009', $cmd->getPayload()['customer_number'], 'left to 001');
        self::assertSame('ramona.kraft@moto-kraft.de', $cmd->getPayload()['email'], 'left to 002');
        self::assertSame('Kraft', $cmd->getPayload()['last_name'], 'left to 002');
        self::assertNull($cmd->getPayload()['title']);
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_FIELD, 'title', 'enforce', null, 'Dr.']], $this->logged);
    }

    public function testIdentityColumnsFallToTheFieldGuardWhenTheIdentityGuardIsOff(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->update($id, ['last_name' => 'Kraft']);

        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id), $this->config(enforce: true, identityEnabled: false), $this->connector, [], $cmd->getPayload());

        self::assertSame('Reischl', $cmd->getPayload()['last_name']);
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_FIELD, 'last_name', 'enforce', 'Reischl', 'Kraft']], $this->logged);
    }

    public function testUsesThePayloadAsSentNotTheAlreadyRevertedCommand(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->update($id, ['title' => 'Dr.']);
        $sent = $cmd->getPayload();
        $cmd->addPayload('title', null); // something before us already reverted it

        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id), $this->config(enforce: true), $this->connector, [], $sent);

        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_FIELD, 'title', 'enforce', null, 'Dr.']], $this->logged, 'the attempted value comes from $sent');
    }

    public function testCustomFieldsAreGuardedPerKey(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->jsonUpdate($id, [
            'hinweis_(intern)' => 'Neuer Hinweis',
            'anmerkung' => 'Bitte anrufen',
            'paypalexpresspayerid' => 'PAYER2',
            'payPalExpressPayerId' => 'PAYER1',
        ]);

        $this->guard->guardCustomerCustomFields($cmd, $id, $this->state($id), $this->config(enforce: true), $this->connector);

        $payload = $cmd->getPayload();
        self::assertSame('Neuer Hinweis', $payload['hinweis_(intern)'], 'allowed key applied');
        self::assertSame('Bitte anrufen', $payload['anmerkung'], 'allowed key applied');
        self::assertNull($payload['paypalexpresspayerid'], 'guarded key missing in the current JSON is written back as null');
        self::assertSame('PAYER1', $payload['payPalExpressPayerId'], 'unchanged, untouched');
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_FIELD, 'custom_fields.paypalexpresspayerid', 'enforce', null, 'PAYER2']], $this->logged);
    }

    public function testCustomFieldsLogOnlyObservesStructures(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->jsonUpdate($id, ['payPalExpressPayerId' => ['nested' => true]]);

        $this->guard->guardCustomerCustomFields($cmd, $id, $this->state($id), $this->config(enforce: false), $this->connector);

        self::assertSame(['nested' => true], $cmd->getPayload()['payPalExpressPayerId']);
        self::assertSame([[GuardLogEntry::ACTION_OBSERVED_FIELD, 'custom_fields.payPalExpressPayerId', 'log_only', 'PAYER1', '{"nested":true}']], $this->logged);
    }

    public function testNoCustomFieldAllowedWhenTheListIsEmpty(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->jsonUpdate($id, ['anmerkung' => 'x']);

        $this->guard->guardCustomerCustomFields($cmd, $id, $this->state($id), $this->config(enforce: true, allowedCustomFields: []), $this->connector);

        self::assertNull($cmd->getPayload()['anmerkung']);
        self::assertCount(1, $this->logged);
    }

    public function testJsonUpdateOnAnotherColumnIsIgnored(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->jsonUpdate($id, ['k' => 'v'], 'newsletter_sales_channel_ids');

        $this->guard->guardCustomerCustomFields($cmd, $id, $this->state($id), $this->config(enforce: true), $this->connector);

        self::assertSame('v', $cmd->getPayload()['k']);
        self::assertSame([], $this->logged);
    }
}
