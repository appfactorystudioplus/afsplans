# 04 — Server additions — Completion

**Verified:** 2026-10-04 — PHP lint on every file, `php artisan route:list`, a no-database capability smoke test, and live no-token probes of lab2 for BS2/BS3/BS4/BS7 (all 401 after deployment). **No PHPUnit file has been executed and BS6/BS8/BS9 have not been deployed or exercised against any database.** No MySQL is reachable from the authoring PC (`127.0.0.1:3306` and `192.168.56.11:3306` are closed) and lab2's database is deliberately not written to.

**Status:** **Complete in code; not verified by execution.** Every required item is written: BS1, BS2, BS3, BS4, BS5, BS6, BS7, BS8, BS9, BS10, BS12. **BS11 (optional) is deliberately not built.** The remaining work is running the tests and the regression below, then deploying BS6/BS8/BS9.

## What was built

| Item | State | Evidence |
|------|-------|----------|
| **BS1** `brain:device-token` + `brain_device_tokens` | ✅ deployed; token minted on lab2 | See [02 completion](02-authentication-device-token.complete.md) |
| **BS2** `GET brain/staff/whoami` | ✅ deployed | `StaffWhoamiController`, `StaffCapabilities` (computed from registered routes, never configured) |
| **BS3** list filters + `summary=1` | ✅ deployed | `MembershipController::index`; no-param response unchanged |
| **BS4** `GET brain/staff/members/{ref}` | ✅ deployed, one gap | `StaffMemberController`. **`subscription_summary` is still always `null`** — a gap against the spec, not a decision. BS6 now exists, so it could be filled from `StaffSubscriptionResolver`; it was not, because file 06's screens do not use it. Fill it when the member header needs a subscription chip |
| **BS5** address edit | ✅ deployed | `MembershipController::update`, `BRAIN_FLUTTER_ADDRESS_EDIT` marker |
| **BS6** staff subscription surface | ✅ written, **not deployed, not run** | `Brain/Staff/BrainStaffSubscriptionController.php` (show, sync, cancel, plans, change-tier); `Services/Brain/Staff/StaffSubscriptionResolver.php` + `ResolvedSubscription.php` (the four paths: `governing` via `MembershipTierResolver`, `payer_link`, `payer_customer`, `none`, with a warning per fallback); `StaffSubscriptionPresenter.php` (one response shape, computed `allowed_actions`, `drift`, `access_ends_policy`); `StaffAudit.php`; `DirectPayments/Subscriptions/SubscriptionActionsFactory.php` |
| **BS7** `GET brain/staff/create-options` | ✅ deployed | `StaffCreateOptionsController` |
| **BS8** `GET brain/staff/members/{ref}/payments` | ✅ written, **not deployed, not run** | `StaffPaymentsController`: both storage shapes of `customer_id`, `refunds` summed only when `succeeded`, `refundable_cents` 0 unless the charge succeeded, `limit` clamped 1–200, `[]` (not an error) with no payer or charges; **each row carries `processor_mode` (added for file 07), resolved through the charge's Direct Payment, falling back to the charge's own processor, null when neither is known** |
| **BS9** `GET brain/staff/billing/overview` | ✅ written, **not deployed, not run** | `StaffBillingController`: enforcement from `brain_billing_settings` (off when there is no row), counts incl. `no_state`, attention sorted by soonest `downgrade_after`, undated last, `current` excluded, limit 50 |
| **BS10** audit + self-protection | ✅ | `DeviceCaller`; `MembershipController` writes and the three BS6 events (`staff_subscription_synced`, `_canceled`, `_tier_changed`) are `admin_mobile` + device label for a registered device token, `admin` otherwise |
| **BS11** discount / billing-amount / billing-date | ❌ **not built, on purpose** | Optional, phase P5b, behind capability `subscription_pricing`, which `whoami` therefore reports `false`. Changing a recurring amount is the riskiest action in the estate, and it needs the logic that is private in `ProcessorBillingController` (whose routes are the unauthenticated ones audit BA10 flags) extracted first. Build it only on request |
| **BS12** `config('brain.membership_direct_payment')` | ✅ deployed | `config/brain.php` |

## The refactor (the only edit to existing money-moving code)

`DirectPaymentSubscriptionController::actionsFor()` is now one line delegating to `SubscriptionActionsFactory`, which holds the same `match` over the same three constructor-injected providers. The controller's constructor swaps `StripeSubscriptionActions`, `SquareSubscriptionActions`, `PayPalSubscriptionActions` for the factory; behaviour is meant to be identical and nothing else in that file changed. The existing test `DirectPaymentSubscriptionUpdateMembershipTest` binds a fake `StripeSubscriptionActions` into the container, which the factory still receives, so it should keep passing — **but it has not been run.**

## Divergences from the plan

- **BS6 events on a browser caller.** The plan says `admin_mobile`; the audit is device-aware (a browser calling a staff route would be `admin`), consistent with BS10 elsewhere.
- **`allowed_actions.change_tier`** is offered when at least one *other* tier of the link's Direct Payment is priced on the subscription's processor. The plan also says "absent when the provider cannot change plans"; that cannot be known without calling the provider, so a provider that refuses (PayPal) is reported at action time as `422 BS_E_NOT_ALLOWED`.
- **`tier_name`** is the tier's `custom_title`, falling back to the processor plan's name.
- **`drift.matches`** is `null` (not `true`) when the tier paid cannot be determined.
- **Self-protection on the new routes applies to every caller**, not just device tokens (§4.2 wording); on the existing member routes it applies only to device tokens so the browser console is unchanged.
- **BS4 `level`** is returned in `member` (spec: all columns but `json_data`); the list's `summary` row omits it. `json_data` is whitelisted to `discount_code`, `tax_rate`, `tax_cents`.
- **`PUT memberships/{ref}` now validates lengths** (names/address); a browser sending only names behaves as before.

## Tests (written, not run)

`BrainDeviceTokenCommandTest` (11), `BrainStaffWhoamiTest` (7), `BrainStaffMembersTest` (10), `BrainStaffWriteTest` (14), **`BrainStaffSubscriptionTest` (new, 34)**: access control on every route, the four resolver paths, drift, billing state, plans (current / unpriced), allowed actions; sync (success, failure → 502 with no event, none → 404); cancel (reason required and provider never called without one, access-end from the pre-cancel `next_charge_at`, idempotent with no second provider call or event, provider failure leaves the link uncancelled, stale 409, matching timestamp accepted); change-tier (validation, current and unpriced plans refused before any provider call, success updates provider/link/membership and audits before/after, default proration, `PlanChangeNotSupported` → 422 and nothing changed, provider failure → 502 and nothing changed, cancelled refused, stale 409); self-action refused on all three; payments (both id shapes, refunds, refundable, limit clamp, empty cases); overview (off without a row, counts, ordering, `current` excluded). The provider actions and `SubscriptionSyncService` are faked at their seams, so **no test can reach Stripe, Square or PayPal**. The settings row is only ever inserted and deleted by id, and the test skips rather than touch an existing row. Support: `tests/Concerns/CreatesStaffSubscriptionTables.php` (creates each table only if absent; never drops).

## To finish verifying this file (all yours)

1. In `w/www`, with a reachable test database: `php artisan test --filter=BrainStaff`, `--filter=BrainDeviceToken`, and **the regression the plan requires**: `php artisan test tests/Feature/DirectPayments` (the self-service controller after the factory extraction).
2. Deploy `w/www` to lab2 as in `handoff-share.md` (no migration), then probe with no token: `…/brain/staff/billing/overview`, `…/members/x/subscription`, `…/members/x/payments` must answer 401, not 404.
3. After deployment `whoami` reports `subscription_staff` and `billing_overview` as `true`; the app has no screen for them until file 07 is built, so nothing visible changes on the phone.
