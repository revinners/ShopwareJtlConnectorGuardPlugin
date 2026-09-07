# 001 - Customer Number Write Protection

> Plugin: **ShopwareJtlConnectorGuardPlugin** (`revinners/shopware6-jtl-connector-guard`)
> First feature inside the plugin. The plugin is a container scoped to the JTL-Connector; this spec covers only the customer-number protection. Later connector fixes are added as additional subscribers in the same plugin.
> Status: **IMPLEMENTED — released as 1.0.0 (2026-09-07), 1.0.1 pending; see PLAN.md.**

---

## Overview

The JTL-Connector (JTL-Wawi → Shopware) overwrites existing customers' `customer_number` in Shopware with numbers coming from JTL-Wawi. Because those numbers come from the same old-shop numbering space as our migrated customers, the pushed number lands on top of a migrated account and creates duplicate customer numbers. This is an **ongoing** leak (~1–2 new collisions/month per shop), separate from the historic backlog which has already been cleaned by SQL.

This plugin makes **Shopware the owner of the customer number**. It intercepts customer writes that originate from the JTL-Connector API integration and:

1. **Blocks any change to `customer_number` on an existing customer**, while letting every other field in the same write through (customer group, addresses, etc.).
2. **On connector-created new customers**, prevents a colliding/foreign number from being written — Shopware assigns the number from its own number range instead.
3. **Logs every blocked or remapped attempt** so the behaviour is auditable and we can see it working.

It must be safe on two production shops: **ducati-world24.com** and **yam-shop.de** (both Shopware 6.6.10.x).

---

## Background — how we got here (findings)

### The two directions of the JTL-Connector (verified against JTL docs + live behaviour)
- **Shopware → Wawi (pull, automatic):** on the JTL-Worker interval Wawi pulls only customers it has **not linked yet**. Linking is by the Shopware customer **UUID** in the connector mapping table, never by the number. A linked customer is never pulled again. Orders match existing Wawi customers by **email**.
- **Wawi → Shopware (push, on change):** when a linked customer changes in Wawi, Wawi sends its stored master data to Shopware and **overwrites** the record, **including `customer_number`** (the value of Wawi's "Kundennummer Onlineshop" field). The write arrives via the JTL-Connector Admin API integration, so in the DB `created_by_id` / `updated_by_id` are **NULL** (no admin user) — this is how connector writes are identified.
- There is **no Wawi setting** to exclude the number from the push. The customer group mapping and "Auftragsnummer aus dem Shop verwenden" are the only relevant switches; none of them controls the customer number.

### Root cause of the collisions
At migration, Shopware customers were numbered from the **old shop's numbering**: the real customer number (`cKundenNr`) where it existed, otherwise the old shop internal id (`kKunde`). JTL-Wawi holds the same numbers. Every push writes Wawi's number into Shopware, and because it comes from the same integer space as the migrated accounts, it collides.

### Proof #1 — Ducati (the reproducible test)
- Customer **Adam Erdösi** (UUID `019df771764772929f1136e52180ccf6`) shared number `10009` with migrated customer **Werner Herdegen** (`2103c0f8ba934cbdb291287aaa3b5ce8`).
- We set Erdösi to `C10009` in Shopware. Matthias changed Erdösi's **Kundengruppe** in Wawi → within seconds Shopware reverted `C10009` → `10009`, `updated_by_id` NULL. **The Kundengruppe change alone triggered a push that overwrote the number.**
- When Matthias first corrected Wawi's "Kundennummer Onlineshop" to `C10009` **and then** changed the group, the push wrote `C10009` (correct). So the push writes whatever Wawi holds in that field.
- Conclusion: keeping the number safe by correcting Wawi per-customer works but does not scale (thousands of customers, and returning customers keep re-triggering it). A Shopware-side guard is the durable fix.

### Proof #2 — Yamaha (the ongoing leak, verified against the old shop DB)
Yamaha's customer number range start was raised to 100000 on **2026-01-23** (go-live Jan 2026), which stopped **counter-driven** collisions. Yet **9 new collisions appeared Feb–Jul 2026**, all written by the connector (`created_by_id`/`updated_by_id` NULL). Shopware's counter physically cannot produce those low numbers (it only issues 100000+), so they came from the connector.

We looked the 9 up in the old Yamaha JTL-Shop DB (`yam-shop-jtl2`). Every overwrite number is a real old-shop number:

**Flavour A — returning customer got their OWN old number back (same-person collision):**
| Email | old-shop cKundenNr | overwritten to |
|---|---|---|
| schroeppel@imtec-gmbh.net | 2700 | 2700 |
| info@motorradgarage-dachau.de | 37430 | 37430 |
| verkauf@sauter-kfz.de | 51520 | 51520 |

**Flavour B — got a number belonging to a DIFFERENT old-shop customer (two unrelated people collide):**
| New account overwritten to | old-shop kKunde owner |
|---|---|
| 52858 Dietmar Zimmermann (dietmars-fs@t-online.de) | 52858 = Christian Schneider (schneider.ch@gmx.at) |
| 56162 Maik Jungmann | 56162 = Thilo |
| 56963 Basti Schröer | 56963 = Thomas |
| 57480 Michael Adler | 57480 = Marc |
| 58697 Jens Merkel | 58697 = Andre |
| 59726 Armin Zindler | 59726 = Joel |

Two sub-mechanisms of the same leak:
- 2 of the 9 were **created directly by the connector** with a low number (Sauter 51520, Schröppel 2700) — no storefront registration, `remote_address` NULL.
- 7 were **storefront registrations** (got a proper 100000+ number, `remote_address` set) and were **later overwritten** by the connector to a low number (`updated_at` > `created_at`, `updated_by_id` NULL). E.g. Zimmermann registered 2026-02-17, overwritten 2026-05-18 → 52858.

### Why renumbering alone does not close it
The backlog renumber (Ducati 26 accounts, Yamaha 153 accounts) fixed the **existing** duplicates but not the **mechanism**. Every future push re-applies Wawi's number, so new collisions keep appearing. It cannot be closed from Shopware without this plugin, and cannot be fully closed from Wawi without correcting thousands of stored numbers and every future returning customer by hand.

---

## Task — what the plugin must do

### R1 — Block customer-number changes from the connector on existing customers
- Intercept customer entity writes **before** they are persisted.
- If the write **originates from the JTL-Connector integration** AND the write is an **update to an existing customer** AND it would change `customer_number` to a value different from the current one → **drop only the `customer_number` field** from that write. Everything else in the same write (customer group, addresses, names, etc.) must still be applied.
- Do **not** fail/abort the whole write (the Kundengruppe sync etc. must keep working).

### R2 — Prevent colliding numbers on connector-created customers
- If the connector **creates** a new customer and supplies a `customer_number` that (a) collides with an existing customer, or (b) is not in the shop's own range → the new customer must get a number **from Shopware's own customer number range** instead of the supplied one.
- Note: Shopware's `customer.customer_number` is NOT NULL with no DB default, so on create we cannot simply strip it — the plugin must **assign** a range number (reserve from the `customer` number range for the relevant sales channel) when it rejects the supplied one.
- Simplest acceptable v1: if a connector-created number collides with an existing customer, replace it with a freshly reserved range number. (If we decide connector-created accounts are rare enough, an alternative is to always reserve a range number for connector creates — decide during planning.)

### R3 — Identify the connector write reliably
- Detect the source from the write `Context`: an **Admin API** source belonging to the **JTL-Connector integration** (by `integrationId`, resolved from a configurable integration technical name/id). NOT admin-user writes, NOT storefront/system writes.
- Identification must be **configurable per shop** (the integration id/name differs between ducati-world24.com and yam-shop.de).
- **Fail safe:** if the source cannot be positively identified as the connector, **do NOT block** — just log at debug. Never risk breaking legitimate admin/storefront writes.

### R4 — Log every blocked or remapped attempt (explicitly requested)
For each intervention, record:
- customer id (UUID) + email + name,
- current/kept `customer_number`,
- attempted `customer_number` from the connector,
- action taken (`blocked_update` | `remapped_create`),
- the assigned number (for remaps),
- source (integration id/name), timestamp.
- Primary sink: a dedicated **Monolog channel** (e.g. `jtl_connector_guard`) so it lands in the Shopware log and can be shipped to Grafana/monitoring.
- Consider (decide in planning) **also** persisting to a small **plugin DB table/log entity** so the attempts are queryable in the admin / via SQL for auditing, since these are exactly the events we want to watch. This is the "log those change attempts" requirement — a durable, queryable trail is preferred over log-file-only.

### R5 — Configuration
- Plugin config (per sales channel where relevant):
  - `enabled` (master switch),
  - `mode`: `enforce` (block/remap) vs `log_only` (observe and log, change nothing) — ship in `log_only` first to confirm detection on production before enforcing,
  - connector integration identifier(s) — id or technical name,
  - optional allow-list of fields the connector may still set (default: everything except `customer_number`).

### R6 — Safety / non-goals
- Must not change behaviour for admin-user writes, storefront registrations, imports, or any non-connector source.
- Must not block the connector from creating/updating customers in general — only the `customer_number` field is protected.
- No change to existing customer numbers on install (the backlog was already fixed by SQL).

---

## Technical notes (to verify during implementation)

- **Interception point (Shopware 6.6.10.x):** most likely a DAL subscriber on the customer entity write. Candidates to verify against the pinned core version:
  - `Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent` — gives access to the write commands before execution; investigate whether a command's payload can be modified (or a `customer_number` field removed) here, or whether this event can only add violations.
  - `Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent` (BeforeWrite) — commands available before write.
  - Fallback: decorate the customer `EntityRepository`/`EntityWriteGateway`, or intercept at the sync API controller — heavier, avoid if a DAL hook works.
  - The chosen hook must let us **modify one field** of the write (strip/replace `customer_number`) without aborting the rest. Confirm this is possible in this core version before committing to the approach; if not, fall back to: allow the write, then in `CustomerEvents::CUSTOMER_WRITTEN_EVENT` detect+correct with a re-write guarded by a recursion flag (less clean, document the trade-off).
- **Source detection:** `WriteContext` / `Context::getSource()`; for Admin API integration writes the source is `Shopware\Core\Framework\Api\Context\AdminApiSource` with `getIntegrationId()` non-null and `getUserId()` null. Map that integration id to "is the JTL-Connector" via config. Cross-check that connector writes actually carry this source (the connector uses the Admin API integration created 2026-05-05 named "JTL-Connector").
- **Number range reservation (R2):** use the `NumberRangeValueGeneratorInterface` for entity `customer` and the correct sales-channel id to reserve a real next number, so remapped creates behave exactly like a normal registration.
- **Repo/plugin scaffolding:** standard Shopware 6.6 plugin skeleton, `composer.json` name `revinners/shopware6-jtl-connector-guard`, PHP namespace `Revinners\ShopwareJtlConnectorGuardPlugin`, bundle class `ShopwareJtlConnectorGuardPlugin`. First subscriber class: `CustomerNumberWriteProtection`.

---

## Environment / facts for the implementer

- Two target shops, both Shopware **6.6.10.x**: `ducati-world24.com` (DB `rodler_sw`) and `yam-shop.de`.
- Connector = standalone **JTL-Connector** via an Admin API integration named **"JTL-Connector"** (Ducati: created 2026-05-05, only API client that writes customers). It is the **only** thing that writes customers with `created_by`/`updated_by` NULL.
- Customer number ranges: Ducati pattern now `C{n}` (start 10000, counter ~12k); Yamaha pattern `{n}` start 100000 (counter ~110k). New registrations already get non-colliding numbers; the plugin protects them from being overwritten afterwards.
- Backlog already fixed by SQL before this plugin (Ducati 26, Yamaha 153, both duplicate-count 0). The plugin is about **stopping new** overwrites, not fixing old ones.
- Local dev of the shops: Docker (`ducati-world24` container, Makefile targets `make up`, `make ssh`, `./bin/console`). Old JTL-Shop DBs available locally in container `mysql-mysql_db_container-1` (`ducati-world24-jtl2`, `yam-shop-jtl2`) for reference; Wawi (MSSQL) is not locally accessible — Matthias checks Wawi in the JTL client.

---

## Test plan (the one-customer proof)

Replicate the Erdösi test, but with the plugin active:
1. In `log_only` mode: change a linked customer's Kundengruppe in Wawi so a push fires; confirm the log records a `blocked_update` attempt with the attempted number, and (log_only) the number still changes. This proves detection on real production traffic without side effects.
2. Switch to `enforce`: repeat; confirm Shopware **keeps** the correct number, the log records the blocked attempt, and the customer group / other fields still updated.
3. Connector-created case (R2): simulate/observe a connector create with a colliding number; confirm the new customer gets a fresh range number and the attempt is logged as `remapped_create`.
4. Regression: an **admin** user changing a customer number in the backend still works (not blocked). A storefront registration still gets a normal number (not touched).
5. Run on a branch first; deploy `log_only` to one shop, verify a few days of real logs, then `enforce`.

---

## Open questions (resolve in planning, not now)

1. Exact DAL hook that allows stripping/replacing a single field in this core version — verify against vendor source before choosing the approach.
2. R2 policy: only remap connector-created numbers that collide, or always assign from the range for connector creates? (Depends on whether legit Wawi-first customers should carry a Wawi number at all — decision: Shopware owns the number, so likely always range-assign.)
3. Log sink: Monolog channel only, or also a queryable plugin table/entity? (Preference: both — durable + queryable.)
4. Confirm with Matthias (pending): Zimmermann's Wawi "Kundennummer Onlineshop" = 52858 (a foreign number) — final confirmation of the exact Wawi field the push reads.
5. Multi-shop config shape: one plugin, per-sales-channel config, connector integration id per shop.

## Out of scope
- Fixing the historic backlog (done via SQL).
- The Wawi-side "Kundennummer Onlineshop" corrections (Matthias, in the JTL client).
- Any change to order numbers or other entities (future subscribers in the same plugin if/when needed).

## Related
- ClickUp: parent task 86cbe5c6t ("Debug customer id overwriting"); plugin subtask **86cbevjp5** (spec + ERP evidence in comments); monitor subtask 86cbeuv78; data subtasks 86cbeudvr (Ducati CSV), 86cbeudxm (Yamaha CSV).
