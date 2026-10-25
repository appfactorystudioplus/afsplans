# 10 — API Contracts

Every endpoint, payload, SDK method and type declaration this plan adds or changes, in one
place. Nothing here is new design — it is the sum of [04](04-connection-registry-and-enforcement.md)
through [09](09-module-self-service.md), written so it can be implemented and reviewed
without re-reading them.

## 10.1 Conventions

- **Prefixes.** Admin: `/portal/api/siteusers/*` (`ApiAuthMiddleware`). Site-user session:
  `/portal/api/siteusers/public/me/*` (`site_users.origin` + `site_users.public.throttle` +
  `site_users.session`). Storefronts keep their existing prefixes
  (`/portal/api/{ecommerce,events,appointments}/storefront/*`).
- **Envelope.** `{"success": true, …}` on success. On failure
  `{"success": false, "error": {"message": string, "code": string, "module"?: string}}`.
  Ecommerce's storefront historically returns a bare `{"error": {…}}` on some paths; new
  endpoints use the envelope and existing ones are **not** changed in this plan (a shape
  change would break the shipped bundle).
- **Auth failures are `401`** with a code ending `_E_AUTH_REQUIRED`
  ([04](04-connection-registry-and-enforcement.md) §4.5). Policy refusals are `409`.
  Validation is `422`. Someone else's record is `404`.
- **Cursors** are opaque strings. `{"items": [...], "next_cursor": "…"|null}`.

## 10.2 Admin — connections

`App\Http\Controllers\Externals\SiteUsers\SiteUsersConnectionsController`, registered inside the
existing `Route::prefix('siteusers')` admin group.

```php
Route::get   ('/modules/{referenceNum}/connections',            [SiteUsersConnectionsController::class, 'index']);
Route::get   ('/modules/{referenceNum}/connections/candidates', [SiteUsersConnectionsController::class, 'candidates']);
Route::get   ('/modules/{referenceNum}/connections/attention',  [SiteUsersConnectionsController::class, 'attention']);
Route::put   ('/modules/{referenceNum}/connections',            [SiteUsersConnectionsController::class, 'upsert']);
Route::patch ('/modules/{referenceNum}/connections/{id}',       [SiteUsersConnectionsController::class, 'update']);
Route::delete('/modules/{referenceNum}/connections/{id}',       [SiteUsersConnectionsController::class, 'destroy']);
Route::get   ('/connections/for-instance',                      [SiteUsersConnectionsController::class, 'forInstance']);
Route::get   ('/connections/summary',                           [SiteUsersConnectionsController::class, 'summary']);
```

The last two are module-agnostic (they answer "what is the state of this instance / of every
module") and therefore sit outside `/modules/{ref}`.

| Endpoint | Request | Response |
|---|---|---|
| `GET …/connections` | — | `{success, data: [ …toAdminArray + {label_resolved, instance_name, instance_status, usage, state} ]}` where `state ∈ active\|paused\|orphaned` |
| `GET …/connections/candidates` | — | [05](05-admin-connections.md) §5.3's shape |
| `GET …/connections/attention` | — | `{success, data: [{module_key, reference_num, name, legacy_mode}]}` |
| `PUT …/connections` | `{module_key, instance_reference_num?, guest_checkout?, label?, settings?, is_active?}` | `{success, data}` · `409 SU_E_INSTANCE_CONNECTED_ELSEWHERE` + `connected_to` · `422 SU_E_GUEST_CHECKOUT_NOT_SUPPORTED` · `404 SU_E_INSTANCE_NOT_FOUND` · `422 SU_E_MODULE_NOT_CONNECTABLE` |
| `PATCH …/connections/{id}` | any subset of `{guest_checkout, label, settings, status}` — `status ∈ active\|disconnected` ([13](13-disconnection.md) §13.2) | `{success, data}` |
| `DELETE …/connections/{id}` | — | `{success}` |
| `GET /connections/for-instance` | `?module_key=&instance=` | `{success, data: {connected, module?, module_name?, guest_checkout?, module_wide?, connection_id?, suggested_module?}}` |
| `GET /connections/summary` | — | `{success, data: {<module_key>: {instances, connected, module_wide}}}` |

`settings` is merged against the registry schema; unknown keys are dropped, types coerced
([04](04-connection-registry-and-enforcement.md) §4.7).

## 10.3 Admin — the per-user activity card

```php
Route::get ('/modules/{referenceNum}/users/{id}/activity', [SiteUsersAdminController::class, 'userActivity']);
Route::post('/modules/{referenceNum}/users/{id}/claim-guest-records', [SiteUsersAdminController::class, 'claimGuestRecords']);
```

`claim-guest-records` takes `{"dry_run": true|false}` and returns
`{success, claimed: {"ecommerce": 3, "events": 1}}` — the counts a claim would make, or did
([09](09-module-self-service.md) §9.7). It refuses for a user with no verified email.

```json
{ "success": true, "activity": [
  { "module_key": "ecommerce", "instance_reference_num": "store-9f3k", "label": "Main Store",
    "counts": { "orders": 12, "open_returns": 1 },
    "recent": [ { "kind": "order", "reference_num": "ord-…", "label": "Order #1042",
                  "amount_cents": 8450, "currency": "usd", "status": "paid",
                  "at": "2026-09-01T14:02:11Z",
                  "admin_link": { "view": "ecommerce:order-detail", "params": { "reference_num": "ord-…" } } } ] }
] }
```

Read-only, capped at five rows per module ([05](05-admin-connections.md) §5.8).

## 10.4 Site-user session — Site Users' own `/me/*`

Added inside the existing `Route::middleware('site_users.session')` group.

```php
Route::get   ('/me/connections',                    [SiteUsersPublicController::class, 'connections']);
Route::get   ('/me/customers',                      [SiteUsersPaymentsController::class, 'customerFor']);
// POST /me/customers already exists (linkCustomer) — behaviour unchanged, gate re-pointed (04 §4.8)
Route::get   ('/me/payment-methods',                [SiteUsersPaymentsController::class, 'index']);
Route::post  ('/me/payment-methods',                [SiteUsersPaymentsController::class, 'store']);
Route::post  ('/me/payment-methods/setup',          [SiteUsersPaymentsController::class, 'setup']);
Route::delete('/me/payment-methods/{methodId}',     [SiteUsersPaymentsController::class, 'destroy']);
Route::post  ('/me/payment-methods/{methodId}/default', [SiteUsersPaymentsController::class, 'setDefault']);
Route::get   ('/me/purchases',                      [SiteUsersPaymentsController::class, 'purchases']);

// site_users/13 §13.4 — changing the address a user signs in with. The confirm
// leg sits OUTSIDE the session group, beside the other token confirmations, so
// the link works from any device and any mail client.
Route::patch ('/me/email',                          [SiteUsersPublicController::class, 'requestEmailChange']);
Route::post  ('/me/email/cancel',                   [SiteUsersPublicController::class, 'cancelEmailChange']);
// …and, in the unauthenticated public group alongside /email/verify:
Route::post  ('/email/change/confirm',              [SiteUsersPublicController::class, 'confirmEmailChange']);
```

| Endpoint | Request | Response |
|---|---|---|
| `GET /me/connections` | `?badges=0\|1` (default 1) | [08](08-dashboard-sections.md) §8.3 |
| `GET /me/customers` | `?processor=pr-1234&create=0\|1` | `{success, connected, customer: {provider, provider_customer_id}\|null}` |
| `POST /me/customers` | `{processor_reference_num, provider, provider_customer_id}` | `{success, linked}` · `409` when linked elsewhere · `{linked:false, skipped:'payments_not_connected'}` |
| `GET /me/payment-methods` | `?processor=` (optional) | `{success, connected, payment_methods: [{id, brand, last4, exp_month, exp_year, is_default, provider, processor_reference_num, processor_name}]}` |
| `POST /me/payment-methods` | `{processor_reference_num, payment_method_id?, source_id?}` | `{success, payment_method}` |
| `POST /me/payment-methods/setup` | `{processor_reference_num}` | `{success, provider, client_secret?, …provider-specific}` |
| `DELETE /me/payment-methods/{id}` | `?processor=` | `{success}` · `404` when not one of the caller's |
| `POST /me/payment-methods/{id}/default` | `?processor=` | `{success}` |
| `GET /me/purchases` | `?scope=all\|orders\|events\|appointments\|other&limit=&cursor=` | `{success, items: [row], next_cursor}` — row shape in [07](07-direct-payments-connection.md) §7.6. Joined on the **customer-link table only**, never on the client-settable `invoices.user_id` (X23) |
| `PATCH /me/email` | `{email, current_password?}` | `202 {pending}` · `422 SU_E_EMAIL_IN_USE` · `422 SU_E_EMAIL_INVALID` · `403 SU_E_PASSWORD_REQUIRED`. Password required unless the account has no password identity |
| `POST /me/email/cancel` | — | `{success}` — discards an unconsumed change token |
| `POST /email/change/confirm` *(public)* | `{module, token}` | `{success, user}` · `422 SU_E_TOKEN_INVALID` · `409 SU_E_EMAIL_IN_USE` (claimed between request and confirm) |

**All of these are `401` without a session** — the group's middleware does that before the
handler runs.

## 10.5 Storefront — ecommerce

| Method | Path | Change |
|---|---|---|
| `GET` | `/storefront/store` | **+** `site_users` block ([04](04-connection-registry-and-enforcement.md) §4.6). `site_users_mode` retained, now derived |
| `POST` | `/storefront/checkout/complete` | **+** gate + cart-owner check ([04](04-connection-registry-and-enforcement.md) §4.5) |
| `GET` | `/storefront/orders` | **+** `scope`, `limit`, `cursor`; **+** `401` carries `module` |
| `POST` | `/storefront/orders/{ref}/cancel` | **new** ([09](09-module-self-service.md) §9.2) |
| `GET` | `/storefront/returns` | **new** — `?store=` |
| `POST` | `/storefront/orders/{ref}/returns` | **new** — `{reason, note?, lines:[{order_line_id, quantity}]}` |
| `POST` | `/storefront/returns/{ref}/cancel` | **new** |

Admin additions for the returns queue:

```php
Route::get  ('/returns',                  [EcommerceController::class, 'getReturnRequests']);      // ?store=&status=
Route::patch('/returns/{reference_num}',  [EcommerceController::class, 'updateReturnRequest']);    // {status, resolution?, admin_note?, refund_cents?}
```

## 10.6 Storefront — events

| Method | Path | Change |
|---|---|---|
| `GET` | `/storefront/events/{ref}` | **+** `site_users` block |
| `POST` | `/storefront/hold` | **+** gate |
| `POST` | `/storefront/waitlist` | **+** gate |
| `GET` | `/storefront/registrations/mine` | **`401`** for guests (was `{registrations: []}`); **+** connection filter; **+** `occurrence_starts_at`, `event_name`, `venue_name`, `checked_in_at`; **−** `access_token` |
| `POST` | `/storefront/registrations/{ref}/cancel` | **+** policy window, **+** waitlist promotion in the transaction |

## 10.7 Storefront — appointments

| Method | Path | Change |
|---|---|---|
| `GET` | `/storefront/appointments/{ref}` | **+** `site_users` block |
| `POST` | `/storefront/hold` | **+** gate |
| `POST` | `/storefront/waitlist` | **+** gate |
| `GET` | `/storefront/bookings/mine` | **`401`** for guests; **+** connection filter; **+** `service_name`, `staff_name`, `can_cancel`, `can_reschedule`, `cancel_up_to_hours` |
| `POST` | `/storefront/bookings/{ref}/cancel` | **+** policy enforcement from `policy_snapshot` |
| `POST` | `/storefront/bookings/{ref}/reschedule` | **+** same |

## 10.8 Processor and Direct Payments — the X1/X2 fixes

| Change | Where |
|---|---|
| `resolveProcessorAndCustomer()` accepts a local integer id **only** for admin-authenticated callers | `ProcessorBillingController` |
| `ApiAuthMiddleware` on `GET /api/v1/processors/customers` | `routes/portal.php` |
| `App\Externals\Processor\ProcessorCustomerService` — `findOrCreateForEmail()`, `createForEmail()` extracted from the controller | new |
| `App\Externals\Processor\ProcessorPaymentMethodService` — `list()`, `store()`, `delete()`, `setDefault()`, `setupIntent()` extracted | new |

Neither service takes a `Request`, and neither knows what a site user is.

## 10.9 SDK — `window.Portal.siteUsers`

```ts
// external/api/site_users/SiteUsersClient.ts — new methods, all session-authenticated

/** 08 §8.3 — the Dashboard's groups. */
connections(opts?: { badges?: boolean }): Promise<PortalSiteUserConnection[]>;

/** site_users/13 §13.4 — request a change; the new address must be free within the module. */
changeEmail(payload: { email: string; current_password?: string }): Promise<{ pending: string }>;
cancelEmailChange(): Promise<void>;
/** Confirms from the emailed link. Public — no session needed. */
confirmEmailChange(token: string): Promise<PortalSiteUserPublic>;

/** 07 §7.4 — hand to Portal.directPayments.create(ref).useCustomerResolver(...). */
paymentsResolver(): DirectPaymentsCustomerResolver;

paymentMethods(processorRef?: string): Promise<PortalSavedPaymentMethod[]>;
addPaymentMethod(input: { processor_reference_num: string; payment_method_id?: string; source_id?: string }): Promise<PortalSavedPaymentMethod>;
setupPaymentMethod(processorRef: string): Promise<Record<string, unknown>>;
removePaymentMethod(methodId: string, processorRef: string): Promise<void>;
setDefaultPaymentMethod(methodId: string, processorRef: string): Promise<void>;
purchases(opts?: { scope?: 'all' | 'orders' | 'events' | 'appointments' | 'other'; limit?: number; cursor?: string }): Promise<{ items: PortalPurchase[]; next_cursor: string | null }>;
```

## 10.10 SDK — the other four

```ts
// commerce/api.ts
myOrders(opts?: { scope?: 'open' | 'all'; limit?: number; cursor?: string }): Promise<…>;   // widened, back-compatible
cancelOrder(referenceNum: string, reason?: string): Promise<Record<string, unknown>>;
returns(): Promise<PortalReturnRequest[]>;
requestReturn(orderRef: string, input: { reason: string; note?: string; lines: Array<{ order_line_id: number; quantity: number }> }): Promise<PortalReturnRequest>;
cancelReturn(returnRef: string): Promise<void>;

// events/api.ts — myRegistrations()/cancelRegistration() unchanged in signature; richer rows

// appointments/api.ts — myBookings() richer rows; cancelBooking()/rescheduleBooking() unchanged

// direct_payments/api.ts
useCustomerResolver(resolver: DirectPaymentsCustomerResolver | null): void;
```

Every thrown error from these clients gains `code`, `module?` and `status` so
`isAuthRequired()` ([06](06-checkout-guard.md) §6.5) has something to read. That is a change
to each client's shared `fetchJson` / `requestJson` helper — five small edits, one behaviour.

## 10.11 SDK — `window.Portal.ui`

```ts
// external/ui/site_users/sections.ts, re-exported on the namespace
Portal.ui.siteUsers.registerSectionProvider(provider: DashboardSectionProvider): void;
```

Exposed on the namespace (not only as a module import) so a tenant with a bespoke module can
contribute a dashboard group from their own script tag — the standalone requirement
([site_users/07](../site_users/07-externals-api.md) §7.1) applied to this surface.

## 10.12 `portal.d.ts` — the full delta

Hand-maintained, updated **in the same pass** as the code. Consumers copy it
([site_users/07](../site_users/07-externals-api.md) §7.6).

```ts
// ── Connections & dashboard ───────────────────────────────────────────────────
interface PortalSiteUserConnection {
    module_key: string;
    instance_reference_num: string | null;
    label: string;
    capabilities: string[];
    settings: Record<string, unknown>;
    badges: Record<string, number>;
}

interface PortalSiteUsersBlock {
    connected: boolean;
    guest_checkout: boolean;
    module?: string;
    login_path?: string | null;
    register_path?: string | null;
    dashboard_path?: string | null;
}

interface PortalDashboardView { key: string; label: string; icon?: string }
interface PortalDashboardSectionProvider {
    moduleKey: string;
    views(connection: PortalSiteUserConnection): PortalDashboardView[];
    render(host: HTMLElement, ctx: PortalDashboardRenderContext): Promise<void>;
}
interface PortalDashboardRenderContext {
    connection: PortalSiteUserConnection;
    view: string;
    ref?: string;
    su: PortalSiteUsersInstance;
    user: PortalSiteUserPublic;
    refresh(): Promise<void>;
    goTo(moduleKey: string, instance: string | null, view: string, ref?: string): void;
}

// ── Payments ──────────────────────────────────────────────────────────────────
interface PortalSavedPaymentMethod {
    id: string; brand?: string; last4?: string; exp_month?: number; exp_year?: number;
    is_default: boolean; provider: 'stripe' | 'square' | 'paypal';
    processor_reference_num: string; processor_name?: string;
}
interface PortalPurchase {
    kind: 'order' | 'event_order' | 'booking' | 'invoice' | 'subscription';
    reference_num: string; label: string;
    module_key: string | null; instance_reference_num: string | null;
    invoice_ref: string | null; processor_reference_num: string | null;
    amount_cents: number; currency: string; status: string; refunded_cents: number;
    paid_at: string | null; placed_at: string | null;
    manage: { section: string; view: string; ref: string } | null;
}
interface DirectPaymentsCustomerResolver {
    resolve(processorRef: string): Promise<{ provider_customer_id: string; provider: string } | null>;
    adopt(processorRef: string, provider: string, providerCustomerId: string): Promise<void>;
    paymentMethods(processorRef: string): Promise<PortalSavedPaymentMethod[]>;
    subject?(): string | null;
}

// ── Errors ────────────────────────────────────────────────────────────────────
interface PortalApiError extends Error { code: string; module?: string; status?: number }

// ── Returns ───────────────────────────────────────────────────────────────────
interface PortalReturnRequest {
    reference_num: string; order_reference_num: string; kind: 'return' | 'cancellation';
    status: 'requested' | 'approved' | 'rejected' | 'received' | 'refunded' | 'cancelled';
    reason: string; note?: string | null;
    lines: Array<{ order_line_id: number; quantity: number }>;
    resolution?: string | null; refund_cents?: number | null;
    created_at: string; decided_at?: string | null;
}

// ── Additions to existing interfaces ──────────────────────────────────────────
interface PortalSiteUsersInstance { /* …existing… */
    connections(opts?: { badges?: boolean }): Promise<PortalSiteUserConnection[]>;
    changeEmail(payload: { email: string; current_password?: string }): Promise<{ pending: string }>;
    cancelEmailChange(): Promise<void>;
    confirmEmailChange(token: string): Promise<PortalSiteUserPublic>;
    paymentsResolver(): DirectPaymentsCustomerResolver;
    paymentMethods(processorRef?: string): Promise<PortalSavedPaymentMethod[]>;
    addPaymentMethod(input: { processor_reference_num: string; payment_method_id?: string; source_id?: string }): Promise<PortalSavedPaymentMethod>;
    setupPaymentMethod(processorRef: string): Promise<Record<string, unknown>>;
    removePaymentMethod(methodId: string, processorRef: string): Promise<void>;
    setDefaultPaymentMethod(methodId: string, processorRef: string): Promise<void>;
    purchases(opts?: { scope?: string; limit?: number; cursor?: string }): Promise<{ items: PortalPurchase[]; next_cursor: string | null }>;
}

interface PortalCommerceInstance { /* …existing… */
    cancelOrder(referenceNum: string, reason?: string): Promise<Record<string, unknown>>;
    returns(): Promise<PortalReturnRequest[]>;
    requestReturn(orderRef: string, input: { reason: string; note?: string; lines: Array<{ order_line_id: number; quantity: number }> }): Promise<PortalReturnRequest>;
    cancelReturn(returnRef: string): Promise<void>;
}

interface PortalUiSiteUsersAPI extends PortalUiModuleAPI {
    registerSectionProvider(provider: PortalDashboardSectionProvider): void;
}
interface PortalUiNamespace { /* …existing… */ siteUsers: PortalUiSiteUsersAPI }
```

`PortalUiNamespace.siteUsers` widens from `PortalUiModuleAPI` to `PortalUiSiteUsersAPI` — a
superset, so no consumer breaks.

## 10.13 Error codes, complete

| Code | Status | Meaning |
|---|---|---|
| `SU_E_AUTH_REQUIRED` | 401 | Sign-in required (generic / registry) |
| `EC_E_AUTH_REQUIRED` / `EV_E_AUTH_REQUIRED` / `AP_E_AUTH_REQUIRED` | 401 | Same, per module |
| `EC_E_CART_OWNER_MISMATCH` | 403 | Completing another account's checkout in `required` mode |
| `SU_E_INSTANCE_CONNECTED_ELSEWHERE` | 409 | K3 |
| `SU_E_INSTANCE_NOT_FOUND` | 404 | Unknown or out-of-scope instance |
| `SU_E_MODULE_NOT_CONNECTABLE` | 422 | `connectable: false` |
| `SU_E_GUEST_CHECKOUT_NOT_SUPPORTED` | 422 | Module has no `required` mode |
| `EC_E_CANCEL_DISABLED` / `EC_E_CANCEL_WINDOW_PASSED` / `EC_E_CANCEL_FULFILLED` | 409 | Shopper cancel |
| `EC_E_RETURNS_DISABLED` / `EC_E_RETURN_WINDOW_PASSED` / `EC_E_RETURN_ALREADY_OPEN` | 409 | Returns |
| `EC_E_RETURN_LINE_INVALID` | 422 | Line not on the order, or quantity too high |
| `EC_E_ORDER_UNPAID` | 409 | Return on an unpaid order |
| `EV_E_CANCEL_DISABLED` / `EV_E_CANCEL_WINDOW_PASSED` | 409 | Registration cancel |
| `SU_E_EMAIL_IN_USE` | 422 / 409 | The address belongs to another user in this module — `422` at request, `409` at confirm |
| `SU_E_EMAIL_INVALID` | 422 | Not a valid address |
| `SU_E_PASSWORD_REQUIRED` | 403 | Wrong or missing current password on an account that has one |
| `SU_E_TOKEN_INVALID` | 422 | Expired, consumed or unknown change token |
| `SU_E_CONNECTION_DISCONNECTED` | 409 | The action's module is connected but read-only ([13](13-disconnection.md) §13.5) |
| `AP_E_CANCEL_WINDOW_PASSED` / `AP_E_RESCHEDULE_DISABLED` | 409 | Booking policy |

## 10.14 Rate limits (X18)

`SiteUsersPublicThrottle` matches nothing under `/me/*` today, so every session endpoint —
existing and new — is unlimited. The buckets this plan adds, keyed on the **session token's
hash** rather than the IP, because these are authenticated calls and the resource being
protected is a per-tenant provider quota ([07](07-direct-payments-connection.md) §7.9):

| Path | Per session | Per IP | Window |
|---|---|---|---|
| `/me/payment-methods*` (any method) | 30 | 120 | 5 min |
| `/me`, `/me/connections`, `/me/purchases`, `/me/sessions` | 240 | 600 | 5 min |

Every other bucket in that middleware is unchanged. `429` carries `SU_E_RATE_LIMITED`,
`retry_after` and a `Retry-After` header, matching what the middleware already returns.

Beneath the throttle, `ProcessorPaymentMethodService::list()` caches per
`(processor, provider_customer_id)` for 60 seconds and every write busts the key.

## 10.15 What is deliberately absent

- **No endpoint takes a `site_user_reference`, `user_id` or `customer_id` from the client** on any
  path this plan adds. Grep gate in [11](11-rollout-and-testing.md) §11.5.
- **No refund endpoint.** K15.
- **No connection endpoint on a public prefix.** A visitor never reads or writes connections;
  they read the `site_users` block on an instance's own config and their own
  `/me/connections`.
