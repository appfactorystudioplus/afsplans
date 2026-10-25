# 02 — Route inventory (administrator routes now carry the login; open writes are a written decision)

## Status

Phase P2 built to the extent in `../02-route-inventory.md` §5: of the 236 externals routes that had no login, **91 now carry `externals.dashboard`** (the dashboard login in the off / report / enforce mode; shipped in `report`), leaving 145 public; 16 abuse-prone public routes are throttled; `RoutePolicy` records the 88 administrator actions and the 78 open write actions, and `RoutePolicyTest` keeps the route table to it.

## Phases

| Phase | State |
|---|---|
| Read each controller marked DASH or `?` and close the gaps | done by classification from the controllers, the dashboard's own calls (cookie, no header) and the SDK/builder's calls; **not** a line-by-line audit of every method |
| Declare the tier on every route | done by reading the middleware a route carries (`RouteTiers`), plus the explicit `RoutePolicy` lists, instead of one alias per group |
| The route-table test | `tests/Feature/Externals/RoutePolicyTest` |
| SDK-tier routes: site scope, throttle, limits | throttles added on 16 routes; site-scope middleware for the modules that already had it is unchanged; upload limits, CAPTCHA, mail-send policy open |

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
