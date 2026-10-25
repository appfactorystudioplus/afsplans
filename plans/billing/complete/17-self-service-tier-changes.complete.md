# 17 — Self-Service Tier Changes Never Actually Change The Tier — Complete

## Status

Done, §17.3 only (the core fix). OQ-M and OQ-N are resolved as built, per the plan's own
leaning. OQ-O is **not** built here — it's a change to `08-brain-enforcement.md`'s own
`DunningService::advance()`, explicitly flagged in the plan as a separate decision on an
already-complete document, not folded silently into this one.

## Summary

`DirectPaymentSubscriptionController::update()` (`w/www`) raises `SubscriptionPlanChanged`
immediately after the existing `$link->update(['direct_payment_tier_reference_num' => $tierRef])`
write, and appfactory's listener (`App\Services\Billing\ApplyTierOnPlanChange`) writes
`brain_memberships.membership` synchronously, in the same request. A fresh
`MembershipTierResolver` (constructor-injected into the listener, not a singleton) resolves the member's newly
governing tier — seeing the just-written `direct_payment_tier_reference_num`, not a stale
value — and writes `membership` only when it actually differs from the current value.

This closes the finding in §17.1 exactly as described: a member upgrading through
`BillingApp.ts`'s "Change Plan" flow is charged the new amount and now actually receives the
new tier's entitlements in the same request, instead of never receiving them at all. A
voluntary downgrade now writes `membership` down too, which — with no code change needed
elsewhere — starts the existing 30-day retention grace for the two resources with a working
backward edge (`storage_files_size_kb`, `domain_email_accounts`): `storage:retention-sweep`
and the email retention sweep both read `brain_memberships.membership` directly on their own
schedule, exactly as they already do for a non-payment downgrade.

## Files changed

| File | Repo | Change |
|---|---|---|
| `app/Http/Controllers/Externals/DirectPayments/DirectPaymentSubscriptionController.php` | www | `update()` raises `SubscriptionPlanChanged` right after the existing tier-reference-link write and returns the listener's `warning` |
| `app/Externals/DirectPayments/Events/SubscriptionPlanChanged.php`, `app/Services/Billing/ApplyTierOnPlanChange.php` | www | The event, and the listener that resolves and writes `membership` — its try/catch logs and reports a `warning` on the event rather than failing the request |
| `tests/Feature/DirectPayments/DirectPaymentSubscriptionUpdateMembershipTest.php` | www | New. Four cases: upgrade writes the new tier, downgrade writes the new (lower) tier, an already-current tier is a genuine no-op (no write, no `updated_at` bump, no `warning` key), and an unresolvable tier (no `GOVERNING_STATUSES` row) leaves `membership` untouched rather than treating "unknown" as "zero" |

No migration, no new endpoint, no new signing mechanism — exactly as §17.6 said. `BillingApp.ts`
needed no change; it already sends the confirmation the fix makes true.

## Design decisions not fully spelled out in the plan text

- **OQ-M, resolved as the plan's own leaning:** the membership write is wrapped in its own
  `try`/`catch` (`\Throwable`), separate from the two upstream provider/link-write `try`
  blocks. On failure it logs at `error` level with the Direct Payment and member reference,
  sets a `$membershipWarning` string, and still returns `success: true` — the charge and the
  tier-reference link both genuinely succeeded by this point, and telling the member their
  paid-for change failed would be a second falsehood layered on the one this plan exists to
  remove. `members_brain`'s force-reconcile operator action is the documented manual recovery
  path for the rare failure; nothing new was built for it, per the plan's own "not a blocking
  decision."
- **OQ-N, resolved as "fires anyway":** no special-casing was added or considered — the
  write is a plain `Membership::save()`, indistinguishable from any other write site
  (`MembershipController::store()`, `DunningService`). `membership_change/09`'s observer, once
  built, catches this call site for free with zero additional code here, exactly as the plan
  intended.
- **Where in `update()` the write had to land, precisely:** after `$link->update(...)`
  (so `MembershipTierResolver::deriveTierPaid()`'s step 1 sees the new
  `direct_payment_tier_reference_num` when it re-queries), and after the provider call
  succeeded (`$this->actionsFor(...)->update(...)`, which can throw and already returns early
  on failure) — never before either, or the member's tier could be forced ahead of a charge
  that never actually completed.
- **`$member` is resolved from `$link->member_reference_num`, not `$subscription->member`.**
  The plan's own §17.3 code sketch used `$subscription->member`, but `ProcessorSubscription`
  has no `member` relation (confirmed by reading the model — it has `processor()`,
  `customer()`, `plan()`, nothing else). `DirectPaymentSubscription::member_reference_num` is
  the correct, already-established path to a member from inside this controller — the same
  field `resolveMemberReferenceNum()` and `buildSubscriptionResponse()` already key on.
- **Guarded on `$link->member_reference_num` being non-null before touching `Membership`
  at all** — a tenant deployment has no `brain_memberships` rows and this column is
  legitimately null there (04-subscription-state.md §4.2.2's own note); skipping cleanly
  keeps this fix inert off the Brain, matching every other Brain-only write site's own guard
  style (`BrainGuard`, `AdvancedConfigGate`) without needing a second, redundant domain check
  here.

## Verification performed

- `php -l` on the changed controller — clean.
- New feature test suite (4 tests, 15 assertions) — all pass, exercising the real HTTP route
  (`POST /portal/api/v1/directpayments/{dpRef}/subscription/update`) with `CheckpointIdentityResolver`
  and the Stripe action/reader classes faked at the container level (no real provider or
  Checkpoint calls), matching this repo's existing `DirectPaymentCheckoutPriceTest` pattern
  for this same controller.
- Full `tests/Feature/DirectPayments/` suite (91 tests, 249 assertions) — all pass; the new
  constructor dependency introduced no regressions in `checkout-price`, `cancel`,
  `set-payment-method` or the swap/webhook/tier-binding suites that share this controller or
  `MembershipTierResolver`.
- Full repository test suite run for regressions: 11 pre-existing failures, all in
  `Appointments`/`Builder`/`Ecommerce`/`Events`/`Forms` unit tests unrelated to billing,
  subscriptions or membership (confirmed by `git status` on this checkout showing only the
  controller and the new test file touched) — environment-domain-sensitive assertions
  (`https://www.test` vs. this vagrant lab's `appfactory.test1`), not something this change
  introduced.
- **Not** exercised against a live Stripe/Square/PayPal sandbox or the real Checkpoint auth
  server — this environment has no such credentials configured; the feature test fakes both
  seams at the container boundary rather than mocking HTTP, so it proves the controller's own
  logic (tier resolution and the conditional write) rather than the provider integration,
  which `14-subscription-actions.md`'s (now `www/direct_payments/28`) own test matrix already
  covers separately.

## Follow-ups

- **OQ-O** (whether `DunningService::advance()`'s healthy-path branch should also self-heal
  a drifted `membership`) remains open, exactly as flagged — a change to
  `08-brain-enforcement.md`'s own code, for a separate decision.
- **`membership_change/09-billing-tier-change-hook.md`** — the fast-notification observer —
  still doesn't exist. Without it, `members`' own resource reconciliation only reacts to this
  fix's writes on its existing hourly/on-view sweep cadence, not immediately. `membership`
  itself is correct the instant this endpoint returns; only how fast `members` *notices* is
  still what §17.7 said it would be.
- **`membership_change/10-preview-endpoint.md`** — the "see the consequence before it
  happens" dialog — still doesn't exist. A downgrade through this exact flow now silently
  starts the 30-day retirement clock on any resource above the new tier with no warning in
  the confirm dialog beyond price and proration, exactly the gap §17.4 named and deferred.
