# Brain Membership Identity

How `brain_memberships` — the AFS platform's own "who is a paying member" table — and the
public signup flow that writes it (`w/www/resources/js/afs/`) adopt the payer model from
[`../www/direct_payments/`](../www/direct_payments/) instead of storing a raw PSP customer id.

## The one-sentence version

`brain_memberships.customer_id` is a bare, unindexed `cus_…` string written straight from the
client SDK; this plan replaces it with `payer_reference_num` — the same DP-issued, never-changing
reference every other implementer (ecommerce, events, appointments, Site Users) is being moved
onto — so a Stripe/Square/PayPal account swap on AFS's own membership product doesn't orphan a
single paying member.

## Why this is its own folder, not a doc bolted onto an existing plan

Three folders were considered and rejected before this one, each for a reason worth recording so
it isn't re-litigated:

- **`plans/members_brain/`** — wrong module entirely. That plan is the Brain admin's Parse
  `MemberRegistry` allocation-counter editor (`brain_membership.ts`'s "Allocations" tab). It
  shares a table name by coincidence (`brain_memberships` the MySQL row vs. `MemberRegistry` the
  Parse usage ledger) and nothing else — see [`../members_brain/01-current-state-audit.md`](../members_brain/01-current-state-audit.md)
  §1 for confirmation that no customer-id concept exists anywhere in that plan.
- **`plans/billing/`** — billing/ is the platform's *enforcement* layer (dunning, downgrades,
  `brain_membership_billing_state`). This plan is about *identity* (which payer a member is),
  not enforcement. The two tables sit next to each other in the same migration file but answer
  different questions.
- **`plans/www/direct_payments/`** — that plan is the provider-portability core: the payer,
  binding and tier-binding machinery itself. This plan is one *consumer* of it, in the same
  relationship ecommerce/events/appointments/site_users already have to it. Folding a consumer's
  migration into the core plan is exactly the seam-inconsistency mistake
  [`../www/connections/01-current-state-audit.md`](../www/connections/01-current-state-audit.md)'s
  X42 finding documents — a pointer note left over old code underneath it. This plan exists so
  that never happens here.

## Dependency

**This plan cannot ship before `direct_payments/02` and `/05` do.** `payer_reference_num`,
`PayerService::updateEmail()` and the SDK's payer-returning resolver are all defined there, not
here. Everything below assumes they exist.

## Docs

| # | Doc | Covers |
|---|---|---|
| 00 | [00-overview.md](00-overview.md) | Goals, non-goals, glossary, decisions |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | What's wrong today — BM1 through BM8 |
| 02 | [02-database-and-server.md](02-database-and-server.md) | **BM-P1/P4/P6 implemented 2026-09-22** — see [complete/02-database-and-server.complete.md](complete/02-database-and-server.complete.md). `customer_id` dropped outright, in the same migration that adds `payer_reference_num` (no live data to protect through a phased window). Shipped server-only, without §2.3's client-trusting design: BM-P0's own stated prerequisite (direct_payments/05's SDK payer-resolver) was never built, and `getStoredCustomerId()` turned out to be live, shared SDK internals. BM-P5 (a live backfill command) was built, tested, then deleted once BM-P6 made it dead code — the two seed datasets were hand-corrected instead. See the completion doc's "Deviation from the plan" and "Seed data" |
| 03 | [03-client-sdk-and-flow.md](03-client-sdk-and-flow.md) | **Partly built 2026-09-23 — BM3 and BM6 done, BM5 was already done; BM2 deliberately not built.** See [complete/03-client-sdk-and-flow.complete.md](complete/03-client-sdk-and-flow.complete.md). Why BM2 isn't: the SDK method AFS would need (`getStoredPayerReferenceNum()`) has no underlying mechanism to read from; removing `getStoredCustomerId()`/`updateCustomerEmail()` as written would break subscription checkout for every tenant, not just AFS |
| 04 | [04-rollout-and-testing.md](04-rollout-and-testing.md) | Phases, grep gates, rollback, acceptance. BM-P1/P4/P6 done, compressed into one pass (no phased backfill window — this environment has no live data); BM-P2/P3 not started; BM-P5 built and removed again, see 02's completion doc |

## What this plan does not touch

- `DirectPaymentSubscription` / `DirectPaymentTier` resolution (the *tier a member is on*) —
  that's `billing/`'s and [`direct_payments/07`](../www/direct_payments/07-tier-bindings.md)'s
  job. §1.7 of the audit notes where AFS's signup controller already leans on those tables, as
  corroborating evidence for D2, but this plan does not change that code.
- `brain_membership_billing_state` / `brain_membership_events` — both already store a
  per-row `processor_reference_num` snapshot (§1.6). They predate this plan and already do the
  right thing; nothing here changes them.
- Checkpoint (SSO) registration itself — `user_id` is untouched.
