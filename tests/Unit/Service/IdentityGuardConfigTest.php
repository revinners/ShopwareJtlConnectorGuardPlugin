<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\IdentityGuardConfig;

final class IdentityGuardConfigTest extends TestCase
{
    public function testDisabledFactoryIsOffAndLogOnly(): void
    {
        $identity = IdentityGuardConfig::disabled();

        self::assertFalse($identity->enabled);
        self::assertFalse($identity->enforce);
        self::assertSame(IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP, $identity->protectName);
        self::assertSame(GuardConfigProvider::MODE_LOG_ONLY, $identity->mode());
    }

    public function testModeReflectsEnforce(): void
    {
        self::assertSame(GuardConfigProvider::MODE_ENFORCE, (new IdentityGuardConfig(true, true, IdentityGuardConfig::PROTECT_NAME_ALWAYS))->mode());
    }

    public function testGuardConfigDefaultsToADisabledIdentityGuard(): void
    {
        $config = new GuardConfig(true, true, ['JTL-Connector'], [], ['customer_number']);

        self::assertFalse($config->identity->enabled, '5-argument construction (all pre-002 call sites) must keep the identity guard off');
    }
}
