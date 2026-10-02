# 004 - Same-Person Check

> Plugin: **ShopwareJtlConnectorGuardPlugin** (`revinners/shopware6-jtl-connector-guard`)
> Fourth feature. **Replaces 002 (identity guard) and 003 (field allow-list), which were removed from the plugin in 1.3.0**; **001** (customer number) is unchanged.
> Status: **IMPLEMENTED — 1.3.0. Ships `log_only`.**

---

## Why

003 implemented the rule "on an existing customer the connector may change only the customer group". Two things are wrong with it (2026-10-01):

1. **It blocks legitimate work.** The merchant edits customers in JTL-Wawi — addresses above all, also name, company, VAT id — and expects those edits in the shop. Under 003 `enforce` none of them would arrive.
2. **It lets the damaging part through.** The customer group is on the allow-list, so when Wawi pushes customer A onto customer B's account (a wrong link on the Wawi side), B still receives A's group. On yam-shop.de that put retail customers into dealer discount groups.

### Evidence (yam-shop.de prod, 2026-10-01)

- Trigger ticket: two accounts showing the same dealer. One of them carries the dealer's name, company, e-mail and the 10 % dealer group, but its only address and its only order belong to a retail customer who has his own, separate Wawi record; the dealer's real account is the other one.
- Sweep (customer e-mail on none of the account's own orders **and** name differing from the default billing address): **45** accounts; 38 were counted on 2026-09-07 (spec 002). Compared one by one with the Wawi customer export of 2026-10-01: **44 confirmed**, 1 a probable own e-mail change.
- In all 44 the current identity and the original owner exist as **two separate Wawi customers**. In 36 of 44 the identity now on the account is a discount customer in Wawi (Händler 10 % / 15 %, Kleingewerbe 10 %, Endkunden 5 %); the original owner is a plain Endkunde in 43 of 44. 13 accounts written 2026-09-08…11 carry "Endkunden 5 %" — a batch of group assignments in Wawi, each one pushing onto a wrongly linked account.
- In all 44 the **address rows were untouched** by the push, as in specs 001/002.
- The guard was not installed on prod during any of this.

**Why the Wawi links are wrong is not established** — it is not visible from Shopware or from the export. This feature does not depend on the answer: it makes a wrong link harmless.

## The rule

On a JTL-Connector **update of an existing customer**, the e-mail is the test of "is this the same person":

| The write carries… | Meaning | What happens |
|---|---|---|
| the account's own e-mail (case-insensitive, trimmed) | same person | **everything is applied**: addresses (update, create, delete, default ids), name, company, VAT id, group, custom fields. Not logged. |
| no `email` at all (e.g. an address-only write) | nothing to judge by | treated as the same person: applied. |
| a **different** e-mail | another customer pushed onto this account | **nothing is applied** in `enforce`: every changed customer column, every custom-field key, and every address of that customer in the same write is kept. In `log_only` it is applied and each replaced value is recorded. |

`customer_number` stays with 001 in both cases (its own switch and mode).

### Consequences, stated plainly

- A **genuine e-mail change made in Wawi** is indistinguishable from a wrong link and is blocked together with the rest of that write. Change the e-mail in the Shopware admin instead; admin writes are never guarded.
- A different-person write that **creates or deletes an address** cannot have that command removed (DAL limitation, see 003). It is recorded per column (`observed_address_create` / `observed_address_delete`), and the default address ids — customer columns — are kept, so the foreign address never becomes the default. `addressCreateDeletePolicy=reject_write` rejects the whole write instead (enforce only).
- An **address-only write** (no customer command with an e-mail in the same write) cannot be judged and is applied. Observed so far: the connector does not touch addresses on these pushes at all.

## Requirements

- **R1** New switches `samePersonGuardEnabled` (default `true`) and `samePersonGuardMode` (`log_only` default | `enforce`), per sales channel like the others.
- **R2** 002 and 003 are removed (code, settings, audit actions). The plugin had never run on a production shop, so there is nothing to migrate; `addressCreateDeletePolicy` moves to this feature.
- **R3** Different person, customer columns: every column in the write except bookkeeping columns is compared with the current row; a changed one is written back (`enforce`) / recorded (`log_only`). Columns 001 already wrote back are skipped; a column 001 only observed (001 in `log_only`) is still kept here.
- **R4** Different person, custom fields (`JsonUpdateCommand`): every key, no allow-list.
- **R5** Different person, addresses in the same write: every changed column of an updated address; create/delete as in 003.
- **R6** Audit actions `blocked_mismatch` (kept) and `observed_mismatch` (applied), on the existing table and channel; address rows carry `entity = customer_address` and the address id. No migration.
- **R7** Safety as before: connector writes only, inserts untouched (001 still remaps the number), never throw into the DAL write, one failing command never stops the others.

## Applying the kept write to the right account (added 2026-10-01)

Live tests the same day showed the wrong target is deterministic: for 39 of 44 overwritten accounts,
`old JTL-Shop tkunde.kKunde of the Wawi customer − customer.auto_increment of the hit Shopware account =
40494` (40493 for the higher ones). One Wawi customer writes to exactly one shop account, the wrong one;
the account with the matching e-mail is not touched. So blocking alone means the merchant's change in the
ERP arrives nowhere — and the merchant wants to keep doing group and master-data changes in the ERP.

- **R8** In `enforce`, with `samePersonRerouteEnabled` (default on), the kept write is applied to the
  single registered (`guest = 0`) customer whose e-mail equals the one in the write. Zero or several →
  nothing is written, one `reroute_skipped` row. Guest accounts are never a target.
- **R9** Only columns listed in `samePersonRerouteFields` and supported by the plugin are transferred;
  never `customer_number`, never `email`. Only changed values are written and logged (`rerouted`).
- **R10** The reroute is queued from the write event's success callback (the connector's commands were
  executed without error) and applied on `kernel.response`, through the customer repository with a
  system context; a failure is logged (`reroute_skipped / write_failed`) and can never fail the
  connector's write. A rolled-back write reroutes nothing.
- **R11** The person is judged per customer over all its commands in the write; the target of a reroute
  must match the pushed e-mail by the check's own comparison, not only by DB collation.
- **R12** `enabled` is the master switch of the whole plugin. The connector's integration is selected
  explicitly (no name matching); a selection in any config scope counts shop-wide.

## Out of scope

- Repairing the 44 accounts (restore e-mail, name, company, group from the order snapshots and the Wawi export) and correcting the links in Wawi. Repair before the link is fixed is pointless only in `log_only`; in `enforce` a repaired account stays repaired.
- Finding out why Wawi links a customer to the wrong shop account.

## Rollout

1. Install (the plugin was never installed on yam-shop.de prod) and select the connector's integration in the settings (`JTL Connector`, id `019b8946ccc67767b9fb8cb524300ba1`) — nothing is guarded until one is selected; matching by name was removed.
2. `log_only` until one real push is seen in `revinners_jtl_guard_log` (a Kundengruppe change in Wawi triggers one).
3. `mode=enforce` (001) and `samePersonGuardMode=enforce`.
4. Verify from Wawi: an address edit on a correctly linked customer arrives in the shop; a group change on a customer linked to a foreign account produces `blocked_mismatch` rows and changes nothing.
