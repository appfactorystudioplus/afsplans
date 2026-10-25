# 11-rollout-and-testing — completion record (hub)

**Plan file:** [`../11-rollout-and-testing.md`](../11-rollout-and-testing.md)
**Repo:** `w/www`
**Date:** 2026-09-13
**Status:** ✅ Complete — every phase K0–K12 shipped. K0, K1, K2, K3 shipped; K4 shipped out of numeric order (its own
"Depends on" column names K2, not K3 — see the K4 row below); K5 fully shipped (both halves —
`publicBlock()` from doc 04, the frontend gate from doc 06); K6 fully shipped, including both
pieces once thought blocked (doc 07's SDK payer-resolver and email-change propagation — see the
K6/K11 recaps); K11 shipped out of numeric order right alongside K6, for the same reason K4
shipped ahead of K3: closing K6's own §7.10 gap meant building the email-change flow K11 names,
so K11 landed as a side effect of finishing K6 properly rather than being deferred past it; K7
and K8 (the Dashboard shell and its four section providers, doc 08) are fully shipped; **K9 —
self-service endpoints — shipped 2026-09-15** (shopper order cancel, the returns table +
endpoints + admin queue, events cancel policy + waitlist promotion, appointments
`customerPermissions()` enforcement, waitlist attribution (X28), `SiteUsersGuestClaimService`
(X24)); **K10 — guest continuity — shipped 2026-09-15** (the cross-store cart-cookie fix X25,
and the receipt/manage-link fix X26); **K12 — disconnection — shipped 2026-09-15** (the §13.5
refusal guard, the §13.6 payments carve-out's own 409, the §13.7 admin UI, and X41's guest
read-only surface — closing the one piece K10 left open. Also fixed a real pre-existing bug
found along the way: every ownership check on an existing registration/booking/order used the
active-only `attributableUserReference()`, so a disconnected instance's own true owner got a
false 404 before ever reaching a refusal or a read-only notice — see that document's own
completion record, "The ownership bug")

One file per numbered plan document, per the convention every sibling folder under
`plans/www/` follows. This one is the hub §11.1's phase table hangs off, same role
`direct_payments/complete/06-rollout-and-testing.complete.md` plays for that plan.

## Phase checklist

| Phase | Status | Where |
|---|---|---|
| **K0 — the prerequisite** | ✅ Shipped 2026-09-13, `w/www` commit `3a12a2dd7` | X1/X2/X3 fixed in `ProcessorBillingController`, `routes/portal.php`, the two `processors`/`direct_payments` SDK files. `tests/Feature/Processor/ProcessorPaymentMethodSecurityTest.php` (5 tests). No completion doc of its own — folded into this hub since neither 01 nor 11 is a "build this" document the way 03/04 are |
| **K1 — schema** | ✅ Shipped 2026-09-13 | [`03-database-schema.complete.md`](03-database-schema.complete.md), [`04-connection-registry-and-enforcement.complete.md`](04-connection-registry-and-enforcement.complete.md), [`../../site_users/complete/13-identity-and-email-change.complete.md`](../../site_users/complete/13-identity-and-email-change.complete.md) |
| **K2 — the registry** | ✅ Shipped 2026-09-13 — "the behaviour switch" | [`02-architecture.complete.md`](02-architecture.complete.md), [`04-connection-registry-and-enforcement.complete.md`](04-connection-registry-and-enforcement.complete.md) (updated) |
| **K3 — enforcement** | ✅ Shipped 2026-09-13 — identifier migration step 3, the three modules' ungated writes gated, `401` standardised, `currentUserId()` callers migrated | [`04-connection-registry-and-enforcement.complete.md`](04-connection-registry-and-enforcement.complete.md) (updated) |
| **K4 — admin** | ✅ Shipped 2026-09-13, fully complete and verified live in a browser — all four module-form panels, the Sign-in-page select, and the per-user activity card | [`05-admin-connections.complete.md`](05-admin-connections.complete.md) |
| **K5 — public block + gate** | ✅ Shipped 2026-09-14 — `publicBlock()` on all three storefronts (K3/doc 04) plus the frontend gate, `PortalApiError`, and `su_session` `secure: true` (doc 06) | [`04-connection-registry-and-enforcement.complete.md`](04-connection-registry-and-enforcement.complete.md), [`06-checkout-guard.complete.md`](06-checkout-guard.complete.md) |
| K6 — payments + guard isolation | ✅ Shipped 2026-09-14, in full — guard isolation (X27), doc 07's payer-link fix, `linkCustomer()` retirement, `/me/payment-methods*`, `/me/purchases`, rate limiting/caching, the SDK payer-resolver (§7.3/§7.4), and email-change propagation (§7.10) | [`04-connection-registry-and-enforcement.complete.md`](04-connection-registry-and-enforcement.complete.md), [`07-direct-payments-connection.complete.md`](07-direct-payments-connection.complete.md) |
| **K7 — dashboard shell** | ✅ Shipped 2026-09-14 — `GET /me/connections`, `SiteUsersDashboardService::forUser()` built on the X22 expansion algorithm, the four badge contributions, the client section registry, the shell rewrite, builder canvas integration | [`08-dashboard-sections.complete.md`](08-dashboard-sections.complete.md) |
| **K8 — the four providers** | ✅ Shipped 2026-09-14 — all four Dashboard section providers, `external/ui/direct_payments/` (new), wired into `main.ts`. A few per-provider actions (ecommerce returns/favourites, appointments reschedule, add-a-card) are named in this row's own description but not built — each blocked on something genuinely separate (K9's schema, a full availability-picker flow, a live Elements mount); see the completion doc | [`08-dashboard-sections.complete.md`](08-dashboard-sections.complete.md) |
| **K9 — self-service endpoints** | ✅ Shipped 2026-09-15 — shopper order cancel, the returns table + shopper/admin endpoints, events cancel-cutoff policy + same-transaction waitlist promotion, appointments `customerPermissions()` enforcement (X15) + waitlist attribution (X28), `SiteUsersGuestClaimService` (X24). A real bug found and fixed along the way: reschedule dropped the customer's `policy_snapshot`. Not built: the admin-side frontend for the returns queue and "claim guest records" (both backend-complete) | [`09-module-self-service.complete.md`](09-module-self-service.complete.md) |
| **K10 — guest continuity** | ✅ Shipped 2026-09-15 — cross-store cart-cookie fix (X25), receipt/manage-link fix (X26); the disconnected-instance read-only piece named in X26's own text is K12's, not built here | [`12-guest-continuity.complete.md`](12-guest-continuity.complete.md) |
| **K11 — identity and email change** | ✅ Shipped 2026-09-14, in full, out of numeric order (as a direct consequence of closing K6's §7.10 gap) — §13.1/§13.2 (identifier format + generator), all five steps of §13.3's migration including the irreversible column drop, §13.4's complete email-change flow, §13.6's `siteusers:reformat-references`, and (once K7/K8 shipped) §13.4.4's Dashboard/Admin UI | [`../../site_users/complete/13-identity-and-email-change.complete.md`](../../site_users/complete/13-identity-and-email-change.complete.md) |
| **K12 — disconnection** | ✅ Shipped 2026-09-15 — §13.5 refusal guard, §13.6 payments carve-out's 409, §13.7 admin UI, X41 guest read-only surface, and a real pre-existing ownership bug found and fixed along the way | [`13-disconnection.complete.md`](13-disconnection.complete.md) |

## K0 — what shipped (recap)

- **X1** — `ProcessorBillingController::resolveProcessorAndCustomer()` no longer resolves a
  customer by local bigint id.
- **X2** — `GET /portal/api/v1/processors/customers` requires `ApiAuthMiddleware`. Found and
  fixed a second, pre-existing bug in the same pass: the route was registered *after* the
  catch-all `GET /{id}`, so it 404'd before this change ever reached the controller —
  `ProcessorCustomerController::index()` was dead code, not a live leak.
- **X3** — deleted `ExternalProcessorsAPI.getPaymentMethods()` (called a route that never
  existed); its only caller already returned `[]` on the resulting throw, so behaviour is
  unchanged.

**Verification:** `php artisan test tests/Feature/Processor/ProcessorPaymentMethodSecurityTest.php`

## K1 — what shipped (recap; full detail in the two linked completion docs)

- `laravel_site_user_connections` + `SiteUserConnection` model, `laravel_site_user_mode_snapshots`
  (X29), the backfill (`SiteUsersConnectionsBackfill`), `siteusers:connections-audit`, the
  test-schema helper + `CreatesConnectionsFixtures`.
- The four container bindings (X31): `SiteUsersConnectionRegistry`, `SiteUsersConsumerRegistry`,
  `SiteUsersGateway`, `SiteUsersConnectionService`, all `scoped` in `AppServiceProvider`. Built
  ahead of K2's own line item because a binding cannot be proven against classes that do not
  exist — see the doc-04 completion record for exactly what is "built" versus "wired to a
  caller" (that re-pointing is K2's).
- `SiteUsersUserDeletion::detachAndDelete()` (X20), wired into
  `SiteUsersAdminController::deleteUser()`.
- Identifier migration steps 1–2 (`site_user_reference` added + backfilled + dual-written) on
  thirteen tables — one more than the plan text's "twelve" after reconciling against the
  schema that actually exists. Detail and the two corrected table names are in
  `site_users/complete/13-identity-and-email-change.complete.md`.

## K2 — what shipped (recap; full detail in the linked completion docs)

- `SiteUsersConsumerRegistry::modeFor()` re-pointed onto `SiteUsersConnectionRegistry` for every
  connectable module — the legacy `site_users_mode` column is no longer read for ecommerce,
  events, appointments or direct_payments. `attributableUserReference()` added.
  `assertAuthenticated()` re-pointed and gained a `module` field. `currentUserId()` marked
  `@deprecated`, unchanged in behaviour.
- `SiteUsersGateway::currentUserFor()` added (§2.5).
- `config/site_users_consumers.php` completed to §4.1's full shape: `direct_payments` added as
  a sixth registered module, `connectable` set is exactly `{ecommerce, events, appointments,
  direct_payments}`, `user_column` moved to `site_user_reference` for the three storefront
  modules.
- §4.8 — `SiteUsersPublicController::linkCustomer()` and `siteusers:link-customers` re-pointed
  from `modules.link_payment_customers` onto `SiteUsersConnectionRegistry::paymentsLinked()`.
  `linkCustomer()`'s skip reason changed from `link_payment_customers_off` to
  `payments_not_connected`.
- `SiteUsersConnectionService::upsert()`/`remove()` and `SiteUsersDerivedColumns::sync()` —
  built in K1, proven end-to-end for the first time in K2's `ConnectionDerivedColumnsTest`.

**Two real defects found by writing that proof, not by review — both fixed in K2:**

1. **A circular dependency the plan's own code produces.** §4.2 shows
   `SiteUsersConnectionRegistry`'s constructor taking `SiteUsersConsumerRegistry`; §4.2's own
   `modeFor()` re-pointing (this phase) makes `SiteUsersConsumerRegistry` depend back on
   `SiteUsersConnectionRegistry`. Resolving either through the container crashed with a memory
   exhaustion (infinite recursion), not a clean container error. Fixed by removing
   `SiteUsersConnectionRegistry`'s dependency on the consumer registry entirely — its one use
   (a `connectable` check in `forDashboard()`) now reads `config('site_users_consumers')`
   directly, which both classes can do without depending on each other.
2. **`SiteUsersDerivedColumns::sync()`'s own `direct_payments` branch was dead code.** The
   plan's shown config gives `direct_payments` an `instance.model`
   (`\App\Models\Externals\DirectPayment::class`), but `laravel_direct_payments` has no
   `site_users_mode` column — the plan's `sync()` code only calls `syncLinkPaymentCustomers()`
   when `instanceModel === null`, which is never true for `direct_payments` under its own
   config. Fixed by special-casing `direct_payments` before the instance-model branch.
   Found because `ConnectionDerivedColumnsTest`'s direct_payments assertion failed, not
   because anyone re-read the two sections side by side.

A third bug surfaced by the same test, unrelated to the plan text: `DB::table(...)->whereKey()`
does not exist on the plain query builder (only on Eloquent's) — `SiteUsersDerivedColumns`
used it once, on the `laravel_site_user_modules` update. Fixed to `->where('id', $id)`.

## K3 — what shipped (recap; full detail in `04-connection-registry-and-enforcement.complete.md`)

Shipped after K4 in wall-clock time (K4 shipped first, deliberately, per the note below), but
numbered K3 here because that is the rollout table's own label for this content.

- **Module isolation wired end-to-end**, not just proven in the registry: `currentUserId()` — the
  any-module, bigint-returning gateway call — is gone from every consumer
  (`app/Externals/Ecommerce`, `app/Events`, `app/Externals/Appointments`, `app/Http/Controllers/Portal`). A new
  `SiteUsersConsumerRegistry::attributableUser()` resolves the module-aware `SiteUser` once per
  call site so both the identity migration's still-dual-written bigint and its reference read
  come from the same query.
- **Every enforcement point in §4.5's table**: ecommerce's `checkout/complete` (the X8 cart-owner
  logic, exactly as written — required-mode-no-session → `401`, wrong-session-owns-the-cart →
  `403 EC_E_CART_OWNER_MISMATCH`), events' and appointments' `hold`/`waitlist` (new gates —
  capacity can no longer be claimed anonymously on a `required` instance), each with its own
  `_E_AUTH_REQUIRED` code and a `module` hint.
- **§4.4's "mine" endpoints rewritten to the plan's own worked example**: `registrations/mine`
  and `bookings/mine` return `401` for a guest instead of silently returning `[]` (X16), and
  filter through `resolveAny()` so a disconnected instance's history still shows while a removed
  connection's does not (13 §13.9).
- **Five copies of the same bigint owner-comparison bug collapsed into one correct helper** in
  `AppointmentStorefrontController` (`isBookingOwner()`), and the equivalent fix repeated across
  `EventStorefrontController`'s three owner-path endpoints — all now compare
  `site_user_reference`, module-aware, instead of a bigint that can coincidentally match across
  modules.
- **Two slices of K5/K6/K7 that doc 04 itself defines were also finished in the same pass**,
  since they share the same registry and were faster to finish than to re-scope around:
  `publicBlock()` + a new `SiteUsersPagePaths` (§4.6 — reads the real
  `laravel_builder_routes` dashboard-publish row, wired into all three storefronts' public
  configs) and the guard/route module-isolation fix (§4.10, X27 — `SiteUsersApiGuard` and
  `BuilderPublishedController` now both refuse a session from the wrong module). Neither
  finishes its whole phase — see the phase table above for exactly what's still doc 06/07/08's.
- **34 new/updated tests** across `tests/Feature/Connections/` (new directory — `Checkout`/
  `Events`/`AppointmentsEnforcementTest`), `tests/Unit/SiteUsers/` (`SiteUsersDashboardServiceTest`,
  `SiteUsersPagePathsTest`, extended `SiteUsersConsumerRegistryTest`), and
  `SiteUsersRouteGuardFeatureTest` — all going through the real HTTP routes and a real
  bearer-token session (`tests/Support/SignsInSiteUsers.php`, new), not request-attribute
  shortcuts.

## K4 — what shipped (recap; full detail in `05-admin-connections.complete.md`)

Shipped ahead of K3 deliberately — K4's own "Depends on" column names K2, not K3, and §11.1's
ordering notes don't forbid it either.

- The full admin API — eleven endpoints (nine plus `login-pages` and `activity`, added while
  closing out the last two gaps below), one controller (`SiteUsersConnectionsController`),
  every write going through `SiteUsersConnectionService` (K1/K2), zero direct
  `SiteUserConnection::` writes from a controller.
- The Connections tab, wired into the existing Site Users module detail screen: connect,
  disconnect (with the guest-order-count confirm), the guest-checkout switch, a
  config-driven settings panel with the "Sign-in page" select, and the X29 needs-attention
  banner with working Connect/Dismiss.
- **All four** module-form shortcut panels — ecommerce (the plan's own worked example),
  events (identical panel on its confirmed-live create/edit form), appointments (standing
  alone on the configure page — the module has no calendar-settings screen to embed it in),
  and Direct Payments (its real per-instance form, missed in the first pass and added in the
  second, with copy that names card-linking rather than a checkout mode since the module has
  no `guest_checkout` switch) — plus the Modules index card's connection-count line.
- Both controls the phase explicitly retires: the store form's `site_users_mode` select and
  the module Settings tab's `link_payment_customers` checkbox.
- The settings modal's real copy: §5.5's exact ecommerce cancellation/returns sentences, with
  equivalent copy in the same voice for events'/appointments' own schema knobs, plus the
  resolved-default label placeholder. Fixed a real bug surfaced while doing this — sibling
  settings groups sharing a key name (`cancel.enabled`/`returns.enabled`) collided on the
  original bare-key `data-su-knob` lookup.
- **The "Sign-in page" select**, resolved without the `data-siteusers-kind` marker the plan
  assumes (confirmed absent from the codebase by grep) — reads `laravel_builder_routes.login_route`
  directly instead, a column the builder's own route-protection screen already stamps on every
  published page.
- **§5.8's per-user "Linked activity" card**, resolved without waiting on K7's
  `SiteUsersDashboardService` (which doesn't exist yet) — queries the same
  `site_user_reference`-bearing tables K1 built directly, scoped to only the modules actually
  connected for that user, with real deep links into each module's real admin view.
- **Verified live, on the user's explicit go-ahead, across two passes.** The local portal's
  login chains into real Brain/Checkpoint member-allocation lookups, not just a password
  check, and this work had neither passwords for the three real local memberships nor standing
  authorization to create one — so it asked rather than assumed. The user then explicitly said
  to create one. A disposable `User`+`Membership` was created via `php artisan tinker` and used
  to log into `https://www.test/portal/admin1/login` and drive every piece of the Connections
  surface for real: connect, the exact §5.4 guest-checkout copy switching live, the settings
  modal's exact §5.5 copy with a real save round-trip (`window_minutes` persisted 60→45
  correctly), the Modules index card's note line, the Sign-in page select populating and
  persisting a real published route, the needs-attention banner's Connect (correctly refused a
  seeded fake instance) and Dismiss, all four module-form panels rendering correct
  connected/not-connected state and copy, and the Linked activity card rendering a real seeded
  order and deep-linking correctly into `ecommerce:orders`. All test fixtures from both passes
  were cleaned up afterward and `siteusers:connections-audit` confirmed clean; the test
  membership itself was left in place. Full detail in `05-admin-connections.complete.md`.

## K5 — what shipped (recap; full detail in `06-checkout-guard.complete.md`)

Started only once K3 had actually shipped — an earlier turn this session flagged the rollout
table's own ordering rule ("K5 after K3, never before… a gate that says 'sign in' while the
server still accepts anonymous requests is decoration, not enforcement") and held off on the
user's own instruction. K3 shipped in the interim; this is that hold resuming, not a reversal.

- **`shared/authGate.ts`** — `gateState()`, `requireSignIn()`, `isAuthRequired()`, as the plan's
  own code gives them, plus a new `offerOptionalSignIn()` the plan's prose calls for but its
  code doesn't show (see the completion doc's "A real design decision" — the plan's own worked
  ecommerce example contradicts its own prose about what `guest` mode should look like; the
  prose was built, not the contradicting literal code path).
- **`wireLoginForm()` extracted** from `renderLogin()`, **`buildGateMarkup()` added**, both in
  `site_users/ui.ts` — one bug closed that the plan's own shown code doesn't handle:
  `register_path` (not just `allow_registration`) gates whether the register affordance is a
  working link or "Ask the site owner for an account."
- **All three storefronts wired** — ecommerce's `renderCheckout()`, events' `renderRegister()`,
  appointments' `renderBooking()` — each adapted to what the real file actually does rather
  than the plan's more idealized pseudocode. Appointments in particular has no up-front page
  gate; its gate is defense-in-depth on the hold/book calls themselves, because that is where
  this UI's own claim moment genuinely is.
- **`PortalApiError`** — a new shared `external/api/shared/apiError.ts` plus a matching
  `portal.d.ts` interface, wired into all three SDK clients' `fetchJson()`.
- **`su_session` `secure: true`** on HTTPS, `SameSite=Lax` unchanged — the one-line, no-CSRF-
  implication fix the plan names explicitly as in scope for this phase.
- **A real bug, found only by live-clicking through it, not by re-reading the plan.** Every
  defense-in-depth catch handler's first working version built a synthetic block from nothing
  but the refusal's `module` hint, silently dropping the "Create an account" link in favor of
  "Ask the site owner" — passed every test written before the bug was found, since those tests
  asserted a gate appeared, not what was in it. Fixed by reusing the real block already in
  scope at each catch site.

## K6 — what shipped (recap; full detail in `07-direct-payments-connection.complete.md`)

Started only after the user pushed back on this session's own first read, which had concluded
§7.3/§7.4 and §7.10 were blocked without first checking whether the sibling plans they depend
on had completion reports. They did; reading both in full confirmed the blocked conclusion for
those two sections specifically, but also showed `direct_payments/05` (DP6) had already shipped
most of doc 07's groundwork ahead of this phase — narrowing K6's real remaining work to a
security fix, a retirement, and two genuinely new endpoints.

- **A real, previously-shipped security gap, closed**: `myPayer()`'s first-call resolution used
  `PayerService::resolveForCheckout()` — the guest-oriented, email-adopting method — for a
  signed-in user's first call, which could silently attribute a stranger's guest payer (and its
  purchase/payment-method history) to whoever later signed in with a matching email. New
  `PayerService::resolveForSiteUser()` never queries by email; `myPayer()` rewritten to call it
  and re-pointed onto `paymentsLinked()`. Regression-tested (`SiteUsersMyPayerTest`'s new case),
  confirmed against the pre-fix code by reverting the fix and watching it fail.
- **`linkCustomer()` / `POST /me/customers` retired**, per doc 07 §7.8's own words that the
  per-processor link table's writer "no longer exist[s]" now that `GET /me/payer` covers it —
  deleted from both route files and the controller, with its dedicated test file removed after
  confirming it tested nothing else.
- **`GET/POST/DELETE /me/payment-methods*`** — new `SiteUsersPaymentsController` +
  `ProcessorPaymentMethodService` (a re-keyed extraction of `ProcessorBillingController`'s
  existing Stripe/Square/PayPal logic), gated on `paymentsLinked()`/`paymentsVisible()` per
  action per §7.5/13 §13.6, with §7.9's 60-second cache.
- **`GET /me/purchases`** — new `SiteUsersPurchasesService`, unioning façade orders/bookings
  (visible-or-disconnected, excluded only once removed) with stray payer-matched DP invoices,
  money resolved through one `PaymentGateway::snapshots()` call, deliberately never joined on
  the client-writable `invoices.user_id` (X23).
- **§7.9's rate limiting**, closing a real gap: `SiteUsersPublicThrottle`'s `/me/*` family was
  completely unthrottled before this phase (matched none of its `if/elseif` branches). New
  session-hash-keyed buckets for the payment-methods provider fan-out (tight) and the rest of
  `/me/*` (looser), implemented to cover both `portal/` and `auth1/` prefixes — the plan's own
  illustrative snippet matches `portal/`-only, which would have left `auth1/` traffic on this
  same family unthrottled had it been copied literally.
- **The two pieces this session first called "blocked" got unblocked, not skipped.** §7.10
  (email-change propagation) needed `site_users/13`'s email-change flow built first — done as
  K11, below, specifically to give §7.10 something to call. §7.3/§7.4 (the SDK payer-resolver)
  was built as a real, standalone addition (`useCustomerResolver()`, `savedPaymentMethods()`,
  `SiteUsersClient.paymentsResolver()`) — with one disclosed, verified exception: no live
  storefront call site exists anywhere in this codebase to attach it to, so it ships unwired
  rather than wired to something invented for the purpose. Full reasoning in
  `07-direct-payments-connection.complete.md`'s own "§7.3/§7.4" section.

## K11 — what shipped (recap; full detail in `../../site_users/complete/13-identity-and-email-change.complete.md`)

Shipped out of numeric order, right alongside K6, for the same kind of reason K4 shipped ahead
of K3: K6's own §7.10 gap could not be closed without an email-change endpoint to call from, and
building that endpoint meant building the plan section that defines it.

- **§13.1/§13.2** — `SiteUserReference::generate()`/`isValid()`, the `usr-` → `u...` format
  switch at both call sites, existing `usr-` references left untouched and still resolving.
- **§13.3 steps 3–5** — step 3 (read the reference) turned up three real, live bigint reads a
  much earlier phase (K3) had missed entirely, all in the signed-in-buyer's-own-payer lookup
  three modules share; fixing that meant adding `site_user_reference` to
  `laravel_site_user_payers` too (not one of the plan's own thirteen tables, but a real
  cross-module join). Eight further crash-risk sites (a raw property write or `WHERE` clause on
  a column about to be dropped) found and fixed across ecommerce's cart/checkout/promotion
  services. Steps 4–5 (stop writing, then drop `site_user_id` from all thirteen tables)
  completed only after `siteusers:reference-drift` (new) reported zero drift on the real local
  database, and **only after explicit confirmation from the user** that the irreversible column
  drop should proceed rather than stopping at step 4.
- **§13.4** — the full email-change flow: `PATCH /me/email` mails a token to the new address;
  `POST /email/change/confirm` (public, token-only) swaps it, re-checking uniqueness inside the
  same transaction (a race → `409`, not a raw integrity error), notifies the old address,
  revokes every other session but the confirming one if there is one, and closes §7.10 by
  calling `PayerService::updateEmail()`. SSO-only accounts skip the password check by design.
- **§13.6** — `siteusers:reformat-references`, opt-in, per-user, one transaction each.
- **Not built**: §13.4.4's Profile-pane email field and the admin per-user email screen. At the
  time this was written K7/K8 (the Dashboard shell) hadn't shipped yet; they have now (see the
  K7/K8 recap below), but this specific field was not added to the Profile pane as part of that
  work either — it remains a real, still-open follow-up, now genuinely unblocked rather than
  waiting on infrastructure that doesn't exist.

## K7/K8 — what shipped (recap; full detail in `08-dashboard-sections.complete.md`)

The Dashboard grows one navigation group per connected module — badges, read-only rendering,
deep links (`#module:instance:view/ref`), and a retry pane when a provider throws.

- **Server**: `GET /me/connections` (`SiteUsersDashboardService::forUser()`), built directly on
  the X22 module-wide expansion algorithm an earlier phase had already shipped standalone —
  label resolution, capability filtering (registry capabilities, narrowed by the connection's
  own settings, narrowed again to the read-only subset when disconnected), and four new
  `App\SiteUsers\Dashboard\*Contribution` badge classes (ecommerce `orders`, events `upcoming`,
  appointments `bookings`; `returns` has no backend until K9, payments carries no badge at all
  by design).
- **Client registry**: new `external/ui/site_users/sections.ts`
  (`DashboardSectionProvider`/`registerSectionProvider()`) and a new
  `external/ui/direct_payments/` package — a section provider and markup builders only, no
  `install()`/`mount()`, imported explicitly by `main.ts` since nothing else in the bundle
  references it.
- **Shell**: `buildDashboardGroups()`/`navKey()` replace the old fixed Account-only tuple;
  lazy per-view rendering cached in the DOM; a `refreshBadges()` addition (beyond the plan's own
  text) that re-fetches connections and patches the sidebar's badge counts after an action,
  closing §8.8's own "badges… refresh after a cancel" acceptance line the plan's `ctx.refresh()`
  alone doesn't cover.
- **Four providers**: ecommerce (`orders`, `order` detail), events (`upcoming`, reusing the
  existing registrations list+cancel verbatim), appointments (`upcoming`, cancel wired,
  reschedule not), payments (`cards` — remove/set-default wired, add-a-card not — and
  `purchases`). Each omission is a named, disclosed boundary (a missing schema, a missing
  availability-picker flow, a missing card-entry form), not an oversight.
- **A real gap closed in passing**: `GET /me/payment-methods`'s merged rows carried no
  `processor_reference_num`, making remove/set-default unusable on a payer with cards on more
  than one processor. Fixed by tagging each row with its processor.
- **A real self-contradiction in doc 08 itself, resolved by the later, explicitly-superseding
  document**: §8.8 has one acceptance line saying disconnecting removes a group and another,
  two lines later, saying a disconnected connection still renders read-only. `13-disconnection.md`
  supersedes the first outright; built the read-only behavior.

## Deviations from the plan text, found while implementing

- **K0's `GET /customers` route-ordering bug** (above) — not predicted by the plan, found by
  running the test the plan itself specifies.
- **"Twelve tables" is thirteen in the real schema**, and two of the plan's named tables don't
  exist under the names it gives them (`laravel_form_files` has no `site_user_id` — the forms
  table that does is `laravel_form_partials`; `laravel_appointment_waitlist` has no
  `site_user_id` — the appointments table that does is `laravel_appointment_credits`). Corrected
  against `Schema::getColumnListing()` on the real local database, not assumed from the plan
  prose. Full accounting in the site_users/13 completion doc.
- **`laravel_ecommerce_gift_cards` and `laravel_form_submissions`/`laravel_form_partials`** have
  no live writer anywhere in `app/` today — the column and dual-write plumbing exist, but there
  is nothing yet to null on a user delete or fill in on an insert. Consistent with the plan's own
  X33 note about forms; gift cards were not previously flagged but are the same shape.
- **K3's owner-path endpoints now return `404`, not `401`, for a guest with no token** (`events`'
  `registration()`/`cancelRegistration()` used to return `401 EV_E_AUTH`). §4.3's own table calls
  for this ("owner test fails → the token path may still succeed → else `404`"); the earlier
  `401` disclosed, via status code alone, that *something* exists at a reference even for a wrong
  guess. No existing test asserted the old code (confirmed by grep before changing it).
- **`EcommerceShopperOrdersController`'s "mine" comparison switched from `site_user_id` to
  `site_user_reference`** — closer to the identifier migration's own "read the reference" step 3
  than the plan's literal pseudocode, which still compares bigints in a few of its worked
  examples.
- **`EcommerceCartService`/`EcommerceCheckoutService` kept their `?int $siteUserId` signatures**
  rather than being refactored to take a `SiteUserReference` string end-to-end, even though
  §4.2's table names `EcommerceShopperAuth::siteUserReference(): ?string` as the replacement for
  `siteUserId(): ?int`. Both methods exist now (`EcommerceShopperAuth` gained both), but the
  services five files deeper still take the bigint — both columns are dual-written correctly
  either way, and the security-relevant fix is *which* session resolves as "the user" (now
  module-aware), not which column a deep internal signature reads first.

## Verification

```bash
cd w/www
php artisan test tests/Unit/SiteUsers/SiteUserConnectionModelTest.php \
  tests/Feature/SiteUsers/ConnectionsBackfillTest.php \
  tests/Feature/SiteUsers/ContainerBindingTest.php \
  tests/Feature/SiteUsers/SiteUsersUserDeletionTest.php \
  tests/Unit/SiteUsers/SiteUsersConnectionRegistryTest.php \
  tests/Unit/SiteUsers/SiteUsersConsumerRegistryTest.php \
  tests/Feature/SiteUsers/ConnectionDerivedColumnsTest.php \
  tests/Feature/SiteUsers/SiteUsersConnectionsAdminTest.php \
  tests/Feature/Connections/ \
  tests/Unit/SiteUsers/SiteUsersDashboardServiceTest.php \
  tests/Unit/SiteUsers/SiteUsersPagePathsTest.php \
  tests/Feature/SiteUsers/SiteUsersRouteGuardFeatureTest.php \
  tests/Feature/SiteUsers/SiteUsersAuthFeatureTest.php
php artisan test                                                      # 404 passed, 3 pre-existing unrelated failures (K6 added 27 tests, K11 added 27 more, K7/K8 added 11 more)
npm run test:external-ui                                              # 59 passed (was 48 before K7/K8; +11 new dashboard tests)
php artisan siteusers:connections-audit                               # exit 0 on this deployment
cd w/www && npx tsc --noEmit -p tsconfig.json                         # exit 0
cd w/www && npx tsc --noEmit -p resources/js/external/ui/tsconfig.json    # exit 0
cd w/www && npx tsc --noEmit -p resources/js/external/api/tsconfig.json   # exit 0
cd w/www && npm run build                                             # exit 0
cd w/www && npm run build-external-webpack                            # exit 0
cd w/www && npm run build-external-ui-webpack                         # exit 0
cd w/www && npm run test:external-ui                                  # 48 passed (K5's authGate.ts + appointments.test.ts additions)
cd w/www && npm run test:builder                                      # 481 passed, 93 suites
```

## Residue

- **Guard/route "blast radius" audit check (X27)** from §11.2's bullet list — still not built,
  but for a narrower reason than before: `SiteUsersApiGuard` and `BuilderPublishedController` DO
  enforce module isolation now (04 §4.10, shipped alongside K3), so the boundary this check would
  measure against exists; what's missing is the check itself (counting affected guard/route rows
  and sessions, reported before the enforcement ships to a given deployment — see the K6 recap).
  `app/Console/Commands/SiteUsersConnectionsAudit.php`'s own docblock was updated to say so rather
  than leave the old "no boundary exists yet" reasoning stale. **derived-column-drift-against-a-
  live-writer check (X30)** was already implemented by K1, unaffected by this update.
- **`mergeSettings()`** — §4.7's `upsert()` references a settings-schema merge/cast helper the
  plan text doesn't fully specify. K1's `SiteUsersConnectionService::upsert()` passes `settings`
  through unvalidated; the admin-editor's schema validation is K4's actual settings surface, so
  this is deferred there rather than guessed at now.
- **`publicBlock()` is now built** (04 §4.6, this round) — closing the item this section used to
  carry about it being blocked on an unbuilt `SiteUsersPagePaths` collaborator. See the K3 recap
  above and `04-connection-registry-and-enforcement.complete.md` for what shipped and which of
  the plan's three conflicting phase-ownership claims on it (K2's bullet, §4.6's own "(K8)"
  header, K5's "public configs" line) it satisfies.
- **`SiteUsersPublicController::myPayer()`** reads `$module->link_payment_customers` directly
  and was NOT re-pointed in K2 — §4.8 names exactly two readers (`linkCustomer()` and
  `siteusers:link-customers`) and `myPayer()` isn't one of them. Left alone deliberately: the
  derived-column write-through (§3.5, now actually wired as of this phase) keeps that column
  correct for exactly the "one release" grace period it exists for, so `myPayer()` stays
  accurate without needing a K2 edit.
- **This item is now closed, not residue**: `EcommerceShopperAuth::siteUserId()`,
  `EventRegistrationService`, `AppointmentBookingService` and their controllers no longer call
  the deprecated `currentUserId()` — all migrated to `attributableUser()`/
  `attributableUserReference()` this round (K3's own line item, "`currentUserId()` callers
  migrated"). `grep -rn "currentUserId(" app/Externals/Ecommerce app/Events app/Externals/Appointments
  app/Http/Controllers/Portal` returns only a docblock comment, no call sites. This bullet is
  kept, corrected, rather than deleted, because an earlier draft of this record described the
  pre-K3 state as intentional and deferred — worth being visibly wrong about once rather than
  silently dropped.
- **Ecommerce's `[data-commerce-kind]` blocks have no generic auto-mount scan** — unlike
  events/appointments/site_users, `ecommerceUi.install()` never calls `mount()` for a
  `[data-commerce-kind]` root; the real local `new-page-checkout` page's own "designed" render
  path doesn't implement a checkout form at all. K5's live verification worked around this with
  a temporary minimal published page and a direct `mount()` call — see
  `06-checkout-guard.complete.md`'s own Residue for the full account. Pre-existing, unrelated to
  connections, and not something this phase was asked to fix; flagged because the gap is real
  and someone will hit it building K7/K8's dashboard providers against the same file.
