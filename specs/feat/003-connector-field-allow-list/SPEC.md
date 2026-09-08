# 003 - Connector Field Allow-List (customer + address)

> Plugin: **ShopwareJtlConnectorGuardPlugin** (`revinners/shopware6-jtl-connector-guard`)
> Third feature in the plugin. Builds on **001** (number guard) and **002** (identity guard): same connector detection, same `log_only`/`enforce` pattern, same audit table. Reverses the model from "block a few named columns" to "allow one named column, guard everything else", and extends the guard to the address entity.
> Status: **READY — 2026-09-08. Open questions resolved by SQL pre-checks on yam-shop.de; see PLAN.md.**

---

## Overview

001 and 002 stop the connector from overwriting four columns of `customer`: `customer_number`, `email`, `first_name`, `last_name`. Everything else the connector sends still lands: `title`, `company`, `vat_ids`, `salutation_id`, `birthday`, default address ids, custom fields, and every column of `customer_address`.

The merchant's rule is simpler than a block list: **the only thing JTL-Wawi is allowed to change on an existing Shopware customer is the customer group.** Every other customer column and every address column is owned by Shopware.

This feature turns that rule into config. On a connector write to an **existing** customer, every changed column that is not on the allow-list is kept at its current value (`enforce`) or recorded with its current value (`log_only`). The same applies to updates of that customer's addresses. Because every non-allowed column is recorded together with its pre-write value, two months of `log_only` produce a complete per-field record of what the connector changed, which is the data needed to repair those accounts in Shopware afterwards.

Two shops in scope: **ducati-world24.com** and **yam-shop.de** (Shopware 6.6.10.x). Ship `log_only` first.

---

## Background

### Why a block list is not enough
- 001's `protectedFields` is a block list: a column is guarded only if someone lists it. Any column nobody thought of is silently overwritten. The Reischl/Kraft case (`/Users/macbookpro/Soft/jtl-sw-orders/corrupted-accounts.md`) and the 38 yam-shop.de identity swaps (spec 002) show that the connector writes whole records, not single fields.
- The merchant's intent is an allow-list with one entry. Expressing it as a block list of ~35 column names is fragile and has to be maintained on every Shopware upgrade.

### What the observation window is for
The user's model for this plugin: run in `log_only` for one or two months, record every connector change **with the value it replaced**, then restore the affected accounts from that record. 001 and 002 only record four columns, so the record is incomplete. Under an allow-list every non-allowed column that changes produces an audit row with `current_value` (the correct, pre-write value) and `attempted_value` (what Wawi sent). That is the repair source.

Caveat kept from spec 002: accounts corrupted **before** the plugin was installed are not in the log and still need the `order_customer` snapshot repair.

### What the DAL lets us do (verified against 6.6.10.x vendor source, 2026-09-08)
- `EntityWriteEvent` (`EntityWriteGateway::execute()`) exposes the live `WriteCommand` list. `WriteCommand::addPayload()` overwrites a payload key; a key can never be removed and a command can never be dropped from the list. `PreWriteValidationEvent` (dispatched later, inside the transaction) exposes the same list read-only.
- `WriteCommand::isValid()` is `count(payload) > 0` for insert/update and `count(primaryKey) > 0` for delete; neither can be driven to `false` from a subscriber.
- Therefore: an **UpdateCommand** can be neutralised column by column (write the current value back), an **InsertCommand** and a **DeleteCommand** can only be **logged** or made to **abort the whole write** by adding a violation to the write context. A connector sync batch is one DAL write, so aborting rejects every customer in that batch.

---

## Task — what the feature must do

### R1 — Allow-list on `customer` updates
- Config `allowedFields`, default `customer_group_id`. Storage column names, comma-separated, same parsing as 001's `protectedFields`.
- On a connector **update** of an existing customer, every column present in the payload that is **not** on the allow-list, **not** a bookkeeping column (R5), and whose value differs from the current row → `enforce`: write the current value back; `log_only`: record and let it through. One audit row per column.
- Columns already handled by 001 (`protectedFields`) or 002 (`email`, `first_name`, `last_name` per the identity policy) keep their existing actions and are never logged twice. 003 handles the remainder. Precedence: 001 → 002 → 003, using the existing `$handled` mechanism.
- Connector **inserts** of a new customer are not affected (a new customer's data legitimately comes from Wawi; 001 already remaps the number).

### R1a — Custom fields are guarded per key, not per column
- Custom field writes do not arrive as a column in an `UpdateCommand`. The DAL emits a separate `JsonUpdateCommand` (extends `UpdateCommand`, `getStorageName()` = `custom_fields`) whose payload keys are the **custom field keys**, merged into the JSON column with `JSON_SET`. 001 and 002 already receive these commands and ignore them (their column names never match a custom field key).
- 003 treats a `JsonUpdateCommand` on `customer` as one guarded item per key: config `allowedCustomFields` (comma-separated keys, default `anmerkung,hinweis_(intern)`, see open question 2). A key not on that list whose value differs from the current JSON value → `enforce`: write the current value back for that key (a missing key is written back as `null`); `log_only`: record. Audit `field` is `custom_fields.<key>`.
- The same applies to `JsonUpdateCommand` on `customer_address` (no allow-list; Q3 shows no address custom fields on yam-shop.de today).

### R2 — Address updates
- Subscribe to `customer_address` commands in the same `EntityWriteEvent`.
- **UpdateCommand** on an address whose `customer_id` is an existing customer: every changed column except bookkeeping columns → `enforce`: revert; `log_only`: record. There is no allow-list on addresses: the connector may change nothing on an address.
- **InsertCommand** whose `customer_id` belongs to a customer being **inserted** in the same write: ignore (new customer, new address).
- **InsertCommand** for an existing customer: cannot be dropped. Record it (one audit row per column, `attempted_value` filled, `current_value` empty). The customer's `default_billing_address_id` / `default_shipping_address_id` are guarded by R1, so the new address cannot become the default. It stays as an extra address the repair job can delete.
- **DeleteCommand** / **CascadeDeleteCommand**: cannot be blocked. Record the row being deleted (one audit row per column, `current_value` filled, `attempted_value` empty).
- Config `addressCreateDeletePolicy`: `log` (default) | `reject_write`. With `reject_write` in `enforce`, an address insert or delete for an existing customer adds a `WriteConstraintViolationException` to the write context so the whole connector write is rejected. Default `log` because a rejected sync batch stops every customer in that batch and the connector may retry it indefinitely. The merchant's stated preference (2026-09-08) is that the connector must not add or delete addresses at all; if the `log_only` window shows it happening, switch to `reject_write` and test the batch behaviour on the local shop first.

### R3 — Configuration (third card in the same `config.xml`, flat keys)
- `fieldGuardEnabled` (bool, default `true`) — master switch of 003, independent of 001 and 002.
- `fieldGuardMode` (`log_only` | `enforce`, default `log_only`) — one mode for customer columns and address columns.
- `allowedFields` (text, default `customer_group_id`).
- `allowedCustomFields` (text, default `anmerkung,hinweis_(intern)`; empty = every custom field key guarded).
- `addressCreateDeletePolicy` (`log` | `reject_write`, default `log`).
- Reuse 001's connector identification and per-sales-channel config inheritance.

### R4 — Audit
- Same `revinners_jtl_guard_log` table and Monolog channel. New actions: `blocked_field`, `observed_field` (customer columns); `blocked_address`, `observed_address` (address updates); `observed_address_create`, `observed_address_delete`; `rejected_write` (only with `reject_write`).
- `field` stays a storage column name. Address rows carry the address id in a new nullable column `entity_id BINARY(16)` and the entity name in a new column `entity VARCHAR(32)` (default `customer`). One migration adding both columns; existing rows read as `customer`.
- Every row still carries `customer_id`, `email`, `first_name`, `last_name` of the customer as they were before the write, so the repair can be keyed per customer.
- Values longer than 255 characters (`custom_fields`, `vat_ids`, `newsletter_sales_channel_ids`) are stored JSON-encoded and truncated with a `…` marker; the log line in the channel file carries the full value.

### R5 — Bookkeeping columns (never guarded, never logged)
`updated_at`, `updated_by_id`, `created_at`, `created_by_id`, `auto_increment`, `version_id` (if present). Shopware writes these on every update and they carry no merchant data. Everything else, including `password`, `hash`, `custom_fields`, `tag_ids`, `active`, `guest`, `remote_address`, is guarded: the observation window will show whether the connector ever touches them, which is the point of `log_only`.

### R6 — Safety / non-goals (unchanged from 001/002)
- Never guard admin-user, storefront, CLI or non-connector writes; fail safe to "do nothing".
- Never abort the write except under the explicit `reject_write` policy.
- One failing command must not stop the others in the batch (per-command try/catch, as in 001).
- No data repair in this feature. The restore command that replays the audit log is a separate feature (004) and must run only after 001, 002 and 003 are in `enforce`.

---

## Technical notes
- Same subscriber and event. `guard()` currently fetches only `customer` commands; extend it to also fetch `customer_address` commands and to collect the ids of customers inserted in the same write (for the R2 skip rule).
- `JsonUpdateCommand` must be checked **before** the plain `UpdateCommand` branch (`instanceof` order), otherwise its custom-field keys would be compared against `customer` columns.
- New `CustomerAddressStateLoader` (`SELECT * FROM customer_address WHERE id IN (...)`), same shape as `CustomerStateLoader`. Address updates need the parent customer's state too (sales channel for config, email/name for the audit row): load customers by the address rows' `customer_id`.
- The allow-list step runs after 001 and 002 in `guardUpdate()` and receives the same `$sent` snapshot and `$handled` list.
- `GuardLogger::message()` gains cases for the new actions. `GuardLogEntity`/`GuardLogDefinition` gain `entity` and `entityId` so the admin search API exposes them.
- Config parsing: `allowedFields` reuses `GuardConfigProvider::splitList()`. `customer_group_id` is always included so a blank value cannot lock the connector out of the one thing it is allowed to do.
- Interplay with `protectedFields`: under an allow-list the 001 block list is redundant except for `customer_number`. Keep it working; document that leaving it at the default is expected.

## Test plan (mirror 001/002's live proof on the local yam-shop shop)
1. `log_only`: connector PATCH changing `title`, `company`, `vat_ids` and `customer_group_id` on an existing customer → group applied, the other three applied and recorded as `observed_field` with the pre-write values.
2. `enforce`: same write → group applied, the other three kept, three `blocked_field` rows.
3. `enforce`: address update (street, zipcode) via the connector's `/api/_action/sync` batch → both kept, two `blocked_address` rows; the customer's group in the same batch still applied.
4. Address insert for an existing customer, policy `log` → address created, not default, `observed_address_create` rows; customer `default_billing_address_id` in the same write kept.
5. Address insert, policy `reject_write`, `enforce` → whole write rejected, one `rejected_write` row.
6. Connector customer insert with an address → nothing from 003 logged; 001 remaps the number as before.
7. Regression: admin user, storefront profile edit, second integration → untouched, no rows. 001 and 002 behaviour unchanged with 003 disabled.

## Out of scope / follow-up
- **004 — restore from audit log.** Console command: per customer, take the earliest row per column since install, diff against the live row, dry-run and apply as an admin-context write. Address inserts recorded by 003 are listed for deletion. Must run only when all guards are in `enforce`.
- **Pre-install damage** (38 yam-shop.de accounts, Ducati unswept) — `order_customer` snapshot repair, as in spec 002.
- **The stale link in JTL's mapping** (Wawi customer → wrong Shopware UUID). Not fixable from Shopware. The `blocked_field`/`blocked_identity` rows are the worklist for the merchant.

## Open questions
1. ~~Does the connector ever send address inserts or deletes for existing customers?~~ **Pre-checked 2026-09-08 on yam-shop.de:** Q5 (addresses added later on accounts that never had a storefront session) returned **zero rows**, so the connector has not been adding addresses to existing customers. Deletes cannot be checked retroactively. The merchant does not want either. Keep `addressCreateDeletePolicy=log` as the default and read the `observed_address_create` / `observed_address_delete` rows during the `log_only` window; switch to `reject_write` only if any appear.
2. ~~Does the connector write `custom_fields` on `customer`?~~ **Answered 2026-09-08 on yam-shop.de (Q1/Q2 below):** yes. The connector owns the custom field set `custom_jtl` (assigned to customers, orders, products, categories) with the keys `anmerkung`, `hinweis_(intern)` and `paypalexpresspayerid`. `hinweis_(intern)` is on 133 customers last written without an admin user, 103 of them with no `remote_address` (never a storefront session), `anmerkung` on 46/34. Those are Wawi's own customer notes ("Anmerkung", "Hinweis (intern)") pushed into Shopware. The other keys (`payPalExpressPayerId`, `swag_amazon_pay_account_id`, `oauth2-*`, `revinners_price_display_standard`) belong to shop plugins. **Decision (2026-09-08, recommendation accepted by default):** allow the two Wawi note keys `anmerkung` and `hinweis_(intern)` — nobody edits them in Shopware and they only ever come from Wawi. `paypalexpresspayerid` and every other key stay guarded. Ducati not checked yet: run Q1/Q2 there before `enforce`.
3. ~~Is `customer_group_id` really the only column?~~ **Decided 2026-09-08:** yes. `vat_ids`, `account_type`, `requested_customer_group_id` stay guarded; the connector owns only `customer_group_id`.

## Pre-checks by SQL (2026-09-08)

Connector writes are the only Admin API writes with `created_by_id` / `updated_by_id` NULL on `customer` (storefront registrations also have NULL, but carry `remote_address`).

```sql
-- Q1: which custom_fields keys exist on customers at all, and how many rows carry them
SELECT JSON_KEYS(custom_fields) AS keys_, COUNT(*) AS customers
FROM customer
WHERE custom_fields IS NOT NULL AND JSON_LENGTH(custom_fields) > 0
GROUP BY keys_ ORDER BY customers DESC;

-- Q2: same, restricted to rows last written without an admin user (connector or storefront)
SELECT JSON_KEYS(custom_fields) AS keys_, COUNT(*) AS customers,
       SUM(remote_address IS NULL) AS without_remote_address
FROM customer
WHERE custom_fields IS NOT NULL AND JSON_LENGTH(custom_fields) > 0
  AND updated_by_id IS NULL
GROUP BY keys_ ORDER BY customers DESC;

-- Q3: custom_fields on addresses
SELECT JSON_KEYS(custom_fields) AS keys_, COUNT(*) AS addresses
FROM customer_address
WHERE custom_fields IS NOT NULL AND JSON_LENGTH(custom_fields) > 0
GROUP BY keys_;

-- Q4 result (yam-shop.de, 2026-09-08): 200+ rows, inconclusive — looks like storefront customers
--     adding delivery addresses. Q5 narrows it to customers who never had a storefront session.
-- Q5: addresses added later on accounts that never logged in (all their data came through the API)
SELECT LOWER(HEX(c.id)) AS customer_id, c.customer_number, c.email,
       c.created_at AS customer_created, a.created_at AS address_created,
       a.first_name, a.last_name, a.street, a.city,
       (SELECT COUNT(*) FROM customer_address x WHERE x.customer_id = c.id) AS address_count
FROM customer_address a
JOIN customer c ON c.id = a.customer_id
WHERE c.updated_by_id IS NULL
  AND c.remote_address IS NULL AND c.last_login IS NULL
  AND a.created_at > c.created_at + INTERVAL 1 DAY
  AND a.created_at > '2026-01-23'      -- after the jtl-sw-orders export window
ORDER BY a.created_at DESC
LIMIT 200;

-- Q4: addresses created more than a day after their customer, on accounts last touched
--     without an admin user. Storefront users add addresses too, so this is a hint, not proof.
SELECT LOWER(HEX(c.id)) AS customer_id, c.customer_number, c.email,
       c.created_at AS customer_created, a.created_at AS address_created,
       a.first_name, a.last_name, a.street, a.city
FROM customer_address a
JOIN customer c ON c.id = a.customer_id
WHERE c.updated_by_id IS NULL
  AND a.created_at > c.created_at + INTERVAL 1 DAY
ORDER BY a.created_at DESC
LIMIT 200;
```

## Related
- Specs 001 and 002 in `specs/feat/`.
- ClickUp: parent `86cbe5c6t`; plugin subtask `86cbevjp5`.
- Prior investigation: `/Users/macbookpro/Soft/jtl-sw-orders/corrupted-accounts.md`.
