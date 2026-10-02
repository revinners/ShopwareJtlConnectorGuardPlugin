<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Address rows need to say which address they belong to. `entity` defaults to
 * `customer` so every existing row keeps its meaning without a data migration.
 *
 * @internal
 */
class Migration1789171200AddEntityColumnsToJtlGuardLog extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1789171200;
    }

    public function update(Connection $connection): void
    {
        $existing = $connection->fetchFirstColumn('SHOW COLUMNS FROM `revinners_jtl_guard_log`');

        if (!\in_array('entity', $existing, true)) {
            $connection->executeStatement("
                ALTER TABLE `revinners_jtl_guard_log`
                    ADD COLUMN `entity` VARCHAR(32) NOT NULL DEFAULT 'customer' AFTER `customer_id`
            ");
        }

        if (!\in_array('entity_id', $existing, true)) {
            $connection->executeStatement('
                ALTER TABLE `revinners_jtl_guard_log`
                    ADD COLUMN `entity_id` BINARY(16) NULL AFTER `entity`,
                    ADD KEY `idx.revinners_jtl_guard_log.entity_id` (`entity_id`)
            ');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive: the table is dropped on uninstall (without keepUserData).
    }
}
