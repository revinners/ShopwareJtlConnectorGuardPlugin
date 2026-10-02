<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads the plugin config (config.xml, both cards) into a GuardConfig, per sales channel, memoised per request.
 */
final class GuardConfigProvider
{
    public const CONFIG_PREFIX = 'ShopwareJtlConnectorGuardPlugin.config.';

    public const MODE_LOG_ONLY = 'log_only';

    public const MODE_ENFORCE = 'enforce';

    public const FIELD_CUSTOMER_NUMBER = 'customer_number';

    public const KEY_ADDRESS_CREATE_DELETE_POLICY = 'addressCreateDeletePolicy';

    public const KEY_SAME_PERSON_ENABLED = 'samePersonGuardEnabled';

    public const KEY_SAME_PERSON_MODE = 'samePersonGuardMode';

    public const KEY_SAME_PERSON_REROUTE = 'samePersonRerouteEnabled';

    public const KEY_SAME_PERSON_REROUTE_FIELDS = 'samePersonRerouteFields';

    /** @var array<string, GuardConfig> */
    private array $memo = [];

    /** @var list<string>|null */
    private ?array $connectorIds = null;

    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly Connection $connection,
    ) {
    }

    /**
     * The integrations selected as the connector in ANY scope of the setting (global or a sales
     * channel). The admin lets the setting be saved with a sales channel selected; reading only
     * the global value would then leave the plugin silently idle.
     *
     * @return list<string> lowercase 32-char hex ids
     */
    public function connectorIntegrationIds(): array
    {
        if ($this->connectorIds !== null) {
            return $this->connectorIds;
        }

        $ids = [];
        $rows = $this->connection->fetchFirstColumn(
            'SELECT `configuration_value` FROM `system_config` WHERE `configuration_key` = :key',
            ['key' => self::CONFIG_PREFIX . 'integrationIds']
        );
        foreach ($rows as $row) {
            $decoded = \is_string($row) ? json_decode($row, true) : null;
            foreach ($this->validIds(\is_array($decoded) ? ($decoded['_value'] ?? null) : null) as $id) {
                $ids[$id] = true;
            }
        }

        return $this->connectorIds = array_keys($ids);
    }

    public function load(?string $salesChannelId = null): GuardConfig
    {
        $memoKey = $salesChannelId ?? '';
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $enabled = $this->get('enabled', $salesChannelId);
        $mode = (string) ($this->get('mode', $salesChannelId) ?? self::MODE_LOG_ONLY);

        $ids = $this->validIds($this->get('integrationIds', $salesChannelId));

        $samePersonEnabled = $this->get(self::KEY_SAME_PERSON_ENABLED, $salesChannelId);
        $samePersonMode = (string) ($this->get(self::KEY_SAME_PERSON_MODE, $salesChannelId) ?? self::MODE_LOG_ONLY);

        $policy = (string) ($this->get(self::KEY_ADDRESS_CREATE_DELETE_POLICY, $salesChannelId) ?? SamePersonGuardConfig::POLICY_LOG);
        if (!\in_array($policy, SamePersonGuardConfig::POLICY_VALUES, true)) {
            $policy = SamePersonGuardConfig::POLICY_LOG;
        }

        $fields = array_values(array_unique(array_merge(
            [self::FIELD_CUSTOMER_NUMBER],
            $this->splitList($this->get('protectedFields', $salesChannelId)),
        )));

        $samePersonReroute = $this->get(self::KEY_SAME_PERSON_REROUTE, $salesChannelId);
        $rerouteFields = array_values(array_intersect(
            $this->splitList($this->get(self::KEY_SAME_PERSON_REROUTE_FIELDS, $salesChannelId)),
            CustomerRerouter::supportedColumns(),
        ));
        if ($rerouteFields === []) {
            $rerouteFields = SamePersonGuardConfig::DEFAULT_REROUTE_FIELDS;
        }

        return $this->memo[$memoKey] = new GuardConfig(
            enabled: self::bool($enabled, true),
            enforce: $mode === self::MODE_ENFORCE,
            integrationIds: $ids,
            protectedFields: $fields,
            samePerson: new SamePersonGuardConfig(
                enabled: self::bool($samePersonEnabled, true),
                enforce: $samePersonMode === self::MODE_ENFORCE,
                reroute: self::bool($samePersonReroute, true),
                rerouteFields: $rerouteFields,
                addressCreateDeletePolicy: $policy,
            ),
        );
    }

    public function reset(): void
    {
        $this->memo = [];
        $this->connectorIds = null;
    }

    /**
     * The admin stores real booleans; `bin/console system:config:set key false` stores the string
     * "false", which a plain (bool) cast would read as true — and a switch that cannot be switched
     * off from the console is the last thing an operator needs.
     */
    private static function bool(mixed $raw, bool $default): bool
    {
        if ($raw === null) {
            return $default;
        }
        if (\is_string($raw)) {
            return !\in_array(strtolower(trim($raw)), ['', '0', 'false', 'off', 'no'], true);
        }

        return (bool) $raw;
    }

    private function get(string $key, ?string $salesChannelId): mixed
    {
        return $this->systemConfig->get(self::CONFIG_PREFIX . $key, $salesChannelId);
    }

    /**
     * @return list<string> lowercase 32-char hex ids, anything else dropped
     */
    private function validIds(mixed $raw): array
    {
        $ids = [];
        foreach ($this->idList($raw) as $id) {
            $id = strtolower($id);
            if (preg_match('/^[0-9a-f]{32}$/', $id) === 1) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The admin's integration select stores a JSON array of ids; a value set by hand or over the
     * CLI may be a comma-separated string.
     *
     * @return list<string>
     */
    private function idList(mixed $raw): array
    {
        if (\is_array($raw)) {
            return array_values(array_filter(array_map(static fn (mixed $v): string => \is_string($v) ? trim($v) : '', $raw), static fn (string $v): bool => $v !== ''));
        }

        return $this->splitList($raw);
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
