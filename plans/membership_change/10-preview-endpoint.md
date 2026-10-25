# 10 — The Preview Endpoint

> New 2026-09-22. Extends the architecture step sequence (see `README.md`) — implements a
> specific piece of [03-api-contracts.md](03-api-contracts.md)'s already-designed contract
> (`preview()`), the same relationship [09](09-billing-tier-change-hook.md) has to that
> document's `reconcile()` entry.

> **Done, 2026-09-23 — banner below is stale, see
> [complete/10-preview-endpoint.complete.md](complete/10-preview-endpoint.complete.md).**
> Built as designed: `RetentionEngine::preview()`'s `cascadeRetirement()` wiring, both
> services' `bandStatesFor()`/`previewChange()`, the member-facing and signed routes, and
> `BillingApp.ts`'s confirm-dialog wiring. One addition the plan text didn't name: a
> registry-wide resource-scope reader (`RegistryResourcesClient`) and an email-specific
> tier reader (`EmailRegistryClient`) had to be built — see the completion doc's own
> deviation notes for why. OQ-P/OQ-Q stay open, exactly as scoped.
>
> **PLANNING ONLY. No code written for this feature yet.**

## 10.1 What this closes

**D10** (`00-overview.md` §5): *"The member sees the consequence before the change, with a
real number, and is told plainly when evacuation is impossible."* Surfaced as a concrete gap
while planning [../billing/17-self-service-tier-changes.md](../billing/17-self-service-tier-changes.md)
§17.4: once a self-service downgrade actually writes `membership` (17's fix), the member's
confirmation dialog (`BillingApp.ts`'s `selectPlan()`) still shows only price and proration —
nothing about what retires. **This document designs the endpoint that dialog needs to call
before the member confirms**, not after.

It is not a new design. `03-api-contracts.md` already specifies
`GET {base}/{resource}/preview?to_plan=N` in full — response shape, error codes, the
`.resource(key).preview({toPlan})` client method (on `03`'s facade — see that document's
2026-09-23 naming correction, §3.1) — and marks it, correctly,
as contract only: *"Nothing built; this is the specification."* This document is the
implementation plan `03`'s own "Not verified" list (items 5-7: cascade correctness, evacuation
impossibility, preview accuracy) has been waiting on since 2026-09-21.

## 10.2 What already exists — more than expected, verified by reading it directly

`App\Checkpoint\RetentionEngine::preview()` (`members`, lines 219-281) **already computes the
core of this** — `bands_retired`, `bands_restored`, `items_affected`, `amount_affected`,
`can_evacuate`, `evacuation_headroom` — as a pure function of `(fromPlan, toPlan,
accountStatus, tierMode, tiers, currentBandStates, registryResources)`. It mutates nothing and
calls no adapter. **It is also called by nothing** — no controller, no service, not even a
test exercises it. Built as part of the engine scaffold, the same way
`EmailRetentionAdapter` existed correctly for a full day before anything called it
(`features/complete/2-domain_email_accounts.complete.md`'s own history).

**One real gap inside the engine itself:** `preview()`'s return always sets
`'cascade' => []`, with a comment admitting *"will be populated by cascadeRetirement."`
`cascadeRetirement()` exists as a separate method on the same class and is never called from
`preview()`. Wiring that one call is this document's smallest, most concrete implementation
item.

**The larger gap is the input, not the engine.** `preview()` needs `$currentBandStates`
shaped as `band => {item_count, used, reserved, purge_after}` for *every* band 0-4 — a
hypothetical-plan view, not "what's currently retired." Neither `RetentionService::summaryFor()`
nor `EmailRetentionService::summaryFor()` (`members`, both already built for the `state()`
contract endpoint) produce this shape — both only query bands *already* in
`retired`/`purging`/`suspended`, because `summaryFor()` answers "what's wrong right now," not
"what would happen at a different plan." A new query per resource, `bandStatesFor(memberRef)`
(storage) / `bandStatesFor(domain, memberRef)` (email), reading all five bands unconditionally
from the same `BucketUsage`/`EmailBandUsage` tables `summaryFor()` already reads, is the
actual missing piece — not new inventory, not a new table, a differently-shaped read of data
that already exists.

## 10.3 Where the endpoint lives — reusing the established cross-repo pattern, not OQ-G

`03-api-contracts.md`'s **OQ-G** ("one base path per resource or one per owning app") is still
open and this document does not resolve it globally. It doesn't need to, to ship the two
resources that actually have an engine today:

- **`storage_files_size_kb`** — inventory (`StorageNode`), ledger (`BucketUsage`), and the
  engine all live in `members`. The endpoint is a plain new `members` route, no cross-repo
  call at all.
- **`domain_email_accounts`** — inventory (`users_email`) lives in `www`; the engine and
  ledger (`EmailBandUsage`) live in `members`. This is exactly the shape
  `EmailAccountController::index()`'s reconcile call already solved
  (`features/complete/2-domain_email_accounts.complete.md`) — a signed HTTP call from `www`
  to a new route in `members`' existing `www.internal` group
  (`VerifyWwwInternalSignature`, already built, already used for reconcile). **No new
  middleware, no new signing mechanism** — one more route in a group that already exists.

Both resources' preview lands on the member-facing dashboard the same way
`EmailAccountController::index()` already surfaces reconcile results — read, not written, so
neither needs a queue or async handling; it's a request the member's own confirm-plan-change
click is waiting on.

## 10.4 The aggregate consumer, and what "aggregate" honestly means today

`03-api-contracts.md` §3.4 already names a second consumer: *"an aggregated `preview(toPlan)`
across all resources... one computation, two consumers — the dashboard and the [dunning] email"*
(`../billing/08-brain-enforcement.md` §8.8's hand-maintained "what you lose" list). **That
aggregation, if built today, would faithfully report the consequences for exactly two of
thirty-five resources and silently say nothing about the other thirty-three** — not because
nothing would happen to them on a downgrade, but because they have no backward edge built yet
to ask. This is the same shape of honesty this plan has insisted on throughout
(`04-feature-template.md`'s "done means both edges"; the storage/email complete docs'
repeated "tested directly... not yet true end-to-end" caveats) — an aggregate preview must
either explicitly list which resources it could not evaluate, or it becomes exactly the kind
of confident-looking wrong answer D10 exists to prevent. **Not a blocker for shipping preview
for the two resources that have it — a hard requirement for whenever the aggregate wrapper
gets built**, so it never silently under-reports.

## 10.5 Open questions

- **OQ-P — does `cascadeRetirement()`'s wiring into `preview()` need to recurse into other
  apps' engines, or does it stop at "these resource keys would retire"?** A domain retiring
  cascades into `per_store` resources beneath it (`02-architecture.md`'s scope graph) — if any
  of those live in yet another app the way email's inventory lives in `www`, a full cascade
  preview compounds the cross-repo problem §10.3 solved once, per level of nesting. Today,
  with only `storage_files_size_kb` (member-scoped, no cascade) and `domain_email_accounts`
  (per_domain, no children of its own yet registered) actually built, this has no real case to
  test against — flagged for whichever resource is next to actually sit beneath a cascading
  parent.
- **OQ-Q — `messages[]` (contract-specified, human-readable) — generated per-resource, or a
  shared template keyed on the same `(bands_retired, can_evacuate)` shape every resource's
  preview already returns?** A shared template ("moving from plan 3 to plan 1 will retire N
  items in bands 2-3; you have room to move M of them") avoids 35 resources each writing
  slightly different copy for the same structural situation, at the cost of losing
  resource-specific nuance (email's "these addresses will stop sending" vs storage's "these
  files become read-only"). Not decided; both are reasonable, and it doesn't block the two
  resources shipping first.
- **OQ-R — does the preview call reconcile as a side effect, matching `state()`'s own
  documented behavior** (`03-api-contracts.md`'s OQ-H, still separately open: *"`state()`
  reconciling as a side effect of `GET` — storage does this and it's load-bearing"*)?
  Preview is inherently hypothetical (a plan the member hasn't chosen yet); reconciling the
  member's *actual* current plan while answering a question about a *different* plan seems
  safe either way, but should be a stated decision, not an accident of reusing `state()`'s
  code path.

## 10.6 Files to create/modify (when this moves to implementation)

| File | Repo | Change |
|---|---|---|
| `app/Checkpoint/RetentionEngine.php` | members | `preview()`: call `cascadeRetirement()` and populate `cascade[]` instead of hardcoding `[]` |
| `app/Checkpoint/Storage/RetentionService.php` | members | New `bandStatesFor(memberRef)`, new `previewChange(memberRef, toPlan)` calling the engine |
| `app/Checkpoint/Email/EmailRetentionService.php` | members | Same two additions, domain-scoped |
| `routes/web.php` (or wherever storage's routes live) | members | `GET .../storage_files_size_kb/preview?to_plan=N` |
| `routes/www_internal.php` | members | `POST .../email/preview` in the *existing* `www.internal` group |
| `app/Http/Controllers/Www/EmailReconcileController.php` (or a sibling) | members | Handles the new email preview route |
| `app/Services/Members/MembersEmailClient.php` | www | New `previewEmail(domain, memberRef, toPlan)`, sibling of the existing `reconcileEmail()` |
| `resources/js/controllers/BillingApp.ts` | members | `selectPlan()`'s confirm dialog (line ~1190) calls preview for every resource with an engine before showing the confirmation text — the actual D10 close, and `../billing/17-self-service-tier-changes.md` §17.4's dependency |

No new database table, no new signing mechanism, no engine redesign — `RetentionEngine.preview()`
already does the hard part; this is wiring it to real data and a route, twice.
