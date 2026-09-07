<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Audit table: one row per blocked / remapped connector write. No FK to `customer`
 * on purpose — the trail must survive customer deletion.
 *
 * @internal
 */
class Migration1788739200CreateJtlGuardLog extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1788739200;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `revinners_jtl_guard_log` (
                `id`                BINARY(16)   NOT NULL,
                `customer_id`       BINARY(16)   NULL,
                `email`             VARCHAR(255) NULL,
                `first_name`        VARCHAR(255) NULL,
                `last_name`         VARCHAR(255) NULL,
                `field`             VARCHAR(64)  NOT NULL,
                `current_value`     VARCHAR(255) NULL,
                `attempted_value`   VARCHAR(255) NULL,
                `assigned_value`    VARCHAR(255) NULL,
                `action`            VARCHAR(32)  NOT NULL,
                `mode`              VARCHAR(16)  NOT NULL,
                `integration_id`    BINARY(16)   NULL,
                `integration_label` VARCHAR(255) NULL,
                `sales_channel_id`  BINARY(16)   NULL,
                `created_at`        DATETIME(3)  NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx.revinners_jtl_guard_log.customer_id` (`customer_id`),
                KEY `idx.revinners_jtl_guard_log.created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive: the table is dropped on uninstall (without keepUserData).
    }
}
