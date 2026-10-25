# 08-dashboard-sections — completion record

**Plan file:** [`../08-dashboard-sections.md`](../08-dashboard-sections.md)
**Rollout phase:** K7 (the shell + registry + server) and K8 (the four providers), shipped together
**Repo:** `w/www`
**Date:** 2026-09-14
**Status:** ✅ Shipped — the Dashboard grows one navigation group per connected module, exactly
as §8.1 describes, with badges, read-only rendering, deep links and a retry-on-error pane. Two
follow-up passes have since closed every gap either one disclosed except one: ecommerce
favorites, events' upcoming/past split, appointments reschedule, dedicated retry-pane/deep-link
tests, and — across both passes — Direct Payments' "add a card" for Stripe, Square and PayPal,
including a first card with no prior checkout, are all built now. **Ecommerce `returns` remains
unbuilt** — its backend table doesn't exist until K9 ([03](../03-database-schema.md) §3.3),
reconfirmed absent from the real schema in both follow-up passes, not assumed unchanged from
the first.

## What shipped

### §8.2 — the client section registry

New `external/ui/site_users/sections.ts`: `DashboardConnection`, `DashboardView`,
`DashboardRenderContext`, `DashboardSectionProvider`, `registerSectionProvider()`/
`sectionProvider()` — exactly the plan's own shape, with `DashboardRenderContext` pulled out
as its own named interface (the plan inlines it in `render()`'s signature) purely for
readability; no behavioural difference.

`external/ui/direct_payments/` is new, as the plan requires: a section provider and markup
builders only, no `install()`, no `mount()`. `main.ts` imports it explicitly
(`import './direct_payments'`) — confirmed by a test that the provider registers only because
of that import, not as a side effect of anything else in the bundle.

### §8.3 — `GET /me/connections`

New `SiteUsersDashboardService::forUser()`, built on top of `expand()` — the X22 module-wide
expansion algorithm a much earlier phase (K3/K4-adjacent work) had already built and tested
standalone, exactly as `11-rollout-and-testing.complete.md`'s own K7 row anticipated ("nothing
calls it yet"). `forUser()` is the caller that was missing: label resolution (a new
`SiteUsersConsumerRegistry::resolveLabel()` — the three-tier fallback §8.3 step 3 describes,
factored out so `SiteUserConnection::resolvedLabel()`/`readOnlyNotice()` (13 §13.2) can share
it instead of duplicating the config lookup), capability filtering (registry capabilities,
narrowed by the connection's own settings — `returns.enabled`/`reschedule.enabled` — then
narrowed again to the read-only subset when disconnected), and badges.

Four new `App\SiteUsers\Dashboard\*Contribution` classes, resolved from a map keyed by
`module_key` exactly as the plan specifies:

- `EcommerceContribution` — `orders` only. `returns` is not queryable: `laravel_ecommerce_return_requests`
  does not exist until K9 ([03](../03-database-schema.md) §3.3), confirmed absent from the real
  schema before writing this class, not assumed from the plan text.
- `EventsContribution` — `upcoming`, via a small subquery on `laravel_event_occurrences`
  (`EventRegistration` carries no `starts_at` of its own).
- `AppointmentsContribution` — `bookings`.
- `PaymentsContribution` — always `[]`, per the plan's own "a card count is not an action item."

New route `GET /me/connections` on both `routes/portal.php` and `routes/auth1.php`, under the
existing `site_users.session` group.

### §8.4 — the shell

`site_users/ui.ts`'s `DASH_SECTIONS` fixed tuple is gone. `buildDashboardGroups()` and
`navKey()` are new, exactly per the plan. `buildDashboardMarkup()` gained the `groups`
parameter; the Account group's three sections keep their exact existing content but now key as
`account:*:profile`/`account:*:security`/`account:*:sessions`, and one empty
`<section data-su-section="<navKey>" hidden>` is rendered per module view, filled by its
provider on first activation and cached in the DOM afterward (a `Set<string>` of already-
rendered keys).

Deep linking: `location.hash` is parsed on load (`#module:instance:view/ref`, split on the
first `/`, exactly as §8.4 shows), falls back to `account:*:profile`, and every nav
click/programmatic `goTo()` writes the hash back via `history.replaceState` — the back button
leaves the dashboard rather than walking its panes, matching the plan's own stated reason for
`replaceState` over `pushState`.

Failure inside a pane: a throwing `provider.render()` is caught, the pane shows
`buildSectionErrorMarkup()` with a retry button that re-invokes the same activation with
`force: true`; every other pane (Profile in particular) is unaffected, since each section's
markup lives in its own `<section>` and only the failing one is touched.

**One addition beyond the plan's own text, closing its own §8.8 acceptance line "badges…
refresh after a cancel without a full page load":** the plan's `ctx.refresh()` re-renders the
active pane but says nothing about the *sidebar's* badge counts, which are baked into the
initial markup and don't follow a pane re-render on their own. Added `refreshBadges()` —
re-fetches `/me/connections` and patches every nav button's `[data-su-badge]` in place — called
alongside the pane refresh whenever a provider's `refresh()` runs. The events and appointments
providers' cancel handlers were written to call `ctx.refresh()` rather than re-invoking their
own `render()` directly specifically so this fires.

### §8.5 — the four providers

| Provider | Views built |
|---|---|
| `ecommerce/dashboard.ts` | `orders` (list), `order` (detail, row click or deep link), `favorites` (list + remove) |
| `events/dashboard.ts` | `upcoming`, `past` (split from one `myRegistrations()` call — see below), `ticket` (detail, row click or deep link) |
| `appointments/dashboard.ts` | `upcoming` — `buildMyBookingsMarkup()`'s `showActions` now adds Cancel **and** Reschedule (an inline `buildSlotButtonsMarkup()` picker per row, opened on click) |
| `direct_payments/dashboard.ts` | `cards` (list, remove, set-default, **add a card**), `purchases` |

Only ecommerce `returns` remains unbuilt — see the header note above.

Every provider's `views()` filters on `connection.capabilities` (already server-filtered for
read-only) rather than assuming a view is always offered — a disconnected ecommerce connection
correctly loses its `orders` view's cancel affordance because the capability that would gate it
is gone before the client ever sees it (13 §13.4's own design point).

### Follow-up pass — closing the first pass's own disclosed gaps

**Ecommerce favorites.** `buildFavoritesMarkup(items, showRemove = false)` extracted from the
storefront's own inline favorites branch (`ecommerce/ui.ts`) — the same "opt-in parameter,
default false" convention every markup extension in this codebase follows, so the existing
storefront page's own usage is byte-for-byte unchanged. The Dashboard's `favorites` view turns
`showRemove` on when the connection isn't read-only; `shop.favorites()`/`removeFavorite()` were
already real SDK calls.

**Events upcoming/past.** `myRegistrations()`'s response carried no date to split on — fixed at
the source (`EventStorefrontController::myRegistrations()`), not worked around client-side with
N+1 calls: eager-loads `EventRegistration::occurrence` and a single batched
`Event::whereIn()->pluck()` name lookup, adding `starts_at`/`event_name` to each row
(`PortalEventRegistrationRecord` extended to match). `splitRegistrationsByDate()` (new, pure)
buckets by `Date.parse(starts_at) > Date.now()`; `buildMyRegistrationsMarkup()` gained a `bare`
parameter (skip the hero wrapper, tag each row `data-registration-ref`) so all three views
(`upcoming`/`past`/`ticket`) reuse one row-markup function rather than three.

**A real navigation bug, found and fixed before it shipped:** the ecommerce `order` and events
`ticket` detail views were initially left out of their provider's own `views()` return (reasoning
at the time: "they're not nav buttons"). That meant `buildDashboardGroups()` never created a
`<section>` or a `groupByKey` entry for them — `ctx.goTo('ecommerce', ref, 'order', orderRef)`
would set the hash correctly and then silently do nothing, breaking row-click-to-detail and deep
linking for both. Fixed with a `navVisible?: boolean` flag on `DashboardView` (default `true`):
`false` still populates `views()`/`groupByKey`/the section markup — it only skips the nav-button
HTML in `buildDashboardMarkup()`. Caught by review, not a test failure, before any test ran.

**Appointments reschedule.** `buildMyBookingsMarkup()`'s per-row markup gained a Reschedule
button alongside Cancel (same `showActions` gate) plus a `data-ap-slots-for="<ref>"` host, hidden
until opened. `appointments/dashboard.ts` wires it: `api.availability({service_reference_num})`
for that booking's own service, `buildSlotButtonsMarkup()` reused verbatim from the booking
page's own picker, `api.reschedule(ref, slot.start)` — a single call, no hold-then-confirm cycle
unlike the initial booking flow — then `ctx.refresh()`.

**Direct Payments "add a card."** Mounts Stripe Elements directly with `window.Stripe`, not the
module-level `Portal.directPayments`/`ProcessorClient` machinery — that machinery's own
`create-setup-intent` endpoint wants a raw provider `customer_id` the Dashboard has no business
holding. `ctx.su.setupPaymentMethod(processorRef)` (`SiteUsersPaymentsController::
setupPaymentMethod()`) now also returns `provider`/`publishable_key` alongside the SetupIntent's
`client_secret`, enough to mount Elements without ever constructing a `ProcessorClient`. Two
real, disclosed limits carried over from the backend rather than papered over:

- `su.setupPaymentMethod()` requires an *already-active* payer/processor-customer row
  (connections/07 §7.5) — there is no path to vault a user's very *first* card from a bare
  Dashboard click with no live checkout. `buildAddCardPanelMarkup()` therefore keys the "add a
  card" affordance off an *existing* saved method's own `processor_reference_num`: this is "add
  ANOTHER card to a processor you already have one with," not "connect a new one," and a user
  with zero saved methods sees an explanatory message, not a broken button.
- `ProcessorPaymentMethodService::createSetupIntent()` only ever succeeds for Stripe — Square and
  PayPal explicitly return "Call `POST /me/payment-methods` directly for this provider." A
  non-Stripe processor gets a clear "not supported here yet" message instead of a silently
  hidden control.

**Dedicated tests for the retry pane and deep-link resolution.** `renderDashboard()` exported
(`mount()` is still its only real caller) so `tests/dashboard.test.ts` could reach it directly
with a stubbed `PortalSiteUsersInstance`. Three new tests: a throwing provider degrades to
`[data-su-retry]` without disturbing Profile; clicking retry re-invokes the same provider;
`#module:instance:view/ref` opens the right pane, with the right `ctx.view`/`ctx.ref`, on load
(`window.location.hash` set before `renderDashboard()` runs).

**A real, previously-shipped gap closed in passing:** `GET /me/payment-methods` merges saved
cards across every processor a payer has ever paid on, but the merged rows carried no
`processor_reference_num` — `removePaymentMethod()`/`setDefaultPaymentMethod()` both require
one, so a merged list was unusable for exactly the actions this Dashboard view needs. Fixed by
tagging each returned method with the processor it came from
(`SiteUsersPaymentsController::paymentMethods()`), and the SDK's `DirectPaymentsSavedMethod`/
ambient `PortalDirectPaymentsSavedMethod` types updated to match.

### §8.6 — single source of markup

`ecommerce/ui.ts`'s `renderOrders()` — previously the exact inline-markup gap the plan names —
now calls new exported `buildOrdersListMarkup()`; a new `buildOrderDetailMarkup()` serves the
order-detail pane both the Dashboard and (potentially) a future storefront confirmation page
could share. `events`/`appointments` already exported their list builders before this phase;
`appointments/ui.ts`'s gained the `showActions` parameter noted above. `direct_payments/ui.ts`
is new: `buildCardsMarkup()`, `buildPurchasesMarkup()`.

Builder integration: `core/siteUsersTemplates.ts` now imports all four dashboard-provider
registration modules (`views()` is pure and synchronous — safe to import into the canvas
preview's own bundle, since it's never called with `render()`, which is the only piece that
would reach a live API) and builds two sample connections (one store, one events group) via
`buildDashboardGroups()`, so the canvas preview renders a representative sidebar instead of a
bare Account column. `core/siteUsersChrome.ts` gained the plan's named selectors
(`[data-su-badge]`, `[data-su-table]`, `[data-su-row-actions]`, `[data-su-empty]`, plus
`[data-su-readonly-notice]`/`[data-su-section-error]` the shell's own error/notice markup
needs) inside the existing `[data-siteusers-kind]` scope, styled once for all six designs.

### Second follow-up pass — the two limits the first follow-up pass disclosed

The first follow-up pass shipped "add a card" for the one case it targeted — an existing
Stripe customer adding another card — and explicitly disclosed two gaps: no path for a user's
*first* card with no live checkout, and no Square/PayPal support at all. Both close here.

**A first card, no live checkout required.** `su.setupPaymentMethod()`'s own precondition — an
*already-active* payer/processor-customer row — was the actual blocker, not anything about
Stripe specifically. Two new `PayerService` methods do the resolving:

- `ensureCustomerForProcessor(payerRef, processorRef)` — the same "create a provider customer,
  anonymous or by email" logic `customerFor()` already used, but resolving its processor from a
  bare ref instead of a Direct Payment's own binding (the Dashboard has no Direct Payment in
  view). Returns null on any failure rather than throwing, so the controller can turn it into a
  clean JSON error.
- `processorsFor(directPaymentRefs)` — "which processor(s) could a first card be vaulted
  against," resolved through the SAME binding rule `customerFor()` uses (`BindingRegistry::
  active()`, falling back to the Direct Payment's own column), deduplicated.

`SiteUsersPaymentsController` ties them together: `paymentMethods()` now also returns
`available_processors` (computed off the module's own `direct_payments` connections, active
only) whenever the merged card list is empty; `setupPaymentMethod()` — factored, along with the
two new PayPal endpoints below, into a shared `resolveOrCreatePayerCustomer()` — creates the
customer on demand for a processor one of THIS module's own connections actually names, and
404s for anything else (never against an arbitrary ref a caller might probe with). A new
`payerLinkOrCreate()` mirrors `SiteUsersPublicController::myPayer()`'s own first-call behavior
exactly, so this also works for a visitor who has never called `GET /me/payer` at all —
`setupPaymentMethod()` is now the one write in this controller allowed to lazily create that
link, matching `myPayer()`'s own precedent rather than inventing a second way to mint one.

`direct_payments/ui.ts`'s `buildAddCardPanelMarkup()` and `dashboard.ts`'s `renderCards()` pick
an existing method's own processor when there is one ("add ANOTHER card"), or the first of
`available_processors` when there is nothing saved yet ("add a FIRST card") — the same panel,
the same wiring, just a different source for which processor to key it off of.

**Square.** No server-side "setup intent" exists for Square at all —
`ProcessorPaymentMethodService::createSetupIntent()` only ever succeeds for Stripe by design.
`setupPaymentMethod()` now branches: for `square`, it returns `publishable_key` (the
application id), `location_id` (from `extra_config`, 400 if missing — the same guard
`SquareClient.init()` already enforces) and `mode`, with no provider call at all.
`dashboard.ts`'s `openSquare()` loads the Square Web Payments SDK (mirroring `SquareClient.ts`'s
own `buildSquareSdkUrl()` — sandbox and live are different hosts, not a query param), mounts
`payments.card()`, and on submit calls `card.tokenize()` then `su.addPaymentMethod({source_id})`
— exactly the existing `storeSquare()` path, previously unreachable from the Dashboard because
nothing could create the customer row first.

**PayPal.** The largest and least-verified piece: no reusable client-side vaulting primitive
existed anywhere in this codebase before this pass (`PayPalClient.setupPaymentMethod()` was
itself an unimplemented stub — `"PayPal vaulting is currently handled via the subscription
flow"` — and `store()`'s own PayPal branch says vaulting is "handled via the frontend SDK,"
which had never been built). PayPal's real Vault v3 API is a two-step flow, and this pass
implements both steps for real, against PayPal's documented API shape:

1. `ProcessorPaymentMethodService::createPayPalVaultSetupToken()` — `POST /v3/vault/setup-tokens`
   scoped to the payer's PayPal customer id, returns a `setup_token_id`.
2. The client's `paypal.CardFields()` component (Number/Expiry/CVV rendered into three separate
   containers, per PayPal's own documented shape — not one combined field) collects the card
   against that token via `createVaultSetupToken`, and PayPal's own SDK drives the token to
   `APPROVED` before `cardFields.submit()` resolves.
3. `confirmPayPalVaultSetup()` — `POST /v3/vault/payment-tokens`, exchanging the approved setup
   token for the actual vaulted payment token `fetchPayPal()`'s own existing listing code
   already reads cards back from. Nothing new is written locally — the vault is entirely
   provider-side, same as every other PayPal card already listed there.

Two new routes (`POST /me/payment-methods/paypal/setup-token`, `.../confirm`) carry this, both
under the existing `su-me-pm` throttle bucket (path-substring matched on `/payment-methods`, no
middleware change needed). A small `paypalAccessToken()`/`paypalBase()` pair was factored out of
`fetchPayPal()` (behavior-preserving — same URL, same headers, same order) so three PayPal call
sites don't each re-implement the OAuth2 client-credentials exchange; `deletePayPal()`'s own,
slightly different `$clientId` source (`publishable_key` rather than
`extra_config['paypal_client_id']`) was left exactly as it was — a pre-existing inconsistency
in this codebase, not this pass's to fix.

**Verification limit, disclosed rather than worked around:** none of the three providers' live
SDK behavior was exercised against a real account — the user explicitly chose "code review and
type-check only" for this pass. Confidence is highest for Stripe (extends the already-built,
still-unverified flow from the first follow-up pass) and Square (mirrors `SquareClient.ts`'s
own working, if differently-contexted, implementation almost line for line); it is lowest for
PayPal, where no prior working code in this codebase to mirror existed at all. See "Residue."

## Deviations from the plan text, found while implementing

- **Doc 08 §8.8 contradicts itself on disconnection**, and the contradiction predates this
  phase. One acceptance line reads "disconnecting it removes the group on the next load";
  another, two lines later, reads "A disconnected connection still renders its group, with its
  history, no write controls, no badge, and the server's notice at the top of each pane (13
  §13.10)." [`13-disconnection.md`](../13-disconnection.md) is the later, explicitly-superseding
  rewrite ("This supersedes the earlier disconnect semantics in 05 §5.4…") and is unambiguous:
  disconnection is read-only, not removal. Built the doc-13-consistent behavior — a disconnected
  connection renders, filtered to its read-only capabilities, with the server's notice — and
  treated the first, contradicting §8.8 line as the stale one. `SiteUsersDashboardConnectionsTest::
  test_a_disconnected_connection_is_read_only_with_filtered_capabilities_and_no_badges` proves
  this directly.
- **`shop.purchase(invoiceRef)` is not used**, though §8.5's own table names it for the
  ecommerce provider. `EcommerceOrderService::serializeOrder()` — what both `myOrders()` and
  `order()` already call — resolves every money field (`total_cents`, `refunded_cents`,
  `payment_status`, etc.) from the Direct Payments invoice itself before the SDK response ever
  reaches the client; there is nothing left for a second `purchase()` call to add. Confirmed by
  reading `serializeOrder()`'s own source before writing `ecommerce/dashboard.ts`, not assumed.
- **Test file names differ from `11-rollout-and-testing.md`'s own suggestions** — that document
  names `tests/Feature/SiteUsers/DashboardConnectionsTest.php` (this phase shipped
  `SiteUsersDashboardConnectionsTest.php`, matching every other Site Users test file's own
  `SiteUsers*` prefix convention already in that directory), `resources/js/external/ui/tests/
  dashboard-sections.test.ts` (shipped as `dashboard.test.ts`), and `resources/js/portal/builder/
  tests/site_users.test.ts` *(extended)* for the `buildDashboardGroups()`/`navKey()` tests
  (shipped in `external/ui/tests/dashboard.test.ts` instead, since those functions live in
  `external/ui/site_users/ui.ts`, not anywhere in the builder package — a builder-side test
  would have had to import across packages for no reason). Same coverage, different names/
  locations, flagged rather than renamed to match, consistent with how this session has handled
  every prior instance of a plan naming a test file this way.

## Not implemented

- **Ecommerce `returns`** — `laravel_ecommerce_return_requests` doesn't exist until K9
  ([03](../03-database-schema.md) §3.3), reconfirmed absent from the real schema in both
  follow-up passes. Additive, not blocked — a small follow-up once K9 ships the table.

Everything else either pass disclosed — first-card vaulting, Square, PayPal — is built now
(see "Second follow-up pass," above). Nothing from §8.5's "add a card" row remains unbuilt.

## Files

**New (backend):** `app/SiteUsers/Dashboard/DashboardContribution.php`,
`EcommerceContribution.php`, `EventsContribution.php`, `AppointmentsContribution.php`,
`PaymentsContribution.php`; `tests/Feature/SiteUsers/SiteUsersDashboardConnectionsTest.php`

**Modified (backend):** `app/SiteUsers/SiteUsersDashboardService.php` (`forUser()`,
`filterCapabilities()` added; `SiteUsersConnectionRegistry` injected),
`app/SiteUsers/SiteUsersConsumerRegistry.php` (`resolveLabel()` added),
`app/Models/Externals/SiteUsers/SiteUserConnection.php` (`resolvedLabel()`/`readOnlyNotice()` added),
`app/Http/Controllers/Externals/SiteUsers/SiteUsersPublicController.php` (`connections()` added),
`app/Http/Controllers/Externals/SiteUsers/SiteUsersPaymentsController.php` (`processor_reference_num`
tagged onto each merged payment method; `setupPaymentMethod()` now also returns
`provider`/`publishable_key` on success — the follow-up pass's "add a card" panel),
`app/Http/Controllers/Externals/Events/EventStorefrontController.php`
(`myRegistrations()`/`serializeRegistration()` — `starts_at`/`event_name` added, the follow-up
pass's events upcoming/past split), `routes/portal.php`, `routes/auth1.php` (`GET
/me/connections` added to both), `tests/Unit/SiteUsers/SiteUsersDashboardServiceTest.php`
(constructor call site updated for the new `SiteUsersConnectionRegistry` dependency),
`tests/Feature/SiteUsers/SiteUsersPaymentsControllerTest.php` (setup-payment-method
enrichment tests — a bound `Mockery` mock of `ProcessorPaymentMethodService`, since
`createSetupIntent()` itself talks to Stripe over raw curl with no injectable seam; extended
again in the second follow-up pass for first-card/Square/PayPal, plus its own schema gaining
`laravel_direct_payments`/`laravel_direct_payment_processor_bindings` and a new
`connectDirectPayment()` helper for a REAL per-instance connection, since `processorsFor()` is
the first code in this test file to actually resolve `instance_key` as a DirectPayment
reference rather than treat it as an opaque string)

**Modified (backend, second follow-up pass)** — no new backend files, only additions to
existing ones: `app/Externals/DirectPayments/PayerService.php`
(`ensureCustomerForProcessor()`, `processorsFor()` added), `app/Externals/Processor/
ProcessorPaymentMethodService.php` (`createPayPalVaultSetupToken()`, `confirmPayPalVaultSetup()`
added; `paypalAccessToken()`/`paypalBase()` factored out of, and `fetchPayPal()` refactored to
use — behavior-preserving, `deletePayPal()` deliberately left alone), `app/Http/Controllers/
SiteUsers/SiteUsersPaymentsController.php` (`payerLinkOrCreate()`, `connectedProcessors()`,
`resolveOrCreatePayerCustomer()` added; `paymentMethods()` gained `available_processors`;
`setupPaymentMethod()` rewritten to create-on-demand and branch for Square;
`setupPayPalVault()`/`confirmPayPalVault()` added), `routes/portal.php`, `routes/auth1.php`
(the two new PayPal routes on both prefixes)

**New (frontend):** `external/ui/site_users/sections.ts`,
`external/ui/ecommerce/dashboard.ts`, `external/ui/events/dashboard.ts`,
`external/ui/appointments/dashboard.ts`, `external/ui/direct_payments/` (`ui.ts`,
`dashboard.ts`, `index.ts`), `external/ui/tests/dashboard.test.ts`,
`external/ui/tests/direct_payments.test.ts`

**Modified (frontend):** `external/api/site_users/SiteUsersClient.ts` (`connections()`,
`paymentMethods()`/`addPaymentMethod()`/`setupPaymentMethod()`/`removePaymentMethod()`/
`setDefaultPaymentMethod()`, `purchases()` added; `paymentMethodsFull()`/`setupPayPalVault()`/
`confirmPayPalVault()` added in the second follow-up pass — `paymentMethodsFull()` is additive,
not a changed return shape on the public `paymentMethods()` contract), `external/api/portal.d.ts`
(matching ambient types, plus `PortalDirectPaymentsSavedMethod.processor_reference_num`),
`external/api/direct_payments/api.ts` (`DirectPaymentsSavedMethod.processor_reference_num`),
`external/ui/site_users/ui.ts` (the shell rewrite — `buildDashboardGroups()`, `navKey()`,
`buildReadOnlyNoticeMarkup()`, `buildSectionErrorMarkup()`, `buildDashboardMarkup()`'s new
parameter, `renderDashboard()`'s lazy-render/deep-link/retry/badge-refresh logic;
`DashboardView.navVisible` added in the follow-up pass, `renderDashboard()` exported for tests),
`external/ui/ecommerce/ui.ts` (`buildOrdersListMarkup()`/`buildOrderDetailMarkup()` extracted;
`buildFavoritesMarkup()` extracted in the follow-up pass), `external/ui/ecommerce/dashboard.ts`
(`favorites` view, `order` view's `navVisible: false`, follow-up pass),
`external/ui/events/ui.ts` (`buildMyRegistrationsMarkup()`'s `bare` parameter,
`buildTicketDetailMarkup()`, `splitRegistrationsByDate()`, follow-up pass),
`external/ui/events/dashboard.ts` (rewritten for `upcoming`/`past`/`ticket`, follow-up pass),
`external/ui/appointments/ui.ts` (`buildMyBookingsMarkup()`'s `showActions` parameter; a
Reschedule button and slot-picker host added in the follow-up pass),
`external/ui/appointments/dashboard.ts` (reschedule wiring, follow-up pass),
`external/ui/direct_payments/ui.ts` (`buildAddCardPanelMarkup()`, follow-up pass; extended in
the second follow-up pass for Square's single container and PayPal's three split-field
containers), `external/ui/direct_payments/dashboard.ts` (the Stripe Elements mount/confirm/
finalize flow, follow-up pass; the second follow-up pass added `openSquare()`/`openPayPal()`
alongside a renamed `openStripe()`, a shared `loadScript()`, and switched `renderCards()` from
`paymentMethods()` to the new `paymentMethodsFull()` for `available_processors`),
`external/ui/tests/direct_payments.test.ts` (extended for Square/PayPal's markup branches),
`external/ui/ecommerce/index.ts`, `external/ui/events/index.ts`,
`external/ui/appointments/index.ts` (provider registration added to each),
`external/ui/main.ts` (`./direct_payments` imported explicitly),
`portal/builder/core/siteUsersTemplates.ts` (sample connections, provider imports),
`portal/builder/core/siteUsersChrome.ts` (new selectors)

## Verification

```bash
cd w/www
php artisan test tests/Feature/SiteUsers/SiteUsersDashboardConnectionsTest.php tests/Unit/SiteUsers/SiteUsersDashboardServiceTest.php
php artisan test tests/Feature/SiteUsers/ tests/Feature/Connections/ tests/Feature/DirectPayments/ tests/Feature/Ecommerce/ tests/Feature/Events/ tests/Feature/Appointments/  # 292 passed
npx tsc --noEmit -p tsconfig.json                                      # exit 0 (root — builder integration included)
npx tsc --noEmit -p resources/js/external/api/tsconfig.json            # exit 0
npx tsc --noEmit -p resources/js/external/ui/tsconfig.json             # exit 0
npm run build                                                          # exit 0 (root vite build)
npm run build-external-webpack                                        # exit 0
npm run build-external-ui-webpack                                     # exit 0
npm run test:external-ui                                               # 66 passed (48 before doc 08's first pass, 59 after the first follow-up, 66 after the second)
```

## Residue

- **No live browser or sandbox-account verification, for any of the three "add a card"
  providers** — unlike several earlier phases in this rollout (K4, K5), none of this doc's three
  passes were driven through a real published dashboard page in a browser, and the second
  follow-up pass's own scope was explicitly agreed with the user as "code review and type-check
  only" given no live Stripe/Square/PayPal account is reachable from here. Confidence differs by
  provider:
  - **Stripe** — extends the first follow-up pass's own already-built, still-unverified flow;
    `stripe.confirmSetup()`'s return shape is asserted from Stripe's documented API, not observed.
  - **Square** — mirrors this codebase's own working `SquareClient.ts` (SDK URL selection,
    `payments.card()`, `card.tokenize()`) almost line for line, so the biggest remaining risk is
    the NEW piece: `setupPaymentMethod()`'s customer-creation-on-demand path, not the SDK mount
    itself.
  - **PayPal** — the least confident of the three. No prior working vaulting code existed
    anywhere in this codebase to mirror (`PayPalClient.setupPaymentMethod()` was an
    unimplemented stub); `paypal.CardFields()`'s exact method names
    (`NumberField()`/`ExpiryField()`/`CVVField()`/`createVaultSetupToken`/`submit()`) and the
    `POST /v3/vault/setup-tokens` / `.../payment-tokens` request/response shapes are built from
    PayPal's documented Vault API, not verified against a live sandbox. **A human should walk
    this one through PayPal's sandbox before it ships to any real tenant.**
  Recommended before any of this ships to a real tenant: connect a test module to ecommerce +
  events + one processor per provider (Stripe, Square, PayPal), sign in as a brand-new user with
  no saved methods, add a first card for each, reschedule a booking, and confirm a deep link
  into an order/ticket detail works.
- **`PaymentsContribution` and the Payments group carry no "needs your attention" signal at
  all**, by design (the plan's own words) — flagged here only so a future reviewer doesn't read
  the empty badge as an oversight.
- **The badge-refresh fix re-fetches the entire `/me/connections` payload** (all modules' badge
  counts) rather than just the one group whose action changed — simpler and still cheap (one
  indexed query per module, §8.3's own standard), but not the narrowest possible
  implementation. Not worth the added complexity of threading a per-module badge-only endpoint
  for what is, at most, a handful of extra counted rows per refresh.
- **`deletePayPal()`'s `$clientId` source was left inconsistent with the rest of this pass's own
  PayPal code** (`$processor->publishable_key` there, vs. `extra_config['paypal_client_id']`
  everywhere this pass added) — a pre-existing inconsistency in this codebase, deliberately not
  touched, since fixing it is unrelated to "add a card" and risks a behavior change to an
  already-shipped, untested-by-this-session delete path.
