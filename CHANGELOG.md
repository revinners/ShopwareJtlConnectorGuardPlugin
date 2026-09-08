# Changelog

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
