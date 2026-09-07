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
        $this->logger->info($this->message($entry), $entry->toArray());

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

    /**
     * The message states what actually happened, which differs per mode: in `log_only` the
     * connector's value IS applied, so the line must not claim the old value was kept.
     */
    private function message(GuardLogEntry $entry): string
    {
        return sprintf(
            '[%s] %s: customer %s <%s> %s %s: %s (integration %s "%s")',
            $entry->mode,
            $entry->action,
            $entry->customerId ?? 'new',
            $entry->email ?? '-',
            trim(($entry->firstName ?? '') . ' ' . ($entry->lastName ?? '')),
            $entry->field,
            $entry->action === GuardLogEntry::ACTION_REMAPPED_CREATE
                ? $this->createOutcome($entry)
                : $this->updateOutcome($entry),
            $entry->integrationId,
            $entry->integrationLabel ?? '',
        );
    }

    private function updateOutcome(GuardLogEntry $entry): string
    {
        if ($entry->mode === GuardConfigProvider::MODE_ENFORCE) {
            return sprintf(
                'kept "%s", connector sent "%s"',
                $entry->currentValue ?? '',
                $entry->attemptedValue ?? '',
            );
        }

        return sprintf(
            'connector sent "%s" over "%s" and it was applied; enforce mode would have kept "%s"',
            $entry->attemptedValue ?? '',
            $entry->currentValue ?? '',
            $entry->currentValue ?? '',
        );
    }

    private function createOutcome(GuardLogEntry $entry): string
    {
        if ($entry->assignedValue !== null) {
            return sprintf(
                'connector sent "%s", assigned "%s" from the shop number range',
                $entry->attemptedValue ?? '',
                $entry->assignedValue,
            );
        }

        return sprintf(
            'connector sent "%s" and it was applied; enforce mode would have assigned a number from the shop range',
            $entry->attemptedValue ?? '',
        );
    }
}
