# 003 - QA plan: driving the guard from JTL-Wawi

> Manual QA of the released plugin (1.2.0) on a real shop, with the real JTL-Connector, triggered
> from JTL-Wawi. Complements the local API proof in PLAN.md Task 9, which exercised the DAL
> paths directly. This plan proves the same behaviour end to end: Wawi → JTL-Connector → Shopware.
>
> Shop: **yam-shop.de** first (integration `JTL Connector`, id `019b8946ccc67767b9fb8cb524300ba1`).
> Ducati follows the same plan after its own pre-checks.

---

## 0. Before you start

**Pick one test customer** that exists in both systems and is linked (Wawi shows it under the
`SW6-YamShop` sales channel with the Shopware customer number). Preferably an account with:
- at least one address,
- a value in *Titel* / *Firma* / *USt-IdNr* you can change and change back,
- no open orders.

Record its Shopware state before anything else (replace `<email>`):

```sql
SELECT LOWER(HEX(id)) AS id, customer_number, email, first_name, last_name, title, company,
       vat_ids, LOWER(HEX(customer_group_id)) AS group_id, custom_fields,
       LOWER(HEX(default_billing_address_id)) AS billing, LOWER(HEX(default_shipping_address_id)) AS shipping
FROM customer WHERE email = '<email>';

SELECT LOWER(HEX(a.id)) AS id, a.first_name, a.last_name, a.street, a.zipcode, a.city, a.company,
       a.phone_number, a.custom_fields
FROM customer_address a JOIN customer c ON c.id = a.customer_id WHERE c.email = '<email>';
```

**Confirm the plugin state** (Settings → Extensions → JTL-Connector Guard, or SQL):

```sql
SELECT configuration_key, configuration_value FROM system_config
WHERE configuration_key LIKE 'ShopwareJtlConnectorGuardPlugin.%' ORDER BY 1;
```

Expected for phase A: `mode` and `identityGuardMode` as currently rolled out (001/002),
`fieldGuardEnabled` = true, `fieldGuardMode` = `log_only`, `allowedFields` = `customer_group_id`,
`allowedCustomFields` = `anmerkung,hinweis_(intern)`, `addressCreateDeletePolicy` = `log`,
`integrationIds` = `019b8946ccc67767b9fb8cb524300ba1`.

**How to read results** after every Wawi action (wait ~30 s for the push, then):

```sql
SELECT created_at, action, mode, entity, LOWER(HEX(entity_id)) AS entity_id, field,
       current_value, attempted_value
FROM revinners_jtl_guard_log
WHERE customer_id = UNHEX('<customer id>')
ORDER BY created_at DESC LIMIT 30;
```

and the channel file on the server: `tail -f var/log/jtl_connector_guard_prod.log`.

**Do not** use *Onlineshopdaten zurücksetzen* on the Wawi sales channel during QA. It unlinks
every customer and re-links on the next push; that is a separate, risky operation.

**How a push is triggered in Wawi**: any save of a linked customer pushes the whole record
(001's proof: a *Kundengruppe* change alone did it). The *Daten im Onlineshop aktualisieren*
button forces a full push without changing anything.

---

## Phase A — `fieldGuardMode = log_only` (observation, nothing is blocked)

Goal: prove the connector's writes are seen, classified correctly and recorded with the value
they replaced, while everything still applies exactly as before the plugin.

| # | In Wawi (customer form) | Expected in Shopware | Expected audit rows |
|---|---|---|---|
| A1 | Change **Kundengruppe** to another mapped group, save | group changes | **none** (allowed column) |
| A2 | Change **Titel** to `QA-Dr.`, save | title changes to `QA-Dr.` | 1× `observed_field / log_only / title`, `current_value` = old title, `attempted_value` = `QA-Dr.` |
| A3 | Change **Firma** and **USt-IdNr** in one save | both change | 2× `observed_field` (`company`, `vat_ids`), `vat_ids` rendered as JSON `["DE…"]` |
| A4 | Edit **Anmerkung** and **Hinweis (intern)**, save | `custom_fields` in Shopware shows the new texts | **none** (allowed custom-field keys) |
| A4b | If Wawi exposes it: change the PayPal payer id field of the `custom_jtl` set, save | applied (`log_only`) | 1× `observed_field / custom_fields.paypalexpresspayerid` — in `enforce` the key is written back as JSON `null` (verified locally), so afterwards it reads `"paypalexpresspayerid": null`, not absent |
| A5 | Edit the customer's **billing address street and ZIP**, save | address changes | 2× `observed_address / log_only`, `entity = customer_address`, `entity_id` = that address id, fields `street`, `zipcode` |
| A6 | Add a **second address** (Lieferadresse) to the customer, save | a new address row exists; the customer's default billing/shipping ids are unchanged | N× `observed_address_create` (one per non-empty column of the new address) — *if the connector pushes addresses at all; Q5 in the spec suggested it does not, so "no new address and no rows" is also a valid outcome — record which* |
| A7 | Delete that second address in Wawi, save | address gone (or still there if the connector never sent the delete) | N× `observed_address_delete` with `current_value` filled — or nothing; record which |
| A8 | Press **Daten im Onlineshop aktualisieren** without changing anything | nothing changes | **none** (values equal → no rows) |
| A9 | Change **E-Mail** to a different address, save | per 002's current mode: applied (`log_only`) or kept (`enforce`) | 002 rows (`observed_identity` / `blocked_identity`), and **no** `observed_field` row for `email` (owned by 002) |
| A10 | Change **Kundennummer Onlineshop**, save | per 001's current mode | 001 row (`blocked_update`), no 003 row for `customer_number` |

Pass criteria for phase A: every row in A2, A3, A5 carries the correct pre-write value in
`current_value`; A1, A4, A8 produce no rows; nothing the connector sent was blocked.

Restore the customer in Wawi to its original values (Titel, Firma, USt-IdNr, address) and save.
The push applies them; the rows it produces are the mirror image of A2/A3/A5 (again observed).

---

## Phase B — `fieldGuardMode = enforce` (the guard holds)

Switch only the 003 mode (`system:config:set ShopwareJtlConnectorGuardPlugin.config.fieldGuardMode enforce`
+ `cache:clear`, or the admin UI). Leave 001/002 as they are. Repeat the actions:

| # | In Wawi | Expected in Shopware | Expected audit rows |
|---|---|---|---|
| B1 | Change **Kundengruppe**, save | group changes | none |
| B2 | Change **Titel** to `QA-Prof.`, save | **title unchanged** in Shopware | 1× `blocked_field / enforce / title`, `attempted_value` = `QA-Prof.` |
| B3 | Change **Firma** + **USt-IdNr** + **Kundengruppe** in one save | group changes, company and VAT id unchanged | 2× `blocked_field`, none for the group |
| B4 | Edit **Anmerkung**, save | applied | none |
| B5 | Edit billing address **street**, save | **address unchanged** | 1× `blocked_address / enforce / street` |
| B6 | Add a second address, save | if the connector pushes it: the address exists but is **not** the default; if not: nothing | `observed_address_create` rows or none — same as A6 |
| B7 | Press **Daten im Onlineshop aktualisieren** | nothing changes (Wawi still holds `QA-Prof.` etc., so the push re-attempts them) | the same `blocked_field` / `blocked_address` rows again — this is expected: Wawi re-sends its stale values on every push and the guard keeps blocking them |
| B8 | Change **E-Mail** and **Nachname** in one save | per 002 (`enforce`: both kept; `on_email_swap` policy) | 002 rows only |
| B9 | In Shopware admin, change the same customer's **title** by hand | applied | none (admin writes are never guarded) |
| B10 | In the storefront, log in as the customer and change the **address** | applied | none |

Pass criteria for phase B: B2, B3, B5 keep the Shopware value and log `blocked_*`; B1, B4
apply; B9, B10 are untouched by the guard.

**Important consequence to show the merchant (B7):** while Wawi holds a value that differs from
Shopware, every push will be blocked and logged again. The audit table grows by one row per
blocked column per push. That is by design; the fix is to correct the value in Wawi.

Restore the test customer in Wawi to the original values and save; in `enforce` the restore is
blocked too (title etc.), so the Shopware side is already correct and Wawi is now back in sync.

---

## Phase C — connector-created customer (001 + 003 together)

| # | In Wawi | Expected in Shopware | Expected audit rows |
|---|---|---|---|
| C1 | Create a new customer with a full address, assign it to the shop sales channel, save | customer created with all fields and the address; customer number from the shop's own range (001) | 1× `remapped_create` (001); **no** 003 rows (new customer, new address) |
| C2 | Change the new customer's Titel in Wawi, save | kept (B2 behaviour) | `blocked_field` |

Delete the test customer afterwards in Shopware admin (and in Wawi).

---

## Phase D — optional: `addressCreateDeletePolicy = reject_write`

Only if phase A/B showed the connector actually creating or deleting addresses (A6/A7 produced rows).

1. Set `addressCreateDeletePolicy` to `reject_write` (003 must be in `enforce`), `cache:clear`.
2. Add an address in Wawi, save. Expected: the push **fails** in Wawi (sync error on that
   customer), no new address in Shopware, one `rejected_write` line in the channel log and,
   as verified locally, the DB row survives the rollback too (the audit insert commits
   independently of the rejected write).
3. **Watch the connector**: does it retry the same batch, and does the failure block other
   customers in the same sync run? Record the Wawi-side error text.
4. Set the policy back to `log`.

If the connector never sends address creates/deletes, skip this phase and leave the policy on `log`.

---

## Acceptance

- Phase A complete with correct `current_value` on every observed row → the observation window
  can start on production (keep it at 1–2 months, then run phase B before switching).
- Phase B complete → `fieldGuardMode = enforce` can stay on.
- Any deviation: capture the audit rows and the channel lines, note the Wawi action, and file it
  against the plugin with the customer id.

## Cleanup

- Restore the test customer's Titel/Firma/USt-IdNr/address in Wawi if not already done.
- Delete the phase C customer.
- Leave the audit rows in place (they are evidence); they can be pruned later by `created_at`.
