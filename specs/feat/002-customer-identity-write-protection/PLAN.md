# 002 - Customer Identity Write Protection — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend `ShopwareJtlConnectorGuardPlugin` (1.0.1) so that a JTL-Connector update of an existing customer can no longer replace the customer's identity (`email`, and `first_name`/`last_name` when paired with an email swap), with its own `log_only`/`enforce` switch, independent of the customer-number guard, logged to the same audit trail. Released as **1.1.0**.

**Architecture:** No new subscriber. `CustomerNumberWriteProtection::guardUpdate()` gains a second step, `guardIdentity()`, that runs after the 001 block-list step on the same live `UpdateCommand` and the same already-loaded `CustomerState`. A new readonly `IdentityGuardConfig` (enabled / enforce / protectName) hangs off `GuardConfig` as an optional 6th constructor argument (defaults to "disabled" so every existing test and call site keeps compiling). Two new audit actions, `blocked_identity` and `observed_identity`, encode whether the value was kept or merely observed; `GuardLogger::message()` dispatches on the action. Email equality is case-insensitive and trimmed. Nothing changes for inserts, for admin/storefront/system writes, or for the address entity.

**Tech Stack:** unchanged — PHP 8.2+, Shopware 6.6.10.x, PHPUnit 11 unit tests with `dg/bypass-finals` (scoped to `src/`), plain DBAL for the audit row.

**Spec:** `specs/feat/002-customer-identity-write-protection/SPEC.md` (same folder as this plan). 001's spec/plan in `specs/feat/001-customer-number-write-protection/` describe the code being extended.

## Global Constraints

- Repo `/Users/macbookpro/Soft/ShopwareJtlConnectorGuardPlugin`, branch `feat/002-customer-identity-write-protection` (starts at `master` = `62313e3` = tag `1.0.1`). Consumer shop `/Users/macbookpro/Soft/yam-shop` (composer project in `src/`, two local unpushed commits on `master` already reference the plugin at 1.0.1).
- Spec R5: never guard admin-user, storefront, CLI or non-connector writes (reuse 001's detection, fail-safe to "do nothing"); never abort the write; never touch address fields; no data migration on install.
- Spec R3: identity guard and number guard are **independently switchable** — 002 may run `log_only` while 001 runs `enforce`, and either may be disabled alone.
- Config keys (flat, Shopware config.xml has no nesting): `ShopwareJtlConnectorGuardPlugin.config.identityGuardEnabled` (bool, default `true`), `.identityGuardMode` (`log_only` | `enforce`, default `log_only`), `.identityGuardProtectName` (`on_email_swap` | `always` | `off`, default `on_email_swap`).
- Audit actions exactly `blocked_identity` (value kept, enforce) and `observed_identity` (value applied, either because of `log_only` or because the name is not protected in the current policy). Fields logged as storage column names `email`, `first_name`, `last_name`. No DB migration: `action` is `VARCHAR(32)`, `field` is `VARCHAR(64)`.
- Email comparison = `mb_strtolower(trim())` equality; `null` vs non-null is a difference.
- A field already covered by 001's `protectedFields` block list (and the number guard enabled) is handled by 001 only — never logged twice.
- Style: `declare(strict_types=1)`, `final` classes, constructor promotion, readonly value objects, English comments. Tests run from the plugin root with `vendor/bin/phpunit` (baseline: 52 tests green, pristine, `failOnWarning`).
- Every git commit message must end with these two trailer lines:
  ```
  Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_012JytMPkTC5r69aAKyP7tmB
  ```
- Release checklist (README): bump `version` in composer.json together with the tag; tags have no `v` prefix.

---

## Design decisions resolved from the spec's open questions

| # | Question | Decision |
|---|---|---|
| Spec header "do not implement before 001 is observed on prod" | — | Overridden by the user on 2026-09-07 ("keep going with spec 002 now"). 002 ships `log_only` by default, so it can be deployed together with 001 without changing behaviour. |
| Same subscriber or a new one? | Technical notes say "add to the set of guarded fields" | Same subscriber (`CustomerNumberWriteProtection`), new private step `guardIdentity()`. The class name stays (renaming would churn `services.xml`, tests and docs for no behaviour gain); its doc-comment is updated to say it now guards number + identity. |
| Where do the identity config values live? | R3 "extend 001's config, do not fork it" | Three new fields in a **second card** of the same `config.xml`, read by the same `GuardConfigProvider`, exposed as `GuardConfig::$identity` (`IdentityGuardConfig`). Per-sales-channel inheritance is inherited for free. |
| `protectName` semantics | R2 | `on_email_swap` (default): name fields are kept only when the same write also swaps the email; a name-only change is **logged as `observed_identity` and applied** (measurement). `always`: name changes are guarded like email. `off`: name changes are neither guarded nor logged. |
| Action naming | R4 | `blocked_identity` when the guard wrote the current value back; `observed_identity` in every other logged case. `mode` column still records the identity guard's mode, so "observed in enforce" (unprotected name) is distinguishable from "observed in log_only". |
| Q1/Q2 (how often legitimate changes happen) | measure | Answered by `log_only` + the audit table; not code. |
| Repair job | out of scope | Untouched. |

## File structure

```
src/Service/IdentityGuardConfig.php            NEW  readonly VO: enabled, enforce, protectName (+ constants, mode(), disabled())
src/Service/GuardConfig.php                    MOD  6th promoted param `IdentityGuardConfig $identity` with a disabled default
src/Service/GuardConfigProvider.php            MOD  parse the three identityGuard* keys
src/Service/GuardLogEntry.php                  MOD  two new ACTION_* constants
src/Service/GuardLogger.php                    MOD  message() dispatches identity actions
src/Subscriber/CustomerNumberWriteProtection.php MOD  guardIdentity() step, independent enable gating, sameEmail()
src/Resources/config/config.xml                MOD  second card with the three fields
README.md / CHANGELOG.md / composer.json       MOD  feature 002 docs, 1.1.0
specs/feat/002-customer-identity-write-protection/{SPEC.md,PLAN.md}  committed with Task 1
tests/Unit/Service/IdentityGuardConfigTest.php NEW
tests/Unit/Service/GuardConfigProviderTest.php MOD
tests/Unit/Service/GuardLoggerTest.php         MOD
tests/Unit/Subscriber/CustomerNumberWriteProtectionTest.php MOD
```

---

### Task 1: IdentityGuardConfig, GuardConfig extension, provider parsing, config.xml card

**Files:**
- Create: `src/Service/IdentityGuardConfig.php`, `tests/Unit/Service/IdentityGuardConfigTest.php`
- Modify: `src/Service/GuardConfig.php`, `src/Service/GuardConfigProvider.php`, `src/Resources/config/config.xml`
- Test: `tests/Unit/Service/GuardConfigProviderTest.php`
- Commit also the untracked `specs/feat/002-customer-identity-write-protection/SPEC.md` and `PLAN.md`.

**Interfaces:**
- Produces:
  - `final readonly class IdentityGuardConfig { public const PROTECT_NAME_ON_EMAIL_SWAP = 'on_email_swap'; public const PROTECT_NAME_ALWAYS = 'always'; public const PROTECT_NAME_OFF = 'off'; public const PROTECT_NAME_VALUES = [...]; public function __construct(public bool $enabled, public bool $enforce, public string $protectName); public static function disabled(): self; public function mode(): string }`
  - `GuardConfig::__construct(bool $enabled, bool $enforce, array $integrationLabels, array $integrationIds, array $protectedFields, IdentityGuardConfig $identity = new IdentityGuardConfig(false, false, IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP))` — public readonly property `$identity`.
  - `GuardConfigProvider` constants `KEY_IDENTITY_ENABLED = 'identityGuardEnabled'`, `KEY_IDENTITY_MODE = 'identityGuardMode'`, `KEY_IDENTITY_PROTECT_NAME = 'identityGuardProtectName'`; `load()` now reads 8 keys per sales channel.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Service/IdentityGuardConfigTest.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\IdentityGuardConfig;

final class IdentityGuardConfigTest extends TestCase
{
    public function testDisabledFactoryIsOffAndLogOnly(): void
    {
        $identity = IdentityGuardConfig::disabled();

        self::assertFalse($identity->enabled);
        self::assertFalse($identity->enforce);
        self::assertSame(IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP, $identity->protectName);
        self::assertSame(GuardConfigProvider::MODE_LOG_ONLY, $identity->mode());
    }

    public function testModeReflectsEnforce(): void
    {
        self::assertSame(GuardConfigProvider::MODE_ENFORCE, (new IdentityGuardConfig(true, true, IdentityGuardConfig::PROTECT_NAME_ALWAYS))->mode());
    }

    public function testGuardConfigDefaultsToADisabledIdentityGuard(): void
    {
        $config = new GuardConfig(true, true, ['JTL-Connector'], [], ['customer_number']);

        self::assertFalse($config->identity->enabled, '5-argument construction (all pre-002 call sites) must keep the identity guard off');
    }
}
```

Append to `tests/Unit/Service/GuardConfigProviderTest.php` (inside the class, after `testResetClearsTheMemo`):
```php
    public function testIdentityGuardDefaultsWhenNothingIsConfigured(): void
    {
        $identity = $this->providerWith([])->load()->identity;

        self::assertTrue($identity->enabled);
        self::assertFalse($identity->enforce, 'identity guard ships in log_only');
        self::assertSame(IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP, $identity->protectName);
    }

    public function testIdentityGuardParsesConfiguredValues(): void
    {
        $identity = $this->providerWith([
            'identityGuardEnabled' => false,
            'identityGuardMode' => 'enforce',
            'identityGuardProtectName' => 'always',
        ])->load('sc-1')->identity;

        self::assertFalse($identity->enabled);
        self::assertTrue($identity->enforce);
        self::assertSame(IdentityGuardConfig::PROTECT_NAME_ALWAYS, $identity->protectName);
    }

    public function testIdentityGuardIsIndependentOfTheNumberGuard(): void
    {
        $config = $this->providerWith(['enabled' => false, 'mode' => 'log_only', 'identityGuardMode' => 'enforce'])->load();

        self::assertFalse($config->enabled);
        self::assertFalse($config->enforce);
        self::assertTrue($config->identity->enabled);
        self::assertTrue($config->identity->enforce);
    }

    public function testUnknownIdentityValuesFallBackToDefaults(): void
    {
        $identity = $this->providerWith(['identityGuardMode' => 'yolo', 'identityGuardProtectName' => 'sometimes'])->load()->identity;

        self::assertFalse($identity->enforce);
        self::assertSame(IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP, $identity->protectName);
    }
```
Add `use Revinners\ShopwareJtlConnectorGuardPlugin\Service\IdentityGuardConfig;` to that test file's imports. Then update the two memoisation tests in the same file: `load()` now reads **8** keys per sales channel, so change both `self::exactly(10)` to `self::exactly(16)` and the comment `// 5 keys x 2 channels` to `// 8 keys x 2 channels`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Service/IdentityGuardConfigTest.php tests/Unit/Service/GuardConfigProviderTest.php`
Expected: errors `Class ... IdentityGuardConfig not found` and, for the provider tests, undefined property `identity`.

- [ ] **Step 3: Write the value object and extend `GuardConfig`**

`src/Service/IdentityGuardConfig.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

/**
 * Feature 002 configuration: protection of a customer's identity (email, and the name when
 * paired with an email swap) against the JTL-Connector. Independent of the number guard.
 */
final readonly class IdentityGuardConfig
{
    /** Name fields are kept only when the same write also swaps the email (default). */
    public const PROTECT_NAME_ON_EMAIL_SWAP = 'on_email_swap';

    /** Name fields are guarded like the email. */
    public const PROTECT_NAME_ALWAYS = 'always';

    /** Name changes are neither guarded nor logged. */
    public const PROTECT_NAME_OFF = 'off';

    public const PROTECT_NAME_VALUES = [
        self::PROTECT_NAME_ON_EMAIL_SWAP,
        self::PROTECT_NAME_ALWAYS,
        self::PROTECT_NAME_OFF,
    ];

    public function __construct(
        public bool $enabled,
        public bool $enforce,
        public string $protectName,
    ) {
    }

    public static function disabled(): self
    {
        return new self(false, false, self::PROTECT_NAME_ON_EMAIL_SWAP);
    }

    public function mode(): string
    {
        return $this->enforce ? GuardConfigProvider::MODE_ENFORCE : GuardConfigProvider::MODE_LOG_ONLY;
    }
}
```

`src/Service/GuardConfig.php` — replace the constructor (keep the class doc-comment and `mode()`):
```php
    /**
     * @param list<string> $integrationLabels labels of the connector's Admin API integrations
     * @param list<string> $integrationIds    lowercase 32-char hex ids of the connector's integrations
     * @param list<string> $protectedFields   storage column names of `customer` the connector may not change; always contains customer_number
     * @param IdentityGuardConfig $identity   feature 002 (identity guard); defaults to disabled so pre-002 call sites are unaffected
     */
    public function __construct(
        public bool $enabled,
        public bool $enforce,
        public array $integrationLabels,
        public array $integrationIds,
        public array $protectedFields,
        public IdentityGuardConfig $identity = new IdentityGuardConfig(false, false, IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP),
    ) {
    }
```

- [ ] **Step 4: Extend `GuardConfigProvider::load()`**

Add the constants after `FIELD_CUSTOMER_NUMBER`:
```php
    public const KEY_IDENTITY_ENABLED = 'identityGuardEnabled';

    public const KEY_IDENTITY_MODE = 'identityGuardMode';

    public const KEY_IDENTITY_PROTECT_NAME = 'identityGuardProtectName';
```
In `load()`, after the `$fields = ...` block and before `return $this->memo[...]`, add:
```php
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
```
and pass `identity: $identity,` as the last named argument of `new GuardConfig(...)`. Update the class doc-comment to "Reads the plugin config (config.xml, both cards) into a GuardConfig ...".

- [ ] **Step 5: Add the second card to `config.xml`**

Insert before the final `</config>`:
```xml
    <card>
        <title>JTL-Connector Guard — customer identity protection</title>
        <title lang="de-DE">JTL-Connector Guard — Kundenidentitäts-Schutz</title>
        <title lang="pl-PL">JTL-Connector Guard — ochrona tożsamości klienta</title>

        <input-field type="bool">
            <name>identityGuardEnabled</name>
            <label>Identity guard enabled</label>
            <label lang="de-DE">Identitätsschutz aktiv</label>
            <label lang="pl-PL">Ochrona tożsamości włączona</label>
            <helpText>Protects an existing customer's e-mail (and name, see below) from being replaced by the JTL-Connector. Independent of the customer-number guard above.</helpText>
            <helpText lang="de-DE">Schützt E-Mail (und Name, siehe unten) eines bestehenden Kunden vor dem Überschreiben durch den JTL-Connector. Unabhängig vom Kundennummern-Schutz oben.</helpText>
            <helpText lang="pl-PL">Chroni e-mail (i nazwisko, patrz niżej) istniejącego klienta przed nadpisaniem przez JTL-Connector. Niezależne od ochrony numeru klienta powyżej.</helpText>
            <defaultValue>true</defaultValue>
        </input-field>

        <input-field type="single-select">
            <name>identityGuardMode</name>
            <label>Identity guard mode</label>
            <label lang="de-DE">Modus Identitätsschutz</label>
            <label lang="pl-PL">Tryb ochrony tożsamości</label>
            <helpText>"Log only" records every e-mail/name replacement but applies it (use first on production). "Enforce" keeps the current e-mail (and name per the policy below) and applies the rest of the connector's write.</helpText>
            <helpText lang="de-DE">"Nur protokollieren" zeichnet jeden E-Mail-/Namenstausch auf, wendet ihn aber an (zuerst in Produktion verwenden). "Durchsetzen" behält die aktuelle E-Mail (und den Namen gemäß Regel unten) und wendet den Rest des Connector-Schreibvorgangs an.</helpText>
            <helpText lang="pl-PL">"Tylko loguj" zapisuje każdą podmianę e-maila/nazwiska, ale ją stosuje (użyj najpierw na produkcji). "Wymuszaj" zachowuje obecny e-mail (i nazwisko wg reguły poniżej), a resztę zapisu connectora stosuje.</helpText>
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

        <input-field type="single-select">
            <name>identityGuardProtectName</name>
            <label>Protect first/last name</label>
            <label lang="de-DE">Vor-/Nachname schützen</label>
            <label lang="pl-PL">Chroń imię/nazwisko</label>
            <helpText>"Only with an e-mail swap" (default): the name is kept only when the same write also replaces the e-mail; a name-only change is logged as observed and applied. "Always": name changes are guarded like the e-mail. "Off": name changes are neither guarded nor logged.</helpText>
            <helpText lang="de-DE">"Nur bei E-Mail-Tausch" (Standard): der Name wird nur behalten, wenn derselbe Schreibvorgang auch die E-Mail ersetzt; eine reine Namensänderung wird protokolliert und angewendet. "Immer": Namensänderungen werden wie die E-Mail geschützt. "Aus": Namensänderungen werden weder geschützt noch protokolliert.</helpText>
            <helpText lang="pl-PL">"Tylko przy podmianie e-maila" (domyślnie): nazwisko jest zachowywane tylko, gdy ten sam zapis podmienia też e-mail; sama zmiana nazwiska jest logowana i stosowana. "Zawsze": zmiany nazwiska chronione jak e-mail. "Wyłączone": zmiany nazwiska nie są ani chronione, ani logowane.</helpText>
            <options>
                <option>
                    <id>on_email_swap</id>
                    <name>Only together with an e-mail swap</name>
                    <name lang="de-DE">Nur zusammen mit E-Mail-Tausch</name>
                    <name lang="pl-PL">Tylko razem z podmianą e-maila</name>
                </option>
                <option>
                    <id>always</id>
                    <name>Always</name>
                    <name lang="de-DE">Immer</name>
                    <name lang="pl-PL">Zawsze</name>
                </option>
                <option>
                    <id>off</id>
                    <name>Off</name>
                    <name lang="de-DE">Aus</name>
                    <name lang="pl-PL">Wyłączone</name>
                </option>
            </options>
            <defaultValue>on_email_swap</defaultValue>
        </input-field>
    </card>
```
Also extend `tests/Unit/ShopwareJtlConnectorGuardPluginTest::testConfigXmlDeclaresAllKeys` so the key list includes `identityGuardEnabled`, `identityGuardMode`, `identityGuardProtectName` and add `self::assertStringContainsString('<defaultValue>on_email_swap</defaultValue>', $xml);`.

- [ ] **Step 6: Run the suite**

Run: `vendor/bin/phpunit`
Expected: green (52 + 3 + 4 = 59 tests), pristine.

- [ ] **Step 7: Commit**

```bash
git add specs/feat/002-customer-identity-write-protection src/Service/IdentityGuardConfig.php src/Service/GuardConfig.php src/Service/GuardConfigProvider.php src/Resources/config/config.xml tests/Unit/Service/IdentityGuardConfigTest.php tests/Unit/Service/GuardConfigProviderTest.php tests/Unit/ShopwareJtlConnectorGuardPluginTest.php
git commit -m "feat(002): identity guard configuration (enabled, mode, protectName)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_012JytMPkTC5r69aAKyP7tmB"
```

---

### Task 2: Audit actions `blocked_identity` / `observed_identity` and their log messages

**Files:**
- Modify: `src/Service/GuardLogEntry.php`, `src/Service/GuardLogger.php`
- Test: `tests/Unit/Service/GuardLoggerTest.php`

**Interfaces:**
- Produces: `GuardLogEntry::ACTION_BLOCKED_IDENTITY = 'blocked_identity'`, `GuardLogEntry::ACTION_OBSERVED_IDENTITY = 'observed_identity'`. `GuardLogger::message()` renders `blocked_identity` as `kept "<current>", connector sent "<attempted>" (identity guard)` and `observed_identity` as `connector sent "<attempted>" over "<current>" and it was applied (identity guard, observed only)` regardless of `mode`.

- [ ] **Step 1: Write the failing tests** (append inside `GuardLoggerTest`)

```php
    private function identityEntry(string $action, string $mode, string $field = 'email'): GuardLogEntry
    {
        return new GuardLogEntry(
            action: $action,
            mode: $mode,
            field: $field,
            customerId: '019daaeff59572c2a4f5c068e613edb5',
            email: 'tobiasschroeer1999@web.de',
            firstName: 'Tobias',
            lastName: 'Schröer',
            currentValue: $field === 'email' ? 'tobiasschroeer1999@web.de' : 'Schröer',
            attemptedValue: $field === 'email' ? 'info@motorradgarage-dachau.de' : 'Kühnel',
            assignedValue: null,
            integrationId: '019b8946ccc67767b9fb8cb524300ba1',
            integrationLabel: 'JTL Connector',
            salesChannelId: null,
        );
    }

    public function testBlockedIdentityMessageSaysTheEmailWasKept(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::logicalAnd(
            self::stringContains('blocked_identity'),
            self::stringContains('kept "tobiasschroeer1999@web.de"'),
            self::stringContains('connector sent "info@motorradgarage-dachau.de"'),
            self::stringContains('identity guard'),
        ), self::anything());

        (new GuardLogger($logger, $this->createMock(LoggerInterface::class), $this->createMock(Connection::class)))
            ->log($this->identityEntry(GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'enforce'));
    }

    public function testObservedIdentityInEnforceModeSaysTheValueWasApplied(): void
    {
        // an unprotected name-only change is observed even while the identity guard is in enforce
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::logicalAnd(
            self::stringContains('observed_identity'),
            self::stringContains('connector sent "Kühnel" over "Schröer" and it was applied'),
            self::logicalNot(self::stringContains('kept "')),
        ), self::anything());

        (new GuardLogger($logger, $this->createMock(LoggerInterface::class), $this->createMock(Connection::class)))
            ->log($this->identityEntry(GuardLogEntry::ACTION_OBSERVED_IDENTITY, 'enforce', 'last_name'));
    }

    public function testIdentityRowIsPersistedWithItsAction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with('revinners_jtl_guard_log', self::callback(
            static fn (array $row): bool => $row['action'] === 'observed_identity' && $row['field'] === 'email' && $row['mode'] === 'log_only'
        ));

        (new GuardLogger($this->createMock(LoggerInterface::class), $this->createMock(LoggerInterface::class), $connection))
            ->log($this->identityEntry(GuardLogEntry::ACTION_OBSERVED_IDENTITY, 'log_only'));
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Service/GuardLoggerTest.php`
Expected: `Undefined constant ... ACTION_BLOCKED_IDENTITY`.

- [ ] **Step 3: Add the constants and the message dispatch**

`GuardLogEntry.php` — after `ACTION_REMAPPED_CREATE`:
```php
    /** Feature 002: the guard wrote the current identity value back (enforce). */
    public const ACTION_BLOCKED_IDENTITY = 'blocked_identity';

    /** Feature 002: an identity change was recorded but applied (log_only, or an unprotected name change). */
    public const ACTION_OBSERVED_IDENTITY = 'observed_identity';
```

`GuardLogger::message()` — replace the outcome selection expression with a `match`:
```php
            match ($entry->action) {
                GuardLogEntry::ACTION_REMAPPED_CREATE => $this->createOutcome($entry),
                GuardLogEntry::ACTION_BLOCKED_IDENTITY => sprintf(
                    'kept "%s", connector sent "%s" (identity guard)',
                    $entry->currentValue ?? '',
                    $entry->attemptedValue ?? '',
                ),
                GuardLogEntry::ACTION_OBSERVED_IDENTITY => sprintf(
                    'connector sent "%s" over "%s" and it was applied (identity guard, observed only)',
                    $entry->attemptedValue ?? '',
                    $entry->currentValue ?? '',
                ),
                default => $this->updateOutcome($entry),
            },
```
Update the `message()` doc-comment: identity actions carry their outcome in the action itself (`blocked_*` = kept, `observed_*` = applied), so they do not depend on `mode`.

- [ ] **Step 4: Run the suite**

Run: `vendor/bin/phpunit`
Expected: green (62 tests), pristine.

- [ ] **Step 5: Commit**

```bash
git add src/Service/GuardLogEntry.php src/Service/GuardLogger.php tests/Unit/Service/GuardLoggerTest.php
git commit -m "feat(002): blocked_identity / observed_identity audit actions

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_012JytMPkTC5r69aAKyP7tmB"
```

---

### Task 3: The identity guard step in `CustomerNumberWriteProtection`

**Files:**
- Modify: `src/Subscriber/CustomerNumberWriteProtection.php`
- Test: `tests/Unit/Subscriber/CustomerNumberWriteProtectionTest.php`

**Interfaces:**
- Consumes: `GuardConfig::$identity` (Task 1), `GuardLogEntry::ACTION_BLOCKED_IDENTITY / ACTION_OBSERVED_IDENTITY` (Task 2), existing `CustomerState::get()/getEmail()/getFirstName()/getLastName()`, `UpdateCommand::hasField()/getPayload()/addPayload()`.
- Produces (private, but the behaviour contract): in `guard()`, the early return happens only when **both** `$globalConfig->enabled` and `$globalConfig->identity->enabled` are false. In `guardUpdate()`: the 001 block-list loop runs only when `$config->enabled`; then `guardIdentity()` runs when `$config->identity->enabled`. `guardInsert()` is unchanged (number guard only). Constants `FIELD_EMAIL = 'email'`, `NAME_FIELDS = ['first_name', 'last_name']`.

- [ ] **Step 1: Extend the test helper and write the failing tests**

In `CustomerNumberWriteProtectionTest`, replace the `config()` helper:
```php
    private function config(bool $enforce, bool $enabled = true, array $protected = ['customer_number'], ?IdentityGuardConfig $identity = null): GuardConfig
    {
        return new GuardConfig($enabled, $enforce, ['JTL-Connector'], [], $protected, $identity ?? IdentityGuardConfig::disabled());
    }

    private function identity(bool $enforce, string $protectName = IdentityGuardConfig::PROTECT_NAME_ON_EMAIL_SWAP, bool $enabled = true): IdentityGuardConfig
    {
        return new IdentityGuardConfig($enabled, $enforce, $protectName);
    }

    /**
     * @return list<array{action: string, field: string, current: ?string, attempted: ?string, mode: string}>
     */
    private function captureLog(): array
    {
        $captured = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$captured): void {
            $captured[] = ['action' => $e->action, 'field' => $e->field, 'current' => $e->currentValue, 'attempted' => $e->attemptedValue, 'mode' => $e->mode];
        });

        return $captured; // NOTE: PHP arrays are by-value; read the by-reference variable via the closure below instead
    }
```
Actually use this simpler capture pattern in each test (drop `captureLog()`): `$logged = []; $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void { $logged[] = [$e->action, $e->field, $e->currentValue, $e->attemptedValue, $e->mode]; });` — the plan shows it inline below; do not add `captureLog()`.

Add `use Revinners\ShopwareJtlConnectorGuardPlugin\Service\IdentityGuardConfig;` to the imports. Append these tests before the final `}`:
```php
    // ---- feature 002: identity guard ---------------------------------------

    public function testIdentityGuardDisabledLeavesAnEmailSwapUntouched(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->update($id, ['email' => 'info@motorradgarage-dachau.de']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('info@motorradgarage-dachau.de', $cmd->getPayload()['email']);
    }

    public function testEnforceKeepsTheEmailAndAppliesTheRestOfTheWrite(): void
    {
        $id = Uuid::randomHex();
        $group = Uuid::randomBytes();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false, identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field, $e->currentValue, $e->attemptedValue, $e->mode];
        });

        $cmd = $this->update($id, ['email' => 'info@motorradgarage-dachau.de', 'customer_group_id' => $group]);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('erdoesi@example.com', $cmd->getPayload()['email'], 'email reverted to the current value');
        self::assertSame($group, $cmd->getPayload()['customer_group_id'], 'other fields untouched');
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'email', 'erdoesi@example.com', 'info@motorradgarage-dachau.de', 'enforce']], $logged);
    }

    public function testLogOnlyObservesTheEmailSwapButAppliesIt(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, identity: $this->identity(enforce: false)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field, $e->mode];
        });

        $cmd = $this->update($id, ['email' => 'info@motorradgarage-dachau.de']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('info@motorradgarage-dachau.de', $cmd->getPayload()['email']);
        self::assertSame([[GuardLogEntry::ACTION_OBSERVED_IDENTITY, 'email', 'log_only']], $logged);
    }

    public function testSameEmailInDifferentCaseOrWhitespaceIsNotASwap(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->update($id, ['email' => '  Erdoesi@Example.COM ']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('  Erdoesi@Example.COM ', $cmd->getPayload()['email'], 'not our business; left as sent');
    }

    public function testEmailAndNameSwapInOneWriteKeepsAllThreeInEnforce(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field];
        });

        $cmd = $this->update($id, ['email' => 'info@motorradgarage-dachau.de', 'first_name' => 'Christopher', 'last_name' => 'Kühnel']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('erdoesi@example.com', $cmd->getPayload()['email']);
        self::assertSame('Adam', $cmd->getPayload()['first_name']);
        self::assertSame('Erdösi', $cmd->getPayload()['last_name']);
        self::assertSame([
            [GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'email'],
            [GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'first_name'],
            [GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'last_name'],
        ], $logged);
    }

    public function testNameOnlyChangeIsObservedAndAppliedWithOnEmailSwapPolicy(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field, $e->mode];
        });

        $cmd = $this->update($id, ['last_name' => 'Erdösi-Wagner', 'email' => 'erdoesi@example.com']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('Erdösi-Wagner', $cmd->getPayload()['last_name'], 'ambiguous name-only change is applied');
        self::assertSame([[GuardLogEntry::ACTION_OBSERVED_IDENTITY, 'last_name', 'enforce']], $logged, 'but observed, with the guard mode recorded');
    }

    public function testNameOnlyChangeIsKeptWithAlwaysPolicy(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, identity: $this->identity(enforce: true, protectName: IdentityGuardConfig::PROTECT_NAME_ALWAYS)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field];
        });

        $cmd = $this->update($id, ['first_name' => 'Christopher']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('Adam', $cmd->getPayload()['first_name']);
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'first_name']], $logged);
    }

    public function testNameChangeIsIgnoredWithOffPolicy(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, identity: $this->identity(enforce: true, protectName: IdentityGuardConfig::PROTECT_NAME_OFF)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field];
        });

        $cmd = $this->update($id, ['email' => 'info@motorradgarage-dachau.de', 'last_name' => 'Kühnel']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('erdoesi@example.com', $cmd->getPayload()['email'], 'email still guarded');
        self::assertSame('Kühnel', $cmd->getPayload()['last_name'], 'name neither guarded nor logged');
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'email']], $logged);
    }

    public function testIdentityGuardRunsEvenWhenTheNumberGuardIsDisabled(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, enabled: false, identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field];
        });

        $cmd = $this->update($id, ['customer_number' => '10009', 'email' => 'info@motorradgarage-dachau.de']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('10009', $cmd->getPayload()['customer_number'], 'number guard off: number change applied and not logged');
        self::assertSame('erdoesi@example.com', $cmd->getPayload()['email'], 'identity guard on: email kept');
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'email']], $logged);
    }

    public function testEmailInTheNumberGuardBlockListIsHandledOnceNotTwice(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, protected: ['customer_number', 'email'], identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field];
        });

        $cmd = $this->update($id, ['email' => 'info@motorradgarage-dachau.de']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('erdoesi@example.com', $cmd->getPayload()['email']);
        self::assertSame([[GuardLogEntry::ACTION_BLOCKED_UPDATE, 'email']], $logged, 'the 001 block list wins; no second identity entry');
    }

    public function testIndependentModesNumberLogOnlyIdentityEnforce(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false, identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = [$e->action, $e->field, $e->mode];
        });

        $cmd = $this->update($id, ['customer_number' => '10009', 'email' => 'info@motorradgarage-dachau.de']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('10009', $cmd->getPayload()['customer_number'], 'number guard log_only: applied');
        self::assertSame('erdoesi@example.com', $cmd->getPayload()['email'], 'identity guard enforce: kept');
        self::assertSame([
            [GuardLogEntry::ACTION_BLOCKED_UPDATE, 'customer_number', 'log_only'],
            [GuardLogEntry::ACTION_BLOCKED_IDENTITY, 'email', 'enforce'],
        ], $logged);
    }

    public function testIdentityGuardIgnoresInserts(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false, enabled: false, identity: $this->identity(enforce: true)));
        $this->connectorDetected();
        $this->numberRange->expects(self::never())->method('getValue');
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->insert($id, ['customer_number' => '51520', 'email' => 'new@example.com']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('new@example.com', $cmd->getPayload()['email']);
    }

    public function testBothGuardsDisabledSkipDetectionEntirely(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, enabled: false, identity: $this->identity(enforce: true, enabled: false)));
        $this->detector->expects(self::never())->method('resolve');
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->update(Uuid::randomHex(), ['email' => 'x@example.com']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('x@example.com', $cmd->getPayload()['email']);
    }
```
Also update the existing `testDisabledPluginDoesNothing` expectation: it constructs `config(enforce: true, enabled: false)` whose identity guard is disabled by default, so it still passes unchanged — verify, do not edit.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Subscriber/CustomerNumberWriteProtectionTest.php`
Expected: the new identity tests fail (email not reverted / nothing logged); the existing 19 pass.

- [ ] **Step 3: Implement the identity step**

In `src/Subscriber/CustomerNumberWriteProtection.php`:

1. Add `use Revinners\ShopwareJtlConnectorGuardPlugin\Service\IdentityGuardConfig;`.
2. Replace the class doc-comment's first line with: `Makes Shopware the owner of a customer's number (feature 001) and identity — email and name (feature 002) — against the JTL-Connector.` and add a bullet: ` *  - UpdateCommand, identity guard: an email swap (case-insensitive, trimmed inequality) and, per policy, a name change are reverted the same way; unprotected name-only changes are logged as observed and applied.`
3. Add constants after `FIELD_CUSTOMER_NUMBER`:
```php
    private const FIELD_EMAIL = 'email';

    private const NAME_FIELDS = ['first_name', 'last_name'];
```
4. In `guard()`, replace the enabled check:
```php
        $globalConfig = $this->configProvider->load(null);
        if (!$globalConfig->enabled && !$globalConfig->identity->enabled) {
            return;
        }
```
5. Replace `guardUpdate()` entirely:
```php
    private function guardUpdate(UpdateCommand $command, string $idHex, ?CustomerState $state, ConnectorSource $connector): void
    {
        if ($state === null) {
            return; // row vanished between extraction and event; nothing to protect
        }

        $config = $this->configProvider->load($state->getSalesChannelId());

        // Fields the 001 block list already handled (whether or not they changed) are not
        // re-examined by the identity guard, so a field is never logged twice.
        $handled = [];
        if ($config->enabled) {
            $handled = $this->guardProtectedFields($command, $idHex, $state, $config, $connector);
        }

        if ($config->identity->enabled) {
            $this->guardIdentity($command, $idHex, $state, $config, $connector, $handled);
        }
    }

    /**
     * Feature 001: the configurable block list (always containing customer_number).
     *
     * @return list<string> the protected fields present in this write
     */
    private function guardProtectedFields(UpdateCommand $command, string $idHex, CustomerState $state, GuardConfig $config, ConnectorSource $connector): array
    {
        $payload = $command->getPayload();
        $present = [];
        foreach ($config->protectedFields as $field) {
            if (!$command->hasField($field)) {
                continue;
            }
            $present[] = $field;

            $attempted = $payload[$field];
            $current = $state->get($field);
            if ($this->same($attempted, $current)) {
                continue;
            }

            if ($config->enforce) {
                $command->addPayload($field, $current);
            }

            $this->guardLogger->log($this->entry(
                GuardLogEntry::ACTION_BLOCKED_UPDATE,
                $config->mode(),
                $field,
                $idHex,
                $state,
                $connector,
                $this->renderValue($field, $current),
                $this->renderValue($field, $attempted),
            ));
        }

        return $present;
    }

    /**
     * Feature 002: email is the hard identity key; a name change is guarded only per policy.
     *
     * @param list<string> $handled fields already processed by the 001 block list
     */
    private function guardIdentity(UpdateCommand $command, string $idHex, CustomerState $state, GuardConfig $config, ConnectorSource $connector, array $handled): void
    {
        $identity = $config->identity;
        $payload = $command->getPayload();

        $emailSwapped = $command->hasField(self::FIELD_EMAIL)
            && !\in_array(self::FIELD_EMAIL, $handled, true)
            && !$this->sameEmail($payload[self::FIELD_EMAIL], $state->getEmail());

        $changedNames = [];
        foreach (self::NAME_FIELDS as $field) {
            if ($command->hasField($field) && !\in_array($field, $handled, true) && !$this->same($payload[$field], $state->get($field))) {
                $changedNames[] = $field;
            }
        }

        if ($emailSwapped) {
            $this->guardIdentityField($command, self::FIELD_EMAIL, true, $idHex, $state, $identity, $connector);
        }

        if ($identity->protectName === IdentityGuardConfig::PROTECT_NAME_OFF) {
            return;
        }
        $protectNames = $identity->protectName === IdentityGuardConfig::PROTECT_NAME_ALWAYS || $emailSwapped;
        foreach ($changedNames as $field) {
            $this->guardIdentityField($command, $field, $protectNames, $idHex, $state, $identity, $connector);
        }
    }

    private function guardIdentityField(UpdateCommand $command, string $field, bool $protect, string $idHex, CustomerState $state, IdentityGuardConfig $identity, ConnectorSource $connector): void
    {
        $attempted = $command->getPayload()[$field];
        $current = $state->get($field);

        $kept = $protect && $identity->enforce;
        if ($kept) {
            $command->addPayload($field, $current);
        }

        $this->guardLogger->log($this->entry(
            $kept ? GuardLogEntry::ACTION_BLOCKED_IDENTITY : GuardLogEntry::ACTION_OBSERVED_IDENTITY,
            $identity->mode(),
            $field,
            $idHex,
            $state,
            $connector,
            $this->renderValue($field, $current),
            $this->renderValue($field, $attempted),
        ));
    }

    private function entry(string $action, string $mode, string $field, string $idHex, CustomerState $state, ConnectorSource $connector, ?string $current, ?string $attempted): GuardLogEntry
    {
        return new GuardLogEntry(
            action: $action,
            mode: $mode,
            field: $field,
            customerId: $idHex,
            email: $state->getEmail(),
            firstName: $state->getFirstName(),
            lastName: $state->getLastName(),
            currentValue: $current,
            attemptedValue: $attempted,
            assignedValue: null,
            integrationId: $connector->integrationId,
            integrationLabel: $connector->label,
            salesChannelId: $state->getSalesChannelId(),
        );
    }

    /**
     * Email identity comparison: case-insensitive and trimmed (spec 002, technical notes).
     */
    private function sameEmail(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }
```
`guardInsert()` stays as it is (it already checks `$config->enabled`, the number guard's own switch).

- [ ] **Step 4: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: green (62 + 13 = 75 tests), pristine. Every pre-existing test unchanged.

- [ ] **Step 5: Commit**

```bash
git add src/Subscriber/CustomerNumberWriteProtection.php tests/Unit/Subscriber/CustomerNumberWriteProtectionTest.php
git commit -m "feat(002): guard customer identity (email, name) against connector updates

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_012JytMPkTC5r69aAKyP7tmB"
```

---

### Task 4: Docs, version 1.1.0, push the branch

**Files:**
- Modify: `README.md`, `CHANGELOG.md`, `composer.json`, `specs/feat/002-customer-identity-write-protection/SPEC.md` (status line)

- [ ] **Step 1: README**

After the "Feature 001" section's `### Implementation notes` block and before `## Development`, insert:
```markdown
## Feature 002 — customer identity write protection

The same connector push also replaces a customer's **identity**: `email` and `first_name`/`last_name`
are overwritten with a different person's data while the address entity stays untouched (see
`specs/feat/002-customer-identity-write-protection/SPEC.md` for the evidence; 38 confirmed cases on
yam-shop.de). Feature 002 extends the guard, with its own switches, independent of the number guard:

- **Email** (the hard identity key): a connector update that would change an existing customer's
  email to a *different* address (case-insensitive, trimmed) is kept in `enforce`, observed in `log_only`.
- **Name**: per `identityGuardProtectName` — `on_email_swap` (default) keeps the name only when the
  same write also swaps the email and otherwise logs the name change as observed and applies it;
  `always` guards the name like the email; `off` ignores name changes entirely.
- Everything else in the write (group, addresses, …) is applied. Connector-created customers are
  not affected. Admin, storefront, CLI and other-integration writes are never touched.
- Audit actions: `blocked_identity` (value kept) and `observed_identity` (value applied — either
  `log_only`, or an unprotected name-only change); `field` is `email`, `first_name` or `last_name`.

| Key | Default | Meaning |
|---|---|---|
| `identityGuardEnabled` | `true` | master switch of feature 002 |
| `identityGuardMode` | `log_only` | `log_only` = observe; `enforce` = keep the current email / name |
| `identityGuardProtectName` | `on_email_swap` | `on_email_swap` / `always` / `off` (see above) |

Rollout mirrors feature 001: deploy in `log_only`, watch `revinners_jtl_guard_log` for
`observed_identity` rows (`SELECT field, current_value, attempted_value, email FROM revinners_jtl_guard_log WHERE action LIKE '%identity' ORDER BY created_at DESC`),
then switch `identityGuardMode` to `enforce`. Repairing already-swapped accounts is a separate data
job (spec 002, "Out of scope").
```
In the Configuration table of feature 001 nothing changes. Add to the top of the README, under the title paragraph, one sentence listing both features.

- [ ] **Step 2: CHANGELOG, version, spec status**

Prepend to `CHANGELOG.md` after `# Changelog`:
```markdown
## 1.1.0 — 2026-09-07

- Feature 002: customer identity write protection — a connector update can no longer replace an
  existing customer's `email` (and `first_name`/`last_name` when paired with the email swap, or
  always/never per `identityGuardProtectName`). Own switches `identityGuardEnabled` /
  `identityGuardMode` (ships `log_only`), independent of the number guard.
- New audit actions `blocked_identity` and `observed_identity` on the same table and channel.
- No migration.
```
`composer.json`: `"version": "1.1.0"`. SPEC.md status line → `> Status: **IMPLEMENTED — see PLAN.md; released as 1.1.0 (2026-09-07). Implemented before 001 was observed on prod at the user's request; ships log_only.**`

- [ ] **Step 3: Test, commit, push**

```bash
vendor/bin/phpunit
git add README.md CHANGELOG.md composer.json specs/feat/002-customer-identity-write-protection/SPEC.md
git commit -m "docs(002): README section, changelog 1.1.0, spec status

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_012JytMPkTC5r69aAKyP7tmB"
git push -u origin feat/002-customer-identity-write-protection
```

---

### Task 5: Live proof in the local yam-shop Docker shop (spec 002 test plan)

Same environment and cleanup discipline as 001's Task 8 (environment facts: compose file `docker-compose.yam-shop.dev.yml` in `/Users/macbookpro/Soft/yam-shop`; web container `yam-shop` has no `mysql` client — use `docker compose -f docker-compose.yam-shop.dev.yml exec -T db mysql -u root -pdocker barthel_sw`; Admin API at `http://localhost/api` inside the web container; `APP_ENV=prod` so the channel file is `var/log/jtl_connector_guard_prod.log`; the DB is a prod dump with the real integration `JTL Connector`, id `019b8946ccc67767b9fb8cb524300ba1`; storefront sales channel `019b02dbf154717c8d127b5df75c3b7d`; copy the plugin with `rsync -a --delete --exclude vendor --exclude .git --exclude .superpowers --exclude .phpunit.cache --exclude specs` into `src/custom/plugins/ShopwareJtlConnectorGuardPlugin/`, then `plugin:refresh`, `plugin:install --activate`, `cache:clear`; create a test integration labelled `JTL Connector` with access key `SWIAGUARDTEST` and a bcrypt secret; nothing is committed to the shop repo; restore the customer and delete everything created at the end; `git -C /Users/macbookpro/Soft/yam-shop status --short` must be empty).

- [ ] **Step 1:** install the branch build; set `ShopwareJtlConnectorGuardPlugin.config.mode enforce` (001 stays enforce) and leave `identityGuardMode` at its `log_only` default.
- [ ] **Step 2 (log_only):** PATCH an existing customer through the `JTL Connector` test integration with `{"email":"guard-swap@example.com"}` → HTTP 204, the email **changes**, one `observed_identity / log_only / email` row with `current_value` = old email, `attempted_value` = `guard-swap@example.com`, and the channel line says "applied".
- [ ] **Step 3 (enforce email):** `system:config:set ShopwareJtlConnectorGuardPlugin.config.identityGuardMode enforce` + `cache:clear`; restore the customer's email by SQL first; PATCH `{"email":"guard-swap2@example.com","title":"GuardTitle"}` → 204, email **unchanged**, `title` applied, one `blocked_identity / enforce / email` row.
- [ ] **Step 4 (email + name):** PATCH `{"email":"guard-swap3@example.com","firstName":"Christopher","lastName":"Kühnel"}` → 204, all three unchanged, three `blocked_identity` rows (`email`, `first_name`, `last_name`).
- [ ] **Step 5 (name only, default policy):** PATCH `{"lastName":"GuardObserved"}` → 204, last name **changes**, one `observed_identity / enforce / last_name` row.
- [ ] **Step 6 (regression):** the same email PATCH through a second integration labelled `Other API client` → email changes, no new row (debug line only); through an admin user (`bin/console user:create --admin --password=GuardTest123! guardtest`, password grant `client_id=administration`) → email changes, no new row.
- [ ] **Step 7 (sync path):** `POST /api/_action/sync` with one `upsert` on `customer` containing two customers whose emails are swapped → both kept, two `blocked_identity` rows.
- [ ] **Step 8:** cleanup exactly as in 001 (uninstall drops the table, delete integrations/user, restore the customer's email/name/title, delete `system_config` rows `LIKE 'ShopwareJtlConnectorGuardPlugin.%'`, remove the plugin copy, `cache:clear`, shop `git status` empty). Append a verification line to `CHANGELOG.md` under 1.1.0 and commit + push in the plugin repo.

If a step fails because of plugin code: fix it in the plugin with a reproducing unit test, commit, re-rsync, repeat from that step.

---

### Task 6: Release 1.1.0 and bump yam-shop

- [ ] **Step 1:** plugin repo: `git checkout master && git merge --ff-only feat/002-customer-identity-write-protection && git push origin master && git tag -a 1.1.0 -m "1.1.0 - customer identity write protection (002)" && git push origin 1.1.0`; verify with `git ls-remote --tags origin`.
- [ ] **Step 2:** shop: from `/Users/macbookpro/Soft/yam-shop/src` run `composer update revinners/shopware6-jtl-connector-guard --no-scripts --no-install --no-interaction`; `git diff src/composer.lock | grep -E '^[-+] +"version"'` must show only `1.0.1 → 1.1.0`; commit `chore: bump revinners/shopware6-jtl-connector-guard to 1.1.0 (identity guard, log_only)` with the trailers. Do not push the shop.
- [ ] **Step 3:** ecosystem docs: in `~/.claude/skills/shopware-ecosystem-architect/repositories.md` and `sw_plugins.md`, extend the plugin's row text to "features 001 customer_number + 002 identity (email/name)".

---

## Self-review

- **Spec coverage:** R1 → Task 3 `guardIdentity` email branch + tests (enforce keeps, log_only observes, other fields applied). R2 → `protectName` policy + tests (email+name kept; name-only observed/applied; `always`; `off`). R3 → Task 1 config (three keys, defaults, independence tests) + Task 3 gating (`guard()` and `guardUpdate()` independent switches; insert path untouched). R4 → Task 2 actions/messages + Task 3 entries (customer id, field, current/attempted, action, mode, source). R5 → detection reused unchanged; per-command try/catch and outer catch unchanged; no address fields touched; no migration. Test plan 1-6 → Task 5. Open questions → measurement via `log_only`, not code.
- **Placeholder scan:** none.
- **Type consistency:** `IdentityGuardConfig(enabled, enforce, protectName)` used identically in Tasks 1 and 3; `GuardConfig` 6th param name `identity` used as `$config->identity` in Task 3 and tests; `GuardLogEntry::ACTION_BLOCKED_IDENTITY/ACTION_OBSERVED_IDENTITY` (Task 2) used in Task 3 tests and code; `entry()` helper signature `(action, mode, field, idHex, state, connector, current, attempted)` consistent between the two callers; `GuardConfigProvider::load()` key count 8 matches the memo tests.
