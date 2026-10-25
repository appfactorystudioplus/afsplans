# 06 — Swap Engine v2 (complete — server side and admin wizard; provider calls and browser unverified)

## Status

**Complete 2026-10-17** for everything this step puts on the server and in the admin. Nothing is committed. Four things are deliberately outside it and listed first so they are not missed:

1. **Server-owned invoices (`../direct_payments/TODOS.md` P0) are still outstanding.** `pay` reads the amount from the *stored* invoice, but the invoice itself is still built by the browser. That is a separate plan.
2. **The SDK does not yet send `binding_version`** (step 08). The server answers it correctly and treats a caller that sends none as "route to the active binding".
3. **`pay` and `subscriptions/start` were tested with the provider controllers replaced by recording stubs** (the Stripe and PayPal intent/order calls use `curl` directly, so cannot be faked with the Http client). What is proven is *where the server sends the money, with what amount and plan*; the provider call itself is the pre-existing code.
4. **The wizard has been type-checked but not looked at in a browser**, and its text is English only (one new key, `directpayments-form.change_processor`, was added to the five language files).

Verification: on MariaDB 11.4.5, `tests/Feature/{DirectPayments,Processor,SiteUsers,Connections,Ecommerce,Events,Membership}` — **660 tests, 2672 assertions**. The full run had one failure (`SwapServiceTest::test_preview_counts_impact_correctly`, whose "saved cards" figure is now a real count); the test was updated and re-run green (10/10). `tsc --noEmit` is clean for `w/www` and for the SDK project. The suite was not re-run in full after that one test edit. MariaDB is stopped.

## Phases

| Phase | What shipped |
|---|---|
| P0 — schema | `laravel_direct_payments.binding_version` (default 1); `refund_window_days` on bindings (default 180); new `laravel_direct_payment_scheduled_changes` (reference `swc_`+12, kind change/go_live/revert, status scheduled/running/completed/failed/cancelled/superseded, `scheduled_hash`, pre-check and result columns, unique `pending_key` = one pending change per Direct Payment). Straight into the create migration; `addSafetyRailsSchema` patches older test schemas |
| P1 — version and router | `BindingRegistry::swap` adds 1 to `binding_version` in the same transaction (revert included). `ProcessorRouter::forNewBusiness($dp, $callerVersion)` → `RoutedProcessor`, or `BindingChanged` (`409 DP_E_BINDING_CHANGED` carrying `binding_version` and the processor's *public* config); `forExisting` resolves through any processor the DP was ever bound to. The version is part of the DP row returned by `GET /directpayments?direct_payment=` |
| P2 — DP-addressed endpoints | `DirectPaymentCheckoutController`: `GET /{dp}/checkout-config`, `POST /{dp}/invoices/{inv}/pay` (processor from the binding, pending invoice re-stamped, amount from the invoice, currency invoice → DP → usd), `POST /{dp}/subscriptions/start` (price from `TierBindingRegistry::priceFor`, customer from the payer — never from the page — and the link row written in the same request, A31) |
| P3 — compatibility shim | `ProcessorRouter::legacyGuard` on create-intent, create-setup-intent, create-session, PayPal create-order, Square create-payment and create-subscription: when the request names a Direct Payment (directly or through its invoice) and the processor named is not its active binding → `409 DP_E_BINDING_CHANGED` (rule A21). `processor_override` is ignored and logged (A22). A request tied to no Direct Payment is untouched |
| P4 — event | `DirectPaymentProcessorSwapped` (from, to, kind, version, binding), dispatched after the commit |
| P5 — preflight | `SWAP_E_PROVISIONING` (a tier with neither a plan nor a price on the target), `SWAP_E_PORTABILITY` (same-account-only / same-provider-only classes), `SWAP_E_TARGET_UNVERIFIED` as a **live** account lookup — skipped when the target has no fingerprint, run once *outside* the lock (the locked re-check does not call the provider). Real counts: payers with a saved method (from default methods), open disputes, other DPs on the same processor; warnings for each, plus live subscriptions and open invoices. The counts are in the impact hash, so a change in them makes the operator look again |
| P6 — refund window | Per-binding `refund_window_days`, used by `canRetire`; `PUT /{dp}/bindings/{id}`; listed in the bindings history |
| P7 — scheduled changes | `ScheduledChangeService`: schedule (acknowledges the same hash, **creates the target's plans for real**, stores a *material fingerprint* — target account, provider, mode, kind, portability class and differences, non-count warnings, provisioning state; counts are excluded), reschedule, cancel, supersede (an immediate change, a go-live or an archive), pre-check one hour before, run (atomic claim; a blocker or a fingerprint change makes it **fail**, not proceed; the scheduling operator is e-mailed). `direct_payments:run-scheduled-changes` runs every minute from `routes/console.php`. API under `/{dp}/swap/schedule`, `/swap/scheduled` |
| P8 — cron check | The command leaves a heartbeat; `schedulerStatus()` reports whether it ran in the last 3 minutes. The schedule response and the banner show a warning when it did not, pointing at `core/scripts/commands.sh --update-cron-task` (which already installs `schedule:run` every minute) |
| P9 — admin | `changeProcessorWizard.ts`: Choose → Prepare → Review → Confirm (now or at a date/time), go-live and move-back recognised, stale-hash handling back to Review, binding history with refund-window edit and Retire, banner for a pending / failed scheduled change with cancel and reschedule. Wired into the Direct Payment form (`Change processor…`). The processor list's Paused / Archived states were already done in step 16 |

## Files

**New (PHP):** `app/Externals/DirectPayments/Routing/{ProcessorRouter,RoutedProcessor,BindingChanged}.php`, `ScheduledChangeService.php`, `Events/DirectPaymentProcessorSwapped.php`, `app/Models/Externals/DirectPaymentScheduledChange.php`, `app/Http/Controllers/Externals/DirectPayments/DirectPaymentCheckoutController.php`, `app/Console/Commands/DirectPaymentsRunScheduledChanges.php`.
**New (TypeScript):** `resources/js/portal/admin/controllers/direct_payments/changeProcessorWizard.ts`.
**Changed:** both create migrations; `BindingRegistry`, `SwapService`, `DirectPaymentArchiver`, `DirectPaymentSwapController`, `ProcessorIntentController`, `ProcessorSubscriptionController`, `ProcessorSquarePaymentController`, `DirectPayment` / `DirectPaymentProcessorBinding` models, `routes/portal.php`, `routes/console.php`, `directpayments.ts`, `directpayments-form.html`, five `i18n/*.json`, `tests/Support/CreatesPaymentsSchema.php`, `SafetyRailsTestCase` (identity columns settable in `makeProcessor`), `SwapServiceTest` (saved-card count).

## Tests (`tests/Feature/DirectPayments/`)

`ProcessorRouterTest` (20), `SwapPreflightTest` (12), `ScheduledChangeTest` (23) — 55 new, all green. They cover the version, the 409 and re-stamp, amount from the stored invoice, the link row, the shim, the event, each new blocker and the live look (once, not in the lock), the real counts, the refund window, scheduling / cancel / reschedule / supersede / pre-check / run / revert / go-live, the atomic claim, the fingerprint failure, the command and heartbeat, and the schedule registration.

## Acceptance (§6.11)

| Criterion | Result | Evidence |
|---|---|---|
| A swap whose target fails any blocker writes nothing | ✅ | `SwapPreflightTest` (provisioning, unverified, portability: binding and version unchanged) |
| One transaction; a failed target lookup leaves the DP as it was (A13) | ✅ | `SwapAtomicityTest` (step 16), unchanged and green |
| A page at version *n* after a swap gets `DP_E_BINDING_CHANGED`, then pays the new processor; no new provider object on a retiring processor | ✅ at the server | `ProcessorRouterTest` — pay, subscriptions/start, checkout-config and the shim. The SDK re-init is step 08 |
| CONNECT cannot move to PayPal / Square, nor to another platform with connected accounts, by either path (A12) | ✅ | `SwapCompatibilityTest` (step 16) + `SWAP_E_PORTABILITY` |
| Live → sandbox refused; sandbox → live only through Go live; target needs a webhook (A14, A15) | ✅ | step 16 tests, unchanged |
| A swap and an immediate revert leave exactly two binding rows and no superseded payer customers | ✅ | `SwapRevertTest`; `ScheduledChangeTest::test_a_scheduled_revert…` (the same row comes back) |

## Deviations and residue

- **A tier with no price now blocks a change** (`SWAP_E_PROVISIONING`) where it used to be only a warning line; the plan asks for a blocker. A tier that has a price (a plan is created) or is already bound on the target does not block.
- **"Operator notified" is an e-mail to the scheduling operator** (taken from their dashboard login), plus the audit log and the banner. If the change was scheduled while the dashboard login was off (`unauthenticated`) no address is known and only the audit row and banner exist. There is no other operator-mail mechanism in this code base to reuse.
- **The cron "check" reports; it does not install.** Installing remains `commands.sh --update-cron-task`.
- **Scheduled changes are not in the SDK or the page** — only the admin sees them.
- **Square and PayPal behaviour from step 05 (†)** is unchanged and still unverified against provider sandboxes; scheduling and swapping onto those providers inherits that.
- **Per-binding refund window is a number of days only**; the plan's wizard text about it is a hint, not a calculation.
- The wizard reloads the page after a successful change instead of repainting in place.
