# direct_payments — completion records

One file per numbered plan document, named `<source basename>.complete.md` — the convention
every sibling folder under `plans/www/` follows.

**All seven phases (DP0–DP6) have shipped**, 2026-09-12/13. Start at
[`06-rollout-and-testing.complete.md`](06-rollout-and-testing.complete.md) — it's the index the
other six hang off, with the full phase checklist and test-matrix state.

## What a completion document contains

| Section | Contents |
|---|---|
| **Header** | Plan file, rollout phase (DP0–DP6 — [`../06-rollout-and-testing.md`](../06-rollout-and-testing.md) §6.1), repo, date, status |
| **What shipped** | Per plan section, what was actually built — not what was intended |
| **Not implemented** | Explicitly, with the phase that owns it |
| **Files** | Every file added or changed |
| **Acceptance** | The plan section's acceptance list, each row ✅ / ⚠️ / ❌ with the evidence |
| **Verification** | The exact commands a reader can run |
| **Residue** | Anything found on the way that belongs elsewhere, with where |

## Expected files

| Phase | Document |
|---|---|
| DP0 | `03-the-swap.complete.md` (§3.3 only — the guard; extended later by DP5) |
| DP1 | `02-payers-and-bindings.complete.md` (§2.5 — the processor snapshots) |
| DP2 | same file, extended (§2.2, §2.3 — payers) |
| DP3 | same file, extended (§2.4 — bindings) |
| DP4 | `04-history-refunds-and-webhooks.complete.md` |
| DP5 | `03-the-swap.complete.md`, extended |
| DP6 | `05-implementer-contract.complete.md` |
| — | `06-rollout-and-testing.complete.md` — the phase checklist and the test-matrix state, the index the others hang off |
| — | `07-tier-bindings.complete.md` — added beyond the original table: DP1's D9 fix and DP4's D10/resolvers both landed in `07-tier-bindings.md`, including a real backfill gap found and closed during DP4 |

[`../00-overview.md`](../00-overview.md) and
[`../01-current-state-audit.md`](../01-current-state-audit.md) get no completion documents of
their own — they are reference, verified through the phases that implement them.

## The two records that matter most

When DP4 lands, its completion document must show the **provider-level** evidence, not just a
passing test: the fake provider asserting which account a refund and a subscription
cancellation were sent to ([`../06-rollout-and-testing.md`](../06-rollout-and-testing.md)
§6.4). A test that proves the call happened, without proving where it went, is the exact
failure **D2** describes.
