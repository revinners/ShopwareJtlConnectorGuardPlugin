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
     * @param IdentityGuardConfig $identity   feature 002 (identity guard); defaults to disabled so pre-002 call sites are unaffected
     * @param FieldGuardConfig $fieldGuard    feature 003 (field allow-list); defaults to disabled so pre-003 call sites are unaffected
     */
    public function __construct(
        public bool $enabled,
        public bool $enforce,
        public array $integrationLabels,
        public array $integrationIds,
        public array $protectedFields,
        public IdentityGuardConfig $identity = new IdentityGuardConfig(false, false, IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP),
        public FieldGuardConfig $fieldGuard = new FieldGuardConfig(false, false, [FieldGuardConfig::FIELD_CUSTOMER_GROUP], [], FieldGuardConfig::POLICY_LOG),
    ) {
    }

    public function mode(): string
    {
        return $this->enforce ? GuardConfigProvider::MODE_ENFORCE : GuardConfigProvider::MODE_LOG_ONLY;
    }
}
