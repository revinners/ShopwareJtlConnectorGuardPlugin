<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

/**
 * A write positively identified as coming from the JTL-Connector's Admin API integration.
 */
final readonly class ConnectorSource
{
    public function __construct(
        public string $integrationId,
        public ?string $label,
    ) {
    }
}
