<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

/**
 * Feature 003 configuration: the connector may change only the allow-listed columns of an
 * existing customer (and only the allow-listed custom field keys); everything else on the
 * customer and on its addresses is owned by Shopware. Independent of the number guard (001)
 * and the identity guard (002).
 */
final readonly class FieldGuardConfig
{
    /** The one column the merchant lets JTL-Wawi own. Always on the allow-list. */
    public const FIELD_CUSTOMER_GROUP = 'customer_group_id';

    /** Storage name of the JSON column the DAL updates through JsonUpdateCommand. */
    public const CUSTOM_FIELDS_COLUMN = 'custom_fields';

    /** Address create/delete cannot be dropped from a write: record it (default). */
    public const POLICY_LOG = 'log';

    /** ... or reject the whole connector write (enforce only). */
    public const POLICY_REJECT_WRITE = 'reject_write';

    public const POLICY_VALUES = [self::POLICY_LOG, self::POLICY_REJECT_WRITE];

    /**
     * Columns Shopware writes on every update and that carry no merchant data (spec R5).
     * Never guarded, never logged, on `customer` and on `customer_address` alike.
     */
    public const BOOKKEEPING_COLUMNS = [
        'id',
        'version_id',
        'created_at',
        'created_by_id',
        'updated_at',
        'updated_by_id',
        'auto_increment',
    ];

    /**
     * @param list<string> $allowedFields       storage columns of `customer` the connector may change; always contains customer_group_id
     * @param list<string> $allowedCustomFields custom field keys of `customer` the connector may change
     */
    public function __construct(
        public bool $enabled,
        public bool $enforce,
        public array $allowedFields,
        public array $allowedCustomFields,
        public string $addressCreateDeletePolicy,
    ) {
    }

    public static function disabled(): self
    {
        return new self(false, false, [self::FIELD_CUSTOMER_GROUP], [], self::POLICY_LOG);
    }

    public function mode(): string
    {
        return $this->enforce ? GuardConfigProvider::MODE_ENFORCE : GuardConfigProvider::MODE_LOG_ONLY;
    }

    /**
     * True when the connector may change this `customer` column: allow-listed or bookkeeping.
     */
    public function isAllowedField(string $column): bool
    {
        return self::isBookkeeping($column) || \in_array($column, $this->allowedFields, true);
    }

    public function isAllowedCustomField(string $key): bool
    {
        return \in_array($key, $this->allowedCustomFields, true);
    }

    public static function isBookkeeping(string $column): bool
    {
        return \in_array($column, self::BOOKKEEPING_COLUMNS, true);
    }

    /**
     * The reject policy only bites in enforce: in log_only nothing may ever fail a write.
     */
    public function rejectsAddressCreateDelete(): bool
    {
        return $this->enforce && $this->addressCreateDeletePolicy === self::POLICY_REJECT_WRITE;
    }
}
