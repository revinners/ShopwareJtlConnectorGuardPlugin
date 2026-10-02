<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

/**
 * Resolved plugin configuration for one sales channel (or the global fallback).
 */
final readonly class GuardConfig
{
    /**
     * @param bool         $enabled           customer number guard switch
     * @param bool         $enforce           customer number guard mode
     * @param list<string> $integrationIds    lowercase 32-char hex ids of the integrations selected as the connector; empty = nothing is guarded
     * @param list<string> $protectedFields   storage column names of `customer` the connector may not change; always contains customer_number
     * @param SamePersonGuardConfig $samePerson the same-person check; defaults to disabled
     */
    public function __construct(
        public bool $enabled,
        public bool $enforce,
        public array $integrationIds,
        public array $protectedFields,
        public SamePersonGuardConfig $samePerson = new SamePersonGuardConfig(false, false),
    ) {
    }

    public function mode(): string
    {
        return $this->enforce ? GuardConfigProvider::MODE_ENFORCE : GuardConfigProvider::MODE_LOG_ONLY;
    }
}
