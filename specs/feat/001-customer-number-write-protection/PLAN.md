# 001 - Customer Number Write Protection — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the first version of the `ShopwareJtlConnectorGuardPlugin` Shopware 6.6 plugin, which stops the JTL-Connector from overwriting `customer.customer_number` on yam-shop.de / ducati-world24.com while logging every intervention to a Monolog channel and a queryable DB table.

**Architecture:** One `kernel.event_subscriber` on `Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent` (dispatched by `Dbal\EntityWriteGateway::execute()` *before* the transaction, with the very same `WriteCommand` objects that are executed afterwards). The subscriber identifies connector writes through the `AdminApiSource` integration id, compares the incoming `customer_number` against the current DB row, and neutralises the change with `WriteCommand::addPayload()` (the only mutation API on a command — a key cannot be removed, so for updates the current value is written back and for inserts a freshly reserved number-range value replaces the supplied one). Four small services (config provider, source detector, customer state loader, guard logger) keep the subscriber thin and unit-testable with PHPUnit mocks; a plugin-owned entity `revinners_jtl_guard_log` persists the audit trail.

**Tech Stack:** PHP 8.2+, Shopware 6.6.10.x (`shopware/core ~6.6.10`), Doctrine DBAL (`ArrayParameterType::BINARY`), Symfony DI XML, Monolog (channel via `prependExtensionConfig` in the bundle `build()`), PHPUnit 11 unit tests with mocks + `Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry`.

**Spec:** `specs/feat/001-customer-number-write-protection/SPEC.md` (same folder as this plan).

## Global Constraints

- Composer package name: `revinners/shopware6-jtl-connector-guard`; PHP namespace `Revinners\ShopwareJtlConnectorGuardPlugin`; bundle class `ShopwareJtlConnectorGuardPlugin`; first subscriber class `CustomerNumberWriteProtection` (spec, "Repo/plugin scaffolding").
- `shopware/core` constraint `~6.6.10` (both target shops run 6.6.10.x; yam-shop.de is pinned to 6.6.10.18). `php >= 8.2`.
- Fail safe: the subscriber must never throw into the DAL write and must never touch a write whose source is not positively identified as the connector (spec R3, R6). Any internal exception is caught and logged; the write proceeds untouched.
- Only the `customer_number` field (plus optional extra protected columns from config) is altered; every other field in the same write goes through unchanged (spec R1, R6).
- Ship with `mode = log_only` as the config default (spec R5).
- Config keys live under `ShopwareJtlConnectorGuardPlugin.config.*`: `enabled`, `mode`, `integrationLabels`, `integrationIds`, `protectedFields`.
- DB table name `revinners_jtl_guard_log`; entity name `revinners_jtl_guard_log`; Monolog channel `jtl_connector_guard`; log actions exactly `blocked_update` and `remapped_create`.
- Plugin repo: `/Users/macbookpro/Soft/ShopwareJtlConnectorGuardPlugin` (remote `https://github.com/revinners/ShopwareJtlConnectorGuardPlugin.git`), work on the existing branch `feat/001-customer-number-write-protection` (it has no commits yet). Consumer shop checkout: `/Users/macbookpro/Soft/yam-shop` (composer project lives in `src/`, DE shop, dev compose file `docker-compose.yam-shop.dev.yml`, service/container `yam-shop`, DB `barthel_sw`).
- Every git commit message must end with these two trailer lines:
  ```
  Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_012JytMPkTC5r69aAKyP7tmB
  ```
- Style: `declare(strict_types=1)`, `final` classes, constructor promotion, readonly value objects, PSR-4 `src/` ↔ `tests/` mirrored (`Revinners\ShopwareJtlConnectorGuardPlugin\Tests\`). No Shopware admin UI code (config.xml only). Comments in English.
- Tests run from the plugin root with `vendor/bin/phpunit` after `composer install` inside the plugin (the plugin declares `shopware/core` as a dependency, like `revinners/vin-garage`). Never run `composer update` on the shop unless a task says so.

---

## Design decisions resolved from the spec's open questions

| # | Question | Decision |
|---|---|---|
| 1 | DAL hook that allows mutating one field | `EntityWriteEvent` (BeforeWrite). Verified in `vendor/shopware/core/Framework/DataAbstractionLayer/Dbal/EntityWriteGateway.php:102-121` on 6.6.10.18: the event carries the exact command instances that are executed; `WriteCommand::addPayload(string $key, mixed $value)` (`Write/Command/WriteCommand.php:64`) overwrites a payload key; payload keys are **storage** column names (`customer_number`, `sales_channel_id` as 16-byte binary). Change sets are generated *after* the event, so the current value must be loaded by the plugin itself. `addPayload` is `@internal`; accepted and documented in the README. No fallback re-write needed. |
| 2 | R2 policy | **Always** assign a fresh number-range value for connector-created customers in `enforce` mode (spec's own leaning: Shopware owns the number). No collision lookup needed; the attempted value is logged. |
| 3 | Log sink | **Both**: Monolog channel `jtl_connector_guard` (own file `var/log/jtl_connector_guard_<env>.log`, plus it propagates to the main log) **and** DB table `revinners_jtl_guard_log` exposed as a DAL entity (queryable via Admin API `/api/search/revinners-jtl-guard-log` and SQL). |
| 4 | Wawi field confirmation | Not needed for the code path; the guard works on whatever value the push carries. |
| 5 | Multi-shop config | One plugin; plugin config is per-sales-channel capable (Shopware system config inheritance). Connector identity is matched by integration **label** (default `JTL-Connector`, which is the label on both shops) with an optional explicit id list. |
| R5 allow-list | Implemented as the inverse, a **block list** `protectedFields` (default and always containing `customer_number`). Same semantics as "everything except customer_number", simpler to reason about. Extra columns are compared as strings; documented as scalar-columns-only. |

## File structure

```
ShopwareJtlConnectorGuardPlugin/
├── composer.json
├── phpunit.xml
├── .gitignore
├── README.md
├── CHANGELOG.md
├── specs/feat/001-customer-number-write-protection/{SPEC.md,PLAN.md}
├── src/
│   ├── ShopwareJtlConnectorGuardPlugin.php            bundle: Monolog channel + uninstall cleanup
│   ├── Resources/config/services.xml                  DI wiring
│   ├── Resources/config/config.xml                    plugin config (enabled, mode, integration ids/labels, protected fields)
│   ├── Migration/Migration1788739200CreateJtlGuardLog.php
│   ├── Core/Content/GuardLog/GuardLogDefinition.php   DAL entity for the audit table
│   ├── Core/Content/GuardLog/GuardLogEntity.php
│   ├── Core/Content/GuardLog/GuardLogCollection.php
│   ├── Service/GuardConfig.php                        readonly VO: enabled, enforce, integrationLabels, integrationIds, protectedFields
│   ├── Service/GuardConfigProvider.php                SystemConfigService → GuardConfig (per sales channel, memoised)
│   ├── Service/ConnectorSource.php                    readonly VO: integrationId (hex), label
│   ├── Service/ConnectorSourceDetector.php            Context → ?ConnectorSource (AdminApiSource + id/label match)
│   ├── Service/CustomerState.php                      readonly VO around one current `customer` row
│   ├── Service/CustomerStateLoader.php                SELECT * FROM customer WHERE id IN (...)
│   ├── Service/GuardLogEntry.php                      readonly VO for one intervention
│   ├── Service/GuardLogger.php                        Monolog + DB insert (never throws)
│   └── Subscriber/CustomerNumberWriteProtection.php   the EntityWriteEvent subscriber
└── tests/
    ├── bootstrap.php
    └── Unit/
        ├── ShopwareJtlConnectorGuardPluginTest.php
        ├── Service/GuardConfigProviderTest.php
        ├── Service/ConnectorSourceDetectorTest.php
        ├── Service/CustomerStateLoaderTest.php
        ├── Service/GuardLoggerTest.php
        ├── Subscriber/CustomerNumberWriteProtectionTest.php
        └── Subscriber/CustomerTestDefinition.php      minimal `customer` EntityDefinition stub for building WriteCommands
```

---

### Task 1: Plugin skeleton, composer, Monolog channel, test toolchain

**Files:**
- Create: `composer.json`, `phpunit.xml`, `.gitignore`, `tests/bootstrap.php`
- Create: `src/ShopwareJtlConnectorGuardPlugin.php`
- Create: `src/Resources/config/services.xml` (empty container for now)
- Create: `src/Resources/config/config.xml`
- Test: `tests/Unit/ShopwareJtlConnectorGuardPluginTest.php`

**Interfaces:**
- Produces: bundle class `Revinners\ShopwareJtlConnectorGuardPlugin\ShopwareJtlConnectorGuardPlugin extends Shopware\Core\Framework\Plugin`, constant `ShopwareJtlConnectorGuardPlugin::LOG_CHANNEL = 'jtl_connector_guard'`; DI service id `monolog.logger.jtl_connector_guard` (created by MonologBundle from the prepended channel config); config keys `ShopwareJtlConnectorGuardPlugin.config.{enabled,mode,integrationLabels,integrationIds,protectedFields}`.

- [ ] **Step 1: Write `composer.json`**

```json
{
  "name": "revinners/shopware6-jtl-connector-guard",
  "description": "Guards Shopware data against unwanted overwrites by the JTL-Connector (JTL-Wawi). First feature: customer number write protection.",
  "version": "1.0.0",
  "type": "shopware-platform-plugin",
  "license": "proprietary",
  "authors": [
    {
      "name": "Revinners",
      "role": "Manufacturer"
    }
  ],
  "require": {
    "php": ">=8.2",
    "shopware/core": "~6.6.10"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.3"
  },
  "autoload": {
    "psr-4": {
      "Revinners\\ShopwareJtlConnectorGuardPlugin\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Revinners\\ShopwareJtlConnectorGuardPlugin\\Tests\\": "tests/"
    }
  },
  "extra": {
    "shopware-plugin-class": "Revinners\\ShopwareJtlConnectorGuardPlugin\\ShopwareJtlConnectorGuardPlugin",
    "label": {
      "de-DE": "JTL-Connector Guard",
      "en-GB": "JTL-Connector Guard",
      "pl-PL": "JTL-Connector Guard"
    },
    "description": {
      "de-DE": "Schützt Shopware-Daten (Kundennummern) vor Überschreiben durch den JTL-Connector.",
      "en-GB": "Protects Shopware data (customer numbers) from being overwritten by the JTL-Connector.",
      "pl-PL": "Chroni dane Shopware (numery klientów) przed nadpisaniem przez JTL-Connector."
    }
  },
  "config": {
    "allow-plugins": {
      "symfony/runtime": true
    }
  }
}
```

- [ ] **Step 2: Write `phpunit.xml`, `tests/bootstrap.php`, `.gitignore`**

`phpunit.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.3/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         failOnWarning="true"
         executionOrder="random">
    <php>
        <ini name="error_reporting" value="-1"/>
    </php>
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </source>
</phpunit>
```

`tests/bootstrap.php`:
```php
<?php

declare(strict_types=1);

/*
 * Unit tests only need the class loader: the plugin declares shopware/core as a
 * dependency, so `composer install` inside the plugin directory provides every
 * Shopware class the code touches. No kernel, no database.
 */
require __DIR__ . '/../vendor/autoload.php';
```

`.gitignore`:
```
/vendor/
/composer.lock
/.phpunit.cache/
/.phpunit.result.cache
/.idea/
.DS_Store
```

- [ ] **Step 3: Write the bundle class**

`src/ShopwareJtlConnectorGuardPlugin.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Container plugin for every fix we apply on top of the JTL-Connector (JTL-Wawi -> Shopware).
 * Feature 001: customer number write protection (see specs/feat/001-customer-number-write-protection).
 */
class ShopwareJtlConnectorGuardPlugin extends Plugin
{
    public const LOG_CHANNEL = 'jtl_connector_guard';

    public const LOG_TABLE = 'revinners_jtl_guard_log';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Registering the channel + handler in build() works regardless of whether
        // Shopware picks up Resources/config/packages/*.yaml for this bundle
        // (same approach as revinners/shopware-revinners-search-advance).
        $container->prependExtensionConfig('monolog', self::monologConfig());
    }

    /**
     * Monolog configuration prepended in build(): own channel + own log file.
     *
     * @return array{channels: list<string>, handlers: array<string, array<string, mixed>>}
     */
    public static function monologConfig(): array
    {
        return [
            'channels' => [self::LOG_CHANNEL],
            'handlers' => [
                self::LOG_CHANNEL => [
                    'type' => 'stream',
                    'path' => '%kernel.logs_dir%/' . self::LOG_CHANNEL . '_%kernel.environment%.log',
                    'level' => 'debug',
                    'channels' => [self::LOG_CHANNEL],
                ],
            ],
        ];
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `' . self::LOG_TABLE . '`');
    }
}
```

- [ ] **Step 4: Write the (still empty) `services.xml` and the full `config.xml`**

`src/Resources/config/services.xml`:
```xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
    </services>
</container>
```

`src/Resources/config/config.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/platform/trunk/src/Core/System/SystemConfig/Schema/config.xsd">

    <card>
        <title>JTL-Connector Guard — customer number protection</title>
        <title lang="de-DE">JTL-Connector Guard — Kundennummern-Schutz</title>
        <title lang="pl-PL">JTL-Connector Guard — ochrona numerów klientów</title>

        <input-field type="bool">
            <name>enabled</name>
            <label>Enabled</label>
            <label lang="de-DE">Aktiv</label>
            <label lang="pl-PL">Włączone</label>
            <helpText>Master switch. When off, the plugin does nothing at all.</helpText>
            <helpText lang="de-DE">Hauptschalter. Wenn aus, tut das Plugin gar nichts.</helpText>
            <helpText lang="pl-PL">Główny przełącznik. Wyłączony = plugin nic nie robi.</helpText>
            <defaultValue>true</defaultValue>
        </input-field>

        <input-field type="single-select">
            <name>mode</name>
            <label>Mode</label>
            <label lang="de-DE">Modus</label>
            <label lang="pl-PL">Tryb</label>
            <helpText>"Log only" records every attempt but changes nothing (use first on production to confirm detection). "Enforce" blocks connector changes to the customer number and assigns a number from the shop's own range to connector-created customers.</helpText>
            <helpText lang="de-DE">"Nur protokollieren" zeichnet jeden Versuch auf, ändert aber nichts (zuerst in Produktion verwenden). "Durchsetzen" blockiert Kundennummer-Änderungen des Connectors und vergibt für vom Connector angelegte Kunden eine Nummer aus dem Nummernkreis des Shops.</helpText>
            <helpText lang="pl-PL">"Tylko loguj" zapisuje każdą próbę, nic nie zmienia (użyj najpierw na produkcji). "Wymuszaj" blokuje zmiany numeru klienta przez connector i nadaje klientom tworzonym przez connector numer z własnego zakresu sklepu.</helpText>
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
            <name>integrationLabels</name>
            <label>Connector integration label(s)</label>
            <label lang="de-DE">Integrations-Bezeichnung(en) des Connectors</label>
            <label lang="pl-PL">Nazwa/nazwy integracji connectora</label>
            <helpText>Comma-separated labels of the Admin API integrations (Settings > System > Integrations) used by the JTL-Connector. Matched case-insensitively. Writes from any other integration, admin user or the storefront are never touched.</helpText>
            <helpText lang="de-DE">Kommagetrennte Bezeichnungen der Admin-API-Integrationen (Einstellungen > System > Integrationen), die der JTL-Connector verwendet. Groß-/Kleinschreibung egal. Schreibvorgänge anderer Integrationen, Admin-Benutzer oder der Storefront werden nie angefasst.</helpText>
            <helpText lang="pl-PL">Nazwy (po przecinku) integracji Admin API (Ustawienia > System > Integracje) używanych przez JTL-Connector. Wielkość liter bez znaczenia. Zapisy innych integracji, użytkowników admina i storefrontu nigdy nie są modyfikowane.</helpText>
            <placeholder>JTL-Connector</placeholder>
            <defaultValue>JTL-Connector</defaultValue>
        </input-field>

        <input-field type="text">
            <name>integrationIds</name>
            <label>Connector integration id(s) — optional</label>
            <label lang="de-DE">Integrations-ID(s) des Connectors — optional</label>
            <label lang="pl-PL">ID integracji connectora — opcjonalnie</label>
            <helpText>Comma-separated 32-char hex ids of the integrations, if you prefer matching by id instead of (or in addition to) the label.</helpText>
            <helpText lang="de-DE">Kommagetrennte 32-stellige Hex-IDs der Integrationen, falls statt (oder zusätzlich zur) Bezeichnung per ID zugeordnet werden soll.</helpText>
            <helpText lang="pl-PL">32-znakowe identyfikatory hex integracji (po przecinku), jeśli wolisz dopasowanie po id zamiast (lub oprócz) nazwy.</helpText>
        </input-field>

        <input-field type="text">
            <name>protectedFields</name>
            <label>Protected customer columns</label>
            <label lang="de-DE">Geschützte Kundenspalten</label>
            <label lang="pl-PL">Chronione kolumny klienta</label>
            <helpText>Comma-separated storage column names of the customer table the connector may NOT change on existing customers. "customer_number" is always protected, even if removed here. Scalar columns only.</helpText>
            <helpText lang="de-DE">Kommagetrennte Spaltennamen der Kundentabelle, die der Connector bei bestehenden Kunden NICHT ändern darf. "customer_number" ist immer geschützt. Nur skalare Spalten.</helpText>
            <helpText lang="pl-PL">Nazwy kolumn tabeli klienta (po przecinku), których connector NIE może zmieniać u istniejących klientów. "customer_number" jest chronione zawsze. Tylko kolumny skalarne.</helpText>
            <placeholder>customer_number</placeholder>
            <defaultValue>customer_number</defaultValue>
        </input-field>
    </card>
</config>
```

- [ ] **Step 5: Write the smoke test**

`tests/Unit/ShopwareJtlConnectorGuardPluginTest.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\ShopwareJtlConnectorGuardPlugin;
use Shopware\Core\Framework\Plugin;

final class ShopwareJtlConnectorGuardPluginTest extends TestCase
{
    public function testIsAShopwarePlugin(): void
    {
        self::assertTrue(is_subclass_of(ShopwareJtlConnectorGuardPlugin::class, Plugin::class));
    }

    public function testMonologConfigDeclaresChannelAndDedicatedHandler(): void
    {
        // Bundle::build() needs a full kernel container (kernel.environment, filesystem params),
        // so the prepended config is exposed through a static method and tested directly.
        $config = ShopwareJtlConnectorGuardPlugin::monologConfig();

        self::assertSame(['jtl_connector_guard'], $config['channels']);
        self::assertArrayHasKey('jtl_connector_guard', $config['handlers']);
        self::assertSame('stream', $config['handlers']['jtl_connector_guard']['type']);
        self::assertSame(['jtl_connector_guard'], $config['handlers']['jtl_connector_guard']['channels']);
        self::assertStringContainsString('jtl_connector_guard', $config['handlers']['jtl_connector_guard']['path']);
    }

    public function testConfigXmlDeclaresAllKeys(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../../src/Resources/config/config.xml');

        foreach (['enabled', 'mode', 'integrationLabels', 'integrationIds', 'protectedFields'] as $key) {
            self::assertStringContainsString('<name>' . $key . '</name>', $xml);
        }
        self::assertStringContainsString('<defaultValue>log_only</defaultValue>', $xml);
    }
}
```

- [ ] **Step 6: Install dependencies and run the test**

Run (from the plugin root):
```bash
cd /Users/macbookpro/Soft/ShopwareJtlConnectorGuardPlugin && composer install --no-interaction 2>&1 | tail -5 && vendor/bin/phpunit
```
Expected: `composer install` completes (it pulls `shopware/core` and its Symfony deps; several minutes the first time). PHPUnit: `OK (3 tests, ...)`.

- [ ] **Step 7: Commit**

```bash
cd /Users/macbookpro/Soft/ShopwareJtlConnectorGuardPlugin
git add composer.json phpunit.xml .gitignore tests/bootstrap.php src/ShopwareJtlConnectorGuardPlugin.php src/Resources/config/services.xml src/Resources/config/config.xml tests/Unit/ShopwareJtlConnectorGuardPluginTest.php specs/
git commit -m "feat: plugin skeleton with Monolog channel, config schema and test toolchain

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_012JytMPkTC5r69aAKyP7tmB"
```

---

### Task 2: GuardConfig value object and GuardConfigProvider

**Files:**
- Create: `src/Service/GuardConfig.php`, `src/Service/GuardConfigProvider.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Unit/Service/GuardConfigProviderTest.php`

**Interfaces:**
- Consumes: `Shopware\Core\System\SystemConfig\SystemConfigService::get(string $key, ?string $salesChannelId = null): mixed`.
- Produces:
  - `final readonly class GuardConfig { public function __construct(public bool $enabled, public bool $enforce, /** @var list<string> */ public array $integrationLabels, /** @var list<string> lowercase hex */ public array $integrationIds, /** @var list<string> */ public array $protectedFields) }`
  - `final class GuardConfigProvider { public function __construct(SystemConfigService $systemConfig); public function load(?string $salesChannelId = null): GuardConfig; public function reset(): void }`
  - constants `GuardConfigProvider::MODE_LOG_ONLY = 'log_only'`, `GuardConfigProvider::MODE_ENFORCE = 'enforce'`, `GuardConfigProvider::FIELD_CUSTOMER_NUMBER = 'customer_number'`, `GuardConfigProvider::CONFIG_PREFIX = 'ShopwareJtlConnectorGuardPlugin.config.'`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Service/GuardConfigProviderTest.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class GuardConfigProviderTest extends TestCase
{
    private SystemConfigService&MockObject $systemConfig;

    protected function setUp(): void
    {
        $this->systemConfig = $this->createMock(SystemConfigService::class);
    }

    /**
     * @param array<string, mixed> $values keyed by short config key (without prefix)
     */
    private function providerWith(array $values): GuardConfigProvider
    {
        $this->systemConfig->method('get')->willReturnCallback(
            static function (string $key, ?string $salesChannelId = null) use ($values): mixed {
                $short = substr($key, \strlen(GuardConfigProvider::CONFIG_PREFIX));

                return $values[$short] ?? null;
            }
        );

        return new GuardConfigProvider($this->systemConfig);
    }

    public function testDefaultsWhenNothingIsConfigured(): void
    {
        $config = $this->providerWith([])->load();

        self::assertTrue($config->enabled);
        self::assertFalse($config->enforce, 'ships in log_only');
        self::assertSame(['JTL-Connector'], $config->integrationLabels);
        self::assertSame([], $config->integrationIds);
        self::assertSame(['customer_number'], $config->protectedFields);
    }

    public function testParsesConfiguredValues(): void
    {
        $config = $this->providerWith([
            'enabled' => false,
            'mode' => 'enforce',
            'integrationLabels' => ' JTL-Connector , Wawi Sync ,, ',
            'integrationIds' => "019DF771764772929F1136E52180CCF6,\n2103c0f8ba934cbdb291287aaa3b5ce8, not-a-uuid",
            'protectedFields' => 'customer_group_id, customer_number ,email',
        ])->load('sc-1');

        self::assertFalse($config->enabled);
        self::assertTrue($config->enforce);
        self::assertSame(['JTL-Connector', 'Wawi Sync'], $config->integrationLabels);
        self::assertSame(
            ['019df771764772929f1136e52180ccf6', '2103c0f8ba934cbdb291287aaa3b5ce8'],
            $config->integrationIds,
            'ids are lower-cased and invalid entries dropped'
        );
        self::assertSame(['customer_number', 'customer_group_id', 'email'], $config->protectedFields);
    }

    public function testCustomerNumberIsAlwaysProtected(): void
    {
        $config = $this->providerWith(['protectedFields' => 'email'])->load();

        self::assertSame(['customer_number', 'email'], $config->protectedFields);
    }

    public function testUnknownModeFallsBackToLogOnly(): void
    {
        $config = $this->providerWith(['mode' => 'yolo'])->load();

        self::assertFalse($config->enforce);
    }

    public function testLoadIsMemoisedPerSalesChannel(): void
    {
        $this->systemConfig->expects(self::exactly(10))->method('get')->willReturn(null); // 5 keys x 2 channels

        $provider = new GuardConfigProvider($this->systemConfig);
        $provider->load(null);
        $provider->load(null);
        $provider->load('sc-1');
        $provider->load('sc-1');
    }

    public function testResetClearsTheMemo(): void
    {
        $this->systemConfig->expects(self::exactly(10))->method('get')->willReturn(null);

        $provider = new GuardConfigProvider($this->systemConfig);
        $provider->load(null);
        $provider->reset();
        $provider->load(null);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Service/GuardConfigProviderTest.php`
Expected: errors with `Class "Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider" not found`.

- [ ] **Step 3: Write the value object and the provider**

`src/Service/GuardConfig.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

/**
 * Resolved plugin configuration for one sales channel (or the global fallback).
 */
final readonly class GuardConfig
{
    /**
     * @param list<string> $integrationLabels labels of the connector's Admin API integrations
     * @param list<string> $integrationIds    lowercase 32-char hex ids of the connector's integrations
     * @param list<string> $protectedFields   storage column names of `customer` the connector may not change; always contains customer_number
     */
    public function __construct(
        public bool $enabled,
        public bool $enforce,
        public array $integrationLabels,
        public array $integrationIds,
        public array $protectedFields,
    ) {
    }

    public function mode(): string
    {
        return $this->enforce ? GuardConfigProvider::MODE_ENFORCE : GuardConfigProvider::MODE_LOG_ONLY;
    }
}
```

`src/Service/GuardConfigProvider.php`:
```php
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
```

- [ ] **Step 4: Register the provider in `services.xml`**

Replace the empty `<services>` block:
```xml
    <services>
        <service id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider">
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService"/>
            <tag name="kernel.reset" method="reset"/>
        </service>
    </services>
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Service/GuardConfigProviderTest.php`
Expected: `OK (6 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add src/Service/GuardConfig.php src/Service/GuardConfigProvider.php src/Resources/config/services.xml tests/Unit/Service/GuardConfigProviderTest.php
git commit -m "feat: guard config value object and per-sales-channel provider

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_012JytMPkTC5r69aAKyP7tmB"
```

---

### Task 3: ConnectorSourceDetector — identify connector writes from the Context

**Files:**
- Create: `src/Service/ConnectorSource.php`, `src/Service/ConnectorSourceDetector.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Unit/Service/ConnectorSourceDetectorTest.php`

**Interfaces:**
- Consumes: `GuardConfig` (Task 2); `Shopware\Core\Framework\Api\Context\AdminApiSource::getUserId(): ?string`, `::getIntegrationId(): ?string`; `Doctrine\DBAL\Connection::fetchOne()`.
- Produces:
  - `final readonly class ConnectorSource { public function __construct(public string $integrationId /* lowercase hex */, public ?string $label) }`
  - `final class ConnectorSourceDetector { public function __construct(Connection $connection); public function resolve(Context $context, GuardConfig $config): ?ConnectorSource; public function reset(): void }`
  - Semantics: returns `null` unless the context source is an `AdminApiSource` **with** an integration id **and without** a user id, and that integration is listed in `$config->integrationIds` or its DB label (`integration.label`, not soft-deleted) matches one of `$config->integrationLabels` case-insensitively.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Service/ConnectorSourceDetectorTest.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSourceDetector;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

final class ConnectorSourceDetectorTest extends TestCase
{
    private const INTEGRATION_ID = '019df771764772929f1136e52180ccf6';

    private Connection&MockObject $connection;
    private ConnectorSourceDetector $detector;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->detector = new ConnectorSourceDetector($this->connection);
    }

    private function config(array $labels = ['JTL-Connector'], array $ids = []): GuardConfig
    {
        return new GuardConfig(true, true, $labels, $ids, ['customer_number']);
    }

    public function testSystemSourceIsNotTheConnector(): void
    {
        $this->connection->expects(self::never())->method('fetchOne');

        self::assertNull($this->detector->resolve(Context::createDefaultContext(new SystemSource()), $this->config()));
    }

    public function testSalesChannelSourceIsNotTheConnector(): void
    {
        $context = Context::createDefaultContext(new SalesChannelApiSource(Uuid::randomHex()));

        self::assertNull($this->detector->resolve($context, $this->config()));
    }

    public function testAdminUserWriteIsNotTheConnectorEvenWithIntegration(): void
    {
        $context = Context::createDefaultContext(new AdminApiSource(Uuid::randomHex(), self::INTEGRATION_ID));

        self::assertNull($this->detector->resolve($context, $this->config(ids: [self::INTEGRATION_ID])));
    }

    public function testAdminApiSourceWithoutIntegrationIsNotTheConnector(): void
    {
        $context = Context::createDefaultContext(new AdminApiSource(null, null));

        self::assertNull($this->detector->resolve($context, $this->config()));
    }

    public function testMatchesByConfiguredIdWithoutTouchingLabelsMatch(): void
    {
        $this->connection->method('fetchOne')->willReturn('Something else');
        $context = Context::createDefaultContext(new AdminApiSource(null, strtoupper(self::INTEGRATION_ID)));

        $source = $this->detector->resolve($context, $this->config(labels: ['Nope'], ids: [self::INTEGRATION_ID]));

        self::assertNotNull($source);
        self::assertSame(self::INTEGRATION_ID, $source->integrationId, 'id is normalised to lowercase');
        self::assertSame('Something else', $source->label);
    }

    public function testMatchesByLabelCaseInsensitively(): void
    {
        $this->connection->expects(self::once())->method('fetchOne')
            ->with(
                self::stringContains('FROM `integration`'),
                ['id' => Uuid::fromHexToBytes(self::INTEGRATION_ID)]
            )
            ->willReturn('jtl-connector');
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        $source = $this->detector->resolve($context, $this->config(labels: ['JTL-Connector']));

        self::assertNotNull($source);
        self::assertSame('jtl-connector', $source->label);
    }

    public function testUnknownIntegrationIsNotTheConnector(): void
    {
        $this->connection->method('fetchOne')->willReturn('Some other API client');
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        self::assertNull($this->detector->resolve($context, $this->config()));
    }

    public function testDeletedOrMissingIntegrationIsNotTheConnector(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        self::assertNull($this->detector->resolve($context, $this->config()));
    }

    public function testLabelLookupIsMemoisedPerIntegration(): void
    {
        $this->connection->expects(self::once())->method('fetchOne')->willReturn('JTL-Connector');
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        $this->detector->resolve($context, $this->config());
        $this->detector->resolve($context, $this->config());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Service/ConnectorSourceDetectorTest.php`
Expected: `Class ... ConnectorSourceDetector not found`.

- [ ] **Step 3: Write the value object and the detector**

`src/Service/ConnectorSource.php`:
```php
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
```

`src/Service/ConnectorSourceDetector.php`:
```php
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
 * labels. Anything that is not a positive match is treated as "not the connector".
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

        $wanted = array_map(static fn (string $l): string => mb_strtolower($l), $config->integrationLabels);
        if (\in_array(mb_strtolower($label), $wanted, true)) {
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
}
```

- [ ] **Step 4: Register the detector in `services.xml`** (append inside `<services>`)

```xml
        <service id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSourceDetector">
            <argument type="service" id="Doctrine\DBAL\Connection"/>
            <tag name="kernel.reset" method="reset"/>
        </service>
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Service/ConnectorSourceDetectorTest.php`
Expected: `OK (9 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add src/Service/ConnectorSource.php src/Service/ConnectorSourceDetector.php src/Resources/config/services.xml tests/Unit/Service/ConnectorSourceDetectorTest.php
git commit -m "feat: detect JTL-Connector writes by Admin API integration id or label

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_012JytMPkTC5r69aAKyP7tmB"
```

---

### Task 4: CustomerState + CustomerStateLoader — current DB row of the customers being written

**Files:**
- Create: `src/Service/CustomerState.php`, `src/Service/CustomerStateLoader.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Unit/Service/CustomerStateLoaderTest.php`

**Interfaces:**
- Consumes: `Doctrine\DBAL\Connection::fetchAllAssociative(string $sql, array $params, array $types)`, `Doctrine\DBAL\ArrayParameterType::BINARY`, `Shopware\Core\Framework\Uuid\Uuid::fromBytesToHex()`.
- Produces:
  - `final readonly class CustomerState { public function __construct(public string $id /* hex */, array $row); public function get(string $column): mixed; public function getCustomerNumber(): ?string; public function getEmail(): ?string; public function getFirstName(): ?string; public function getLastName(): ?string; public function getSalesChannelId(): ?string /* hex */ }`
  - `final class CustomerStateLoader { public function __construct(Connection $connection); /** @param list<string> $idsBytes @return array<string, CustomerState> keyed by lowercase hex id */ public function load(array $idsBytes): array }`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Service/CustomerStateLoaderTest.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader;
use Shopware\Core\Framework\Uuid\Uuid;

final class CustomerStateLoaderTest extends TestCase
{
    public function testLoadsRowsKeyedByHexId(): void
    {
        $id = Uuid::randomHex();
        $salesChannelId = Uuid::randomHex();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')
            ->with(
                self::stringContains('FROM `customer` WHERE `id` IN (:ids)'),
                ['ids' => [Uuid::fromHexToBytes($id)]],
                ['ids' => ArrayParameterType::BINARY]
            )
            ->willReturn([[
                'id' => Uuid::fromHexToBytes($id),
                'customer_number' => '100123',
                'email' => 'a@b.de',
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
                'customer_group_id' => Uuid::randomBytes(),
            ]]);

        $states = (new CustomerStateLoader($connection))->load([Uuid::fromHexToBytes($id)]);

        self::assertArrayHasKey($id, $states);
        $state = $states[$id];
        self::assertSame($id, $state->id);
        self::assertSame('100123', $state->getCustomerNumber());
        self::assertSame('a@b.de', $state->getEmail());
        self::assertSame('Ada', $state->getFirstName());
        self::assertSame('Lovelace', $state->getLastName());
        self::assertSame($salesChannelId, $state->getSalesChannelId());
        self::assertSame('100123', $state->get('customer_number'));
        self::assertNull($state->get('does_not_exist'));
    }

    public function testEmptyInputSkipsTheQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAllAssociative');

        self::assertSame([], (new CustomerStateLoader($connection))->load([]));
    }

    public function testNullSalesChannelIsTolerated(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([[
            'id' => Uuid::randomBytes(),
            'customer_number' => 'X',
            'sales_channel_id' => null,
        ]]);

        $state = array_values((new CustomerStateLoader($connection))->load([Uuid::randomBytes()]))[0];

        self::assertNull($state->getSalesChannelId());
        self::assertNull($state->getEmail());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/CustomerStateLoaderTest.php`
Expected: `Class ... CustomerStateLoader not found`.

- [ ] **Step 3: Write the value object and the loader**

`src/Service/CustomerState.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The current `customer` row (storage column names, raw DB values) of a customer that is about to be updated.
 */
final readonly class CustomerState
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

    public function getCustomerNumber(): ?string
    {
        return $this->string('customer_number');
    }

    public function getEmail(): ?string
    {
        return $this->string('email');
    }

    public function getFirstName(): ?string
    {
        return $this->string('first_name');
    }

    public function getLastName(): ?string
    {
        return $this->string('last_name');
    }

    public function getSalesChannelId(): ?string
    {
        $bytes = $this->row['sales_channel_id'] ?? null;

        return \is_string($bytes) && \strlen($bytes) === 16 ? Uuid::fromBytesToHex($bytes) : null;
    }

    private function string(string $column): ?string
    {
        $value = $this->row[$column] ?? null;

        return $value === null ? null : (string) $value;
    }
}
```

`src/Service/CustomerStateLoader.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Loads the current DB state of customers by primary key (binary ids), one query per write event.
 */
final class CustomerStateLoader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<string> $idsBytes 16-byte binary ids as found in WriteCommand::getPrimaryKey()['id']
     *
     * @return array<string, CustomerState> keyed by lowercase hex id
     */
    public function load(array $idsBytes): array
    {
        if ($idsBytes === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM `customer` WHERE `id` IN (:ids)',
            ['ids' => array_values($idsBytes)],
            ['ids' => ArrayParameterType::BINARY]
        );

        $states = [];
        foreach ($rows as $row) {
            $hex = Uuid::fromBytesToHex((string) $row['id']);
            $states[$hex] = new CustomerState($hex, $row);
        }

        return $states;
    }
}
```

- [ ] **Step 4: Register the loader in `services.xml`** (append inside `<services>`)

```xml
        <service id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader">
            <argument type="service" id="Doctrine\DBAL\Connection"/>
        </service>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/CustomerStateLoaderTest.php`
Expected: `OK (3 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add src/Service/CustomerState.php src/Service/CustomerStateLoader.php src/Resources/config/services.xml tests/Unit/Service/CustomerStateLoaderTest.php
git commit -m "feat: load current customer rows for the customers in a write

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_012JytMPkTC5r69aAKyP7tmB"
```

---

### Task 5: Audit trail — migration, `revinners_jtl_guard_log` entity, GuardLogEntry and GuardLogger

**Files:**
- Create: `src/Migration/Migration1788739200CreateJtlGuardLog.php`
- Create: `src/Core/Content/GuardLog/GuardLogDefinition.php`, `GuardLogEntity.php`, `GuardLogCollection.php`
- Create: `src/Service/GuardLogEntry.php`, `src/Service/GuardLogger.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Unit/Service/GuardLoggerTest.php`

**Interfaces:**
- Consumes: `Psr\Log\LoggerInterface` (service `monolog.logger.jtl_connector_guard`, Task 1), `Doctrine\DBAL\Connection::insert()`, `ShopwareJtlConnectorGuardPlugin::LOG_TABLE`.
- Produces:
  - `final readonly class GuardLogEntry { public function __construct(public string $action, public string $mode, public string $field, public ?string $customerId, public ?string $email, public ?string $firstName, public ?string $lastName, public ?string $currentValue, public ?string $attemptedValue, public ?string $assignedValue, public string $integrationId, public ?string $integrationLabel, public ?string $salesChannelId); public const ACTION_BLOCKED_UPDATE = 'blocked_update'; public const ACTION_REMAPPED_CREATE = 'remapped_create'; public function toArray(): array }`
  - `final class GuardLogger { public function __construct(LoggerInterface $logger, Connection $connection); public function log(GuardLogEntry $entry): void }` — never throws.
  - DAL entity `revinners_jtl_guard_log` (repository service `revinners_jtl_guard_log.repository`).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Service/GuardLoggerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Shopware\Core\Framework\Uuid\Uuid;

final class GuardLoggerTest extends TestCase
{
    private function entry(): GuardLogEntry
    {
        return new GuardLogEntry(
            action: GuardLogEntry::ACTION_BLOCKED_UPDATE,
            mode: 'enforce',
            field: 'customer_number',
            customerId: '019df771764772929f1136e52180ccf6',
            email: 'erdoesi@example.com',
            firstName: 'Adam',
            lastName: 'Erdösi',
            currentValue: 'C10009',
            attemptedValue: '10009',
            assignedValue: null,
            integrationId: '2103c0f8ba934cbdb291287aaa3b5ce8',
            integrationLabel: 'JTL-Connector',
            salesChannelId: null,
        );
    }

    public function testWritesToMonologAndToTheDbTable(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::logicalAnd(
                self::stringContains('blocked_update'),
                self::stringContains('erdoesi@example.com'),
                self::stringContains('C10009'),
                self::stringContains('10009'),
            ),
            self::callback(static fn (array $ctx): bool => $ctx['action'] === 'blocked_update' && $ctx['attemptedValue'] === '10009')
        );

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with(
            'revinners_jtl_guard_log',
            self::callback(static function (array $row): bool {
                return \strlen($row['id']) === 16
                    && $row['customer_id'] === Uuid::fromHexToBytes('019df771764772929f1136e52180ccf6')
                    && $row['integration_id'] === Uuid::fromHexToBytes('2103c0f8ba934cbdb291287aaa3b5ce8')
                    && $row['sales_channel_id'] === null
                    && $row['action'] === 'blocked_update'
                    && $row['mode'] === 'enforce'
                    && $row['field'] === 'customer_number'
                    && $row['current_value'] === 'C10009'
                    && $row['attempted_value'] === '10009'
                    && $row['assigned_value'] === null
                    && $row['email'] === 'erdoesi@example.com'
                    && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', $row['created_at']) === 1;
            })
        );

        (new GuardLogger($logger, $connection))->log($this->entry());
    }

    public function testDbFailureIsSwallowedAndReported(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');
        $logger->expects(self::once())->method('error')->with(self::stringContains('could not persist'), self::anything());

        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('table gone'));

        (new GuardLogger($logger, $connection))->log($this->entry());
    }

    public function testEntryToArrayIsFlat(): void
    {
        $array = $this->entry()->toArray();

        self::assertSame('blocked_update', $array['action']);
        self::assertSame('Adam', $array['firstName']);
        self::assertArrayHasKey('integrationLabel', $array);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/GuardLoggerTest.php`
Expected: `Class ... GuardLogEntry not found`.

- [ ] **Step 3: Write the migration**

`src/Migration/Migration1788739200CreateJtlGuardLog.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Audit table: one row per blocked / remapped connector write. No FK to `customer`
 * on purpose — the trail must survive customer deletion.
 *
 * @internal
 */
class Migration1788739200CreateJtlGuardLog extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1788739200;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `revinners_jtl_guard_log` (
                `id`                BINARY(16)   NOT NULL,
                `customer_id`       BINARY(16)   NULL,
                `email`             VARCHAR(255) NULL,
                `first_name`        VARCHAR(255) NULL,
                `last_name`         VARCHAR(255) NULL,
                `field`             VARCHAR(64)  NOT NULL,
                `current_value`     VARCHAR(255) NULL,
                `attempted_value`   VARCHAR(255) NULL,
                `assigned_value`    VARCHAR(255) NULL,
                `action`            VARCHAR(32)  NOT NULL,
                `mode`              VARCHAR(16)  NOT NULL,
                `integration_id`    BINARY(16)   NULL,
                `integration_label` VARCHAR(255) NULL,
                `sales_channel_id`  BINARY(16)   NULL,
                `created_at`        DATETIME(3)  NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx.revinners_jtl_guard_log.customer_id` (`customer_id`),
                KEY `idx.revinners_jtl_guard_log.created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive: the table is dropped on uninstall (without keepUserData).
    }
}
```

- [ ] **Step 4: Write the DAL entity (definition, entity, collection)**

`src/Core/Content/GuardLog/GuardLogDefinition.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Core\Content\GuardLog;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Read model for the audit table so the trail is searchable via the Admin API
 * (POST /api/search/revinners-jtl-guard-log). Rows are written with plain DBAL by GuardLogger.
 */
class GuardLogDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'revinners_jtl_guard_log';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return GuardLogEntity::class;
    }

    public function getCollectionClass(): string
    {
        return GuardLogCollection::class;
    }

    /**
     * The table has no updated_at column, so only created_at is a default field.
     */
    protected function defaultFields(): array
    {
        return [(new CreatedAtField())->addFlags(new ApiAware())];
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            new IdField('customer_id', 'customerId'),
            new StringField('email', 'email'),
            new StringField('first_name', 'firstName'),
            new StringField('last_name', 'lastName'),
            (new StringField('field', 'field', 64))->addFlags(new Required()),
            new StringField('current_value', 'currentValue'),
            new StringField('attempted_value', 'attemptedValue'),
            new StringField('assigned_value', 'assignedValue'),
            (new StringField('action', 'action', 32))->addFlags(new Required()),
            (new StringField('mode', 'mode', 16))->addFlags(new Required()),
            new IdField('integration_id', 'integrationId'),
            new StringField('integration_label', 'integrationLabel'),
            new IdField('sales_channel_id', 'salesChannelId'),
        ]);
    }
}
```

`src/Core/Content/GuardLog/GuardLogEntity.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Core\Content\GuardLog;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class GuardLogEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $customerId = null;

    protected ?string $email = null;

    protected ?string $firstName = null;

    protected ?string $lastName = null;

    protected string $field;

    protected ?string $currentValue = null;

    protected ?string $attemptedValue = null;

    protected ?string $assignedValue = null;

    protected string $action;

    protected string $mode;

    protected ?string $integrationId = null;

    protected ?string $integrationLabel = null;

    protected ?string $salesChannelId = null;

    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getCurrentValue(): ?string
    {
        return $this->currentValue;
    }

    public function getAttemptedValue(): ?string
    {
        return $this->attemptedValue;
    }

    public function getAssignedValue(): ?string
    {
        return $this->assignedValue;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getIntegrationId(): ?string
    {
        return $this->integrationId;
    }

    public function getIntegrationLabel(): ?string
    {
        return $this->integrationLabel;
    }

    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
    }
}
```

`src/Core/Content/GuardLog/GuardLogCollection.php`:
```php
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
```

- [ ] **Step 5: Write the log entry value object and the logger**

`src/Service/GuardLogEntry.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

/**
 * One intervention of the guard (spec R4): who, what was kept, what the connector tried, what we did.
 */
final readonly class GuardLogEntry
{
    public const ACTION_BLOCKED_UPDATE = 'blocked_update';

    public const ACTION_REMAPPED_CREATE = 'remapped_create';

    public function __construct(
        public string $action,
        public string $mode,
        public string $field,
        public ?string $customerId,
        public ?string $email,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $currentValue,
        public ?string $attemptedValue,
        public ?string $assignedValue,
        public string $integrationId,
        public ?string $integrationLabel,
        public ?string $salesChannelId,
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
```

`src/Service/GuardLogger.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Revinners\ShopwareJtlConnectorGuardPlugin\ShopwareJtlConnectorGuardPlugin;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Records every intervention twice: on the `jtl_connector_guard` Monolog channel and in the
 * `revinners_jtl_guard_log` table (plain DBAL insert — no DAL write from inside a write event).
 * Logging must never break the customer write, so DB failures are reported and swallowed.
 */
final class GuardLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Connection $connection,
    ) {
    }

    public function log(GuardLogEntry $entry): void
    {
        $this->logger->info(
            sprintf(
                '[%s] %s: customer %s <%s> %s %s: kept "%s", connector sent "%s"%s (integration %s "%s")',
                $entry->mode,
                $entry->action,
                $entry->customerId ?? 'new',
                $entry->email ?? '-',
                trim(($entry->firstName ?? '') . ' ' . ($entry->lastName ?? '')),
                $entry->field,
                $entry->currentValue ?? '',
                $entry->attemptedValue ?? '',
                $entry->assignedValue !== null ? sprintf(', assigned "%s"', $entry->assignedValue) : '',
                $entry->integrationId,
                $entry->integrationLabel ?? '',
            ),
            $entry->toArray()
        );

        try {
            $this->connection->insert(ShopwareJtlConnectorGuardPlugin::LOG_TABLE, [
                'id' => Uuid::randomBytes(),
                'customer_id' => $entry->customerId !== null ? Uuid::fromHexToBytes($entry->customerId) : null,
                'email' => $entry->email,
                'first_name' => $entry->firstName,
                'last_name' => $entry->lastName,
                'field' => $entry->field,
                'current_value' => $entry->currentValue,
                'attempted_value' => $entry->attemptedValue,
                'assigned_value' => $entry->assignedValue,
                'action' => $entry->action,
                'mode' => $entry->mode,
                'integration_id' => Uuid::fromHexToBytes($entry->integrationId),
                'integration_label' => $entry->integrationLabel,
                'sales_channel_id' => $entry->salesChannelId !== null ? Uuid::fromHexToBytes($entry->salesChannelId) : null,
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'jtl_connector_guard: could not persist audit row: ' . $e->getMessage(),
                ['exception' => $e] + $entry->toArray()
            );
        }
    }
}
```

- [ ] **Step 6: Register entity + logger in `services.xml`** (append inside `<services>`)

```xml
        <service id="Revinners\ShopwareJtlConnectorGuardPlugin\Core\Content\GuardLog\GuardLogDefinition">
            <tag name="shopware.entity.definition" entity="revinners_jtl_guard_log"/>
        </service>

        <service id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger">
            <argument type="service" id="monolog.logger.jtl_connector_guard"/>
            <argument type="service" id="Doctrine\DBAL\Connection"/>
        </service>
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Service/GuardLoggerTest.php`
Expected: `OK (3 tests, ...)`.

- [ ] **Step 8: Commit**

```bash
git add src/Migration src/Core src/Service/GuardLogEntry.php src/Service/GuardLogger.php src/Resources/config/services.xml tests/Unit/Service/GuardLoggerTest.php
git commit -m "feat: audit trail via Monolog channel and revinners_jtl_guard_log entity

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_012JytMPkTC5r69aAKyP7tmB"
```

---

### Task 6: The subscriber — `CustomerNumberWriteProtection` on `EntityWriteEvent`

**Files:**
- Create: `src/Subscriber/CustomerNumberWriteProtection.php`
- Create: `tests/Unit/Subscriber/CustomerTestDefinition.php` (test stub)
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Unit/Subscriber/CustomerNumberWriteProtectionTest.php`

**Interfaces:**
- Consumes: `GuardConfigProvider::load(?string): GuardConfig` (Task 2), `ConnectorSourceDetector::resolve(Context, GuardConfig): ?ConnectorSource` (Task 3), `CustomerStateLoader::load(list<string>): array<string, CustomerState>` (Task 4), `GuardLogger::log(GuardLogEntry)` (Task 5), `Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface::getValue(string $type, Context $context, ?string $salesChannelId, bool $preview = false): string`, `Psr\Log\LoggerInterface`.
- Core DAL facts (verified on 6.6.10.18): `EntityWriteEvent::getCommandsForEntity('customer')` returns the live `WriteCommand` instances; `UpdateCommand` = existing row, `InsertCommand` = new row; `$command->getPayload()` is keyed by **storage** column name with DB-encoded values (`sales_channel_id` is 16 raw bytes, `customer_number` a string); `$command->hasField('customer_number')`; `$command->getPrimaryKey()['id']` is 16 raw bytes; `$command->addPayload($key, $value)` overwrites one payload key.
- Produces: `final class CustomerNumberWriteProtection implements EventSubscriberInterface { public function __construct(GuardConfigProvider, ConnectorSourceDetector, CustomerStateLoader, NumberRangeValueGeneratorInterface, GuardLogger, LoggerInterface); public static function getSubscribedEvents(): array /* [EntityWriteEvent::class => 'onEntityWrite'] */; public function onEntityWrite(EntityWriteEvent $event): void }`

- [ ] **Step 1: Write the test stub definition**

`tests/Unit/Subscriber/CustomerTestDefinition.php`:
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
 * Minimal stand-in for CustomerDefinition: same entity name, only the columns the guard
 * touches, no associations (so it compiles in a StaticDefinitionInstanceRegistry without the
 * rest of the core definitions).
 */
final class CustomerTestDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'customer';
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            new IdField('sales_channel_id', 'salesChannelId'),
            new IdField('customer_group_id', 'customerGroupId'),
            new StringField('customer_number', 'customerNumber'),
            new StringField('email', 'email'),
            new StringField('first_name', 'firstName'),
            new StringField('last_name', 'lastName'),
        ]);
    }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Unit/Subscriber/CustomerNumberWriteProtectionTest.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Subscriber;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSource;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSourceDetector;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerState;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Revinners\ShopwareJtlConnectorGuardPlugin\Subscriber\CustomerNumberWriteProtection;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CustomerNumberWriteProtectionTest extends TestCase
{
    private const INTEGRATION_ID = '2103c0f8ba934cbdb291287aaa3b5ce8';

    private EntityDefinition $definition;
    private GuardConfigProvider&MockObject $configProvider;
    private ConnectorSourceDetector&MockObject $detector;
    private CustomerStateLoader&MockObject $stateLoader;
    private NumberRangeValueGeneratorInterface&MockObject $numberRange;
    private GuardLogger&MockObject $guardLogger;
    private LoggerInterface&MockObject $logger;
    private CustomerNumberWriteProtection $subscriber;
    private Context $connectorContext;

    protected function setUp(): void
    {
        $registry = new StaticDefinitionInstanceRegistry(
            [CustomerTestDefinition::class],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGatewayInterface::class),
        );
        $this->definition = $registry->getByEntityName('customer');

        $this->configProvider = $this->createMock(GuardConfigProvider::class);
        $this->detector = $this->createMock(ConnectorSourceDetector::class);
        $this->stateLoader = $this->createMock(CustomerStateLoader::class);
        $this->numberRange = $this->createMock(NumberRangeValueGeneratorInterface::class);
        $this->guardLogger = $this->createMock(GuardLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new CustomerNumberWriteProtection(
            $this->configProvider,
            $this->detector,
            $this->stateLoader,
            $this->numberRange,
            $this->guardLogger,
            $this->logger,
        );

        $this->connectorContext = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));
    }

    // ---- helpers -----------------------------------------------------------

    private function config(bool $enforce, bool $enabled = true, array $protected = ['customer_number']): GuardConfig
    {
        return new GuardConfig($enabled, $enforce, ['JTL-Connector'], [], $protected);
    }

    private function connectorDetected(): void
    {
        $this->detector->method('resolve')->willReturn(new ConnectorSource(self::INTEGRATION_ID, 'JTL-Connector'));
    }

    /**
     * @param array<string, mixed> $payload storage-name keyed, DB-encoded
     */
    private function update(string $idHex, array $payload): UpdateCommand
    {
        $pk = ['id' => Uuid::fromHexToBytes($idHex)];

        // existing row: exists = true (EntityExistence::createForEntity() would mean "does not exist yet")
        $existence = new EntityExistence('customer', ['id' => $idHex], true, false, false, []);

        return new UpdateCommand($this->definition, $payload, $pk, $existence, '/0');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insert(string $idHex, array $payload): InsertCommand
    {
        $pk = ['id' => Uuid::fromHexToBytes($idHex)];

        return new InsertCommand($this->definition, ['id' => $pk['id']] + $payload, $pk, EntityExistence::createForEntity('customer', ['id' => $idHex]), '/0');
    }

    /**
     * @param list<WriteCommand> $commands
     */
    private function event(array $commands, ?Context $context = null): EntityWriteEvent
    {
        return EntityWriteEvent::create(WriteContext::createFromContext($context ?? $this->connectorContext), $commands);
    }

    private function state(string $idHex, string $number, string $salesChannelHex, array $extra = []): CustomerState
    {
        return new CustomerState($idHex, $extra + [
            'id' => Uuid::fromHexToBytes($idHex),
            'customer_number' => $number,
            'email' => 'erdoesi@example.com',
            'first_name' => 'Adam',
            'last_name' => 'Erdösi',
            'sales_channel_id' => Uuid::fromHexToBytes($salesChannelHex),
        ]);
    }

    // ---- tests -------------------------------------------------------------

    public function testSubscribesToEntityWriteEvent(): void
    {
        self::assertSame([EntityWriteEvent::class => 'onEntityWrite'], CustomerNumberWriteProtection::getSubscribedEvents());
    }

    public function testIgnoresWritesWithoutCustomerCommands(): void
    {
        $this->detector->expects(self::never())->method('resolve');
        $this->guardLogger->expects(self::never())->method('log');

        $this->subscriber->onEntityWrite($this->event([]));
    }

    public function testNonConnectorWriteIsLeftUntouched(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->detector->method('resolve')->willReturn(null);
        $this->stateLoader->expects(self::never())->method('load');
        $this->guardLogger->expects(self::never())->method('log');

        $id = Uuid::randomHex();
        $cmd = $this->update($id, ['customer_number' => '10009']);
        $this->subscriber->onEntityWrite($this->event([$cmd], Context::createDefaultContext(new AdminApiSource(Uuid::randomHex(), null))));

        self::assertSame('10009', $cmd->getPayload()['customer_number']);
    }

    public function testDisabledPluginDoesNothing(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, enabled: false));
        $this->detector->expects(self::never())->method('resolve');
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->update(Uuid::randomHex(), ['customer_number' => '10009']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('10009', $cmd->getPayload()['customer_number']);
    }

    public function testEnforceBlocksNumberChangeOnUpdateButKeepsOtherFields(): void
    {
        $id = Uuid::randomHex();
        $sc = Uuid::randomHex();
        $group = Uuid::randomBytes();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->connectorDetected();
        $this->stateLoader->method('load')->with([Uuid::fromHexToBytes($id)])->willReturn([$id => $this->state($id, 'C10009', $sc)]);
        $this->guardLogger->expects(self::once())->method('log')->with(self::callback(
            static fn (GuardLogEntry $e): bool => $e->action === GuardLogEntry::ACTION_BLOCKED_UPDATE
                && $e->mode === 'enforce'
                && $e->field === 'customer_number'
                && $e->customerId === $id
                && $e->email === 'erdoesi@example.com'
                && $e->firstName === 'Adam'
                && $e->lastName === 'Erdösi'
                && $e->currentValue === 'C10009'
                && $e->attemptedValue === '10009'
                && $e->assignedValue === null
                && $e->integrationId === self::INTEGRATION_ID
                && $e->integrationLabel === 'JTL-Connector'
                && $e->salesChannelId === $sc
        ));

        $cmd = $this->update($id, ['customer_number' => '10009', 'customer_group_id' => $group]);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('C10009', $cmd->getPayload()['customer_number'], 'number reverted to the current value');
        self::assertSame($group, $cmd->getPayload()['customer_group_id'], 'other fields untouched');
    }

    public function testLogOnlyRecordsButDoesNotChangeTheUpdate(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->guardLogger->expects(self::once())->method('log')->with(self::callback(
            static fn (GuardLogEntry $e): bool => $e->action === GuardLogEntry::ACTION_BLOCKED_UPDATE && $e->mode === 'log_only'
        ));

        $cmd = $this->update($id, ['customer_number' => '10009']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('10009', $cmd->getPayload()['customer_number']);
    }

    public function testUnchangedNumberIsNotLogged(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->update($id, ['customer_number' => 'C10009', 'first_name' => 'Adam']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('C10009', $cmd->getPayload()['customer_number']);
    }

    public function testUpdateWithoutProtectedFieldsIsNotLogged(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->update($id, ['customer_group_id' => Uuid::randomBytes()]);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertArrayNotHasKey('customer_number', $cmd->getPayload());
    }

    public function testExtraProtectedFieldIsBlockedTooAndLoggedPerField(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true, protected: ['customer_number', 'email']));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', Uuid::randomHex())]);
        $logged = [];
        $this->guardLogger->method('log')->willReturnCallback(static function (GuardLogEntry $e) use (&$logged): void {
            $logged[] = $e->field;
        });

        $cmd = $this->update($id, ['customer_number' => '10009', 'email' => 'new@example.com', 'last_name' => 'Neu']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame(['customer_number', 'email'], $logged);
        self::assertSame('C10009', $cmd->getPayload()['customer_number']);
        self::assertSame('erdoesi@example.com', $cmd->getPayload()['email']);
        self::assertSame('Neu', $cmd->getPayload()['last_name']);
    }

    public function testUsesTheCustomersSalesChannelForConfig(): void
    {
        $id = Uuid::randomHex();
        $sc = Uuid::randomHex();
        $this->configProvider->expects(self::exactly(2))->method('load')
            ->willReturnCallback(fn (?string $scId): GuardConfig => $this->config(enforce: $scId === $sc));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willReturn([$id => $this->state($id, 'C10009', $sc)]);
        $this->guardLogger->expects(self::once())->method('log');

        $cmd = $this->update($id, ['customer_number' => '10009']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('C10009', $cmd->getPayload()['customer_number'], 'per-sales-channel enforce applied');
    }

    public function testEnforceRemapsConnectorCreatedCustomerToTheShopsNumberRange(): void
    {
        $id = Uuid::randomHex();
        $sc = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->connectorDetected();
        $this->stateLoader->expects(self::never())->method('load');
        $this->numberRange->expects(self::once())->method('getValue')
            ->with('customer', $this->connectorContext, $sc)
            ->willReturn('100456');
        $this->guardLogger->expects(self::once())->method('log')->with(self::callback(
            static fn (GuardLogEntry $e): bool => $e->action === GuardLogEntry::ACTION_REMAPPED_CREATE
                && $e->mode === 'enforce'
                && $e->customerId === $id
                && $e->email === 'sauter@example.com'
                && $e->firstName === 'S'
                && $e->lastName === 'Sauter'
                && $e->currentValue === null
                && $e->attemptedValue === '51520'
                && $e->assignedValue === '100456'
                && $e->salesChannelId === $sc
        ));

        $cmd = $this->insert($id, [
            'customer_number' => '51520',
            'email' => 'sauter@example.com',
            'first_name' => 'S',
            'last_name' => 'Sauter',
            'sales_channel_id' => Uuid::fromHexToBytes($sc),
        ]);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('100456', $cmd->getPayload()['customer_number']);
        self::assertSame('sauter@example.com', $cmd->getPayload()['email']);
    }

    public function testLogOnlyDoesNotReserveANumberOnCreate(): void
    {
        $id = Uuid::randomHex();
        $this->configProvider->method('load')->willReturn($this->config(enforce: false));
        $this->connectorDetected();
        $this->numberRange->expects(self::never())->method('getValue');
        $this->guardLogger->expects(self::once())->method('log')->with(self::callback(
            static fn (GuardLogEntry $e): bool => $e->action === GuardLogEntry::ACTION_REMAPPED_CREATE
                && $e->mode === 'log_only'
                && $e->assignedValue === null
                && $e->salesChannelId === null
        ));

        $cmd = $this->insert($id, ['customer_number' => '51520']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('51520', $cmd->getPayload()['customer_number']);
    }

    public function testInternalFailureNeverBreaksTheWrite(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->connectorDetected();
        $this->stateLoader->method('load')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('left untouched'), self::anything());
        $this->guardLogger->expects(self::never())->method('log');

        $cmd = $this->update(Uuid::randomHex(), ['customer_number' => '10009']);
        $this->subscriber->onEntityWrite($this->event([$cmd]));

        self::assertSame('10009', $cmd->getPayload()['customer_number']);
    }

    public function testUnidentifiedIntegrationWriteIsDebugLogged(): void
    {
        $this->configProvider->method('load')->willReturn($this->config(enforce: true));
        $this->detector->method('resolve')->willReturn(null);
        $this->logger->expects(self::once())->method('debug')->with(self::stringContains('not identified'), ['integrationId' => self::INTEGRATION_ID]);

        $this->subscriber->onEntityWrite($this->event([$this->update(Uuid::randomHex(), ['customer_number' => '1'])]));
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Subscriber/CustomerNumberWriteProtectionTest.php`
Expected: `Class ... CustomerNumberWriteProtection not found`.

- [ ] **Step 4: Write the subscriber**

`src/Subscriber/CustomerNumberWriteProtection.php`:
```php
<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Subscriber;

use Psr\Log\LoggerInterface;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSource;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSourceDetector;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Makes Shopware the owner of `customer.customer_number` against the JTL-Connector.
 *
 * Runs on EntityWriteEvent, which the DBAL EntityWriteGateway dispatches with the exact
 * WriteCommand instances it executes afterwards. For connector writes only:
 *  - UpdateCommand: a changed protected column is reverted to its current DB value
 *    (WriteCommand::addPayload overwrites the key — a key cannot be removed), every other
 *    field of the same write is left alone;
 *  - InsertCommand: the supplied customer_number is replaced by a value reserved from the
 *    shop's own `customer` number range for the customer's sales channel.
 * In log_only mode nothing is changed, only logged. Any internal failure is caught: the
 * write must never be blocked by the guard itself.
 */
final class CustomerNumberWriteProtection implements EventSubscriberInterface
{
    private const FIELD_CUSTOMER_NUMBER = GuardConfigProvider::FIELD_CUSTOMER_NUMBER;

    public function __construct(
        private readonly GuardConfigProvider $configProvider,
        private readonly ConnectorSourceDetector $sourceDetector,
        private readonly CustomerStateLoader $stateLoader,
        private readonly NumberRangeValueGeneratorInterface $numberRangeGenerator,
        private readonly GuardLogger $guardLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [EntityWriteEvent::class => 'onEntityWrite'];
    }

    public function onEntityWrite(EntityWriteEvent $event): void
    {
        try {
            $this->guard($event);
        } catch (\Throwable $e) {
            $this->logger->error(
                'jtl_connector_guard failed, customer write left untouched: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    private function guard(EntityWriteEvent $event): void
    {
        $commands = $event->getCommandsForEntity(CustomerDefinition::ENTITY_NAME);
        if ($commands === []) {
            return;
        }

        $context = $event->getContext();
        $source = $context->getSource();
        // Cheap pre-filter: only Admin API integration writes can be the connector.
        if (!$source instanceof AdminApiSource || $source->getIntegrationId() === null || $source->getUserId() !== null) {
            return;
        }

        $globalConfig = $this->configProvider->load(null);
        if (!$globalConfig->enabled) {
            return;
        }

        $connector = $this->sourceDetector->resolve($context, $globalConfig);
        if ($connector === null) {
            $this->logger->debug(
                'jtl_connector_guard: admin-api integration write to customer not identified as the connector, left untouched',
                ['integrationId' => strtolower($source->getIntegrationId())]
            );

            return;
        }

        $updates = [];
        $inserts = [];
        foreach ($commands as $command) {
            if ($command instanceof UpdateCommand) {
                $updates[] = $command;
            } elseif ($command instanceof InsertCommand) {
                $inserts[] = $command;
            }
        }

        $this->guardUpdates($updates, $connector);
        $this->guardInserts($inserts, $connector, $context);
    }

    /**
     * @param list<UpdateCommand> $updates
     */
    private function guardUpdates(array $updates, ConnectorSource $connector): void
    {
        if ($updates === []) {
            return;
        }

        $ids = [];
        foreach ($updates as $command) {
            $ids[] = (string) $command->getPrimaryKey()['id'];
        }
        $states = $this->stateLoader->load($ids);

        foreach ($updates as $command) {
            $idHex = Uuid::fromBytesToHex((string) $command->getPrimaryKey()['id']);
            $state = $states[$idHex] ?? null;
            if ($state === null) {
                continue; // row vanished between extraction and event; nothing to protect
            }

            $config = $this->configProvider->load($state->getSalesChannelId());
            if (!$config->enabled) {
                continue;
            }

            $payload = $command->getPayload();
            foreach ($config->protectedFields as $field) {
                if (!$command->hasField($field)) {
                    continue;
                }

                $attempted = $payload[$field];
                $current = $state->get($field);
                if ($this->same($attempted, $current)) {
                    continue;
                }

                if ($config->enforce) {
                    $command->addPayload($field, $current);
                }

                $this->guardLogger->log(new GuardLogEntry(
                    action: GuardLogEntry::ACTION_BLOCKED_UPDATE,
                    mode: $config->mode(),
                    field: $field,
                    customerId: $idHex,
                    email: $state->getEmail(),
                    firstName: $state->getFirstName(),
                    lastName: $state->getLastName(),
                    currentValue: $this->stringOrNull($current),
                    attemptedValue: $this->stringOrNull($attempted),
                    assignedValue: null,
                    integrationId: $connector->integrationId,
                    integrationLabel: $connector->label,
                    salesChannelId: $state->getSalesChannelId(),
                ));
            }
        }
    }

    /**
     * @param list<InsertCommand> $inserts
     */
    private function guardInserts(array $inserts, ConnectorSource $connector, Context $context): void
    {
        foreach ($inserts as $command) {
            $payload = $command->getPayload();
            $salesChannelId = $this->hexOrNull($payload['sales_channel_id'] ?? null);

            $config = $this->configProvider->load($salesChannelId);
            if (!$config->enabled) {
                continue;
            }

            $attempted = $this->stringOrNull($payload[self::FIELD_CUSTOMER_NUMBER] ?? null);
            $assigned = null;
            if ($config->enforce) {
                $assigned = $this->numberRangeGenerator->getValue(CustomerDefinition::ENTITY_NAME, $context, $salesChannelId);
                $command->addPayload(self::FIELD_CUSTOMER_NUMBER, $assigned);
            }

            $this->guardLogger->log(new GuardLogEntry(
                action: GuardLogEntry::ACTION_REMAPPED_CREATE,
                mode: $config->mode(),
                field: self::FIELD_CUSTOMER_NUMBER,
                customerId: $this->hexOrNull($command->getPrimaryKey()['id'] ?? null),
                email: $this->stringOrNull($payload['email'] ?? null),
                firstName: $this->stringOrNull($payload['first_name'] ?? null),
                lastName: $this->stringOrNull($payload['last_name'] ?? null),
                currentValue: null,
                attemptedValue: $attempted,
                assignedValue: $assigned,
                integrationId: $connector->integrationId,
                integrationLabel: $connector->label,
                salesChannelId: $salesChannelId,
            ));
        }
    }

    private function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return (string) $a === (string) $b;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = (string) $value;
        // binary ids (16 raw bytes) are shown as hex in the log
        if (\strlen($string) === 16 && !ctype_print($string)) {
            return Uuid::fromBytesToHex($string);
        }

        return $string;
    }

    private function hexOrNull(mixed $bytes): ?string
    {
        return \is_string($bytes) && \strlen($bytes) === 16 ? Uuid::fromBytesToHex($bytes) : null;
    }
}
```

- [ ] **Step 5: Register the subscriber in `services.xml`** (append inside `<services>`)

```xml
        <service id="Revinners\ShopwareJtlConnectorGuardPlugin\Subscriber\CustomerNumberWriteProtection">
            <argument type="service" id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider"/>
            <argument type="service" id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSourceDetector"/>
            <argument type="service" id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader"/>
            <argument type="service" id="Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface"/>
            <argument type="service" id="Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger"/>
            <argument type="service" id="monolog.logger.jtl_connector_guard"/>
            <tag name="kernel.event_subscriber"/>
        </service>
```

- [ ] **Step 6: Run the whole suite**

Run: `vendor/bin/phpunit`
Expected: all green (3 + 6 + 9 + 3 + 3 + 15 = 39 tests). `EntityExistence::__construct(?string $entityName, array $primaryKey, bool $exists, bool $isChild, bool $wasChild, array $state)` verified on 6.6.10.18; the subscriber dispatches on the command class, not on `exists()`.

- [ ] **Step 7: Commit**

```bash
git add src/Subscriber src/Resources/config/services.xml tests/Unit/Subscriber
git commit -m "feat: block connector customer_number changes and remap connector-created numbers

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_012JytMPkTC5r69aAKyP7tmB"
```

---

### Task 7: README, CHANGELOG, push the branch

**Files:**
- Create: `README.md`, `CHANGELOG.md`
- Modify: `specs/feat/001-customer-number-write-protection/SPEC.md` (status line only)

- [ ] **Step 1: Write `README.md`**

```markdown
# ShopwareJtlConnectorGuardPlugin

`revinners/shopware6-jtl-connector-guard` — a container plugin for every fix we apply on top of the
JTL-Connector (JTL-Wawi → Shopware). Shops: **yam-shop.de**, **ducati-world24.com** (Shopware 6.6.10.x).

## Feature 001 — customer number write protection

The JTL-Connector pushes Wawi's "Kundennummer Onlineshop" into `customer.customer_number` on every
customer change, creating duplicate numbers (see `specs/feat/001-customer-number-write-protection/SPEC.md`).
This plugin makes Shopware the owner of the number:

- **Existing customers:** a connector write that would change `customer_number` (or any other column
  listed in *Protected customer columns*) has that column reverted to the current value; every other
  field of the same write (customer group, addresses, …) is applied normally.
- **Connector-created customers:** the supplied number is replaced by one reserved from the shop's own
  `customer` number range (for the customer's sales channel), exactly like a storefront registration.
- **Audit trail:** every intervention is written to the Monolog channel `jtl_connector_guard`
  (`var/log/jtl_connector_guard_<env>.log`, also propagated to the main log) **and** to the table
  `revinners_jtl_guard_log` (DAL entity `revinners_jtl_guard_log`, searchable via
  `POST /api/search/revinners-jtl-guard-log`).

### How connector writes are identified

The connector authenticates as an Admin API **integration** (client credentials), so its writes carry
an `AdminApiSource` with an integration id and no user id. That integration is matched by **label**
(default `JTL-Connector`, case-insensitive) and/or by explicit ids from the plugin config. Anything
else — admin users, storefront, CLI, imports, other integrations — is never touched.

### Configuration (Settings → Extensions → JTL-Connector Guard, per sales channel capable)

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | master switch |
| `mode` | `log_only` | `log_only` = observe and log, change nothing; `enforce` = block / remap |
| `integrationLabels` | `JTL-Connector` | comma-separated integration labels |
| `integrationIds` | *(empty)* | comma-separated 32-char hex integration ids |
| `protectedFields` | `customer_number` | storage columns of `customer` the connector may not change (customer_number always included) |

### Rollout

1. Install and activate — it starts in `log_only`.
2. Trigger a push (change a linked customer's Kundengruppe in Wawi) and check the log / table for a
   `blocked_update` row with the attempted number.
3. Switch `mode` to `enforce`, repeat: the number must stay, the group must still change.

### Implementation notes

- Hook: `Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent`, dispatched by the DBAL
  `EntityWriteGateway` with the live `WriteCommand` objects. The only mutation API is
  `WriteCommand::addPayload()` (`@internal`): a payload key cannot be removed, so we overwrite it.
  Re-verify this on every Shopware minor upgrade.
- The audit row is inserted with plain DBAL inside the event (no nested DAL write) and can never
  break the customer write.

## Development

```bash
composer install
vendor/bin/phpunit
```

Local shop integration: copy the plugin into `custom/plugins/ShopwareJtlConnectorGuardPlugin` of the
shop checkout, then `bin/console plugin:refresh && bin/console plugin:install --activate ShopwareJtlConnectorGuardPlugin`.
```

- [ ] **Step 2: Write `CHANGELOG.md`**

```markdown
# Changelog

## 1.0.0 — 2026-09-07

- Feature 001: customer number write protection against the JTL-Connector
  (`log_only` by default, `enforce` blocks updates and remaps connector creates to the shop number range).
- Audit trail on Monolog channel `jtl_connector_guard` and table `revinners_jtl_guard_log`.
```

- [ ] **Step 3: Update the spec status line**

In `specs/feat/001-customer-number-write-protection/SPEC.md` replace
`> Status: **SPEC ONLY — no implementation plan yet (PLAN.md intentionally empty).**`
with
`> Status: **PLANNED — see PLAN.md (implementation in progress on branch feat/001-customer-number-write-protection).**`

- [ ] **Step 4: Run the suite once more, commit, push**

```bash
vendor/bin/phpunit
git add README.md CHANGELOG.md specs/
git commit -m "docs: README, changelog and spec status for feature 001

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_012JytMPkTC5r69aAKyP7tmB"
git push -u origin feat/001-customer-number-write-protection
```
Expected: push succeeds (plain `git push` works on this machine; the `gh` CLI does not — do not use it).

---

### Task 8: Integration verification in the local yam-shop Docker shop (real DAL, real Admin API)

This is the "one-customer proof" of the spec, run locally instead of against Wawi. Nothing here is committed to the shop repo; the plugin copy is removed at the end.

**Files:**
- Temporary copy: `/Users/macbookpro/Soft/yam-shop/src/custom/plugins/ShopwareJtlConnectorGuardPlugin` (deleted in the last step; never `git add` it)

- [ ] **Step 1: Start the DE dev shop and copy the plugin in**

```bash
cd /Users/macbookpro/Soft/yam-shop
docker compose -f docker-compose.yam-shop.dev.yml up -d
rsync -a --delete --exclude vendor --exclude .git --exclude .phpunit.cache \
  /Users/macbookpro/Soft/ShopwareJtlConnectorGuardPlugin/ src/custom/plugins/ShopwareJtlConnectorGuardPlugin/
docker compose -f docker-compose.yam-shop.dev.yml exec yam-shop bash -c \
  "bin/console plugin:refresh && bin/console plugin:install --activate ShopwareJtlConnectorGuardPlugin && bin/console cache:clear"
```
Expected: plugin listed as installed+active; migration ran (`SHOW TABLES LIKE 'revinners_jtl_guard_log'` returns the table). If the container image needs a first build, allow several minutes. If `plugin:refresh` cannot see the plugin, confirm `custom/plugins/ShopwareJtlConnectorGuardPlugin/composer.json` exists inside the container (`ls /var/www/html/custom/plugins`).

- [ ] **Step 2: Create a test integration named "JTL-Connector" and fetch a token**

```bash
docker compose -f docker-compose.yam-shop.dev.yml exec yam-shop bash -c '
HASH=$(php -r "echo password_hash(\"guardsecret\", PASSWORD_BCRYPT);")
mysql -h db -u root -pdocker barthel_sw -e "
INSERT INTO integration (id, label, access_key, secret_access_key, admin, created_at)
VALUES (UNHEX(REPLACE(UUID(),\"-\",\"\")), \"JTL-Connector\", \"SWIAGUARDTEST\", \"$HASH\", 1, NOW(3));
SELECT LOWER(HEX(id)) id, label FROM integration WHERE label=\"JTL-Connector\";"
curl -s -X POST http://localhost/api/oauth/token -H "Content-Type: application/json" \
  -d "{\"grant_type\":\"client_credentials\",\"client_id\":\"SWIAGUARDTEST\",\"client_secret\":\"guardsecret\"}" | head -c 300'
```
Expected: an integration row and a JSON body with `access_token`. Save the token in `TOKEN` for the next steps (re-run the curl and `export TOKEN=...` inside the same `bash -c` if needed).

- [ ] **Step 3: log_only — PATCH a customer's number through the integration**

```bash
docker compose -f docker-compose.yam-shop.dev.yml exec yam-shop bash -c '
TOKEN=$(curl -s -X POST http://localhost/api/oauth/token -H "Content-Type: application/json" -d "{\"grant_type\":\"client_credentials\",\"client_id\":\"SWIAGUARDTEST\",\"client_secret\":\"guardsecret\"}" | php -r "echo json_decode(stream_get_contents(STDIN))->access_token;")
read ID NUM <<< $(mysql -N -h db -u root -pdocker barthel_sw -e "SELECT LOWER(HEX(id)), customer_number FROM customer ORDER BY created_at DESC LIMIT 1")
echo "customer $ID current $NUM"
curl -s -o /dev/null -w "%{http_code}\n" -X PATCH http://localhost/api/customer/$ID -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d "{\"customerNumber\":\"GUARDTEST-1\"}"
mysql -h db -u root -pdocker barthel_sw -e "SELECT customer_number, updated_by_id FROM customer WHERE id=UNHEX(\"$ID\"); SELECT action, mode, field, current_value, attempted_value, assigned_value, integration_label FROM revinners_jtl_guard_log ORDER BY created_at DESC LIMIT 3;"
tail -3 var/log/jtl_connector_guard_dev.log'
```
Expected: HTTP `204`; `customer_number` is now `GUARDTEST-1` (log_only changes nothing); one `blocked_update` row with `mode=log_only`, `current_value=<old>`, `attempted_value=GUARDTEST-1`, `integration_label=JTL-Connector`; matching line in the channel log.

- [ ] **Step 4: enforce — repeat and confirm the number is kept while other fields still change**

```bash
docker compose -f docker-compose.yam-shop.dev.yml exec yam-shop bash -c '
bin/console system:config:set ShopwareJtlConnectorGuardPlugin.config.mode enforce && bin/console cache:clear >/dev/null
TOKEN=$(curl -s -X POST http://localhost/api/oauth/token -H "Content-Type: application/json" -d "{\"grant_type\":\"client_credentials\",\"client_id\":\"SWIAGUARDTEST\",\"client_secret\":\"guardsecret\"}" | php -r "echo json_decode(stream_get_contents(STDIN))->access_token;")
read ID NUM <<< $(mysql -N -h db -u root -pdocker barthel_sw -e "SELECT LOWER(HEX(id)), customer_number FROM customer ORDER BY created_at DESC LIMIT 1")
curl -s -o /dev/null -w "%{http_code}\n" -X PATCH http://localhost/api/customer/$ID -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d "{\"customerNumber\":\"GUARDTEST-2\",\"lastName\":\"GuardEnforced\"}"
mysql -h db -u root -pdocker barthel_sw -e "SELECT customer_number, last_name FROM customer WHERE id=UNHEX(\"$ID\"); SELECT action, mode, current_value, attempted_value FROM revinners_jtl_guard_log ORDER BY created_at DESC LIMIT 1;"'
```
Expected: HTTP `204`; `customer_number` still `GUARDTEST-1` (kept), `last_name` = `GuardEnforced` (other field applied); newest log row `blocked_update / enforce / GUARDTEST-1 / GUARDTEST-2`.

- [ ] **Step 5: enforce — connector-created customer gets a range number**

```bash
docker compose -f docker-compose.yam-shop.dev.yml exec yam-shop bash -c '
TOKEN=$(curl -s -X POST http://localhost/api/oauth/token -H "Content-Type: application/json" -d "{\"grant_type\":\"client_credentials\",\"client_id\":\"SWIAGUARDTEST\",\"client_secret\":\"guardsecret\"}" | php -r "echo json_decode(stream_get_contents(STDIN))->access_token;")
read SC GROUP PAY SALUT <<< $(mysql -N -h db -u root -pdocker barthel_sw -e "SELECT LOWER(HEX(sc.id)), LOWER(HEX(sc.customer_group_id)), LOWER(HEX(sc.payment_method_id)), (SELECT LOWER(HEX(id)) FROM salutation LIMIT 1) FROM sales_channel sc WHERE sc.type_id=UNHEX(\"8a243080f92e4c719546314b577cf82b\") LIMIT 1")
NEW=$(php -r "echo bin2hex(random_bytes(16));")
ADDR=$(php -r "echo bin2hex(random_bytes(16));")
COUNTRY=$(mysql -N -h db -u root -pdocker barthel_sw -e "SELECT LOWER(HEX(id)) FROM country WHERE iso=\"DE\"")
curl -s -w "\n%{http_code}\n" -X POST http://localhost/api/customer -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d "{\"id\":\"$NEW\",\"customerNumber\":\"51520\",\"email\":\"guard-create-$NEW@example.com\",\"firstName\":\"Guard\",\"lastName\":\"Created\",\"salesChannelId\":\"$SC\",\"groupId\":\"$GROUP\",\"defaultPaymentMethodId\":\"$PAY\",\"salutationId\":\"$SALUT\",\"defaultBillingAddress\":{\"id\":\"$ADDR\",\"firstName\":\"Guard\",\"lastName\":\"Created\",\"street\":\"Teststr. 1\",\"zipcode\":\"10115\",\"city\":\"Berlin\",\"countryId\":\"$COUNTRY\",\"salutationId\":\"$SALUT\"},\"defaultShippingAddressId\":\"$ADDR\"}" | tail -c 400
mysql -h db -u root -pdocker barthel_sw -e "SELECT customer_number FROM customer WHERE id=UNHEX(\"$NEW\"); SELECT action, mode, attempted_value, assigned_value FROM revinners_jtl_guard_log ORDER BY created_at DESC LIMIT 1;"'
```
Expected: HTTP `204`; the new customer's number is a fresh value from the `customer` number range (100000+ on yam-shop), not `51520`; newest log row `remapped_create / enforce / 51520 / <assigned>`. If the create is rejected for a missing required association (e.g. addresses), adjust the payload until Shopware accepts it — the guard only cares about `customer_number`; a failing create (HTTP 400) must show **no** side effects apart from a possibly consumed range number.

- [ ] **Step 6: Regression — an admin-user write still changes the number, storefront untouched**

```bash
docker compose -f docker-compose.yam-shop.dev.yml exec yam-shop bash -c '
read ID <<< $(mysql -N -h db -u root -pdocker barthel_sw -e "SELECT LOWER(HEX(id)) FROM customer ORDER BY created_at DESC LIMIT 1")
bin/console dal:write customer "[{\"id\":\"$ID\",\"customerNumber\":\"ADMIN-OK\"}]" 2>/dev/null || php -r "echo \"dal:write not available, use the admin UI or an admin-user API token instead\n\";"
mysql -h db -u root -pdocker barthel_sw -e "SELECT customer_number FROM customer WHERE id=UNHEX(\"$ID\"); SELECT COUNT(*) rows_after FROM revinners_jtl_guard_log;"'
```
Expected: with a CLI/system or admin-user source the number changes to `ADMIN-OK` and the log row count does not grow. If no CLI write command exists, log into the admin (`http://yam-shop.docker.localhost/admin`) and change the customer number in the customer module — it must save, and no log row must appear.

- [ ] **Step 7: Clean up the shop checkout**

```bash
cd /Users/macbookpro/Soft/yam-shop
docker compose -f docker-compose.yam-shop.dev.yml exec yam-shop bash -c \
  "bin/console plugin:uninstall ShopwareJtlConnectorGuardPlugin && mysql -h db -u root -pdocker barthel_sw -e \"DELETE FROM integration WHERE access_key='SWIAGUARDTEST'\" && bin/console cache:clear"
rm -rf src/custom/plugins/ShopwareJtlConnectorGuardPlugin
git status --short   # must be clean
```
Expected: `git status` shows nothing for the shop repo.

- [ ] **Step 8: Record the result**

Append to `CHANGELOG.md` under 1.0.0: `- Verified locally on yam-shop dev (6.6.10.18): blocked_update in log_only and enforce, remapped_create in enforce, admin writes unaffected.` and commit in the plugin repo:
```bash
cd /Users/macbookpro/Soft/ShopwareJtlConnectorGuardPlugin
git add CHANGELOG.md
git commit -m "docs: record local integration verification

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_012JytMPkTC5r69aAKyP7tmB"
git push
```
If any step failed, fix the code first (with a unit test reproducing it), re-run Task 8 from Step 1, and only then commit.

---

### Task 9: Release 1.0.0 and wire the plugin into yam-shop.de

**Files:**
- Plugin repo: branch `master` + tag `1.0.0`
- Modify: `/Users/macbookpro/Soft/yam-shop/src/composer.json` (repositories + require), `/Users/macbookpro/Soft/yam-shop/src/composer.lock`
- Modify: `~/.claude/skills/shopware-ecosystem-architect/repositories.md`, `~/.claude/skills/shopware-ecosystem-architect/sw_plugins.md`

- [ ] **Step 1: Merge to master and tag in the plugin repo**

```bash
cd /Users/macbookpro/Soft/ShopwareJtlConnectorGuardPlugin
git checkout -B master
git merge --ff-only feat/001-customer-number-write-protection
git push -u origin master
git tag -a 1.0.0 -m "1.0.0 - customer number write protection"
git push origin 1.0.0
```
Expected: `master` and tag `1.0.0` visible on `https://github.com/revinners/ShopwareJtlConnectorGuardPlugin`.

- [ ] **Step 2: Add the VCS repository and requirement to the shop**

In `/Users/macbookpro/Soft/yam-shop/src/composer.json`, add to `repositories` (next to the other `ShopwareXxxPlugin` entries):
```json
    "ShopwareJtlConnectorGuardPlugin": {
      "type": "vcs",
      "url": "https://github.com/revinners/ShopwareJtlConnectorGuardPlugin"
    }
```
and to `require` (alphabetically among the `revinners/*` lines):
```json
    "revinners/shopware6-jtl-connector-guard": "^1.0",
```
Then resolve the lock file from the host (PHP 8.4 with the required extensions is installed; no scripts so nothing touches the DB):
```bash
cd /Users/macbookpro/Soft/yam-shop/src
composer update revinners/shopware6-jtl-connector-guard --no-scripts --no-install 2>&1 | tail -5
git diff --stat
```
Expected: `composer.lock` gains exactly one package entry `revinners/shopware6-jtl-connector-guard 1.0.0`; no other package changes (if others move, abort with `git checkout composer.lock` and use `composer require revinners/shopware6-jtl-connector-guard:^1.0 --no-scripts --no-install --no-update` followed by `composer update revinners/shopware6-jtl-connector-guard --no-scripts --no-install --with-dependencies=false`).

- [ ] **Step 3: Commit in the shop repo (do not push unless asked; prod deploys are manual)**

```bash
cd /Users/macbookpro/Soft/yam-shop
git add src/composer.json src/composer.lock
git commit -m "chore: add revinners/shopware6-jtl-connector-guard 1.0.0 (customer number guard, log_only)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_012JytMPkTC5r69aAKyP7tmB"
```

- [ ] **Step 4: Register the plugin in the ecosystem docs**

`~/.claude/skills/shopware-ecosystem-architect/repositories.md` — add a row to the "Custom Shopware plugins" table:
```
| `revinners/shopware6-jtl-connector-guard`               | https://github.com/revinners/ShopwareJtlConnectorGuardPlugin        | `$SW_ROOT/ShopwareJtlConnectorGuardPlugin`        | Guards data against JTL-Connector overwrites (feature 001: customer_number). Shops: yam-shop.de, ducati-world24.com                     |
```
`~/.claude/skills/shopware-ecosystem-architect/sw_plugins.md` — add a row to the "brand-specific" table:
```
| `revinners/shopware6-jtl-connector-guard` | `yam-shop.de`, `ducati-world24.com`                             | Blocks JTL-Connector overwrites of customer_number; audit log table `revinners_jtl_guard_log` |
```

- [ ] **Step 5: Final report to the user**

State: plugin repo/tag, unit test count, what the local verification proved, the shop commit hash, and the manual prod steps left for the operator on each shop: `composer install`, `bin/console plugin:refresh && bin/console plugin:install --activate ShopwareJtlConnectorGuardPlugin`, `bin/console cache:clear`, confirm the integration label is `JTL-Connector` (or set `integrationLabels`/`integrationIds`), watch `revinners_jtl_guard_log` for a few days in `log_only`, then set `mode = enforce`. ducati-world24.com follows the same steps (its checkout `$SW_ROOT/ducati-world24` is not part of this plan).

---

## Self-review

- **Spec coverage:** R1 → Task 6 `guardUpdates` (only the protected column reverted, rest applied, write never aborted). R2 → Task 6 `guardInserts` + number range reservation per sales channel. R3 → Task 3 (AdminApiSource + integration id/label, configurable, fail-safe null) + Task 6 pre-filter and debug log. R4 → Task 5 (Monolog channel + DB table with customer id/email/name, kept/attempted/assigned values, action, integration, timestamp). R5 → Task 1 config.xml + Task 2 provider (`enabled`, `mode` default `log_only`, integration identifiers, protected-fields block list). R6 → Task 3/6 (non-connector untouched, only the field touched, no data migration on install). Test plan steps 1–4 → Task 8; step 5 (prod rollout) → operator steps in Task 9. Open questions 1, 2, 3, 5 → decisions table; 4 needs no code.
- **Placeholder scan:** none; every code step carries the full file.
- **Type consistency:** `GuardConfig(enabled, enforce, integrationLabels, integrationIds, protectedFields)` used identically in Tasks 2, 3, 6; `GuardLogEntry` named-argument order identical in Tasks 5 and 6; `ConnectorSource(integrationId, label)`; `CustomerState::get/getCustomerNumber/getEmail/getFirstName/getLastName/getSalesChannelId`; `CustomerStateLoader::load(list<bytes>): array<hex, CustomerState>`; service ids in `services.xml` match the FQCNs; Monolog service id `monolog.logger.jtl_connector_guard` matches the channel constant.
