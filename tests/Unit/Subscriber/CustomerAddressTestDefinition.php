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
 * Minimal stand-in for CustomerAddressDefinition: same entity name, id + customer_id and a few
 * scalar columns, no associations.
 */
final class CustomerAddressTestDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'customer_address';
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            new IdField('customer_id', 'customerId'),
            new StringField('street', 'street'),
            new StringField('zipcode', 'zipcode'),
            new StringField('city', 'city'),
        ]);
    }
}
