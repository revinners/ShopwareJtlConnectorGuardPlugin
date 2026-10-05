<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

/**
 * Configuration of the same-person check: a connector write carrying the account's own e-mail is
 * applied in full, one carrying a different e-mail is not applied to that account at all and is
 * handed to the account it was meant for.
 */
final readonly class SamePersonGuardConfig
{
    /**
     * What the merchant actually edits in the ERP. Salutation, title and VAT ids are supported
     * but not transferred by default: the connector sends them empty even when JTL-Wawi holds a
     * value (seen on production with `vat_ids`).
     */
    public const DEFAULT_REROUTE_FIELDS = ['customer_group_id', 'first_name', 'last_name', 'company'];

    /** A new or deleted address in a different person's write cannot be dropped: record it (default). */
    public const POLICY_LOG = 'log';

    /** ... or reject the whole connector write (enforce only). */
    public const POLICY_REJECT_WRITE = 'reject_write';

    public const POLICY_VALUES = [self::POLICY_LOG, self::POLICY_REJECT_WRITE];

    /**
     * @param bool         $reroute       apply a write kept away from a foreign account to the registered account with the e-mail it carried (enforce only)
     * @param list<string> $rerouteFields storage columns of `customer` that may be rerouted
     */
    public function __construct(
        public bool $enabled,
        public bool $enforce,
        public bool $reroute = false,
        public array $rerouteFields = self::DEFAULT_REROUTE_FIELDS,
        public string $addressCreateDeletePolicy = self::POLICY_LOG,
    ) {
    }

    public static function disabled(): self
    {
        return new self(false, false);
    }

    public function mode(): string
    {
        return $this->enforce ? GuardConfigProvider::MODE_ENFORCE : GuardConfigProvider::MODE_LOG_ONLY;
    }

    /**
     * Rerouting only makes sense once the foreign account is actually protected.
     */
    public function reroutes(): bool
    {
        return $this->enabled && $this->enforce && $this->reroute;
    }

    /**
     * The reject policy only bites in enforce: in log_only nothing may ever fail a write.
     */
    public function rejectsAddressCreateDelete(): bool
    {
        return $this->enforce && $this->addressCreateDeletePolicy === self::POLICY_REJECT_WRITE;
    }
}
