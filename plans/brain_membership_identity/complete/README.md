# brain_membership_identity — completion records

One file per numbered plan document, named `<source basename>.complete.md`, matching the
convention every sibling plan folder uses.

Nothing has landed yet. This folder is empty by design until BM-P0
([`../04-rollout-and-testing.md`](../04-rollout-and-testing.md) §4.1) — the direct_payments
prerequisite this plan depends on — ships first.

## Expected files

| Phase | Document |
|---|---|
| BM-P1 | `02-database-and-server.complete.md` (§2.1, §2.4 — the column and the dual-write) |
| BM-P2 | `03-client-sdk-and-flow.complete.md` (§3.1 only — the SDK method) |
| BM-P3 | same file, extended (§3.2-3.5 — the `resources/js/afs/` rewrite) |
| BM-P4 | `02-database-and-server.complete.md`, extended (§2.6 — the admin endpoint) |
| BM-P5 | `02-database-and-server.complete.md`, extended (§2.5 — the backfill, with the count of rows that could not be resolved and why) |
| BM-P6 | `04-rollout-and-testing.complete.md` — the column drop, plus confirmation §4.6's definition of done is fully met |

[`00-overview.md`](../00-overview.md) and [`01-current-state-audit.md`](../01-current-state-audit.md)
get no completion documents of their own — reference, verified through the phases that implement
them, same as every other plan in this tree.
