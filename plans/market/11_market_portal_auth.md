# Step 11 — Market: the `/portal` route, Checkpoint authentication, and the SDK tag on every page

**On completion:** write `complete/11_market_portal_auth_complete.md`.
**Depends on:** [02](02_environment_and_config.md).
**Blocks:** 12, 13, 14, 15.

---

## 11.1 The route file

New: `apps/market/routes/portal.php`, with a full `/** */` header and the tag.

Wired in `bootstrap/app.php` — which today declares only
`withRouting(web:, commands:, health:)` and no `then:` closure. Step 04 introduces the closure; this
step adds the second group to it:

```php
->withRouting(
    web:      __DIR__.'/../routes/web.php',
    commands: __DIR__.'/../routes/console.php',
    health:   '/up',
    then: function () {
        /** MARKET_MODIFIER_ADDITION */
        Route::middleware(['api', 'market.internal'])
            ->prefix('api/internal/v1')
            ->group(base_path('routes/internal.php'));      // step 04

        Route::middleware(['web', 'checkpoint.member'])
            ->prefix('portal')
            ->name('portal.')
            ->group(base_path('routes/portal.php'));        // this step
    },
)
```

`web` middleware because the portal is a session-backed browser surface (cart, CSRF, flash).
`name('portal.')` so every route is `route('portal.dashboard')` and no Blade file contains a URL
literal. 

Route inventory (controllers under `app/Http/Controllers/Portal/`):

| Route | Name | Purpose |
|---|---|---|
| `GET /portal` | `portal.dashboard` | Landing — purchases, recent orders |
| `GET /portal/assets` | `portal.assets` | Owned assets |
| `GET /portal/assets/{entitlement}` | `portal.assets.show` | Versions, license, downloads |
| `GET /portal/assets/{entitlement}/download/{version?}` | `portal.assets.download` | Redeems a signed URL (step 05) |
| `GET /portal/services` | `portal.services` | Purchased service orders |
| `GET /portal/services/{serviceOrder}` | `portal.services.show` | Order detail (step 15) |
| `GET /portal/orders` | `portal.orders` | Order history |
| `GET /portal/orders/{order}` | `portal.orders.show` | Receipt |
| `GET /portal/licenses` | `portal.licenses` | Every license snapshot the buyer holds |
| `GET /portal/messages` | `portal.messages` | Order messaging |
| `GET /portal/settings` | `portal.settings` | Profile, receipt email |
| `GET /portal/auth/callback` | `portal.auth.callback` | Post-Checkpoint landing |
| `POST /portal/auth/logout` | `portal.auth.logout` | Clear session, hand off to Checkpoint |

---

## 11.2 Authentication — client-side, against auth15

**Project owner's rule: market has no server-side auth implementation.** The browser owns the
session and talks to `https://auth15.appfactory.studio` directly. Market keeps:

- no Laravel `Auth` usage, no `auth` middleware, no login routes
- no `checkpoint_*` tables (members has them only because it shares the IdP's database; market has
  its own and cannot)
- no local user records — `users` stays for framework plumbing and gains no marketplace relationship
- no server-held session identity

### What already exists, client-side

| Piece | Where |
|---|---|
| Full Checkpoint client — `auth`, `authz`, `http-client`, `sso-handler`, `client-handler`, `browser-auth`, `secondary-users` | `resources/js/checkpoint/` (15 files) |
| `MARKET: 'MARKET'` + label `'Market'` | `checkpoint/app-names.ts` — already declared |
| Token storage | `localStorage` / `sessionStorage`: `checkpoint_access_token`, `checkpoint_refresh_token`, `checkpoint_token_expiry` |
| Token reader | `checkpointHelper/auth/accessToken.ts` → `getCheckpointAccessToken()` |
| Refresh + CSRF recovery | `checkpointHelper/static/sessionFetch.ts` → `fetchWithSessionRefresh()` |
| Login URL construction | `checkpoint/client-handler.ts:314` → `buildLoginUrl()`, already setting `target_domain`, `app_name`, `redirect_url` |
| auth15 endpoints the client knows | `/auth/login`, `/auth/sso/login`, `/auth/sso/exchange`, `/auth/token/refresh`, **`/auth/token/verify`**, `/auth/logout` |

So the browser half is essentially built. The work is wiring, not implementation.

### `resources/js/dashboard.ts` — fix

It is a verbatim Dream Graph copy:

```ts
window.location.href = handler.buildLoginUrl('normal', CHECKPOINT_APPS.GRAPH_EDITOR);
throw new Error('Dream Graph requires an authenticated Checkpoint session');
```

Three corrections: `GRAPH_EDITOR` → `CHECKPOINT_APPS.MARKET` (both occurrences), the two error
strings, and `new ClientHandler(checkpoint, '/')` → the portal path, so post-login lands on
`/portal`. Replace the Dream-Graph-shaped `initSettingsData()` with market's own bootstrap; keep
`updateUserAccountNav()`.

### `resources/js/portal.ts` — new Vite entry

`dashboard.ts` is **not currently built** — `vite.config.ts:7` lists only `app.css` and `app.ts`.
Add `resources/js/portal.ts`, which imports the fixed bootstrap then mounts the portal views.

```ts
input: ['resources/css/app.css', 'resources/js/app.ts', 'resources/js/portal.ts'],
```

### The page shell is public; the data is not

`GET /portal` returns a **shell** — layout, nav, empty containers. No session gate, no server
redirect. The client bootstraps, and if `handlePageLoad()` reports unauthenticated it redirects to
`{AUTH_URL}/login?...&redirect_url={MARKET_URL}/portal`.

Rendering an empty shell to an anonymous visitor is harmless because **the shell contains no data**.

> ### The one thing that cannot be client-only
>
> The dashboard shows *this buyer's* purchases, and those live in market's database. So some market
> endpoint returns them — and it must know who is asking.
>
> **The client cannot answer that question about itself.** If `GET /portal/api/assets` accepts a
> `member_reference_num` from the browser, anyone can enumerate anyone's purchases, licenses, order
> history and download URLs by changing one parameter.
>
> This is not a server-side *auth system* — no sessions, no users, no password handling, nothing
> stored. It is **one verification call** to an endpoint the Checkpoint client already knows.
>
> #### The verified contract (read from `apps/auth`, not assumed)
>
> `POST|GET {AUTH_URL}/auth/token/verify` → `AuthController::validateToken()` →
> `AuthenticationService::verifyToken()` → `attachMember()` → `MemberLookupService::fetchByUserId()`.
>
> ```json
> {
>   "success": true,
>   "data": {
>     "valid": true,
>     "user_id": 123,
>     "user": { "id": 123, "email": "…", "username": "…", "status": 1, "roles": [] },
>     "expires_at": 1767225600,
>     "member": { "…full brain_memberships row…", "reference_num": "m1xJppRmdtH2Y", "json_data": {} }
>   }
> }
> ```
>
> **`data.member.reference_num` is the value market needs.** Three properties of this endpoint will
> cost time if not designed for:
>
> **1 — It returns HTTP 200 on failure.** `validateToken()` catches the exception and responds
> `{"success": false, "data": {"valid": false, "error": "…"}}` with a **200 status**. So
> `$res->successful()` is *not* a validity check — an expired or forged token passes it. Assert
> `data.valid === true`.
>
> **2 — `member` can be `null`, and right now that is the likely case.** `attachMember()` only
> throws `AUTH_MEMBER_NOT_FOUND` when `$lookup->isProduction()`; otherwise it swallows the miss and
> sets `member => null`. Everything is currently non-production (step 01 §01.2.4), so a **valid**
> token for a user with no `brain_memberships` row verifies successfully with no member attached.
> Market must reject that explicitly — otherwise it proceeds with a null reference and either errors
> obscurely or, worse, matches rows where `member_reference_num IS NULL`.
>
> **3 — It returns `SELECT * FROM brain_memberships`.** The whole row, whatever columns that holds.
> Extract `reference_num` and discard the rest; do not persist it, do not log it, do not pass it to
> the browser.
>
> ```php
> /** MARKET_MODIFIER_ADDITION */
> // app/Http/Middleware/VerifyCheckpointToken.php — data endpoints only, never the shell
> $token = $request->bearerToken();
> abort_if(!$token, 401);
>
> $res = Http::asJson()->post(config('market.urls.auth') . '/auth/token/verify', ['token' => $token]);
>
> // 200-on-failure: the status tells you nothing. The payload does.
> abort_unless($res->json('data.valid') === true, 401);
>
> $memberRef = $res->json('data.member.reference_num');
> abort_if(empty($memberRef), 403, 'Authenticated user has no membership record.');
>
> $request->attributes->set('member_reference_num', $memberRef);   // the ONLY source
> ```
>
> Cache per token until `data.expires_at`, so this is one call per session rather than per request.
>
> If this is genuinely unwanted, the alternative is that market's portal holds no private data
> server-side and fetches everything from a service that does verify. That is a larger change than
> the middleware, so it should be a deliberate choice rather than a default.

### Client requests carry the token

Portal data calls go through `fetchWithSessionRefresh()` (already written) with
`Authorization: Bearer ${getCheckpointAccessToken()}`. The existing 419/refresh recovery comes free.

### Routing

```php
Route::middleware('web')->prefix('portal')->group(base_path('routes/portal.php'));
```

`web` for the cart's session and CSRF — **not** for identity. Inside that file, the shell route is
ungated; the data routes carry the token-verify middleware.

### Logout

Client-side: clear tokens, call `handler.logout()`, redirect to the storefront. Market has no session
to destroy and must not try to invalidate the IdP's.

## 11.3 The `$afssdk` bug

`layouts/app.blade.php:15` already renders:

```blade
<script src="https://{{ $afssdk }}.appfactory.studio/sdk.js"></script>
```

But `$afssdk` is passed by `HomeController::index()` **only**. `ServiceController`,
`AssetController` and `PurchaseController` do not pass it — so on `/services`, `/assets` and, most
consequentially, `/checkout/*`, the tag renders as
`<script src="https://.appfactory.studio/sdk.js">`. That is a live broken script tag on the checkout
page today, and step 13's entire checkout depends on that script having loaded.

**Fix: a view composer, not four controller edits.**

`app/View/Composers/MarketConfigComposer.php`, registered in `AppServiceProvider` against `*`:

```php
$view->with('afssdk', config('market.sdk.host'));
$view->with('marketConfig', [
    'brain_url'           => config('market.urls.brain'),
    'market_url'          => config('market.urls.market'),
    'auth_url'            => config('market.urls.auth'),
    'portal_path'         => config('market.portal.path'),
    'direct_payment_ref'  => config('market.direct_payments.ref'),
    'dp_http_url'         => config('market.direct_payments.http_url'),
    'currency'            => config('market.commerce.default_currency'),
]);
```

Every view gets both, always. A new controller cannot forget.

Also replace `HomeController.php:33`'s `env('APP_ENV') === 'production' ? 'api' : 'api-sandbox'`
with the config value — per step 02, `env()` outside `config/` returns `null` under
`config:cache`, which would make the SDK tag break in production in exactly the environment where
it matters most.

The layout gains, immediately before the SDK script:

```blade
{{-- MARKET_MODIFIER_ADDITION --}}
<script>
  window.Portal = window.Portal || {};
  window.Portal.httpUrl = @json($marketConfig['dp_http_url']);
  window.MarketConfig  = @json($marketConfig);
</script>
```

`window.Portal.httpUrl` must be set **before** `sdk.js` loads or the SDK initializes against the
wrong origin. Ordering is not cosmetic here.

---

## 11.4 Portal layout

`resources/views/portal/layouts/portal.blade.php` — separate from the storefront layout: different
nav, no "Become a seller" CTA, an authenticated user chip. It includes the same
`$afssdk` + `window.Portal` head block.

Sidebar: Dashboard · My Assets · My Services · Orders · Licenses · Messages · Settings, plus a
"Sell on Market" link out to `MEMBERS_URL` (from config).

---

## 11.5 Logout

`POST /portal/auth/logout` clears market's session, then hands off to the Checkpoint client's
`handler.logout()` and redirects to the storefront root. Market must not attempt to invalidate the
IdP session itself.

---

## 11.6 Files

> **Tagging (step 01 §01.4) — every file below.** Created files carry
> `MARKET_MODIFIER_ADDITION` in their top doc block; modified files carry it at each changed line or
> region. `grep -rn MARKET_MODIFIER_ADDITION` must return this list in full, or the change index is
> already wrong.


**Created:** `routes/portal.php`, `app/Http/Controllers/Portal/*`,
`app/Http/Middleware/VerifyCheckpointToken.php` (the ~30-line token-verify middleware — **not** a
session or user system), `app/View/Composers/MarketConfigComposer.php`, `resources/js/portal.ts`,
`resources/views/portal/layouts/portal.blade.php`.

**Modified:** `bootstrap/app.php`, `vite.config.ts`, `resources/js/dashboard.ts`,
`resources/views/layouts/app.blade.php`, `app/Http/Controllers/HomeController.php`,
`app/Providers/AppServiceProvider.php`.

---

## 11.7 Verification

- [ ] `/portal` while signed out renders the shell and the **client** redirects to `{AUTH_URL}/login` with `app_name=MARKET` and `redirect_url={MARKET_URL}/portal`. No server-side redirect, no Laravel session involved.
- [ ] Market has no `checkpoint_*` table, no `auth` middleware, and no local user record for any buyer.
- [ ] A portal **data** endpoint called with no bearer token → `401`. With another member's token → that member's data only.
- [ ] Appending `?member_reference_num=…` to any portal data endpoint changes nothing — the reference comes from token verification, never from the request.
- [ ] The verified member reference is cached per token until `data.expires_at`, not re-verified per request.
- [ ] An **expired or forged** token is rejected — verified against the 200-on-failure behaviour, by asserting the middleware reads `data.valid` and not the HTTP status.
- [ ] A valid token whose user has **no `brain_memberships` row** (`member: null`, the common non-production case) is rejected with `403`, not treated as an anonymous or null-matching member.
- [ ] Nothing from the returned membership row other than `reference_num` is persisted, logged, or sent to the browser.
- [ ] After login, the browser lands on `/portal` authenticated.
- [ ] `grep -rn "GRAPH_EDITOR\|Dream Graph" apps/market/resources/js` → zero.
- [ ] View source on `/`, `/services`, `/assets`, `/checkout/asset/1`, `/portal`: **every one** has a fully-formed `sdk.js` src with no empty subdomain.
- [ ] `window.Portal.httpUrl` is set before `sdk.js` executes (check ordering in the served HTML, not just the template).
- [ ] `php artisan config:cache` then reload every page: `$afssdk` still resolves. This is the `env()`-trap test.
- [ ] `/portal/assets/{entitlement}` for an entitlement belonging to another member → `403`/`404`, never the data.
- [ ] Appending `?member_reference_num=m1xTHpRmdtH2Y` to any portal URL changes nothing.
- [ ] `npm run build` emits the `portal.ts` bundle.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/11_market_portal_auth_complete.md` written.
