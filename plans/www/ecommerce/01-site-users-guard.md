# 01 — Guarding Ecommerce with Site Users

Making a tenant's shop respect who is signed in — and closing what the analysis found on the
way there.

> ### Extended by [`../connections/`](../connections/README.md) (2026-09-12)
>
> P0–P3 shipped ([complete/01](complete/01-site-users-guard.complete.md),
> [complete/07](complete/07-site-users-integration.complete.md)). What changed since:
>
> - **§1.5.2's `stores.site_users_mode` / `site_users_id` columns stop being truth.** The
>   mode derives from a connection row; the columns are written through for one release,
>   then dropped ([connections/03](../connections/03-database-schema.md) §3.5).
> - **§1.5.3's store-settings panel is replaced by a read-only state line plus a shortcut**
>   into Site Users → Connections
>   ([connections/05](../connections/05-admin-connections.md) §5.6). The three modes and the
>   plain-language copy survive; the select moves.
> - **§1.4's enforcement gains `POST /checkout/complete`** — gated today only at
>   `begin` ([connections/04](../connections/04-connection-registry-and-enforcement.md) §4.5).
> - **The `required` mode becomes usable**: an inline sign-in gate at checkout with
>   *Continue as guest* shown only when the connection allows it
>   ([connections/06](../connections/06-checkout-guard.md)).
> - **P4's members-only shop is unchanged** — route protection plus an API guard row, both
>   or neither (§1.5.4).
>
> §1.4's attribution rule and ownership filter, and §1.7's acceptance list, are unchanged.

> ### 🔴 Read §1.1 before anything else
>
> `/portal/api/ecommerce/*` is **not** behind authentication. Its only wrapper is
> `portal.cors`, which is an explicit no-op. That includes `POST /product`,
> `DELETE /product/{ref}`, `POST /store` and **`DELETE /store/{ref}`**.
>
> Anyone on the internet can delete a tenant's store. That is not a Site Users problem and it
> does not wait for this plan.

## 1.1 What the analysis found

### E1 — the ecommerce API is unauthenticated, including writes

`routes/portal.php:600`:

```php
Route::prefix('api/ecommerce')->group(function () {
    Route::get   ('/categories',                 [EcommerceController::class, 'getCategories']);
    Route::post  ('/category',                   [EcommerceController::class, 'saveCategory']);
    Route::delete('/category/{reference_num}',   [EcommerceController::class, 'deleteCategory']);
    Route::get   ('/products',                   [EcommerceController::class, 'getProducts']);
    Route::post  ('/product',                    [EcommerceController::class, 'saveProduct']);
    Route::delete('/product/{reference_num}',    [EcommerceController::class, 'deleteProduct']);
    Route::post  ('/store',                      [EcommerceController::class, 'createStore']);
    Route::get   ('/stores',                     [EcommerceController::class, 'getStores']);
    Route::delete('/store/{reference_num}',      [EcommerceController::class, 'deleteStore']);
});
```

The enclosing group is `Route::middleware('portal.cors')` (`routes/portal.php:75`), and
`PortalCorsMiddleware::handle()` is:

```php
public function handle(Request $request, Closure $next): Response
{
    return $next($request);      // its own docblock: CORS is handled by Nginx
}
```

**There is no `ApiAuthMiddleware` anywhere in that chain.** Verified by walking the brace
depth from line 128 to line 600 — the two `ApiAuthMiddleware` groups above it close at 155
and 202.

And the handlers do exactly what their names say, with no ownership check:

```php
public function deleteStore(Request $request, $reference_num) {
    $deleted = EcommerceStore::where('reference_num', $reference_num)->delete();
    return response()->json(['success' => (bool)$deleted]);
}
```

The findings are **E1**–**E4**, defined with the rest in
[02](02-current-state-audit.md) §2.2: no authentication on the group (**E1**), `getStores()`
returning every store on the deployment (**E2**), `saveProduct()` overwriting any product by
name (**E3**), and deletes with no ownership check (**E4**).

**E1 is a live, unauthenticated, destructive vulnerability and it is not this plan's
dependency — it is its prerequisite.** §1.6 phases it first, ahead of every Site Users
concern.

### E19 — orders have no owner

`laravel_ecommerce_orders` has no `site_user_id`; the only identity on an order is
`customer_email`, free text. Defined in [02](02-current-state-audit.md) §2.5 — "my orders" is
impossible, order history cannot survive a device change, and a returning shopper cannot be
shown their own purchases. §1.4 is the fix.

## 1.2 What guarding actually means here

Four distinct things, commonly conflated, each with a different enforcement point:

| Goal | Enforced by | Ecommerce's work |
|---|---|---|
| The **admin** API is not public | `ApiAuthMiddleware` (**E1**) | Route change only |
| A **members-only shop page** is not served to strangers | Route protection ([site_users/12](../site_users/12-builder-integration.md) §12.5) | **None** |
| The **storefront API** behind it is not readable by strangers | The Site Users API guard ([site_users/12](../site_users/12-builder-integration.md) §12.8) | **None** — a config row |
| A shopper sees **their own** orders | Ecommerce itself | §1.4 — the only real integration |

The first is a bug fix. The middle two are infrastructure that already exists in the Site
Users plan. **Only the fourth is ecommerce code**, and it is small.

## 1.3 Splitting the API — the change E1 forces

The current group mixes two audiences under one prefix, which is why it ended up with the
wrong middleware. Fixing **E1** properly means separating them:

| Prefix | Contents | Auth |
|---|---|---|
| `/portal/api/ecommerce/*` | **Admin only** — save/delete product, category, store | `ApiAuthMiddleware` |
| `/api/ecommerce/storefront/*` | **Public read** — products, categories, store info | Open by default; guardable (§1.5) |
| `/api/ecommerce/orders/*` | **Shopper** — place an order, list *my* orders | Site Users session required |

The storefront read endpoints are the ones a published page actually calls, and they are
**deliberately open by default**: a shop is public unless the tenant says otherwise, and
default-closed would break every existing storefront on deploy.

`resources/js/external/commerce/api.ts` currently hardcodes
`baseUrl = '/portal/api/ecommerce'`. It moves to the storefront prefix — and per the
externals contract, **`portal.d.ts` is updated in the same pass**
([site_users/07](../site_users/07-externals-api.md) §7.6), with `members`' copy refreshed if
it declares the commerce namespace.

The old paths remain as deprecated aliases **for reads only** for one deprecation window
([site_users/02](../site_users/02-the-rename.md) §2.3's pattern) — published pages are
calling them today. **The write paths get no alias**; they move behind auth immediately,
because leaving a deprecated unauthenticated `DELETE /store/{ref}` for a release cycle is
the vulnerability, not the fix for it.

## 1.4 The only ecommerce code that changes

### Attribution

```php
$table->unsignedBigInteger('site_user_id')->nullable()->index();
```

Nullable, on `laravel_ecommerce_orders` (**E19**). Nullable because guest checkout stays
supported and existing orders have no user
([site_users/09](../site_users/09-app-integrations.md) §9.3).

Written at checkout from the guard-populated request attribute — **never** from the request
body:

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/ecommerce/01-site-users-guard.md §1.4.
 *
 * The signed-in shopper, or null for a guest. Set by SiteUsersApiGuard
 * (site_users/12 §12.8.5); ecommerce imports nothing and knows nothing about
 * how it got there.
 *
 * NEVER read a user id from the request body. A client-supplied owner is how
 * one shopper's order gets attributed to another's account.
 */
$user = $request->attributes->get('site_user');
$order->site_user_id = $user?->id;
```

### Ownership filtering — the part a guard cannot do

```php
// GET /api/ecommerce/orders  — "my orders"
$user = $request->attributes->get('site_user');
$orders = EcommerceOrder::where('site_user_id', $user->id)->get();
```

That `where` is the entire authorisation story, and it is why ecommerce cannot be *fully*
generic: the API guard proves **someone** is signed in; only ecommerce knows that *this*
order belongs to *that* shopper.

**Authentication is generic. Authorisation is not.** A middleware that tried to do the second
would have to understand every module's ownership model, which is the per-module integration
this design exists to avoid.

### Order lookup by reference

`GET /api/ecommerce/orders/{reference_num}` must check ownership too:

```php
$order = EcommerceOrder::where('reference_num', $ref)->first();
if (! $order) abort(404);
if ($order->site_user_id && $order->site_user_id !== $user?->id) abort(404);
```

**404, not 403** — a 403 confirms the order exists. And the `$order->site_user_id &&` guard
keeps guest orders reachable by whoever holds the reference, which is how a guest checkout
receipt link has to work.

That third line is the one to get right. Written as `!==` without the null check, every guest
order becomes unreachable, including by the person who just placed it.

## 1.5 Authentication is optional, and off until a tenant turns it on

**Guest checkout is the baseline, not a fallback.** A shop with Site Users switched off
behaves exactly as it does today: a visitor browses, adds to a cart, pays, and never sees an
account. That is the correct default for most shops and it must not require any
configuration to keep working.

Everything below is opt-in.

### 1.5.1 The modes

| Mode | Storefront | Checkout | Orders |
|---|---|---|---|
| `off` **(default)** | Public | Guest only | No attribution. **Today's behaviour, unchanged** |
| `guest` | Public | Guest **or** signed in | Attributed when signed in; anonymous orders still allowed |
| `required` | Public *(or guarded — §1.5.4)* | **Signed in only** | Always attributed |

`guest` is the mode most shops that adopt this will want: a returning customer gets order
history and saved cards, and a first-time buyer is never blocked at the till. **Requiring an
account to buy something costs conversions**, and a tenant should reach `required` on purpose
rather than by following a setup wizard.

`required` rejects an unauthenticated checkout **in the API**, not the template:

```php
if ($mode === 'required' && ! $request->attributes->get('site_user')) {
    return response()->json(['error' => 'Sign in to complete your order.',
                             'code' => 'EC_E_AUTH_REQUIRED'], 401);
}
```

A hidden checkout button is not a mode
([site_users/12](../site_users/12-builder-integration.md) §12.6).

### 1.5.2 The mode is per **store**, not per module

`laravel_ecommerce_stores` is per store, and a tenant may run more than one on a site — a
public retail shop and a members-only wholesale catalogue are a normal pair.

So the mode lives on the store:

```php
$table->string('site_users_mode', 20)->default('off');       // off | guest | required
$table->string('site_users_id', 32)->nullable();             // which module, when not 'off'
```

Two levels, doing different jobs:

| Level | Holds | Where |
|---|---|---|
| **Module registry** (code) | *What ecommerce supports* — `['guest','required']`, `user_column: site_user_id` | [site_users/09](../site_users/09-app-integrations.md) §9.2 |
| **Store row** (data) | *What this store does* | Here |

The registry is a developer statement; the store row is a tenant decision. Conflating them is
how a per-module toggle ends up unable to express the wholesale case.

### 1.5.3 Where a tenant turns it on

**In the ecommerce admin section, on the store — not in a central Site Users screen.**

A tenant configuring their shop is in `Ecommerce → Store`. Making them leave for a separate
module to switch on accounts is how a feature stays undiscovered, and it inverts the mental
model: they are not configuring Site Users, they are deciding how their *shop* works.

```
Store settings — Wholesale
──────────────────────────────────────────────
  Name            Wholesale
  Direct Payment  [ Wholesale Checkout ▾ ]

  Customer accounts
    ( • ) Off — anyone can buy as a guest
    (   ) Optional — shoppers may sign in; guests can still buy
    (   ) Required — shoppers must sign in to check out

    Site Users module   [ Main (su-a1b2) ▾ ]

  ⓘ "Required" blocks guest checkout. Most shops should use
    "Optional" — returning customers get order history and
    saved cards, and first-time buyers are not turned away.
──────────────────────────────────────────────
```

The module select appears only once a mode other than `off` is chosen, and lists the modules
that actually exist — with a link to create one when there are none, rather than an empty
dropdown.

**This panel shape is the convention for every module** that adopts Site Users
([site_users/09](../site_users/09-app-integrations.md) §9.2): the same three modes, the same
module select, in that module's own admin section. Events configures it under an event,
appointments under a booking calendar. A tenant learns it once.

### 1.5.4 A members-only shop

The modes govern **checkout**. Hiding the **catalogue** is a separate decision, and it is
configuration rather than ecommerce code:

1. Protect the shop route ([site_users/12](../site_users/12-builder-integration.md) §12.5) — the page is
   never served.
2. Guard the storefront read paths ([site_users/12](../site_users/12-builder-integration.md) §12.8) — the
   API returns 401.

**Both, or neither.** Protecting the page alone leaves the product API readable, which is the
more dangerous state because it looks finished. The builder's "Also protect this page's data"
checkbox ([site_users/12](../site_users/12-builder-integration.md) §12.8.7) writes both in one action for
exactly that reason.

## 1.6 Build order

| Phase | Contents | Ships behind |
|---|---|---|
| **P0 — close E1** | Split the routes (§1.3). Admin writes behind `ApiAuthMiddleware`; storefront reads public; **no alias for writes** | Nothing. **Not optional, not schedulable** |
| **P0b — scope the queries** | `getStores()` stops being `::all()` (**E2**); save/delete check ownership (**E3**, **E4**) | Ships with E0 — auth without scoping still lets one authenticated tenant delete another's rows |
| **P1 — attribution** | `site_user_id` column, written at checkout from the request attribute | Nullable; no behaviour change |
| **P2 — my orders** | The orders endpoints with ownership filtering (§1.4) | New endpoints |
| **P3 — modes** | `off`/`guest`/`required` on the store, the registry entry, and the store-settings panel (§1.5.3) | Default `off` — **no shop changes behaviour until a tenant opts in** |
| **P4 — members-only shops** | Route + API guard config in the builder panel | Per tenant |

**P0 and P0b do not depend on Site Users at all** and should ship on their own schedule —
immediately. Everything from P1 onward depends on
[site_users](../site_users/README.md) P2 (sessions) and P8d (the API guard).

## 1.7 Acceptance

**P0 / P0b — the prerequisite**

- Unauthenticated `DELETE /portal/api/ecommerce/store/{ref}` returns **401** (**E1**).
- Same for `POST /product`, `DELETE /product/{ref}`, `POST /store`, `POST /category`,
  `DELETE /category/{ref}`.
- An authenticated caller cannot delete a store they do not own (**E4**).
- `getStores()` returns only the caller's stores (**E2**).
- `saveProduct()` with another store's `reference_num` is refused (**E3**).
- A published storefront page still loads products through the aliased read path.

**Site Users integration**

- `grep -rn "SiteUser\|site_user" app/Http/Controllers/Externals/Ecommerce/EcommerceController.php`
  finds **only** `$request->attributes->get('site_user')` — no auth logic in the module.
- A signed-in checkout writes `site_user_id`; a guest checkout writes null.
- `GET /api/ecommerce/orders` returns **only** the caller's orders — verified with two
  shoppers.
- `GET /api/ecommerce/orders/{ref}` for another shopper's order returns **404**, not 403.
- A **guest** order remains reachable by reference (§1.4).
- `required` mode rejects unauthenticated checkout **from `curl`**, not just in the UI.
- A members-only shop with the page guarded but the API not is **caught** — a test asserts
  the storefront endpoints return 401 when the route is protected.
- **With mode `off` — the default — a shop behaves exactly as today.** Guest checkout works,
  no gateway calls, no extra queries, nothing to configure.
- Two stores on one site can hold different modes (§1.5.2).
- The module select is hidden while the mode is `off`, and offers a create link when no
  module exists.
- A signed-in shopper's second purchase reuses their provider customer
  ([site_users/08](../site_users/08-direct-payments-link.md)) and their saved cards appear.
