# 15 — Experimental billing controls: completion report

Written 2026-10-22. Plan: `../15-stripe-experimental-billing-control.md`. Nothing committed, nothing uploaded, migration `2026_10_22_000002` not run on dev.

## What was built (all uncommitted)

**Server (`w/www`)** — `app/Externals/DirectPayments/Experimental/`: `ExperimentalActions` (the one list of 35 controls: area, rules, preview words, dangerous flag, path, method), `ExperimentalProvider` + `StripeExperimental`, `SquareExperimental`, `PayPalExperimental`, `Api/{Stripe,Square,PayPal}Api` (Http facade), `ExperimentalService` (validate, refuse what the provider cannot do, preview, require reason + idempotency key when dangerous, audit, replay, re-sync), `ExperimentalProviders`, `ExperimentalContext`, `ExperimentalException` (`DPX_E_UNSUPPORTED` 409, `DPX_E_PROVIDER` 502, `DPX_E_INPUT` 422, `DPX_E_NOT_FOUND` 404, `DPX_E_REPLAY` 409). Controller `ProcessorExperimentalController`; routes generated from the list in `routes/externals.php` under `processors/` (before the greedy `/{id}` group), `ApiAuthMiddleware`, no flag, no level gate, nothing under `brain/v1`. Plus `GET processors/capabilities`, `GET processors/inspect/{object}`. `ProviderCapabilities::experimental()` (kept out of `for()` so the public output is unchanged). Audit/idempotency table `laravel_direct_payment_experimental_log` (migration + model).

**SDK** — `resources/js/external/api/direct_payments/experimental.ts` (routes come from the server's capabilities), `dp.experimental` getter in `api.ts`, `@experimental` types in `external/api/portal.d.ts`. Other repos' copies of `portal.d.ts` were NOT refreshed.

**Dashboard** — `services/memberStaffX.ts` (forms, text→params rules, wording), `controllers/brain/brain_member_controls.ts`, a "Billing controls (Experimental)" card in `brain_memberships_subscription_content.html`, mounted from `brain_member_subscription.ts`.

**Phone** — `lib/features/billing_controls/` (models, forms, repository, page), button on the Subscription tab. Same processor routes as the dashboard.

## Tests run (only what was touched)

- PHP `ExperimentalControlsTest`: 15 tests, 122 assertions, pass (local MariaDB).
- Admin `memberStaffX.test.ts` + SDK `experimental.test.ts`: 16 pass.
- Flutter `test/features/billing_controls`: 17 pass; `flutter analyze` clean on the new and edited folders.
- Not run: the rest of the suites; esbuild syntax checks passed on the edited TS.

## Differences from the plan

- Square one-member price, Square invoice list needing a location, PayPal cards/customer: **not implemented** (`no`). PayPal invoices not implemented (its subscription charges are not invoices).
- Per-member dunning override dropped (Stripe has no per-subscription retry rules); `retry_now` covers it.
- Refund stays on the existing `money/charges/{id}/refund` route.
- Square and PayPal rows are built from documentation only: every one is `confirmed: false` and the clients say so. Stripe rows are also unrun against the real Stripe API (tests use `Http::fake`).
- `card_update_link` saves a card but does not make it the default; use "Make a card the default" afterwards.
- Subscription ids still come from the existing Brain read `brain/v1/staff/members/{ref}/subscription` (open item in the plan).

## To do before it is live

Run the migration on dev, build the front end on the server, try a Stripe test-mode subscription through each area, then sandbox-confirm Square and PayPal rows.
