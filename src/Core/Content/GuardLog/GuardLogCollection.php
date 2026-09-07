<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Core\Content\GuardLog;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<GuardLogEntity>
 */
class GuardLogCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return GuardLogEntity::class;
    }
}
