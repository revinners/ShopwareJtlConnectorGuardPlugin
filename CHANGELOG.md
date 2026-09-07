# Changelog

## 1.0.0 — 2026-09-07

- Feature 001: customer number write protection against the JTL-Connector
  (`log_only` by default, `enforce` blocks updates and remaps connector creates to the shop number range).
- Audit trail on Monolog channel `jtl_connector_guard` and table `revinners_jtl_guard_log`.
