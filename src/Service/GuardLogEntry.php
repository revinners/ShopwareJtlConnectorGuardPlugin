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

    /** Feature 002: the guard wrote the current identity value back (enforce). */
    public const ACTION_BLOCKED_IDENTITY = 'blocked_identity';

    /** Feature 002: an identity change was recorded but applied (log_only, or an unprotected name change). */
    public const ACTION_OBSERVED_IDENTITY = 'observed_identity';

    /** Feature 003: a non-allowed `customer` column was kept (enforce). */
    public const ACTION_BLOCKED_FIELD = 'blocked_field';

    /** Feature 003: a non-allowed `customer` column change was recorded and applied (log_only). */
    public const ACTION_OBSERVED_FIELD = 'observed_field';

    /** Feature 003: a `customer_address` column was kept (enforce). */
    public const ACTION_BLOCKED_ADDRESS = 'blocked_address';

    /** Feature 003: a `customer_address` column change was recorded and applied (log_only). */
    public const ACTION_OBSERVED_ADDRESS = 'observed_address';

    /** Feature 003: the connector created an address of an existing customer (cannot be dropped; one row per column). */
    public const ACTION_OBSERVED_ADDRESS_CREATE = 'observed_address_create';

    /** Feature 003: the connector deleted an address of an existing customer (cannot be dropped; one row per column). */
    public const ACTION_OBSERVED_ADDRESS_DELETE = 'observed_address_delete';

    /** Feature 003: the whole connector write was rejected (policy reject_write). */
    public const ACTION_REJECTED_WRITE = 'rejected_write';

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
