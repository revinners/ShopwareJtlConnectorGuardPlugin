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
