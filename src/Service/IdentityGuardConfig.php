<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

/**
 * Feature 002 configuration: protection of a customer's identity (email, and the name when
 * paired with an email swap) against the JTL-Connector. Independent of the number guard.
 */
final readonly class IdentityGuardConfig
{
    /** Name fields are kept only when the same write also swaps the email (default). */
    public const PROTECT_NAME_ON_EMAIL_SWAP = 'on_email_swap';

    /** Name fields are guarded like the email. */
    public const PROTECT_NAME_ALWAYS = 'always';

    /** Name changes are neither guarded nor logged. */
    public const PROTECT_NAME_OFF = 'off';

    public const PROTECT_NAME_VALUES = [
        self::PROTECT_NAME_ON_EMAIL_SWAP,
        self::PROTECT_NAME_ALWAYS,
        self::PROTECT_NAME_OFF,
    ];

    public function __construct(
        public bool $enabled,
        public bool $enforce,
        public string $protectName,
    ) {
    }

    public static function disabled(): self
    {
        return new self(false, false, self::PROTECT_NAME_ON_EMAIL_SWAP);
    }

    public function mode(): string
    {
        return $this->enforce ? GuardConfigProvider::MODE_ENFORCE : GuardConfigProvider::MODE_LOG_ONLY;
    }
}
