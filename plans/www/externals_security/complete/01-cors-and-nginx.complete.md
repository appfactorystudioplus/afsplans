# 01 — CORS in Laravel, nginx as a plain proxy (built, report mode, not deployed)

## Status

Built 2026-10-09. The contract in `../01-cors-and-nginx.md` §1 holds in the files: nginx proxies (`tmp/nginx/afs-proxy.conf`, no CORS directive anywhere on the externals paths, `Parse` untouched) and Laravel answers the preflight, sets the headers and refuses the rest. Details of every class are in `../01-cors-and-nginx.md` §8.

## Phases (the plan's "things to change, in order")

| # | Step | State |
|---|---|---|
| 1 | Middleware in report mode and the dashboard setting | built, tested |
| 2 | Replace the nginx CORS blocks (dev file `www15 /portal/api`; `/checkpoint` and market `/api`; the generator template) and the lab | files changed; `/parse` left as it was on purpose; **live `appfactory.studio.zone.conf` not changed**; nothing deployed, `nginx -t` not run |
| 3 | Remove `SiteUsersOriginMiddleware` once the global one covers it | **not done** (both exist) |
| — | Checkpoint (`w/auth`) CORS of its own, which `/checkpoint` needs before the nginx change | built, tested (9 tests) |

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
