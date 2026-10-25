# 07 — Direct Payments API Surface — Complete

## Status

Done for the parts this step owns outright (§7.2, most of §7.3, §7.4, §7.5's type
declarations for what was built). Three pieces are explicitly deferred to the plan steps
that own them, and one pre-existing gap was discovered and flagged rather than silently
worked around. See "Deferred" and "Known gaps" below.

## Summary

Built the public, read-only halves of the external SDK surface: `dp.notifications`
(list/get, filtered to `event`/`days_before`/`active`), `dp.subscription.get()` (server-side
identity resolution via a new Checkpoint bearer-validation proxy, `member_ref` as a
checked hint only, 401/403 per §7.3's rules), and the full `dp.on`/`dp.off` client-side
event emitter wired into every payment/subscription/payment-method completion point in
`api.ts`. Declared all of it in `portal.d.ts`. Documented in the SDK's own README and in
`PROCESSOR_DOCUMENTATION.md`.

## Files changed

### Backend
| File | Change |
|---|---|
| `www/app/Services/Checkpoint/CheckpointIdentityResolver.php` | New. Resolves a Bearer token to a `brain_memberships.reference_num` by proxying to the central Checkpoint server's `/checkpoint/auth/token/validate` (`AuthController::validateToken()` → `AuthenticationService::verifyToken()` → `attachMember()`) — the same server-to-server pattern `AllocationV2Controller`/`MemberInitController` already use. Fails closed (any transport error, non-2xx, or `valid: false` → null) |
| `www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentSubscriptionController.php` | Added `show()` — `GET .../{dpRef}/subscription`, §7.3's identity rule (401 unresolvable, 403 on a `member_ref` mismatch, 404 no subscription). Also **upgraded** `store()`'s (step 04) member resolution to try `CheckpointIdentityResolver` first, falling back to the provider-customer-id match — closing the gap step 04's completion doc flagged, now that a real resolver exists |
| `www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentNotificationController.php` | Added `publicList()` — `GET .../{dpRef}/notifications`, filtered to `event`/`days_before`/`active` only |
| `www/routes/portal.php` | `GET /{dpRef}/notifications` and `GET /{dpRef}/subscription`, both in the existing public `directpayments` route group |

### External SDK
| File | Change |
|---|---|
| `www/resources/js/external/direct_payments/api.ts` | New `DirectPaymentNotificationsApi`/`DirectPaymentSubscriptionApi` classes; `get notifications`/`get subscription` lazy getters (mirroring `get connect`'s pattern); `_listeners` map + `on()`/`off()`/`_emit()` (throwing listeners caught and logged, never propagated); `confirmPayment()` split into a thin wrapper (`_emit`s `payment:succeeded`/`payment:failed` for the single-pay flow only) calling a renamed `_confirmPaymentInner()`; `subscription:created`/`subscription:failed` emitted from all three subscription-creation points already instrumented for step 04's link-write (`setupSubscriptionPayment`'s Stripe/Square branch, its PayPal `onResult` callback, `_confirmSubscriptionPaymentInternal`'s Square branch) plus `charge()`'s subscription branch; `paymentmethod:added` from `confirmCardSetup()` (the actual completion point, not `addPaymentMethod()` which only mounts the UI); `paymentmethod:removed` from `deletePaymentMethod()` |
| `www/resources/js/external/portal.d.ts` | `PortalDirectPaymentNotificationRow`/`PortalDirectPaymentNotificationsAPI`, `PortalDirectPaymentSubscriptionState`/`PortalDirectPaymentSubscriptionAPI` (only the fields this step implements — `cancel`/`update`/`setPaymentMethod`/`history` are declared by steps 14/15 when they land), `PortalDirectPaymentClientEvent`, and `notifications`/`subscription`/`on`/`off` on `PortalDirectPaymentsAPI` |
| `www/resources/js/external/direct_payments/README.md` | New "Subscription lifecycle" section (§7.2–§7.4), method table rows for `get notifications`/`get subscription`/`on`/`off` |
| `www/resources/js/external/processors/PROCESSOR_DOCUMENTATION.md` | New subsection under §9 on the normalised `next_charge_at` field and the "never branch on provider" rule (§4.3) |

## Deferred to the plan steps that own them

- **`dp.subscription.cancel()` / `.update()` / `.setPaymentMethod()`** — 14-subscription-actions.md. Not implemented; `portal.d.ts` does not declare them yet, so a consumer calling them today gets a compile error rather than a silent `undefined` — the honest failure mode.
- **`dp.subscription.history()`** — 15-money-and-refunds.md §15.5, which owns the underlying charge/refund tables this would read. Same treatment: not implemented, not declared.
- **The worked playground page** (`public/playground/direct-payments/subscription_lifecycle/`, §7.6) — not built. It is explicitly described as a manual test fixture for step 11 (rollout-and-testing), and with three of the surface's methods (`cancel`/`update`/`history`) not existing yet, a page exercising "the subscription lifecycle" end to end would be materially incomplete regardless. Flagged for step 11 rather than built early and half-working.

## Known gap discovered this step — `members`' `portal.d.ts` copy is already stale

§7.5 requires refreshing `members`' copy of `portal.d.ts`
(`members/resources/js/checkpointHelper/static/portal.d.ts`) — "P5 updates `members`' copy
and leaves the rest alone." Diffing it against the source
(`www/resources/js/external/portal.d.ts`) before touching it found **719 lines of diff**
across 1529 vs 1225 lines — the copy is missing a large amount of pre-existing drift
(Connect API types and others) that predates this plan entirely and has nothing to do with
billing.

A wholesale overwrite would import all of that unrelated drift under this step's name and
risks breaking whatever in `members` currently compiles against the stale copy (e.g. an
`export` keyword mismatch was visible in the first diff line alone). That is a separate,
larger reconciliation effort — not a copy-paste — so **the copy step was not performed**.
`members`' TypeScript build will not see `dp.notifications`/`dp.subscription`/`dp.on`/`dp.off`
until that reconciliation happens. Flagged here rather than either silently skipped or
risked with an unreviewed overwrite; step 10 (members dashboard) needs this resolved before
`BillingApp.ts` can compile against the new surface.

## Design decisions not fully spelled out in the plan text

- **`dp.subscription.get()`'s identity resolution** only implements the Checkpoint-bearer path. §7.3 says "else from the processor customer id bound to the session," but no server-side session-binding mechanism exists anywhere in this codebase for anonymous DP checkout pages (confirmed by the same survey that flagged this gap in step 04's completion doc). Since `members`' `BillingApp.ts` — the one real consumer per §7.3's own note — always sends a Checkpoint bearer, the bearer path covers the actual use case; the session-bound fallback is unimplemented and undocumented as a TODO in the endpoint rather than faked.
- **`billing_state`/`downgrade_after`/`days_until_downgrade`** are wired as a seam (`resolveBillingState()`) that currently always returns `null`, since 08-brain-enforcement.md's dunning state does not exist yet. `portal.d.ts` marks these three fields optional so a consumer checking `if (sub.billing_state)` degrades correctly today and starts working the moment step 08 lands and this seam is filled in.
- **`CheckpointIdentityResolver` also closes step 04's flagged gap.** `DirectPaymentSubscriptionController::store()` (the link-write endpoint) previously resolved `member_reference_num` only via the provider-customer-id match, with step 04's completion doc explicitly noting the Checkpoint-bearer path was unimplemented. It now tries the resolver first — a genuine improvement made possible by discovering `/checkpoint/auth/token/validate` exists, not scope creep, since both call sites need the exact same resolver.

## Verification performed

- `php -l` on every changed/new PHP file — all clean.
- `tsc --noEmit` on the full project — zero errors (same clean baseline as prior steps' runs).
- Manually traced §7.7's acceptance list:
  - `dp.notifications.list()` on a DP with no configs → `DirectPaymentNotification::where(...)->get()` returns an empty collection → `[]`, not an error. ✅
  - The public notifications endpoint's `publicList()` explicitly selects only `event`/`days_before`/`is_active` and maps to exactly three output keys — a mail handler reference or recipient email cannot reach the response even via an incautious future edit that adds a column to the model, since the `->get([...])` column list and the `->map()` projection are both explicit allow-lists, not `->toArray()`. ✅ (matches the acceptance note's own reasoning for why this needs to be structural, not just correct today)
  - `dp.subscription.get()` with another member's `member_ref` → `$requestedMemberRef !== $resolvedMemberRef` → 403 before any subscription lookup runs. ✅
  - A throwing `dp.on()` listener does not break `confirmPayment()`: `_emit()`'s per-handler `try/catch` swallows and logs, the loop continues, and `_emit()` is called only after `confirmPayment()`'s own result is already computed, so a listener can never affect the returned promise. ✅
- **Not** run against a live database, and the Checkpoint proxy call was not exercised against a real auth server (no such host reachable from this environment) — same pre-existing limitation as every prior step (`routes/portal.php` requires `public/_portal_/admin`, absent from this checkout).

## Follow-ups for later steps

- Step 10 (members dashboard) needs the `members` `portal.d.ts` reconciliation resolved before it can build against `dp.subscription.get()` from `BillingApp.ts`.
- Step 14 must add `cancel()`/`update()`/`setPaymentMethod()` to both `DirectPaymentSubscriptionApi` (api.ts) and `PortalDirectPaymentSubscriptionAPI`/`PortalDirectPaymentsFactory` (portal.d.ts), plus dispatch their own `dp.on()` events per §7.4's method list (`charge()`, `confirmPayment()`, `_confirmSubscriptionPaymentInternal()`, `addPaymentMethod()`, `deletePaymentMethod()` are the ones named there — the new actions should follow the same "emit on the provider-confirmed outcome" rule already applied here).
- Step 15 must add `history()` in the same two places, and give `resolveBillingState()` in `DirectPaymentSubscriptionController` its real dunning-state data once 08 exists.
- The playground page (§7.6) is worth building once 14/15 land, so it can honestly exercise the full lifecycle rather than two-thirds of it.
