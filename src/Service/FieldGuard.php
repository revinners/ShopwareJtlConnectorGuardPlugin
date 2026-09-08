<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;

/**
 * Feature 003 on the `customer` entity: the connector may change only the allow-listed
 * columns (spec R1) and only the allow-listed custom-field keys (spec R1a) of an existing
 * customer. Everything else is written back to its current value in enforce and recorded with
 * that current value in log_only — the record two months of log_only produce is the repair
 * source for the accounts the connector has been overwriting.
 *
 * Never touches inserts, never touches columns 001 (block list) or 002 (identity) already own.
 */
final class FieldGuard
{
    /** Owned by the identity guard whenever it is enabled — never double-handled here. */
    private const IDENTITY_COLUMNS = ['email', 'first_name', 'last_name'];

    public function __construct(private readonly GuardLogger $guardLogger)
    {
    }

    /**
     * Plain UpdateCommand: one guarded item per non-allowed column present in the write.
     *
     * @param list<string>         $handled columns 001's block list already processed (present in the write)
     * @param array<string, mixed> $sent    the payload exactly as the connector sent it, captured before
     *                                      001/002 could revert anything via addPayload()
     */
    public function guardCustomerColumns(UpdateCommand $command, string $idHex, CustomerState $state, GuardConfig $config, ConnectorSource $connector, array $handled, array $sent): void
    {
        $fieldGuard = $config->fieldGuard;

        $owned = $handled;
        if ($config->identity->enabled) {
            $owned = array_merge($owned, self::IDENTITY_COLUMNS);
        }

        foreach ($sent as $column => $attempted) {
            if ($fieldGuard->isAllowedField($column) || \in_array($column, $owned, true)) {
                continue;
            }

            $current = $state->get($column);
            if (Values::sameStorage($attempted, $current)) {
                continue;
            }

            $kept = $fieldGuard->enforce;
            if ($kept) {
                $command->addPayload($column, $current);
            }

            $this->guardLogger->log(new GuardLogEntry(
                action: $kept ? GuardLogEntry::ACTION_BLOCKED_FIELD : GuardLogEntry::ACTION_OBSERVED_FIELD,
                mode: $fieldGuard->mode(),
                field: $column,
                customerId: $idHex,
                email: $state->getEmail(),
                firstName: $state->getFirstName(),
                lastName: $state->getLastName(),
                currentValue: Values::render($column, $current),
                attemptedValue: Values::render($column, $attempted),
                assignedValue: null,
                integrationId: $connector->integrationId,
                integrationLabel: $connector->label,
                salesChannelId: $state->getSalesChannelId(),
            ));
        }
    }

    /**
     * JsonUpdateCommand on `custom_fields`: the payload keys are custom-field keys, merged into
     * the JSON column with JSON_SET, so the guard works per key. A key absent from the current
     * JSON is written back as null (JSON_SET cannot remove a key from inside a write command).
     */
    public function guardCustomerCustomFields(JsonUpdateCommand $command, string $idHex, CustomerState $state, GuardConfig $config, ConnectorSource $connector): void
    {
        if ($command->getStorageName() !== FieldGuardConfig::CUSTOM_FIELDS_COLUMN) {
            return; // core only emits JsonUpdateCommand for custom_fields; anything else is not ours
        }

        $fieldGuard = $config->fieldGuard;
        $current = Values::decodeJson($state->get(FieldGuardConfig::CUSTOM_FIELDS_COLUMN));

        foreach ($command->getPayload() as $key => $attempted) {
            $key = (string) $key;
            if ($fieldGuard->isAllowedCustomField($key)) {
                continue;
            }

            $currentValue = $current[$key] ?? null;
            if (Values::sameJson($attempted, $currentValue)) {
                continue;
            }

            $kept = $fieldGuard->enforce;
            if ($kept) {
                $command->addPayload($key, $currentValue);
            }

            $field = FieldGuardConfig::CUSTOM_FIELDS_COLUMN . '.' . $key;
            $this->guardLogger->log(new GuardLogEntry(
                action: $kept ? GuardLogEntry::ACTION_BLOCKED_FIELD : GuardLogEntry::ACTION_OBSERVED_FIELD,
                mode: $fieldGuard->mode(),
                field: $field,
                customerId: $idHex,
                email: $state->getEmail(),
                firstName: $state->getFirstName(),
                lastName: $state->getLastName(),
                currentValue: Values::render($field, $currentValue),
                attemptedValue: Values::render($field, $attempted),
                assignedValue: null,
                integrationId: $connector->integrationId,
                integrationLabel: $connector->label,
                salesChannelId: $state->getSalesChannelId(),
            ));
        }
    }
}
