<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Container plugin for every fix we apply on top of the JTL-Connector (JTL-Wawi -> Shopware).
 * Customer number write protection (specs/feat/001-customer-number-write-protection) and the
 * same-person check with reroute (specs/feat/004-same-person-check).
 */
class ShopwareJtlConnectorGuardPlugin extends Plugin
{
    public const LOG_CHANNEL = 'jtl_connector_guard';

    public const LOG_TABLE = 'revinners_jtl_guard_log';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Registering the channel + handler in build() works regardless of whether
        // Shopware picks up Resources/config/packages/*.yaml for this bundle
        // (same approach as revinners/shopware-revinners-search-advance).
        $container->prependExtensionConfig('monolog', self::monologConfig());
    }

    /**
     * Monolog configuration prepended in build(): own channel + own log file.
     *
     * @return array{channels: list<string>, handlers: array<string, array<string, mixed>>}
     */
    public static function monologConfig(): array
    {
        return [
            'channels' => [self::LOG_CHANNEL],
            'handlers' => [
                self::LOG_CHANNEL => [
                    'type' => 'stream',
                    'path' => '%kernel.logs_dir%/' . self::LOG_CHANNEL . '_%kernel.environment%.log',
                    'level' => 'debug',
                    'channels' => [self::LOG_CHANNEL],
                ],
            ],
        ];
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `' . self::LOG_TABLE . '`');
    }
}
