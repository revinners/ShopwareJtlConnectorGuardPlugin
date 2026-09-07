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
 * context source is an AdminApiSource with an integration id and no user id. That id is
 * matched against the configured ids, or its `integration.label` against the configured
 * labels. Labels are matched ignoring case, whitespace and punctuation (so `JTL Connector`
 * and `JTL-Connector` are the same label). Anything that is not a positive match is treated
 * as "not the connector".
 */
final class ConnectorSourceDetector
{
    /** @var array<string, string|null> integration id => label (null = not found / deleted) */
    private array $labels = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function resolve(Context $context, GuardConfig $config): ?ConnectorSource
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
        $label = $this->label($integrationId);

        if (\in_array($integrationId, $config->integrationIds, true)) {
            return new ConnectorSource($integrationId, $label);
        }

        if ($label === null) {
            return null;
        }

        $normalisedLabel = self::normaliseLabel($label);
        if ($normalisedLabel === '') {
            return null;
        }

        $wanted = array_map(static fn (string $l): string => self::normaliseLabel($l), $config->integrationLabels);
        if (\in_array($normalisedLabel, $wanted, true)) {
            return new ConnectorSource($integrationId, $label);
        }

        return null;
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

        $label = $this->connection->fetchOne(
            'SELECT `label` FROM `integration` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => Uuid::fromHexToBytes($integrationId)]
        );

        return $this->labels[$integrationId] = \is_string($label) ? $label : null;
    }

    /**
     * Lowercases and strips everything but a-z0-9, so labels differing only in case,
     * whitespace or punctuation (e.g. "JTL Connector" vs "JTL-Connector") compare equal.
     * A label with no alphanumeric characters normalises to '' and must never match.
     */
    private static function normaliseLabel(string $label): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($label)) ?? '';
    }
}
