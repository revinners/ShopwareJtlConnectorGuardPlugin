<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class GuardConfigProviderTest extends TestCase
{
    private SystemConfigService&MockObject $systemConfig;
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $this->connection = $this->createMock(Connection::class);
    }

    /**
     * @param array<string, mixed> $values keyed by short config key (without prefix)
     */
    private function providerWith(array $values): GuardConfigProvider
    {
        $this->systemConfig->method('get')->willReturnCallback(
            static function (string $key, ?string $salesChannelId = null) use ($values): mixed {
                $short = substr($key, \strlen(GuardConfigProvider::CONFIG_PREFIX));

                return $values[$short] ?? null;
            }
        );

        return new GuardConfigProvider($this->systemConfig, $this->connection);
    }

    public function testDefaultsWhenNothingIsConfigured(): void
    {
        $config = $this->providerWith([])->load();

        self::assertTrue($config->enabled);
        self::assertFalse($config->enforce, 'ships in log_only');
        self::assertSame([], $config->integrationIds, 'nothing selected = nothing guarded');
        self::assertSame(['customer_number'], $config->protectedFields);
    }

    public function testParsesConfiguredValues(): void
    {
        $config = $this->providerWith([
            'enabled' => false,
            'mode' => 'enforce',
            'integrationIds' => "019DF771764772929F1136E52180CCF6,\n2103c0f8ba934cbdb291287aaa3b5ce8, not-a-uuid",
            'protectedFields' => 'customer_group_id, customer_number ,email',
        ])->load('sc-1');

        self::assertFalse($config->enabled);
        self::assertTrue($config->enforce);
        self::assertSame(
            ['019df771764772929f1136e52180ccf6', '2103c0f8ba934cbdb291287aaa3b5ce8'],
            $config->integrationIds,
            'ids are lower-cased and invalid entries dropped'
        );
        self::assertSame(['customer_number', 'customer_group_id', 'email'], $config->protectedFields);
    }

    public function testCustomerNumberIsAlwaysProtected(): void
    {
        $config = $this->providerWith(['protectedFields' => 'email'])->load();

        self::assertSame(['customer_number', 'email'], $config->protectedFields);
    }

    public function testUnknownModeFallsBackToLogOnly(): void
    {
        $config = $this->providerWith(['mode' => 'yolo'])->load();

        self::assertFalse($config->enforce);
    }

    public function testLoadIsMemoisedPerSalesChannel(): void
    {
        $this->systemConfig->expects(self::exactly(18))->method('get')->willReturn(null); // 9 keys x 2 channels

        $provider = new GuardConfigProvider($this->systemConfig, $this->connection);
        $provider->load(null);
        $provider->load(null);
        $provider->load('sc-1');
        $provider->load('sc-1');
    }

    public function testResetClearsTheMemo(): void
    {
        $this->systemConfig->expects(self::exactly(18))->method('get')->willReturn(null); // 9 keys x 2 channels

        $provider = new GuardConfigProvider($this->systemConfig, $this->connection);
        $provider->load(null);
        $provider->reset();
        $provider->load(null);
    }

    public function testSamePersonCheckDefaultsWhenNothingIsConfigured(): void
    {
        $samePerson = $this->providerWith([])->load()->samePerson;

        self::assertTrue($samePerson->enabled);
        self::assertFalse($samePerson->enforce, 'ships in log_only');
        self::assertSame('log_only', $samePerson->mode());
    }

    public function testSamePersonCheckParsesConfiguredValues(): void
    {
        $samePerson = $this->providerWith(['samePersonGuardEnabled' => false, 'samePersonGuardMode' => 'enforce'])->load()->samePerson;

        self::assertFalse($samePerson->enabled);
        self::assertTrue($samePerson->enforce);
    }

    public function testUnknownSamePersonModeFallsBackToLogOnly(): void
    {
        self::assertFalse($this->providerWith(['samePersonGuardMode' => 'yolo'])->load()->samePerson->enforce);
    }

    public function testRerouteDefaultsOnWithTheDefaultColumns(): void
    {
        $samePerson = $this->providerWith([])->load()->samePerson;

        self::assertTrue($samePerson->reroute);
        self::assertSame(['customer_group_id', 'first_name', 'last_name', 'company'], $samePerson->rerouteFields);
        self::assertFalse($samePerson->reroutes(), 'log_only never reroutes');
    }

    public function testRerouteColumnsAreLimitedToTheSupportedOnes(): void
    {
        $samePerson = $this->providerWith(['samePersonGuardMode' => 'enforce', 'samePersonRerouteFields' => 'customer_group_id, email, customer_number, company'])->load()->samePerson;

        self::assertSame(['customer_group_id', 'company'], $samePerson->rerouteFields);
        self::assertTrue($samePerson->reroutes());
    }

    public function testRerouteCanBeSwitchedOff(): void
    {
        self::assertFalse($this->providerWith(['samePersonGuardMode' => 'enforce', 'samePersonRerouteEnabled' => false])->load()->samePerson->reroutes());
    }

    public function testAddressCreateDeletePolicyDefaultsToLog(): void
    {
        self::assertSame('log', $this->providerWith([])->load()->samePerson->addressCreateDeletePolicy);
    }

    public function testUnknownAddressCreateDeletePolicyFallsBackToLog(): void
    {
        self::assertSame('log', $this->providerWith(['addressCreateDeletePolicy' => 'yolo'])->load()->samePerson->addressCreateDeletePolicy);
    }

    public function testRejectWritePolicyBitesOnlyInEnforce(): void
    {
        self::assertTrue($this->providerWith(['addressCreateDeletePolicy' => 'reject_write', 'samePersonGuardMode' => 'enforce'])->load()->samePerson->rejectsAddressCreateDelete());
    }

    public function testRejectWritePolicyIsInertInLogOnly(): void
    {
        self::assertFalse($this->providerWith(['addressCreateDeletePolicy' => 'reject_write'])->load()->samePerson->rejectsAddressCreateDelete());
    }

    public function testIntegrationIdsFromTheAdminSelectArriveAsAnArray(): void
    {
        $config = $this->providerWith(['integrationIds' => ['019B8946CCC67767B9FB8CB524300BA1', '', 'not-a-uuid', 42]])->load();

        self::assertSame(['019b8946ccc67767b9fb8cb524300ba1'], $config->integrationIds);
    }

    public function testConnectorIntegrationIdsAreTheUnionOfEveryScope(): void
    {
        // global row, a sales-channel row saved by the admin picker, a legacy comma string, junk
        $this->connection->expects(self::once())->method('fetchFirstColumn')
            ->with(self::stringContains('`system_config`'), ['key' => 'ShopwareJtlConnectorGuardPlugin.config.integrationIds'])
            ->willReturn([
                '{"_value": ["019B8946CCC67767B9FB8CB524300BA1"]}',
                '{"_value": ["2103c0f8ba934cbdb291287aaa3b5ce8", "019b8946ccc67767b9fb8cb524300ba1"]}',
                '{"_value": "e2e00000000000000000000000000001, nope"}',
                'not json',
            ]);

        $provider = new GuardConfigProvider($this->systemConfig, $this->connection);

        self::assertSame(
            ['019b8946ccc67767b9fb8cb524300ba1', '2103c0f8ba934cbdb291287aaa3b5ce8', 'e2e00000000000000000000000000001'],
            $provider->connectorIntegrationIds()
        );
        $provider->connectorIntegrationIds(); // memoised: the query ran once
    }

    public function testNoIntegrationSelectedAnywhereGivesAnEmptyList(): void
    {
        $this->connection->method('fetchFirstColumn')->willReturn([]);

        self::assertSame([], (new GuardConfigProvider($this->systemConfig, $this->connection))->connectorIntegrationIds());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideSwitchValues')]
    public function testSwitchesUnderstandWhatTheConsoleStores(mixed $stored, bool $expected): void
    {
        $config = $this->providerWith(['enabled' => $stored, 'samePersonGuardEnabled' => $stored, 'samePersonRerouteEnabled' => $stored])->load();

        self::assertSame($expected, $config->enabled);
        self::assertSame($expected, $config->samePerson->enabled);
        self::assertSame($expected, $config->samePerson->reroute);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: bool}>
     */
    public static function provideSwitchValues(): iterable
    {
        yield 'admin false' => [false, false];
        yield 'admin true' => [true, true];
        yield 'console "false"' => ['false', false];
        yield 'console "0"' => ['0', false];
        yield 'console "off"' => [' OFF ', false];
        yield 'console "true"' => ['true', true];
        yield 'console "1"' => ['1', true];
        yield 'int 0' => [0, false];
    }
}
