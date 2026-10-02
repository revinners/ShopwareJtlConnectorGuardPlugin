<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;

/**
 * The same-person check on the `customer` entity. JTL-Wawi may edit an existing customer freely as long
 * as it is talking about the same person; the e-mail is the test. A connector update whose
 * e-mail differs from the account's is Wawi pushing one customer onto another customer's
 * account (a wrong link on the Wawi side), so nothing of that write may land: every changed
 * column and custom-field key is written back in enforce, and recorded with the value it
 * replaced in log_only.
 *
 * The subscriber decides per write which case applies (isDifferentPerson) and only calls the
 * guard methods for a different person.
 */
final class SamePersonGuard
{
    private const FIELD_EMAIL = 'email';

    public function __construct(private readonly GuardLogger $guardLogger)
    {
    }

    /**
     * A write without an e-mail gives nothing to judge by and is treated as the same person.
     *
     * @param array<string, mixed> $sent the payload exactly as the connector sent it
     */
    public static function isDifferentPerson(array $sent, CustomerState $state): bool
    {
        return \array_key_exists(self::FIELD_EMAIL, $sent) && !Values::sameEmail($sent[self::FIELD_EMAIL], $state->getEmail());
    }

    /**
     * @param list<string>         $reverted columns the number guard (001) already wrote back in this write
     * @param array<string, mixed> $sent     the payload exactly as the connector sent it
     */
    public function guardCustomerColumns(UpdateCommand $command, string $idHex, CustomerState $state, SamePersonGuardConfig $config, ConnectorSource $connector, array $reverted, array $sent): void
    {
        foreach ($sent as $column => $attempted) {
            $column = (string) $column;
            if (Values::isBookkeeping($column) || \in_array($column, $reverted, true)) {
                continue;
            }

            $current = $state->get($column);
            if (Values::sameStorage($attempted, $current)) {
                continue;
            }

            if ($config->enforce) {
                $command->addPayload($column, $current);
            }

            $this->log($config, $column, $idHex, $state, $connector, Values::render($column, $current), Values::render($column, $attempted));
        }
    }

    /**
     * JsonUpdateCommand on `custom_fields` of a customer the same write identified as a different
     * person, per key. A key absent from the current JSON is written back as null (JSON_SET cannot
     * remove a key from inside a write command).
     */
    public function guardCustomerCustomFields(JsonUpdateCommand $command, string $idHex, CustomerState $state, SamePersonGuardConfig $config, ConnectorSource $connector): void
    {
        if ($command->getStorageName() !== Values::CUSTOM_FIELDS_COLUMN) {
            return;
        }

        $current = Values::decodeJson($state->get(Values::CUSTOM_FIELDS_COLUMN));

        foreach ($command->getPayload() as $key => $attempted) {
            $key = (string) $key;
            $currentValue = $current[$key] ?? null;
            if (Values::sameJson($attempted, $currentValue)) {
                continue;
            }

            if ($config->enforce) {
                $command->addPayload($key, $currentValue);
            }

            $field = Values::CUSTOM_FIELDS_COLUMN . '.' . $key;
            $this->log($config, $field, $idHex, $state, $connector, Values::render($field, $currentValue), Values::render($field, $attempted));
        }
    }

    private function log(SamePersonGuardConfig $config, string $field, string $idHex, CustomerState $state, ConnectorSource $connector, ?string $current, ?string $attempted): void
    {
        $this->guardLogger->log(new GuardLogEntry(
            action: $config->enforce ? GuardLogEntry::ACTION_BLOCKED_MISMATCH : GuardLogEntry::ACTION_OBSERVED_MISMATCH,
            mode: $config->mode(),
            field: $field,
            customerId: $idHex,
            email: $state->getEmail(),
            firstName: $state->getFirstName(),
            lastName: $state->getLastName(),
            currentValue: $current,
            attemptedValue: $attempted,
            assignedValue: null,
            integrationId: $connector->integrationId,
            integrationLabel: $connector->label,
            salesChannelId: $state->getSalesChannelId(),
        ));
    }
}
