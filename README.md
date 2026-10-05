# ShopwareJtlConnectorGuardPlugin

`revinners/shopware6-jtl-connector-guard` — a container plugin for every fix we apply on top of the
JTL-Connector (JTL-Wawi → Shopware). Shops: **yam-shop.de**, **ducati-world24.com** (Shopware 6.6.10.x).
It does two things: **customer number write protection** and the **same-person check** (with applying a
misdirected change to the right account). The earlier identity guard (1.1.0) and field allow-list (1.2.0)
were removed in 1.3.0 — the plugin had never been installed on a production shop, and the same-person
check covers what they were for.

## Customer number write protection

The JTL-Connector pushes Wawi's "Kundennummer Onlineshop" into `customer.customer_number` on every
customer change, creating duplicate numbers (see `specs/feat/001-customer-number-write-protection/SPEC.md`).
This plugin makes Shopware the owner of the number:

- **Existing customers:** a connector write that would change `customer_number` (or any other column
  listed in *Protected customer columns*) has that column reverted to the current value; every other
  field of the same write is left to the same-person check below.
- **Connector-created customers:** the supplied number is replaced by one reserved from the shop's own
  `customer` number range (for the customer's sales channel), exactly like a storefront registration.
- **Audit trail:** every intervention is written to the Monolog channel `jtl_connector_guard`
  (`var/log/jtl_connector_guard_<env>.log`) **and** to the table `revinners_jtl_guard_log`
  (DAL entity `revinners_jtl_guard_log`, searchable via `POST /api/search/revinners-jtl-guard-log`).
  Those two are the reliable sinks. Shopware's prod Monolog config runs the `main` handler as
  `fingers_crossed` with `action_level: error`, so an `info` line only reaches `prod.log` if an
  *error* also happens in the same request — the guard's routine `blocked_update` /
  `remapped_create` / `blocked_mismatch` / `rerouted` lines will not show up there. Only the guard's
  own `error` lines (an internal failure, or a logging sink that itself failed — see *Implementation notes*) are
  expected to land in the main log; treat `jtl_connector_guard_<env>.log` and the DB table as
  the sources of truth for auditing.

### How connector writes are identified

The connector authenticates as an Admin API **integration** (client credentials), so its writes carry
an `AdminApiSource` with an integration id and no user id. The plugin guards only writes of the
integration(s) **selected in its settings** (`integrationIds`, a dropdown of the shop's integrations —
the stable integration id is stored, so renaming the integration or regenerating its access key changes
nothing). There is no matching by name. **With nothing selected the plugin does nothing.** Anything
else — admin users, storefront, CLI, imports, other integrations — is never touched.

### Configuration (Settings → Extensions → JTL-Connector Guard)

Switches and modes are read per sales channel (of the customer). The integration selection is not:
every integration selected in any scope counts for the whole shop, so picking it with a sales channel
selected in the settings cannot leave the plugin idle.

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | **master switch of the whole plugin**, including the same-person check |
| `mode` | `log_only` | customer number protection: `log_only` = observe and log, change nothing; `enforce` = block / remap |
| `integrationIds` | *(empty)* | **required** — the connector's integration(s), picked from a dropdown in the admin (stored as a list of 32-char hex ids; a comma-separated string set over the CLI works too) |
| `protectedFields` | `customer_number` | storage columns of `customer` the connector may not change (customer_number always included) |

### Rollout

1. Install and activate, then open the plugin settings and **select the connector's integration** —
   until that is done the plugin guards nothing:
   - **yam-shop.de:** `JTL Connector` (id `019b8946ccc67767b9fb8cb524300ba1`).
   - **ducati-world24.com:** `JTL-Connector`; check Settings → System → Integrations.
   Over the CLI: `bin/console system:config:set ShopwareJtlConnectorGuardPlugin.config.integrationIds <id>`.
2. Both parts start in `log_only`. In this mode the guard only *observes*: Wawi still overwrites the
   account while you watch, nothing is blocked and nothing is rerouted. Keep this window short — one
   real push is enough to confirm the connector is recognised — because `log_only` protects nobody.
3. Trigger a push (change a customer's Kundengruppe in Wawi) and check the log / table: a
   `blocked_update` row if the number differs, `observed_mismatch` rows if the push carried another
   customer's e-mail. No rows at all for a push you know happened means the integration is not selected.
4. Switch both modes to `enforce`: `mode` (customer number) and `samePersonGuardMode` (same-person
   check). Repeat the push: the addressed account must stay as it is, and the change must arrive on the
   account with the pushed e-mail (`rerouted` rows).
5. Repair the accounts whose e-mail was already swapped before the plugin was live — they are not
   protected until then (see *Things to know* below).
6. Roll out to **one shop first**. Watch `revinners_jtl_guard_log` and the channel file
   (`var/log/jtl_connector_guard_<env>.log`) for a few days of real traffic before installing on
   the second shop.
7. Both shops require PHP >= 8.2 (see `composer.json`). yam-shop.de is verified on PHP 8.3; check
   the running PHP version on ducati-world24.com before install.
8. Release checklist: when releasing, bump `version` in `composer.json` and create the git tag
   with the same value — Composer ignores a tag whose `composer.json` version disagrees.

### Implementation notes

- Hook: `Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent`, dispatched by the DBAL
  `EntityWriteGateway` with the live `WriteCommand` objects. The only mutation API is
  `WriteCommand::addPayload()` (`@internal`): a payload key cannot be removed, so we overwrite it.
  Re-verify this on every Shopware minor upgrade.
- The audit row is inserted with plain DBAL inside the event (no nested DAL write) and can never
  break the customer write.
- Both the channel logger call and the DB insert are wrapped independently, and every error/warning
  line the guard emits falls back to Shopware's main `logger` service if the `jtl_connector_guard`
  channel itself cannot be written (e.g. its log file cannot be opened). No failure of either
  logger can propagate out of `onEntityWrite()` and into the DAL write.
- Custom-field writes arrive as a separate `JsonUpdateCommand` (payload keyed by custom-field key); the
  number guard ignores it, the same-person check guards it per key for a different person.

## Same-person check

The merchant edits customers in JTL-Wawi and wants those edits in the shop; what must never happen is
Wawi writing customer A onto customer B's account, which it does whenever its link points at the wrong
shop account (44 confirmed accounts on yam-shop.de, see `specs/feat/004-same-person-check/SPEC.md`).
The e-mail in the connector's write decides:

- **Same e-mail as the account** (case-insensitive, trimmed), or no e-mail in the write: the same
  person. Everything is applied — addresses, name, company, VAT id, group, custom fields — and nothing
  is logged. Only `customer_number` stays protected, by the number guard.
- **A different e-mail**: a different person. In `enforce` nothing of that write lands: every changed
  customer column (including the group), every custom-field key and every address of that customer in
  the same write is kept. In `log_only` it is applied and each replaced value is recorded. Actions
  `blocked_mismatch` / `observed_mismatch`; address rows carry `entity = customer_address` and the
  address id.

| Key | Default | Meaning |
|---|---|---|
| `samePersonGuardEnabled` | `true` | switch of the same-person check (the plugin's master switch is `enabled`, above) |
| `samePersonGuardMode` | `log_only` | `log_only` = record and apply; `enforce` = keep the account as it is |
| `samePersonRerouteEnabled` | `true` | enforce only: apply the kept write to the registered account with the e-mail it carried |
| `samePersonRerouteFields` | `customer_group_id,first_name,last_name,company` | columns that are transferred. `salutation_id`, `title`, `vat_ids`, `account_type` are supported but off by default: the connector sends them empty even when Wawi holds a value |
| `addressCreateDeletePolicy` | `log` | a different person's write that creates/deletes an address: `log` = record it; `reject_write` = fail the whole write (enforce only) |

**Applied to the right account.** JTL-Wawi's data about the customer is correct; only the shop account
it addresses is wrong (on yam-shop.de: old JTL-Shop key − 40494 = `auto_increment` of the account that
gets hit). So in `enforce` the kept write is applied, right after the connector's request, to the **single
registered (non-guest) account whose e-mail equals the one in the write**: actions `rerouted`, one row
per changed column, `customer_id` = the account that was updated, `assigned_value` = the account the
connector addressed. If no such account exists, or more than one, nothing is written and one
`reroute_skipped` row records the e-mail and the reason (`no_registered_account` /
`several_registered_accounts` / `write_failed`). The target must carry exactly that e-mail (case and
surrounding spaces aside) — the database's looser collation (`é` = `e`) is not trusted. Customer number
and e-mail are never transferred. Guest accounts are never a target. **An empty value in the write is never
rerouted, so it cannot erase a value the target account has** — the connector does not send every field Wawi holds (seen on
production: an empty VAT id list for a customer with a VAT id in Wawi). This keeps group and master-data changes in the ERP working while the links are wrong.

Things to know:

- **A genuine e-mail change made in Wawi is blocked** together with the rest of that write. Change the
  e-mail in the Shopware admin instead — admin writes are never guarded.
- A different-person write that **creates or deletes an address** cannot have that command removed (the
  DAL offers no way): it is recorded per column (`observed_address_create` / `observed_address_delete`)
  and the default address ids are kept, so the foreign address never becomes the default — or the whole
  write is rejected with `addressCreateDeletePolicy=reject_write` (enforce only; that fails every
  customer in the same sync batch).
- An **address-only write** carries no e-mail and is applied.
- Run the number guard in `enforce` alongside. If it is still `log_only`, a different-person write has
  its number kept by the same-person check anyway, but a same-person write may still change the number.
- **Who is guarded:** only writes of the Admin API integration(s) selected in the settings. Any other
  integration, admin users, the storefront and the CLI are never touched.
- **Not logged:** same-person writes, which are simply applied.
- **Several commands for one customer in one write** (a sync batch) are judged together: one command
  with a foreign e-mail makes all of them a different person's write.
- **The reroute happens only after the connector's write went through.** If the write fails and is
  rolled back, nothing is rerouted; the `blocked_*` rows already written then describe an attempt (a
  `warning` line in the channel log says so).
- **Deleting the account's default address** in a different person's write is always rejected in
  `enforce` (whole write fails): the default address ids are kept, so the account would otherwise point
  at an address that no longer exists.
- **An address of a third customer** cannot be moved onto the account by such a write either.
- **A customer who changes their e-mail in the shop** keeps the old one in Wawi. From then on every Wawi
  edit of that customer is a "different person" here: blocked, and `reroute_skipped /
  no_registered_account`. Update the e-mail in Wawi as well; such rows in the log are the signal.
- **Not guarded:** tags (`customer_tag`) and other child entities sent with a different person's write,
  and a connector DELETE of a customer.
- **The log holds personal data** (e-mails, names, address columns, VAT ids) and is not pruned
  automatically; the audit entity can be written through the Admin API by an admin.
- `log_only` protects nobody; keep it only until one real push shows up in the log.
- **An account whose e-mail is already swapped is not protected:** the write carries "its" e-mail. Repair
  such accounts first; until then they also make the pushed e-mail ambiguous (`reroute_skipped`).

## Development

```bash
composer install
vendor/bin/phpunit
```

Unit tests mock the plugin's `final` services, so `dg/bypass-finals` is enabled dev-only in `tests/bootstrap.php`.

Local shop integration: copy the plugin into `custom/plugins/ShopwareJtlConnectorGuardPlugin` of the
shop checkout, then `bin/console plugin:refresh && bin/console plugin:install --activate ShopwareJtlConnectorGuardPlugin`.
**Coming from 1.0–1.2:** `integrationLabels` is gone and `integrationIds` used to be a text field; select
the integration once in the settings (or delete the stale `system_config` row first if the dropdown shows
nothing sensible).

**Upgrading an installed plugin in place:** replace the files, then `bin/console cache:clear` →
`plugin:refresh` → `plugin:update ShopwareJtlConnectorGuardPlugin` → `cache:clear`. Running
`plugin:refresh` against a warm container compiled from the previous version fails with a
constructor TypeError (the subscriber's constructor changes between versions) and, on production,
breaks every customer write until the cache is cleared.
