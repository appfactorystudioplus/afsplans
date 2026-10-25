# 09 — Extension Points (complete — built and tested with Direct Payments alone; the appfactory side was built minimally)

## Status

**Complete 2026-10-17**, committed in `www` as `bbfb108a6`. The appfactory implementations (M1–M6) were built without an explicit go-ahead for step 14, so they are the smallest thing that keeps studio behaviour; step 14 owns their final shape. The full seven-folder suite was **not** run to the end for this step (a run takes over 30 minutes because every test rebuilds ~100 tables); the affected files were run instead: the two new classes plus webhook, refund, ended-subscription, payer-identity, swap, contract, migration, dispute, payment-gap, charge-hook and Brain ledger tests, all passing after three test-fixture fixes (committed). Both `tsc` projects are clean; `npm run test:external-api` was not run.

## Phases

| Phase | What shipped |
|---|---|
| P0 — contracts | `App\Externals\DirectPayments\Contracts`: `SubscriberIdentity` (`resolve(Request): ?ResolvedSubscriber`), `SubscriptionResponseDecorator`, `PayerDisplayResolver`, `AdvancedConfigAuthority`, `RetryPolicyDefaults` (typed to the existing `DunningPolicy`; the rename to RetryPolicy is step 13) |
| P1 — identity | `PayerIdentity` → `SubscriberIdentity`, chain built from `direct_payments.identity_sources` (Site Users, then the subscription token — now also `X-Subscription-Token`). `DirectPaymentSubscriptionController` no longer touches Membership or Checkpoint; `member_ref` is gone |
| P2 — defaults | `Defaults/`: no decorator, payer-e-mail label, any dashboard administrator may write advanced settings, built-in retry policy (grace 14, reminders 3/7/12, **off**). A decorator can only add keys; one that throws is logged and skipped |
| P3 — notifications | Recipient order payer e-mail → provider customer e-mail → none (`skipped: no_recipient`); names/e-mail from the payer, never a host record |
| P4 — events | `SubscriptionPlanChanged::$warning` removed: events carry no reply channel |
| P5 — appfactory side | `AppfactoryDirectPaymentsServiceProvider` (flag `billing.direct_payments_host`, default on) registers `MembershipSubscriberIdentity`, `MembershipBillingStateDecorator`, `MembershipPayerDisplayResolver`, `SiteOwnerAdvancedConfigAuthority` (the level-99 rule moved out of `AdvancedConfigGate`, which is deleted), `BrainRetryPolicyDefaults`; `ApplyTierOnPlanChange` does nothing when the flag is off |

## Tests

`ExtensionPointsTest` (18, passing): Site User show/cancel/update with no host; card change stops at the provider rule; unauthenticated is 401; subscription token; chain order; decorator adds only and failures are skipped; ledger label default and replacement; advanced-settings authority default / closed / fail-closed; retry defaults inert vs supplied; contact e-mail order; names from the payer; no `warning` on the event or response; host-on member recognition and billing state; container bindings follow the flag; **G5** source gate (no `App\Models\Membership`, `brain_`, `CheckpointIdentityResolver` in the Direct Payments / processor code).

## Deviations and residue

* *(Update: the §13.6 vocabulary gate is now clean — step 13 — and M7, M9 and M10 were built in step 14.)*
* `NotificationDispatcher` still sends through `BillingMailer` (steps 13/15).
* `RetryPolicyDefaults` returns `DunningPolicy` for now.
* `DirectPaymentAdvancedController` now resolves the authority per request (it was a constructor dependency) so a rebinding takes effect.
* The ledger's `payer_link` and the `MoneyView.ts` change were **not checked in a browser**.
* Existing tests that hand-build tables were adjusted only where they hit the new code (`CreatesStaffSubscriptionTables` now adds the safety-rails schema).
