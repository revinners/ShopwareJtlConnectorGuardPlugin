<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

/**
 * One intervention of the guard (spec R4): who, what was kept, what the connector tried, what we did.
 */
final readonly class GuardLogEntry
{
    public const ACTION_BLOCKED_UPDATE = 'blocked_update';

    public const ACTION_REMAPPED_CREATE = 'remapped_create';

    /** A different person's write created an address on the account (cannot be dropped; one row per column). */
    public const ACTION_OBSERVED_ADDRESS_CREATE = 'observed_address_create';

    /** A different person's write deleted an address of the account (cannot be dropped; one row per column). */
    public const ACTION_OBSERVED_ADDRESS_DELETE = 'observed_address_delete';

    /** The whole connector write was rejected (policy reject_write). */
    public const ACTION_REJECTED_WRITE = 'rejected_write';

    /** The write carried a different person's e-mail, the value was kept (enforce). */
    public const ACTION_BLOCKED_MISMATCH = 'blocked_mismatch';

    /** The write carried a different person's e-mail, the value was recorded and applied (log_only). */
    public const ACTION_OBSERVED_MISMATCH = 'observed_mismatch';

    /** A value kept away from a foreign account was applied to the account with the e-mail the write carried. */
    public const ACTION_REROUTED = 'rerouted';

    /** No single registered account with that e-mail exists, nothing was written. */
    public const ACTION_REROUTE_SKIPPED = 'reroute_skipped';

    public const ENTITY_CUSTOMER = 'customer';

    public const ENTITY_CUSTOMER_ADDRESS = 'customer_address';

    public function __construct(
        public string $action,
        public string $mode,
        public string $field,
        public ?string $customerId,
        public ?string $email,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $currentValue,
        public ?string $attemptedValue,
        public ?string $assignedValue,
        public string $integrationId,
        public ?string $integrationLabel,
        public ?string $salesChannelId,
        public string $entity = self::ENTITY_CUSTOMER,
        public ?string $entityId = null,
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
