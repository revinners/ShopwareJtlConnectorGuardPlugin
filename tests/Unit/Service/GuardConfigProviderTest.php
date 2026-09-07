<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class GuardConfigProviderTest extends TestCase
{
    private SystemConfigService&MockObject $systemConfig;

    protected function setUp(): void
    {
        $this->systemConfig = $this->createMock(SystemConfigService::class);
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

        return new GuardConfigProvider($this->systemConfig);
    }

    public function testDefaultsWhenNothingIsConfigured(): void
    {
        $config = $this->providerWith([])->load();

        self::assertTrue($config->enabled);
        self::assertFalse($config->enforce, 'ships in log_only');
        self::assertSame(['JTL-Connector'], $config->integrationLabels);
        self::assertSame([], $config->integrationIds);
        self::assertSame(['customer_number'], $config->protectedFields);
    }

    public function testParsesConfiguredValues(): void
    {
        $config = $this->providerWith([
            'enabled' => false,
            'mode' => 'enforce',
            'integrationLabels' => ' JTL-Connector , Wawi Sync ,, ',
            'integrationIds' => "019DF771764772929F1136E52180CCF6,\n2103c0f8ba934cbdb291287aaa3b5ce8, not-a-uuid",
            'protectedFields' => 'customer_group_id, customer_number ,email',
        ])->load('sc-1');

        self::assertFalse($config->enabled);
        self::assertTrue($config->enforce);
        self::assertSame(['JTL-Connector', 'Wawi Sync'], $config->integrationLabels);
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
        $this->systemConfig->expects(self::exactly(10))->method('get')->willReturn(null); // 5 keys x 2 channels

        $provider = new GuardConfigProvider($this->systemConfig);
        $provider->load(null);
        $provider->load(null);
        $provider->load('sc-1');
        $provider->load('sc-1');
    }

    public function testResetClearsTheMemo(): void
    {
        $this->systemConfig->expects(self::exactly(10))->method('get')->willReturn(null);

        $provider = new GuardConfigProvider($this->systemConfig);
        $provider->load(null);
        $provider->reset();
        $provider->load(null);
    }
}
