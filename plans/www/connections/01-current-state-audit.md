# 01 — Current State Audit

Read from `w/www` on 2026-09-12. Every path is relative to `w/www/`. Line numbers are as of
that read and will drift; the symbol names will not.

## 1.1 What exists — the foundation this plan stands on

### The consumer registry and its readers

`config/site_users_consumers.php` declares five consumers. Three carry a `mode_source` naming
the model attribute the per-instance mode lives on:

```php
'ecommerce'    => ['label' => 'Ecommerce',    'user_column' => 'site_user_id', 'supports' => ['guest', 'required'],
                   'mode_source' => [\App\Models\Externals\EcommerceStore::class, 'site_users_mode']],
'events'       => [... 'mode_source' => [\App\Models\Externals\Event::class, 'site_users_mode']],
'appointments' => [... 'mode_source' => [\App\Models\Externals\Appointment::class, 'site_users_mode']],
'forms'        => [... 'supports' => ['guest'],    'mode_source' => null],
'discussions'  => [... 'supports' => ['required'], 'mode_source' => null],
```

`app/SiteUsers/SiteUsersConsumerRegistry.php` is the single resolver:

| Method | Behaviour today |
|---|---|
| `modeFor($appKey, ?object $instance)` | Reads `$instance->{$attribute}`; coerces unknown to `off`; `required` unconditionally for discussions |
| `assertAuthenticated($appKey, $instance, Request)` | `null` unless mode is `required` **and** `gateway->currentUserId($request) === null`; then `['message' => 'Sign in to continue.', 'code' => 'SU_E_AUTH_REQUIRED']` |
| `supportedModes($appKey)` | `['off', ...supports]` |
| `currentUserId(Request)` | delegates to the gateway |

Its three callers, all of which this plan keeps calling it:

| Module | Call site | Gate |
|---|---|---|
| ecommerce | `app/Externals/Ecommerce/Support/EcommerceShopperAuth::assertCheckoutAllowed(EcommerceStore, Request)` → `EcommerceCartController::beginCheckout()` | `401 EC_E_AUTH_REQUIRED` |
| events | `app/Externals/Events/Services/EventRegistrationService::register()` (~line 112) | `422` with `SU_E_AUTH_REQUIRED` in the error body |
| appointments | `app/Externals/Appointments/Services/AppointmentBookingService::book()` (~line 73) | `422` with `SU_E_AUTH_REQUIRED` (`status` from result) |

`app/SiteUsers/SiteUsersGateway::currentUser(Request)` returns the `site_user` request
attribute when `SiteUsersApiGuard` set it, else falls back to
`SiteUsersSessionService::resolveFromRequest()` — **any valid session from any module**.

### The columns

| Table | Columns | Migration |
|---|---|---|
| `laravel_ecommerce_stores` | `site_users_mode` (default `off`), `site_users_id` nullable | `2026_04_03_133338_create_laravel_ecommerce_tables.php:27-28` |
| `laravel_events` | `site_users_mode` only | `2026_08_31_200000_create_events_module_tables.php:59` |
| `laravel_appointments` | `site_users_mode` only | `2026_08_31_230000_create_appointments_extended_schema.php:26` |
| `laravel_ecommerce_orders`, `laravel_ecommerce_carts`, `laravel_event_orders`, `laravel_event_registrations`, `laravel_appointment_bookings`, forms tables, promo redemptions, gift cards, pass redemptions | `site_user_id` nullable, indexed | various |
| `laravel_site_user_modules` | `link_payment_customers` default `false` | `2026_08_31_180000_create_site_users_schema.php` |
| `laravel_site_user_customer_links` | `(site_users_id, user_id, processor_reference_num, provider, provider_customer_id, linked_by, linked_at)`, unique on `(processor_reference_num, provider_customer_id)` | same |
| `laravel_builder_routes` | `subpaths, requires_auth, is_public, site_users_id, require_verified_email, unauthenticated_behaviour, login_route, verify_route` | same |
| `laravel_api_guards` | `path_pattern, methods, requires_auth, site_users_id, require_verified_email, is_active` | same |
| `laravel_builder_module_refs` | `(project_reference_num, subpaths, module_id, instance_ref, module_version)` — which published page names which instance | `2026_09_01_000002_create_builder_module_refs_table.php` |

### The admin

- `EcommerceController::updateStore()` (~line 378) writes `site_users_mode` from the request
  and **never** `site_users_id`. `createStore()` likewise. `getStores()` echoes
  `site_users_mode`.
- `resources/js/portal/admin/html/views/commerce-store.html:60` — `<select id="storeSiteUsersModeSelect">`
  with the three modes; `commerce-store.ts` reads/writes it. **No module select** — the
  worked example in [ecommerce/01](../ecommerce/01-site-users-guard.md) §1.5.3 was half-built.
- `EventController::update()` (~line 330) and `AppointmentsController::update()` (~line 505)
  accept `site_users_mode` from the request; **neither admin form renders a control for it**
  (`grep site_users` in `controllers/events/events.ts` and `controllers/appointments/appointments.ts`
  is empty).
- `resources/js/portal/admin/controllers/site_users/site-users.ts` — three views. The module
  detail view (`/site-users/detail`) has tabs **Settings / SSO / Users**
  (`html/views/site-users-detail.html:21-23`). The Settings tab carries
  `#su-set-link-payment-customers`. `loadSettings()` only reads `name`/`is_active` because
  `GET /status` returns only those (`SiteUsersAdminController::status()`).
- `controllers/modules/modulesIndex.ts` — the Modules index; `MODULE_HOME_VIEW` /
  `MODULE_HOME_ROUTE` map module ids to admin views; cards show "Used on N published pages".

### The Site Users API (routes/portal.php)

Public, `/portal/api/siteusers/public/*`, middleware `site_users.origin` +
`site_users.public.throttle` (line 194): `config`, `register`, `login`, `password/forgot`,
`password/reset`, `email/verify`, `email/resend`, `sso/{provider}/start|callback`. Under
`site_users.session` (line 207): `logout`, `logout-all`, `me` (GET/PATCH), `me/password`,
**`me/customers` (POST)**, `me/sessions` (GET), `me/sessions/{id}` (DELETE).

Admin, `/portal/api/siteusers/*`, `ApiAuthMiddleware` (line 267): `status`, `activate`,
`modules/{ref}` (PATCH), `modules/{ref}/sso`, `modules/{ref}/users…`, `routes`,
`api-guards`.

`SiteUsersOriginMiddleware` resolves the module from `?module=` / `module` / `site_users_id` in
the body and **passes through when none is given** — every `/me/*` call from the SDK today
carries no module and is therefore not origin-checked. The session is the credential there;
this is noted, not changed, by this plan.

`SiteUsersPublicController::linkCustomer()` — session-resolved user; refuses with
`{linked:false, skipped:'link_payment_customers_off'}` when the module flag is off; `409` when
the customer is linked to another user; idempotent otherwise.

### The storefront APIs

All under `Route::middleware(['portal.cors', 'site_users.api.guard'])` (line 102) — i.e. no
auth unless a guard row matches — plus each module's public throttle.

| Module | Prefix | Relevant endpoints |
|---|---|---|
| ecommerce | `/portal/api/ecommerce/storefront` | `GET /store` (returns `site_users_mode`, **no module id**), cart CRUD, `POST /checkout/begin` (gated), `POST /checkout/attach`, `POST /checkout/complete` (**not** gated), `GET /orders` (401 without session), `GET /orders/{ref}` (owner-or-guest by reference) |
| events | `/portal/api/events/storefront` | `GET /events`, `GET /events/{ref}` (no site-users fields), `POST /hold` (**not** gated), `POST /register` (gated), `POST /waitlist` (**not** gated), `GET /registrations/mine` (empty array for guests), `GET /registrations/{ref}` (token-or-owner), `POST /registrations/{ref}/cancel` (token-or-owner; no window check; releases capacity), `.../calendar.ics`, `.../confirm-payment` |
| appointments | `/portal/api/appointments/storefront` | `GET /appointments/{ref}` (no site-users fields), `POST /hold` (**not** gated), `POST /book` (gated), `POST /waitlist` (**not** gated), `GET /bookings/mine` (empty for guests), `GET /bookings/{ref}`, `POST .../reschedule`, `POST .../cancel` (token-or-owner; `AppointmentBookingService::cancel()` does **not** consult `policy_snapshot`), `.../calendar.ics`, `.../confirm-payment` |

### The processor and Direct Payments APIs

`Route::prefix('api/v1/processors')` (line 697) and `Route::prefix('api/v1/directpayments')`
(line 983) sit **inside the same `portal.cors` + fail-open guard group** — unauthenticated.

Relevant to this plan:

| Endpoint | Controller | Notes |
|---|---|---|
| `POST /customers/create-anonymous` | `ProcessorCustomerController::createAnonymous` | Called by the DP SDK on every checkout mount; persists a `ProcessorCustomer` with `email = ''` |
| `POST /customers/find-or-create` | `::findOrCreate` | By email; idempotent |
| `POST /customers/update-email` | `::updateEmail` | Stamps the email after payment |
| `GET /customers` | `::index` | **Lists every customer with email**, paginated, unauthenticated (**X2**) |
| `DELETE /billing/payment-methods`, `POST /billing/payment-methods/store`, `POST /billing/payment-methods/default` | `ProcessorBillingController` | `resolveProcessorAndCustomer()` tries **`ProcessorCustomer::find($customerId)` — a local integer id — before falling back to `provider_customer_id`** (**X1**) |
| `GET /billing/payments?processor_reference_num&customer_id` | *(none)* | Called by `processors/api.ts::getPaymentMethods()`. **The route is not registered and `ProcessorBillingController` has no `payments()` method** — confirmed by grep on 2026-09-12. The SDK's saved-card list is dead code today (**X3**) |
| `GET /directpayments/invoices?user_id=` | `DirectPaymentsController::getInvoices` | Lists any user's invoices by a client-supplied `user_id` (**X4**) |
| `GET /directpayments/invoice/{ref}` | `::getInvoice` | By high-entropy reference; deliberately public for guest receipts |
| `POST /directpayments/invoice` | `::saveInvoice` | Accepts `customer_id` (provider id string) and `user_id` (free string) |

`App\Externals\DirectPayments\Contracts\PaymentGateway` (`isPaid`, `snapshot`, `snapshots`,
`paidInvoicesAwaiting`) and `InvoiceSnapshot` are the server-side read door every façade
already uses. `EcommerceOrderService::serializeOrder()` resolves all money through it.

### The externals SDK (`resources/js/external/api/`)

- `site_users/SiteUsersClient.ts` — `init/config/me/updateProfile/changePassword/sessions/
  revokeSession/register/login/logout/logoutAll/requestPasswordReset/resetPassword/verifyEmail/
  resendVerification/sso/on`. Cookie token storage by default; `authedJson()` sends
  `credentials: 'include'`. **No `connections()`, no payments helpers.**
- `direct_payments/api.ts` (2,495 lines) — `_createAnonymousCustomer(processorRef)` stores the
  provider customer id in `sessionStorage` under `afs_customer_id_<dpRef>`; called from
  `setupSubscriptionPayment`, `setupSinglePayPayment` and `addPaymentMethod`.
  `getSavedPaymentMethods` / `addPaymentMethod` / `deletePaymentMethod` /
  `setDefaultPaymentMethod` all key on that session customer and call the unauthenticated
  processor endpoints. `saveInvoice()` forwards `customer_id` and `user_id`. **No hook for a
  caller to supply a customer.**
- `commerce/api.ts` — `beginCheckout`, `attachCheckout`, `completeCheckout`, `purchase(invoiceRef)`
  (the client-side DP read), `myOrders`, `order`. Checkout payment is the storefront's
  concern via `Portal.directPayments` directly.
- `events/api.ts` — `beginPayment()` (loads the prepared invoice, `setupSinglePayPayment`,
  returns `{confirm}`), `myRegistrations`, `cancelRegistration`, `calendarUrl`.
- `appointments/api.ts` — `beginPayment()`, `myBookings`, `cancelBooking`, `rescheduleBooking`.
- `portal.d.ts` — `PortalSiteUsersInstance` (line ~1718), `PortalUiNamespace` (~1305) with
  `ecommerce/events/appointments/siteUsers: PortalUiModuleAPI`, `PortalCommerceInstance` (~1238).

### The externals UI (`resources/js/external/ui/`)

- `site_users/ui.ts` — `buildDashboardMarkup(user)` renders a sidebar shell with a fixed
  `DASH_SECTIONS = ['profile', 'security', 'sessions']`; `renderDashboard()` wires them.
  All markup builders are exported pure functions, imported by the builder's
  `core/siteUsersTemplates.ts` for the canvas preview ([builder/v2/09](../builder/v2/09-single-source-ui.md)).
- `ecommerce/ui.ts` — `renderCheckout()` mounts email + card + "Begin checkout" + "Pay";
  `renderOrders()` lists `myOrders()`; **no sign-in handling anywhere** — a `401` surfaces as
  "Checkout failed".
- `events/ui.ts` — `renderMyRegistrations()`: catches the fetch error and shows
  `buildMyRegistrationsSignInMarkup()` (a notice, no form); cancel button per row.
- `appointments/ui.ts` — `renderMyBookings()`: "You have no bookings." for guests; no cancel
  or reschedule controls in the list.
- `shared/{dom,format,nav}.ts` — `esc`, `waitFor`, `injectStyleOnce`, `spinnerStyle`,
  `fmtMoney`, `fmtDate`, `siblingHref`, `paintFooter`, `onReady`, `mountAll`.
- `main.ts` — attaches all four `*Ui` objects to `window.Portal.ui` and calls each `install()`.

### The builder

`core/modules/site_users.module.ts` — `surface: 'page'`, subpages
`login/register/dashboard/forgot-password/reset-password`. Publishing a Dashboard subpage
writes a `laravel_builder_routes` row with `requires_auth`, `site_users_id` and
**`login_route` = the sibling Login page's slug** ([builder/v2/08](../builder/v2/complete/08-site-users-pages.complete.md) §8.8).
That row is the one reliable record of "where is this module's sign-in page".

Ecommerce, events and appointments page modules already declare account-ish subpages —
`orders`, `my-registrations`, `my-bookings` — with `authDefaultForSubpage()` → `'page'`.

## 1.2 The gaps — X1 to X17

Findings are numbered **X** (for *cross-module*). **X1–X4 are security findings that exist
with or without this plan.**

| # | Finding | Where | Consequence |
|---|---|---|---|
| **X1** | Payment-method delete / store / set-default endpoints are unauthenticated and resolve the customer by **local integer id first** | `ProcessorBillingController::resolveProcessorAndCustomer()`; routes line 697 group | Anyone can iterate `customer_id=1,2,3…` and detach every saved card on the deployment. **Prerequisite, not dependency** — [11](11-rollout-and-testing.md) K0 |
| **X2** | `GET /portal/api/v1/processors/customers` lists all customers (emails, names) unauthenticated | `ProcessorCustomerController::index()` | PII leak. Fix is `ApiAuthMiddleware` on that one route (it is documented as admin use) — K0 |
| **X3** | The SDK's `getPaymentMethods()` calls `GET /billing/payments`, a route that does not exist and a controller method that does not exist | `external/api/processors/api.ts:162` | Saved-card listing is **dead** in the shipped SDK — it throws `Failed to fetch payment methods` on every call. So there is no existing saved-card surface to preserve, and [07](07-direct-payments-connection.md) §7.5's `/me/payment-methods` is the first working one |
| **X4** | `GET /directpayments/invoices?user_id=` returns any `user_id`'s invoices | `DirectPaymentsController::getInvoices()` | `user_id` is a free string; guessable for members. Not used by any tenant surface this plan builds — the purchases view is session-scoped ([07](07-direct-payments-connection.md) §7.6) — but flagged for billing's owner |
| **X5** | **No module isolation.** `modeFor()` never reads `site_users_id`; `assertAuthenticated()` accepts any module's session; attribution writes whichever `site_user_id` the session carries | `SiteUsersConsumerRegistry`, `SiteUsersGateway` | On a deployment with two Site Users modules, a user of module B is attributed on — and passes `required` for — a store meant for module A |
| **X6** | Events and appointments have **no `site_users_id`** at all; stores have one nothing writes | migrations above; `EcommerceController::updateStore()` | "Which module" is unanswerable for two of three consumers, and unanswered for the third |
| **X7** | Three write surfaces for one concept: the store form select, two admin controllers accepting `site_users_mode` with no UI, and nothing for the module id | admin controllers and views | A tenant cannot see the state of their own configuration in one place |
| **X8** | `completeCheckout()` is not gated; `hold` and `waitlist` (events and appointments) are not gated | `EcommerceCartController::completeCheckout()`, `EventStorefrontController::hold()/joinWaitlist()`, `AppointmentStorefrontController::hold()/joinWaitlist()` | In `required` mode an anonymous client can hold capacity and join waitlists; a cart begun by a signed-in user can be completed by anyone holding the cart reference |
| **X9** | Public instance config exposes a **mode** and no **module** | `EcommerceStorefrontController::getStore()`; events/appointments expose nothing | The storefront cannot construct `Portal.siteUsers.create(<module>)`, so it cannot offer sign-in at checkout |
| **X10** | No storefront UI handles `SU_E_AUTH_REQUIRED` / `EC_E_AUTH_REQUIRED` | `external/ui/{ecommerce,events,appointments}/ui.ts` | `required` mode is enforceable and unusable: the button says "Checkout failed" |
| **X11** | The Dashboard is fixed to Profile / Security / Sessions | `external/ui/site_users/ui.ts` `DASH_SECTIONS` | No orders, tickets, bookings or cards reach the user's account page |
| **X12** | The customer link has a write endpoint and **no read side**: `_createAnonymousCustomer()` still runs for signed-in users; nothing consults `laravel_site_user_customer_links` at checkout | `direct_payments/api.ts`; [site_users/08 complete](../site_users/complete/08-direct-payments-link.complete.md) "Deferred #1" | A second purchase mints a second provider customer; saved cards do not follow the account |
| **X13** | `link_payment_customers` is a checkbox with no relationship to anything a tenant would call "connecting payments" | Settings tab | A tenant has to know the checkbox exists, and it agrees with nothing else |
| **X14** | Ecommerce has no shopper-side cancel and no returns model | routes; `EcommerceController::cancelOrder()` is admin-only | "Returns and other things a single user can manage" has nothing to manage |
| **X15** | `cancelBooking()` ignores `policy_snapshot`; `cancelRegistration()` has no window | services above | Self-service cancel in a dashboard would let a customer cancel one minute before a paid slot with no policy applied |
| **X16** | `myRegistrations()` and `myBookings()` return **empty arrays** to guests rather than `401` | storefront controllers | The UI cannot distinguish "signed out" from "no history"; events' UI guesses by catching a throw that never comes |
| **X17** | Storefront registration/booking responses expose `access_token` to a signed-in owner who does not need it; the dashboard must never depend on it | `register()`, `book()` | Not a leak (it is the caller's own), but the dashboard's owner path must use the session, or a token copied into a URL becomes a second credential |

### Found in the plan review pass (2026-09-12) — X18 to X26

These were not in the first audit. Each was found by re-reading the plan against the code and
asking "what breaks when this is built"; each changes a document downstream.

| # | Finding | Where | Consequence |
|---|---|---|---|
| **X18** | **`SiteUsersPublicThrottle` has no bucket matching `/me/*`.** Its `if/elseif` chain covers login, register, forgot, resend, verify/reset and SSO. Every `/me/*` path falls through with an empty `$buckets` array — **no limit at all** | `app/Http/Middleware/SiteUsersPublicThrottle.php` | Tolerable today (`/me` is a cheap DB read). **Not** tolerable once `/me/payment-methods` fans out to Stripe/Square/PayPal once per linked customer: an authenticated user becomes an amplifier against the tenant's provider rate limits. [07](07-direct-payments-connection.md) §7.10 |
| **X19** | **`su_session` is `SameSite=Lax`, `secure=false`, httpOnly, path `/`** | `SiteUsersPublicController::authResponse()` | Lax is not sent on cross-site XHR, so **cookie token storage cannot work on a site the tenant does not serve from this deployment** — the standalone promise ([site_users/07](../site_users/07-externals-api.md) §7.1) holds for the *API* but not for the *session* in its default mode. Raising to `SameSite=None` is not a one-line change: `routes/portal.php` carries no CSRF middleware at all, and Lax is currently the only thing preventing cross-site POST/DELETE with the session cookie. [06](06-checkout-guard.md) §6.9 |
| **X20** | **`SiteUsersAdminController::deleteUser()` hard-deletes the user row**; nothing nulls or reassigns `site_user_id` on orders, registrations, bookings, carts, promo redemptions or customer links | that method | Attribution dangles. After this plan those rows are invisible to every dashboard (no user) but still counted by the admin and by `/me/purchases`-adjacent queries for nobody. There is also no user-facing "delete my account". [03](03-database-schema.md) §3.7 |
| **X21** | **A site user cannot change their email.** `updateMe()` validates `display_name` and `username` only | `SiteUsersPublicController::updateMe()` | Pre-existing and reasonable (an email change needs re-verification). It becomes load-bearing here: the provider customer created at first checkout is created *with the registration email* and keeps it, so receipts and provider-side support keep using an address the user may no longer read. [07](07-direct-payments-connection.md) §7.11 |
| **X22** | **`GET /storefront/orders` requires `?store=`** (`requireStore()` → `422 EC_E_STORE_REQUIRED`) | `EcommerceShopperOrdersController::index()`, `EcommerceCartController::requireStore()` | A **module-wide** ecommerce connection (`instance_key = '*'`) would produce a dashboard group with no store to query. Events and appointments have no equivalent problem — their `mine` endpoints are global. [04](04-connection-registry-and-enforcement.md) §4.9, [08](08-dashboard-sections.md) §8.3 |
| **X23** | **`laravel_direct_payment_invoices.user_id` is a free string set by the client** (`saveInvoice()` validates `nullable\|string`) | `DirectPaymentsController::saveInvoice()` | [07](07-direct-payments-connection.md) §7.6's original second clause matched on it, so a caller could stamp `su:<victim reference_num>` and have their own purchase appear in the victim's list. The link table is the only trustworthy join. Fixed in §7.6 |
| **X24** | **There is no path from a guest order to an account.** Nothing matches `orders.customer_email` against `users.email`, and the §8.5 reconciler covers *processor customers*, not façade records | `EcommerceOrderService`, `SiteUsersLinkCustomers` | The most common real journey — buy as a guest, create an account afterwards — produces an empty Orders pane. [09](09-module-self-service.md) §9.7 |
| **X25** | **Two stores on one site share one cart cookie, and the second destroys the first cart.** `EcommerceCartCookie::NAME` is one constant for the deployment, `carts.cart_token` is globally unique, and `getOrCreate()` discards a token that exists but does not match the store, mints a new one, and overwrites the cookie | `EcommerceCartCookie`, `EcommerceCartService::getOrCreate()` | A guest browsing store A then store B loses store A's cart — it survives in the database and the browser can no longer address it. This plan actively encourages the configuration that triggers it (K2's retail-plus-wholesale case). [12](12-guest-continuity.md) §12.2 |
| **X26** | **The guest's route back is generated and never delivered.** Both confirmation services are `Log::info` stubs that omit the token; ecommerce has no mail call at all; the events UI holds the token in a variable that dies on navigation | `EventCommunicationService`, `AppointmentCommunicationService`, `app/Externals/Ecommerce/`, `external/ui/events/ui.ts` | A guest who closes the tab cannot reach their registration or booking at all, and reaches an order only if they still have the URL. Every self-service action in [09](09-module-self-service.md) is reachable by an account holder and nobody else. [12](12-guest-continuity.md) §12.3 |

### Found in the second review pass (2026-09-12) — X27 to X34

| # | Finding | Where | Consequence |
|---|---|---|---|
| **X27** | **Neither protection layer enforces its own `site_users_id`.** `SiteUsersApiGuard::handle()` resolves the session with `resolveFromRequest()` and checks `requires_auth` and `require_verified_email` — it never compares `$guard->site_users_id` to the user's module. `BuilderPublishedController::show()` does the same: `$route->requires_auth` is checked, `$route->site_users_id` is not | `app/Http/Middleware/SiteUsersApiGuard.php`, `app/Http/Controllers/BuilderPublishedController.php` | **X5's hole, in the two places that actually guard things.** A members-only page and a members-only storefront API both admit an account on *any* Site Users module on the deployment. Moot on a one-module tenant; this plan is what makes two modules easy to create. [04](04-connection-registry-and-enforcement.md) §4.11 |
| **X28** | **The appointments waitlist never attributes.** `laravel_appointment_waitlist.site_user_id` exists and is nullable; `AppointmentStorefrontController::joinWaitlist()` passes `$request->all()` to the service and no user id. Events does it correctly — its controller passes `currentUserId()` | that controller | A signed-in customer's waitlist entry is anonymous, so the offer that follows a cancellation reaches them by email and never appears in their dashboard. [09](09-module-self-service.md) §9.4 |
| **X29** | **The derived-column sync destroys the evidence the "needs attention" banner reads.** `syncDerivedColumns()` writes `off` to every instance with no connection row, and the banner detects exactly "legacy column non-`off` **and** no row" | [03](03-database-schema.md) §3.5 vs [05](05-admin-connections.md) §5.4 — a defect in this plan, not the code | After K2's first sync the banner is permanently empty and a tenant never learns which instances silently lost `required`. Fixed by snapshotting the legacy mode in K1, before anything writes to it. [03](03-database-schema.md) §3.4 |
| **X30** | **The derived sync runs inside the write transaction.** `upsert()` wraps `$this->derived->sync()` in `DB::transaction`, and a module-wide change chunks an `update` over every instance of that module | [04](04-connection-registry-and-enforcement.md) §4.7 — this plan | Row locks on the whole stores/events table for the length of an admin request. Harmless at forty events, a lock convoy at forty thousand. Move it after commit |
| **X31** | **None of these services are bound in the container.** `app/Providers/AppServiceProvider.php` contains no `bind`, `singleton` or `scoped` call, so Laravel constructs a fresh instance at every injection point | that provider | [04](04-connection-registry-and-enforcement.md) §4.2's "request-scoped cache" would never hit — each caller gets its own empty one. And binding only the connection registry as `scoped` while the consumer registry stays transient (or becomes a singleton) pins a stale cache in Octane and queue workers. [04](04-connection-registry-and-enforcement.md) §4.2.1 |
| **X32** | **`instance.scope` and `dashboard.scope` meant two different things** in one registry array — a site-ownership column name, and instance-versus-global dashboard grouping | [04](04-connection-registry-and-enforcement.md) §4.1 — this plan | Renamed `instance.site_column`. Caught in review; would have been caught in code review, at the cost of a rewrite |
| **X33** | **Forms attribution is declared and never written.** `FormSubmission` lists `site_user_id` in `$fillable` and no code assigns it | `app/Models/Externals/FormSubmission.php` | [04](04-connection-registry-and-enforcement.md) §4.1's table claimed forms attributes today "via the API-guard attribute". It does not. Corrected there; forms stays `connectable: false` and gains no dashboard group |
| **X34** | **The caller-migration list omitted the waitlist paths.** `EventStorefrontController::joinWaitlist()` calls `currentUserId()` and is not in [04](04-connection-registry-and-enforcement.md) §4.2's table | that table — this plan | One un-migrated caller keeps the any-module behaviour after K3, which is the bug K6 exists to close. Added |

### Found in the third review pass (2026-09-12) — X35 to X41

Every one of these is an interaction between documents written separately, which is where the
last two passes' findings also came from. **Seven of the eight are defects in this plan**, not
in the code.

| # | Finding | Where | Consequence |
|---|---|---|---|
| **X35** | **`paymentsLinked()` reads *visible* rows, so a disconnected Direct Payments connection still authorises writes.** [13](13-disconnection.md) renamed `forModule()` to `forDashboard()`, which now includes `disconnected`, and `paymentsLinked()` was carried along with it | [04](04-connection-registry-and-enforcement.md) §4.2 | A disconnected payments connection would still let `linkCustomer()` write links, still let the reconciler run, and still let a card be added — contradicting [13](13-disconnection.md) §13.5 and §13.6 in the same release that introduced them. Split into `paymentsLinked()` (active, governs writes) and `paymentsVisible()` (visible, governs the card list and purchases) — §4.2 |
| **X36** | **The guest-claim service iterates the wrong set, and by a method that no longer exists.** [09](09-module-self-service.md) §9.7 still reads `forModule($user->site_users_id)` | [09](09-module-self-service.md) §9.7 | Two bugs in one line: a dangling name after [13](13-disconnection.md)'s rename, and — once renamed — claiming would write attribution into a **disconnected** connection, which is a write into a read-only module. It must iterate active connections only |
| **X37** | **An email change silently re-fires the guest claim.** [`../site_users/13`](../site_users/13-identity-and-email-change.md) §13.4.2 sets `email_verified_at` at confirm; [09](09-module-self-service.md) §9.7 fires "when a user's email becomes verified" | the seam between the two | Any naive hook on that column claims guest records for the *new* address, with nothing in either document saying so. The behaviour is defensible — verification is the proof, exactly as at registration — but it must be **stated and deliberate**, not emergent. [09](09-module-self-service.md) §9.7 |
| **X38** | **The email-change rate limit can never fire.** [07](07-direct-payments-connection.md) §7.9 adds `elseif (str_starts_with($path, '…/public/me'))` with a 240-per-5-minutes bucket; [`../site_users/13`](../site_users/13-identity-and-email-change.md) §13.4.2 specifies 3 per hour for `su-emailchange-*`. `/me/email` matches the generic branch first, and in an `if/elseif` chain the first match wins | [07](07-direct-payments-connection.md) §7.9 | The tight bucket is dead code. An attacker on a borrowed session could hammer change requests at 240 per five minutes, mailing tokens to addresses of their choosing. Branch order is now specified — §7.9 |
| **X39** | **The customer-link index still names the bigint.** `su_customer_links_user_idx` is `(site_users_id, user_id)`; [`../site_users/13`](../site_users/13-identity-and-email-change.md) §13.3 converts the column and never mentions the index | that section | The lookup the payments surface runs on every card list would fall off its index. Renamed to `(site_users_id, site_user_reference)` |
| **X40** | **`readOnlyNotice()` is called and never defined.** [13](13-disconnection.md) §13.5 and [09](09-module-self-service.md) §9.2 both call it on the model | [13](13-disconnection.md) §13.2 | An invented method with no definition is the kind of thing that gets implemented four different ways. Defined on `SiteUserConnection` — §13.2 |
| **X41** | **A guest's emailed link still offers actions on a disconnected instance.** [12](12-guest-continuity.md) §12.6's `?t=` view wires cancel and reschedule from the token path, which [13](13-disconnection.md) §13.5 refuses with `409` | [12](12-guest-continuity.md) §12.6 | The one surface where the read-only rule was not applied, because the two documents were written in the wrong order. A guest clicks Cancel and gets an error instead of an explanation |

### Found in the fourth review pass (2026-09-12) — X42

| # | Finding | Where | Consequence |
|---|---|---|---|
| **X42** | **[07](07-direct-payments-connection.md) still implemented the pre-payer model wholesale.** [`../direct_payments/`](../direct_payments/README.md) landed *after* this document, re-pointed two of its headers with a short note each, and left roughly 500 lines of code beneath them — the resolver interface, `SiteUsersPaymentsController`, the email-change loop, the acceptance list — implementing raw `provider_customer_id`, `adopt()` and `SiteUserCustomerLink` exactly as before | that document, essentially end to end | A reader who skimmed the two pointer notes and started implementing from the code below them would build the interface this plan explicitly retired. Fixed by rewriting the document's body — not just its headers — against the payer model throughout, and by catching two smaller errors the rewrite surfaced: an invented cross-reference to an `email_match` rule that [`../direct_payments/02`](../direct_payments/02-payers-and-bindings.md) never actually specifies, and a wrong section citation (`direct_payments/07`, the *tier-bindings* document, cited for email-propagation code that lives in `direct_payments/05` §5.5) |

## 1.3 What is *not* a gap

Worth stating, because each was checked:

- **Attribution writes are correct.** Every consumer writes `site_user_id` from the gateway,
  never from the request body. `grep -rn "input('site_user" app/` is empty.
- **Ownership filters are correct.** `orders`, `registrations/{ref}`, `bookings/{ref}` use
  404-not-403 and the token-or-owner rule.
- **Sessions are sound.** Hashed, per-device, revocable, cookie-by-default.
- **The Dashboard route is server-protected.** A guest never receives its HTML.
- **The façade order model is stable.** Three references, money from `PaymentGateway`. The
  purchases view in [07](07-direct-payments-connection.md) reuses `snapshots()` unchanged.
- **The builder canvas already imports the runtime's markup builders**, so K14 is a
  continuation, not a new discipline.
