# 13 — Naming and Separation (complete — gate passes with no exception)

## Status

**Complete 2026-10-17.** Nothing is committed (`www`, plus one-line changes in `members` and `market`). The §13.6 gate is now a PHP test (`tests/Unit/DirectPayments/VocabularyGateTest.php`) and **passes** with no exception (the one it had, `BillingMailer`, went with step 15). The full seven-folder suite was **not** run; the 16 affected test files were run and pass (list below). Both `tsc` projects are clean.

Said plainly: the gate allows (a) the words a provider's own API needs (`billing_cycle`, `billing_details`, `billing_info`, `v1/billing/`, …) and (b) the two other plans' marker names `AFS_BRAIN_FLUTTER_MODIFIER_ADDITION` / `AFS_MEMBERSHIP_MODIFIER_ADDITION`, which are marker names, not Direct Payments vocabulary and which the marker rule forbids renaming.

## Phases

| Phase | What shipped |
|---|---|
| P0 — classes and commands | `BillingInterval` → `PlanInterval`; `BillingBackfillDpSubscriptions` → `DirectPaymentsBackfillSubscriptions`, `BillingSubscriptionNotify` → `DirectPaymentsSubscriptionNotify` (signatures unchanged); `Dunning\` namespace → `RetryPolicy\` (`RetryPolicy`, `RetryPolicyResolver`; appfactory's own `DunningService` keeps its name) |
| P1 — controller split | `ProcessorBillingController` (1,400 lines) → `ProcessorRefundController`, `ProcessorSubscriptionAdjustmentController` (`applyDiscount`, `updateSubscriptionAmount`, `updateRenewalDate`, field `renewal_date`), `ProcessorCustomerSyncController`, `ProcessorPaymentMethodController`, sharing a `ResolvesProcessorCustomer` trait. New routes `/processors/refunds`, `/refunds/by-payment-intent`, `/subscriptions/{discount,amount,renewal-date}`, `/customers/sync`, `/payment-methods*`; the old `/processors/billing/*` paths stay as aliases for one release **except renewal-date** (its field was renamed). `RoutePolicy` updated; SDK, the Brain admin screen and the market payout client moved to the new paths |
| P2 — config | `sync_limit_per_run`, `default_notice_window_days`, `sends_per_run` moved from `config/billing.php` to `config/direct_payments.php` (`DIRECT_PAYMENTS_*`); **no fallback to the old `BILLING_*` env names** (D-DEV; the plan allowed one release of fallback, which would have left `BILLING_*` in Direct Payments config) |
| P3 — Connect | `member_reference_num` removed from `laravel_processor_connected_accounts`; `connect_user` is the only owner; `created_by_member_reference_num` → `created_by`, migration accounts' `member_reference_num` → `connect_user` (the create migration, no data); `createSession` checks `connect_user`; SDK/typings/docs/playground lose `member_reference_num`. **members** repo: its one reader of the old Direct Payments status field now reads `connect_user` (marked as an appfactory change) |
| P4 — strings | `[Billing][…]` → `[DirectPayments][…]` log tags; PayPal cancel reason "Cancelled by the subscriber."; "still renewing" in blockers, swap text and the migrate command; `manage_billing_url` → `manage_subscription_url` (a host sets `direct_payments.manage_subscription_url`; the appfactory provider points it at the Brain's page); `$billing…` variables renamed |
| P5 — SDK and samples | `member_ref` removed from `dp.subscription.{get,cancel,update,setPaymentMethod,history}`; `billing_period` → `show_interval` (**old name not accepted**, unlike the plan's one-release allowance — same reason as the env names); README seller refs, dashboard sample takes a website user id, "Payment details" headings |
| P6 — markers and plan refs | 92 files in Direct Payments / processor code, migrations and tests re-tagged `AFS_BILLING_MODIFIER_ADDITION` → `AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION`; `plans/billing/NN` references re-pointed to `plans/www/direct_payments/2x`; `create_member_redo/NN` references reworded to what they describe. Note added to `plans/www/direct_payments/README.md` (§13.4) |

## Tests

* New: `VocabularyGateTest` (the gate).
* Run and passing after fixes: `ChargeParsersTest`, `LayeringTest`, `RoutePolicyTest`, `RefundResolvesFromChargeTest`, `ProcessorPaymentMethodSecurityTest`, `SubscriptionMigrateCommandTest`, `BindingRetirementTest`, `DirectPaymentSwapControllerTest`, `SdkContractTest`, `ExtensionPointsTest`, `MoneyLedgerMemberResolutionTest`, `DirectPaymentSubscriptionUpdateMembershipTest`, `WebhookOrphanRecordingTest`, `ProcessorWebhookRegistrationTest`.
* Fixed on the way: registration tests expect the per-processor URL from step 10 (`&p=`); `SubscriptionMigrateCommandTest` and the update-membership test now build the real production tables instead of hand-built subsets; `RoutePolicy` now lists the nine step 04/07/08 public write routes on purpose (they were failing the policy test since those steps).
* `DirectPaymentSubscriptionUpdateMembershipTest` tests appfactory behaviour, so it moved from `tests/Feature/DirectPayments` to `tests/Feature/Brain`.

## Deviations and residue

* *(Resolved in step 15: `NotificationDispatcher` sends through `MailHandlerSender`; the gate test has no exception left.)* **Payment-method endpoints are not removed** (the plan says "removed in favour of the payer-scoped endpoints"): the Brain's admin screen still uses them, so they moved to `ProcessorPaymentMethodController` under `/processors/payment-methods` with aliases.
* **Routes file markers not re-tagged**: `routes/portal.php` mixes appfactory and Direct Payments blocks; its `AFS_BILLING_MODIFIER_ADDITION` lines are untouched. The `plans/markers.md` list already contains the new marker; no prose note was added to it (it is a bare list) — the re-tag is recorded in the §13.4 note instead.
* **SDK `getPayments()`** now calls `/processors/customers/payments`; there is still **no such route** (the old `/billing/payments` was equally absent) — it fails exactly as before.
* The old `/processors/billing/billing-date` path is gone; callers must send `renewal_date` to `/processors/subscriptions/renewal-date`.
* `config/billing.php` keeps appfactory's own settings; the compiled SDK bundle (`public/external/api/sdk.js`) and `resources/js/external/api/build/` were not rebuilt.
* Plan §13.5 (table names) was already true; nothing to do.
