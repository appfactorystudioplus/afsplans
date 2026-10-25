# 14 — appfactory studio Modifications (complete — M1–M10 all in place; staff-tool readers in §14.4 left as listed)

## Status

**Complete 2026-10-17.** Nothing is committed (`www`, `members`, `market`, `afsserver`). All of these are appfactory-side changes; Direct Payments depends on none of them. M1–M6 and M8 were built in step 09 without a go-ahead for step 14 (the smallest version that kept studio behaviour); this step checked them against §14.2, built **M7, M9 and M10** (M9/M10 were "set aside" by OQ11; the owner asked for the whole step), and tested them. `tsc --noEmit` is clean in `www`, and `members` shows no error in the files touched. The full suite was not run; the affected files were.

## Phases

| # | Modification | State |
|---|---|---|
| M1 | `MembershipSubscriberIdentity` | built in 09; `ExtensionPointsTest` (member recognised first, 401 for nobody) |
| M2 | `MembershipBillingStateDecorator` | built in 09; tested (billing state and days until downgrade arrive through the decorator) |
| M3 | `MembershipPayerDisplayResolver` | built in 09; tested; ledger test `MoneyLedgerMemberResolutionTest` |
| M4 | `SiteOwnerAdvancedConfigAuthority` (level-99 rule, fails closed) | built in 09; tested |
| M5 | `BrainRetryPolicyDefaults` | built in 09; tested (inert until the Brain switch is on) |
| M6 | `ApplyTierOnPlanChange` logs and reports its own failure; no `warning` in the event or response; also does nothing unless the host flag is on | built in 09, flag guard added in 09 follow-up; tested |
| M7 | The members dashboard (`BillingApp.ts`: get, history, update, cancel, setPaymentMethod) no longer sends `member_ref`; the `member_ref` option is gone from the three `portal.d.ts` copies (www, members, market) and a stale comment about it; the two Connect typings now say `connect_user` | **built now**; `members` tsc shows no error in the touched files |
| M8 | `AppfactoryDirectPaymentsServiceProvider` behind `billing.direct_payments_host`; also points the e-mails' "manage your subscription" link at the Brain's page | built in 09/13 |
| M9 | `MembershipTierResolver` asks each of the member's subscriptions for its **current leg** and that leg's provider subscription, instead of joining on a provider id stored on the contract. An `ended`/`incomplete` subscription never governs; `migrating` does. Rows with no legs (older data) fall back to the old join | **built now**; `TierResolverFollowsSubscriptionTest` (one live subscription; after a move to another provider the member still resolves; ended → unknown, not zero; no payer → nothing) |
| M10 | The signup claim moved into `App\Services\Billing\SubscriptionClaim`: claims by the Direct Payments subscription reference (`sub_…`); a provider id of any leg still resolves to the same subscription; the one conditional write and the retry-by-same-payer rule are unchanged | **built now**; tests: claim by reference, claim by a later leg's id, replay refused / retry allowed / unknown handle refused; the existing signup-claim and payer-resolution tests still pass |

## Deviations and residue

* **M10 is a refactor of the claim, not a change of who may claim.** The old test `MembershipSignupTierClaimTest` replicates the claim block rather than calling the controller; it still passes but does not exercise `SubscriptionClaim` — the new tests do. `MembershipController::store()` itself was not run end to end.
* **The signup page and SDK still send the provider subscription id** as `membership_id`; that keeps working. Switching them to send the `sub_…` reference (Direct Payments returns it from `startSubscription`) is a client change not made here.
* **§14.4 readers** (DunningService, the Brain staff resolver/presenter/controllers, `BillingEnforceMembership`) still read Direct Payments tables directly. The tier resolver they rely on is fixed (M9); the rest were left as the plan lists them, at appfactory's own pace.
* **§14.3a notes** need no change: archived Direct Payments and allowances are appfactory's call; receipts keep going to every attached handler unless a `payment_receipt` setting exists (step 15).
* The members dashboard change is a code edit only; it was not run in a browser.
