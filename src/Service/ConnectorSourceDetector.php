<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Decides whether a DAL write comes from the JTL-Connector.
 *
 * The connector authenticates as an Admin API *integration* (client credentials), so its
 * context source is an AdminApiSource with an integration id and no user id. That id must be
 * one of the integrations selected in the plugin config — there is no guessing by name: with
 * nothing selected the plugin guards nothing. Anything that is not a positive match is treated
 * as "not the connector".
 */
final class ConnectorSourceDetector
{
    /** @var array<string, string|null> integration id => label, for the audit log only */
    private array $labels = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<string> $integrationIds lowercase hex ids of the integrations selected as the connector
     */
    public function resolve(Context $context, array $integrationIds): ?ConnectorSource
    {
        $source = $context->getSource();
        if (!$source instanceof AdminApiSource) {
            return null;
        }

        $integrationId = $source->getIntegrationId();
        if ($integrationId === null || $source->getUserId() !== null) {
            return null;
        }

        $integrationId = strtolower($integrationId);
        if (!\in_array($integrationId, $integrationIds, true)) {
            return null;
        }

        return new ConnectorSource($integrationId, $this->label($integrationId));
    }

    public function reset(): void
    {
        $this->labels = [];
    }

    private function label(string $integrationId): ?string
    {
        if (\array_key_exists($integrationId, $this->labels)) {
            return $this->labels[$integrationId];
        }

        try {
            $label = $this->connection->fetchOne(
                'SELECT `label` FROM `integration` WHERE `id` = :id',
                ['id' => Uuid::fromHexToBytes($integrationId)]
            );
        } catch (\Throwable) {
            $label = null; // the label only decorates the audit log; never let it block the guard
        }

        return $this->labels[$integrationId] = \is_string($label) ? $label : null;
    }
}
