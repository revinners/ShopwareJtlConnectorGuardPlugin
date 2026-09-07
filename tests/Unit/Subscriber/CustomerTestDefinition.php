<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Minimal stand-in for CustomerDefinition: same entity name, only the columns the guard
 * touches, no associations (so it compiles in a StaticDefinitionInstanceRegistry without the
 * rest of the core definitions).
 */
final class CustomerTestDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'customer';
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            new IdField('sales_channel_id', 'salesChannelId'),
            new IdField('customer_group_id', 'customerGroupId'),
            new StringField('customer_number', 'customerNumber'),
            new StringField('email', 'email'),
            new StringField('first_name', 'firstName'),
            new StringField('last_name', 'lastName'),
        ]);
    }
}
