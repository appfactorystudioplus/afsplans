# 00 — Externals API security: overview (built, enforce by default, not deployed)

## Status

Built 2026-10-09 across `w/www`, `w/auth`, the server's nginx files, the generator template and the lab. The plan's four rules are in place: the site is the host the request arrives on; **Laravel, not nginx, decides CORS**; administrator actions need the dashboard login; the Direct Payments rules for "nothing the page sends is believed" are filed, not built. Nothing is committed or deployed.

## Phases

| Phase | State |
|---|---|
| P0 — Laravel CORS middleware in report mode | built and tested (`01` §8) |
| P1 — nginx change, allowed-websites setting, enforce | nginx files changed in `tmp/nginx` and the lab; setting built (backend, dashboard panel); **enforce not switched on, nothing deployed** |
| P2 — route inventory worked through | 91 administrator routes got a login (report-mode switch), 16 routes throttled, route-policy test; the rest is "Not done" in `02` |
| P3 — publishable key, one-flow tokens | not started, as decided (S5) |

## Decisions S1–S6

Built as in `../00-overview.md` §7: S1 own host, `APP_URL` and the managed domain pass automatically; S2 refused in enforce mode (switchable); S3 one list per installation in `laravel_settings`; **S4 not done**; S5 later; S6 `EXTERNALS_TRUSTED_ORIGINS`.

## Differences from the plan (found while building)

1. The SDK sends cookies (`credentials: 'include'`), so an allowed origin is answered **with** `Access-Control-Allow-Credentials: true`; the plan said none.
2. The dashboard login is a **cookie as well as a bearer token** (`ApiAuthMiddleware` accepts `portal_auth_token`) and portal routes carry no CSRF middleware, so the old nginx block let any website make state-changing dashboard calls with an administrator's cookie; enforce mode refuses a data-changing request from an unlisted origin before the route runs.
3. Dashboard routes use the same allowed set instead of "no CORS".
4. The "site" is the installation (each tenant runs its own copy of the application), not a claims-table lookup.

## What was checked

- `php -l` on every new and changed PHP file; the new TypeScript file type-checks clean under `strict` (a temporary `tsconfig`, removed).
- **New tests, all passing**: `w/www` — `tests/Unit/Externals/OriginsTest` (5), `tests/Feature/Externals/ExternalsCorsTest` (19), `ExternalsDashboardLoginTest` (5), `RoutePolicyTest` (3): 32 tests; `w/auth` — `tests/Feature/CheckpointCorsTest` (9).
- **Route table before and after, entry by entry** (domain, method, URI, name, controller@method): the only differences are the two new `externals/security` routes; the only middleware differences are `externals.dashboard` on 91 routes and `throttle` on 16; nothing was removed from any route. 732 routes now.
- **Regression**: the unit tests and the externals feature folders (738 tests with the new ones) fail exactly the 338 tests they failed before this work (shared-database leftovers, MariaDB identifier length, a PDO driver function, tests of other code — see `../externals_namespace/complete/00-overview.complete.md`); **no new failure**, no missing-class error. `DomainsSurfaceTest` (13) still passes.

## Not done / to know

- **Nothing is deployed and nothing is committed.** The first release is `report` mode: it changes no browser's behaviour and no administrator call is refused; it only logs what `enforce` would refuse. `enforce` needs a few days of those logs and each embedding website listed in its dashboard first (`01-cors-and-nginx.md` §8, rollout).
- **No request was made through a real browser or a real nginx.** `nginx -t` was not run (nginx is not installed here); the dashboard panel was not built or opened; the lab was not re-provisioned.
- **The end-user endpoints (decision S4)** — "my orders", "my payment methods", "my sessions" — are unchanged.
- **The per-module site-user allowed-origin lists** (`SiteUserModule.allowed_origins`, with `SiteUsersOriginMiddleware`) are not merged into the new per-installation list; both exist side by side.
- **The 78 open write actions are decisions, not audits**; controllers were not read one by one for authorisation in the method. Upload size/type limits, a CAPTCHA on public submit routes, and who may use `mail-handler send` beyond its throttle are open.
- **The live nginx file** (`appfactory.studio.zone.conf`) and the `/checkpoint` swap are not changed; `Checkpoint` and `www` must be deployed in `report` first (rollout in `01` §8).
- The Direct Payments gaps (server-priced invoice lines, status only from the provider) remain in `../direct_payments/TODOS.md`.

## Files

`w/www`: `config/externals.php`; `app/Externals/Security/{Origins,ExternalsSecuritySettings,SiteHosts,OriginPolicy,RouteTiers,RoutePolicy}.php`; `app/Http/Middleware/{ExternalsCors,ExternalsDashboardLogin}.php` (+ `PortalCorsMiddleware` comment); `app/Http/Controllers/Externals/Security/ExternalsSecurityController.php`; `bootstrap/app.php` (global `ExternalsCors`, alias `externals.dashboard`); `routes/portal.php` (the two settings routes, 91 `externals.dashboard`, 16 `throttle`); `app/Http/Controllers/Portal/Api/SettingsController.php` (keeps the `externals` key); `resources/js/portal/admin/controllers/settings/{externalsSecurity,settings}.ts`, `html/views/settings.html`; tests as above.
`w/auth`: `app/Http/Middleware/CheckpointCors.php`, `bootstrap/app.php`, `config/checkpoint.php`, `tests/Feature/CheckpointCorsTest.php`.
`packages/afsserver`: `tmp/nginx/conf.d/appfactory.studio.dev.zone.conf`, `tmp/nginx/afs-proxy.conf`, `tmp/nginx-externals-cors.example.conf`, `generations/http/nginx-vh.conf`. `lab2`: `provision/deploy-app.sh`, `web/afs-proxy.conf`.

## Update (same day): enforce by default, client-app audit

`enforce` is now the default for `w/www` (`EXTERNALS_CORS_MODE`, and the fallback in `ExternalsSecuritySettings::corsMode()`) and `w/auth` (`CHECKPOINT_CORS_MODE`). `afsbrain_flutter`, `afsmobile_flutter` and `afsdesktop5` were audited for the routes they call (details in `../00-overview.md` §8): the two Flutter apps send no `Origin` and call only server / dashboard / public-read routes, so nothing they use is refused; the desktop app's renderer sends `Origin: null` to Checkpoint, which `enforce` would have refused, so `CheckpointCors` now admits it (`checkpoint.cors.allow_null_origin`, default true; answered with `Access-Control-Allow-Origin: null` + credentials; Checkpoint cookies are SameSite=Lax). Tests: `w/www` 33 (adds the default-mode test), `w/auth` 13 (adds three `null`-origin tests and the default-mode test); `DomainsSurfaceTest` not re-run. **Not verified**: the communications service's own CORS for the desktop's `Origin: null`; a real desktop sign-in against an enforcing Checkpoint.
