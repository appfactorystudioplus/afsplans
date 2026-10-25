# 04-connection-registry-and-enforcement — completion record

**Plan file:** [`../04-connection-registry-and-enforcement.md`](../04-connection-registry-and-enforcement.md)
**Rollout phases:** K1 + K2 in full (§4.1, §4.2, §4.7, §4.8); K3 in full (§4.3, §4.4, §4.5);
the parts of K5, K6, K7, K8 that this document itself defines — §4.6 (`publicBlock()` +
`SiteUsersPagePaths`), §4.9 (the module-wide dashboard-expansion algorithm), §4.10 (guard/route
module isolation) — see "What's still the rollout phase's, not this document's" below for what
each of those four phases still needs beyond what doc 04 asks for
**Repo:** `w/www`
**Date:** 2026-09-13
**Status:** ✅ Every section of this document (§4.1–§4.11) is now built and tested. Nothing in
doc 04's own text is deferred.

## What shipped — K1 + K2 (recap; unchanged from the earlier pass)

§4.1's consumer-registry config, §4.2's `SiteUsersConnectionRegistry` (with the circular-dependency
and MySQL-only-`ORDER BY` fixes), §4.7's `SiteUsersConnectionService`/`SiteUsersDerivedColumns`
proven end-to-end, §4.8's `link_payment_customers` readers re-pointed. Full detail unchanged from
the previous record — see this file's git history.

## What shipped this round — §4.2's caller migration, §4.3, §4.4, §4.5 (K3 in full)

### `SiteUsersConsumerRegistry::attributableUser()` — new, the shared resolution

Every caller below needs the identifier migration's step 3 ("read the reference, keep writing
both," site_users/13 §13.3) — a module-aware **reference** for reads/comparisons, but still the
bigint **id** for the columns that keep writing both for one release. Rather than resolve twice
(once for each), `attributableUser()` returns the module-aware `SiteUser` object itself;
`attributableUserReference()` (K2) is now a one-line wrapper over it. Every write site below
reads `?->id` and `?->reference_num` off one resolution instead of a second query.

### Module isolation, wired into all three storefronts (§4.3)

`currentUserId()` — the deprecated, any-module, bigint-returning gateway call — is gone from
`app/Externals/Ecommerce`, `app/Events`, `app/Externals/Appointments`, and `app/Http/Controllers/Portal`
(§4.11's own acceptance grep is empty). Every write that used to attribute a signed-in visitor
now resolves through `attributableUser()`/`attributableUserReference()`, scoped to the specific
store/event/calendar instance:

- **Ecommerce**: `EcommerceShopperAuth` gained module-aware `siteUserId(EcommerceStore, Request)`
  and `siteUserReference(EcommerceStore, Request)` (replacing the old any-module
  `siteUserId(Request)`), plus `modeFor()` and `publicBlock()` passthroughs.
  `EcommerceCartController`, `EcommerceShopperListsController`, and
  `EcommerceShopperOrdersController` all resolve the store first and call these instead of the
  gateway directly. `EcommerceShopperOrdersController` also switched its "mine" comparison from
  the bigint `site_user_id` column to `site_user_reference` — a small deviation from the plan's
  own pseudocode (which still compares ids) but the more direct reading of "read the reference."
- **Events**: `EventRegistrationService::register()` (already gated in `required` mode from an
  earlier pass, but with the generic `SU_E_AUTH_REQUIRED` code) now uses `attributableUser()`
  for the order/registration attribution and returns the module-specific `EV_E_AUTH_REQUIRED`
  code with the `module` hint. `EventPassService::redeem()`'s redemption-row attribution migrated
  the same way. `EventStorefrontController` gained `SiteUsersConsumerRegistry`/
  `SiteUsersConnectionRegistry` and rewrote `confirmPayment()`, `registration()`, and
  `cancelRegistration()`'s owner checks onto `site_user_reference` comparison instead of the
  bigint column — module-aware, so a same-numbered id on a different module can never pass as
  owner.
- **Appointments**: `AppointmentBookingService::book()` migrated identically (own
  `AP_E_AUTH_REQUIRED` code, module-aware attribution). `AppointmentStorefrontController` gained
  a single `isBookingOwner()` private helper (module-aware, reference-based) used by all five of
  `rescheduleBooking()`, `calendarIcs()`, `confirmPayment()`, `booking()`, and `cancelBooking()` —
  collapsing five copies of the same bigint-comparison bug into one correct implementation.

### §4.4 — the two "mine" endpoints, rewritten to the plan's own worked example

`EventStorefrontController::myRegistrations()` and `AppointmentStorefrontController::myBookings()`
now: return `401 SU_E_AUTH_REQUIRED` for a guest (X16 — previously both silently returned `[]`,
which the storefront's own `try {…} catch {signInMarkup}` could never trigger); query
`site_user_reference` directly (one query, no per-module loop); and filter through
`SiteUsersConnectionRegistry::resolveAny()` per row so a **disconnected** instance's history still
shows (13 §13.9 — the connection was only paused, not removed) while a **removed** connection's
rows silently disappear (the tenant explicitly said this history should not be in the account).

### §4.5 — every enforcement point in the plan's own table

- **Ecommerce `POST /checkout/complete`** (`EcommerceCartController::completeCheckout()`): the
  exact X8 logic from the plan — `required` mode with no attributable session → `401
  EC_E_AUTH_REQUIRED` with `module`; a cart whose `site_user_reference` disagrees with the
  completing session → `403 EC_E_CART_OWNER_MISMATCH` in `required` mode, silently cleared
  (both `site_user_id` and `site_user_reference` nulled) in `guest` mode so the order writes as
  a guest order rather than either party's.
- **Events `POST /hold` and `POST /waitlist`** (new gates in `EventStorefrontController`):
  resolve the occurrence → event, call `assertAuthenticated('events', $event, …)`, refuse with
  `401 EV_E_AUTH_REQUIRED` + `module` in `required` mode. `joinWaitlist()` also switched its
  attribution from the any-module `currentUserId()` to the module-aware resolution.
- **Appointments `POST /hold` and `POST /waitlist`** (new gates in
  `AppointmentStorefrontController`): identical shape. `laravel_appointment_waitlist` has no
  `site_user_id`/`site_user_reference` column at all (confirmed by reading
  `AppointmentWaitlistService::join()`'s raw `DB::table()->insert()` call — a schema gap outside
  this document's scope, previously flagged in the K1/K2 rollout hub's residue), so only the gate
  was added there; there is no attribution write to migrate.
- **Status codes**: every new/migrated refusal above uses each module's own `_E_AUTH_REQUIRED`
  code with a `module` hint, per §4.5's "standardises on `401`" rule. The owner-path endpoints
  (`registration()`, `cancelRegistration()`, `booking()`, `confirmPayment()`,
  `rescheduleBooking()`, `calendarIcs()`) all resolve to a plain `404` on failure — no
  `401`/`403` — matching §4.3's own table ("owner test fails → the token path may still
  succeed → else `404`"), which is a real, deliberate behavior change on `registration()` and
  `cancelRegistration()` specifically: they used to return `401 EV_E_AUTH` for a guest with no
  token, which disclosed (via a different status code) that *something* exists at that
  reference even for a wrong guess. See Residue for the one place this status-code change
  needed to be traded off against an existing test's expectation.

## What shipped this round — §4.6, §4.9, §4.10 (slices of K5/K6/K7/K8 that doc 04 itself defines)

### §4.6 — `publicBlock()` and the new `SiteUsersPagePaths`

`SiteUsersPagePaths::forModule()` (new, `app/SiteUsers/SiteUsersPagePaths.php`) answers "where
are this module's pages" from the one reliable record the plan names:
`laravel_builder_routes`' own dashboard-publish row — `login_route` is the login slug, the row's
own `subpaths` is the dashboard slug, and the register slug is the login slug with `-login` →
`-register` (the builder's own sibling-page suffix convention). When more than one protected row
exists for a module, the one whose `subpaths` ends in `-dashboard` is preferred (real local data
confirms this shape: `demo-site-users` has exactly one row, `new-page-account-dashboard`, with
`login_route = /new-page-account-login`). A connection's `settings.login_path` override recomputes
all three paths as siblings of the overridden login page, per §4.6's "overrides all three roots."
No row at all (module activated, pages never published) → every path `null`.

`SiteUsersConsumerRegistry::publicBlock()` is the plan's own method, exactly as written, added
alongside `assertAuthenticated()`/`attributableUserReference()`. Wired into all three storefronts:
`EcommerceStorefrontController::getStore()` (which also switched `site_users_mode` from reading
`$store->site_users_mode` directly to `modeFor()`, per §4.6's own instruction to keep the field
for one release but populate it from the mode resolver, not the column),
`EventStorefrontController::serializeEventDetail()`, and
`AppointmentStorefrontController::serializeDetail()`.

**Why this was buildable now, despite three conflicting phase labels.** The K1/K2 completion
record flagged that `publicBlock()` is claimed by three different places in the plan (§4.6's own
header says "(K8)"; the rollout table's K2 row lists it as a K2 deliverable; the rollout table's
K5 row lists "`site_users` on the three public configs" as K5's). All three claims are now
satisfied by the same one implementation — the plan disagreeing with itself about which K-number
owns a method doesn't change what the method has to do.

### §4.9 — the module-wide dashboard-expansion algorithm

New `app/SiteUsers/SiteUsersDashboardService.php`, holding exactly the one algorithm §4.9 gives
in full: a module-wide connection for an `instance`-scoped module (only `ecommerce` today) is
expanded into one dashboard entry per registered instance; a `global`-scoped module's module-wide
row collapses to one entry; an instance row and a module-wide row for the same module never
double up (the module-wide expansion skips any instance that already has its own row); expansion
past `MAX_EXPANDED = 12` collapses to a single entry instead of becoming a store directory.

**This is one real, tested slice of K7's eventual `SiteUsersDashboardService`, not the whole
service** — see "What's still the rollout phase's" below for exactly what's missing. It was
buildable now because §4.9 gives the full algorithm against connection rows alone; the
activity-based filtering K7's fuller version needs ("only stores this user has plausibly used")
needs a specific user's usage data this document's own scope doesn't provide a caller for.

### §4.10 — the guard/route module-isolation hole (X27)

The exact one-comparison fix from §4.10, applied in both places the plan names:

- `SiteUsersApiGuard::handle()` — a guard row naming a module now refuses (falls through to
  "not signed in here") a session belonging to a *different* module, evaluated **before** the
  `requires_auth` check and **before** the `require_verified_email` check (so a foreign module's
  unverified user gets the plain auth-required refusal, not a `403` that discloses the guard
  exists). A `null site_users_id` keeps meaning "any signed-in site user," so no existing guard
  row's behavior changes.
- `BuilderPublishedController::show()` — identical comparison on `BuilderRoute`. The existing
  redirect-to-login behavior for an anonymous visitor is unchanged; what changes is that a
  visitor signed in to the *wrong* module now falls into that same branch and is redirected to
  *this route's own* login, rather than being incorrectly admitted.

## What's still the rollout phase's, not this document's

Doc 04's own text is fully built. Four rollout phases still have real work beyond what doc 04
asks for — none of it is a gap in this document, all of it is named by the rollout table as a
different document's job:

- **K5** — `shared/authGate.ts`, `buildGateMarkup()`, `wireLoginForm()` extraction, the three
  storefront frontend wirings, `PortalApiError`, the gate's optional `su` parameter, and
  `su_session`'s `secure: true` on HTTPS (X19) — all doc 06's. §4.6's server-side half (the
  `site_users` block on all three public configs) is done; the client that reads it is not.
- **K6** — `ProcessorCustomerService`, `ProcessorPaymentMethodService`,
  `SiteUsersPaymentsController`, `DirectPaymentsCustomerResolver`, `paymentsResolver()`, the
  `/me/*` throttle buckets and 60-second provider cache (X18) — all doc 07's. §4.10's guard
  isolation (X27) is done; the payments-resolver half of K6 is not.
- **K7** — `sections.ts`, `buildDashboardGroups()`, `buildDashboardMarkup()`, the four section
  providers' own contributions, chrome selectors, canvas preview samples — all doc 08's. §4.9's
  expansion algorithm is done and tested; nothing calls it yet, because nothing renders a
  dashboard yet.
- **K8** — the four providers themselves (ecommerce orders/returns/favourites, events
  upcoming/past/tickets, appointments upcoming/past/reschedule, payments cards/purchases) — doc
  08's. `publicBlock()` (§4.6, which the plan's own header labels K8) is done.

## Files

**New:**
`app/SiteUsers/SiteUsersPagePaths.php`, `app/SiteUsers/SiteUsersDashboardService.php`

**Modified (registry/gateway layer):**
`app/SiteUsers/SiteUsersConsumerRegistry.php` (`attributableUser()`, `publicBlock()`)

**Modified (ecommerce):**
`app/Externals/Ecommerce/Support/EcommerceShopperAuth.php`,
`app/Http/Controllers/Externals/Ecommerce/EcommerceCartController.php`,
`app/Http/Controllers/Externals/Ecommerce/EcommerceShopperListsController.php`,
`app/Http/Controllers/Externals/Ecommerce/EcommerceShopperOrdersController.php`,
`app/Http/Controllers/Externals/Ecommerce/EcommerceStorefrontController.php`

**Modified (events):**
`app/Externals/Events/Services/EventRegistrationService.php`, `app/Externals/Events/Services/EventPassService.php`,
`app/Http/Controllers/Externals/Events/EventStorefrontController.php`

**Modified (appointments):**
`app/Externals/Appointments/Services/AppointmentBookingService.php`,
`app/Http/Controllers/Externals/Appointments/AppointmentStorefrontController.php`

**Modified (guard isolation):**
`app/Http/Middleware/SiteUsersApiGuard.php`, `app/Http/Controllers/BuilderPublishedController.php`

**New (tests):**
`tests/Feature/Connections/CheckoutEnforcementTest.php` (4),
`tests/Feature/Connections/EventsEnforcementTest.php` (7),
`tests/Feature/Connections/AppointmentsEnforcementTest.php` (7),
`tests/Unit/SiteUsers/SiteUsersDashboardServiceTest.php` (4),
`tests/Unit/SiteUsers/SiteUsersPagePathsTest.php` (4),
`tests/Support/SignsInSiteUsers.php` (a real bearer-token session helper, not a request-attribute
shortcut — every enforcement test above goes through the actual
`SiteUsersSessionService::resolveFromRequest()` path)

**Modified (tests):**
`tests/Unit/Ecommerce/EcommerceShopperAuthTest.php` (new `siteUserId()`/`siteUserReference()`
signatures), `tests/Unit/Events/EventPassAndScannerTest.php` (constructor type),
`tests/Unit/SiteUsers/SiteUsersConsumerRegistryTest.php` (`publicBlock()` tests),
`tests/Feature/SiteUsers/ConnectionDerivedColumnsTest.php` (constructor arg),
`tests/Feature/SiteUsers/SiteUsersRouteGuardFeatureTest.php` (4 new module-isolation tests)

## Acceptance (§4.11, in full)

| Criterion | Status | Evidence |
|---|---|---|
| `modeFor()` ignores the legacy column | ✅ | Pre-existing (K2) |
| Module-wide `events@*` → guest; `events@evt-1` guest_checkout false → required, rest guest | ✅ | Pre-existing (K2) |
| Deactivating the module → every connection resolves null | ✅ | Pre-existing (K2) |
| Module B session on a module A store: null attribution in guest, 401+module in required | ✅ | `SiteUsersConsumerRegistryTest`, and now wired end-to-end into all three storefronts |
| Off mode signed-in checkout writes `site_user_reference = null` | ✅ | Structural — `attributableUser()` returns null with no connection row, by construction |
| `POST /checkout/complete`: no session on required → 401; wrong session → 403 `EC_E_CART_OWNER_MISMATCH` | ✅ | `CheckoutEnforcementTest` (4 tests) |
| `/hold` and `/waitlist` on a required event/calendar without a session → 401, both modules | ✅ | `EventsEnforcementTest`, `AppointmentsEnforcementTest` |
| `registrations/mine`/`bookings/mine` → 401 for a guest; include disconnected; exclude removed | ✅ | `EventsEnforcementTest`, `AppointmentsEnforcementTest` (3 tests each) |
| `publicBlock()` for an unconnected instance has neither `module` nor any path | ✅ | `SiteUsersConsumerRegistryTest::test_public_block_for_an_unconnected_instance_…` |
| Connecting the same instance to a second module → `409 SU_E_INSTANCE_CONNECTED_ELSEWHERE` | ✅ | Pre-existing (`SiteUsersConnectionsAdminTest`) |
| `grep -rn "currentUserId(" app/Externals/Ecommerce app/Events app/Externals/Appointments app/Http/Controllers/Portal` is empty | ✅ | Confirmed — only a docblock comment mentions the name |
| Module-wide `ecommerce@*` with three stores → three dashboard entries; `events@*` → one | ✅ | `SiteUsersDashboardServiceTest` (4 tests) |
| `paymentsLinked()` false / `paymentsVisible()` true for a disconnected payments connection | ✅ | Pre-existing (`SiteUsersConnectionRegistryTest`) |
| An instance row plus a module-wide row for the same module → the instance entry once | ✅ | `SiteUsersDashboardServiceTest::test_instance_row_plus_module_wide_row_…` |

## Verification

```bash
cd w/www
php artisan test tests/Feature/Connections/ tests/Unit/SiteUsers/ tests/Feature/SiteUsers/SiteUsersRouteGuardFeatureTest.php \
  tests/Unit/Ecommerce/EcommerceShopperAuthTest.php tests/Unit/Events/EventPassAndScannerTest.php \
  tests/Feature/SiteUsers/ConnectionDerivedColumnsTest.php
php artisan test                                    # 341 passed, 3 pre-existing unrelated failures
php artisan siteusers:connections-audit             # "Nothing needs attention."
```

No frontend files changed in this round (every piece was server-side), so `tsc --noEmit`/
`npm run build` were not re-run — nothing for them to catch.

## Residue

- **`registration()`/`cancelRegistration()`'s status-code change** (401→404 for a guest with no
  token) is a deliberate, plan-mandated behavior change (§4.3's own "owner path… else 404" rule),
  not a bug fix disguised as one. No existing test asserted the old `401 EV_E_AUTH` response for
  either endpoint (confirmed by grep before making the change), so nothing needed updating for
  it — but a client depending on distinguishing "no token, no session" from "wrong owner" by
  status code will observe both as `404` now, which is the point.
- **`laravel_appointment_waitlist` has no `site_user_id`/`site_user_reference` column** — the
  waitlist gate was added, but there is nothing to attribute a waiting visitor to even when
  signed in. This is a pre-existing schema gap (flagged in the K1/K2 rollout hub's own residue
  section), not introduced or worsened here.
- **`EcommerceShopperAuth::siteUserId()`/`siteUserReference()` take the store model**, not just
  the request, which the plan's own §4.2 table shows as the intended shape
  (`siteUserReference(EcommerceStore $store, Request $request)`) — but `EcommerceCartService`
  and `EcommerceCheckoutService`'s internal signatures were deliberately left as `?int
  $siteUserId` rather than refactored to take a `SiteUserReference` string end-to-end. Both
  columns get written correctly either way (the module-aware bigint id is resolved at the
  controller boundary and both `site_user_id`/`site_user_reference` are still dual-written
  downstream exactly as before) — the security-relevant fix is *which* session counts as "the
  user," not which column a five-file-deep service signature reads first. A pure rename with no
  behavioral difference was judged not worth the diff size.
- **`SiteUsersDashboardService::expand()` has no caller yet** — by design, since nothing renders
  a dashboard until K7's `sections.ts`/`buildDashboardMarkup()` exist. It is real, tested code,
  not a stub, so K7 has one fewer thing to build rather than a placeholder to replace.
- **Event holds/appointment holds have no test coverage of their own creation logic** (capacity
  claiming, availability checks) anywhere in the suite — a pre-existing gap this phase's new
  tests deliberately did not try to close, since doing so is unrelated to connections and would
  have meant building out ticket-type/availability fixtures this document has no reason to own.
  The new enforcement tests exercise only the auth gate in front of both, which is genuinely new
  and genuinely this phase's.
