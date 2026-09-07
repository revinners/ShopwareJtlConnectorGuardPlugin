# ShopwareJtlConnectorGuardPlugin

`revinners/shopware6-jtl-connector-guard` — a container plugin for every fix we apply on top of the
JTL-Connector (JTL-Wawi → Shopware). Shops: **yam-shop.de**, **ducati-world24.com** (Shopware 6.6.10.x).
Features: **001** customer number write protection (1.0.x), **002** customer identity write protection (1.1.0).

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
  (`var/log/jtl_connector_guard_<env>.log`) **and** to the table `revinners_jtl_guard_log`
  (DAL entity `revinners_jtl_guard_log`, searchable via `POST /api/search/revinners-jtl-guard-log`).
  Those two are the reliable sinks. Shopware's prod Monolog config runs the `main` handler as
  `fingers_crossed` with `action_level: error`, so an `info` line only reaches `prod.log` if an
  *error* also happens in the same request — the guard's routine `blocked_update` /
  `remapped_create` lines will not show up there. Only the guard's own `error` lines (an
  internal failure, or a logging sink that itself failed — see *Implementation notes*) are
  expected to land in the main log; treat `jtl_connector_guard_<env>.log` and the DB table as
  the sources of truth for auditing.

### How connector writes are identified

The connector authenticates as an Admin API **integration** (client credentials), so its writes carry
an `AdminApiSource` with an integration id and no user id. That integration is matched by **label**
(default `JTL-Connector`; matching ignores case, whitespace and punctuation, so the `JTL Connector`
label used on yam-shop.de matches too) and/or by explicit ids from the plugin config. Anything
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

1. Before installing on a shop, confirm the connector's integration label/id in
   Settings → System → Integrations:
   - **yam-shop.de:** the production integration is labelled `JTL Connector`. The default
     `integrationLabels` (`JTL-Connector`) already matches it thanks to normalisation (matching
     ignores case, whitespace and punctuation), but set `integrationIds` to
     `019b8946ccc67767b9fb8cb524300ba1` as well so detection does not depend on the label
     surviving a future rename.
   - **ducati-world24.com:** verify the integration label/id in Settings → System → Integrations
     before install; do not assume it matches the default.
2. Install and activate — it starts in `log_only`. In this mode the guard only *observes*:
   Wawi still overwrites `customer_number` while you watch, nothing is blocked yet. Keep this
   window short — a few days of real pushes is enough to confirm detection — then switch to
   `enforce`; leaving `log_only` on longer does not protect any customer number.
3. Trigger a push (change a linked customer's Kundengruppe in Wawi) and check the log / table for a
   `blocked_update` row with the attempted number.
4. Switch `mode` to `enforce`, repeat: the number must stay, the group must still change.
5. Roll out to **one shop first**. Watch `revinners_jtl_guard_log` and the channel file
   (`var/log/jtl_connector_guard_<env>.log`) for a few days of real traffic before installing on
   the second shop.
6. Both shops require PHP >= 8.2 (see `composer.json`). yam-shop.de is verified on PHP 8.3; check
   the running PHP version on ducati-world24.com before install.
7. Release checklist: when releasing, bump `version` in `composer.json` and create the git tag
   with the same value — Composer ignores a tag whose `composer.json` version disagrees.

### Implementation notes

- Hook: `Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent`, dispatched by the DBAL
  `EntityWriteGateway` with the live `WriteCommand` objects. The only mutation API is
  `WriteCommand::addPayload()` (`@internal`): a payload key cannot be removed, so we overwrite it.
  Re-verify this on every Shopware minor upgrade.
- The audit row is inserted with plain DBAL inside the event (no nested DAL write) and can never
  break the customer write.
- Both the channel logger call and the DB insert are wrapped independently, and every error/debug
  line the guard emits falls back to Shopware's main `logger` service if the `jtl_connector_guard`
  channel itself cannot be written (e.g. its log file cannot be opened). No failure of either
  logger can propagate out of `onEntityWrite()` and into the DAL write.

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

## Development

```bash
composer install
vendor/bin/phpunit
```

Unit tests mock the plugin's `final` services, so `dg/bypass-finals` is enabled dev-only in `tests/bootstrap.php`.

Local shop integration: copy the plugin into `custom/plugins/ShopwareJtlConnectorGuardPlugin` of the
shop checkout, then `bin/console plugin:refresh && bin/console plugin:install --activate ShopwareJtlConnectorGuardPlugin`.
