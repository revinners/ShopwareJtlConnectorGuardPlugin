<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

/**
 * Resolved plugin configuration for one sales channel (or the global fallback).
 */
final readonly class GuardConfig
{
    /**
     * @param list<string> $integrationLabels labels of the connector's Admin API integrations
     * @param list<string> $integrationIds    lowercase 32-char hex ids of the connector's integrations
     * @param list<string> $protectedFields   storage column names of `customer` the connector may not change; always contains customer_number
     */
    public function __construct(
        public bool $enabled,
        public bool $enforce,
        public array $integrationLabels,
        public array $integrationIds,
        public array $protectedFields,
    ) {
    }

    public function mode(): string
    {
        return $this->enforce ? GuardConfigProvider::MODE_ENFORCE : GuardConfigProvider::MODE_LOG_ONLY;
    }
}
