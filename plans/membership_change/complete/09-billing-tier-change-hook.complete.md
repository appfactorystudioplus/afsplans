# 09 — The Billing Tier-Change Hook — Complete

## Status

Done. `www` → `members`, fired by an Eloquent observer on every real write to
`brain_memberships.membership`, reusing the signed `www.internal` channel and the
`reconcile()` isolation already built for email. All three open questions (§9.6) resolved
as built; see below.

## Summary

`App\Observers\MembershipTierObserver` (`www`) watches `App\Models\Membership`'s
`created`/`updated` events and dispatches `App\Jobs\NotifyMembersOfTierChange`
(`ShouldQueue`, `afterCommit()`) whenever `membership` actually changes — signup included.
The job signs a POST to `members`' new `api/internal/www/v1/tier-changed` route
(`App\Http\Controllers\Www\TierChangeController`), which reconciles every resource the
member holds (`RetentionService::reconcile()` for storage, `EmailRetentionService::reconcile()`
per domain for email) against the current `membership` value immediately, instead of
waiting for the next hourly sweep.

This closes D8's third detection mechanism — the only one of the three named in
`00-overview.md` §5 that was never built (`billing:enforce-membership`'s 15-minute poll and
the opportunistic-on-read path were already shipped). It was blocked on
[billing/17](../../billing/17-self-service-tier-changes.md) shipping first, which it has
(`plans/billing/complete/17-self-service-tier-changes.complete.md` exists) — confirmed
before starting this work.

## Files changed

| File | Repo | Change |
|---|---|---|
| `app/Observers/MembershipTierObserver.php` | www | New. `created()`/`updated()` handlers, `->afterCommit()` dispatch |
| `app/Jobs/NotifyMembersOfTierChange.php` | www | New. `ShouldQueue`, `tries=3`, `backoff=[5,30,120]` — signed POST via `MembersSignature`, `->throw()` on failure so retries are meaningful |
| `app/Providers/AppServiceProvider.php` | www | `Membership::observe(MembershipTierObserver::class)` registered in `boot()` |
| `app/Http/Controllers/Www/TierChangeController.php` | members | New. Receives the notification, loops the two registered reconcile calls |
| `routes/www_internal.php` | members | `POST /tier-changed` added to the existing `www.internal` group — no new middleware |
| `tests/Feature/Observers/MembershipTierObserverTest.php` | www | New. 3 tests |
| `tests/Feature/Www/TierChangeControllerTest.php` | members | New. 3 tests |
| `tests/Feature/Membership/*.php` (4 files), `tests/Feature/DirectPayments/DirectPaymentSubscriptionUpdateMembershipTest.php` | www | `Queue::fake()` added to `setUp()` — see "Design decisions" below |

No new database table, no new signing mechanism, no new middleware — everything this
needed already existed for a different purpose, exactly as §9.7 said it would.

## Design decisions not fully spelled out in the plan text

- **`created()`/`updated()`, not one `saved()` handler on `wasChanged('membership')` +
  `wasRecentlyCreated`, as §9.4's pseudocode sketched.** Building this surfaced two real
  bugs in that literal design, both caught by tests before they shipped:
  - `wasChanged()` alone misses signup entirely. `Model::performInsert()` never calls
    `syncChanges()` (only `performUpdate()` does), so a brand-new row's `saved` event has
    an empty `$changes` array regardless of what was set — `MembershipController::store()`'s
    `Membership::create()` write (write site #1) would have silently never fired the hook.
  - `wasRecentlyCreated` is not a "this save just inserted" flag — Eloquent sets it `true`
    on insert and never resets it. A `saved()` handler checking it, on a model instance
    fetched once and saved twice in the same process, would keep reporting `tierBefore:
    null` for every write after the first, forever.

  Two separate Observer methods, each tied to the Eloquent event that fires exactly once
  per real insert/update, sidesteps both problems structurally rather than adding more
  conditionals to work around them. `tests/Feature/Observers/MembershipTierObserverTest.php`'s
  second test (`tier_write_dispatches_with_before_and_after`) exercises exactly the reused-
  instance case that would have caught the second bug.

- **OQ-K, resolved as the plan's own leaning: no distinction by `tier_source`.** The
  observer fires for every real write regardless of who made it (admin `updateTier()`,
  billing `DunningService`, or self-service `DirectPaymentSubscriptionController::update()`
  per billing/17) — an operator granting a tier bump wants it applied immediately, same as
  a paid upgrade. This falls out of the "catch every write site structurally" design
  (§9.3) rather than needing separate code.

- **OQ-L, built as an explicit case.** `TierChangeController` checks both
  `EmailBandUsage` and `BucketUsage` for the member before doing anything; a
  member with no known holdings anywhere (a fresh signup, tier written before any resource
  is touched) returns `{"success": true, "reconciled": []}` and logs an explicit
  info-level line, rather than silently calling `reconcile()` on nothing and looking
  identical to a request that reconciled real holdings. `TierChangeControllerTest`'s
  `test_no_known_holdings_is_an_explicit_no_op` proves this.

- **OQ-M, resolved as "just two lines," not a registry.** `TierChangeController` hardcodes
  the two calls (`RetentionService::reconcile()`, `EmailRetentionService::reconcile()` per
  domain). Per §9.6's own framing, this is worth revisiting when a third resource's
  backward edge needs to join, not before.

- **`NotifyMembersOfTierChange` throws on a non-2xx response (`->throw()`), unlike
  `MembersEmailClient`'s swallow-and-log pattern for the opportunistic-on-read call.**
  That client is inline in a page load with no retry machinery — swallowing is correct
  there (a member should see their accounts whether or not `members` is reachable). This
  job has `tries`/`backoff` specifically so a transient failure gets retried; not throwing
  would make those retries meaningless (Laravel's queue only retries on an actual
  exception). Failure after all retries is still safe by design (§9.5) — `failed()` logs
  and the hourly sweep is the backstop either way.

- **Discovered while running the full regression suite, not anticipated by the plan:**
  registering a global Eloquent observer means *every* `Membership` write anywhere in the
  app now fires this job — including in tests that have nothing to do with this feature.
  `QUEUE_CONNECTION=sync` in `phpunit.xml` means an unfaked queue runs the job inline, and
  `MEMBERS_INTERNAL_API_URL` is a real (unreachable-from-test) vagrant-lab URL, not empty —
  so five pre-existing `tests/Feature/Membership/*` files plus
  `DirectPaymentSubscriptionUpdateMembershipTest` started making real 10-second-timeout
  HTTP calls per membership write, turning a ~20s run into ~230s with 19 test-method
  failures, none in this feature's own tests. Fixed by adding `Queue::fake()` to each
  affected file's `setUp()` — the correct Laravel idiom for "this test doesn't care about
  a side-effecting job," not a change to production code. This is the same class of
  cross-file regression billing/17's own completion doc flagged verifying for
  (`DirectPaymentCheckoutPriceTest`, `cancel`, `set-payment-method`, swap/webhook suites);
  here it just happened to be a global observer rather than a shared constructor
  dependency.

## Verification performed

- `php -l` on every new/changed PHP file — clean.
- New test suites — `tests/Feature/Observers/MembershipTierObserverTest.php` (3 tests: signup
  dispatches with `tierBefore: null`, a later write to the *same* instance dispatches with
  the correct before/after, an unrelated column change does not dispatch) and
  `tests/Feature/Www/TierChangeControllerTest.php` (3 tests: a downgraded member with both
  storage and email holdings gets both reconciled and the response says so, a member with
  no known holdings gets the explicit no-op, an unsigned request is rejected 401) — all
  pass.
- `members`' full test suite (`php artisan test`, no path filter) — 54 passed, 0 failures.
  Storage and email retention suites specifically re-verified passing unchanged.
- `www`'s `tests/Feature/DirectPayments/`, `tests/Feature/Membership/`, and
  `tests/Feature/Observers/` — 120 passed, 0 failures (after the `Queue::fake()` fix above;
  before it, 19 of these failed on real-network timeouts caused by this change).
- `www`'s full test suite (`php artisan test`, no path filter) — 562 passed, 11 failed.
  All 11 failures are in `Appointments`/`Builder`/`Ecommerce`/`Events`/`Forms`, pre-existing
  and environment-domain-sensitive (`https://www.test` vs. this vagrant lab's
  `appfactory.test1`) — the exact same failure class billing/17's own completion doc
  documented at the same count (11). None touch `Membership`, `DirectPayments`,
  `Billing`, or `Observers`.
- **Not** exercised against a real running `members` instance end-to-end (no live queue
  worker, no live HTTP round-trip between the two real apps) — this environment's tests
  prove each side of the contract independently (the observer dispatches the right job
  with the right payload; the controller reconciles correctly given a valid signed
  request), the same boundary billing/17's own verification drew for its provider
  integrations.

## Follow-ups

- **§9.6's registered-reconcile-callbacks list (OQ-M)** is still just two hardcoded calls
  in `TierChangeController`. Revisit when a third resource's backward edge needs to join.
- **No dead-letter handling or manual replay UI was built**, per §9.5 — exactly as
  designed. A permanently unreachable `members` still self-heals via the existing hourly
  sweep, at most an hour late.
- **`membership_change/10-preview-endpoint.md`** — the "see the consequence before it
  happens" dialog — still doesn't exist, unrelated to this document's scope.
