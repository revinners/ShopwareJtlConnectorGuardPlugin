# ShopwareJtlConnectorGuardPlugin

`revinners/shopware6-jtl-connector-guard` — a container plugin for every fix we apply on top of the
JTL-Connector (JTL-Wawi → Shopware). Shops: **yam-shop.de**, **ducati-world24.com** (Shopware 6.6.10.x).
Features: **001** customer number write protection (1.0.x), **002** customer identity write protection (1.1.0), **003** connector field allow-list (1.2.0).

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
  `remapped_create` lines (and, from feature 002, `blocked_identity` / `observed_identity`) will
  not show up there. Only the guard's own `error` lines (an
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
- Custom-field writes arrive as a separate `JsonUpdateCommand` (payload keyed by custom-field key);
  001/002 ignore it, 003 guards it per key.

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

**Caveat:** if `email` is also listed in the 001 `protectedFields`, the 001 guard (and its mode)
owns the email field itself — it alone decides whether the email is kept or applied, and the
identity guard never acts on `email` again. The identity guard still uses the connector's attempted
email swap to drive the name policy (per `identityGuardProtectName`), so a name swapped in the same
write is still guarded even though the identity guard does not touch `email`. Do not list `email`
there unless that overlap is intended.

Rollout mirrors feature 001: deploy in `log_only`, watch `revinners_jtl_guard_log` for
`observed_identity` rows (`SELECT field, current_value, attempted_value, email FROM revinners_jtl_guard_log WHERE action LIKE '%identity' ORDER BY created_at DESC`),
then switch `identityGuardMode` to `enforce`. As with feature 001, in `log_only` the identity
damage keeps happening — the connector still overwrites email/name while you observe — so keep
this window short; leaving `log_only` on longer does not protect any customer's identity.
Repairing already-swapped accounts is a separate data job (spec 002, "Out of scope").

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
  `custom_fields.<key>`. In `enforce`, a guarded key the customer did not have before is written back
  as JSON `null` (the DAL cannot remove a key), so `custom_fields` may accrete `"<key>": null`
  entries; harmless for the admin, which renders null as empty.
- **Addresses:** an update of an existing customer's address is guarded column by column with no
  allow-list (`blocked_address` / `observed_address`). A **new or deleted address** of an existing
  customer cannot be removed from the connector's write by the DAL, so it is recorded one row per
  column (`observed_address_create` / `observed_address_delete`); the customer's default address ids
  are customer columns and therefore stay, so a recorded new address never becomes the default.
  With `addressCreateDeletePolicy=reject_write` **and** `fieldGuardMode=enforce` the whole connector
  write is rejected instead (`rejected_write`). That fails every customer in the same sync batch, so
  keep the default `log` unless the log shows creates/deletes actually happening.
  `fieldGuardEnabled` is read from the global config to decide whether address commands are
  inspected at all; a shop that disables it globally and re-enables it for one sales channel gets
  customer-column guarding but no address guarding on that channel.
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
identity guard disabled those three columns fall to 003 like any other column. With the identity
guard enabled and `identityGuardProtectName=off`, a connector name change is neither kept nor
recorded by any guard (002 skips it by policy and 003 leaves the identity columns to 002); use
`always` under the allow-list.

**Rollout:** the point of this feature is the observation window. Deploy in `log_only` for one or
two months: every `observed_*` row holds the value the connector replaced, keyed by customer and
column, which is the data needed to restore those accounts afterwards (repair is a separate job,
see spec "Out of scope"). Then switch `fieldGuardMode` to `enforce`. As with 001/002, in `log_only`
the damage keeps happening while you observe. A `rejected_write` DB row can be rolled back together
with the write it rejected; the channel file line is the reliable record for that action. Before
`enforce` on ducati-world24.com, run the custom-fields pre-check SQL from the spec there too.

**Upgrading an installed plugin in place:** replace the files, then `bin/console cache:clear` →
`plugin:refresh` → `plugin:update ShopwareJtlConnectorGuardPlugin` → `cache:clear`. Running
`plugin:refresh` against a warm container compiled from the previous version fails with a
constructor TypeError (`Argument #6 ($fieldGuard) must be of type FieldGuard`) and, on production,
breaks every customer write until the cache is cleared.

## Development

```bash
composer install
vendor/bin/phpunit
```

Unit tests mock the plugin's `final` services, so `dg/bypass-finals` is enabled dev-only in `tests/bootstrap.php`.

Local shop integration: copy the plugin into `custom/plugins/ShopwareJtlConnectorGuardPlugin` of the
shop checkout, then `bin/console plugin:refresh && bin/console plugin:install --activate ShopwareJtlConnectorGuardPlugin`.
**Upgrading an installed plugin in place:** replace the files, then `bin/console cache:clear` →
`plugin:refresh` → `plugin:update ShopwareJtlConnectorGuardPlugin` → `cache:clear`. Running
`plugin:refresh` against a warm container compiled from the previous version fails with a
constructor TypeError (`Argument #6 ($fieldGuard) must be of type FieldGuard`) and, on production,
breaks every customer write until the cache is cleared.
