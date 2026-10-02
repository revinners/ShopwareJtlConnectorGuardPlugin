# 002 - Customer Identity Write Protection

> **REMOVED in 1.3.0 (2026-10-02).** The identity guard was replaced by the same-person check, see `specs/feat/004-same-person-check/SPEC.md`. Kept for history only.

> Plugin: **ShopwareJtlConnectorGuardPlugin** (`revinners/shopware6-jtl-connector-guard`)
> Second feature in the plugin. Builds directly on **001 - Customer Number Write Protection** (implemented, released 1.0.0/1.0.1). Reuses 001's connector-detection, mode switch (`log_only`/`enforce`), and audit log.
> Status: **IMPLEMENTED — see PLAN.md; released as 1.1.0 (2026-09-07). Implemented before 001 was observed on prod at the user's request; ships log_only.**

---

## Overview

001 proved and stopped the JTL-Connector overwriting `customer_number`. Investigation on the way showed the **same push overwrites more than the number**: it replaces a customer's whole **identity** — `email` and `first_name`/`last_name` — with a *different person's* data, while leaving the address entity untouched. The customer literally becomes someone else in the shop.

This feature extends the guard from "protect the number" to "protect the identity". On a JTL-Connector write to an **existing** customer, it prevents the connector from **changing the email to a different address** (the hard identity-swap signal), and treats a full name replacement the same way, while still letting legitimate field updates through. Same plugin, same subscriber pattern, same `log_only` → `enforce` rollout, same audit table.

Two shops in scope: **ducati-world24.com** and **yam-shop.de** (Shopware 6.6.10.x). Ship `log_only` first.

> This spec is **prevention only**. Repairing the already-corrupted accounts (restoring identity from frozen order snapshots) is a separate one-off data job — see **Out of scope / follow-up**.

---

## Background — evidence the connector corrupts identity

### Prior, independent confirmation (2026-03-13)
`/Users/macbookpro/Soft/jtl-sw-orders/corrupted-accounts.md` documented this months ago, on a different customer:
- Our export created **Martin Reischl** (UUID `06f7ba3d…`, email `reischl@t-online.de`, number `48111`) on 2026-01-20.
- On **2026-02-16** the connector overwrote that same UUID with **Ramona Kraft's** data: email → `ramona.kraft@moto-kraft.de`, number → `39414`, name → Ramona Kraft — **but the address stayed Martin Reischl's** (Nelkenweg 12, Mainaschaff).
- The order snapshots (`order_customer`) still hold the correct Martin Reischl data. Our export code only ever POSTs customers and PATCHes `groupId`; it never rewrites identity. So the overwrite is the connector, writing one person onto another's UUID.

The **address-left-behind** signature is the tell: the connector overwrites customer-level fields (email, name, number) and does not touch the separate address entity, so a corrupted record shows person A's name/email with person B's address.

### This investigation (2026-09-07, yam-shop.de)
- The same identity overwrite is live and ongoing, not a one-off. Example: account `C37430-2` (`019daaeff59572c2a4f5c068e613edb5`) — its order 83776 was placed by **Tobias Schröer** (Federgrasweg 16, Wietmarschen, `tobiasschroeer1999@web.de`), but the live customer record now reads **Christopher Kühnel** (`info@motorradgarage-dachau.de`, Dachau). Name and email overwritten, address still Schröer's.
- **Scope (measured):** a name-mismatch sweep (current surname on none of the customer's own orders) returned **266** rows, but that is mostly noise — GDPR-anonymised `*@deleted.local`, company accounts, family/proxy orders, and spelling/umlaut/first-last-swap variants.
- Filtering by **email** instead (current email on none of the customer's own order snapshots, excluding `@deleted.local`) returns **38** rows — the true identity-overwrite scope. Email is a hard identity key, so it drops the company/family/spelling noise. Examples: `C61582` now "Paul Böshans" but ordered as `schroeppel@imtec-gmbh.net`; `C49993` "Dirk Riesen" but `giancarlocapizzi@…`; `C37093` "Achim Freund" but `bastianpaffenholz@…`.
- **17 of the 38 overlap with the accounts renumbered in 001's backlog cleanup** (they carry the `2026-09-07 15:15:51` renumber timestamp). Those were hit twice — colliding number *and* swapped identity. The renumber fixed only the number; the identity is still wrong. (The renumber did **not** cause the identity swap; our UPDATE only touched `customer_number`.)
- The other ~21 span Feb–Sep 2026, i.e. steady since migration.

### Measurement query (true scope)
```sql
SELECT c.customer_number, c.first_name, c.last_name, LOWER(c.email) AS current_email,
       COUNT(DISTINCT o.id) AS orders,
       GROUP_CONCAT(DISTINCT LOWER(oc.email) SEPARATOR ' | ') AS order_emails,
       LOWER(HEX(c.id)) AS customer_id, c.updated_at
FROM customer c
JOIN order_customer oc ON oc.customer_id = c.id AND oc.version_id = UNHEX('0FA91CE3E96A4BC2BE4BD9CE752C3425')
JOIN `order` o ON o.id = oc.order_id AND o.version_id = oc.order_version_id
WHERE LOWER(c.email) NOT LIKE '%@deleted.local'
GROUP BY c.id
HAVING SUM(LOWER(TRIM(oc.email)) = LOWER(TRIM(c.email))) = 0
ORDER BY c.updated_at DESC;
```

---

## Task — what the feature must do

Reuse everything 001 already built: connector detection (Admin API integration id/label, fail-safe to "do nothing"), the `mode` switch (`log_only`/`enforce`), the `revinners_jtl_guard_log` audit table and Monolog channel, and the `EntityWriteEvent` subscriber that rewrites the current value back into the live write command (DAL has no remove-key, only `addPayload`).

### R1 — Protect `email` on existing customers (the hard signal)
- On a **connector write** that is an **update to an existing customer**, if it would change `email` to a **different address** than the current one → in `enforce`, write the current email back into the command (keep it); in `log_only`, record but allow. Let all other fields in the same write proceed.
- Rationale: an email change on an existing linked customer is the corruption signature (identity replacement). Legitimate email corrections are rare and can be done by an admin, which is not blocked (admin writes are never guarded — 001's detection already excludes them).

### R2 — Protect `first_name` / `last_name` on existing customers (paired with an identity swap)
- Treat a name change as identity corruption **when it co-occurs with the email swap of R1** (same write replaces both) → keep the current name too.
- A name-only change (email unchanged) is **ambiguous** — could be a legitimate correction. Default: **log only**, do not block, so we can measure how often it happens before deciding. Make this a config toggle (`protectNameOnEmailSwapOnly` vs `protectNameAlways`), defaulting to the conservative "only when email also swaps".

### R3 — Configuration (extend 001's config, do not fork it)
- `identityGuard.enabled` (master switch for this feature, independent of the number guard).
- `identityGuard.mode`: `log_only` | `enforce` (ship `log_only`).
- `identityGuard.protectName`: `on_email_swap` (default) | `always` | `off`.
- Reuse 001's connector-integration identification and per-shop config (yam-shop.de label is `JTL Connector` with a space; integrationId `019b8946ccc67767b9fb8cb524300ba1`).

### R4 — Audit
- Every kept/observed identity write goes to the same `revinners_jtl_guard_log` table + Monolog channel, with: customer id, field(s) affected (`email`/`name`), current (kept) value, attempted value, action (`blocked_identity` | `observed_identity`), mode, source, timestamp. Log the attempted value so the repair job (below) can use the connector's intent if ever needed.

### R5 — Safety / non-goals
- Never guard admin-user, storefront, or non-connector writes (reuse 001's detection; fail-safe to "do nothing" on uncertain source).
- Never abort the whole write — always keep the rest of the connector's payload (group, address, etc.).
- Do not touch address fields. (The connector already leaves the address correct; the address is in fact the *uncorrupted* half and is useful evidence.)
- No data migration on install.

---

## Technical notes
- Same interception point and command-rewrite technique as 001 (`EntityWriteEvent` subscriber, `addPayload` to write the current value back per command). Add `email`, `firstName`, `lastName` to the set of guarded fields alongside `customerNumber`, gated by this feature's config.
- Reading the **current** value: 001 already fetches current customer state to compare `customer_number`; extend that read to include `email`, `firstName`, `lastName`.
- "Different address" for email = case-insensitive, trimmed inequality. Consider normalising, but do not over-engineer.
- Keep the number guard (001) and identity guard (002) independently switchable, so 002 can ship `log_only` while 001 runs `enforce`.

## Test plan (mirror 001's live proof)
1. `log_only`: connector update that swaps email on an existing customer → logged as `observed_identity`, email still changes.
2. `enforce`: same write → email kept, other fields (e.g. group) applied; audit row written.
3. Email + name swap in one write (`enforce`) → both kept.
4. Name-only change with `protectName=on_email_swap` → allowed and (optionally) logged, not blocked.
5. Regression: admin user changes an email → not guarded. Another integration → not guarded. Storefront profile edit → not guarded.
6. Run against the real `/api/_action/sync` batch path, as 001 did.

## Out of scope / follow-up (NOT this plugin)
- **Repair of already-corrupted accounts.** The ~38 (yam-shop) confirmed identity swaps need their `email`/`first_name`/`last_name` restored from the frozen `order_customer` snapshots (the address is already correct). This is a one-off data job, best as a console command or reviewed SQL in `jtl-sw-orders`, run **after** this guard is in `enforce` (otherwise the connector re-corrupts). Some cases are genuine duplicates of one person split across accounts and need a merge decision, not a blind restore — so the repair needs a per-account review step, not a bulk update. Track separately (a 003 spec or a `jtl-sw-orders` task). See `corrupted-accounts.md` "Fix Strategy".
- Ducati has not been swept for identity corruption yet; run the measurement query there too before deciding the repair scope.

## Open questions
1. How often do legitimate email/name changes come from the connector on genuinely-matched customers? Measure in `log_only` before enforcing.
2. `protectName` default — is "on email swap only" enough, or does name get corrupted without email (measure)?
3. Repair ownership: console command in `jtl-sw-orders` vs reviewed SQL; and the merge cases.

## Related
- Plugin repo: https://github.com/revinners/ShopwareJtlConnectorGuardPlugin — 001 spec at `specs/feat/001-customer-number-write-protection/`.
- Prior investigation: `/Users/macbookpro/Soft/jtl-sw-orders/corrupted-accounts.md` (Reischl/Kraft, 2026-03-13).
- ClickUp: parent `86cbe5c6t`; plugin subtask `86cbevjp5`; monitor `86cbeuv78`; data subtasks `86cbeudvr` (Ducati), `86cbeudxm` (Yamaha).
