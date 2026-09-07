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
 * Logging must never break the customer write: neither sink (the channel logger or the DB
 * insert) is allowed to throw out of log(). If the channel logger itself is broken (e.g. its
 * StreamHandler cannot open `var/log/jtl_connector_guard_<env>.log`), we report on the
 * fallback logger (Shopware's main channel) instead, and if that also fails, we give up
 * silently — there is nothing left we can safely do without risking the customer write.
 */
final class GuardLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $fallbackLogger,
        private readonly Connection $connection,
    ) {
    }

    public function log(GuardLogEntry $entry): void
    {
        try {
            $this->logger->info($this->message($entry), $entry->toArray());
        } catch (\Throwable $e) {
            $this->reportOnFallback(
                'jtl_connector_guard: could not write channel log: ' . $e->getMessage(),
                ['exception' => $e] + $entry->toArray()
            );
        }

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
            $message = 'jtl_connector_guard: could not persist audit row: ' . $e->getMessage();
            $context = ['exception' => $e] + $entry->toArray();

            try {
                $this->logger->error($message, $context);
            } catch (\Throwable $channelFailure) {
                $this->reportOnFallback($message, $context + ['channelLoggerException' => $channelFailure]);
            }
        }
    }

    /**
     * Last-resort report when the channel logger sink itself failed. Never rethrows: if the
     * fallback logger also fails there is nothing left we can safely do here.
     *
     * @param array<string, mixed> $context
     */
    private function reportOnFallback(string $message, array $context): void
    {
        try {
            $this->fallbackLogger->error($message, $context);
        } catch (\Throwable) {
            // Both sinks are broken. Swallow: logging must never break the customer write.
        }
    }

    /**
     * The message states what actually happened, which differs per mode: in `log_only` the
     * connector's value IS applied, so the line must not claim the old value was kept.
     * Identity actions carry their outcome in the action itself (`blocked_*` = kept,
     * `observed_*` = applied), so they do not depend on `mode`.
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
            match ($entry->action) {
                GuardLogEntry::ACTION_REMAPPED_CREATE => $this->createOutcome($entry),
                GuardLogEntry::ACTION_BLOCKED_IDENTITY => sprintf(
                    'kept "%s", connector sent "%s" (identity guard)',
                    $entry->currentValue ?? '',
                    $entry->attemptedValue ?? '',
                ),
                GuardLogEntry::ACTION_OBSERVED_IDENTITY => sprintf(
                    'connector sent "%s" over "%s" and it was applied (identity guard, observed only)',
                    $entry->attemptedValue ?? '',
                    $entry->currentValue ?? '',
                ),
                default => $this->updateOutcome($entry),
            },
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
