# 09-module-self-service — completion record

**Plan file:** [`../09-module-self-service.md`](../09-module-self-service.md)
**Rollout phase:** K9 — self-service endpoints (11-rollout-and-testing.md's own row: "the appointments
waitlist attributes (X28), shopper cancel, the returns table + endpoints + admin queue, events
cancel policy + waitlist promotion, appointments `customerPermissions()` enforcement, the richer
`mine` serializers, `SiteUsersGuestClaimService` (X24)")
**Repo:** `w/www`
**Date:** 2026-09-15
**Status:** ✅ Shipped — every backend action in §9.2–§9.4 and §9.7 is built and tested; the
ecommerce dashboard pane gained the Cancel and Request-a-return UI this phase's own scope
required (08 had shipped events'/appointments' cancel/reschedule buttons already, anticipating
this phase's backend). **Not shipped:** the admin-side frontend panels (the orders admin's
Returns filter/panel, the per-user screen's "Claim guest records" button) — both have working
backend endpoints with no portal-admin UI wired to them yet. See "Not implemented."

## What shipped

### §9.2 — Ecommerce: orders, returns, favourites

**Orders list** ([EcommerceShopperOrdersController.php](../../../../../w/www/app/Http/Controllers/Externals/Ecommerce/EcommerceShopperOrdersController.php)
`index()`): `scope=open|all`, `limit` (capped at 100/page), and a `next_cursor` keyset cursor on
`id` — chosen over a compound `(placed_at, id)` cursor because `id` already tracks insertion
order closely enough in practice and keeps the cursor a single opaque value; a page boundary
therefore can't be shifted by a fulfilment landing between two loads, which a page-number
scheme would allow. `scope=open` uses the exact same definition `EcommerceContribution::badges()`
already uses (not cancelled, not fully fulfilled), so the dashboard badge and the "open orders"
view agree by construction.

**Shopper cancel** (`cancel()`): ownership via `EcommerceShopperAuth` (404, not 403, for someone
else's order), the connection's `cancel.enabled`/`cancel.window_minutes` via
`SiteUsersConnectionRegistry::resolve()` (active only — matches how ownership itself resolves),
the fulfilment guard, and `EcommerceFulfilmentService::cancelOrder()` gaining the plan's own
`string $actor = 'admin'` parameter — the admin controller's existing call is untouched
(defaults to `'admin'`), the shopper path passes `'customer'`. No refund call anywhere in this
path (K15).

**Returns** — the table now exists
([migration](../../../../../w/www/database/migrations/2026_09_15_000000_create_ecommerce_return_requests_table.php),
verified against the real local MySQL database, not just sqlite — `DESCRIBE
laravel_ecommerce_return_requests` matches §3.3 column-for-column). New
`EcommerceReturnRequest` model, new `EcommerceReturnsService` (`request()`, `cancel()`,
`decide()`, `recordRefund()`) and a new `EcommerceShopperReturnsController`
(`index`/`store`/`cancel`), validating in the plan's own order: connection + `returns.enabled`,
paid via `PaymentGateway::isPaid()`, the window, every line's `order_line_id` belongs to the
order and `quantity <= remaining`, `reason` against the connection's configured list or
`'other'`, and the one-open-request-per-order rule inside a `lockForUpdate()` transaction.
Admin queue added to the existing `EcommerceController`: `getReturns()`, `decideReturn()`
(`requested→approved|rejected`, `approved→received`), `recordReturnRefund()` — records what the
Money ledger already refunded, never calls one itself.

**Favourites / reorder** — no work needed; favourites already shipped in 08, reorder is
purely client-side per the plan and has no endpoint to build.

### §9.3 — Events: upcoming, past, tickets

`serializeRegistration()` (`EventStorefrontController.php`) gained `checked_in_at`, a batched
`venue_name` (the event's `default_venue_id` → `EventVenue.name`, eager-loaded once per
`myRegistrations()` call — no per-occurrence override exists in the schema, so this resolves
the event's default venue only, not a per-occurrence one), `ticket_type_name` (from
`ticket_snapshot`), and an `$includeAccessToken` parameter: `false` on `myRegistrations()`
(X17), `true` on the single-registration `registration()` response the emailed link uses —
previously `access_token` was on *neither* response, so this is a net-new field on the
single-registration path, not a narrowing.

`cancelRegistration()` gained the cutoff policy (`cancel.enabled`, `cancel.cutoff_hours`,
default 24) and an explicit `EV_E_ALREADY_CANCELLED` guard (idempotent-refuse, rule 7).
Capacity release and waitlist promotion (`EventWaitlistService::promoteNext()`) now run inside
one `DB::transaction()` — previously release ran unwrapped and nothing promoted the waitlist at
all.

### §9.4 — Appointments: bookings, reschedule, cancel

New private `AppointmentStorefrontController::customerPermissions()` — `can_cancel`,
`can_reschedule`, `cancel_up_to_hours`, `within_window` — computed from the booking's own
`policy_snapshot` plus the connection's live `cancel.enabled`/`reschedule.enabled` kill
switches, exactly as the plan's own snippet shows. `serializeBookingCustomer()` now returns it
alongside `service_name` (from `service_snapshot`) and `staff_name` (looked up from
`staff_reference_num`); `location` is **not** added — no location concept exists anywhere in
this module's schema (`Appointment`, `AppointmentStaff` — neither carries one), so the plan's
own wording ("staff_name, location") only has data for two of the three fields it names.

`cancelBooking()`/`rescheduleBooking()` now call `customerPermissions()` themselves and refuse
(`AP_E_CANCEL_DISABLED`/`AP_E_CANCEL_WINDOW_PASSED`/`AP_E_RESCHEDULE_DISABLED`/
`AP_E_RESCHEDULE_WINDOW_PASSED`) before ever reaching the service — X15 closed, matching the
plan's own acceptance line: verified by two bookings carrying *different* `cancel_up_to_hours`
snapshots (simulating one made before and one after a policy change) each governed by its own
snapshot regardless of the other, and by a connection-level `cancel.enabled=false` kill switch
refusing even a booking whose own policy allows it.

**A real bug found while wiring this**, not merely the plan's own gap:
`AppointmentLifecycleService::reschedule()` built the new booking's row without copying
`policy_snapshot` — a reschedule silently reset the customer's agreed policy to whatever
`customerPermissions()`'s own defaults are (24h, allowed), contradicting rule 5 ("the policy
the customer agreed to travels with the booking"). Fixed by adding `'policy_snapshot' =>
$booking->policy_snapshot` to the new row.

**Waitlist attribution (X28)**: `AppointmentStorefrontController::joinWaitlist()` now resolves
`attributableUserReference('appointments', …)` and merges it into the service call —
`array_merge($request->all(), [...])`, not the plan's own `$request->all() + [...]`: PHP's `+`
keeps the *left* array's value on a key collision, so the plan's own snippet would have let a
malicious client's own `site_user_reference` body field survive over the resolved one.
`AppointmentWaitlistService::join()` now writes the column (already present in the schema since
site_users/13 §13.3); a guest join still writes `null`.

### §9.5 — Payments (07's own surface, two behaviours this plan owns)

**A real, pre-existing bug fixed**: `SiteUsersPaymentsController::setDefaultPaymentMethod()`
read `paymentsVisible()` (`requireLinked: false`) — the same gate `deletePaymentMethod()` uses
for its deliberate disconnected-still-works carve-out (13 §13.6). The plan is explicit that
*only* removal survives disconnection; adding a card and setting a default both 409/403 when
disconnected. Fixed to `requireLinked: true`, matching `storePaymentMethod()`'s own gate.

**The last-card warning**: `deletePaymentMethod()` now checks, only after a successful delete,
whether the payer has zero saved methods left across *every* processor
(`remainingSavedMethodsCount()`, mirroring `paymentMethods()`'s own merge-across-processors
loop) and an active `DirectPaymentSubscription` resolvable through the payer link
(`whereNull('canceled_at')`) — if both hold, the response carries a `warning` string; the
deletion itself is never blocked.

### §9.6 — Cross-cutting UI

The one new pane this phase builds from scratch (ecommerce's ordersdetail/returns — events'
and appointments' cancel/reschedule UI already shipped in 08, ahead of this phase's backend)
follows §9.6 literally rather than the shortcut 08's own cancel/reschedule buttons took:
**inline confirm, not a browser `confirm()` dialog** — `buildOrderDetailMarkup(order, true)`'s
Cancel button reveals an in-pane panel naming the consequence ("Any payment already taken will
be refunded by the store — not automatically") with Yes/Never-mind, before calling
`cancelOrder()`. A refused action's message is now shown verbatim in three panes it wasn't
before this pass: ecommerce's new cancel/return panels, and — retrofitted onto 08's own
events/appointments buttons, which caught and silently swallowed every refusal — an inline
`[data-events-cancel-error]`/`[data-ap-error]` slot per row.

### §9.7 — Claiming a guest purchase (X24)

New `SiteUsersGuestClaimService` (`claimFor()`, and a `previewFor()` dry-run the plan's own
admin action needs) plus three small claimers (`app/SiteUsers/GuestClaim/`) —
`EcommerceGuestClaimer`, `EventsGuestClaimer`, `AppointmentsGuestClaimer` — each: match on the
verified email (case-folded) against `whereNull('site_user_reference')`, scope to the
connection's instance (or, for a module-wide `'*'` connection, every candidate row is
*re-verified* per-row against `SiteUsersConnectionRegistry::resolve()` rather than assumed
claimable — a store that has since gained its own, different connection is never swept in by a
stale module-wide row), and record provenance.

**Deviation, disclosed rather than invented**: the plan says "events and appointments use their
activity logs." Appointments has one (`AppointmentActivityService`) and
`AppointmentsGuestClaimer` uses it (`booking.guest_claimed`). **Events has no activity-log
service or table at all** (`grep -rln "class.*Activity" app/Events` is empty — confirmed before
writing the claimer, not assumed from the plan text). Rule 3 ("provenance recorded, one query
to undo") is met instead by tagging the claimed registration's own `custom_fields` JSON column
(a real, already-`array`-cast column) with `{site_user_reference, matched_email, claimed_at}`.
Ecommerce writes a `laravel_ecommerce_order_events` row (`event: 'guest_claimed'`) — that table
already existed and is exactly the audit trail the rule asks for.

Both hooks are explicit calls, per the plan's own reasoning ("so a reader of either path can see
that it happens"): `SiteUsersVerificationService::verifyEmail()` and `::confirmEmailChange()`
each call `$this->guestClaims->claimFor($user->fresh())` right after setting
`email_verified_at`. `SiteUsersVerificationService`'s constructor gained the one new dependency
(container-resolved everywhere; no call site constructs it by hand).

**The admin action** — `SiteUsersAdminController::claimGuestRecords()` (new
`GET`/`POST .../users/{id}/claim-guest-records`, `dry_run` query/body param, GET defaults to
preview, POST defaults to executing) — is built and reachable; the per-user *screen's own
button* to call it is not (see "Not implemented").

### §9.8 — Acceptance, checked against what actually shipped

| Line | Status |
|---|---|
| Every write 409s `SU_E_CONNECTION_DISCONNECTED` when read-only, except card removal | ✅ *(updated 2026-09-15)* — was correctly "not yet" at this document's original close (confirmed then: `grep -rln` for the code was empty), on the rollout plan's own K9-before-K12 ordering. [13-disconnection.md](../13-disconnection.md) has since shipped and built exactly this: every write this section names — ecommerce order cancel, return request/cancel, events cancel, appointments cancel/reschedule, favourites write — now 409s with the real code and message; card removal is the one deliberate carve-out (§13.6), unchanged. See that document's own completion record, including a real pre-existing ownership bug it found and fixed that this same acceptance line would otherwise have been unreachable through. |
| Shopper cancels an unfulfilled order in-window: cancelled, stock restored, actor `customer`, no refund call, pane says a refund is coming | ✅ `EcommerceShopperCancelTest` |
| One minute past the window → `409 EC_E_CANCEL_WINDOW_PASSED`; a fulfilment exists → `409 EC_E_CANCEL_FULFILLED` | ✅ same test file |
| Return for another shopper's order → `404`; a second open request → `409 EC_E_RETURN_ALREADY_OPEN`; a line above what remains → `422` | ✅ `EcommerceReturnsTest` |
| Operator sees the request, can approve; `refund_cents` populated only after the ledger refund | ✅ backend (`EcommerceReturnsTest`'s admin-service test) — **no admin frontend panel** (see below) |
| Past events show `attended` only with `checked_in_at`; `mine` no longer returns `access_token` | ✅ `EventCancelPolicyTest` |
| Cancel inside `cutoff_hours` → `409` naming the cutoff; outside it, capacity released **and** the oldest waitlisted registration promoted in the same transaction | ✅ `EventCancelPolicyTest` |
| `can_cancel` computed from `policy_snapshot`; a contradicting `POST` refused by the same rule; verified by a policy change not retroactively touching an existing booking | ✅ `BookingSelfServiceTest` |
| Reschedule uses `buildSlotButtonsMarkup` — one definition, by grep | Unchanged by this phase — still true (appointments/dashboard.ts, shipped in 08) |
| Removing the only card with an active subscription warns and succeeds | ✅ `SiteUsersPaymentsControllerTest` (two new tests: warns when it's the last one, silent when it isn't) |

## Deviations from the plan text, found while implementing

- **`connectionFor()` doesn't exist.** Every illustrative snippet in the plan calls
  `$this->registry->connectionFor(...)`; the real class is `SiteUsersConnectionRegistry` with
  `resolve()` (active-only) and `resolveAny()` (active-or-disconnected, 13's own concern). Every
  write in this phase used `resolve()` for its policy check, matching how ownership itself
  resolved at the time. *(Correction, 2026-09-15: the second half of that sentence — "so a
  disconnected connection's write correctly can't even reach the policy check" — turned out to
  describe a bug, not a correct fallback. `resolve()`-based OWNERSHIP checks (not the policy
  checks named here) returned null identically for "disconnected" and "never connected," so a
  disconnected instance's own true owner got a false 404 before reaching ANY refusal, correct or
  not. [13-disconnection.md](../13-disconnection.md) found and fixed this with a new
  `ownerReference()` method, distinct from `resolve()`/`resolveAny()`. This phase's own policy
  checks — `cancel.enabled`, `cancel.cutoff_hours`, etc. — were never wrong; only the ownership
  gate in front of them was.)*
- **The waitlist-attribution snippet's `$request->all() + [...]`** — see §9.4 above. Fixed to
  `array_merge()` with the resolved value last, so it cannot be shadowed by client input.
- **Events has no activity log** — see §9.7 above.
- **Appointments has no `location` concept** — see §9.4 above.
- **A keyset cursor on `id`, not `(placed_at, id)`** — see §9.2 above; a disclosed
  simplification, not a plan requirement precisely followed.

## Not implemented

- ~~**The admin orders screen's Returns filter and per-order panel** (Approve/Reject/Mark
  received/Record refund buttons). The four backend endpoints exist
  (`getReturns`/`decideReturn`/`recordReturnRefund` on `EcommerceController`, tested directly
  against `EcommerceReturnsService`); nothing in the portal-admin frontend calls them yet.~~ —
  **Built, 2026-09-15.** `commerce-orders.html` gained an Orders/Returns tab toggle and a
  `#returnsListCard` (status filter, table); `commerce-orders.ts` gained `loadReturns()` and the
  four action buttons, gated on `EcommerceReturnRequest::serialize()`'s real status set
  (`requested`/`approved`/`rejected`/`received`/`refunded`) and `recordReturnRefund()`'s actual
  allowed transitions (`approved`/`received` → refund, not just `received`). Not exercised in a
  live browser — AGENTS.md forbids `npm run dev` here — verified by `tsc` only.
- ~~**The per-user screen's "Claim guest records" button** (05 §5.8). The backend action exists
  and is reachable (`GET`/`POST .../claim-guest-records`); no button was added to the admin
  per-user Vue/JS screen.~~ — **Built, 2026-09-15.** `site-users.ts`'s per-user detail screen
  gained a "Claim guest records" card: a Preview button (GET, dry-run) rendering per-module
  counts, then a confirm button that POSTs the real claim. Same live-browser caveat as above.
- ~~**Reorder's client-side UI** (`ctx.goTo`-driven "add every still-purchasable line to the
  cart, report what it skipped"). The plan is explicit this needs no endpoint; no frontend time
  was spent on it this phase either — it was lower priority than the tested, acceptance-line
  items above.~~ — **Built, 2026-09-15**, with a real backend gap found and fixed along the way:
  `EcommerceOrderService::serializeOrder()`'s line shape had no `variant_ref` at all, so the
  plan's own "reorder is purely client-side" premise wasn't actually buildable — added a batched
  `EcommerceVariant` lookup to supply it. `ecommerce/ui.ts` gained a Reorder button
  (`buildOrderDetailMarkup()`, shown whenever the order has lines — unlike cancel/return, not
  gated on status) and `reorderStillPurchasableLines()`/`buildReorderResultMarkup()`;
  `dashboard.ts` wires it to `shop.addToCart()`. New `EcommerceOrderServiceReorderTest`
  (backend) plus 8 new frontend tests.
- ~~**A rendered QR code on the ticket-detail pane.** The plan calls for one, client-side. This
  codebase has no QR-encoding library anywhere (`grep -rln qrcode resources/js` is empty), and
  adding one — even a CDN-loaded script at runtime, the pattern `direct_payments/dashboard.ts`
  already uses for Stripe/Square/PayPal's own *official* SDKs — is a different kind of decision
  (an arbitrary open-source package versus a payment provider's own script) than this pass
  should make unilaterally. `buildTicketDetailMarkup()` now shows venue, ticket type and the
  code as text, which is fully functional on its own (`EventCheckinService::
  checkInByTicketCode()` takes the raw string) — just not scannable from a phone screen.~~ —
  **Built, 2026-09-15**, per an explicit user decision to add a real npm dependency rather than a
  CDN script (`qrcode` + `@types/qrcode`, static-imported — `external/ui`'s webpack config has no
  chunk-splitting, so a dynamic `import()` was not an option). `buildTicketDetailMarkup()` now
  emits a canvas placeholder; a new `renderTicketQr()` fills it via `QRCode.toCanvas()` after the
  markup is mounted, wired into both the dashboard's ticket view and the guest-registration
  confirmation render.

## Files

**New (backend):** `app/Models/Externals/EcommerceReturnRequest.php`,
`app/Externals/Ecommerce/Services/EcommerceReturnsService.php`,
`app/Http/Controllers/Externals/Ecommerce/EcommerceShopperReturnsController.php`,
`database/migrations/2026_09_15_000000_create_ecommerce_return_requests_table.php`,
`app/SiteUsers/SiteUsersGuestClaimService.php`,
`app/SiteUsers/GuestClaim/{SiteUsersGuestClaimer,EcommerceGuestClaimer,EventsGuestClaimer,AppointmentsGuestClaimer}.php`

**Modified (backend):** `app/Externals/Ecommerce/Services/EcommerceFulfilmentService.php` (`cancelOrder()`
`$actor` param), `app/Externals/Ecommerce/Support/EcommerceReference.php` (`returnRequest()`),
`app/Http/Controllers/Externals/Ecommerce/EcommerceShopperOrdersController.php` (`cancel()`,
`index()` scope/limit/cursor), `app/Http/Controllers/Externals/Ecommerce/EcommerceController.php`
(returns admin queue), `app/Http/Controllers/Externals/Events/EventStorefrontController.php`
(cancel policy + transaction, serializer fields), `app/Http/Controllers/Externals/Appointments/
AppointmentStorefrontController.php` (`customerPermissions()`, enforcement, waitlist
attribution), `app/Externals/Appointments/Services/AppointmentWaitlistService.php`
(`site_user_reference`), `app/Externals/Appointments/Services/AppointmentLifecycleService.php`
(`policy_snapshot` carry-forward), `app/Http/Controllers/Externals/SiteUsers/SiteUsersPaymentsController.php`
(`setDefaultPaymentMethod()` gate fix, last-card warning), `app/SiteUsers/
SiteUsersVerificationService.php` (two claim hooks), `app/Http/Controllers/Externals/SiteUsers/
SiteUsersAdminController.php` (`claimGuestRecords()`), `app/SiteUsers/Dashboard/
EcommerceContribution.php` (comment only — no behaviour change), `routes/portal.php` (every
route above)

**New (frontend):** `resources/js/external/ui/tests/ecommerce_self_service.test.ts`

**Modified (frontend):** `resources/js/external/api/commerce/api.ts` (`cancelOrder()`,
`myReturns()`, `requestReturn()`, `cancelReturn()`), `resources/js/external/api/portal.d.ts`
(matching ambient types on `PortalCommerceInstance`; `checked_in_at`/`venue_name`/
`ticket_type_name`/`access_token` on `PortalEventRegistrationRecord`), `resources/js/external/ui/
ecommerce/ui.ts` (`buildOrderDetailMarkup()`'s Cancel/Request-a-return additions,
`buildReturnRequestFormMarkup()`, `buildReturnsListMarkup()`), `resources/js/external/ui/
ecommerce/dashboard.ts` (`returns` view, order-detail action wiring), `resources/js/external/ui/
events/ui.ts` (*Attended* label, `buildTicketDetailMarkup()` enrichment + optional calendar
link), `resources/js/external/ui/events/dashboard.ts` (ticket calendar link, cancel error
display), `resources/js/external/ui/appointments/ui.ts` (`can_cancel`/`can_reschedule` gating
on `buildMyBookingsMarkup()`), `resources/js/external/ui/appointments/dashboard.ts` (cancel/
reschedule error display), `resources/js/external/ui/tests/appointments.test.ts`
(`buildMyBookingsMarkup()` gating tests)

**New (tests):** `tests/Feature/Ecommerce/EcommerceShopperCancelTest.php`,
`tests/Feature/Ecommerce/EcommerceReturnsTest.php`,
`tests/Feature/Events/EventCancelPolicyTest.php`,
`tests/Feature/Appointments/BookingSelfServiceTest.php`,
`tests/Feature/Appointments/WaitlistAttributionTest.php`,
`tests/Feature/SiteUsers/GuestClaimTest.php`

**Modified (tests):** `tests/Feature/SiteUsers/SiteUsersPaymentsControllerTest.php` (4 new
tests: set-default disconnected refusal, last-card warning, no-warning-with-others-remaining,
plus a `laravel_direct_payment_subscriptions` fixture table)

## A sqlite-only test gap, disclosed rather than routed around in production code

`EventCapacityService::releaseRegistered()` and `AppointmentCapacityService::release()`/
`claim()` issue raw MySQL SQL this test suite's sqlite connection cannot run natively —
`GREATEST()` (no sqlite builtin) and, for appointments, `<=>` (a syntax error in sqlite, not a
missing-function one). Both are pre-existing, unrelated to this phase. Worked around per test
file, not in the services themselves:

- `EventCancelPolicyTest` registers a `GREATEST` UDF on the test's sqlite PDO connection
  (`sqliteCreateFunction`) — legitimate for `GREATEST()`, which sqlite has no builtin for.
- `BookingSelfServiceTest` cannot do the same for `<=>` (an operator, not a function — sqlite
  has no hook to add one). Its successful-cancel test therefore uses a booking with
  `service_reference_num = null`, which `AppointmentBookingService::cancel()`'s own condition
  (`if ($booking->service_reference_num && $booking->starts_at)`) already causes to skip
  capacity release entirely — a real, if narrow, precondition, not a mock. The reschedule test
  calls `AppointmentLifecycleService::reschedule()` directly with `AppointmentAvailabilityService`
  mocked true and the OLD booking's `starts_at` null (so `release()` is never reached) and
  `capacity=1` on the service (so `claim()` takes its own early "no counter row needed" return,
  skipping its `ON DUPLICATE KEY … VALUES()` MySQL-only insert too).

None of this touches `AppointmentCapacityService`/`EventCapacityService` themselves — a real
fix (parameterising the raw SQL for both drivers, or a MySQL-only local test DB) is future work,
unrelated to this plan.

## Verification

```bash
cd w/www
php artisan test tests/Feature/Ecommerce/ tests/Feature/Events/ tests/Feature/Appointments/ \
  tests/Feature/SiteUsers/ tests/Feature/Connections/ tests/Unit/SiteUsers/ tests/Unit/Ecommerce/
# 330 passed

php artisan test
# 464 passed, 3 failed — all three pre-existing and unrelated (ExampleTest's scaffold route,
# a builder/forms module-refs drift test, a forms external-SDK file that doesn't exist) —
# confirmed unrelated by reading each failure before writing this line, not assumed.

php artisan migrate --force
# 2026_09_15_000000_create_ecommerce_return_requests_table — ran clean against the real local
# MySQL database (testdb_appfactorystudio), not just sqlite; DESCRIBE confirms it matches
# 03-database-schema.md §3.3 exactly.

npx tsc --noEmit -p resources/js/external/ui/tsconfig.json      # exit 0
npx tsc --noEmit -p resources/js/external/api/tsconfig.json     # exit 0
npx tsc --noEmit -p tsconfig.json                                # exit 0 (root)
npm run build-external-ui-webpack                                # exit 0
npm run build-external-webpack                                   # exit 0
npm run build                                                    # exit 0 (root vite, 3m24s)
npm run test:external-ui
# 77 passed (66 pre-existing + 7 new ecommerce markup tests + 4 new appointments gating tests)
```

## Residue

- **No live browser verification** of the new ecommerce cancel/return panel, the
  Attended-label/error-display retrofits on events'/appointments' 08-era buttons, or (added
  2026-09-15) the admin Returns panel, the "Claim guest records" button, reorder's client-side UI,
  or the ticket QR code — same disclosed limit 08's own completion doc carries for its three
  payment providers. The markup and wiring are unit-tested (jsdom) and type-checked; a human
  should click through a real dashboard with a connected store before this ships.
- ~~**The two admin-frontend gaps** named in "Not implemented" are the largest remaining pieces
  of this plan document's own scope. Both are additive, backend-complete follow-ups.~~ — **Built,
  2026-09-15.** See "Not implemented" above — the Returns panel, the Claim guest records button,
  reorder's client-side UI and the QR code are all built now. Still not live-browser-verified
  (AGENTS.md forbids `npm run dev` here); see the browser-verification residue line above, which
  now covers these too.
- ~~**The QR code**, named in "Not implemented" — needs a user decision on whether to add a
  QR-encoding dependency (and which one) before it can be built.~~ — **Resolved, 2026-09-15**: the
  user chose a real npm dependency (`qrcode`) over a CDN script. See "Not implemented" above.
- ~~**`SU_E_CONNECTION_DISCONNECTED` on self-service writes** is real, deliberate future work —
  [13-disconnection.md](../13-disconnection.md)'s own K12 phase~~ — **Closed, 2026-09-15.** 13
  shipped the same day as this update, guarding every write this document names. See the
  acceptance table above.
