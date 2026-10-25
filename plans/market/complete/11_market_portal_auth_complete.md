# Step 11 — Market: the `/portal` route, Checkpoint authentication, and the SDK tag on every page — completion report

**Plan:** [11_market_portal_auth.md](../11_market_portal_auth.md)
**Depends on:** [02](../02_environment_and_config.md) (complete).
**Blocks:** 12, 13, 14, 15.

---

## What shipped

### §11.1 — the route file

`routes/portal.php`, wired in `bootstrap/app.php`'s `then:` closure alongside step 04's internal-API group, under `Route::middleware('web')->prefix('portal')->name('portal.')`.

**A plan inconsistency resolved, documented up front**: §11.1's own worked `bootstrap/app.php` snippet shows this group registered as `['web', 'checkpoint.member']`. No `checkpoint.member` alias is defined anywhere else in the plan, and it directly contradicts §11.2's detailed, reasoned rule — "the page shell is public; the data is not... no session gate, no server redirect [at the shell]." Read as a copy-paste of step 04's own shape (where `market.internal` genuinely is a real alias) rather than a literal instruction. Registered as `'web'` only, exactly matching §11.2's own routing section. Noted in `bootstrap/app.php`'s own comment.

Thirteen page routes (dashboard, assets ×3 incl. download, services ×2, orders ×2, licenses, messages, settings, auth callback/logout) plus one new route this step added: `GET /portal/api/whoami`, gated by `checkpoint.verify` — see "A scope decision" below for why.

### §11.2 — client-side auth, and three real bugs found along the way

Confirmed the Checkpoint client infrastructure was, as the plan says, "essentially built" — `resources/js/checkpoint/` (15 files), `CHECKPOINT_APPS.MARKET` already declared, token storage/reader/refresh already written. Fixed exactly what the plan named in `dashboard.ts` (renamed/exported as `bootstrapMarketAuth()` rather than a bare module-level side effect — see "Decisions" below for why) — both `GRAPH_EDITOR` references, the two error strings, and the `ClientHandler` constructor's page path (now reads `window.MarketConfig.portal_path`, itself set by `MarketConfigComposer`, rather than a literal).

**Three additional Dream-Graph/Design-Flow copy-paste bugs found while reading the surrounding files** — not named in the plan, but in the same family and directly blocking this step's own goal (a working login):

1. `resources/js/checkpointHelper/app.ts` — `initializeCheckpointClient(CHECKPOINT_APPS.FLOW_EDITOR)`. This is the Checkpoint client's own app-scoping identity, used on *every* server-side call it makes — left wrong, this app would have identified itself to auth15 as Design Flow on every request, including the one this step's own middleware depends on. Fixed to `MARKET`.
2. `resources/js/settings/config.ts` — `SETTINGS_APP_NAME = CHECKPOINT_APPS.GRAPH_EDITOR`. `auth-display.ts`'s account-chip role label (§11.4's own "authenticated user chip") reads a secondary user's role via `app.window.user.apps[SETTINGS_APP_NAME]`; left wrong, every secondary user's role would look up under the wrong app's grant and always render empty. Fixed to `MARKET`.
3. **Two Vite config files, one silently shadowing the other.** `vite.config.js` *and* `vite.config.ts` both existed (same "initial commit", confirmed via `git log`) — near-identical, but only the `.js` one predates this step's `input` array. Vite prefers `.js` over `.ts` when both exist, so **every edit to `vite.config.ts` — including the plan's own instruction to add `portal.ts` — was silently a no-op** until this was found. Confirmed live: `npm run build` kept emitting only 2 modules (app.css/app.ts) after editing `.ts`, and only started bundling `portal.ts` once the stale `.js` copy was deleted. The plan's own §11.2 text refers to "`vite.config.ts:7`" by name, suggesting its author also believed `.ts` was already the active file. Deleted `vite.config.js`; `.ts` is now the single source of truth.

Beyond code: `CHECKPOINT_APP_ID` and `CHECKPOINT_SSO_DOMAIN` were **entirely missing** from market's `.env` (step 02 never added them), and `CHECKPOINT_SERVER_URL` was present but blank. `initializeCheckpointClient()` has no fallback for an empty `appId` — every Checkpoint client call would have gone out with nothing identifying which app it was. Added, matching the same shared platform Parse/Checkpoint app id every other app in this project already uses (confirmed against `apps/members/.env`'s own `CHECKPOINT_APP_ID`/`PARSE_APP_ID`), not a market-specific value.

**A scope decision, made explicit**: §11.2's own illustrative example (`GET /portal/api/assets`) is not built as a full data layer in this step. `SignedDownloadUrlService`'s own header comment already assigns "the /portal dashboard" as **step 12's** consumer, and `PublicDownloadController::redeem()`'s `'entitlement_id' => null` line is commented "step 12/18 set this once wired" — both written before this step, both already deferring the real entitlement-scoped reads to step 12. Building a differently-shaped data layer here that step 12 would likely redesign anyway would cost more than it saves. What this step ships instead: `GET /portal/api/whoami`, a minimal, real, gated endpoint returning nothing but the verified `member_reference_num` — proof `VerifyCheckpointToken` works end to end, wired into the dashboard shell's own JS (`portal.ts`) so it is a working demonstration, not a theoretical middleware nobody calls. All 13 page routes from §11.1's own table are plain, ungated shells; each `Portal/*` controller's header comment states which future step owns its real content.

`app/Http/Middleware/VerifyCheckpointToken.php` implements the exact contract read from `apps/auth` (not assumed): `AuthController::validateToken()` → `AuthenticationService::verifyToken()` → `attachMember()`, confirmed by reading all three directly. All three named gotchas handled: 200-on-failure (`data.valid` checked, never `$response->successful()`), `member: null` on a valid token with no `brain_memberships` row rejected explicitly with 403 (not treated as anonymous or matched against a null reference), and only `reference_num` extracted from the full row the endpoint returns. Cached per token (hashed, never the raw token as a visible cache key) with a TTL computed from `data.expires_at` — `Cache::remember()` doesn't fit here since its TTL must be fixed *before* the callback runs, but the right TTL varies per token, so this checks the cache manually and writes back with a computed TTL.

### §11.3 — the `$afssdk` bug

Confirmed exactly as described: `HomeController::index()` was the only controller passing `$afssdk`; `ServiceController`, `AssetController`, `PurchaseController` did not. `MarketConfigComposer` (`View::composer('*', ...)`, registered in `AppServiceProvider::boot()`) closes this for every current and future view. `HomeController.php`'s `env('APP_ENV') === 'production' ? 'api' : 'api-sandbox'` replaced with `config('market.sdk.host')`.

### §11.4 — portal layout

`resources/views/portal/layouts/portal.blade.php` — separate from the storefront layout, sidebar (Dashboard/My Assets/My Services/Orders/Licenses/Messages/Settings), "Sell on Market" linking to `config('market.urls.members')`, no "Become a seller" CTA, an authenticated-user chip populated by the existing `updateUserAccountNav()` (same DOM contract, no changes needed there). Same `window.Portal`/`sdk.js` head block as the storefront layout, ordering verified live (see below).

### §11.5 — logout

Client-side: `window.__portalLogout` (registered by `portal.ts`) calls `checkpointLogout(false)` then redirects to `/`. `POST /portal/auth/logout` exists as a plain fallback for a non-JS `<form>` and does nothing but redirect — market has no session to destroy.

---

## Files created

| File | Purpose |
|---|---|
| `routes/portal.php` | The 13 page routes + `GET /portal/api/whoami` |
| `app/Http/Controllers/Portal/DashboardController.php` | Shell + `whoami()` |
| `app/Http/Controllers/Portal/AssetController.php` | Shells (index/show/download — download deferred to step 12) |
| `app/Http/Controllers/Portal/ServiceController.php` | Shells |
| `app/Http/Controllers/Portal/OrderController.php` | Shells |
| `app/Http/Controllers/Portal/LicenseController.php` | Shell |
| `app/Http/Controllers/Portal/MessageController.php` | Shell |
| `app/Http/Controllers/Portal/SettingsController.php` | Shell |
| `app/Http/Controllers/Portal/AuthController.php` | `callback()`, `logout()` |
| `app/Http/Middleware/VerifyCheckpointToken.php` | The token-verify middleware |
| `app/View/Composers/MarketConfigComposer.php` | `$afssdk`/`$marketConfig` on every view |
| `resources/js/portal.ts` | The `/portal` Vite entry |
| `resources/views/portal/layouts/portal.blade.php` | Portal layout |
| `resources/views/portal/dashboard.blade.php` | Dashboard shell + whoami demo |
| `resources/views/portal/{assets,services,orders}/{index,show}.blade.php`, `licenses.blade.php`, `messages.blade.php`, `settings.blade.php` | Placeholder shells (9 files) |
| `resources/views/portal/auth/callback.blade.php` | Callback landing shell |
| `lang/en/portal.php` | `portal.*` strings (English only — matches this app's existing no-cross-locale-parity convention) |

## Files modified

| File | Change |
|---|---|
| `bootstrap/app.php` | `portal.php` route group registered (`web` only — see "Decisions"); `checkpoint.verify` middleware alias added |
| `vite.config.ts` | `portal.ts` added to `input` |
| `resources/js/dashboard.ts` | Rewritten per §11.2's three named fixes, exported as `bootstrapMarketAuth()` |
| `resources/views/layouts/app.blade.php` | `window.Portal`/`window.MarketConfig` block added before `sdk.js` |
| `app/Http/Controllers/HomeController.php` | `env()` → `config('market.sdk.host')` |
| `app/Providers/AppServiceProvider.php` | `MarketConfigComposer` registered |
| `.env` (root only — `_env/dev`/`_env/prod` never touched) | `CHECKPOINT_APP_ID`, `CHECKPOINT_SSO_DOMAIN`, `CHECKPOINT_SERVER_URL` (was blank), their `VITE_` mirrors |
| `resources/js/checkpointHelper/app.ts` | `FLOW_EDITOR` → `MARKET` (found bug, §11.2 family) |
| `resources/js/settings/config.ts` | `GRAPH_EDITOR` → `MARKET` (found bug, §11.2 family) |
| `package.json`/`package-lock.json` | `accesscontrol@^2.2.1`, `parse@^8.1.0` added (found missing deps, see "Decisions") |

## Files deleted

| File | Reason |
|---|---|
| `vite.config.js` | Stale duplicate silently shadowing `vite.config.ts` — see §11.2's write-up above |

---

## Decisions made during execution that diverge from the plan

1. **The `bootstrap/app.php` route group carries `web` only, not `['web', 'checkpoint.member']`** — see §11.1's write-up above.
2. **`dashboard.ts`'s bootstrap logic is exported as `bootstrapMarketAuth()`, not run as a bare module-level side effect** (the file's pre-existing shape). `portal.ts` is a second, new caller of the same file — a second independent `handlePageLoad()` call from two different modules on the same page load risks consuming a one-time SSO ticket twice. Exporting a single function `portal.ts` calls once is the only way to add a second consumer safely.
3. **Two missing npm dependencies (`accesscontrol`, `parse`) installed.** Both are transitively required by `resources/js/checkpoint/`'s barrel file (`checkpoint/index.ts` re-exports `alloc_v2.ts`, which statically imports `parse`; `authz.ts` imports `accesscontrol`) — `dashboard.ts`/`app.ts` never previously imported anything reaching that barrel, so the build never needed them until `portal.ts` did. Versions matched to what `apps/members/package.json` already pins for the same shared client library, not guessed.
4. **`GET /portal/api/whoami` added — not in §11.1's own route table.** See "A scope decision" above.
5. **The 9 non-dashboard page routes render honest "coming soon" placeholders**, each controller's header comment naming the step that owns its real content (12, 13, 15, or 16, per what already exists in the codebase's own forward-referencing comments — `SignedDownloadUrlService`, `AssetDownload::create()`, `FreelanceController.php`'s `requestWithdrawal()` all already point at specific future steps for adjacent work).
6. **`lang/en/portal.php` is English-only.** Confirmed via `de`/`es`/`fr`/`zh-CN`'s existing key counts (~250 each vs. `en`'s ~840+) that this app does not maintain cross-locale parity for newer strings — consistent with the existing pattern, not a new gap introduced here.

---

## Known gaps / deferred

1. **No local database this session** (as with every step depending on a live MySQL connection) — `HomeController`/`ServiceController`/`AssetController`/`PurchaseController`'s pages could not be rendered end-to-end to visually confirm the `sdk.js` fix on the storefront specifically. What *was* confirmed live: `MarketConfigComposer` runs successfully before the DB query fails (the 500 is a pure `SQLSTATE[HY000] [2002] Connection refused`, confirmed by reading the actual stack trace, not a composer/config error) — and the identical composer mechanism is proven working on every portal page, which needs no DB. Given `View::composer('*', ...)` applies uniformly, there is no code path by which the storefront views would behave differently.
2. **Caching behavior verified by code review, not by observing a real cache hit.** `php artisan serve`'s built-in server spawns a fresh PHP process per request, so even switching to the `array` cache driver locally (to work around the unavailable database — see "How this was verified") does not persist across separate curl requests the way `database`/`redis` would in a real deployment. The `Cache::get`/`Cache::put` structure was reviewed directly rather than observed via a timing difference.
3. **No live Checkpoint session available** — the whoami endpoint's *success* path (a genuinely valid token → 200 with the real `member_reference_num`) was not observed; only the two rejection paths (missing token → 401, invalid/garbage token → 401 via a real network call to `auth15.appfactory.studio`) were. Login itself (SSO ticket exchange, redirect back to `/portal`) needs a real browser session against a real Checkpoint deployment.
4. **`app-names.ts`'s `GRAPH_EDITOR` entries are not bugs** — flagging this explicitly since a literal `grep -rn "GRAPH_EDITOR|Dream Graph"` still returns 3 hits there. That file is the shared, cross-app Checkpoint identifier registry; Dream Graph is a real, separate app that needs its own entry in it. Zero hits exist in any file describing *market's own* behavior — see "How this was verified".

---

## How this was verified

Unlike prior steps in this plan, `php artisan` **works** for this app (confirmed by trying it directly) — this step's verification is live, not code-review-only, for everything not blocked by the missing database.

- **`php -l`** on every created/modified PHP file — clean.
- **`php artisan route:list --path=portal`** — all 14 routes registered with the expected names (`portal.dashboard`, `portal.assets`, `portal.assets.show`, … `portal.api.whoami`).
- **A real dev server** (`php artisan serve`), tested with `curl` against every route:
  - `GET /portal`, `/portal/assets`, `/portal/assets/{ref}`, `/portal/services`, `/portal/services/{ref}`, `/portal/orders`, `/portal/orders/{ref}`, `/portal/licenses`, `/portal/messages`, `/portal/settings`, `/portal/auth/callback` — all **200**.
  - `window.Portal.httpUrl` confirmed set (line 20 of the served HTML) **before** the `sdk.js` `<script>` tag (line 23) — read from the actual response body, not the template.
  - `sdk.js` src confirmed fully-formed: `https://api-sandbox.appfactory.studio/sdk.js` — no empty subdomain.
  - `GET /portal/api/whoami` with no `Authorization` header → **401** `{"success":false,"message":"Missing bearer token."}`.
  - `GET /portal/api/whoami` with a garbage bearer token → **401** `{"success":false,"message":"Invalid or expired session."}`, and timing (~0.25–0.28s per call) consistent with a real network round trip to `https://auth15.appfactory.studio` rather than a local-only check — confirming the middleware genuinely calls the live verify endpoint rather than rejecting on shape alone.
  - `?member_reference_num=…` appended to both `/portal/api/whoami` and `/portal/assets/{ref}` — response unchanged in both cases (still 401 with no token; still 200 identical shell) — the reference is never read from the request.
  - **`php artisan config:cache`, then reload `/portal`**: `$afssdk`/`window.Portal.httpUrl` still resolved correctly (`api-sandbox`, the real brain URL) — the exact `env()`-under-`config:cache` trap the plan's own checklist names, confirmed closed. Config cache cleared again immediately after.
- **`npm run build`**: after fixing the shadowed Vite config and installing the two missing dependencies, builds cleanly and emits a dedicated `portal-*.js` chunk (~408 KB, 834 modules) — the checklist's own "emits the portal.ts bundle" item, satisfied directly. `CHECKPOINT_APP_ID`'s real value confirmed present in the built bundle via `grep`.
- **`tsc --noEmit --skipLibCheck`**, isolated (the project's real `tsconfig.json` is blocked by the same pre-existing `bootstrap` type-definition issue noted in prior steps' reports, unrelated to this step) — every file this step created or modified: zero errors specific to those files (remaining errors are pre-existing, unrelated `appfactory3.ts`/`globalAuth.ts` environment artifacts, confirmed by inspecting each one).
- **Grep checks from §11.7**:
  - `grep -rn "GRAPH_EDITOR|Dream Graph" apps/market/resources/js` → 3 hits, all in `checkpoint/app-names.ts` (the shared registry — see "Known gaps" #4). Zero hits anywhere describing market's own behavior.
  - `MARKET_MODIFIER_ADDITION` present in all 25 created files and all 9 non-`.env` modified files; reconciled against this report's own Files tables — no file in the list is untagged, no tagged file is missing from the list. (`.env` itself carries the tag inline at each added block, matching every prior step's env-file convention.)

---

## Follow-ups spawned

- Step 12 owns the real "My Assets"/"My Licenses" data layer and the `/portal/assets/{entitlement}/download` redemption flow — both `SignedDownloadUrlService` and `AssetDownload::create()` already say so in their own comments; this step's placeholder controllers name it too.
- Steps 13/15/16 own orders, service-order detail, and the settings page respectively — same treatment.
- The two newly-installed npm packages (`accesscontrol`, `parse`) pull in Node core-module polyfills (`events`, externalized by Vite with a warning, not an error) — worth a look if the `portal.js` bundle size (408 KB) becomes a real concern; not addressed here since it builds and runs correctly.
- Verifying the whoami endpoint's *success* path, and the full SSO login round trip, needs a real browser session against live Checkpoint credentials — flag when that environment exists.
