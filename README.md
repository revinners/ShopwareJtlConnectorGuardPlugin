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

Unit tests mock the plugin's `final` services, so `dg/bypass-finals` is enabled dev-only in `tests/bootstrap.php`.

Local shop integration: copy the plugin into `custom/plugins/ShopwareJtlConnectorGuardPlugin` of the
shop checkout, then `bin/console plugin:refresh && bin/console plugin:install --activate ShopwareJtlConnectorGuardPlugin`.
