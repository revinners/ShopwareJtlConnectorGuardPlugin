<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads the plugin config (config.xml, all three cards) into a GuardConfig, per sales channel, memoised per request.
 */
final class GuardConfigProvider
{
    public const CONFIG_PREFIX = 'ShopwareJtlConnectorGuardPlugin.config.';

    public const MODE_LOG_ONLY = 'log_only';

    public const MODE_ENFORCE = 'enforce';

    public const FIELD_CUSTOMER_NUMBER = 'customer_number';

    public const KEY_IDENTITY_ENABLED = 'identityGuardEnabled';

    public const KEY_IDENTITY_MODE = 'identityGuardMode';

    public const KEY_IDENTITY_PROTECT_NAME = 'identityGuardProtectName';

    public const KEY_FIELD_GUARD_ENABLED = 'fieldGuardEnabled';

    public const KEY_FIELD_GUARD_MODE = 'fieldGuardMode';

    public const KEY_ALLOWED_FIELDS = 'allowedFields';

    public const KEY_ALLOWED_CUSTOM_FIELDS = 'allowedCustomFields';

    public const KEY_ADDRESS_CREATE_DELETE_POLICY = 'addressCreateDeletePolicy';

    /** Literal config value meaning "no custom field key is allowed" (null means "use the default list"). */
    public const ALLOWED_CUSTOM_FIELDS_NONE = 'none';

    /** The two Wawi customer notes the connector pushes into the `custom_jtl` set (spec, open question 2). */
    private const DEFAULT_ALLOWED_CUSTOM_FIELDS = ['anmerkung', 'hinweis_(intern)'];

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

        $identityEnabled = $this->get(self::KEY_IDENTITY_ENABLED, $salesChannelId);
        $identityMode = (string) ($this->get(self::KEY_IDENTITY_MODE, $salesChannelId) ?? self::MODE_LOG_ONLY);
        $protectName = (string) ($this->get(self::KEY_IDENTITY_PROTECT_NAME, $salesChannelId) ?? IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP);
        if (!\in_array($protectName, IdentityGuardConfig::PROTECT_NAME_VALUES, true)) {
            $protectName = IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP;
        }

        $identity = new IdentityGuardConfig(
            enabled: $identityEnabled === null ? true : (bool) $identityEnabled,
            enforce: $identityMode === self::MODE_ENFORCE,
            protectName: $protectName,
        );

        $fieldGuardEnabled = $this->get(self::KEY_FIELD_GUARD_ENABLED, $salesChannelId);
        $fieldGuardMode = (string) ($this->get(self::KEY_FIELD_GUARD_MODE, $salesChannelId) ?? self::MODE_LOG_ONLY);

        $allowedFields = array_values(array_unique(array_merge(
            [FieldGuardConfig::FIELD_CUSTOMER_GROUP],
            $this->splitList($this->get(self::KEY_ALLOWED_FIELDS, $salesChannelId)),
        )));

        $rawCustomFields = $this->get(self::KEY_ALLOWED_CUSTOM_FIELDS, $salesChannelId);
        if ($rawCustomFields === null) {
            $allowedCustomFields = self::DEFAULT_ALLOWED_CUSTOM_FIELDS;
        } elseif (\is_string($rawCustomFields) && strtolower(trim($rawCustomFields)) === self::ALLOWED_CUSTOM_FIELDS_NONE) {
            $allowedCustomFields = [];
        } else {
            $allowedCustomFields = $this->splitList($rawCustomFields);
        }

        $policy = (string) ($this->get(self::KEY_ADDRESS_CREATE_DELETE_POLICY, $salesChannelId) ?? FieldGuardConfig::POLICY_LOG);
        if (!\in_array($policy, FieldGuardConfig::POLICY_VALUES, true)) {
            $policy = FieldGuardConfig::POLICY_LOG;
        }

        $fieldGuard = new FieldGuardConfig(
            enabled: $fieldGuardEnabled === null ? true : (bool) $fieldGuardEnabled,
            enforce: $fieldGuardMode === self::MODE_ENFORCE,
            allowedFields: $allowedFields,
            allowedCustomFields: $allowedCustomFields,
            addressCreateDeletePolicy: $policy,
        );

        return $this->memo[$memoKey] = new GuardConfig(
            enabled: $enabled === null ? true : (bool) $enabled,
            enforce: $mode === self::MODE_ENFORCE,
            integrationLabels: $labels,
            integrationIds: $ids,
            protectedFields: $fields,
            identity: $identity,
            fieldGuard: $fieldGuard,
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
