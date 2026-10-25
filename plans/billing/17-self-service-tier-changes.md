# 17 — Self-Service Tier Changes Never Actually Change The Tier

> New 2026-09-22, per direct instruction, after tracing a question from
> [../membership_change/09-billing-tier-change-hook.md](../membership_change/09-billing-tier-change-hook.md)
> about whether a paid upgrade takes effect immediately. It doesn't take effect at all — this
> plan is the fix.

> **§17.3 implemented 2026-09-22 — see [`complete/17-self-service-tier-changes.complete.md`](complete/17-self-service-tier-changes.complete.md).**
> OQ-M and OQ-N shipped as their own "leaning toward" answer below; OQ-O is still open, by
> design (it's a separate change to an already-complete document). §17.4's preview
> dependency ([`../membership_change/10-preview-endpoint.md`](../membership_change/10-preview-endpoint.md))
> and §17.7's fast-notification dependency
> ([`../membership_change/09-billing-tier-change-hook.md`](../membership_change/09-billing-tier-change-hook.md))
> remain unbuilt.

## 17.1 The finding, verified against live code

The member dashboard (`w/members/resources/js/controllers/BillingApp.ts`) already has a real,
working "Change Plan" UI — `openChangeSubscription()`/`selectPlan()` (lines 1131-1213), built
against [10-members-dashboard.md](10-members-dashboard.md) (already implemented, see
`complete/10-members-dashboard.complete.md`). `selectPlan()` confirms with the member, then
calls `dp.subscription.update({ tier_ref, member_ref })`, which reaches
`DirectPaymentSubscriptionController::update()` (`w/www`, line 368), built against
[28-subscription-actions.md](../www/direct_payments/28-subscription-actions.md) §14.4 (also already implemented).

**That endpoint charges the member through the payment provider, correctly, then writes
exactly one thing: `$link->update(['direct_payment_tier_reference_num' => $tierRef])`
(line 419) — the Direct Payment subscription link record. It never writes
`brain_memberships.membership`, the column every entitlement/quota read in the estate
actually uses** (`BrainEntitlementController::show()`, `QuotaService::planFor()`,
`coordinator`'s `BrainMembershipLookup`, `alloc_v2` band assignment — all of it).

**This isn't an oversight in `update()` — it's a documented, deliberate deferral that turned
out to be wrong.** Its own comment (lines 416-418): *"the column
`08-brain-enforcement.md §8.3` step 1 reads to resolve `tier_paid`. A plan change that skips
this silently leaves enforcement working from the OLD tier."* The assumption was that
`billing:enforce-membership`'s 15-minute sweep (`DunningService::advance()`, run on `www` when `members` triggers it, [08](08-brain-enforcement.md) §8.0) would pick up the
new `tier_paid` on its next tick and write `membership` then.

**Read `DunningService::advance()` directly (`w/www/app/Services/Billing/DunningService.php`,
lines 58-97) — it doesn't.** For a subscription that's `active`/`trialing` and not currently
in `past_due`/`downgraded` recovery — **exactly the state a member is in immediately after a
successful self-service plan change, up or down** — the only thing that branch does is:

```php
$state->tier_effective = (int) $member->membership;   // the OLD value, unchanged
if (!$dryRun) { $state->save(); }
return 'none';                                          // no write to $member->membership
```

It never compares the freshly-resolved `tierPaid` against the member's current `membership`
to detect "this changed, apply it." The comparison this deferral assumed exists does not
exist. **A member who upgrades is charged for the higher tier and never receives it. A member
who voluntarily downgrades keeps paying less and keeps every band/quota of the tier they left**
— `advance()`'s healthy-path branch has no code path that ever lowers `membership` either;
only `advancePastDue()`/`restore()`/the cancellation sweep write it, and none of those fire for
a voluntary, still-current-and-paid subscription.

## 17.2 Why this wasn't caught by 10 or 14's own testing

Both docs' completion records describe testing the piece each one owns: 10 tested that the UI
calls the right endpoint and renders the response; 14 tested that `update()` correctly talks to
the payment provider and updates the link record it's actually responsible for. **Neither
tested the end-to-end claim "and then the member's tier is actually different"** — that claim
lived in a code comment pointing at a different document (`08-brain-enforcement.md`), and `08`'s
own testing was scoped to the dunning ladder (lapse → warn → downgrade → restore), which never
exercises a healthy member choosing a new plan. The gap sits exactly on the seam between two
already-"complete" documents, which is why it survived both.

## 17.3 The fix: apply the tier synchronously, in `update()` itself

**Do not extend `DunningService`/`billing:enforce-membership` to detect this.** That
sweep's entire design (`08-brain-enforcement.md`, and `00-overview.md`/`01-architecture.md`'s
D-decisions) is for tier changes that happen *outside* a request — a lapsed card, a
provider-side cancellation, a subscription that resolves differently than last tick. A
self-service change is the opposite: the member is in an active request, has just been
charged, and is looking at a spinner. Deferring it to a 15-minute poll is the exact bug this
document exists to fix, not a smaller version of it.

**`update()` already has everything it needs, right after the link update.** Once the link record
carries the new tier, Direct Payments announces the change and appfactory's listener applies it:

```php
$link->update(['direct_payment_tier_reference_num' => $tierRef]);

// Direct Payments does not know what a plan change means to the application that sold it.
// It raises an event; appfactory's ApplyTierOnPlanChange listens and applies the tier now,
// not on the next enforcement tick.
$planChanged = new SubscriptionPlanChanged($link, $dpRef, $tierRef);
event($planChanged);                       // listener writes brain_memberships.membership synchronously
$membershipWarning = $planChanged->warning; // set by the listener if that write failed

$this->syncService->syncOne($subscription);
```

The listener, `App\Services\Billing\ApplyTierOnPlanChange`, constructor-injects
`MembershipTierResolver`. No binding makes the resolver a singleton, so a fresh instance has an empty
`$cache` and re-resolves from the database, seeing the just-updated
`direct_payment_tier_reference_num` rather than a stale cached value from earlier in the request. It
reuses `08-brain-enforcement.md §8.3`'s exact resolution order — the same tier-from-tables logic the
sweep already trusts — rather than a second, competing way to compute a member's paid tier. The
Direct Payments controller itself has no dependency on `App\Services\Billing`; on a deployment with no
listener registered (any tenant) the event does nothing. The listener saves through the normal
`Membership` model, so `membership_change/09`'s observer fires as it does for any other write.
**This applies to upgrade and downgrade identically, on purpose.** The existing confirmation
dialog (`BillingApp.ts`'s `selectPlan()`, line 1192) already tells the member *"you'll be
charged a prorated amount today... from your next billing date, $X/mo"* — the billing side is
already immediate and bidirectional (Stripe's `create_prorations`, passed through for any
provider that supports it). Entitlement should match what billing already promises, not lag
behind it in one direction.

## 17.4 Downgrade needs no new grace mechanism — it inherits one that already exists

Writing `membership` down immediately does not mean the member instantly loses access to
everything above their new tier. **`membership_change/02-architecture.md`'s retention engine
already owns exactly this transition** — for every resource with its backward edge built
(`storage_files_size_kb`, `domain_email_accounts` today), lowering `membership` is what
*starts* the existing 30-day grace window (bands retire, don't purge, until
`grace_days` elapses). This plan does not need to invent a second, billing-level grace period
sitting in front of that one — doing so would mean two different clocks deciding when the same
thing actually happens, which is the same class of problem `02-architecture.md` §2.9's
"two authoritative sweeps... degrade safely, an event that must be delivered exactly once does
not" reasoning already warns about, just in the other direction (two authorities disagreeing,
not one being unreliable).

**What this plan does need, and doesn't have today: the "see the consequence before it
happens" preview `00-overview.md`'s D10 already requires.**
`BillingApp.ts`'s `selectPlan()` confirmation dialog (line 1190-1193) shows only price and
proration — nothing about what retires. A member choosing a lower tier through this exact
flow is the primary audience D10 was written for, and the self-service path currently
bypasses it entirely. **Planned separately:**
[../membership_change/10-preview-endpoint.md](../membership_change/10-preview-endpoint.md) —
the contract has existed since `03-api-contracts.md` and the engine computation
(`RetentionEngine::preview()`) already exists too, unused; that document is the wiring plan.
Scoped here as a dependency, not solved here.

## 17.5 Open questions

- **OQ-M — does this write belong inside `update()`'s existing `try` blocks, or does a
  provider-confirmed charge with a failed `membership` write need its own compensation?**
  The provider has already been charged by the time this code runs (line 409). If
  `$member->save()` throws, the member paid for a tier they still don't have — arguably better
  than today (paid and never gets it, silently), but not "done." Leaning toward: log loudly,
  return `success: true` with a warning field (the charge and the plan-link update both
  genuinely succeeded), and let `members_brain`'s "force reconcile" operator action
  (`08-member-management.md`, line ~170) be the manual recovery path for the rare failure —
  not a blocking decision, but not silently ignored either.
- **OQ-N — should the immediate write also fire
  [../membership_change/09-billing-tier-change-hook.md](../membership_change/09-billing-tier-change-hook.md)'s
  observer once that's built, or is `update()` itself the "fast path" and 09 becomes
  redundant for this specific call site?** Current answer: fire it anyway. `$member->save()`
  is a normal Eloquent write; the observer fires on any qualifying save, and 09's own design
  is deliberately trigger-source-agnostic (§9.3: "catches all three sites structurally,"
  soon to be four). Special-casing this call site to skip the observer because it *also*
  happens to reconcile synchronously would be the same kind of hand-kept exception this whole
  session has been removing, not adding.
- **OQ-O — `advance()`'s healthy-path branch (§17.1) still does nothing when `tierPaid` and
  `membership` already agree, by design (it's a no-op reconciliation check).** Once §17.3
  ships, is there still a reason to leave that branch as-is, or should it also compare and
  self-heal (catching a case where `membership` drifted for some reason § 17.3 didn't cover —
  a direct DB edit, a bug elsewhere)? Leaning toward: yes, add the same comparison there too,
  as the sweep's own defense-in-depth — but that's a change to an already-complete document's
  code (`08-brain-enforcement.md`), which is why it's flagged here rather than folded
  silently into this plan's own scope.

## 17.6 Files to modify (when this moves to implementation)

| File | Repo | Change |
|---|---|---|
| `app/Http/Controllers/Externals/DirectPayments/DirectPaymentSubscriptionController.php` | www | `update()`: after the link update, raise `SubscriptionPlanChanged` and return the listener's `warning` |
| `app/Externals/DirectPayments/Events/SubscriptionPlanChanged.php` (new), `app/Services/Billing/ApplyTierOnPlanChange.php` (new, registered in `AppServiceProvider`) | www | The event, and appfactory's listener that resolves and writes `membership` synchronously |
| `app/Services/Billing/DunningService.php` | www | OQ-O only, if adopted: `advance()`'s healthy-path branch gains a self-heal comparison, as defense-in-depth, not as the primary mechanism. The synchronous write in `update()` (now the `SubscriptionPlanChanged` listener) stays regardless |
| `resources/js/controllers/BillingApp.ts` | members | No change required for the tier-write fix itself; the D10 preview (§17.4) is a separate, larger dependency on per-resource preview endpoints not yet built |

No new table, no new endpoint, no new signing mechanism — this reuses
`MembershipTierResolver`'s existing resolution logic and writes through the same `Membership`
model every other write site already uses, which is exactly what makes
[09-billing-tier-change-hook.md](../membership_change/09-billing-tier-change-hook.md)'s
observer catch it for free once that's built.

## 17.7 Relationship to `membership_change/09`

09 designs *how `members` finds out fast* once `membership` changes. This plan is the
reason 09 matters at all for the self-service path — without §17.3, a member's upgrade never
writes `membership`, so 09's observer would have nothing to fire on. Build order: this plan's
§17.3 first (a member's paid tier must become real at all), then 09 (so `members`' resource
reconciliation reacts to it quickly) — 09 depends on this plan, not the other way around.
