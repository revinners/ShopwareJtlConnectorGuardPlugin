<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Core\Content\GuardLog;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Read model for the audit table so the trail is searchable via the Admin API
 * (POST /api/search/revinners-jtl-guard-log). Rows are written with plain DBAL by GuardLogger.
 */
class GuardLogDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'revinners_jtl_guard_log';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return GuardLogEntity::class;
    }

    public function getCollectionClass(): string
    {
        return GuardLogCollection::class;
    }

    /**
     * The table has no updated_at column, so only created_at is a default field.
     */
    protected function defaultFields(): array
    {
        return [(new CreatedAtField())->addFlags(new ApiAware())];
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            new IdField('customer_id', 'customerId'),
            new StringField('email', 'email'),
            new StringField('first_name', 'firstName'),
            new StringField('last_name', 'lastName'),
            (new StringField('field', 'field', 64))->addFlags(new Required()),
            new StringField('current_value', 'currentValue'),
            new StringField('attempted_value', 'attemptedValue'),
            new StringField('assigned_value', 'assignedValue'),
            (new StringField('action', 'action', 32))->addFlags(new Required()),
            (new StringField('mode', 'mode', 16))->addFlags(new Required()),
            new IdField('integration_id', 'integrationId'),
            new StringField('integration_label', 'integrationLabel'),
            new IdField('sales_channel_id', 'salesChannelId'),
        ]);
    }
}
