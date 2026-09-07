<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\ShopwareJtlConnectorGuardPlugin;
use Shopware\Core\Framework\Plugin;

final class ShopwareJtlConnectorGuardPluginTest extends TestCase
{
    public function testIsAShopwarePlugin(): void
    {
        self::assertTrue(is_subclass_of(ShopwareJtlConnectorGuardPlugin::class, Plugin::class));
    }

    public function testMonologConfigDeclaresChannelAndDedicatedHandler(): void
    {
        // Bundle::build() needs a full kernel container (kernel.environment, filesystem params),
        // so the prepended config is exposed through a static method and tested directly.
        $config = ShopwareJtlConnectorGuardPlugin::monologConfig();

        self::assertSame(['jtl_connector_guard'], $config['channels']);
        self::assertArrayHasKey('jtl_connector_guard', $config['handlers']);
        self::assertSame('stream', $config['handlers']['jtl_connector_guard']['type']);
        self::assertSame(['jtl_connector_guard'], $config['handlers']['jtl_connector_guard']['channels']);
        self::assertStringContainsString('jtl_connector_guard', $config['handlers']['jtl_connector_guard']['path']);
    }

    public function testConfigXmlDeclaresAllKeys(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../../src/Resources/config/config.xml');

        foreach (['enabled', 'mode', 'integrationLabels', 'integrationIds', 'protectedFields', 'identityGuardEnabled', 'identityGuardMode', 'identityGuardProtectName'] as $key) {
            self::assertStringContainsString('<name>' . $key . '</name>', $xml);
        }
        self::assertStringContainsString('<defaultValue>log_only</defaultValue>', $xml);
        self::assertStringContainsString('<defaultValue>on_email_swap</defaultValue>', $xml);
    }
}
