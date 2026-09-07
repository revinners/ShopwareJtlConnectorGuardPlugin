<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads the plugin config (config.xml) into a GuardConfig, per sales channel, memoised per request.
 */
final class GuardConfigProvider
{
    public const CONFIG_PREFIX = 'ShopwareJtlConnectorGuardPlugin.config.';

    public const MODE_LOG_ONLY = 'log_only';

    public const MODE_ENFORCE = 'enforce';

    public const FIELD_CUSTOMER_NUMBER = 'customer_number';

    private const DEFAULT_INTEGRATION_LABEL = 'JTL-Connector';

    /** @var array<string, GuardConfig> */
    private array $memo = [];

    public function __construct(private readonly SystemConfigService $systemConfig)
    {
    }

    public function load(?string $salesChannelId = null): GuardConfig
    {
        $memoKey = $salesChannelId ?? '';
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $enabled = $this->get('enabled', $salesChannelId);
        $mode = (string) ($this->get('mode', $salesChannelId) ?? self::MODE_LOG_ONLY);

        $labels = $this->splitList($this->get('integrationLabels', $salesChannelId));
        if ($labels === []) {
            $labels = [self::DEFAULT_INTEGRATION_LABEL];
        }

        $ids = [];
        foreach ($this->splitList($this->get('integrationIds', $salesChannelId)) as $id) {
            $id = strtolower($id);
            if (preg_match('/^[0-9a-f]{32}$/', $id) === 1) {
                $ids[] = $id;
            }
        }

        $fields = array_values(array_unique(array_merge(
            [self::FIELD_CUSTOMER_NUMBER],
            $this->splitList($this->get('protectedFields', $salesChannelId)),
        )));

        return $this->memo[$memoKey] = new GuardConfig(
            enabled: $enabled === null ? true : (bool) $enabled,
            enforce: $mode === self::MODE_ENFORCE,
            integrationLabels: $labels,
            integrationIds: $ids,
            protectedFields: $fields,
        );
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    private function get(string $key, ?string $salesChannelId): mixed
    {
        return $this->systemConfig->get(self::CONFIG_PREFIX . $key, $salesChannelId);
    }

    /**
     * @return list<string>
     */
    private function splitList(mixed $raw): array
    {
        if (!\is_string($raw) || trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[,\n]/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }
}
