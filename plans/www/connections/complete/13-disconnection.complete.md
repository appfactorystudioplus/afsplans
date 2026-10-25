# 13-disconnection — completion record

**Plan file:** [`../13-disconnection.md`](../13-disconnection.md)
**Repo:** `w/www`
**Date:** 2026-09-15
**Status:** ✅ Shipped. Most of the schema/registry/Dashboard layer (§13.2–§13.4) was already
built in earlier phases (K1's schema, K2's registry, K7/K8's Dashboard) — verified directly
against the code before assuming so, not from the plan's own claims. What this pass actually
built: the server-side refusal guard (§13.5) across every write endpoint the plan names, the
Direct Payments carve-out's own 409 (§13.6), the admin UI's disconnected state (§13.7), and the
guest-token single-record `read_only` surface (X41, deferred here from
[12](../12-guest-continuity.md)'s own completion doc). Along the way, found and fixed a real,
pre-existing bug that would have made most of §13.5/X41 unreachable — see "The ownership bug"
below.

## What was already built (verified, not assumed)

- **§13.2 — schema.** `laravel_site_user_connections` was created directly with `status`
  (`active`/`disconnected`) — it never had an `is_active` boolean to migrate away from. The
  model already had `isActive()`, `isReadOnly()`, `readOnlyNotice()`, `scopeActive()`,
  `scopeVisible()`, matching the plan's own code sample exactly.
- **§13.3 — two readers.** `SiteUsersConnectionRegistry::resolve()` (active-only) and
  `resolveAny()` (active or disconnected, specificity-over-status precedence — an active
  instance row already beat a disconnected module-wide one, and vice versa) both existed and
  matched the plan's semantics precisely.
- **§13.4 — the Dashboard keeps the group.** `SiteUsersDashboardService::forUser()`/
  `filterCapabilities()` already implemented all four rules: `read_only_capabilities` per
  module already configured in `config/site_users_consumers.php` exactly matching §13.4's
  table; badges empty when read-only; `read_only`/`read_only_notice` in the payload; active-
  first ordering via `forDashboard()`'s own `orderByRaw`.
- **§13.9's events/appointments inversion.** `myRegistrations()`/`myBookings()` already used
  `resolveAny()` to include disconnected-instance rows and exclude only removed connections.
- **`/me/purchases`.** `SiteUsersPurchasesService::forUser()` already reads through
  `resolveAny()`, with its own docblock naming this exact document.

## The ownership bug (found while implementing, not in the plan's own text)

Every owner-path fallback in this document's own §13.5 code sample — `registration()`,
`cancelRegistration()`, `booking()`, `cancelBooking()`, `rescheduleBooking()` (via
`isBookingOwner()`), and ecommerce's shopper-order endpoints — verified ownership of an
**existing** record with `SiteUsersConsumerRegistry::attributableUserReference()`, which reads
through `resolve()` (active-only). That method is exactly right for **attribution** ("may we
credit a NEW action to this session") — a disconnected instance must go anonymous there, by
design (§13.5's own "the storefront needs no equivalent" note). It is exactly wrong for
**ownership** of a record that already exists: ownership is a historical fact set when the
record was created, and does not stop being true because a tenant disconnected the module
afterwards.

Before this fix, a disconnected instance's own true owner got a false 404 ("not found") from
the ownership check itself — every step this document specifies after that point (the §13.5
refusal with its real message, the X41 `read_only` fields) was **unreachable** for exactly the
users it exists to serve. `registration()`'s owner-path test against a disconnected event, for
example, would have failed with 404 before this fix and passes now.

Fixed with a new method, `SiteUsersConsumerRegistry::ownerReference()` — `attributableUserReference()`'s
twin, identical except it resolves through `resolveAny()` — plus `EcommerceShopperAuth::ownerReference()`
as ecommerce's thin wrapper over the same call (mirroring `siteUserReference()`'s own existing
wrapper). Every ownership check named above now calls it. `AppointmentStorefrontController::
customerPermissions()` had the identical shape of bug independently (`$conn?->setting(...) ??
true` — a null connection, disconnected or never-connected alike, defaulted every permission to
allowed) — fixed by resolving `$conn` via `resolveAny()` and forcing both booleans false when
`$conn->isReadOnly()`.

## §13.5 — the server-side refusal guard

New `SiteUsersConnectionRegistry::refuseIfReadOnly(moduleKey, instanceRef): ?array` — resolves
via `resolveAny()`, returns the `{success: false, error: {message, code: SU_E_CONNECTION_DISCONNECTED}}`
payload (the caller applies the 409) or `null` to proceed. Applied, ownership checked first in
every case:

| Endpoint | Before | After |
|---|---|---|
| Events `cancelRegistration()` | **Failed open** — `resolve()===null` short-circuited the whole disable/window check, so a disconnected event's cancel went through unrefused | Guarded, real message |
| Appointments `cancelBooking()`/`rescheduleBooking()` | **Failed open** — `customerPermissions()`'s `?? true` | Guarded (explicit refusal) + `customerPermissions()` itself fixed |
| Ecommerce order `cancel()` | Accidentally refused via `resolve()===null`, but with `EC_E_CANCEL_DISABLED` — indistinguishable from "tenant turned cancel off" | Guarded, real message, `EC_E_CANCEL_DISABLED` now only means what it says |
| Ecommerce return `store()` | Same accidental-refusal shape, `EC_E_RETURNS_DISABLED` | Guarded, real message |
| Ecommerce return `cancel()` | **No check of any kind** | Guarded |
| Ecommerce favourites add/remove | No check | Guarded (list stays open; guest favorites, no signed-in account, are untouched — never tied to a connection) |
| Ecommerce **reorder-to-cart** | — | **Does not exist anywhere in this codebase** (confirmed by search, not assumed) — nothing to guard. Named here because the plan names it; building a new feature under this document's banner would be real scope creep |

## §13.6 — the Direct Payments carve-out

Already functionally correct before this pass — `SiteUsersConnectionRegistry::paymentsLinked()`
(active-only, gates add-card/set-default) and `paymentsVisible()` (active-or-disconnected, gates
list/remove) were already the right shape, predating this document under their own names rather
than `resolveAny()`/`isReadOnly()` directly. The one real gap: add-card and set-default answered
both "never connected" and "disconnected" with the same generic 403 "Payments are not connected
for this account." — the acceptance criterion asks for `409` specifically. New
`paymentsReadOnlyNotice(siteUsersId): ?string` distinguishes the two (a `null` result is "not
disconnected," including "never connected," which keeps its existing 403); the three call sites
(`storePaymentMethod()`, `resolveOrCreatePayerCustomer()`, `resolveOwnedPayerCustomer()`) now
return `409 SU_E_CONNECTION_DISCONNECTED` with the real reason when that's what it is.

## §13.7 — the admin UI

Backend `SiteUsersConnectionsController::connectionState()` gains `connection_status`/`read_only`
on its `'connected'` state (named `connection_status`, not `status` — an instance row already
carries its own `status`, e.g. an event's draft/published state; colliding the two in one JSON
object would have been a real bug, caught by `tsc` the moment the two interfaces were merged).
`PATCH .../connections/{id}` already accepted `status` — nothing to add there.

Frontend (`site-users.ts`): Disconnect is now a `PATCH {status: 'disconnected'}`, not the
`DELETE` it used to be — the row, its settings and the history all survive, matching §13.7's own
copy ("Customers keep seeing their past orders... You can reconnect at any time"). A disconnected
row gets its own badge ("Disconnected · read-only") and two new actions, **Reconnect** (`PATCH
{status: 'active'}`) and **Remove** (the old hard-delete, now named for what it actually does,
with its own confirmation naming what's lost). The confirmation dialogs' copy is lifted from
this document's own §13.7 verbatim.

## X41 — the guest's emailed link (deferred from 12's own completion doc)

The single-record serializers gain `read_only`/`read_only_notice`, scoped to the single-record
call only (not the `mine` lists) — reusing each module's existing "is this the emailed-link
response" flag (`$includeAccessToken` for events, a new equivalent `$includeReadOnlyNotice` for
appointments) so this costs no extra query on a list endpoint:

- Events: `registration()` → `serializeRegistration(..., $event)`, computes `read_only` via
  `resolveAny()`.
- Appointments: `booking()` → `serializeBookingCustomer($booking, true)`. Free here (unlike
  events) since `$conn` is already resolved per-row for `customerPermissions()`'s own booleans.
- Ecommerce: `show()` (the single-order endpoint) merges the same two fields in, computed
  outside `serializeOrder()` — which the admin views also call and has no business knowing about
  a shopper's connection state.

Frontend: `buildReadOnlyNoticeMarkup()` moved from `site_users/ui.ts` (its original, Dashboard-
only home) to `shared/dom.ts`, so the guest single-record views can render the identical
server-supplied sentence without a cross-module import; `site_users/ui.ts` now imports and
re-exports it, unchanged for any existing caller. `events/ui.ts`'s `renderGuestRegistration()`
and `appointments/ui.ts`'s `renderGuestBooking()` both branch on `record.read_only` — the notice
renders, and the Cancel action is omitted (events: `buildTicketDetailMarkup(..., !readOnly)`;
appointments: `can_cancel` is already `false` server-side, so `buildMyBookingsMarkup()` omits the
button on its own — the frontend change there is only the notice).

## Files

**Modified (backend):** `app/SiteUsers/SiteUsersConnectionRegistry.php` (`refuseIfReadOnly()`,
`paymentsReadOnlyNotice()`), `app/SiteUsers/SiteUsersConsumerRegistry.php` (`ownerReference()`),
`app/Externals/Ecommerce/Support/EcommerceShopperAuth.php` (`ownerReference()` wrapper),
`app/Http/Controllers/Externals/Events/EventStorefrontController.php` (`registration()`,
`cancelRegistration()`, `serializeRegistration()`), `app/Http/Controllers/Externals/Appointments/
AppointmentStorefrontController.php` (`booking()`, `cancelBooking()`, `rescheduleBooking()`,
`isBookingOwner()`, `serializeBookingCustomer()`, `customerPermissions()`),
`app/Http/Controllers/Externals/Ecommerce/EcommerceShopperOrdersController.php` (`index()`,
`show()`, `cancel()`), `app/Http/Controllers/Externals/Ecommerce/EcommerceShopperReturnsController.php`
(`index()`, `store()`, `cancel()`), `app/Http/Controllers/Externals/Ecommerce/
EcommerceShopperListsController.php` (`favoritesResponse()`), `app/Http/Controllers/Externals/SiteUsers/
SiteUsersPaymentsController.php` (three call sites), `app/Http/Controllers/Externals/SiteUsers/
SiteUsersConnectionsController.php` (`connectionState()`)

**Modified (frontend):** `resources/js/external/ui/shared/dom.ts` (`buildReadOnlyNoticeMarkup()`
added), `resources/js/external/ui/site_users/ui.ts` (delegates to it), `resources/js/external/ui/
events/ui.ts` (`renderGuestRegistration()`), `resources/js/external/ui/appointments/ui.ts`
(`renderGuestBooking()`), `resources/js/external/api/portal.d.ts`
(`PortalEventRegistrationRecord` gains `read_only`/`read_only_notice`), `resources/js/portal/
admin/controllers/site_users/site-users.ts` (`ConnectionState`, `connBadge()`,
`connActionsCell()`, `wireConnections()`)

**New/modified (tests):** `tests/Feature/Connections/EventsEnforcementTest.php` (+3),
`tests/Feature/Connections/AppointmentsEnforcementTest.php` (+2), `tests/Unit/Ecommerce/
EcommerceShopperAuthTest.php` (+3, `ownerReference()`), `tests/Feature/SiteUsers/
SiteUsersConnectionsAdminTest.php` (+4, PATCH status + candidates + the disconnected-elsewhere
conflict), `tests/Feature/SiteUsers/SiteUsersPaymentsControllerTest.php` (2 existing tests
updated: 403 → 409 `SU_E_CONNECTION_DISCONNECTED`, the behavior these tests were always meant
to exercise once §13.6 was actually built) — 12 new tests, 2 behavior-corrected.

## Verification

```bash
cd w/www
npx tsc --noEmit -p resources/js/external/ui/tsconfig.json       # exit 0
npx tsc --noEmit -p resources/js/external/api/tsconfig.json      # exit 0
npx tsc --noEmit -p tsconfig.json                                 # exit 0 (root)
npm run test:external-ui                                          # 81 passed
npm run test:external-api                                         # 11 passed
npm run build-external-webpack                                    # exit 0
npm run build-external-ui-webpack                                 # exit 0
npm run build                                                     # exit 0 (root vite, 2m8s — includes portal/admin)
php artisan test                                                  # 514 passed, 3 pre-existing unrelated failures
```

## Residue

Two items below were closed in a follow-up pass the same day, after re-checking this document's
own §13.10 acceptance list line by line rather than trusting the first pass's summary:

- ~~**§13.10's "instance cannot be connected to a second module while disconnected" not
  independently re-tested.**~~ — **Closed.** New
  `test_put_for_an_instance_disconnected_elsewhere_still_returns_409` confirms `upsert()`'s
  conflict check fires identically for a disconnected row as an active one, and that removing
  it (not merely disconnecting it) is what frees the slot for a second module.
- ~~**The guest TOKEN path's `read_only` exposure was only owner-path-tested.**~~ — **Closed.**
  `test_the_real_owner_can_still_view_their_registration_on_a_disconnected_event` exercised
  `registration()`'s session/owner branch; the literal acceptance scenario ("a guest following
  an emailed link... sees the record and the notice") is the OTHER branch (`$byToken`), a
  separate code path reached differently even though it calls the same `serializeRegistration()`.
  New `test_a_guest_with_a_valid_token_sees_read_only_on_a_disconnected_event` exercises it
  directly, with no signed-in session at all.

Still open:

- ~~**No full HTTP-level test for ecommerce's `cancel()`/`show()` refusal.** The identical
  `ownerReference()`/`refuseIfReadOnly()` mechanism is fully HTTP-tested for events and
  appointments (real routes, real bearer sessions, a disconnected instance, the true owner) and
  unit-tested for ecommerce's own wrapper (`EcommerceShopperAuthTest::test_owner_reference_finds_the_true_owner_of_a_disconnected_store`
  and its two siblings) — standing up the order/fulfilment schema a full HTTP round-trip needs
  was judged not worth the marginal coverage over what the shared primitive's own tests already
  prove. If `EcommerceShopperOrdersController::cancel()` regresses independently of
  `ownerReference()`/`refuseIfReadOnly()` themselves, this residue is where that gap lives.~~ —
  **Covered, 2026-09-15.** `EcommerceShopperCancelTest` (which already stands up the order schema
  for its own unrelated cases) gained two real HTTP round-trips:
  `test_cancel_on_a_disconnected_store_is_refused_with_the_real_reason` (POST cancel on a
  disconnected store → 409 `SU_E_CONNECTION_DISCONNECTED`, order status unchanged) and
  `test_the_real_owner_can_still_view_their_order_on_a_disconnected_store` (GET show() on a
  disconnected store as the true owner → 200, `read_only: true`, a notice naming the
  disconnection) — the second is a direct regression test for the `ownerReference()` bug this
  document fixed (§13's own false-404 finding).
- **The admin Connections tab's frontend was never exercised in a live browser.** Verified by
  `tsc` (the `ConnectionState`/`connBadge()`/`connActionsCell()` changes compile against the
  new API shape) and by API-contract tests (`SiteUsersConnectionsAdminTest`'s new PATCH-status
  and candidates tests confirm the backend returns what the frontend now reads) — but AGENTS.md
  forbids running `npm run dev` in this environment, and no other live-browser path exists here
  for a page behind portal-admin auth. The Disconnect/Reconnect/Remove buttons and the
  "Disconnected · read-only" badge have not been visually confirmed to render correctly.
- **`reorder-to-cart`** — named in §13.5's own list, does not exist in this codebase at all
  (confirmed by search). Not built here; building a new feature under this document's banner
  would be scope creep this document didn't ask for.
