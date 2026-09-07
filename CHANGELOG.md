# Changelog

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
