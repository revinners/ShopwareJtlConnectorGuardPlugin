# Changelog

## 1.3.1 — 2026-10-05

- Fix: the reroute no longer transfers empty values, so the connector can no longer erase a value on the
  target account (to clear a field, do it in the shop).
  The connector does not send every field JTL-Wawi holds — on production it sent `vat_ids: []` for a
  customer whose Wawi record has a VAT id, and the reroute copied that onto his real account (restored by
  hand).
- Default `samePersonRerouteFields` is now `customer_group_id,first_name,last_name,company`;
  `salutation_id`, `title` and `vat_ids` stay supported but are off by default. A shop that already
  saved the setting keeps its value — check it after updating.
- 1.3.0 in production (yam-shop.de, 2026-10-05): verified with real pushes from JTL-Wawi in `log_only`
  (`observed_mismatch`) and in `enforce` (foreign account untouched, change rerouted to the account with
  the pushed e-mail, twice). The integration dropdown in the admin works and stores an id list.

## 1.3.0 — 2026-10-02

- Feature 004: same-person check — replaces the "only the customer group may change" rule of 003, which
  blocked the merchant's legitimate Wawi edits (addresses, name, company) and still let a wrongly linked
  customer's group through. On a connector update of an existing customer the e-mail decides: a write
  carrying the account's own e-mail (or no e-mail) is applied in full; a write carrying a different e-mail
  is another customer pushed onto this account, so every changed column, custom-field key and address of
  that customer in the write is kept (`enforce`) or recorded with the value it replaced (`log_only`).
  The customer number stays with the number guard. Own switches `samePersonGuardEnabled` (default on) /
  `samePersonGuardMode` (ships `log_only`).
- **Removed: feature 002 (identity guard, 1.1.0) and feature 003 (field allow-list, 1.2.0)** with their
  settings (`identityGuard*`, `fieldGuard*`, `allowedFields`, `allowedCustomFields`), services and audit
  actions (`*_identity`, `*_field`, `*_address`). The plugin had not been installed on any production
  shop; it now does exactly two things: number protection and the same-person check.
  `addressCreateDeletePolicy` stays and belongs to the same-person check. Stale `system_config` rows of
  the removed keys on a shop that had 1.1/1.2 installed are ignored.
- **Applied to the right account:** in `enforce`, a write kept away from a foreign account is applied
  to the single registered (non-guest) account with the e-mail the write carried — Wawi's data is right,
  only the account it addresses is wrong. None or several such accounts: nothing is written, the attempt
  is recorded. Transferred columns are configurable (`samePersonRerouteFields`, default group, salutation,
  title, name, company, VAT ids); customer number and e-mail never are. Switch `samePersonRerouteEnabled`
  (default on). Runs after the controller (`kernel.response`), through the DAL with a system context.
- **`enabled` is the master switch of the whole plugin now** (it used to switch only the number guard).
- Review fixes before release (2026-10-02): the person is judged per customer, not per command (a second
  command without an e-mail in the same batch no longer slips through); the reroute is queued from the
  write event's success callback, so a rolled-back write reroutes nothing; the reroute target is
  re-checked with the check's own e-mail comparison instead of trusting the DB collation; a failed
  reroute leaves a `reroute_skipped / write_failed` row; a different person's write that deletes the
  account's default address is rejected in enforce (it would leave a dangling default id); an address of
  a third customer can no longer be re-parented onto the flagged account; the selected integration is
  matched over every config scope; the double-opt-in `hash` is redacted in the audit trail; the debug
  line for every write of a non-selected integration is gone. Found by the end-to-end run: a
  switch set to `false` over `bin/console system:config:set` is stored as the string "false" and was read
  as on — switches now understand it.
- **Connector identification is explicit now:** the setting `integrationLabels` and all matching by
  integration name are gone. `integrationIds` is a dropdown of the shop's integrations in the plugin
  settings (the id is stored); with nothing selected the plugin does nothing. A shop upgrading from
  1.0–1.2 must select the integration once.
- New audit actions `blocked_mismatch` / `observed_mismatch` / `rerouted` / `reroute_skipped` (for the
  last two `assigned_value` holds the addressed account id resp. the reason). No migration.
- A genuine e-mail change made in Wawi is blocked with the rest of that write — change it in the Shopware
  admin (never guarded).
- Internal: `SamePersonGuard` and `CustomerRerouter` services; `AddressGuard` only acts for a customer the
  same write identified as a different person; `FieldGuard`, `FieldGuardConfig`, `IdentityGuardConfig` are gone. The subscriber gained a constructor argument, so the
  upgrade order in the README (`cache:clear` before `plugin:refresh`) applies.
- Verified end-to-end on the local yam-shop dev shop (Shopware 6.6.10.18, PHP 8.3, Apache/mod_php,
  `APP_ENV=prod`, real DAL and Admin API, a test integration) on 2026-10-01, and again on 2026-10-02 after
  the removals, the integration picker and the review fixes (see the end of this entry).
  **Same person** (own e-mail in another case): name, company, group, custom fields, an address update and
  a new address were all applied, the number was kept by 001, no 004 row. **Different person, enforce**
  (PATCH): e-mail, name, company, group, a custom field and the address in the same write were kept — six
  `blocked_mismatch` rows on the customer, two with `entity = customer_address` — and 001 kept the number.
  **Sync batch** with a different-person item and a same-person item: the first was kept, the second
  applied. **log_only:** applied, `observed_mismatch` rows with the pre-write values. **Other
  integration:** applied, no row. **Reroute:** the kept group/name/company/VAT ids landed on the registered
  account with the pushed e-mail (`rerouted` rows), a guest account with the same e-mail stayed untouched,
  a repeat changed nothing; a second registered account → `reroute_skipped / several_registered_accounts`;
  unknown e-mail → `reroute_skipped / no_registered_account`; `log_only` → nothing rerouted.
  A first version flushed on `kernel.terminate` and never ran on that shop — hence `kernel.response`.
  Not yet verified with a real push from JTL-Wawi.

## 1.2.0 — 2026-09-08 (tagged, never installed on production; superseded by 1.3.0)

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
- Verified end-to-end on the local yam-shop dev shop (Shopware 6.6.10.18, PHP 8.3, `APP_ENV=prod`, real DAL
  and Admin API, integration labelled `JTL Connector`), on one existing customer with one address.
  **Upgrade path:** 1.1.0 was installed first, then `plugin:update` ran migration `1789171200` — the table
  gained `entity` (default `customer`) and `entity_id`, and the `blocked_update` row written under 1.1.0
  read `entity = customer` afterwards. **Customer columns:** in `log_only` a PATCH with `title`, `company`,
  `vatIds` and `groupId` returned 204, applied all four and produced three `observed_field` rows carrying
  the pre-write values (all NULL) and none for `customer_group_id`; in `enforce` the same PATCH applied the
  group, kept `title`/`company`/`vat_ids` and produced three `blocked_field` rows. **Custom fields:**
  `hinweis_(intern)` was applied and `paypalexpresspayerid` guarded with one
  `blocked_field / custom_fields.paypalexpresspayerid` row — the guarded key stays in `custom_fields` as
  JSON `null` (the `JSON_SET` writes the reverted null back) rather than disappearing. **Addresses:** an
  address update inside the connector's own `POST /api/_action/sync` batch kept `street` and `zipcode`
  (two `blocked_address` rows with `entity = customer_address` and the address id) while the group of the
  same batch was applied; an address create in that batch under policy `log` created the address and
  recorded eight `observed_address_create` rows (one per non-null column) with the attempted values, and
  the `defaultBillingAddressId` the connector sent in the same write was blocked as a customer column, so
  the new address never became the default; `DELETE /api/customer-address/{id}` returned 204 and recorded
  eight `observed_address_delete` rows with the previous values. **`reject_write`:** the same create sync
  failed with HTTP 400 `FRAMEWORK__WRITE_CONSTRAINT_VIOLATION` ("the connector may not create or delete
  addresses of existing customers (addressCreateDeletePolicy=reject_write)"), no address was created, and
  both the channel line **and** the `rejected_write` DB row survived the rollback of that write.
  **Connector create:** a sync upsert of a brand-new customer carrying one address returned 200, logged
  nothing from 003, and 001 still remapped the connector number `51520` to `10011` from the shop range.
  **Regression:** the same PATCH through a second integration (`Other API client`) and through an admin
  user applied every field and produced no audit row (only the "not identified as the connector" debug
  line for the integration), and in the same build 001 still kept `customer_number` and 002 still kept
  `email` and `first_name` on an attempted email swap. No plugin change was needed — all eleven steps
  matched the expectation.

## 1.1.0 — 2026-09-07

- Feature 002: customer identity write protection — a connector update can no longer replace an
  existing customer's `email` (and `first_name`/`last_name` when paired with the email swap, or
  always/never per `identityGuardProtectName`). Own switches `identityGuardEnabled` /
  `identityGuardMode` (ships `log_only`), independent of the number guard.
- New audit actions `blocked_identity` and `observed_identity` on the same table and channel.
- No migration.
- Verified end-to-end on the local yam-shop dev shop (Shopware 6.6.10.18, PHP 8.3, `APP_ENV=prod`, real DAL
  and Admin API, integration labelled `JTL Connector`): `observed_identity` in `log_only` (the connector's
  e-mail was applied and recorded); `blocked_identity` in `enforce` over `PATCH /api/customer/{id}` for
  `email` alone (the `title` of the same write was still applied), for `email` + `first_name` + `last_name`
  together (three rows, all three values kept), and over the connector's own `POST /api/_action/sync` batch
  swapping two customers' e-mails (both kept, two rows); a name-only change was applied and recorded as
  `observed_identity` under the default `on_email_swap` policy, kept under `always`, and neither guarded nor
  recorded under `off`. Writes from a second Admin API integration and from an admin user changed e-mail and
  name and produced no audit row (only the "not identified as the connector" debug line). The 001 number
  guard still blocked a `customer_number` update in the same build.
- Fix (final review): when `email` is also listed in the 001 `protectedFields`, the email swap the write
  represents was silently lost for policy purposes as soon as 001 owned the field — under `on_email_swap`
  a name swapped in the same write was merely observed instead of reverted. The swap signal is now computed
  independently of which fields 001 already handled and always drives the name policy; 001 still owns
  applying/reverting and logging the email itself, so a field is never double-handled.
- Docs: README now states that `blocked_identity` / `observed_identity` are also routine lines that will
  not reach `prod.log` under the `fingers_crossed` main handler, spells out the `log_only` rollout warning
  for feature 002 (the identity damage keeps happening while you observe), and adds a caveat that listing
  `email` in the 001 `protectedFields` hands ownership of the email to the 001 guard.

## 1.0.1 — 2026-09-07

- Fix: error paths no longer depend on the channel logger. `GuardLogger` and
  `CustomerNumberWriteProtection` now fall back to Shopware's main `logger` service when the
  `jtl_connector_guard` channel logger itself fails (e.g. its log file cannot be opened), so a
  broken channel logger can never throw into the customer DAL write.
- Docs: README corrected on where the audit trail actually lands (the `main` log is
  `fingers_crossed` in prod and only receives error lines, not the routine `blocked_update` /
  `remapped_create` entries) and the Rollout section now covers per-shop integration
  identification, the `log_only` observation window, staged rollout, PHP version requirements,
  and the release version/tag checklist.
- Test: `dg/bypass-finals` is now scoped to this plugin's own `src/` tree in
  `tests/bootstrap.php` instead of rewriting every class encountered by the test process.

## 1.0.0 — 2026-09-07

- Feature 001: customer number write protection against the JTL-Connector
  (`log_only` by default, `enforce` blocks updates and remaps connector creates to the shop number range).
- Audit trail on Monolog channel `jtl_connector_guard` and table `revinners_jtl_guard_log`.
- Verified end-to-end on the local yam-shop dev shop (Shopware 6.6.10.18, PHP 8.3, real DAL and Admin API):
  `blocked_update` in `log_only` (connector value applied, attempt recorded) and in `enforce` (number kept,
  the other fields of the same write applied), over both `PATCH /api/customer/{id}` and the connector's own
  `POST /api/_action/sync` batch path; `remapped_create` in `enforce` (connector number `51520` replaced by
  `10012` from the shop's `customer` number range). Writes from a second Admin API integration and from an
  admin user changed the number and produced no audit row. Detection matched the real label `JTL Connector`
  against the configured `JTL-Connector`.
- Channel log lines now describe what actually happened per mode instead of always claiming the value was
  "kept" (found during that verification).
