<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuardConfig;

final class FieldGuardConfigTest extends TestCase
{
    public function testDisabledDefaults(): void
    {
        $config = FieldGuardConfig::disabled();

        self::assertFalse($config->enabled);
        self::assertFalse($config->enforce);
        self::assertSame(['customer_group_id'], $config->allowedFields);
        self::assertSame([], $config->allowedCustomFields);
        self::assertSame(FieldGuardConfig::POLICY_LOG, $config->addressCreateDeletePolicy);
        self::assertSame('log_only', $config->mode());
    }

    public function testAllowedAndBookkeepingColumnsAreNotGuarded(): void
    {
        $config = new FieldGuardConfig(true, true, ['customer_group_id'], ['anmerkung'], FieldGuardConfig::POLICY_LOG);

        self::assertTrue($config->isAllowedField('customer_group_id'));
        self::assertTrue($config->isAllowedField('updated_at'), 'bookkeeping');
        self::assertTrue($config->isAllowedField('updated_by_id'), 'bookkeeping');
        self::assertFalse($config->isAllowedField('title'));
        self::assertFalse($config->isAllowedField('vat_ids'));
        self::assertTrue(FieldGuardConfig::isBookkeeping('created_at'));
        self::assertFalse(FieldGuardConfig::isBookkeeping('street'));
    }

    public function testAllowedCustomFieldKeys(): void
    {
        $config = new FieldGuardConfig(true, true, ['customer_group_id'], ['anmerkung', 'hinweis_(intern)'], FieldGuardConfig::POLICY_LOG);

        self::assertTrue($config->isAllowedCustomField('anmerkung'));
        self::assertTrue($config->isAllowedCustomField('hinweis_(intern)'));
        self::assertFalse($config->isAllowedCustomField('paypalexpresspayerid'));
    }

    public function testRejectPolicyOnlyBitesInEnforce(): void
    {
        $logOnly = new FieldGuardConfig(true, false, ['customer_group_id'], [], FieldGuardConfig::POLICY_REJECT_WRITE);
        $enforce = new FieldGuardConfig(true, true, ['customer_group_id'], [], FieldGuardConfig::POLICY_REJECT_WRITE);
        $enforceLog = new FieldGuardConfig(true, true, ['customer_group_id'], [], FieldGuardConfig::POLICY_LOG);

        self::assertFalse($logOnly->rejectsAddressCreateDelete());
        self::assertTrue($enforce->rejectsAddressCreateDelete());
        self::assertFalse($enforceLog->rejectsAddressCreateDelete());
        self::assertSame('enforce', $enforce->mode());
    }
}
