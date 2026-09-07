<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Subscriber;

use Psr\Log\LoggerInterface;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSource;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSourceDetector;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerState;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Makes Shopware the owner of `customer.customer_number` against the JTL-Connector.
 *
 * Runs on EntityWriteEvent, which the DBAL EntityWriteGateway dispatches with the exact
 * WriteCommand instances it executes afterwards. For connector writes only:
 *  - UpdateCommand: a changed protected column is reverted to its current DB value
 *    (WriteCommand::addPayload overwrites the key — a key cannot be removed), every other
 *    field of the same write is left alone;
 *  - InsertCommand: the supplied customer_number is replaced by a value reserved from the
 *    shop's own `customer` number range for the customer's sales channel.
 * In log_only mode nothing is changed, only logged. Any internal failure is caught: the
 * write must never be blocked by the guard itself.
 */
final class CustomerNumberWriteProtection implements EventSubscriberInterface
{
    private const FIELD_CUSTOMER_NUMBER = GuardConfigProvider::FIELD_CUSTOMER_NUMBER;

    public function __construct(
        private readonly GuardConfigProvider $configProvider,
        private readonly ConnectorSourceDetector $sourceDetector,
        private readonly CustomerStateLoader $stateLoader,
        private readonly NumberRangeValueGeneratorInterface $numberRangeGenerator,
        private readonly GuardLogger $guardLogger,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $fallbackLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [EntityWriteEvent::class => 'onEntityWrite'];
    }

    public function onEntityWrite(EntityWriteEvent $event): void
    {
        try {
            $this->guard($event);
        } catch (\Throwable $e) {
            $this->log(
                'error',
                'jtl_connector_guard failed, customer write left untouched: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Routes every diagnostic line through the channel logger first, falling back to
     * Shopware's main logger if the channel itself is broken. Never throws: a failure of
     * both loggers must not be able to escape into the DAL write.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            $this->logger->{$level}($message, $context);

            return;
        } catch (\Throwable) {
            // channel logger is broken, fall through to the fallback below
        }

        try {
            $this->fallbackLogger->{$level}($message, $context);
        } catch (\Throwable) {
            // both loggers are broken; swallow, never let logging break the customer write.
        }
    }

    private function guard(EntityWriteEvent $event): void
    {
        $commands = $event->getCommandsForEntity(CustomerDefinition::ENTITY_NAME);
        if ($commands === []) {
            return;
        }

        $context = $event->getContext();
        $source = $context->getSource();
        // Cheap pre-filter: only Admin API integration writes can be the connector.
        if (!$source instanceof AdminApiSource || $source->getIntegrationId() === null || $source->getUserId() !== null) {
            return;
        }

        $globalConfig = $this->configProvider->load(null);
        if (!$globalConfig->enabled) {
            return;
        }

        $connector = $this->sourceDetector->resolve($context, $globalConfig);
        if ($connector === null) {
            $this->log(
                'debug',
                'jtl_connector_guard: admin-api integration write to customer not identified as the connector, left untouched',
                ['integrationId' => strtolower($source->getIntegrationId())]
            );

            return;
        }

        $updates = [];
        $inserts = [];
        foreach ($commands as $command) {
            if ($command instanceof UpdateCommand) {
                $updates[] = $command;
            } elseif ($command instanceof InsertCommand) {
                $inserts[] = $command;
            }
        }

        $this->guardUpdates($updates, $connector);
        $this->guardInserts($inserts, $connector, $context);
    }

    /**
     * @param list<UpdateCommand> $updates
     */
    private function guardUpdates(array $updates, ConnectorSource $connector): void
    {
        if ($updates === []) {
            return;
        }

        $ids = [];
        foreach ($updates as $command) {
            $ids[] = (string) $command->getPrimaryKey()['id'];
        }
        $states = $this->stateLoader->load($ids);

        // Per-command try/catch: one bad row (state loader race, a throwing log sink, ...)
        // must not stop the remaining commands in the same batch from being guarded.
        foreach ($updates as $command) {
            $idHex = Uuid::fromBytesToHex((string) $command->getPrimaryKey()['id']);

            try {
                $this->guardUpdate($command, $idHex, $states[$idHex] ?? null, $connector);
            } catch (\Throwable $e) {
                $this->log(
                    'error',
                    sprintf('jtl_connector_guard: failed to guard customer %s, left untouched: %s', $idHex, $e->getMessage()),
                    ['exception' => $e, 'customerId' => $idHex]
                );
            }
        }
    }

    private function guardUpdate(UpdateCommand $command, string $idHex, ?CustomerState $state, ConnectorSource $connector): void
    {
        if ($state === null) {
            return; // row vanished between extraction and event; nothing to protect
        }

        $config = $this->configProvider->load($state->getSalesChannelId());
        if (!$config->enabled) {
            return;
        }

        $payload = $command->getPayload();
        foreach ($config->protectedFields as $field) {
            if (!$command->hasField($field)) {
                continue;
            }

            $attempted = $payload[$field];
            $current = $state->get($field);
            if ($this->same($attempted, $current)) {
                continue;
            }

            if ($config->enforce) {
                $command->addPayload($field, $current);
            }

            $this->guardLogger->log(new GuardLogEntry(
                action: GuardLogEntry::ACTION_BLOCKED_UPDATE,
                mode: $config->mode(),
                field: $field,
                customerId: $idHex,
                email: $state->getEmail(),
                firstName: $state->getFirstName(),
                lastName: $state->getLastName(),
                currentValue: $this->renderValue($field, $current),
                attemptedValue: $this->renderValue($field, $attempted),
                assignedValue: null,
                integrationId: $connector->integrationId,
                integrationLabel: $connector->label,
                salesChannelId: $state->getSalesChannelId(),
            ));
        }
    }

    /**
     * @param list<InsertCommand> $inserts
     */
    private function guardInserts(array $inserts, ConnectorSource $connector, Context $context): void
    {
        // Per-command try/catch, mirroring guardUpdates: e.g. the number range generator
        // failing for one new customer must not stop the others in the same batch.
        foreach ($inserts as $command) {
            $idHex = $this->hexOrNull($command->getPrimaryKey()['id'] ?? null);

            try {
                $this->guardInsert($command, $connector, $context);
            } catch (\Throwable $e) {
                $this->log(
                    'error',
                    sprintf('jtl_connector_guard: failed to guard new customer %s, left untouched: %s', $idHex ?? 'unknown', $e->getMessage()),
                    ['exception' => $e, 'customerId' => $idHex]
                );
            }
        }
    }

    private function guardInsert(InsertCommand $command, ConnectorSource $connector, Context $context): void
    {
        $payload = $command->getPayload();
        $salesChannelId = $this->hexOrNull($payload['sales_channel_id'] ?? null);

        $config = $this->configProvider->load($salesChannelId);
        if (!$config->enabled) {
            return;
        }

        $attempted = $this->renderValue(self::FIELD_CUSTOMER_NUMBER, $payload[self::FIELD_CUSTOMER_NUMBER] ?? null);
        $assigned = null;
        if ($config->enforce) {
            // Resolve the value first: only once it is known do we touch the payload, so a
            // failing number range generator leaves this insert exactly as the connector sent it.
            $assigned = $this->numberRangeGenerator->getValue(CustomerDefinition::ENTITY_NAME, $context, $salesChannelId);
            $command->addPayload(self::FIELD_CUSTOMER_NUMBER, $assigned);
        }

        $this->guardLogger->log(new GuardLogEntry(
            action: GuardLogEntry::ACTION_REMAPPED_CREATE,
            mode: $config->mode(),
            field: self::FIELD_CUSTOMER_NUMBER,
            customerId: $this->hexOrNull($command->getPrimaryKey()['id'] ?? null),
            email: $this->renderValue('email', $payload['email'] ?? null),
            firstName: $this->renderValue('first_name', $payload['first_name'] ?? null),
            lastName: $this->renderValue('last_name', $payload['last_name'] ?? null),
            currentValue: null,
            attemptedValue: $attempted,
            assignedValue: $assigned,
            integrationId: $connector->integrationId,
            integrationLabel: $connector->label,
            salesChannelId: $salesChannelId,
        ));
    }

    private function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return (string) $a === (string) $b;
    }

    /**
     * Renders a storage-column value for the audit log. Whether a value is a binary id is
     * decided by the column name (storage columns ending in `_id`), never by the value's shape:
     * a plain string can coincidentally be exactly 16 bytes (e.g. "Schröder-Wagner", 16 bytes
     * because of the two-byte "ö"), and guessing from that would corrupt the audit record.
     */
    private function renderValue(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (str_ends_with($field, '_id') && \is_string($value) && \strlen($value) === 16) {
            return Uuid::fromBytesToHex($value);
        }

        return (string) $value;
    }

    private function hexOrNull(mixed $bytes): ?string
    {
        return \is_string($bytes) && \strlen($bytes) === 16 ? Uuid::fromBytesToHex($bytes) : null;
    }
}
