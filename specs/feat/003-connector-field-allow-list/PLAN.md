# 003 - Connector Field Allow-List — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend `ShopwareJtlConnectorGuardPlugin` (1.1.0) so that a JTL-Connector update of an existing customer may change **only** `customer_group_id` (plus two Wawi note custom fields), every other `customer` column, every `customer` custom field and every `customer_address` column being kept (`enforce`) or recorded with its pre-write value (`log_only`); address creates/deletes, which the DAL cannot drop, are recorded (or, by policy, reject the whole write). Released as **1.2.0**.

**Architecture:** Same `EntityWriteEvent` subscriber and the same connector detection. Two new services carry the new logic so the subscriber stays a router: `FieldGuard` (allow-list over `customer` columns and per-key over the `custom_fields` `JsonUpdateCommand`) and `AddressGuard` (all `customer_address` commands of the same event). A readonly `FieldGuardConfig` hangs off `GuardConfig` as an optional 7th constructor argument. The audit table gains `entity` / `entity_id` (one migration) so address rows can be keyed; long values are truncated only in the DB sink. A static `Values` helper replaces the subscriber's private compare/render methods so the three guards share one definition of "same".

**Tech Stack:** unchanged — PHP 8.2+, Shopware 6.6.10.x, PHPUnit 11 unit tests with `dg/bypass-finals` (scoped to `src/`), plain DBAL for the audit row.

**Spec:** `specs/feat/003-connector-field-allow-list/SPEC.md` (same folder as this plan). 001's and 002's spec/plan in `specs/feat/001-*` and `specs/feat/002-*` describe the code being extended.

## Global Constraints

- Repo `/Users/macbookpro/Soft/ShopwareJtlConnectorGuardPlugin`, branch `feat/003-connector-field-allow-list` (starts at `master` = `af0a375` = tag `1.1.0`). Consumer shop `/Users/macbookpro/Soft/yam-shop` (composer project in `src/`).
- Spec R6: never guard admin-user, storefront, CLI or non-connector writes (reuse 001's detection, fail-safe to "do nothing"); never abort the write **except** under the explicit `reject_write` policy; one failing command must not stop the others (per-command try/catch); no data repair.
- Spec R1: allow-list `allowedFields` always contains `customer_group_id`. Precedence 001 → 002 → 003: a column already handled by 001's block list (present in the write) or owned by the identity guard (`email`, `first_name`, `last_name` while `identityGuardEnabled`) is never reverted or logged a second time by 003.
- Spec R1a: custom fields arrive as `JsonUpdateCommand` (`getStorageName()` = `custom_fields`), payload keyed by custom-field key, values PHP scalars/arrays (not JSON strings). Guarded per key against `allowedCustomFields` (default `anmerkung,hinweis_(intern)`; the literal value `none` means "no key allowed"). A key missing from the current JSON is written back as `null`.
- Spec R2: address updates have no allow-list. Address inserts for a customer inserted in the same write are ignored. Address inserts/deletes for existing customers are recorded one row per non-bookkeeping column (`observed_address_create` / `observed_address_delete`); with `addressCreateDeletePolicy=reject_write` **and** `fieldGuardMode=enforce` a `WriteConstraintViolationException` is added to the write context instead (one `rejected_write` row).
- Spec R5 bookkeeping columns, never guarded, never logged: `id`, `version_id`, `created_at`, `created_by_id`, `updated_at`, `updated_by_id`, `auto_increment`.
- Config keys (flat, in `ShopwareJtlConnectorGuardPlugin.config.`): `fieldGuardEnabled` (bool, default `true`), `fieldGuardMode` (`log_only` | `enforce`, default `log_only`), `allowedFields` (text, default `customer_group_id`), `allowedCustomFields` (text, default `anmerkung,hinweis_(intern)`), `addressCreateDeletePolicy` (`log` | `reject_write`, default `log`).
- Audit actions exactly: `blocked_field`, `observed_field`, `blocked_address`, `observed_address`, `observed_address_create`, `observed_address_delete`, `rejected_write`. `field` is a storage column name, or `custom_fields.<key>`. New table columns `entity VARCHAR(32) NOT NULL DEFAULT 'customer'` and `entity_id BINARY(16) NULL` via one migration.
- DB sink truncates `current_value` / `attempted_value` / `assigned_value` to 255 characters (254 + `…`); the channel log line keeps the full value.
- Style: `declare(strict_types=1)`, `final` classes, constructor promotion, readonly value objects, English comments. Tests run from the plugin root with `vendor/bin/phpunit` (baseline: 79 tests / 248 assertions green, `failOnWarning`, random order).
- Every git commit message must end with these two trailer lines:
  ```
  Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01QMob74xnUzbhrYWp62Ky2H
  ```
- Release checklist (README): bump `version` in composer.json together with the tag; tags have no `v` prefix.

---

## Design decisions resolved from the spec

| # | Question | Decision |
|---|---|---|
| Same subscriber or new ones? | Spec technical notes: "same subscriber and event" | Same subscriber (`CustomerNumberWriteProtection`) stays the single `EntityWriteEvent` entry point (detection runs once per event). The new logic lives in two injected services, `FieldGuard` and `AddressGuard`, so the subscriber stays ~450 lines and each guard is unit-testable alone. |
| Name-only changes under 003 | R1 vs 002's `on_email_swap` | The identity columns stay owned by 002 whenever `identityGuardEnabled` is on; 003 never touches them. To have names always kept under an allow-list, set `identityGuardProtectName=always`. Documented in README rollout. |
| Where do 003's config values live? | R3 | Third card in the same `config.xml`, same `GuardConfigProvider`, exposed as `GuardConfig::$fieldGuard` (`FieldGuardConfig`). Memoised loads now read 13 keys per channel. |
| `allowedCustomFields` cleared in admin | R1a "empty = every key guarded" | `SystemConfigService::get()` returns `null` both for "never set" and (on some admin saves) for "cleared", so `null` → the default list; the literal `none` → empty list; any other string → split. |
| Rejected write and the audit row | R2 | With `reject_write` the whole DAL transaction rolls back, which may take the `rejected_write` DB row with it. The channel log line is the reliable record for that action; README says so. |
| Bool / JSON comparison | technical notes "do not over-engineer" | `Values::same()` stringifies scalars (bools as `1`/`0`, matching what MySQL returns); `Values::sameJson()` compares arrays/objects by canonical `json_encode`. Measured in `log_only` before `enforce`. |
| `JsonUpdateCommand` on other JSON columns | — | Core only emits it for `custom_fields`; `FieldGuard::guardCustomerCustomFields()` returns early for any other storage name. |

## File structure

```
src/Service/FieldGuardConfig.php               NEW  readonly VO: enabled, enforce, allowedFields, allowedCustomFields, addressCreateDeletePolicy (+ constants, mode(), disabled(), isAllowedField(), isAllowedCustomField(), rejectsAddressCreateDelete())
src/Service/GuardConfig.php                    MOD  7th promoted param `FieldGuardConfig $fieldGuard` with a disabled default
src/Service/GuardConfigProvider.php            MOD  parse the five fieldGuard* / allowed* / addressCreateDeletePolicy keys
src/Service/Values.php                         NEW  static same()/sameJson()/render()/hexOrNull()/decodeJson()
src/Service/GuardLogEntry.php                  MOD  seven new ACTION_* constants, ENTITY_* constants, `entity` + `entityId` props (defaulted)
src/Service/GuardLogger.php                    MOD  insert entity/entity_id, truncate values for the DB sink, message() dispatches the new actions
src/Migration/Migration1789171200AddEntityColumnsToJtlGuardLog.php  NEW  ALTER TABLE add entity, entity_id (+ index)
src/Core/Content/GuardLog/GuardLogDefinition.php MOD  two fields
src/Core/Content/GuardLog/GuardLogEntity.php    MOD  two props + getters
src/Service/CustomerAddressState.php           NEW  readonly row wrapper (id, getCustomerId(), get(), columns())
src/Service/CustomerAddressStateLoader.php     NEW  SELECT * FROM customer_address WHERE id IN (...)
src/Service/FieldGuard.php                     NEW  003 for `customer`: guardCustomerColumns(), guardCustomerCustomFields()
src/Service/AddressGuard.php                   NEW  003 for `customer_address`: guard(event, commands, insertedCustomerIds, deletedCustomerIds, connector)
src/Subscriber/CustomerNumberWriteProtection.php MOD  fetch address commands, route JsonUpdateCommand, call FieldGuard/AddressGuard, use Values
src/Resources/config/services.xml              MOD  three new services, two new subscriber arguments
src/Resources/config/config.xml                MOD  third card with the five fields
README.md / CHANGELOG.md / composer.json / src/ShopwareJtlConnectorGuardPlugin.php  MOD  feature 003 docs, 1.2.0
specs/feat/003-connector-field-allow-list/{SPEC.md,PLAN.md}  committed with Task 1
tests/Unit/Service/FieldGuardConfigTest.php    NEW
tests/Unit/Service/GuardConfigProviderTest.php MOD
tests/Unit/Service/ValuesTest.php              NEW
tests/Unit/Service/GuardLoggerTest.php         MOD
tests/Unit/Service/CustomerAddressStateLoaderTest.php NEW
tests/Unit/Service/FieldGuardTest.php          NEW
tests/Unit/Service/AddressGuardTest.php        NEW
tests/Unit/Subscriber/CustomerAddressTestDefinition.php NEW
tests/Unit/Subscriber/CustomerNumberWriteProtectionTest.php MOD
```

---

### Task 1: FieldGuardConfig, GuardConfig extension, provider parsing, config.xml card

**Files:**
- Create: `src/Service/FieldGuardConfig.php`
- Modify: `src/Service/GuardConfig.php`
- Modify: `src/Service/GuardConfigProvider.php`
- Modify: `src/Resources/config/config.xml`
- Test: `tests/Unit/Service/FieldGuardConfigTest.php` (new), `tests/Unit/Service/GuardConfigProviderTest.php`

**Interfaces:**
- Produces: `FieldGuardConfig` readonly VO — `__construct(bool $enabled, bool $enforce, array $allowedFields, array $allowedCustomFields, string $addressCreateDeletePolicy)`; constants `FIELD_CUSTOMER_GROUP = 'customer_group_id'`, `CUSTOM_FIELDS_COLUMN = 'custom_fields'`, `POLICY_LOG = 'log'`, `POLICY_REJECT_WRITE = 'reject_write'`, `POLICY_VALUES`, `BOOKKEEPING_COLUMNS`; methods `static disabled(): self`, `mode(): string`, `isAllowedField(string): bool`, `isAllowedCustomField(string): bool`, `static isBookkeeping(string): bool`, `rejectsAddressCreateDelete(): bool`.
- Produces: `GuardConfig::$fieldGuard` (7th constructor param, default `FieldGuardConfig::disabled()`).
- Produces: `GuardConfigProvider` constants `KEY_FIELD_GUARD_ENABLED`, `KEY_FIELD_GUARD_MODE`, `KEY_ALLOWED_FIELDS`, `KEY_ALLOWED_CUSTOM_FIELDS`, `KEY_ADDRESS_CREATE_DELETE_POLICY`, `ALLOWED_CUSTOM_FIELDS_NONE = 'none'`.

- [ ] **Step 1: Create the branch and commit the spec + this plan**

```bash
cd /Users/macbookpro/Soft/ShopwareJtlConnectorGuardPlugin
git checkout -b feat/003-connector-field-allow-list master
git add specs/feat/003-connector-field-allow-list
git commit -m "docs(003): spec and plan for the connector field allow-list

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01QMob74xnUzbhrYWp62Ky2H"
```

- [ ] **Step 2: Write the failing VO test**

`tests/Unit/Service/FieldGuardConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuardConfig;

final class FieldGuardConfigTest extends TestCase
{
    public function testDisabledDefaults(): void
    {
        $config = FieldGuardConfig::disabled();

        self::assertFalse($config->enabled);
        self::assertFalse($config->enforce);
        self::assertSame(['customer_group_id'], $config->allowedFields);
        self::assertSame([], $config->allowedCustomFields);
        self::assertSame(FieldGuardConfig::POLICY_LOG, $config->addressCreateDeletePolicy);
        self::assertSame('log_only', $config->mode());
    }

    public function testAllowedAndBookkeepingColumnsAreNotGuarded(): void
    {
        $config = new FieldGuardConfig(true, true, ['customer_group_id'], ['anmerkung'], FieldGuardConfig::POLICY_LOG);

        self::assertTrue($config->isAllowedField('customer_group_id'));
        self::assertTrue($config->isAllowedField('updated_at'), 'bookkeeping');
        self::assertTrue($config->isAllowedField('updated_by_id'), 'bookkeeping');
        self::assertFalse($config->isAllowedField('title'));
        self::assertFalse($config->isAllowedField('vat_ids'));
        self::assertTrue(FieldGuardConfig::isBookkeeping('created_at'));
        self::assertFalse(FieldGuardConfig::isBookkeeping('street'));
    }

    public function testAllowedCustomFieldKeys(): void
    {
        $config = new FieldGuardConfig(true, true, ['customer_group_id'], ['anmerkung', 'hinweis_(intern)'], FieldGuardConfig::POLICY_LOG);

        self::assertTrue($config->isAllowedCustomField('anmerkung'));
        self::assertTrue($config->isAllowedCustomField('hinweis_(intern)'));
        self::assertFalse($config->isAllowedCustomField('paypalexpresspayerid'));
    }

    public function testRejectPolicyOnlyBitesInEnforce(): void
    {
        $logOnly = new FieldGuardConfig(true, false, ['customer_group_id'], [], FieldGuardConfig::POLICY_REJECT_WRITE);
        $enforce = new FieldGuardConfig(true, true, ['customer_group_id'], [], FieldGuardConfig::POLICY_REJECT_WRITE);
        $enforceLog = new FieldGuardConfig(true, true, ['customer_group_id'], [], FieldGuardConfig::POLICY_LOG);

        self::assertFalse($logOnly->rejectsAddressCreateDelete());
        self::assertTrue($enforce->rejectsAddressCreateDelete());
        self::assertFalse($enforceLog->rejectsAddressCreateDelete());
        self::assertSame('enforce', $enforce->mode());
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/FieldGuardConfigTest.php`
Expected: error `Class "Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuardConfig" not found`.

- [ ] **Step 4: Create the VO**

`src/Service/FieldGuardConfig.php`:

```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

/**
 * Feature 003 configuration: the connector may change only the allow-listed columns of an
 * existing customer (and only the allow-listed custom field keys); everything else on the
 * customer and on its addresses is owned by Shopware. Independent of the number guard (001)
 * and the identity guard (002).
 */
final readonly class FieldGuardConfig
{
    /** The one column the merchant lets JTL-Wawi own. Always on the allow-list. */
    public const FIELD_CUSTOMER_GROUP = 'customer_group_id';

    /** Storage name of the JSON column the DAL updates through JsonUpdateCommand. */
    public const CUSTOM_FIELDS_COLUMN = 'custom_fields';

    /** Address create/delete cannot be dropped from a write: record it (default). */
    public const POLICY_LOG = 'log';

    /** ... or reject the whole connector write (enforce only). */
    public const POLICY_REJECT_WRITE = 'reject_write';

    public const POLICY_VALUES = [self::POLICY_LOG, self::POLICY_REJECT_WRITE];

    /**
     * Columns Shopware writes on every update and that carry no merchant data (spec R5).
     * Never guarded, never logged, on `customer` and on `customer_address` alike.
     */
    public const BOOKKEEPING_COLUMNS = [
        'id',
        'version_id',
        'created_at',
        'created_by_id',
        'updated_at',
        'updated_by_id',
        'auto_increment',
    ];

    /**
     * @param list<string> $allowedFields       storage columns of `customer` the connector may change; always contains customer_group_id
     * @param list<string> $allowedCustomFields custom field keys of `customer` the connector may change
     */
    public function __construct(
        public bool $enabled,
        public bool $enforce,
        public array $allowedFields,
        public array $allowedCustomFields,
        public string $addressCreateDeletePolicy,
    ) {
    }

    public static function disabled(): self
    {
        return new self(false, false, [self::FIELD_CUSTOMER_GROUP], [], self::POLICY_LOG);
    }

    public function mode(): string
    {
        return $this->enforce ? GuardConfigProvider::MODE_ENFORCE : GuardConfigProvider::MODE_LOG_ONLY;
    }

    /**
     * True when the connector may change this `customer` column: allow-listed or bookkeeping.
     */
    public function isAllowedField(string $column): bool
    {
        return self::isBookkeeping($column) || \in_array($column, $this->allowedFields, true);
    }

    public function isAllowedCustomField(string $key): bool
    {
        return \in_array($key, $this->allowedCustomFields, true);
    }

    public static function isBookkeeping(string $column): bool
    {
        return \in_array($column, self::BOOKKEEPING_COLUMNS, true);
    }

    /**
     * The reject policy only bites in enforce: in log_only nothing may ever fail a write.
     */
    public function rejectsAddressCreateDelete(): bool
    {
        return $this->enforce && $this->addressCreateDeletePolicy === self::POLICY_REJECT_WRITE;
    }
}
```

- [ ] **Step 5: Run the VO test**

Run: `vendor/bin/phpunit tests/Unit/Service/FieldGuardConfigTest.php`
Expected: 4 tests PASS.

- [ ] **Step 6: Extend GuardConfig**

In `src/Service/GuardConfig.php` add the 7th promoted parameter and its docblock line:

```php
    /**
     * @param list<string> $integrationLabels labels of the connector's Admin API integrations
     * @param list<string> $integrationIds    lowercase 32-char hex ids of the connector's integrations
     * @param list<string> $protectedFields   storage column names of `customer` the connector may not change; always contains customer_number
     * @param IdentityGuardConfig $identity   feature 002 (identity guard); defaults to disabled so pre-002 call sites are unaffected
     * @param FieldGuardConfig $fieldGuard    feature 003 (field allow-list); defaults to disabled so pre-003 call sites are unaffected
     */
    public function __construct(
        public bool $enabled,
        public bool $enforce,
        public array $integrationLabels,
        public array $integrationIds,
        public array $protectedFields,
        public IdentityGuardConfig $identity = new IdentityGuardConfig(false, false, IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP),
        public FieldGuardConfig $fieldGuard = new FieldGuardConfig(false, false, [FieldGuardConfig::FIELD_CUSTOMER_GROUP], [], FieldGuardConfig::POLICY_LOG),
    ) {
    }
```

- [ ] **Step 7: Write the failing provider tests**

Append to `tests/Unit/Service/GuardConfigProviderTest.php` (inside the class), and change the two memo tests' expectation from `self::exactly(16)` to `self::exactly(26)` with the comment `// 13 keys x 2 channels`:

```php
    public function testFieldGuardDefaultsWhenNothingIsConfigured(): void
    {
        $fieldGuard = $this->providerWith([])->load()->fieldGuard;

        self::assertTrue($fieldGuard->enabled);
        self::assertFalse($fieldGuard->enforce, 'field guard ships in log_only');
        self::assertSame(['customer_group_id'], $fieldGuard->allowedFields);
        self::assertSame(['anmerkung', 'hinweis_(intern)'], $fieldGuard->allowedCustomFields, 'the two Wawi note keys (spec open question 2)');
        self::assertSame(FieldGuardConfig::POLICY_LOG, $fieldGuard->addressCreateDeletePolicy);
    }

    public function testFieldGuardParsesConfiguredValues(): void
    {
        $fieldGuard = $this->providerWith([
            'fieldGuardEnabled' => false,
            'fieldGuardMode' => 'enforce',
            'allowedFields' => ' vat_ids , customer_group_id ,, account_type',
            'allowedCustomFields' => "anmerkung,\n custom_marker ",
            'addressCreateDeletePolicy' => 'reject_write',
        ])->load('sc-1')->fieldGuard;

        self::assertFalse($fieldGuard->enabled);
        self::assertTrue($fieldGuard->enforce);
        self::assertSame(['customer_group_id', 'vat_ids', 'account_type'], $fieldGuard->allowedFields);
        self::assertSame(['anmerkung', 'custom_marker'], $fieldGuard->allowedCustomFields);
        self::assertSame(FieldGuardConfig::POLICY_REJECT_WRITE, $fieldGuard->addressCreateDeletePolicy);
    }

    public function testCustomerGroupIsAlwaysAllowed(): void
    {
        $fieldGuard = $this->providerWith(['allowedFields' => 'title'])->load()->fieldGuard;

        self::assertSame(['customer_group_id', 'title'], $fieldGuard->allowedFields);
    }

    public function testAllowedCustomFieldsNoneMeansNoKeyAllowed(): void
    {
        self::assertSame([], $this->providerWith(['allowedCustomFields' => ' NONE '])->load()->fieldGuard->allowedCustomFields);
        self::assertSame([], $this->providerWith(['allowedCustomFields' => 'none'])->load()->fieldGuard->allowedCustomFields);
    }

    public function testUnknownFieldGuardValuesFallBackToDefaults(): void
    {
        $fieldGuard = $this->providerWith(['fieldGuardMode' => 'yolo', 'addressCreateDeletePolicy' => 'explode'])->load()->fieldGuard;

        self::assertFalse($fieldGuard->enforce);
        self::assertSame(FieldGuardConfig::POLICY_LOG, $fieldGuard->addressCreateDeletePolicy);
    }

    public function testFieldGuardIsIndependentOfTheOtherGuards(): void
    {
        $config = $this->providerWith(['enabled' => false, 'identityGuardEnabled' => false, 'fieldGuardMode' => 'enforce'])->load();

        self::assertFalse($config->enabled);
        self::assertFalse($config->identity->enabled);
        self::assertTrue($config->fieldGuard->enabled);
        self::assertTrue($config->fieldGuard->enforce);
    }
```

Add `use Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuardConfig;` to the test's imports.

- [ ] **Step 8: Run the provider tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Service/GuardConfigProviderTest.php`
Expected: the six new tests FAIL (`fieldGuard` is the disabled default, `allowedCustomFields` empty); the two memo tests FAIL on the call count (16 vs 26).

- [ ] **Step 9: Implement the provider parsing**

In `src/Service/GuardConfigProvider.php` add the constants after `KEY_IDENTITY_PROTECT_NAME`:

```php
    public const KEY_FIELD_GUARD_ENABLED = 'fieldGuardEnabled';

    public const KEY_FIELD_GUARD_MODE = 'fieldGuardMode';

    public const KEY_ALLOWED_FIELDS = 'allowedFields';

    public const KEY_ALLOWED_CUSTOM_FIELDS = 'allowedCustomFields';

    public const KEY_ADDRESS_CREATE_DELETE_POLICY = 'addressCreateDeletePolicy';

    /** Literal config value meaning "no custom field key is allowed" (null means "use the default list"). */
    public const ALLOWED_CUSTOM_FIELDS_NONE = 'none';

    /** The two Wawi customer notes the connector pushes into the `custom_jtl` set (spec, open question 2). */
    private const DEFAULT_ALLOWED_CUSTOM_FIELDS = ['anmerkung', 'hinweis_(intern)'];
```

and in `load()`, after the `$identity = new IdentityGuardConfig(...)` block and before the `return`:

```php
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
```

and pass it: `fieldGuard: $fieldGuard,` as the last named argument of `new GuardConfig(...)`. Update the class docblock to "config.xml, all three cards".

- [ ] **Step 10: Run the provider tests**

Run: `vendor/bin/phpunit tests/Unit/Service/GuardConfigProviderTest.php`
Expected: all PASS (16 tests).

- [ ] **Step 11: Add the third card to config.xml**

Append inside `<config>` in `src/Resources/config/config.xml`, after the identity card:

```xml
    <card>
        <title>JTL-Connector Guard — field allow-list (customer + address)</title>
        <title lang="de-DE">JTL-Connector Guard — Feld-Freigabeliste (Kunde + Adresse)</title>
        <title lang="pl-PL">JTL-Connector Guard — lista dozwolonych pól (klient + adres)</title>

        <input-field type="bool">
            <name>fieldGuardEnabled</name>
            <label>Field guard enabled</label>
            <label lang="de-DE">Feldschutz aktiv</label>
            <label lang="pl-PL">Ochrona pól włączona</label>
            <helpText>On an existing customer the JTL-Connector may change only the allowed columns below; every other customer column, custom field and address column is kept (enforce) or recorded (log only). Independent of the two guards above.</helpText>
            <helpText lang="de-DE">Bei bestehenden Kunden darf der JTL-Connector nur die unten freigegebenen Spalten ändern; alle anderen Kundenspalten, Zusatzfelder und Adressspalten werden behalten (Durchsetzen) bzw. protokolliert (Nur protokollieren). Unabhängig von den beiden Schutzfunktionen oben.</helpText>
            <helpText lang="pl-PL">U istniejącego klienta JTL-Connector może zmieniać tylko dozwolone kolumny poniżej; każda inna kolumna klienta, pole dodatkowe i kolumna adresu jest zachowywana (Wymuszaj) lub zapisywana (Tylko loguj). Niezależne od dwóch ochron powyżej.</helpText>
            <defaultValue>true</defaultValue>
        </input-field>

        <input-field type="single-select">
            <name>fieldGuardMode</name>
            <label>Field guard mode</label>
            <label lang="de-DE">Modus Feldschutz</label>
            <label lang="pl-PL">Tryb ochrony pól</label>
            <helpText>"Log only" records every disallowed change with the value it replaced, but applies it (use first on production, for one or two months: the log is the repair source). "Enforce" keeps the current value and applies the rest of the connector's write.</helpText>
            <helpText lang="de-DE">"Nur protokollieren" zeichnet jede nicht freigegebene Änderung samt dem ersetzten Wert auf, wendet sie aber an (zuerst in Produktion, ein bis zwei Monate: das Protokoll ist die Reparaturquelle). "Durchsetzen" behält den aktuellen Wert und wendet den Rest des Connector-Schreibvorgangs an.</helpText>
            <helpText lang="pl-PL">"Tylko loguj" zapisuje każdą niedozwoloną zmianę razem z zastąpioną wartością, ale ją stosuje (użyj najpierw na produkcji, przez miesiąc lub dwa: log jest źródłem naprawy). "Wymuszaj" zachowuje obecną wartość, a resztę zapisu connectora stosuje.</helpText>
            <options>
                <option>
                    <id>log_only</id>
                    <name>Log only</name>
                    <name lang="de-DE">Nur protokollieren</name>
                    <name lang="pl-PL">Tylko loguj</name>
                </option>
                <option>
                    <id>enforce</id>
                    <name>Enforce</name>
                    <name lang="de-DE">Durchsetzen</name>
                    <name lang="pl-PL">Wymuszaj</name>
                </option>
            </options>
            <defaultValue>log_only</defaultValue>
        </input-field>

        <input-field type="text">
            <name>allowedFields</name>
            <label>Allowed customer columns</label>
            <label lang="de-DE">Freigegebene Kundenspalten</label>
            <label lang="pl-PL">Dozwolone kolumny klienta</label>
            <helpText>Comma-separated storage column names of the customer table the connector MAY change on existing customers. "customer_group_id" is always allowed. Columns handled by the guards above (customer_number, email, first_name, last_name) keep their own rules.</helpText>
            <helpText lang="de-DE">Kommagetrennte Spaltennamen der Kundentabelle, die der Connector bei bestehenden Kunden ändern DARF. "customer_group_id" ist immer freigegeben. Spalten der Schutzfunktionen oben (customer_number, email, first_name, last_name) behalten deren Regeln.</helpText>
            <helpText lang="pl-PL">Nazwy kolumn tabeli klienta (po przecinku), które connector MOŻE zmieniać u istniejących klientów. "customer_group_id" jest dozwolone zawsze. Kolumny obsługiwane przez ochrony powyżej (customer_number, email, first_name, last_name) zachowują własne reguły.</helpText>
            <placeholder>customer_group_id</placeholder>
            <defaultValue>customer_group_id</defaultValue>
        </input-field>

        <input-field type="text">
            <name>allowedCustomFields</name>
            <label>Allowed customer custom-field keys</label>
            <label lang="de-DE">Freigegebene Zusatzfeld-Schlüssel des Kunden</label>
            <label lang="pl-PL">Dozwolone klucze pól dodatkowych klienta</label>
            <helpText>Comma-separated custom field keys the connector may write on existing customers. Default: the two JTL-Wawi note fields. Enter "none" to allow no key at all.</helpText>
            <helpText lang="de-DE">Kommagetrennte Zusatzfeld-Schlüssel, die der Connector bei bestehenden Kunden schreiben darf. Standard: die beiden JTL-Wawi-Bemerkungsfelder. "none" eingeben, um keinen Schlüssel freizugeben.</helpText>
            <helpText lang="pl-PL">Klucze pól dodatkowych (po przecinku), które connector może zapisywać u istniejących klientów. Domyślnie: dwa pola uwag z JTL-Wawi. Wpisz "none", aby nie dopuścić żadnego klucza.</helpText>
            <placeholder>anmerkung,hinweis_(intern)</placeholder>
            <defaultValue>anmerkung,hinweis_(intern)</defaultValue>
        </input-field>

        <input-field type="single-select">
            <name>addressCreateDeletePolicy</name>
            <label>Address create / delete by the connector</label>
            <label lang="de-DE">Adresse anlegen / löschen durch den Connector</label>
            <label lang="pl-PL">Dodanie / usunięcie adresu przez connector</label>
            <helpText>A new or deleted address of an EXISTING customer cannot be dropped from the connector's write. "Record" lets it happen and logs every column (default). "Reject write" (enforce only) fails the whole connector write, i.e. every customer in that sync batch. Use only after the log shows it actually happens.</helpText>
            <helpText lang="de-DE">Eine neue oder gelöschte Adresse eines BESTEHENDEN Kunden kann nicht aus dem Schreibvorgang des Connectors entfernt werden. "Protokollieren" lässt es zu und protokolliert jede Spalte (Standard). "Schreibvorgang ablehnen" (nur Durchsetzen) lässt den gesamten Connector-Schreibvorgang scheitern, also jeden Kunden dieses Sync-Pakets. Erst verwenden, wenn das Protokoll zeigt, dass es wirklich vorkommt.</helpText>
            <helpText lang="pl-PL">Nowego lub usuniętego adresu ISTNIEJĄCEGO klienta nie da się usunąć z zapisu connectora. "Zapisz w logu" pozwala na to i loguje każdą kolumnę (domyślnie). "Odrzuć zapis" (tylko w trybie Wymuszaj) odrzuca cały zapis connectora, czyli każdego klienta z tej paczki synchronizacji. Używaj dopiero, gdy log pokaże, że to naprawdę się zdarza.</helpText>
            <options>
                <option>
                    <id>log</id>
                    <name>Record</name>
                    <name lang="de-DE">Protokollieren</name>
                    <name lang="pl-PL">Zapisz w logu</name>
                </option>
                <option>
                    <id>reject_write</id>
                    <name>Reject write</name>
                    <name lang="de-DE">Schreibvorgang ablehnen</name>
                    <name lang="pl-PL">Odrzuć zapis</name>
                </option>
            </options>
            <defaultValue>log</defaultValue>
        </input-field>
    </card>
```

- [ ] **Step 12: Validate the XML and run the whole suite**

Run: `xmllint --noout src/Resources/config/config.xml && vendor/bin/phpunit`
Expected: xmllint silent; PHPUnit `OK (89 tests, ...)`.

- [ ] **Step 13: Commit**

```bash
git add src/Service/FieldGuardConfig.php src/Service/GuardConfig.php src/Service/GuardConfigProvider.php src/Resources/config/config.xml tests/Unit/Service/FieldGuardConfigTest.php tests/Unit/Service/GuardConfigProviderTest.php
git commit -m "feat(003): field guard configuration (allow-list, custom-field keys, address policy)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01QMob74xnUzbhrYWp62Ky2H"
```

---

### Task 2: `Values` helper shared by all guards

**Files:**
- Create: `src/Service/Values.php`
- Modify: `src/Subscriber/CustomerNumberWriteProtection.php` (replace private `same()`, `renderValue()`, `hexOrNull()` with `Values::` calls)
- Test: `tests/Unit/Service/ValuesTest.php` (new)

**Interfaces:**
- Produces: `Values::same(mixed $a, mixed $b): bool`, `Values::sameJson(mixed $a, mixed $b): bool`, `Values::render(string $field, mixed $value): ?string`, `Values::hexOrNull(mixed $bytes): ?string`, `Values::decodeJson(mixed $raw): array`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Service/ValuesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\Values;
use Shopware\Core\Framework\Uuid\Uuid;

final class ValuesTest extends TestCase
{
    public function testSameComparesAsStringsAndIsNullAware(): void
    {
        self::assertTrue(Values::same('10009', '10009'));
        self::assertTrue(Values::same(5, '5'));
        self::assertTrue(Values::same(null, null));
        self::assertFalse(Values::same(null, ''));
        self::assertFalse(Values::same('C10009', '10009'));
    }

    public function testSameTreatsBoolsLikeMysqlTinyint(): void
    {
        self::assertTrue(Values::same(true, '1'));
        self::assertTrue(Values::same(false, '0'));
        self::assertFalse(Values::same(false, '1'));
    }

    public function testSameJsonComparesStructuresCanonically(): void
    {
        self::assertTrue(Values::sameJson(['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]));
        self::assertFalse(Values::sameJson(['a' => 1], ['a' => 2]));
        self::assertTrue(Values::sameJson('note', 'note'));
        self::assertFalse(Values::sameJson('note', null));
        self::assertTrue(Values::sameJson(null, null));
    }

    public function testRenderHexesBinaryIdColumnsByNameOnly(): void
    {
        $hex = Uuid::randomHex();
        self::assertSame($hex, Values::render('customer_group_id', Uuid::fromHexToBytes($hex)));
        self::assertSame('Schröder-Wagner', Values::render('last_name', 'Schröder-Wagner'), '16 bytes but not an id column');
        self::assertNull(Values::render('title', null));
        self::assertSame('1', Values::render('active', true));
        self::assertSame('{"a":1,"b":["x"]}', Values::render('custom_fields.foo', ['a' => 1, 'b' => ['x']]));
    }

    public function testHexOrNull(): void
    {
        $hex = Uuid::randomHex();
        self::assertSame($hex, Values::hexOrNull(Uuid::fromHexToBytes($hex)));
        self::assertNull(Values::hexOrNull('short'));
        self::assertNull(Values::hexOrNull(null));
    }

    public function testDecodeJson(): void
    {
        self::assertSame(['anmerkung' => 'x'], Values::decodeJson('{"anmerkung":"x"}'));
        self::assertSame([], Values::decodeJson(null));
        self::assertSame([], Values::decodeJson('not json'));
        self::assertSame(['k' => 1], Values::decodeJson(['k' => 1]));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/ValuesTest.php`
Expected: error `Class "...\Service\Values" not found`.

- [ ] **Step 3: Create the helper**

`src/Service/Values.php`:

```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Shopware\Core\Framework\Uuid\Uuid;

/**
 * One definition of "same value" and "value for the audit log" shared by the number guard,
 * the identity guard, the field guard and the address guard. Payload values are what the DAL
 * serializers produced (scalars, bools, binary ids, JSON-encoded strings for JSON columns,
 * PHP arrays for custom-field keys); current values are raw DB rows (strings, `1`/`0`, binary).
 */
final class Values
{
    private function __construct()
    {
    }

    /**
     * Scalar comparison as strings. Bools are compared as MySQL returns them (`1` / `0`).
     * null only equals null.
     */
    public static function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return self::scalar($a) === self::scalar($b);
    }

    /**
     * Custom-field values: scalars as in same(), arrays/objects by canonical JSON.
     */
    public static function sameJson(mixed $a, mixed $b): bool
    {
        if (!\is_array($a) && !\is_object($a) && !\is_array($b) && !\is_object($b)) {
            return self::same($a, $b);
        }

        return self::canonical($a) === self::canonical($b);
    }

    /**
     * Renders a value for the audit log. Whether a value is a binary id is decided by the
     * column name (storage columns ending in `_id`), never by the value's shape: a plain string
     * can coincidentally be exactly 16 bytes (e.g. "Schröder-Wagner"), and guessing from that
     * would corrupt the audit record. Arrays (custom-field structures) are rendered as JSON.
     */
    public static function render(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (str_ends_with($field, '_id') && \is_string($value) && \strlen($value) === 16) {
            return Uuid::fromBytesToHex($value);
        }

        if (\is_array($value) || \is_object($value)) {
            return json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: null;
        }

        return self::scalar($value);
    }

    public static function hexOrNull(mixed $bytes): ?string
    {
        return \is_string($bytes) && \strlen($bytes) === 16 ? Uuid::fromBytesToHex($bytes) : null;
    }

    /**
     * Decodes a JSON column value (as returned by DBAL) into an array; anything unreadable is [].
     *
     * @return array<string, mixed>
     */
    public static function decodeJson(mixed $raw): array
    {
        if (\is_array($raw)) {
            return $raw;
        }
        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    private static function scalar(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private static function canonical(mixed $value): string
    {
        if (\is_array($value)) {
            ksort($value);
            foreach ($value as $k => $v) {
                if (\is_array($v)) {
                    $value[$k] = json_decode(self::canonical($v), true);
                }
            }
        }

        return json_encode($value, \JSON_UNESCAPED_UNICODE) ?: '';
    }
}
```

- [ ] **Step 4: Run the helper test**

Run: `vendor/bin/phpunit tests/Unit/Service/ValuesTest.php`
Expected: 6 tests PASS.

- [ ] **Step 5: Switch the subscriber to the helper**

In `src/Subscriber/CustomerNumberWriteProtection.php`:
- add `use Revinners\ShopwareJtlConnectorGuardPlugin\Service\Values;`
- delete the private methods `same()`, `renderValue()` and `hexOrNull()` (and the `Uuid` import stays: `guardUpdates()` still uses `Uuid::fromBytesToHex`)
- replace every `$this->same(` with `Values::same(`, every `$this->renderValue(` with `Values::render(`, every `$this->hexOrNull(` with `Values::hexOrNull(`.

Run: `grep -n 'this->same(\|this->renderValue(\|this->hexOrNull(' src/Subscriber/CustomerNumberWriteProtection.php`
Expected: no output.

- [ ] **Step 6: Run the whole suite**

Run: `vendor/bin/phpunit`
Expected: `OK (95 tests, ...)` — the existing subscriber tests prove the swap is behaviour-neutral.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Values.php src/Subscriber/CustomerNumberWriteProtection.php tests/Unit/Service/ValuesTest.php
git commit -m "refactor(003): shared Values helper for compare/render, used by the subscriber

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01QMob74xnUzbhrYWp62Ky2H"
```

---

### Task 3: Audit trail — new actions, `entity` / `entity_id`, truncation, migration, DAL definition

**Files:**
- Modify: `src/Service/GuardLogEntry.php`
- Modify: `src/Service/GuardLogger.php`
- Create: `src/Migration/Migration1789171200AddEntityColumnsToJtlGuardLog.php`
- Modify: `src/Core/Content/GuardLog/GuardLogDefinition.php`, `src/Core/Content/GuardLog/GuardLogEntity.php`
- Test: `tests/Unit/Service/GuardLoggerTest.php`

**Interfaces:**
- Produces: `GuardLogEntry` constants `ENTITY_CUSTOMER = 'customer'`, `ENTITY_CUSTOMER_ADDRESS = 'customer_address'`, `ACTION_BLOCKED_FIELD`, `ACTION_OBSERVED_FIELD`, `ACTION_BLOCKED_ADDRESS`, `ACTION_OBSERVED_ADDRESS`, `ACTION_OBSERVED_ADDRESS_CREATE`, `ACTION_OBSERVED_ADDRESS_DELETE`, `ACTION_REJECTED_WRITE`; two trailing constructor params `public string $entity = self::ENTITY_CUSTOMER, public ?string $entityId = null`.
- Produces: `GuardLogger` writes `entity`, `entity_id` and truncates the three value columns.

- [ ] **Step 1: Write the failing logger tests**

Append to `tests/Unit/Service/GuardLoggerTest.php`:

```php
    public function testAddressRowCarriesEntityAndEntityIdAndTruncatesLongValues(): void
    {
        $addressId = Uuid::randomHex();
        $long = str_repeat('x', 300);
        $entry = new GuardLogEntry(
            action: GuardLogEntry::ACTION_BLOCKED_ADDRESS,
            mode: 'enforce',
            field: 'street',
            customerId: '019df771764772929f1136e52180ccf6',
            email: 'erdoesi@example.com',
            firstName: 'Adam',
            lastName: 'Erdösi',
            currentValue: 'Nelkenweg 12',
            attemptedValue: $long,
            assignedValue: null,
            integrationId: '2103c0f8ba934cbdb291287aaa3b5ce8',
            integrationLabel: 'JTL-Connector',
            salesChannelId: null,
            entity: GuardLogEntry::ENTITY_CUSTOMER_ADDRESS,
            entityId: $addressId,
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::logicalAnd(
                self::stringContains('blocked_address'),
                self::stringContains('customer_address ' . $addressId . '.street'),
                self::stringContains('kept "Nelkenweg 12"'),
                self::stringContains($long),
            ),
            self::callback(static fn (array $ctx): bool => $ctx['entity'] === 'customer_address' && $ctx['entityId'] === $addressId && $ctx['attemptedValue'] === $long)
        );
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with(
            'revinners_jtl_guard_log',
            self::callback(static function (array $row) use ($addressId): bool {
                return $row['entity'] === 'customer_address'
                    && $row['entity_id'] === Uuid::fromHexToBytes($addressId)
                    && $row['field'] === 'street'
                    && mb_strlen($row['attempted_value']) === 255
                    && str_ends_with($row['attempted_value'], '…')
                    && $row['current_value'] === 'Nelkenweg 12';
            })
        );

        (new GuardLogger($logger, $this->createMock(LoggerInterface::class), $connection))->log($entry);
    }

    public function testCustomerRowDefaultsEntityToCustomerWithoutEntityId(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with(
            'revinners_jtl_guard_log',
            self::callback(static fn (array $row): bool => $row['entity'] === 'customer' && $row['entity_id'] === null)
        );

        (new GuardLogger($logger, $this->createMock(LoggerInterface::class), $connection))->log($this->entry());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function fieldGuardMessages(): iterable
    {
        yield 'blocked_field' => [GuardLogEntry::ACTION_BLOCKED_FIELD, 'enforce', 'kept "Nelkenweg 12", connector sent "Grasiger Weg 20" (field guard)'];
        yield 'observed_field' => [GuardLogEntry::ACTION_OBSERVED_FIELD, 'log_only', 'connector sent "Grasiger Weg 20" over "Nelkenweg 12" and it was applied (field guard, observed only)'];
        yield 'blocked_address' => [GuardLogEntry::ACTION_BLOCKED_ADDRESS, 'enforce', 'kept "Nelkenweg 12", connector sent "Grasiger Weg 20" (field guard)'];
        yield 'observed_address' => [GuardLogEntry::ACTION_OBSERVED_ADDRESS, 'log_only', 'connector sent "Grasiger Weg 20" over "Nelkenweg 12" and it was applied (field guard, observed only)'];
        yield 'observed_address_create' => [GuardLogEntry::ACTION_OBSERVED_ADDRESS_CREATE, 'enforce', 'connector created it with "Grasiger Weg 20" (cannot be blocked, recorded)'];
        yield 'observed_address_delete' => [GuardLogEntry::ACTION_OBSERVED_ADDRESS_DELETE, 'enforce', 'connector deleted it, had "Nelkenweg 12" (cannot be blocked, recorded)'];
        yield 'rejected_write' => [GuardLogEntry::ACTION_REJECTED_WRITE, 'enforce', 'whole connector write rejected (policy reject_write)'];
    }

    #[DataProvider('fieldGuardMessages')]
    public function testFieldGuardMessages(string $action, string $mode, string $expected): void
    {
        $entry = new GuardLogEntry(
            action: $action,
            mode: $mode,
            field: 'street',
            customerId: '019df771764772929f1136e52180ccf6',
            email: 'erdoesi@example.com',
            firstName: 'Adam',
            lastName: 'Erdösi',
            currentValue: 'Nelkenweg 12',
            attemptedValue: 'Grasiger Weg 20',
            assignedValue: null,
            integrationId: '2103c0f8ba934cbdb291287aaa3b5ce8',
            integrationLabel: 'JTL-Connector',
            salesChannelId: null,
            entity: GuardLogEntry::ENTITY_CUSTOMER_ADDRESS,
            entityId: 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4',
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains($expected), self::anything());

        (new GuardLogger($logger, $this->createMock(LoggerInterface::class), $this->createMock(Connection::class)))->log($entry);
    }
```

Add `use PHPUnit\Framework\Attributes\DataProvider;` to the test's imports (PHPUnit 11 deprecates the `@dataProvider` annotation).

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Service/GuardLoggerTest.php`
Expected: errors — unknown named parameter `entity`, undefined constants.

- [ ] **Step 3: Extend GuardLogEntry**

In `src/Service/GuardLogEntry.php` add after `ACTION_OBSERVED_IDENTITY`:

```php
    /** Feature 003: a non-allowed `customer` column was kept (enforce). */
    public const ACTION_BLOCKED_FIELD = 'blocked_field';

    /** Feature 003: a non-allowed `customer` column change was recorded and applied (log_only). */
    public const ACTION_OBSERVED_FIELD = 'observed_field';

    /** Feature 003: a `customer_address` column was kept (enforce). */
    public const ACTION_BLOCKED_ADDRESS = 'blocked_address';

    /** Feature 003: a `customer_address` column change was recorded and applied (log_only). */
    public const ACTION_OBSERVED_ADDRESS = 'observed_address';

    /** Feature 003: the connector created an address of an existing customer (cannot be dropped; one row per column). */
    public const ACTION_OBSERVED_ADDRESS_CREATE = 'observed_address_create';

    /** Feature 003: the connector deleted an address of an existing customer (cannot be dropped; one row per column). */
    public const ACTION_OBSERVED_ADDRESS_DELETE = 'observed_address_delete';

    /** Feature 003: the whole connector write was rejected (policy reject_write). */
    public const ACTION_REJECTED_WRITE = 'rejected_write';

    public const ENTITY_CUSTOMER = 'customer';

    public const ENTITY_CUSTOMER_ADDRESS = 'customer_address';
```

and two trailing constructor parameters:

```php
        public ?string $salesChannelId,
        public string $entity = self::ENTITY_CUSTOMER,
        public ?string $entityId = null,
    ) {
```

- [ ] **Step 4: Extend GuardLogger**

In `src/Service/GuardLogger.php`:

(a) in the `insert()` array add after `'customer_id' => ...`:

```php
                'entity' => $entry->entity,
                'entity_id' => $entry->entityId !== null ? Uuid::fromHexToBytes($entry->entityId) : null,
```

and change the three value columns to `'current_value' => self::truncate($entry->currentValue)`, `'attempted_value' => self::truncate($entry->attemptedValue)`, `'assigned_value' => self::truncate($entry->assignedValue)`.

(b) add the helper:

```php
    /**
     * The table columns are VARCHAR(255); JSON values (custom_fields, vat_ids) can be longer.
     * Only the DB sink is truncated — the channel log line above carries the full value.
     */
    private static function truncate(?string $value): ?string
    {
        if ($value === null || mb_strlen($value) <= 255) {
            return $value;
        }

        return mb_substr($value, 0, 254) . '…';
    }
```

(c) in `message()` replace the `$entry->field,` argument with `$this->fieldLabel($entry),` and add the new `match` arms before `default`:

```php
                GuardLogEntry::ACTION_BLOCKED_FIELD,
                GuardLogEntry::ACTION_BLOCKED_ADDRESS => sprintf(
                    'kept "%s", connector sent "%s" (field guard)',
                    $entry->currentValue ?? '',
                    $entry->attemptedValue ?? '',
                ),
                GuardLogEntry::ACTION_OBSERVED_FIELD,
                GuardLogEntry::ACTION_OBSERVED_ADDRESS => sprintf(
                    'connector sent "%s" over "%s" and it was applied (field guard, observed only)',
                    $entry->attemptedValue ?? '',
                    $entry->currentValue ?? '',
                ),
                GuardLogEntry::ACTION_OBSERVED_ADDRESS_CREATE => sprintf(
                    'connector created it with "%s" (cannot be blocked, recorded)',
                    $entry->attemptedValue ?? '',
                ),
                GuardLogEntry::ACTION_OBSERVED_ADDRESS_DELETE => sprintf(
                    'connector deleted it, had "%s" (cannot be blocked, recorded)',
                    $entry->currentValue ?? '',
                ),
                GuardLogEntry::ACTION_REJECTED_WRITE => 'whole connector write rejected (policy reject_write)',
```

and the helper:

```php
    /**
     * `street` alone is ambiguous once addresses are logged: prefix non-customer rows with the
     * entity and its id so a line reads "customer_address <id>.street".
     */
    private function fieldLabel(GuardLogEntry $entry): string
    {
        if ($entry->entity === GuardLogEntry::ENTITY_CUSTOMER) {
            return $entry->field;
        }

        return sprintf('%s %s.%s', $entry->entity, $entry->entityId ?? '?', $entry->field);
    }
```

Update the class docblock's second sentence to mention the truncation.

- [ ] **Step 5: Run the logger tests**

Run: `vendor/bin/phpunit tests/Unit/Service/GuardLoggerTest.php`
Expected: all PASS.

- [ ] **Step 6: Migration**

`src/Migration/Migration1789171200AddEntityColumnsToJtlGuardLog.php`:

```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Feature 003: address rows need to say which address they belong to. `entity` defaults to
 * `customer` so every existing row keeps its meaning without a data migration.
 *
 * @internal
 */
class Migration1789171200AddEntityColumnsToJtlGuardLog extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1789171200;
    }

    public function update(Connection $connection): void
    {
        $existing = $connection->fetchFirstColumn('SHOW COLUMNS FROM `revinners_jtl_guard_log`');

        if (!\in_array('entity', $existing, true)) {
            $connection->executeStatement("
                ALTER TABLE `revinners_jtl_guard_log`
                    ADD COLUMN `entity` VARCHAR(32) NOT NULL DEFAULT 'customer' AFTER `customer_id`
            ");
        }

        if (!\in_array('entity_id', $existing, true)) {
            $connection->executeStatement('
                ALTER TABLE `revinners_jtl_guard_log`
                    ADD COLUMN `entity_id` BINARY(16) NULL AFTER `entity`,
                    ADD KEY `idx.revinners_jtl_guard_log.entity_id` (`entity_id`)
            ');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive: the table is dropped on uninstall (without keepUserData).
    }
}
```

Also add the two columns to the `CREATE TABLE` in `Migration1788739200CreateJtlGuardLog.php` (fresh installs get them from the first migration; the second is then a no-op thanks to the column check): after `` `customer_id` BINARY(16) NULL, `` insert

```sql
                `entity`            VARCHAR(32)  NOT NULL DEFAULT 'customer',
                `entity_id`         BINARY(16)   NULL,
```

and add `KEY `idx.revinners_jtl_guard_log.entity_id` (`entity_id`),` before the `created_at` key.

- [ ] **Step 7: DAL definition + entity**

In `GuardLogDefinition::defineFields()` after `new IdField('customer_id', 'customerId'),` add:

```php
            (new StringField('entity', 'entity', 32))->addFlags(new Required()),
            new IdField('entity_id', 'entityId'),
```

In `GuardLogEntity` add after `$customerId`:

```php
    protected string $entity = 'customer';

    protected ?string $entityId = null;
```

and the getters:

```php
    public function getEntity(): string
    {
        return $this->entity;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }
```

- [ ] **Step 8: Run the whole suite and lint**

Run: `vendor/bin/phpunit && php -l src/Migration/Migration1789171200AddEntityColumnsToJtlGuardLog.php`
Expected: `OK (105 tests, ...)`; `No syntax errors detected`.

- [ ] **Step 9: Commit**

```bash
git add src/Service/GuardLogEntry.php src/Service/GuardLogger.php src/Migration src/Core/Content/GuardLog tests/Unit/Service/GuardLoggerTest.php
git commit -m "feat(003): audit actions for the field guard, entity/entity_id columns, value truncation

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01QMob74xnUzbhrYWp62Ky2H"
```

---

### Task 4: `CustomerAddressState` and `CustomerAddressStateLoader`

**Files:**
- Create: `src/Service/CustomerAddressState.php`, `src/Service/CustomerAddressStateLoader.php`
- Modify: `src/Resources/config/services.xml` (register the loader)
- Test: `tests/Unit/Service/CustomerAddressStateLoaderTest.php` (new)

**Interfaces:**
- Produces: `CustomerAddressState` readonly — `__construct(string $id, array $row)`, `get(string $column): mixed`, `getCustomerId(): ?string` (hex), `columns(): array<string, mixed>`.
- Produces: `CustomerAddressStateLoader::load(array $idsBytes): array<string, CustomerAddressState>` keyed by lowercase hex id.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Service/CustomerAddressStateLoaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerAddressStateLoader;
use Shopware\Core\Framework\Uuid\Uuid;

final class CustomerAddressStateLoaderTest extends TestCase
{
    public function testLoadsRowsKeyedByHexId(): void
    {
        $id = Uuid::randomHex();
        $customerId = Uuid::randomHex();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')
            ->with(
                self::stringContains('FROM `customer_address` WHERE `id` IN (:ids)'),
                ['ids' => [Uuid::fromHexToBytes($id)]],
                ['ids' => ArrayParameterType::BINARY]
            )
            ->willReturn([[
                'id' => Uuid::fromHexToBytes($id),
                'customer_id' => Uuid::fromHexToBytes($customerId),
                'street' => 'Nelkenweg 12',
                'zipcode' => '63814',
                'city' => 'Mainaschaff',
            ]]);

        $states = (new CustomerAddressStateLoader($connection))->load([Uuid::fromHexToBytes($id)]);

        self::assertArrayHasKey($id, $states);
        $state = $states[$id];
        self::assertSame($id, $state->id);
        self::assertSame($customerId, $state->getCustomerId());
        self::assertSame('Nelkenweg 12', $state->get('street'));
        self::assertNull($state->get('does_not_exist'));
        self::assertSame(['id', 'customer_id', 'street', 'zipcode', 'city'], array_keys($state->columns()));
    }

    public function testEmptyInputSkipsTheQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAllAssociative');

        self::assertSame([], (new CustomerAddressStateLoader($connection))->load([]));
    }

    public function testNullCustomerIsTolerated(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([['id' => Uuid::randomBytes(), 'customer_id' => null]]);

        $state = array_values((new CustomerAddressStateLoader($connection))->load([Uuid::randomBytes()]))[0];

        self::assertNull($state->getCustomerId());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/CustomerAddressStateLoaderTest.php`
Expected: class not found.

- [ ] **Step 3: Create state + loader**

`src/Service/CustomerAddressState.php`:

```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

/**
 * The current `customer_address` row (storage column names, raw DB values) of an address the
 * connector is about to update or delete.
 */
final readonly class CustomerAddressState
{
    /**
     * @param array<string, mixed> $row
     */
    public function __construct(
        public string $id,
        private array $row,
    ) {
    }

    public function get(string $column): mixed
    {
        return $this->row[$column] ?? null;
    }

    public function getCustomerId(): ?string
    {
        return Values::hexOrNull($this->row['customer_id'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return $this->row;
    }
}
```

`src/Service/CustomerAddressStateLoader.php`:

```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Loads the current DB state of customer addresses by primary key (binary ids), one query per write event.
 */
final class CustomerAddressStateLoader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<string> $idsBytes 16-byte binary ids as found in WriteCommand::getPrimaryKey()['id']
     *
     * @return array<string, CustomerAddressState> keyed by lowercase hex id
     */
    public function load(array $idsBytes): array
    {
        if ($idsBytes === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM `customer_address` WHERE `id` IN (:ids)',
            ['ids' => array_values($idsBytes)],
            ['ids' => ArrayParameterType::BINARY]
        );

        $states = [];
        foreach ($rows as $row) {
            $hex = Uuid::fromBytesToHex((string) $row['id']);
            $states[$hex] = new CustomerAddressState($hex, $row);
        }

        return $states;
    }
}
```

Register in `src/Resources/config/services.xml` after `CustomerStateLoader`:

```xml
        <service id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerAddressStateLoader">
            <argument type="service" id="Doctrine\DBAL\Connection"/>
        </service>
```

- [ ] **Step 4: Run the test and the suite**

Run: `vendor/bin/phpunit`
Expected: `OK (108 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add src/Service/CustomerAddressState.php src/Service/CustomerAddressStateLoader.php src/Resources/config/services.xml tests/Unit/Service/CustomerAddressStateLoaderTest.php
git commit -m "feat(003): customer address state loader

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01QMob74xnUzbhrYWp62Ky2H"
```

---

### Task 5: `FieldGuard` — allow-list on `customer` columns and custom-field keys

**Files:**
- Create: `src/Service/FieldGuard.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Unit/Service/FieldGuardTest.php` (new)

**Interfaces:**
- Consumes: `FieldGuardConfig` (Task 1), `Values` (Task 2), `GuardLogEntry` actions (Task 3), `CustomerState`, `ConnectorSource`, `GuardLogger`.
- Produces: `FieldGuard::guardCustomerColumns(UpdateCommand $command, string $idHex, CustomerState $state, GuardConfig $config, ConnectorSource $connector, array $handled, array $sent): void` and `FieldGuard::guardCustomerCustomFields(JsonUpdateCommand $command, string $idHex, CustomerState $state, GuardConfig $config, ConnectorSource $connector): void`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Service/FieldGuardTest.php`:

```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSource;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerState;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuard;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\IdentityGuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Subscriber\CustomerTestDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class FieldGuardTest extends TestCase
{
    private const INTEGRATION_ID = '2103c0f8ba934cbdb291287aaa3b5ce8';

    private EntityDefinition $definition;
    private GuardLogger&MockObject $guardLogger;
    private FieldGuard $guard;
    private ConnectorSource $connector;
    /** @var list<array{string, string, string, string|null, string|null}> action, field, mode, current, attempted */
    private array $logged = [];

    protected function setUp(): void
    {
        $registry = new StaticDefinitionInstanceRegistry(
            [CustomerTestDefinition::class],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGatewayInterface::class),
        );
        $this->definition = $registry->getByEntityName('customer');
        $this->guardLogger = $this->createMock(GuardLogger::class);
        $this->guardLogger->method('log')->willReturnCallback(function (GuardLogEntry $e): void {
            $this->logged[] = [$e->action, $e->field, $e->mode, $e->currentValue, $e->attemptedValue];
        });
        $this->guard = new FieldGuard($this->guardLogger);
        $this->connector = new ConnectorSource(self::INTEGRATION_ID, 'JTL-Connector');
    }

    private function config(bool $enforce, array $allowedFields = [], array $allowedCustomFields = ['anmerkung', 'hinweis_(intern)'], bool $identityEnabled = true): GuardConfig
    {
        return new GuardConfig(
            true,
            true,
            ['JTL-Connector'],
            [],
            ['customer_number'],
            new IdentityGuardConfig($identityEnabled, true, IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP),
            new FieldGuardConfig(true, $enforce, array_merge(['customer_group_id'], $allowedFields), $allowedCustomFields, FieldGuardConfig::POLICY_LOG),
        );
    }

    private function update(string $idHex, array $payload): UpdateCommand
    {
        $existence = new EntityExistence('customer', ['id' => $idHex], true, false, false, []);

        return new UpdateCommand($this->definition, $payload, ['id' => Uuid::fromHexToBytes($idHex)], $existence, '/0');
    }

    private function jsonUpdate(string $idHex, array $payload, string $storageName = 'custom_fields'): JsonUpdateCommand
    {
        $existence = new EntityExistence('customer', ['id' => $idHex], true, false, false, []);

        return new JsonUpdateCommand($this->definition, $storageName, $payload, ['id' => Uuid::fromHexToBytes($idHex)], $existence, '/0');
    }

    private function state(string $idHex, array $extra = []): CustomerState
    {
        return new CustomerState($idHex, $extra + [
            'id' => Uuid::fromHexToBytes($idHex),
            'customer_number' => 'C10009',
            'email' => 'reischl@t-online.de',
            'first_name' => 'Martin',
            'last_name' => 'Reischl',
            'title' => null,
            'company' => null,
            'vat_ids' => null,
            'active' => '1',
            'customer_group_id' => Uuid::fromHexToBytes('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1'),
            'sales_channel_id' => Uuid::randomBytes(),
            'custom_fields' => '{"hinweis_(intern)":"Stammkunde","payPalExpressPayerId":"PAYER1"}',
        ]);
    }

    public function testEnforceKeepsEveryNonAllowedColumnAndAppliesTheGroup(): void
    {
        $id = Uuid::randomHex();
        $newGroup = Uuid::randomBytes();
        $cmd = $this->update($id, [
            'customer_group_id' => $newGroup,
            'title' => 'Dr.',
            'company' => 'Moto Kraft',
            'vat_ids' => '["DE123456789"]',
            'updated_at' => '2026-09-08 10:00:00.000',
        ]);

        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id), $this->config(enforce: true), $this->connector, [], $cmd->getPayload());

        $payload = $cmd->getPayload();
        self::assertSame($newGroup, $payload['customer_group_id'], 'allowed');
        self::assertNull($payload['title'], 'kept current (null)');
        self::assertNull($payload['company']);
        self::assertNull($payload['vat_ids']);
        self::assertSame('2026-09-08 10:00:00.000', $payload['updated_at'], 'bookkeeping untouched');
        self::assertSame([
            [GuardLogEntry::ACTION_BLOCKED_FIELD, 'title', 'enforce', null, 'Dr.'],
            [GuardLogEntry::ACTION_BLOCKED_FIELD, 'company', 'enforce', null, 'Moto Kraft'],
            [GuardLogEntry::ACTION_BLOCKED_FIELD, 'vat_ids', 'enforce', null, '["DE123456789"]'],
        ], $this->logged);
    }

    public function testLogOnlyRecordsAndApplies(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->update($id, ['title' => 'Dr.', 'active' => false]);

        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id), $this->config(enforce: false), $this->connector, [], $cmd->getPayload());

        self::assertSame('Dr.', $cmd->getPayload()['title']);
        self::assertFalse($cmd->getPayload()['active']);
        self::assertSame([
            [GuardLogEntry::ACTION_OBSERVED_FIELD, 'title', 'log_only', null, 'Dr.'],
            [GuardLogEntry::ACTION_OBSERVED_FIELD, 'active', 'log_only', '1', '0'],
        ], $this->logged);
    }

    public function testUnchangedValuesAreNeitherRevertedNorLogged(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->update($id, ['first_name' => 'Martin', 'active' => true, 'title' => null]);

        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id), $this->config(enforce: true, identityEnabled: false), $this->connector, [], $cmd->getPayload());

        self::assertSame([], $this->logged);
    }

    public function testColumnsOwnedBy001And002AreSkipped(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->update($id, ['customer_number' => '10009', 'email' => 'ramona.kraft@moto-kraft.de', 'last_name' => 'Kraft', 'title' => 'Dr.']);

        // 001 reports customer_number as handled; identity guard enabled owns email/first_name/last_name
        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id), $this->config(enforce: true), $this->connector, ['customer_number'], $cmd->getPayload());

        self::assertSame('10009', $cmd->getPayload()['customer_number'], 'left to 001');
        self::assertSame('ramona.kraft@moto-kraft.de', $cmd->getPayload()['email'], 'left to 002');
        self::assertSame('Kraft', $cmd->getPayload()['last_name'], 'left to 002');
        self::assertNull($cmd->getPayload()['title']);
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_FIELD, 'title', 'enforce', null, 'Dr.']], $this->logged);
    }

    public function testIdentityColumnsFallToTheFieldGuardWhenTheIdentityGuardIsOff(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->update($id, ['last_name' => 'Kraft']);

        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id), $this->config(enforce: true, identityEnabled: false), $this->connector, [], $cmd->getPayload());

        self::assertSame('Reischl', $cmd->getPayload()['last_name']);
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_FIELD, 'last_name', 'enforce', 'Reischl', 'Kraft']], $this->logged);
    }

    public function testUsesThePayloadAsSentNotTheAlreadyRevertedCommand(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->update($id, ['title' => 'Dr.']);
        $sent = $cmd->getPayload();
        $cmd->addPayload('title', null); // something before us already reverted it

        $this->guard->guardCustomerColumns($cmd, $id, $this->state($id), $this->config(enforce: true), $this->connector, [], $sent);

        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_FIELD, 'title', 'enforce', null, 'Dr.']], $this->logged, 'the attempted value comes from $sent');
    }

    public function testCustomFieldsAreGuardedPerKey(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->jsonUpdate($id, [
            'hinweis_(intern)' => 'Neuer Hinweis',
            'anmerkung' => 'Bitte anrufen',
            'paypalexpresspayerid' => 'PAYER2',
            'payPalExpressPayerId' => 'PAYER1',
        ]);

        $this->guard->guardCustomerCustomFields($cmd, $id, $this->state($id), $this->config(enforce: true), $this->connector);

        $payload = $cmd->getPayload();
        self::assertSame('Neuer Hinweis', $payload['hinweis_(intern)'], 'allowed key applied');
        self::assertSame('Bitte anrufen', $payload['anmerkung'], 'allowed key applied');
        self::assertNull($payload['paypalexpresspayerid'], 'guarded key missing in the current JSON is written back as null');
        self::assertSame('PAYER1', $payload['payPalExpressPayerId'], 'unchanged, untouched');
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_FIELD, 'custom_fields.paypalexpresspayerid', 'enforce', null, 'PAYER2']], $this->logged);
    }

    public function testCustomFieldsLogOnlyObservesStructures(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->jsonUpdate($id, ['payPalExpressPayerId' => ['nested' => true]]);

        $this->guard->guardCustomerCustomFields($cmd, $id, $this->state($id), $this->config(enforce: false), $this->connector);

        self::assertSame(['nested' => true], $cmd->getPayload()['payPalExpressPayerId']);
        self::assertSame([[GuardLogEntry::ACTION_OBSERVED_FIELD, 'custom_fields.payPalExpressPayerId', 'log_only', 'PAYER1', '{"nested":true}']], $this->logged);
    }

    public function testNoCustomFieldAllowedWhenTheListIsEmpty(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->jsonUpdate($id, ['anmerkung' => 'x']);

        $this->guard->guardCustomerCustomFields($cmd, $id, $this->state($id), $this->config(enforce: true, allowedCustomFields: []), $this->connector);

        self::assertNull($cmd->getPayload()['anmerkung']);
        self::assertCount(1, $this->logged);
    }

    public function testJsonUpdateOnAnotherColumnIsIgnored(): void
    {
        $id = Uuid::randomHex();
        $cmd = $this->jsonUpdate($id, ['k' => 'v'], 'newsletter_sales_channel_ids');

        $this->guard->guardCustomerCustomFields($cmd, $id, $this->state($id), $this->config(enforce: true), $this->connector);

        self::assertSame('v', $cmd->getPayload()['k']);
        self::assertSame([], $this->logged);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Service/FieldGuardTest.php`
Expected: class `FieldGuard` not found.

- [ ] **Step 3: Implement `FieldGuard`**

`src/Service/FieldGuard.php`:

```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;

/**
 * Feature 003 on the `customer` entity: the connector may change only the allow-listed
 * columns (spec R1) and only the allow-listed custom-field keys (spec R1a) of an existing
 * customer. Everything else is written back to its current value in enforce and recorded with
 * that current value in log_only — the record two months of log_only produce is the repair
 * source for the accounts the connector has been overwriting.
 *
 * Never touches inserts, never touches columns 001 (block list) or 002 (identity) already own.
 */
final class FieldGuard
{
    /** Owned by the identity guard whenever it is enabled — never double-handled here. */
    private const IDENTITY_COLUMNS = ['email', 'first_name', 'last_name'];

    public function __construct(private readonly GuardLogger $guardLogger)
    {
    }

    /**
     * Plain UpdateCommand: one guarded item per non-allowed column present in the write.
     *
     * @param list<string>         $handled columns 001's block list already processed (present in the write)
     * @param array<string, mixed> $sent    the payload exactly as the connector sent it, captured before
     *                                      001/002 could revert anything via addPayload()
     */
    public function guardCustomerColumns(UpdateCommand $command, string $idHex, CustomerState $state, GuardConfig $config, ConnectorSource $connector, array $handled, array $sent): void
    {
        $fieldGuard = $config->fieldGuard;

        $owned = $handled;
        if ($config->identity->enabled) {
            $owned = array_merge($owned, self::IDENTITY_COLUMNS);
        }

        foreach ($sent as $column => $attempted) {
            if ($fieldGuard->isAllowedField($column) || \in_array($column, $owned, true)) {
                continue;
            }

            $current = $state->get($column);
            if (Values::same($attempted, $current)) {
                continue;
            }

            $kept = $fieldGuard->enforce;
            if ($kept) {
                $command->addPayload($column, $current);
            }

            $this->guardLogger->log(new GuardLogEntry(
                action: $kept ? GuardLogEntry::ACTION_BLOCKED_FIELD : GuardLogEntry::ACTION_OBSERVED_FIELD,
                mode: $fieldGuard->mode(),
                field: $column,
                customerId: $idHex,
                email: $state->getEmail(),
                firstName: $state->getFirstName(),
                lastName: $state->getLastName(),
                currentValue: Values::render($column, $current),
                attemptedValue: Values::render($column, $attempted),
                assignedValue: null,
                integrationId: $connector->integrationId,
                integrationLabel: $connector->label,
                salesChannelId: $state->getSalesChannelId(),
            ));
        }
    }

    /**
     * JsonUpdateCommand on `custom_fields`: the payload keys are custom-field keys, merged into
     * the JSON column with JSON_SET, so the guard works per key. A key absent from the current
     * JSON is written back as null (JSON_SET cannot remove a key from inside a write command).
     */
    public function guardCustomerCustomFields(JsonUpdateCommand $command, string $idHex, CustomerState $state, GuardConfig $config, ConnectorSource $connector): void
    {
        if ($command->getStorageName() !== FieldGuardConfig::CUSTOM_FIELDS_COLUMN) {
            return; // core only emits JsonUpdateCommand for custom_fields; anything else is not ours
        }

        $fieldGuard = $config->fieldGuard;
        $current = Values::decodeJson($state->get(FieldGuardConfig::CUSTOM_FIELDS_COLUMN));

        foreach ($command->getPayload() as $key => $attempted) {
            $key = (string) $key;
            if ($fieldGuard->isAllowedCustomField($key)) {
                continue;
            }

            $currentValue = $current[$key] ?? null;
            if (Values::sameJson($attempted, $currentValue)) {
                continue;
            }

            $kept = $fieldGuard->enforce;
            if ($kept) {
                $command->addPayload($key, $currentValue);
            }

            $field = FieldGuardConfig::CUSTOM_FIELDS_COLUMN . '.' . $key;
            $this->guardLogger->log(new GuardLogEntry(
                action: $kept ? GuardLogEntry::ACTION_BLOCKED_FIELD : GuardLogEntry::ACTION_OBSERVED_FIELD,
                mode: $fieldGuard->mode(),
                field: $field,
                customerId: $idHex,
                email: $state->getEmail(),
                firstName: $state->getFirstName(),
                lastName: $state->getLastName(),
                currentValue: Values::render($field, $currentValue),
                attemptedValue: Values::render($field, $attempted),
                assignedValue: null,
                integrationId: $connector->integrationId,
                integrationLabel: $connector->label,
                salesChannelId: $state->getSalesChannelId(),
            ));
        }
    }
}
```

Register in `services.xml` after `GuardLogger`:

```xml
        <service id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuard">
            <argument type="service" id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger"/>
        </service>
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Unit/Service/FieldGuardTest.php && vendor/bin/phpunit`
Expected: 10 tests PASS; suite `OK (118 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add src/Service/FieldGuard.php src/Resources/config/services.xml tests/Unit/Service/FieldGuardTest.php
git commit -m "feat(003): FieldGuard — allow-list over customer columns and custom-field keys

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01QMob74xnUzbhrYWp62Ky2H"
```

---

### Task 6: `AddressGuard` — updates reverted, creates/deletes recorded or rejected

**Files:**
- Create: `src/Service/AddressGuard.php`
- Create: `tests/Unit/Subscriber/CustomerAddressTestDefinition.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Unit/Service/AddressGuardTest.php` (new)

**Interfaces:**
- Consumes: `CustomerAddressStateLoader` / `CustomerAddressState` (Task 4), `CustomerStateLoader`, `GuardConfigProvider`, `FieldGuardConfig`, `Values`, `GuardLogEntry`, `GuardLogger`.
- Produces: `AddressGuard::guard(EntityWriteEvent $event, array $commands, array $insertedCustomerIdsHex, array $deletedCustomerIdsHex, ConnectorSource $connector): void`.

- [ ] **Step 1: Address test definition**

`tests/Unit/Subscriber/CustomerAddressTestDefinition.php`:

```php
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
```

- [ ] **Step 2: Write the failing tests**

`tests/Unit/Service/AddressGuardTest.php`:

```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\AddressGuard;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSource;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerAddressState;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerAddressStateLoader;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerState;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\IdentityGuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Subscriber\CustomerAddressTestDefinition;
use Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Subscriber\CustomerTestDefinition;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AddressGuardTest extends TestCase
{
    private const INTEGRATION_ID = '2103c0f8ba934cbdb291287aaa3b5ce8';

    private EntityDefinition $definition;
    private GuardConfigProvider&MockObject $configProvider;
    private CustomerAddressStateLoader&MockObject $addressLoader;
    private CustomerStateLoader&MockObject $customerLoader;
    private GuardLogger&MockObject $guardLogger;
    private AddressGuard $guard;
    private ConnectorSource $connector;
    /** @var list<array{string, string, string|null, string|null, string, string|null}> action, field, current, attempted, entity, entityId */
    private array $logged = [];

    protected function setUp(): void
    {
        $registry = new StaticDefinitionInstanceRegistry(
            [CustomerTestDefinition::class, CustomerAddressTestDefinition::class],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGatewayInterface::class),
        );
        $this->definition = $registry->getByEntityName('customer_address');
        $this->configProvider = $this->createMock(GuardConfigProvider::class);
        $this->addressLoader = $this->createMock(CustomerAddressStateLoader::class);
        $this->customerLoader = $this->createMock(CustomerStateLoader::class);
        $this->guardLogger = $this->createMock(GuardLogger::class);
        $this->guardLogger->method('log')->willReturnCallback(function (GuardLogEntry $e): void {
            $this->logged[] = [$e->action, $e->field, $e->currentValue, $e->attemptedValue, $e->entity, $e->entityId];
        });
        $this->guard = new AddressGuard(
            $this->configProvider,
            $this->addressLoader,
            $this->customerLoader,
            $this->guardLogger,
            $this->createMock(LoggerInterface::class),
            $this->createMock(LoggerInterface::class),
        );
        $this->connector = new ConnectorSource(self::INTEGRATION_ID, 'JTL-Connector');
    }

    private function config(bool $enforce, string $policy = FieldGuardConfig::POLICY_LOG, bool $enabled = true): GuardConfig
    {
        return new GuardConfig(true, true, ['JTL-Connector'], [], ['customer_number'], IdentityGuardConfig::disabled(),
            new FieldGuardConfig($enabled, $enforce, ['customer_group_id'], [], $policy));
    }

    private function existence(string $idHex, bool $exists): EntityExistence
    {
        return new EntityExistence('customer_address', ['id' => $idHex], $exists, false, false, []);
    }

    private function update(string $idHex, array $payload): UpdateCommand
    {
        return new UpdateCommand($this->definition, $payload, ['id' => Uuid::fromHexToBytes($idHex)], $this->existence($idHex, true), '/0/addresses/0');
    }

    private function jsonUpdate(string $idHex, array $payload): JsonUpdateCommand
    {
        return new JsonUpdateCommand($this->definition, 'custom_fields', $payload, ['id' => Uuid::fromHexToBytes($idHex)], $this->existence($idHex, true), '/0/addresses/0');
    }

    private function insert(string $idHex, array $payload): InsertCommand
    {
        $pk = ['id' => Uuid::fromHexToBytes($idHex)];

        return new InsertCommand($this->definition, $pk + $payload, $pk, EntityExistence::createForEntity('customer_address', ['id' => $idHex]), '/0/addresses/0');
    }

    private function delete(string $idHex): DeleteCommand
    {
        return new DeleteCommand($this->definition, ['id' => Uuid::fromHexToBytes($idHex)], $this->existence($idHex, true));
    }

    /**
     * @param list<WriteCommand> $commands
     */
    private function event(array $commands): EntityWriteEvent
    {
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        return EntityWriteEvent::create(WriteContext::createFromContext($context), $commands);
    }

    private function customer(string $idHex): CustomerState
    {
        return new CustomerState($idHex, [
            'id' => Uuid::fromHexToBytes($idHex),
            'email' => 'reischl@t-online.de',
            'first_name' => 'Martin',
            'last_name' => 'Reischl',
            'sales_channel_id' => Uuid::fromHexToBytes('019b02dbf154717c8d127b5df75c3b7d'),
        ]);
    }

    private function address(string $idHex, string $customerHex, array $extra = []): CustomerAddressState
    {
        return new CustomerAddressState($idHex, $extra + [
            'id' => Uuid::fromHexToBytes($idHex),
            'customer_id' => Uuid::fromHexToBytes($customerHex),
            'street' => 'Nelkenweg 12',
            'zipcode' => '63814',
            'city' => 'Mainaschaff',
            'custom_fields' => null,
            'created_at' => '2026-01-20 16:47:12.680',
            'updated_at' => null,
        ]);
    }

    public function testEnforceRevertsEveryChangedAddressColumn(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->with('019b02dbf154717c8d127b5df75c3b7d')->willReturn($this->config(enforce: true));
        $this->addressLoader->method('load')->with([Uuid::fromHexToBytes($address)])->willReturn([$address => $this->address($address, $customer)]);
        $this->customerLoader->method('load')->with([Uuid::fromHexToBytes($customer)])->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->update($address, ['street' => 'Grasiger Weg 20', 'zipcode' => '93333', 'city' => 'Mainaschaff', 'updated_at' => '2026-09-08 10:00:00.000']);
        $this->guard->guard($this->event([$cmd]), [$cmd], [], [], $this->connector);

        self::assertSame('Nelkenweg 12', $cmd->getPayload()['street']);
        self::assertSame('63814', $cmd->getPayload()['zipcode']);
        self::assertSame('2026-09-08 10:00:00.000', $cmd->getPayload()['updated_at'], 'bookkeeping untouched');
        self::assertSame([
            [GuardLogEntry::ACTION_BLOCKED_ADDRESS, 'street', 'Nelkenweg 12', 'Grasiger Weg 20', 'customer_address', $address],
            [GuardLogEntry::ACTION_BLOCKED_ADDRESS, 'zipcode', '63814', '93333', 'customer_address', $address],
        ], $this->logged);
    }

    public function testLogOnlyRecordsAndAppliesAddressUpdate(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false));
        $this->addressLoader->method('load')->willReturn([$address => $this->address($address, $customer)]);
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->update($address, ['street' => 'Grasiger Weg 20']);
        $this->guard->guard($this->event([$cmd]), [$cmd], [], [], $this->connector);

        self::assertSame('Grasiger Weg 20', $cmd->getPayload()['street']);
        self::assertSame([[GuardLogEntry::ACTION_OBSERVED_ADDRESS, 'street', 'Nelkenweg 12', 'Grasiger Weg 20', 'customer_address', $address]], $this->logged);
    }

    public function testAddressCustomFieldsHaveNoAllowList(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->addressLoader->method('load')->willReturn([$address => $this->address($address, $customer, ['custom_fields' => '{"anmerkung":"alt"}'])]);
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->jsonUpdate($address, ['anmerkung' => 'neu', 'other' => 'x']);
        $this->guard->guard($this->event([$cmd]), [$cmd], [], [], $this->connector);

        self::assertSame('alt', $cmd->getPayload()['anmerkung']);
        self::assertNull($cmd->getPayload()['other']);
        self::assertSame([
            [GuardLogEntry::ACTION_BLOCKED_ADDRESS, 'custom_fields.anmerkung', 'alt', 'neu', 'customer_address', $address],
            [GuardLogEntry::ACTION_BLOCKED_ADDRESS, 'custom_fields.other', null, 'x', 'customer_address', $address],
        ], $this->logged);
    }

    public function testInsertForACustomerInsertedInTheSameWriteIsIgnored(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->expects(self::never())->method('load');
        $this->customerLoader->method('load')->willReturn([]);

        $cmd = $this->insert($address, ['customer_id' => Uuid::fromHexToBytes($customer), 'street' => 'Neu 1']);
        $this->guard->guard($this->event([$cmd]), [$cmd], [$customer], [], $this->connector);

        self::assertSame([], $this->logged);
    }

    public function testInsertForAnExistingCustomerIsRecordedPerColumn(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->customerLoader->method('load')->with([Uuid::fromHexToBytes($customer)])->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->insert($address, ['customer_id' => Uuid::fromHexToBytes($customer), 'street' => 'Grasiger Weg 20', 'city' => 'Neustadt', 'created_at' => '2026-09-08 10:00:00.000']);
        $event = $this->event([$cmd]);
        $this->guard->guard($event, [$cmd], [], [], $this->connector);

        self::assertSame('Grasiger Weg 20', $cmd->getPayload()['street'], 'cannot be blocked');
        self::assertSame([], $event->getWriteContext()->getExceptions()->getExceptions());
        self::assertSame([
            [GuardLogEntry::ACTION_OBSERVED_ADDRESS_CREATE, 'customer_id', null, $customer, 'customer_address', $address],
            [GuardLogEntry::ACTION_OBSERVED_ADDRESS_CREATE, 'street', null, 'Grasiger Weg 20', 'customer_address', $address],
            [GuardLogEntry::ACTION_OBSERVED_ADDRESS_CREATE, 'city', null, 'Neustadt', 'customer_address', $address],
        ], $this->logged, 'id and created_at are bookkeeping');
    }

    public function testDeleteIsRecordedPerColumnFromTheCurrentRow(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->addressLoader->method('load')->willReturn([$address => $this->address($address, $customer)]);
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->delete($address);
        $this->guard->guard($this->event([$cmd]), [$cmd], [], [], $this->connector);

        self::assertSame([
            [GuardLogEntry::ACTION_OBSERVED_ADDRESS_DELETE, 'customer_id', $customer, null, 'customer_address', $address],
            [GuardLogEntry::ACTION_OBSERVED_ADDRESS_DELETE, 'street', 'Nelkenweg 12', null, 'customer_address', $address],
            [GuardLogEntry::ACTION_OBSERVED_ADDRESS_DELETE, 'zipcode', '63814', null, 'customer_address', $address],
            [GuardLogEntry::ACTION_OBSERVED_ADDRESS_DELETE, 'city', 'Mainaschaff', null, 'customer_address', $address],
        ], $this->logged, 'null custom_fields and bookkeeping columns are not logged');
    }

    public function testDeleteOfAnAddressWhoseCustomerIsDeletedInTheSameWriteIsIgnored(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->addressLoader->method('load')->willReturn([$address => $this->address($address, $customer)]);
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);
        $this->configProvider->expects(self::never())->method('load');

        $cmd = $this->delete($address);
        $this->guard->guard($this->event([$cmd]), [$cmd], [], [$customer], $this->connector);

        self::assertSame([], $this->logged);
    }

    public function testRejectWritePolicyAddsAViolationToTheWriteContext(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, policy: FieldGuardConfig::POLICY_REJECT_WRITE));
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->insert($address, ['customer_id' => Uuid::fromHexToBytes($customer), 'street' => 'Grasiger Weg 20']);
        $event = $this->event([$cmd]);
        $this->guard->guard($event, [$cmd], [], [], $this->connector);

        $exceptions = $event->getWriteContext()->getExceptions()->getExceptions();
        self::assertCount(1, $exceptions);
        self::assertInstanceOf(WriteConstraintViolationException::class, $exceptions[0]);
        self::assertSame('/0/addresses/0', $exceptions[0]->getPath());
        self::assertSame([[GuardLogEntry::ACTION_REJECTED_WRITE, '*', null, null, 'customer_address', $address]], $this->logged);
    }

    public function testRejectWritePolicyIsInertInLogOnly(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false, policy: FieldGuardConfig::POLICY_REJECT_WRITE));
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->insert($address, ['customer_id' => Uuid::fromHexToBytes($customer), 'street' => 'Grasiger Weg 20']);
        $event = $this->event([$cmd]);
        $this->guard->guard($event, [$cmd], [], [], $this->connector);

        self::assertSame([], $event->getWriteContext()->getExceptions()->getExceptions());
        self::assertSame(GuardLogEntry::ACTION_OBSERVED_ADDRESS_CREATE, $this->logged[0][0]);
    }

    public function testDisabledPerSalesChannelDoesNothing(): void
    {
        $customer = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, enabled: false));
        $this->addressLoader->method('load')->willReturn([$address => $this->address($address, $customer)]);
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $cmd = $this->update($address, ['street' => 'Grasiger Weg 20']);
        $this->guard->guard($this->event([$cmd]), [$cmd], [], [], $this->connector);

        self::assertSame('Grasiger Weg 20', $cmd->getPayload()['street']);
        self::assertSame([], $this->logged);
    }

    public function testOneFailingCommandDoesNotStopTheOthers(): void
    {
        $customer = Uuid::randomHex();
        $bad = Uuid::randomHex();
        $good = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->addressLoader->method('load')->willReturn([
            $bad => new CustomerAddressState($bad, ['id' => Uuid::fromHexToBytes($bad), 'customer_id' => Uuid::fromHexToBytes($customer), 'street' => new \stdClass()]),
            $good => $this->address($good, $customer),
        ]);
        $this->customerLoader->method('load')->willReturn([$customer => $this->customer($customer)]);

        $badCmd = $this->update($bad, ['street' => 'x']);
        $goodCmd = $this->update($good, ['street' => 'Grasiger Weg 20']);
        $this->guard->guard($this->event([$badCmd, $goodCmd]), [$badCmd, $goodCmd], [], [], $this->connector);

        self::assertSame('Nelkenweg 12', $goodCmd->getPayload()['street'], 'guarded despite the earlier failure');
    }
}
```

- [ ] **Step 3: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Service/AddressGuardTest.php`
Expected: class `AddressGuard` not found.

- [ ] **Step 4: Implement `AddressGuard`**

`src/Service/AddressGuard.php`:

```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Feature 003 on the `customer_address` entity (spec R2). The connector may change nothing on an
 * existing customer's address:
 *  - UpdateCommand / JsonUpdateCommand: every changed column (custom-field key) is written back
 *    in enforce, recorded in log_only;
 *  - InsertCommand / DeleteCommand: cannot be dropped from the write (the DAL exposes no way to
 *    remove a command), so they are recorded one row per column — or, under the reject_write
 *    policy in enforce, a constraint violation is added to the write context, which fails the
 *    whole connector write inside the DAL transaction.
 * Addresses of customers inserted or deleted in the same write belong to those customers and
 * are ignored here.
 */
final class AddressGuard
{
    private const REJECT_MESSAGE = 'JTL-Connector Guard: the connector may not create or delete addresses of existing customers (addressCreateDeletePolicy=reject_write)';

    public function __construct(
        private readonly GuardConfigProvider $configProvider,
        private readonly CustomerAddressStateLoader $addressLoader,
        private readonly CustomerStateLoader $customerLoader,
        private readonly GuardLogger $guardLogger,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $fallbackLogger,
    ) {
    }

    /**
     * @param list<WriteCommand> $commands               the `customer_address` commands of one write event
     * @param list<string>       $insertedCustomerIdsHex customers created in the same write
     * @param list<string>       $deletedCustomerIdsHex  customers deleted in the same write
     */
    public function guard(EntityWriteEvent $event, array $commands, array $insertedCustomerIdsHex, array $deletedCustomerIdsHex, ConnectorSource $connector): void
    {
        $updates = [];
        $inserts = [];
        $deletes = [];
        foreach ($commands as $command) {
            if ($command instanceof DeleteCommand) {
                $deletes[] = $command;
            } elseif ($command instanceof UpdateCommand) {
                $updates[] = $command;
            } elseif ($command instanceof InsertCommand) {
                $inserts[] = $command;
            }
        }

        $addressIds = [];
        foreach ([...$updates, ...$deletes] as $command) {
            $addressIds[] = (string) $command->getPrimaryKey()['id'];
        }
        $addresses = $this->addressLoader->load(array_values(array_unique($addressIds)));

        $customerIds = [];
        foreach ($addresses as $address) {
            $customerHex = $address->getCustomerId();
            if ($customerHex !== null) {
                $customerIds[$customerHex] = Uuid::fromHexToBytes($customerHex);
            }
        }
        foreach ($inserts as $command) {
            $customerHex = Values::hexOrNull($command->getPayload()['customer_id'] ?? null);
            if ($customerHex !== null && !\in_array($customerHex, $insertedCustomerIdsHex, true)) {
                $customerIds[$customerHex] = Uuid::fromHexToBytes($customerHex);
            }
        }
        $customers = $this->customerLoader->load(array_values($customerIds));

        foreach ($updates as $command) {
            $addressHex = Uuid::fromBytesToHex((string) $command->getPrimaryKey()['id']);
            $this->safely($addressHex, function () use ($command, $addressHex, $addresses, $customers, $connector): void {
                $address = $addresses[$addressHex] ?? null;
                $customer = $address === null ? null : ($customers[$address->getCustomerId() ?? ''] ?? null);
                if ($address === null || $customer === null) {
                    return; // vanished between extraction and event; nothing to protect
                }
                $config = $this->configProvider->load($customer->getSalesChannelId())->fieldGuard;
                if (!$config->enabled) {
                    return;
                }
                $this->guardUpdate($command, $addressHex, $address, $customer, $config, $connector);
            });
        }

        foreach ($inserts as $command) {
            $addressHex = Values::hexOrNull($command->getPrimaryKey()['id'] ?? null) ?? 'unknown';
            $this->safely($addressHex, function () use ($event, $command, $addressHex, $insertedCustomerIdsHex, $customers, $connector): void {
                $payload = $command->getPayload();
                $customerHex = Values::hexOrNull($payload['customer_id'] ?? null);
                if ($customerHex === null || \in_array($customerHex, $insertedCustomerIdsHex, true)) {
                    return; // new customer, new address: legitimately Wawi's
                }
                $customer = $customers[$customerHex] ?? null;
                if ($customer === null) {
                    return;
                }
                $config = $this->configProvider->load($customer->getSalesChannelId())->fieldGuard;
                if (!$config->enabled) {
                    return;
                }
                $this->recordCreateOrDelete($event, $command, $addressHex, $payload, $customer, $config, $connector, GuardLogEntry::ACTION_OBSERVED_ADDRESS_CREATE);
            });
        }

        foreach ($deletes as $command) {
            $addressHex = Uuid::fromBytesToHex((string) $command->getPrimaryKey()['id']);
            $this->safely($addressHex, function () use ($event, $command, $addressHex, $addresses, $customers, $deletedCustomerIdsHex, $connector): void {
                $address = $addresses[$addressHex] ?? null;
                $customerHex = $address?->getCustomerId();
                if ($address === null || $customerHex === null || \in_array($customerHex, $deletedCustomerIdsHex, true)) {
                    return; // already gone, or cascading from a customer delete
                }
                $customer = $customers[$customerHex] ?? null;
                if ($customer === null) {
                    return;
                }
                $config = $this->configProvider->load($customer->getSalesChannelId())->fieldGuard;
                if (!$config->enabled) {
                    return;
                }
                $this->recordCreateOrDelete($event, $command, $addressHex, $address->columns(), $customer, $config, $connector, GuardLogEntry::ACTION_OBSERVED_ADDRESS_DELETE);
            });
        }
    }

    private function guardUpdate(UpdateCommand $command, string $addressHex, CustomerAddressState $address, CustomerState $customer, FieldGuardConfig $config, ConnectorSource $connector): void
    {
        if ($command instanceof JsonUpdateCommand) {
            if ($command->getStorageName() !== FieldGuardConfig::CUSTOM_FIELDS_COLUMN) {
                return;
            }
            $current = Values::decodeJson($address->get(FieldGuardConfig::CUSTOM_FIELDS_COLUMN));
            foreach ($command->getPayload() as $key => $attempted) {
                $key = (string) $key;
                $currentValue = $current[$key] ?? null;
                if (Values::sameJson($attempted, $currentValue)) {
                    continue;
                }
                if ($config->enforce) {
                    $command->addPayload($key, $currentValue);
                }
                $field = FieldGuardConfig::CUSTOM_FIELDS_COLUMN . '.' . $key;
                $this->guardLogger->log($this->entry(
                    $config->enforce ? GuardLogEntry::ACTION_BLOCKED_ADDRESS : GuardLogEntry::ACTION_OBSERVED_ADDRESS,
                    $config, $field, $addressHex, $customer, $connector,
                    Values::render($field, $currentValue), Values::render($field, $attempted),
                ));
            }

            return;
        }

        foreach ($command->getPayload() as $column => $attempted) {
            $column = (string) $column;
            if (FieldGuardConfig::isBookkeeping($column)) {
                continue;
            }
            $currentValue = $address->get($column);
            if (Values::same($attempted, $currentValue)) {
                continue;
            }
            if ($config->enforce) {
                $command->addPayload($column, $currentValue);
            }
            $this->guardLogger->log($this->entry(
                $config->enforce ? GuardLogEntry::ACTION_BLOCKED_ADDRESS : GuardLogEntry::ACTION_OBSERVED_ADDRESS,
                $config, $column, $addressHex, $customer, $connector,
                Values::render($column, $currentValue), Values::render($column, $attempted),
            ));
        }
    }

    /**
     * @param array<string, mixed> $columns the inserted payload (create) or the current row (delete)
     */
    private function recordCreateOrDelete(EntityWriteEvent $event, WriteCommand $command, string $addressHex, array $columns, CustomerState $customer, FieldGuardConfig $config, ConnectorSource $connector, string $action): void
    {
        if ($config->rejectsAddressCreateDelete()) {
            $violation = new ConstraintViolation(self::REJECT_MESSAGE, null, [], null, $command->getPath(), null);
            $event->getWriteContext()->getExceptions()->add(
                new WriteConstraintViolationException(new ConstraintViolationList([$violation]), $command->getPath())
            );
            $this->guardLogger->log($this->entry(GuardLogEntry::ACTION_REJECTED_WRITE, $config, '*', $addressHex, $customer, $connector, null, null));

            return;
        }

        $isCreate = $action === GuardLogEntry::ACTION_OBSERVED_ADDRESS_CREATE;
        foreach ($columns as $column => $value) {
            $column = (string) $column;
            if (FieldGuardConfig::isBookkeeping($column) || $value === null) {
                continue;
            }
            $rendered = Values::render($column, $value);
            $this->guardLogger->log($this->entry(
                $action, $config, $column, $addressHex, $customer, $connector,
                $isCreate ? null : $rendered,
                $isCreate ? $rendered : null,
            ));
        }
    }

    private function entry(string $action, FieldGuardConfig $config, string $field, string $addressHex, CustomerState $customer, ConnectorSource $connector, ?string $current, ?string $attempted): GuardLogEntry
    {
        return new GuardLogEntry(
            action: $action,
            mode: $config->mode(),
            field: $field,
            customerId: $customer->id,
            email: $customer->getEmail(),
            firstName: $customer->getFirstName(),
            lastName: $customer->getLastName(),
            currentValue: $current,
            attemptedValue: $attempted,
            assignedValue: null,
            integrationId: $connector->integrationId,
            integrationLabel: $connector->label,
            salesChannelId: $customer->getSalesChannelId(),
            entity: GuardLogEntry::ENTITY_CUSTOMER_ADDRESS,
            entityId: $addressHex,
        );
    }

    /**
     * Per-command try/catch, mirroring the subscriber: one bad row must not stop the others.
     */
    private function safely(string $addressHex, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $message = sprintf('jtl_connector_guard: failed to guard address %s, left untouched: %s', $addressHex, $e->getMessage());
            try {
                $this->logger->error($message, ['exception' => $e, 'addressId' => $addressHex]);
            } catch (\Throwable) {
                try {
                    $this->fallbackLogger->error($message, ['exception' => $e, 'addressId' => $addressHex]);
                } catch (\Throwable) {
                    // both loggers broken; never let logging break the write
                }
            }
        }
    }
}
```

Register in `services.xml` after `FieldGuard`:

```xml
        <service id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\AddressGuard">
            <argument type="service" id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider"/>
            <argument type="service" id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerAddressStateLoader"/>
            <argument type="service" id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader"/>
            <argument type="service" id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger"/>
            <argument type="service" id="monolog.logger.jtl_connector_guard"/>
            <argument type="service" id="logger"/>
        </service>
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit tests/Unit/Service/AddressGuardTest.php && vendor/bin/phpunit`
Expected: 11 tests PASS; suite `OK (129 tests, ...)`. If `testOneFailingCommandDoesNotStopTheOthers` does not throw for the `stdClass` street (Values::render on an object returns JSON), replace the bad state's `street` with a value that makes `Values::same()` throw, e.g. an array containing a closure is not possible — instead make `$this->guardLogger` throw once via `willReturnCallback` for the bad address id; the assertion on the good command stays the same.

- [ ] **Step 6: Commit**

```bash
git add src/Service/AddressGuard.php src/Resources/config/services.xml tests/Unit/Service/AddressGuardTest.php tests/Unit/Subscriber/CustomerAddressTestDefinition.php
git commit -m "feat(003): AddressGuard — revert address updates, record or reject creates/deletes

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01QMob74xnUzbhrYWp62Ky2H"
```

---

### Task 7: Wire both guards into the subscriber

**Files:**
- Modify: `src/Subscriber/CustomerNumberWriteProtection.php`
- Modify: `src/Resources/config/services.xml` (two new subscriber arguments)
- Test: `tests/Unit/Subscriber/CustomerNumberWriteProtectionTest.php`

**Interfaces:**
- Consumes: `FieldGuard` (Task 5), `AddressGuard` (Task 6).
- Produces: subscriber constructor `(GuardConfigProvider, ConnectorSourceDetector, CustomerStateLoader, NumberRangeValueGeneratorInterface, GuardLogger, FieldGuard, AddressGuard, LoggerInterface $logger, LoggerInterface $fallbackLogger)`.

- [ ] **Step 1: Update the test fixture and write the failing tests**

In `tests/Unit/Subscriber/CustomerNumberWriteProtectionTest.php`:

(a) imports: add `use Revinners\ShopwareJtlConnectorGuardPlugin\Service\AddressGuard;`, `use Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuard;`, `use Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuardConfig;`, `use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand;`.

(b) properties + setUp: add `private FieldGuard&MockObject $fieldGuard;` and `private AddressGuard&MockObject $addressGuard;`, register `CustomerAddressTestDefinition::class` in the `StaticDefinitionInstanceRegistry` next to `CustomerTestDefinition::class`, keep `$this->addressDefinition = $registry->getByEntityName('customer_address');` in a new property `private EntityDefinition $addressDefinition;`, create the two mocks, and construct the subscriber as:

```php
        $this->subscriber = new CustomerNumberWriteProtection(
            $this->configProvider,
            $this->detector,
            $this->stateLoader,
            $this->numberRange,
            $this->guardLogger,
            $this->fieldGuard,
            $this->addressGuard,
            $this->logger,
            $this->fallbackLogger,
        );
```

(c) extend the `config()` helper with a trailing `?FieldGuardConfig $fieldGuard = null` parameter passed as the 7th `GuardConfig` argument (`$fieldGuard ?? FieldGuardConfig::disabled()`), and add helpers:

```php
    private function fieldGuard(bool $enforce, bool $enabled = true): FieldGuardConfig
    {
        return new FieldGuardConfig($enabled, $enforce, ['customer_group_id'], ['anmerkung', 'hinweis_(intern)'], FieldGuardConfig::POLICY_LOG);
    }

    private function jsonUpdate(string $idHex, array $payload): JsonUpdateCommand
    {
        $existence = new EntityExistence('customer', ['id' => $idHex], true, false, false, []);

        return new JsonUpdateCommand($this->definition, 'custom_fields', $payload, ['id' => Uuid::fromHexToBytes($idHex)], $existence, '/0');
    }

    private function addressUpdate(string $idHex, array $payload): UpdateCommand
    {
        $existence = new EntityExistence('customer_address', ['id' => $idHex], true, false, false, []);

        return new UpdateCommand($this->addressDefinition, $payload, ['id' => Uuid::fromHexToBytes($idHex)], $existence, '/0/addresses/0');
    }
```

(d) new tests at the end of the class:

```php
    // ---- feature 003 routing -------------------------------------------------

    public function testFieldGuardReceivesTheUpdateWithHandledAndSent(): void
    {
        $id = Uuid::randomHex();
        $state = $this->state($id, 'C10009', Uuid::randomHex());
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, fieldGuard: $this->fieldGuard(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $state]);

        $cmd = $this->update($id, ['customer_number' => '10009', 'title' => 'Dr.']);
        $this->fieldGuard->expects(self::once())->method('guardCustomerColumns')->with(
            $cmd,
            $id,
            $state,
            self::anything(),
            self::isInstanceOf(ConnectorSource::class),
            ['customer_number'],
            ['customer_number' => '10009', 'title' => 'Dr.'],
        );
        $this->fieldGuard->expects(self::never())->method('guardCustomerCustomFields');

        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('C10009', $cmd->getPayload()['customer_number'], '001 still reverted before 003 ran, but 003 saw the payload as sent');
    }

    public function testJsonUpdateIsRoutedToTheCustomFieldGuardAndNotToTheColumnGuards(): void
    {
        $id = Uuid::randomHex();
        $state = $this->state($id, 'C10009', Uuid::randomHex());
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, protected: ['customer_number', 'email'], identity: $this->identity(enforce: true), fieldGuard: $this->fieldGuard(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->with([Uuid::fromHexToBytes($id)])->willReturn([$id => $state]);
        $this->guardLogger->expects(self::never())->method('log');

        // a custom field that happens to be called "email" must not be mistaken for the email column
        $cmd = $this->jsonUpdate($id, ['email' => 'not-a-column@example.com']);
        $this->fieldGuard->expects(self::once())->method('guardCustomerCustomFields')->with($cmd, $id, $state, self::anything(), self::isInstanceOf(ConnectorSource::class));
        $this->fieldGuard->expects(self::never())->method('guardCustomerColumns');

        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('not-a-column@example.com', $cmd->getPayload()['email']);
    }

    public function testFieldGuardIsSkippedWhenDisabledForTheSalesChannel(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, fieldGuard: $this->fieldGuard(enforce: true, enabled: false)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->fieldGuard->expects(self::never())->method('guardCustomerColumns');
        $this->fieldGuard->expects(self::never())->method('guardCustomerCustomFields');

        $this->subscriber->onEntityWrite($this->event([$this->update($id, ['title' => 'Dr.']), $this->jsonUpdate($id, ['x' => 1])]));
    }

    public function testAddressCommandsGoToTheAddressGuardWithInsertedAndDeletedCustomerIds(): void
    {
        $existing = Uuid::randomHex();
        $created = Uuid::randomHex();
        $deleted = Uuid::randomHex();
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false, enabled: false, identity: $this->identity(enforce: false, enabled: false), fieldGuard: $this->fieldGuard(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$existing => $this->state($existing, 'C1', Uuid::randomHex())]);

        $addressCmd = $this->addressUpdate($address, ['street' => 'Grasiger Weg 20']);
        $customerInsert = $this->insert($created, ['customer_number' => '1', 'email' => 'new@example.com']);
        $customerDelete = new DeleteCommand($this->definition, ['id' => Uuid::fromHexToBytes($deleted)], new EntityExistence('customer', ['id' => $deleted], true, false, false, []));
        $event = $this->event([$this->update($existing, ['title' => 'x']), $customerInsert, $customerDelete, $addressCmd]);

        $this->addressGuard->expects(self::once())->method('guard')->with($event, [$addressCmd], [$created], [$deleted], self::isInstanceOf(ConnectorSource::class));

        $this->subscriber->onEntityWrite($event);
    }

    public function testAddressOnlyWriteStillRunsDetection(): void
    {
        $address = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false, enabled: false, identity: $this->identity(enforce: false, enabled: false), fieldGuard: $this->fieldGuard(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->expects(self::never())->method('load');

        $addressCmd = $this->addressUpdate($address, ['street' => 'Grasiger Weg 20']);
        $this->addressGuard->expects(self::once())->method('guard')->with(self::anything(), [$addressCmd], [], [], self::isInstanceOf(ConnectorSource::class));

        $this->subscriber->onEntityWrite($this->event([$addressCmd]));
    }

    public function testAddressGuardIsNotCalledWhenTheFieldGuardIsGloballyDisabled(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, fieldGuard: $this->fieldGuard(enforce: true, enabled: false)));
        $this->connectorDetected();
        $this->addressGuard->expects(self::never())->method('guard');

        $this->subscriber->onEntityWrite($this->event([$this->addressUpdate(Uuid::randomHex(), ['street' => 'x'])]));
    }

    public function testAllThreeGuardsDisabledSkipDetectionEntirely(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, enabled: false, identity: $this->identity(enforce: true, enabled: false), fieldGuard: $this->fieldGuard(enforce: true, enabled: false)));
        $this->detector->expects(self::never())->method('resolve');

        $this->subscriber->onEntityWrite($this->event([$this->update(Uuid::randomHex(), ['title' => 'x']), $this->addressUpdate(Uuid::randomHex(), ['street' => 'x'])]));
    }
```

Add `use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;` to the imports. Also update `testBothGuardsDisabledSkipDetectionEntirely` to pass `fieldGuard: $this->fieldGuard(enforce: true, enabled: false)` (otherwise the disabled default already covers it — leave as is; both are fine).

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Subscriber/CustomerNumberWriteProtectionTest.php`
Expected: constructor argument count error (7 given, 9 expected once mocks are passed) / the seven new tests fail.

- [ ] **Step 3: Modify the subscriber**

In `src/Subscriber/CustomerNumberWriteProtection.php`:

(a) imports: add
```php
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\AddressGuard;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuard;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand;
```

(b) class docblock: add a paragraph
```
 *  - Feature 003 (FieldGuard / AddressGuard): every other column of the customer, every custom
 *    field key and every column of the customer's addresses is kept or recorded; see those
 *    services. This class only routes the commands and runs connector detection once per event.
```

(c) constructor:
```php
    public function __construct(
        private readonly GuardConfigProvider $configProvider,
        private readonly ConnectorSourceDetector $sourceDetector,
        private readonly CustomerStateLoader $stateLoader,
        private readonly NumberRangeValueGeneratorInterface $numberRangeGenerator,
        private readonly GuardLogger $guardLogger,
        private readonly FieldGuard $fieldGuard,
        private readonly AddressGuard $addressGuard,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $fallbackLogger,
    ) {
    }
```

(d) `guard()` becomes:
```php
    private function guard(EntityWriteEvent $event): void
    {
        $customerCommands = $event->getCommandsForEntity(CustomerDefinition::ENTITY_NAME);
        $addressCommands = $event->getCommandsForEntity(CustomerAddressDefinition::ENTITY_NAME);
        if ($customerCommands === [] && $addressCommands === []) {
            return;
        }

        $context = $event->getContext();
        $source = $context->getSource();
        // Cheap pre-filter: only Admin API integration writes can be the connector.
        if (!$source instanceof AdminApiSource || $source->getIntegrationId() === null || $source->getUserId() !== null) {
            return;
        }

        $globalConfig = $this->configProvider->load(null);
        if (!$globalConfig->enabled && !$globalConfig->identity->enabled && !$globalConfig->fieldGuard->enabled) {
            return;
        }

        $connector = $this->sourceDetector->resolve($context, $globalConfig);
        if ($connector === null) {
            $this->log(
                'debug',
                'jtl_connector_guard: admin-api integration write to customer not identified as the connector, left untouched',
                ['integrationId' => strtolower($source->getIntegrationId())]
            );

            return;
        }

        $updates = [];
        $jsonUpdates = [];
        $inserts = [];
        $insertedIds = [];
        $deletedIds = [];
        foreach ($customerCommands as $command) {
            // JsonUpdateCommand extends UpdateCommand: its payload keys are custom-field keys, not
            // columns, so it must be routed before the plain-update branch.
            if ($command instanceof JsonUpdateCommand) {
                $jsonUpdates[] = $command;
            } elseif ($command instanceof UpdateCommand) {
                $updates[] = $command;
            } elseif ($command instanceof InsertCommand) {
                $inserts[] = $command;
                $hex = Values::hexOrNull($command->getPrimaryKey()['id'] ?? null);
                if ($hex !== null) {
                    $insertedIds[] = $hex;
                }
            } elseif ($command instanceof DeleteCommand) {
                $hex = Values::hexOrNull($command->getPrimaryKey()['id'] ?? null);
                if ($hex !== null) {
                    $deletedIds[] = $hex;
                }
            }
        }

        $this->guardUpdates($updates, $jsonUpdates, $connector);
        $this->guardInserts($inserts, $connector, $context);

        if ($addressCommands !== [] && $globalConfig->fieldGuard->enabled) {
            $this->addressGuard->guard($event, $addressCommands, $insertedIds, $deletedIds, $connector);
        }
    }
```

(e) `guardUpdates()` signature and body:
```php
    /**
     * @param list<UpdateCommand>     $updates
     * @param list<JsonUpdateCommand> $jsonUpdates custom_fields writes (feature 003)
     */
    private function guardUpdates(array $updates, array $jsonUpdates, ConnectorSource $connector): void
    {
        if ($updates === [] && $jsonUpdates === []) {
            return;
        }

        $ids = [];
        foreach ([...$updates, ...$jsonUpdates] as $command) {
            $ids[] = (string) $command->getPrimaryKey()['id'];
        }
        $states = $this->stateLoader->load(array_values(array_unique($ids)));

        // Per-command try/catch: one bad row (state loader race, a throwing log sink, ...)
        // must not stop the remaining commands in the same batch from being guarded.
        foreach ($updates as $command) {
            $idHex = Uuid::fromBytesToHex((string) $command->getPrimaryKey()['id']);

            try {
                $this->guardUpdate($command, $idHex, $states[$idHex] ?? null, $connector);
            } catch (\Throwable $e) {
                $this->log(
                    'error',
                    sprintf('jtl_connector_guard: failed to guard customer %s, left untouched: %s', $idHex, $e->getMessage()),
                    ['exception' => $e, 'customerId' => $idHex]
                );
            }
        }

        foreach ($jsonUpdates as $command) {
            $idHex = Uuid::fromBytesToHex((string) $command->getPrimaryKey()['id']);

            try {
                $state = $states[$idHex] ?? null;
                if ($state === null) {
                    continue;
                }
                $config = $this->configProvider->load($state->getSalesChannelId());
                if ($config->fieldGuard->enabled) {
                    $this->fieldGuard->guardCustomerCustomFields($command, $idHex, $state, $config, $connector);
                }
            } catch (\Throwable $e) {
                $this->log(
                    'error',
                    sprintf('jtl_connector_guard: failed to guard custom fields of customer %s, left untouched: %s', $idHex, $e->getMessage()),
                    ['exception' => $e, 'customerId' => $idHex]
                );
            }
        }
    }
```

(f) at the end of `guardUpdate()`, after the identity block:
```php
        if ($config->fieldGuard->enabled) {
            $this->fieldGuard->guardCustomerColumns($command, $idHex, $state, $config, $connector, $handled, $sent);
        }
```

(g) `services.xml`: the subscriber definition becomes
```xml
        <service id="Revinners\ShopwareJtlConnectorGuardPlugin\Subscriber\CustomerNumberWriteProtection">
            <argument type="service" id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider"/>
            <argument type="service" id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSourceDetector"/>
            <argument type="service" id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader"/>
            <argument type="service" id="Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface"/>
            <argument type="service" id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger"/>
            <argument type="service" id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuard"/>
            <argument type="service" id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\AddressGuard"/>
            <argument type="service" id="monolog.logger.jtl_connector_guard"/>
            <argument type="service" id="logger"/>
            <tag name="kernel.event_subscriber"/>
        </service>
```
(keep whatever tag line the file already has.)

- [ ] **Step 4: Run the whole suite**

Run: `vendor/bin/phpunit`
Expected: `OK (136 tests, ...)`. Every pre-003 subscriber test must still pass unchanged apart from the setUp change.

- [ ] **Step 5: Commit**

```bash
git add src/Subscriber/CustomerNumberWriteProtection.php src/Resources/config/services.xml tests/Unit/Subscriber/CustomerNumberWriteProtectionTest.php
git commit -m "feat(003): route customer, custom-field and address commands through the field and address guards

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01QMob74xnUzbhrYWp62Ky2H"
```

---

### Task 8: Docs, version 1.2.0, push the branch

**Files:**
- Modify: `README.md`, `CHANGELOG.md`, `composer.json`, `src/ShopwareJtlConnectorGuardPlugin.php`, `specs/feat/003-connector-field-allow-list/SPEC.md`

- [ ] **Step 1: README**

Change the header line to `Features: **001** customer number write protection (1.0.x), **002** customer identity write protection (1.1.0), **003** connector field allow-list (1.2.0).` Then insert before `## Development`:

```markdown
## Feature 003 — connector field allow-list (customer + address)

001 and 002 guard four columns. The merchant's actual rule is an allow-list with one entry: on an
existing customer the JTL-Connector may change **only the customer group**. Feature 003 turns that
into config (see `specs/feat/003-connector-field-allow-list/SPEC.md`), with its own switches,
independent of 001 and 002:

- **Customer columns:** every column present in a connector update that is not on `allowedFields`
  (default `customer_group_id`, always included), not a bookkeeping column (`created_at`,
  `updated_at`, `created_by_id`, `updated_by_id`, `auto_increment`, `version_id`) and not already
  owned by 001/002, is kept in `enforce` and recorded with its pre-write value in `log_only`.
  Actions `blocked_field` / `observed_field`.
- **Customer custom fields:** the connector owns the `custom_jtl` custom-field set and pushes Wawi's
  notes `anmerkung` and `hinweis_(intern)`; those two keys are allowed by default
  (`allowedCustomFields`, enter `none` to allow nothing). Any other key is guarded per key, logged as
  `custom_fields.<key>`.
- **Addresses:** an update of an existing customer's address is guarded column by column with no
  allow-list (`blocked_address` / `observed_address`). A **new or deleted address** of an existing
  customer cannot be removed from the connector's write by the DAL, so it is recorded one row per
  column (`observed_address_create` / `observed_address_delete`); the customer's default address ids
  are customer columns and therefore stay, so a recorded new address never becomes the default.
  With `addressCreateDeletePolicy=reject_write` **and** `fieldGuardMode=enforce` the whole connector
  write is rejected instead (`rejected_write`). That fails every customer in the same sync batch, so
  keep the default `log` unless the log shows creates/deletes actually happening.
- Connector-created customers (and their addresses) are not affected. Admin, storefront, CLI and
  other-integration writes are never touched.
- Audit rows for addresses carry `entity = customer_address` and `entity_id` (the address id);
  customer rows carry `entity = customer`. Values longer than 255 characters are truncated in the
  table only; the channel log line keeps the full value.

| Key | Default | Meaning |
|---|---|---|
| `fieldGuardEnabled` | `true` | master switch of feature 003 |
| `fieldGuardMode` | `log_only` | `log_only` = record and apply; `enforce` = keep current values |
| `allowedFields` | `customer_group_id` | customer columns the connector may change (group always included) |
| `allowedCustomFields` | `anmerkung,hinweis_(intern)` | customer custom-field keys the connector may write; `none` = no key |
| `addressCreateDeletePolicy` | `log` | `log` = record a new/deleted address; `reject_write` = fail the whole write (enforce only) |

**Precedence:** 001 → 002 → 003. A column 001 lists in `protectedFields` is handled by 001 only.
`email`, `first_name`, `last_name` are handled by 002 only while `identityGuardEnabled` is on — so
to have names kept under the allow-list, set `identityGuardProtectName` to `always`; with the
identity guard disabled those three columns fall to 003 like any other column.

**Rollout:** the point of this feature is the observation window. Deploy in `log_only` for one or
two months: every `observed_*` row holds the value the connector replaced, keyed by customer and
column, which is the data needed to restore those accounts afterwards (repair is a separate job,
see spec "Out of scope"). Then switch `fieldGuardMode` to `enforce`. As with 001/002, in `log_only`
the damage keeps happening while you observe. A `rejected_write` DB row can be rolled back together
with the write it rejected; the channel file line is the reliable record for that action. Before
`enforce` on ducati-world24.com, run the custom-fields pre-check SQL from the spec there too.
```

Also add to the *Implementation notes* list of feature 001: `- Custom-field writes arrive as a separate `JsonUpdateCommand` (payload keyed by custom-field key); 001/002 ignore it, 003 guards it per key.`

- [ ] **Step 2: CHANGELOG**

Prepend to `CHANGELOG.md` after `# Changelog`:

```markdown
## 1.2.0 — unreleased

- Feature 003: connector field allow-list — on an existing customer the JTL-Connector may change only
  `customer_group_id` (config `allowedFields`) and the two Wawi note custom fields (`allowedCustomFields`);
  every other customer column, custom-field key and address column is kept (`enforce`) or recorded with its
  pre-write value (`log_only`). New or deleted addresses of existing customers are recorded per column, or
  reject the whole write under `addressCreateDeletePolicy=reject_write`. Own switches `fieldGuardEnabled` /
  `fieldGuardMode` (ships `log_only`), independent of 001 and 002.
- New audit actions `blocked_field`, `observed_field`, `blocked_address`, `observed_address`,
  `observed_address_create`, `observed_address_delete`, `rejected_write`; new columns `entity` and
  `entity_id` on `revinners_jtl_guard_log` (migration `1789171200`); values over 255 characters are
  truncated in the table only.
- Internal: `Values` helper shared by all guards (bools compared as `1`/`0`, custom-field structures by
  canonical JSON); `FieldGuard` and `AddressGuard` services; the subscriber only routes commands.
```

- [ ] **Step 3: composer.json and plugin class**

`composer.json`: `"version": "1.2.0"`, and the three descriptions become
- de-DE: `Schützt Shopware-Kundendaten vor dem Überschreiben durch den JTL-Connector: Kundennummer, E-Mail, Name und – per Freigabeliste – alle weiteren Kunden- und Adressfelder.`
- en-GB: `Protects Shopware customer data from being overwritten by the JTL-Connector: customer number, e-mail, name and, via an allow-list, every other customer and address field.`
- pl-PL: `Chroni dane klientów Shopware przed nadpisaniem przez JTL-Connector: numer klienta, e-mail, nazwisko oraz, przez listę dozwolonych, wszystkie pozostałe pola klienta i adresu.`
- top-level `description`: `Guards Shopware data against unwanted overwrites by the JTL-Connector (JTL-Wawi): customer number, e-mail and name write protection, plus an allow-list over every other customer and address field.`

`src/ShopwareJtlConnectorGuardPlugin.php` docblock: add `Feature 002: customer identity write protection. Feature 003: connector field allow-list (customer + address).`

Spec status line: `> Status: **IMPLEMENTED — see PLAN.md; released as 1.2.0 (<date>). Ships log_only.**` (fill the date at release, Task 10).

- [ ] **Step 4: Verify and commit, push the branch**

Run: `vendor/bin/phpunit && composer validate --no-check-publish && xmllint --noout src/Resources/config/config.xml src/Resources/config/services.xml`
Expected: suite green, `./composer.json is valid`, xmllint silent.

```bash
git add README.md CHANGELOG.md composer.json src/ShopwareJtlConnectorGuardPlugin.php specs/feat/003-connector-field-allow-list/SPEC.md
git commit -m "docs(003): README section, changelog 1.2.0, plugin description, spec status

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01QMob74xnUzbhrYWp62Ky2H"
git push -u origin feat/003-connector-field-allow-list
```

---

### Task 9: Live proof in the local yam-shop Docker shop (spec 003 test plan)

Same environment and cleanup discipline as 002's Task 5 (compose file `docker-compose.yam-shop.dev.yml` in `/Users/macbookpro/Soft/yam-shop`; web container `yam-shop` has no `mysql` client — use `docker compose -f docker-compose.yam-shop.dev.yml exec -T db mysql -u root -pdocker barthel_sw`; Admin API at `http://localhost/api` inside the web container; `APP_ENV=prod` so the channel file is `var/log/jtl_connector_guard_prod.log`; the DB is a prod dump with the real integration `JTL Connector`, id `019b8946ccc67767b9fb8cb524300ba1`; storefront sales channel `019b02dbf154717c8d127b5df75c3b7d`; copy the plugin with `rsync -a --delete --exclude vendor --exclude .git --exclude .superpowers --exclude .phpunit.cache --exclude .phpunit.result.cache --exclude specs` into `src/custom/plugins/ShopwareJtlConnectorGuardPlugin/`, then `plugin:refresh`, `plugin:install --activate` (or `plugin:update` when 1.1.0 is already installed — this must run migration `1789171200`), `cache:clear`; create a test integration labelled `JTL Connector` with access key `SWIAGUARDTEST` and a bcrypt secret; nothing is committed to the shop repo; restore the customer and delete everything created at the end; `git -C /Users/macbookpro/Soft/yam-shop status --short` must be empty).

Pick one existing customer with at least one address and no orders in flight; note its full `customer` and `customer_address` rows by SQL before starting.

- [ ] **Step 1 (upgrade path):** install the branch build over 1.1.0 → `SHOW COLUMNS FROM revinners_jtl_guard_log` lists `entity` (default `customer`) and `entity_id`; existing rows read `entity = customer`. Set `mode enforce` and `identityGuardMode enforce`; leave `fieldGuardMode` at `log_only`.
- [ ] **Step 2 (log_only, customer columns):** PATCH the customer through the `JTL Connector` test integration with `{"title":"GuardDr","company":"Guard GmbH","vatIds":["DE000000001"],"groupId":"<other group id>"}` → 204; all four applied; three `observed_field / log_only` rows (`title`, `company`, `vat_ids`) with `current_value` = the previous values (NULLs render as NULL) and no row for `customer_group_id`.
- [ ] **Step 3 (enforce, customer columns):** `system:config:set ShopwareJtlConnectorGuardPlugin.config.fieldGuardMode enforce` + `cache:clear`; restore the three columns by SQL; repeat the PATCH → 204; group applied, `title`/`company`/`vat_ids` unchanged; three `blocked_field / enforce` rows.
- [ ] **Step 4 (custom fields):** PATCH `{"customFields":{"hinweis_(intern)":"Guard-Hinweis","paypalexpresspayerid":"GUARD"}}` → 204; `hinweis_(intern)` applied, `paypalexpresspayerid` absent from `custom_fields` afterwards **or** present as JSON `null` (JSON_SET writes the null back) — both acceptable, note which; one `blocked_field / enforce / custom_fields.paypalexpresspayerid` row.
- [ ] **Step 5 (address update via sync):** `POST /api/_action/sync` with one `upsert` on `customer` carrying `{"id":..., "groupId":..., "addresses":[{"id":"<existing address id>","street":"Guard-Str. 1","zipcode":"99999"}]}` → 200; group applied, street and zipcode unchanged; two `blocked_address / enforce` rows with `entity = customer_address`, `entity_id` = the address id.
- [ ] **Step 6 (address create, policy log):** sync upsert with a new address (fresh id, `customerId` = the customer) → 200; address exists, is **not** the default billing/shipping address; `observed_address_create` rows (one per non-null column) with `attempted_value` filled.
- [ ] **Step 7 (address delete, policy log):** `DELETE /api/customer-address/<the new address id>` through the test integration → 204; `observed_address_delete` rows with `current_value` filled.
- [ ] **Step 8 (reject_write):** `system:config:set ShopwareJtlConnectorGuardPlugin.config.addressCreateDeletePolicy reject_write` + `cache:clear`; repeat the address-create sync → HTTP 400 with `FRAMEWORK__WRITE_CONSTRAINT_VIOLATION` mentioning `addressCreateDeletePolicy=reject_write`; no new address; the channel file has the `rejected_write` line (the DB row may or may not exist — record which). Reset the policy to `log`.
- [ ] **Step 9 (connector create with address):** sync upsert with a brand-new customer carrying one address → 201/200; nothing from 003 in the log; 001 `remapped_create` as before.
- [ ] **Step 10 (regression):** the Step 3 PATCH through a second integration labelled `Other API client` and through an admin user → applied, no rows (debug line only). The 001 and 002 checks from their live proofs (number kept, email kept) still hold in this build.
- [ ] **Step 11:** cleanup exactly as in 001/002 (uninstall drops the table, delete integrations/user, restore the customer's and address's original rows, delete the created customer/addresses, delete `system_config` rows `LIKE 'ShopwareJtlConnectorGuardPlugin.%'`, remove the plugin copy, `cache:clear`, shop `git status` empty). Append a verification paragraph to `CHANGELOG.md` under 1.2.0 (same style as 1.1.0's) and commit + push in the plugin repo.

If a step fails because of plugin code: fix it in the plugin with a reproducing unit test, commit, re-rsync, repeat from that step.

---

### Task 10: Release 1.2.0, bump yam-shop, ecosystem docs

- [ ] **Step 1:** fill the release date into `CHANGELOG.md` (`## 1.2.0 — YYYY-MM-DD`) and the spec status line; commit `docs(003): release date`.
- [ ] **Step 2:** plugin repo: `git checkout master && git merge --ff-only feat/003-connector-field-allow-list && git push origin master && git tag -a 1.2.0 -m "1.2.0 - connector field allow-list (003)" && git push origin 1.2.0`; verify with `git ls-remote --tags origin`.
- [ ] **Step 3:** shop: from `/Users/macbookpro/Soft/yam-shop/src` run `composer update revinners/shopware6-jtl-connector-guard --no-scripts --no-install --no-interaction`; `git diff src/composer.lock | grep -E '^[-+] +"version"'` must show only `1.1.0 → 1.2.0`; commit `chore: bump revinners/shopware6-jtl-connector-guard to 1.2.0 (field allow-list, log_only)` with the trailers. Do not push the shop.
- [ ] **Step 4:** ecosystem docs: in `~/.claude/skills/shopware-ecosystem-architect/repositories.md` and `sw_plugins.md`, extend the plugin's row text to "features 001 customer_number + 002 identity (email/name) + 003 field allow-list (customer + address)".
- [ ] **Step 5:** production rollout reminder for the user (not automated): deploy 1.2.0 to yam-shop.de with `fieldGuardMode=log_only`; after the observation window switch to `enforce`; run the spec's SQL pre-checks Q1/Q2/Q5 on ducati-world24.com before installing there.

---

## Self-review

- **Spec coverage:** R1 (allow-list, always `customer_group_id`, precedence 001→002→003, inserts untouched) → Task 1 config + Task 5 `guardCustomerColumns` (+ tests for owned columns, `$sent`) + Task 7 routing (`$handled`, `$sent`, inserts never reach FieldGuard). R1a (`JsonUpdateCommand` per key, default keys, `null` write-back, other storage names ignored) → Task 5 `guardCustomerCustomFields` + Task 7 routing before the plain-update branch + the "custom field named email" regression test. R2 (address updates no allow-list; inserts for new customers ignored; creates/deletes recorded per column; `reject_write` only in enforce; default `log`) → Task 6. R3 (five flat keys, third card, defaults, independence) → Task 1. R4 (seven actions, `field` naming, `entity`/`entity_id`, migration, truncation, customer identity on every row) → Task 3 + entries built in Tasks 5/6. R5 (bookkeeping list) → Task 1 constant, used in Tasks 5/6. R6 (no non-connector writes, fail-safe, per-command try/catch, never abort except `reject_write`, no repair) → Task 6 `safely()`, Task 7 unchanged detection and try/catch, no repair code anywhere. Technical notes (address loader, parent customer load, `$handled`/`$sent`, definition/entity, `customer_group_id` always allowed) → Tasks 4, 6, 3, 1. Test plan 1–7 → Task 9 Steps 2–10.
- **Placeholder scan:** none. The only conditional instruction (Task 6 Step 5 fallback for the failing-command test) states the exact alternative.
- **Type consistency:** `FieldGuardConfig(enabled, enforce, allowedFields, allowedCustomFields, addressCreateDeletePolicy)` identical in Tasks 1, 5, 6, 7 tests; `GuardConfig` 7th param `fieldGuard` used as `$config->fieldGuard` in Tasks 5, 6, 7; `GuardLogEntry` trailing params `entity`, `entityId` used by name in Tasks 3, 6; `FieldGuard::guardCustomerColumns(command, idHex, state, config, connector, handled, sent)` matches the Task 7 call and mock expectation; `FieldGuard::guardCustomerCustomFields(command, idHex, state, config, connector)` likewise; `AddressGuard::guard(event, commands, insertedCustomerIdsHex, deletedCustomerIdsHex, connector)` matches Task 7's call and mock expectation; `CustomerAddressState::columns()` used by `AddressGuard` for deletes; `Values::hexOrNull` replaces the subscriber's private helper before Task 7 uses it in `guard()`; provider memo count 13 keys × 2 = 26 matches the 5 new keys added to 8.
