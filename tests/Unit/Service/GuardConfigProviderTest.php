<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\IdentityGuardConfig;
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
        $this->systemConfig->expects(self::exactly(26))->method('get')->willReturn(null); // 13 keys x 2 channels

        $provider = new GuardConfigProvider($this->systemConfig);
        $provider->load(null);
        $provider->load(null);
        $provider->load('sc-1');
        $provider->load('sc-1');
    }

    public function testResetClearsTheMemo(): void
    {
        $this->systemConfig->expects(self::exactly(26))->method('get')->willReturn(null); // 13 keys x 2 channels

        $provider = new GuardConfigProvider($this->systemConfig);
        $provider->load(null);
        $provider->reset();
        $provider->load(null);
    }

    public function testIdentityGuardDefaultsWhenNothingIsConfigured(): void
    {
        $identity = $this->providerWith([])->load()->identity;

        self::assertTrue($identity->enabled);
        self::assertFalse($identity->enforce, 'identity guard ships in log_only');
        self::assertSame(IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP, $identity->protectName);
    }

    public function testIdentityGuardParsesConfiguredValues(): void
    {
        $identity = $this->providerWith([
            'identityGuardEnabled' => false,
            'identityGuardMode' => 'enforce',
            'identityGuardProtectName' => 'always',
        ])->load('sc-1')->identity;

        self::assertFalse($identity->enabled);
        self::assertTrue($identity->enforce);
        self::assertSame(IdentityGuardConfig::PROTECT_NAME_ALWAYS, $identity->protectName);
    }

    public function testIdentityGuardIsIndependentOfTheNumberGuard(): void
    {
        $config = $this->providerWith(['enabled' => false, 'mode' => 'log_only', 'identityGuardMode' => 'enforce'])->load();

        self::assertFalse($config->enabled);
        self::assertFalse($config->enforce);
        self::assertTrue($config->identity->enabled);
        self::assertTrue($config->identity->enforce);
    }

    public function testUnknownIdentityValuesFallBackToDefaults(): void
    {
        $identity = $this->providerWith(['identityGuardMode' => 'yolo', 'identityGuardProtectName' => 'sometimes'])->load()->identity;

        self::assertFalse($identity->enforce);
        self::assertSame(IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP, $identity->protectName);
    }

    public function testFieldGuardDefaultsWhenNothingIsConfigured(): void
    {
        $fieldGuard = $this->providerWith([])->load()->fieldGuard;

        self::assertTrue($fieldGuard->enabled);
        self::assertFalse($fieldGuard->enforce, 'field guard ships in log_only');
        self::assertSame(['customer_group_id'], $fieldGuard->allowedFields);
        self::assertSame(['anmerkung', 'hinweis_(intern)'], $fieldGuard->allowedCustomFields, 'the two Wawi note keys (spec open question 2)');
        self::assertSame(FieldGuardConfig::POLICY_LOG, $fieldGuard->addressCreateDeletePolicy);
    }

    public function testFieldGuardParsesConfiguredValues(): void
    {
        $fieldGuard = $this->providerWith([
            'fieldGuardEnabled' => false,
            'fieldGuardMode' => 'enforce',
            'allowedFields' => ' vat_ids , customer_group_id ,, account_type',
            'allowedCustomFields' => "anmerkung,\n custom_marker ",
            'addressCreateDeletePolicy' => 'reject_write',
        ])->load('sc-1')->fieldGuard;

        self::assertFalse($fieldGuard->enabled);
        self::assertTrue($fieldGuard->enforce);
        self::assertSame(['customer_group_id', 'vat_ids', 'account_type'], $fieldGuard->allowedFields);
        self::assertSame(['anmerkung', 'custom_marker'], $fieldGuard->allowedCustomFields);
        self::assertSame(FieldGuardConfig::POLICY_REJECT_WRITE, $fieldGuard->addressCreateDeletePolicy);
    }

    public function testCustomerGroupIsAlwaysAllowed(): void
    {
        $fieldGuard = $this->providerWith(['allowedFields' => 'title'])->load()->fieldGuard;

        self::assertSame(['customer_group_id', 'title'], $fieldGuard->allowedFields);
    }

    public function testAllowedCustomFieldsNoneMeansNoKeyAllowed(): void
    {
        self::assertSame([], $this->providerWith(['allowedCustomFields' => ' NONE '])->load()->fieldGuard->allowedCustomFields);
        self::assertSame([], $this->providerWith(['allowedCustomFields' => 'none'])->load()->fieldGuard->allowedCustomFields);
    }

    public function testUnknownFieldGuardValuesFallBackToDefaults(): void
    {
        $fieldGuard = $this->providerWith(['fieldGuardMode' => 'yolo', 'addressCreateDeletePolicy' => 'explode'])->load()->fieldGuard;

        self::assertFalse($fieldGuard->enforce);
        self::assertSame(FieldGuardConfig::POLICY_LOG, $fieldGuard->addressCreateDeletePolicy);
    }

    public function testFieldGuardIsIndependentOfTheOtherGuards(): void
    {
        $config = $this->providerWith(['enabled' => false, 'identityGuardEnabled' => false, 'fieldGuardMode' => 'enforce'])->load();

        self::assertFalse($config->enabled);
        self::assertFalse($config->identity->enabled);
        self::assertTrue($config->fieldGuard->enabled);
        self::assertTrue($config->fieldGuard->enforce);
    }
}
