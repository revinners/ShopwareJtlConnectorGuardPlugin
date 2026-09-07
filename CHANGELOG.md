# Changelog

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
