# 04 — The Connection Registry and Enforcement

The server half: the registry that reads `laravel_site_user_connections`, the service that
writes it, the re-pointing of `SiteUsersConsumerRegistry`, module isolation, the public
config block, and every enforcement point in the three consumers.

## 4.1 The consumer registry grows four keys

`config/site_users_consumers.php` remains the single declaration. Each connectable entry
gains what the admin editor, the dashboard and the derived-column sync need:

```php
<?php

/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/site_users/09-app-integrations.md §9.2;
 * plans/www/connections/04-connection-registry-and-enforcement.md §4.1.
 *
 *   connectable   may a tenant create a connection row for this module?
 *   instance      how to enumerate and label instances for the admin editor and
 *                 the derived-column sync (03 §3.5). `model` must expose
 *                 `reference_num`; `label` is the display column; `site_column` names
 *                 the site-ownership column so the candidates list is restricted
 *                 to the caller's sites (05 §5.3). null = module-wide only.
 *                 NOT called `scope` — `dashboard.scope` below means something
 *                 entirely different, and one word for two ideas in one array is
 *                 how the wrong one gets read.
 *   dashboard     capability flags the section provider may rely on, the read-only
 *                 subset a DISCONNECTED connection is reduced to (13 §13.4), and the
 *                 default module-wide label ("Events"). Per-instance labels come
 *                 from `instance.label`.
 *                 `scope` says whether this module's own "mine" endpoints are
 *                 instance-scoped or global (§4.10, X22): 'instance' means a
 *                 module-wide connection must be EXPANDED into one dashboard
 *                 entry per instance, because `GET /storefront/orders` cannot be
 *                 called without a store; 'global' means one entry is enough.
 *   settings      the per-connection settings schema the editor renders (05 §5.5),
 *                 with defaults. Unknown keys posted by a client are dropped.
 *   mode_source   KEPT for one release for rollback only (03 §3.6). Not read.
 */
return [
    'ecommerce' => [
        'label' => 'Ecommerce',
        'user_column' => 'site_user_reference',
        'supports' => ['guest', 'required'],
        'connectable' => true,
        'instance' => ['model' => \App\Models\Externals\EcommerceStore::class, 'label' => 'name', 'site_column' => 'website_reference_num', 'noun' => 'store'],
        'dashboard' => ['label' => 'Store', 'scope' => 'instance',
                        'capabilities' => ['orders', 'order_detail', 'cancel', 'returns', 'favorites', 'reorder'],
                        'read_only_capabilities' => ['orders', 'order_detail']],   // 13 §13.4
        'settings' => [
            'cancel'  => ['enabled' => true,  'window_minutes' => 60],
            'returns' => ['enabled' => false, 'window_days' => 30, 'reasons' => ['damaged', 'wrong_item', 'not_as_described', 'no_longer_needed', 'other']],
        ],
        'mode_source' => [\App\Models\Externals\EcommerceStore::class, 'site_users_mode'],
    ],
    'events' => [
        'label' => 'Events',
        'user_column' => 'site_user_reference',
        'supports' => ['guest', 'required'],
        'connectable' => true,
        'instance' => ['model' => \App\Models\Externals\Event::class, 'label' => 'name', 'site_column' => 'website_reference_num', 'noun' => 'event'],
        'dashboard' => ['label' => 'Events', 'scope' => 'global',
                        'capabilities' => ['upcoming', 'past', 'tickets', 'cancel', 'calendar'],
                        'read_only_capabilities' => ['upcoming', 'past', 'tickets', 'calendar']],
        'settings' => [
            'cancel' => ['enabled' => true, 'cutoff_hours' => 24],
            'show_past' => true,
        ],
        'mode_source' => [\App\Models\Externals\Event::class, 'site_users_mode'],
    ],
    'appointments' => [
        'label' => 'Appointments',
        'user_column' => 'site_user_reference',
        'supports' => ['guest', 'required'],
        'connectable' => true,
        'instance' => ['model' => \App\Models\Externals\Appointment::class, 'label' => 'name', 'site_column' => 'website_reference_num', 'noun' => 'calendar'],
        'dashboard' => ['label' => 'Appointments', 'scope' => 'global',
                        'capabilities' => ['upcoming', 'past', 'cancel', 'reschedule', 'calendar'],
                        'read_only_capabilities' => ['upcoming', 'past', 'calendar']],
        'settings' => [
            'cancel'     => ['enabled' => true],   // the cutoff itself is the booking's policy_snapshot (09 §9.4)
            'reschedule' => ['enabled' => true],
        ],
        'mode_source' => [\App\Models\Externals\Appointment::class, 'site_users_mode'],
    ],
    'direct_payments' => [
        'label' => 'Direct Payments',
        'user_column' => null,                 // DP has no user column; the link table is the join (site_users/08)
        'supports' => [],                      // no checkout mode of its own — guest_checkout is hidden for this module
        'connectable' => true,
        'instance' => ['model' => \App\Models\Externals\DirectPayment::class, 'label' => 'name', 'site_column' => 'website_reference_num', 'noun' => 'Direct Payment'],
        'dashboard' => ['label' => 'Payments', 'scope' => 'global',
                        'capabilities' => ['payment_methods', 'purchases'],
                        // Both survive: a user must be able to REMOVE a stored card even when
                        // the tenant disconnected payments (13 §13.6). Add and set-default do not.
                        'read_only_capabilities' => ['payment_methods', 'purchases']],
        'settings' => [],
        'mode_source' => null,
    ],
    // X33 — `FormSubmission` lists `site_user_id` in $fillable and NOTHING writes it.
    // Forms attributes nothing today. Saying otherwise would send an implementer
    // looking for a writer that does not exist. Not connectable, no dashboard group.
    'forms'       => [ /* unchanged */ 'connectable' => false ],
    'discussions' => [ /* unchanged */ 'connectable' => false ],
];
```

`SiteUsersConsumerRegistryTest::test_all_five_modules_are_registered` becomes *six*, and a
new assertion pins `connectable` to exactly `{ecommerce, events, appointments, direct_payments}`.

## 4.2 `SiteUsersConnectionRegistry` — the read side

`app/SiteUsers/SiteUsersConnectionRegistry.php`. Request-scoped cache (one array per request),
because a storefront page calls `resolve()` for the same store several times — see §4.2.1,
without which that sentence is not true.

```php
<?php

namespace App\SiteUsers;

use App\Models\Externals\SiteUsers\SiteUserConnection;
use App\Models\Externals\SiteUsers\SiteUserModule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/04-connection-registry-and-enforcement.md §4.2.
 *
 * The one reader of laravel_site_user_connections. Precedence: instance row,
 * then module-wide row, then null (02 §2.3). A row whose Site Users module is
 * inactive is invisible (02 §2.8). Fails OPEN on a read error — a broken
 * connections table must not stop a sale.
 */
class SiteUsersConnectionRegistry
{
    /** @var array<string, Collection<int, SiteUserConnection>>  module_key → active rows */
    private array $byModule = [];

    /** @var array<string, bool>  site_users_id → module is active */
    private array $moduleActive = [];

    public function __construct(private readonly SiteUsersConsumerRegistry $consumers) {}

    public function resolve(string $moduleKey, ?string $instanceRef): ?SiteUserConnection
    {
        $rows = $this->rowsFor($moduleKey);
        if ($rows->isEmpty()) {
            return null;
        }

        $candidate = null;
        if ($instanceRef !== null && $instanceRef !== '') {
            $candidate = $rows->firstWhere('instance_key', $instanceRef);
        }
        $candidate ??= $rows->firstWhere('instance_key', SiteUserConnection::WILDCARD);

        if ($candidate === null || ! $this->moduleIsActive($candidate->site_users_id)) {
            return null;
        }

        return $candidate;
    }

    /**
     * ACTIVE or DISCONNECTED (13 §13.3). Everything that decides what may be
     * SEEN reads this, and so does every action that needs to refuse honestly:
     * without it a read-only action is refused with "not found", and a user is
     * told their order does not exist because the tenant flipped a switch.
     */
    public function resolveAny(string $moduleKey, ?string $instanceRef): ?SiteUserConnection
    {
        return $this->pick($this->rowsFor($moduleKey, visible: true), $instanceRef);
    }

    /**
     * ACTIVE connections for one Site Users module. The set anything that WRITES
     * iterates — the guest-claim service most of all (09 §9.7, X36).
     */
    public function activeFor(string $siteUsersId): Collection
    {
        return $this->forDashboard($siteUsersId)->filter(fn (SiteUserConnection $c) => $c->isActive())->values();
    }

    /** Every VISIBLE connection for one Site Users module — the dashboard's query (08 §8.3, 13 §13.4). */
    public function forDashboard(string $siteUsersId): Collection
    {
        try {
            return SiteUserConnection::query()->visible()
                ->where('site_users_id', $siteUsersId)
                ->orderByRaw("FIELD(status,'active','disconnected')")   // 13 §13.4 — live groups first
                ->orderBy('module_key')->orderBy('instance_key')
                ->get()
                ->filter(fn (SiteUserConnection $c) => $this->consumers->get($c->module_key)['connectable'] ?? false)
                ->values();
        } catch (\Throwable $e) {
            Log::error('[SiteUsers] connections read failed (fail-open)', ['error' => $e->getMessage()]);
            return collect();
        }
    }

    public function isConnected(string $siteUsersId, string $moduleKey, ?string $instanceRef = null): bool
    {
        $row = $this->resolve($moduleKey, $instanceRef);
        return $row !== null && $row->site_users_id === $siteUsersId;
    }

    /**
     * 07 §7.8 — replaces `modules.link_payment_customers`. ACTIVE only (X35):
     * this governs WRITES — linkCustomer(), the reconciler, adding a card — and a
     * disconnected payments connection accepts none of them (13 §13.5).
     */
    public function paymentsLinked(string $siteUsersId): bool
    {
        return SiteUserConnection::query()->active()
            ->where('site_users_id', $siteUsersId)->where('module_key', 'direct_payments')->exists();
    }

    /**
     * VISIBLE — active or disconnected. Governs READS: the saved-card list, the
     * purchases view, and the one write that survives disconnection, removing a
     * card (13 §13.6).
     *
     * The pair exists because "is payments connected" has two different answers
     * depending on whether the caller is about to read or about to write, and
     * collapsing them is exactly how a disconnected module would keep accepting
     * new cards.
     */
    public function paymentsVisible(string $siteUsersId): bool
    {
        return $this->forDashboard($siteUsersId)->contains(fn (SiteUserConnection $c) => $c->module_key === 'direct_payments');
    }

    public function flush(): void
    {
        $this->byModule = [];
        $this->moduleActive = [];
    }

    private function rowsFor(string $moduleKey): Collection
    {
        if (! isset($this->byModule[$moduleKey])) {
            try {
                $this->byModule[$moduleKey] = SiteUserConnection::query()->active()
                    ->where('module_key', $moduleKey)->get();
            } catch (\Throwable $e) {
                Log::error('[SiteUsers] connections read failed (fail-open)', ['module_key' => $moduleKey, 'error' => $e->getMessage()]);
                $this->byModule[$moduleKey] = collect();
            }
        }
        return $this->byModule[$moduleKey];
    }

    private function moduleIsActive(string $siteUsersId): bool
    {
        return $this->moduleActive[$siteUsersId] ??= SiteUserModule::query()
            ->where('reference_num', $siteUsersId)->where('is_active', true)->exists();
    }
}
```

### 4.2.1 Container bindings — required, not optional (X31)

`app/Providers/AppServiceProvider.php` **binds nothing today**: no `bind`, no `singleton`, no
`scoped`. Laravel therefore constructs a fresh instance at every injection point, and a
per-instance cache would never be hit twice — the controller's copy, the service's copy and
the middleware's copy would each read the table again.

So the cache above is a claim about a binding that has to exist:

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/04-connection-registry-and-enforcement.md §4.2.1.
 *
 * `scoped`, not `singleton`: the cache is correct for one request and wrong for
 * the next. Under Octane and inside a queue worker a `singleton` would survive
 * both, and a tenant's connection change would not take effect until the worker
 * restarted.
 *
 * All four are bound together on purpose. Binding only the connection registry
 * as `scoped` while the ones that DEPEND on it stay transient means each of
 * them resolves its own — the cache is per-consumer again — and making any of
 * them a `singleton` pins the first request's scoped instance forever, which is
 * the same staleness with a longer fuse.
 */
public function register(): void
{
    $this->app->scoped(SiteUsersConnectionRegistry::class);
    $this->app->scoped(SiteUsersConsumerRegistry::class);
    $this->app->scoped(SiteUsersGateway::class);
    $this->app->scoped(SiteUsersConnectionService::class);
}
```

`SiteUsersConnectionService::upsert()` calls `$this->registry->flush()` after every write, so
a change made in one request is visible to the rest of that request. Nothing caches across
requests, which is the point.

**A test pins this**, because a missing binding fails silently as extra queries rather than as
an error:

```php
$this->assertSame(
    app(SiteUsersConnectionRegistry::class),
    app(SiteUsersConsumerRegistry::class)->connectionRegistryForTesting(),
);
DB::enableQueryLog();
/* two resolve() calls for the same module through different services */
$this->assertCount(1, array_filter(DB::getQueryLog(),
    fn ($q) => str_contains($q['query'], 'laravel_site_user_connections')));
```

### `SiteUsersConsumerRegistry` — re-pointed (K5)

The public surface is unchanged; two methods change body, two are added:

```php
/**
 * modeFor() now derives from the connection registry (K5). The `$instance`
 * parameter is the same object callers already pass; only `reference_num` is
 * read from it. A module that is not `connectable` keeps its old semantics
 * (discussions → 'required'; forms → 'off').
 */
public function modeFor(string $appKey, ?object $instance = null): string
{
    $entry = $this->get($appKey);
    if ($entry === null) {
        return 'off';
    }
    if (! ($entry['connectable'] ?? false)) {
        return ($this->supportedModes($appKey) === ['off', 'required']) ? 'required' : 'off';
    }

    $conn = $this->connections->resolve($appKey, $instance?->reference_num ?? null);
    if ($conn === null) {
        return 'off';
    }

    $mode = $conn->mode();
    return in_array($mode, $this->supportedModes($appKey), true) ? $mode : 'off';
}

/**
 * K6 — the signed-in user counts only if they belong to the CONNECTED module.
 * Returns the REFERENCE to attribute, or null for "treat as guest". In 'off'
 * mode this is always null (02 §2.4): an unconnected module must not know who
 * the visitor is.
 *
 * Returns `reference_num`, never the bigint id (02 §2.10,
 * site_users/13 §13.3). A consuming module stores the reference and displays
 * the email; it never holds a value that changes meaning between environments
 * or that leaks how many users a tenant has.
 */
public function attributableUserReference(string $appKey, ?object $instance, Request $request): ?string
{
    $conn = $this->connections->resolve($appKey, $instance?->reference_num ?? null);
    if ($conn === null) {
        return null;
    }
    return $this->gateway->currentUserFor($request, $conn->site_users_id)?->reference_num;
}

/**
 * The gate. Unchanged contract — null when allowed, an error payload when not —
 * with one new field: `module`, so the UI can sign the visitor in to the RIGHT
 * module when their current session belongs to another (02 §2.5).
 *
 * @return array{message: string, code: string, module?: string}|null
 */
public function assertAuthenticated(string $appKey, ?object $instance, Request $request): ?array
{
    if ($this->modeFor($appKey, $instance) !== 'required') {
        return null;
    }
    if ($this->attributableUserReference($appKey, $instance, $request) !== null) {
        return null;
    }
    $conn = $this->connections->resolve($appKey, $instance?->reference_num ?? null);

    return [
        'message' => 'Sign in to continue.',
        'code' => 'SU_E_AUTH_REQUIRED',
        'module' => $conn?->site_users_id,
    ];
}

/** @deprecated since connections — reads any module's session AND returns a bigint. Kept for one release; callers below migrate to `attributableUserReference()`. */
public function currentUserId(Request $request): ?int
```

### Callers of the deprecated `currentUserId()` and their replacement

| Today | After |
|---|---|
| `EcommerceShopperAuth::siteUserId($request)` | `siteUserReference(EcommerceStore $store, Request $request): ?string` → `registry->attributableUserReference('ecommerce', $store, $request)` |
| `EcommerceCartController::beginCheckout()` — `$this->siteUsers->currentUserId($request)` | `$this->shopperAuth->siteUserId($store, $request)` |
| `EcommerceCartService::resolveCartForShopper()` (merge) | receives the store-scoped id from its caller |
| `EcommerceShopperOrdersController::index()/show()` | `siteUserId($store, $request)`; `show()` resolves the store from the order |
| `EventRegistrationService::register()` — `$this->siteUsers->currentUserId($request)` | `$registry->attributableUserReference('events', $event, $request)` |
| `EventStorefrontController::myRegistrations()/registration()/cancelRegistration()/confirmPayment()/calendarIcs()/`**`joinWaitlist()`** (X34 — it already calls `currentUserId()` and was missing from this table) | `attributableUserReference('events', $event, …)` — for list endpoints with no single event, resolve through the **module-wide** row (`$instance = null`) and, when only instance rows exist, filter registrations to events whose resolved connection matches the session's module (§4.4) |
| `AppointmentBookingService::book()`, `AppointmentStorefrontController::*` | same pattern with `'appointments'`. **`joinWaitlist()` passes no user id at all today** — it gains one (X28, [09](09-module-self-service.md) §9.4) |

`SiteUsersGateway::currentUserFor()` is added exactly as written in [02](02-architecture.md) §2.5.

## 4.3 Module isolation, precisely (K6)

The comparison is `$user->site_users_id === $conn->site_users_id`. What each surface does
with a mismatch:

| Surface | Mode | Mismatch behaviour |
|---|---|---|
| attribution (`site_user_reference` write) | any | write `null` |
| `required` gate | `required` | `401` with `module` |
| "mine" list (`orders`, `registrations/mine`, `bookings/mine`) | any | `401 SU_E_AUTH_REQUIRED` with `module` — the caller is signed in, but not here |
| owner path on `…/{ref}` | any | owner test fails → the token path may still succeed → else `404` |
| `/me/connections` | — | n/a: it is keyed on the session's own module |

The `module` hint is the reference number only — it exists in the page's own HTML for any
page that could act on it, so it discloses nothing new.

## 4.4 Lists without a single instance

`registrations/mine` and `bookings/mine` are not scoped to one event or one calendar. The
correct filter is: *registrations whose event resolves to a connection naming the session's
module*.

```php
// EventStorefrontController::myRegistrations()
$user = $this->gateway->currentUser($request);               // any module — we filter below
if ($user === null) {
    return response()->json(['success' => false, 'error' => ['message' => 'Sign in to see your registrations.', 'code' => 'SU_E_AUTH_REQUIRED']], 401);
}

$registrations = EventRegistration::query()
    ->where('site_user_reference', $user->reference_num)
    ->with('event:reference_num,name')
    ->orderByDesc('registered_at')
    ->get()
    // 13 §13.9 — `resolveAny`, not `isConnected`: a DISCONNECTED event keeps its
    // history in the user's account, read-only. Only a REMOVED connection takes
    // it out, which is the tenant explicitly saying it should not be there.
    ->filter(fn (EventRegistration $r) => $this->connections
        ->resolveAny('events', $r->event_reference_num)?->site_users_id === $user->site_users_id)
    ->values();
```

`site_user_reference` already restricts to this user's rows; the connection filter removes only
rows whose connection was **removed** — a disconnected one still shows, read-only
([13](13-disconnection.md) §13.4). One query for the rows; `resolveAny()` hits the request
cache, not the database, per row.

`myBookings()` is identical with `appointment_reference_num`. Ecommerce's `orders` already
takes `?store=` and is per-instance.

**Both endpoints return `401` to guests** (**X16**), not `[]`. The events UI's current
`try { … } catch { signInMarkup }` starts working for the right reason.

## 4.5 Enforcement points (K7)

Every anonymous-sensitive write, with what changes. ✅ = already gated today; **new** = this
plan.

### ecommerce

| Endpoint | State | Change |
|---|---|---|
| `POST /checkout/begin` | ✅ `assertCheckoutAllowed()` | none in the gate; attribution uses `attributableUserReference()` |
| `POST /checkout/attach` | open | none — correlates a cart with an invoice; carries no identity |
| `POST /checkout/complete` | **new** | see below |
| `POST /cart/lines` etc. | open | none — a cart is anonymous until checkout; the merge on sign-in is where attribution begins |

```php
/**
 * AFS_ECOMMERCE_MODIFIER_ADDITION — plans/www/connections/04-connection-registry-and-enforcement.md §4.5.
 *
 * X8. In `required` mode the completion must carry the same session that began
 * the checkout. Otherwise: begin as user A (cart.site_user_reference = A), hand the
 * cart_ref to anyone, complete anonymously — an order attributed to A that A
 * did not place. In `guest` mode a cart begun signed-in may be completed by
 * the same session; a different session completes it as a guest order
 * (attribution cleared), never as A's.
 */
public function completeCheckout(Request $request)
{
    // … resolve $storeRef, $store, $cart as today …

    $mode = $this->registry->modeFor('ecommerce', $store);
    $userRef = $this->shopperAuth->siteUserReference($store, $request);

    if ($mode === 'required' && $userRef === null) {
        return response()->json(['success' => false, 'error' => [
            'message' => 'Sign in to complete your order.', 'code' => 'EC_E_AUTH_REQUIRED',
            'module' => $this->registry->assertAuthenticated('ecommerce', $store, $request)['module'] ?? null,
        ]], 401);
    }

    if ($cart !== null && $cart->site_user_reference !== null && $cart->site_user_reference !== $userRef) {
        if ($mode === 'required') {
            return response()->json(['success' => false, 'error' => ['message' => 'This checkout belongs to another account.', 'code' => 'EC_E_CART_OWNER_MISMATCH']], 403);
        }
        $cart->site_user_reference = null;          // guest mode: complete as a guest, never as the other user
        $cart->save();
    }

    // … $this->checkoutService->complete(...) as today …
}
```

`EcommerceCheckoutService::complete()` copies `cart.site_user_reference` onto the order — unchanged,
which is why the controller normalises the cart first.

### events

| Endpoint | State | Change |
|---|---|---|
| `POST /register` | ✅ in `EventRegistrationService::register()` | attribution via `attributableUserReference()` |
| `POST /hold` | **new** | gate: resolve the occurrence → event → `assertAuthenticated('events', $event)` |
| `POST /waitlist` | **new** | same gate |
| `POST /registrations/{ref}/confirm-payment` | ✅ token-or-owner | owner uses `attributableUserReference()` |
| `POST /registrations/{ref}/cancel` | ✅ token-or-owner | + policy window ([09](09-module-self-service.md) §9.3) |

```php
// EventStorefrontController::hold()
$occurrence = EventOccurrence::query()->where('reference_num', (string) $request->input('occurrence_reference_num'))->first();
$event = $occurrence ? Event::query()->where('reference_num', $occurrence->event_reference_num)->first() : null;
if ($event !== null && ($err = $this->registry->assertAuthenticated('events', $event, $request)) !== null) {
    return response()->json(['success' => false, 'error' => ['message' => 'Sign in to reserve tickets.', 'code' => 'EV_E_AUTH_REQUIRED', 'module' => $err['module'] ?? null]], 401);
}
```

The hold is where capacity is *claimed* ([events/06](../events/06-ticketing-and-capacity.md)),
so an ungated hold on a `required` event lets an anonymous client tie up seats for the hold
window, repeatedly. That is the reason the gate is worth a query.

### appointments

| Endpoint | State | Change |
|---|---|---|
| `POST /book` | ✅ in `AppointmentBookingService::book()` | attribution via `attributableUserReference()` |
| `POST /hold` | **new** | gate on the appointment resolved from the request |
| `POST /waitlist` | **new** | same |
| `POST /bookings/{ref}/reschedule` | ✅ token-or-owner | + `settings.reschedule.enabled` and policy window ([09](09-module-self-service.md) §9.4) |
| `POST /bookings/{ref}/cancel` | ✅ token-or-owner | + policy window (**X15**) |

### Status codes

The three modules currently disagree: ecommerce returns `401`, events and appointments return
`422` with the code in the body. This plan **standardises on `401`** for every auth-required
refusal, keeping each module's own code (`EC_E_AUTH_REQUIRED`, `EV_E_AUTH_REQUIRED`,
`AP_E_AUTH_REQUIRED`) and adding the `module` hint. `book()`'s result array already carries
a `status` key the controller honours; `register()`'s controller gains the same. The shared
UI gate keys on the `_E_AUTH_REQUIRED` suffix and `SU_E_AUTH_REQUIRED`
([06](06-checkout-guard.md) §6.5), so a module that forgets is still handled — but the test
matrix asserts the status.

## 4.6 `publicBlock()` — what a page is told (K8)

```php
/**
 * The `site_users` block every instance's public config carries (02 §2.7).
 * When not connected: no module id, no paths — an unconnected store does not
 * advertise which Site Users module exists.
 *
 * @return array{connected: bool, guest_checkout: bool, module?: string, login_path?: ?string, register_path?: ?string, dashboard_path?: ?string}
 */
public function publicBlock(string $appKey, ?object $instance): array
{
    $conn = $this->connections->resolve($appKey, $instance?->reference_num ?? null);
    if ($conn === null) {
        return ['connected' => false, 'guest_checkout' => true];
    }

    $paths = $this->paths->forModule($conn->site_users_id, $conn);   // SiteUsersPagePaths, below

    return [
        'connected' => true,
        'module' => $conn->site_users_id,
        'guest_checkout' => $conn->guest_checkout,
        'login_path' => $paths['login'],
        'register_path' => $paths['register'],
        'dashboard_path' => $paths['dashboard'],
    ];
}
```

`SiteUsersPagePaths::forModule()` answers "where are this module's pages" from the one
reliable record: the `laravel_builder_routes` row the Dashboard publish wrote
([builder/v2/08](../builder/v2/complete/08-site-users-pages.complete.md) §8.8) — `login_route`
is the login slug, the row's `subpaths` is the dashboard slug, and the register slug is the
login slug with `-login` → `-register` (the builder's sibling convention, `siblingHref()`).
A connection's `settings.login_path` overrides all three roots. When no row exists (module
activated, pages never published) every path is `null` and the UI mounts the login form
inline instead of linking ([06](06-checkout-guard.md) §6.4).

Call sites:

- `EcommerceStorefrontController::getStore()` → `'site_users' => $this->registry->publicBlock('ecommerce', $store)`.
- `EventStorefrontController::serializeEventDetail()` → `$data['site_users'] = …('events', $event)`.
- `AppointmentStorefrontController::serializeDetail()` → same with `'appointments'`.

`site_users_mode` stays in `getStore()`'s response for one release, populated from
`modeFor()` (not the column), so an old page bundle keeps working.

## 4.7 `SiteUsersConnectionService` — the write side

`app/SiteUsers/SiteUsersConnectionService.php`. Every admin write goes through it; it owns
validation, the K3 conflict, the derived-column sync and cache flush.

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/04-connection-registry-and-enforcement.md §4.7.
 */
class SiteUsersConnectionService
{
    public function __construct(
        private readonly SiteUsersConsumerRegistry $consumers,
        private readonly SiteUsersConnectionRegistry $registry,
        private readonly SiteUsersDerivedColumns $derived,     // 03 §3.5
    ) {}

    /**
     * @param array{module_key:string, instance_reference_num?:?string, guest_checkout?:bool, label?:?string, settings?:array, is_active?:bool} $input
     * @throws ConnectionException  SU_E_MODULE_NOT_CONNECTABLE | SU_E_INSTANCE_NOT_FOUND | SU_E_INSTANCE_CONNECTED_ELSEWHERE | SU_E_GUEST_CHECKOUT_NOT_SUPPORTED
     */
    public function upsert(SiteUserModule $module, array $input, ?string $actor = null): SiteUserConnection
    {
        $entry = $this->consumers->get($input['module_key']) ?? throw ConnectionException::notConnectable($input['module_key']);
        if (! ($entry['connectable'] ?? false)) {
            throw ConnectionException::notConnectable($input['module_key']);
        }

        $instanceKey = $input['instance_reference_num'] ?? null;
        $instanceKey = ($instanceKey === null || $instanceKey === '') ? SiteUserConnection::WILDCARD : $instanceKey;

        if ($instanceKey !== SiteUserConnection::WILDCARD) {
            $model = $entry['instance']['model'] ?? throw ConnectionException::notConnectable($input['module_key']);
            if (! $model::query()->where('reference_num', $instanceKey)->exists()) {
                throw ConnectionException::instanceNotFound($input['module_key'], $instanceKey);
            }
        }

        // guest_checkout only means something for a module that supports 'required'.
        $guest = (bool) ($input['guest_checkout'] ?? true);
        if (! $guest && ! in_array('required', $entry['supports'], true)) {
            throw ConnectionException::guestCheckoutNotSupported($input['module_key']);
        }

        $settings = $this->mergeSettings($entry['settings'] ?? [], $input['settings'] ?? []);   // drops unknown keys, casts types

        $row = DB::transaction(function () use ($module, $input, $instanceKey, $guest, $settings, $actor) {
            // K3 — the unique index catches the race; this check gives the friendly 409.
            $existing = SiteUserConnection::query()->where('module_key', $input['module_key'])->where('instance_key', $instanceKey)->lockForUpdate()->first();
            if ($existing !== null && $existing->site_users_id !== $module->reference_num) {
                throw ConnectionException::connectedElsewhere($input['module_key'], $instanceKey, $existing->site_users_id);
            }

            $row = $existing ?? new SiteUserConnection(['module_key' => $input['module_key'], 'instance_key' => $instanceKey, 'created_by' => $actor]);
            $row->fill([
                'site_users_id' => $module->reference_num,
                'guest_checkout' => $guest,
                'label' => isset($input['label']) ? (trim((string) $input['label']) ?: null) : $row->label,
                'settings' => $settings,
                'status' => $input['status'] ?? SiteUserConnection::STATUS_ACTIVE,   // 13 §13.2
            ]);
            $row->save();

            return $row;
        });

        // X30 — AFTER the commit, never inside it. A module-wide change chunks an
        // UPDATE over every instance of that module, and holding those row locks
        // for the length of an admin request is a lock convoy waiting for a large
        // catalogue. The derived columns are a cache (03 §3.5), so a crash between
        // the commit and the sync leaves them stale rather than wrong, and
        // `siteusers:connections-audit` reports the drift.
        $this->registry->flush();
        $this->derived->sync($input['module_key']);

        return $row;
    }

    public function remove(SiteUserModule $module, int $id): void
    {
        $row = SiteUserConnection::query()->whereKey($id)->where('site_users_id', $module->reference_num)->firstOrFail();
        $moduleKey = $row->module_key;
        $row->delete();
        $this->registry->flush();
        $this->derived->sync($moduleKey);
    }
}
```

**Disconnecting deletes the row.** History is not on the row — it is on the orders,
registrations and bookings, which keep their `site_user_reference` ([02](02-architecture.md) §2.4).
**Disconnecting does not delete.** [13](13-disconnection.md) re-specifies this: `disconnect`
sets `status = 'disconnected'`, which stops all new activity while keeping the row so the
user's Dashboard can still show what they did. `remove()` — deleting the row — is the separate,
warned action that also takes the history out of their account
([13](13-disconnection.md) §13.7).

## 4.8 Who reads `link_payment_customers` now

Two readers, both re-pointed at `SiteUsersConnectionRegistry::paymentsLinked()` in this plan
so the [03](03-database-schema.md) §3.6 drop is a no-op:

- `SiteUsersPublicController::linkCustomer()` — `if (! $this->connections->paymentsLinked($user->site_users_id))` → the same `{linked:false, skipped:'payments_not_connected'}` (new skip reason; the old string is kept as an alias in the test).
- `App\Console\Commands\SiteUsersLinkCustomers` — refuses with *"Direct Payments is not connected to module X"*.

## 4.9 Module-wide connections and instance-scoped dashboards (X22)

A module-wide row is a *configuration* convenience — "connect all my events with one click".
It is not automatically a *dashboard* entry, because two of the four modules answer "what is
mine" globally and one does not:

| Module | "mine" endpoint | `dashboard.scope` |
|---|---|---|
| ecommerce | `GET /storefront/orders?store=` — **store is required** (`requireStore()` → `422`) | `instance` |
| events | `GET /storefront/registrations/mine` — no instance parameter | `global` |
| appointments | `GET /storefront/bookings/mine` — no instance parameter | `global` |
| direct_payments | `GET /me/purchases`, `/me/payment-methods` — Site Users' own, session-scoped | `global` |

So the rule, applied once, in `SiteUsersDashboardService`
([08](08-dashboard-sections.md) §8.3) rather than in each provider:

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/04-connection-registry-and-enforcement.md §4.9.
 *
 * A module-wide row for an `instance`-scoped module is EXPANDED into one
 * dashboard entry per instance, because the module's own "mine" endpoint cannot
 * be called without one. A `global`-scoped module keeps a single collapsed
 * entry — one "Events" group, not one per event, which is the whole point of
 * connecting events module-wide.
 *
 * Expansion is capped: beyond MAX_EXPANDED entries the dashboard would become a
 * store directory rather than an account. Past the cap the group collapses to a
 * single entry labelled from the registry ("Store") whose provider asks the user
 * to pick a store first — a state no tenant in this estate has, guarded because
 * "one store" is an assumption, not a fact.
 */
private const MAX_EXPANDED = 12;
```

Expansion lists instances **filtered to those the user could plausibly have used** — for
ecommerce, stores on the same `website_reference_num` as the module's own pages where that is
resolvable, else all stores — and then drops any entry with no rows *and* no
badge, so a shopper who has only ever used one of five stores sees one group.

An instance row always produces exactly one entry, expanded or not. The two cases never
double up: expansion skips any instance that already has its own connection row.

**The admin says so.** The Connections tab's *Connect all* button for ecommerce carries the
line *"Each store appears as its own section in the user's Dashboard."*
([05](05-admin-connections.md) §5.4).

## 4.10 The two protection layers have the same hole (X27)

§4.3 makes the *registry* module-aware, which covers attribution and the checkout gate. It
does not cover the two places that actually protect a members-only surface, and both of them
carry a `site_users_id` they never read:

```php
// SiteUsersApiGuard::handle() — the members-only storefront API
$user = $this->sessions->resolveFromRequest($request);          // ANY module's session
if ($guard->requires_auth && $user === null) { return 401; }    // $guard->site_users_id unused

// BuilderPublishedController::show() — the members-only page
if ($route !== null && $route->requires_auth) {
    $user = app(SiteUsersGateway::class)->currentUser($request); // ANY module's session
    if ($user === null) { /* redirect or 404 */ }                // $route->site_users_id unused
}
```

Both columns were added deliberately ([site_users/12](../site_users/12-builder-integration.md)
§12.5, §12.8.3) and neither is consulted. On a one-module deployment that is invisible. This
plan is precisely what makes two modules easy to create — the Connections tab lists them, K2's
precedence rule assumes them, and [02](02-architecture.md) §2.3's wholesale example
recommends them. So the hole opens exactly when the feature is used as intended: **anyone who
can register on the public module can read the members-only module's pages and API.**

The fix is the same one comparison, in both places:

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/04-connection-registry-and-enforcement.md §4.10 (X27, K6).
 *
 * A guard/route that names a module is satisfied only by a session belonging to
 * THAT module. A null `site_users_id` keeps today's meaning — "any signed-in
 * site user" — because that is what every existing row means and a deploy must
 * not start refusing them.
 */
$user = $this->sessions->resolveFromRequest($request);
if ($user !== null && $guard->site_users_id !== null && $user->site_users_id !== $guard->site_users_id) {
    $user = null;                 // wrong module ⇒ not signed in, here
}
```

Three consequences worth stating:

- **Null keeps meaning "any module".** Tightening the default would change behaviour for every
  row a tenant has already written, including the Dashboard-protection rows the builder writes
  automatically — which *do* carry a module, so they tighten correctly and for free.
- **The page guard's redirect improves.** `BuilderPublishedController` already flashes
  `su_intended` and redirects to `login_route`; with the comparison in place, a visitor signed
  in to the wrong module is sent to the *right* module's login instead of looping on a page
  that will not admit them.
- **`require_verified_email` is evaluated after the module test**, not before, or a foreign
  module's unverified user gets a `403` that leaks the existence of the guard.

This is **K6** work, not K3: it touches Site Users' own middleware rather than a consumer, and
it is the one change in the plan that can refuse a request that succeeds today. The audit
command reports how many guard and route rows carry a `site_users_id`, and how many sessions
exist outside it, before the phase ships.

## 4.11 Acceptance (server)

- `modeFor()` returns `off` for an instance with `site_users_mode = 'required'` in its column
  and no connection row — the column is no longer truth.
- A module-wide `events@*` row makes every event `guest`; an `events@evt-1` row with
  `guest_checkout = false` makes that one event `required` while the rest stay `guest`.
- Deactivating the Site Users module makes every connection resolve to `null`.
- A session from module B on a store connected to module A: attributed `null` in `guest`
  mode; `401` with `module: 'su-A'` in `required` mode.
- In `off` mode a signed-in checkout writes `site_user_reference = null`.
- `POST /checkout/complete` without a session on a `required` store → `401`; with a
  different session than the cart's owner → `403 EC_E_CART_OWNER_MISMATCH`.
- `POST /events/storefront/hold` and `/waitlist` on a `required` event without a session →
  `401`; same for appointments.
- `registrations/mine` and `bookings/mine` → `401` for a guest; **include** rows whose event
  or calendar has since been disconnected, and exclude rows whose connection was removed
  ([13](13-disconnection.md) §13.9).
- `publicBlock()` for an unconnected store contains neither `module` nor any path.
- Connecting the same store to a second module → `409 SU_E_INSTANCE_CONNECTED_ELSEWHERE`;
  the race variant is caught by the unique index and mapped to the same code.
- `grep -rn "currentUserId(" app/Externals/Ecommerce app/Events app/Externals/Appointments app/Http/Controllers/Portal` is empty at the end of K3.
- A module-wide `ecommerce@*` row with three stores yields three dashboard entries; the same
  row for `events@*` yields one (§4.9).
- `paymentsLinked()` is **false** for a disconnected Direct Payments connection while
  `paymentsVisible()` is **true** — the card list works and adding a card does not (X35,
  [13](13-disconnection.md) §13.6).
- An instance row plus a module-wide row for the same module yields the instance entry once,
  not twice.
