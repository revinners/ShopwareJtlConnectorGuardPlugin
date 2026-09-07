<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Revinners\ShopwareJtlConnectorGuardPlugin\ShopwareJtlConnectorGuardPlugin;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Records every intervention twice: on the `jtl_connector_guard` Monolog channel and in the
 * `revinners_jtl_guard_log` table (plain DBAL insert — no DAL write from inside a write event).
 * Logging must never break the customer write, so DB failures are reported and swallowed.
 */
final class GuardLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Connection $connection,
    ) {
    }

    public function log(GuardLogEntry $entry): void
    {
        $this->logger->info(
            sprintf(
                '[%s] %s: customer %s <%s> %s %s: kept "%s", connector sent "%s"%s (integration %s "%s")',
                $entry->mode,
                $entry->action,
                $entry->customerId ?? 'new',
                $entry->email ?? '-',
                trim(($entry->firstName ?? '') . ' ' . ($entry->lastName ?? '')),
                $entry->field,
                $entry->currentValue ?? '',
                $entry->attemptedValue ?? '',
                $entry->assignedValue !== null ? sprintf(', assigned "%s"', $entry->assignedValue) : '',
                $entry->integrationId,
                $entry->integrationLabel ?? '',
            ),
            $entry->toArray()
        );

        try {
            $this->connection->insert(ShopwareJtlConnectorGuardPlugin::LOG_TABLE, [
                'id' => Uuid::randomBytes(),
                'customer_id' => $entry->customerId !== null ? Uuid::fromHexToBytes($entry->customerId) : null,
                'email' => $entry->email,
                'first_name' => $entry->firstName,
                'last_name' => $entry->lastName,
                'field' => $entry->field,
                'current_value' => $entry->currentValue,
                'attempted_value' => $entry->attemptedValue,
                'assigned_value' => $entry->assignedValue,
                'action' => $entry->action,
                'mode' => $entry->mode,
                'integration_id' => Uuid::fromHexToBytes($entry->integrationId),
                'integration_label' => $entry->integrationLabel,
                'sales_channel_id' => $entry->salesChannelId !== null ? Uuid::fromHexToBytes($entry->salesChannelId) : null,
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'jtl_connector_guard: could not persist audit row: ' . $e->getMessage(),
                ['exception' => $e] + $entry->toArray()
            );
        }
    }
}
