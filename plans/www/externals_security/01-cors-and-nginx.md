# 01 — CORS in Laravel, nginx as a plain proxy

Part of [00](00-overview.md). **Built 2026-10-09 (report mode, not deployed) — see §8.** The nginx pattern is [`packages/afsserver/tmp/nginx-externals-cors.example.conf`](../../../tmp/nginx-externals-cors.example.conf); the real server files, already changed, are in [`packages/afsserver/tmp/nginx/`](../../../tmp/nginx) — `conf.d/appfactory.studio.dev.zone.conf` (the `www15` host) and `afs-proxy.conf`, which goes to `/etc/nginx/afs-proxy.conf` (not into `conf.d/`: `nginx.conf` includes `conf.d/*.conf` at the http level, where proxy directives are not allowed). Neither was syntax-checked — nginx is not installed on this machine; run `nginx -t` on the server.

## 1. The contract

| | nginx | Laravel |
|---|---|---|
| TLS, proxying, `Host` / client address, size limits, a coarse rate limit, the stream and webhook special cases | ✔ | |
| Answering the `OPTIONS` preflight | **never** | ✔ |
| `Access-Control-Allow-Origin` / `-Credentials` / `-Methods` / `-Headers` / `-Max-Age`, `Vary: Origin` | **never** (no `add_header`, no `proxy_hide_header` for these) | ✔ |
| Knowing which websites may call which site | | ✔ (from the site's settings) |

nginx passes `Origin`, `Referer` and `Access-Control-Request-*` through untouched and passes the application's response headers back untouched. That is all the example file does for CORS: it removes the dev file's block.

Two other lines matter for the checks below: `Host` is passed as the client sent it (Laravel finds the site from it), and `X-Forwarded-For` is set to `$remote_addr` at the edge, not appended to a client-supplied value, or the per-address limits can be dodged by sending a fake header.

## 2. The Laravel middleware (replaces the no-op `PortalCorsMiddleware`)

**Registered globally and first**, so it answers `OPTIONS` before routing (the router would otherwise answer 404/405 to a preflight). It needs, per request: the `Origin` header, the `Host`, and the route's group.

Route groups (set when the routes are declared, in the `Externals` route groups):
- **public** — what the SDK calls on a site;
- **dashboard** — administrator routes (`ApiAuthMiddleware`);
- **webhook** — provider callbacks.

Algorithm for a **public** route:

1. Find the site from `Host` (`tryFromManagedDomain()` / the `sites.appfactory.studio` name). No site: no CORS headers; the route's own 404 applies.
2. `Origin` absent → add nothing. A same-origin page or a script sent it; for requests that change data, decision **S2** (refuse in enforce mode when `Referer` is also absent).
3. Compute *allowed* = the origin equals one of the site's own hosts **or** is on the site's allowed-website list (exact match of scheme + host + port; a wildcard like `https://*.example.com` only if written that way in the list).
4. Allowed:
   - actual request: `Access-Control-Allow-Origin: <that origin>`, `Vary: Origin`; **no** `Allow-Credentials`; `Access-Control-Expose-Headers` for the headers the SDK reads;
   - preflight: **204** with `Allow-Methods` (the route's methods), `Allow-Headers` = the requested headers that are on the approved list (`Content-Type`, `Accept`, `X-Idempotency-Key`, and the key header if S5 is ever done), `Max-Age: 600`, plus the two headers above.
5. Not allowed:
   - preflight: **403**, no CORS headers;
   - actual request that changes data: **403** `SDK_E_ORIGIN_DENIED` — CORS only stops the browser *reading* a response; a simple POST has already been sent, so the server must refuse it;
   - reads: answered without CORS headers (the browser discards them).
6. Always `Vary: Origin` on public routes (a cache must not serve one site's allowed answer to another).

For **dashboard** routes: never any CORS header, unless the request's origin is in the configured trusted-application list (S6). For **webhook** routes: none.

Modes, per site and overall (a setting, default `report` for the first release): **off** (no change), **report** (behave like today — reflect the origin — and write a log line for every request that *would* have been refused), **enforce**.

## 3. The dashboard setting

On the site (the site's `website_reference_num`): *Allowed websites* — a list of origins. Shown beside it, read-only: the site's own hosts that pass automatically (its `sites.appfactory.studio` name and its connected custom domains, S1). Validation: `https://` (and `http://localhost` in development), no path, no query; maximum entries. Saving writes an audit entry. The per-module lists site users have today (`allowed_origins`) are migrated into this one (S3).

## 4. Things to change, in order

1. **P0.** Add the middleware in `report` mode and the dashboard setting (read-only at first). nginx unchanged.
2. **P1.** Replace the CORS blocks in the www vhost of the dev copy (lines 84–128) and in the generator template `generations/http/nginx-vh.conf` with the example's locations; deploy; confirm in the log that nothing would be refused that should not be; switch the mode to `enforce` site by site.
3. Remove the site-user-only origin middleware (`SiteUsersOriginMiddleware`) once the global one covers it, keeping its semantic (empty list = same origin only).

## 5. Tests to write with it

Allowed origin gets the exact origin back and `Vary: Origin`; a second site's origin is refused on the first site; `Origin` equal to the site's own host passes; a connected custom domain passes; preflight answered by Laravel with the right methods and only approved headers; no `Access-Control-Allow-Credentials` ever; a POST from a disallowed origin is 403 and writes nothing; report mode refuses nothing and logs; dashboard routes never carry CORS headers; a missing `Origin` on a write behaves as S2 says.

## 6. Risks

- **Deploying nginx before Laravel** removes CORS from every cross-origin SDK use at once. Laravel (report mode) first.
- **A cache in front** that ignores `Vary: Origin`.
- **Scripts and `curl`** ignore all of this. It protects visitors' browsers and the dashboard's trust, not the endpoints themselves — which is why step [02](02-route-inventory.md) and the Direct Payments rules come with it.

## 7. What is in the real configuration today (read from `tmp/nginx`, the server's own files)

- `nginx.conf` includes only `conf.d/*.conf` and sets `client_max_body_size 10G` globally (large uploads), so the new `/portal/api` location does **not** set a body size — per-route caps belong to Laravel.
- Four locations carry the Parse-style CORS block in the dev file: `auth15 /checkpoint`, `www15 /portal/api` (**the externals API**), `dashboard15-registry /parse` and `market15 /api`. On 2026-10-09 the first, second and fourth were changed to plain proxies (`include /etc/nginx/afs-proxy.conf; proxy_pass …`) so the Laravel application behind each decides its own CORS. **The `/parse` block is the Parse Server's, not Laravel's, and was left exactly as it was** (after an edit that removed it was reverted): nginx hides Parse's own wildcard headers, answers the preflight itself and sets the single origin header, which is deliberate for a Node server. **Checkpoint needs CORS of its own before its change is deployed; market does not.** Checkpoint (`w/auth`) is the authentication layer for members and the apps, and its browser client calls it with `credentials: 'include'` from the members, www and other app front ends (`checkpoint-client` `http-client.ts`, `client-handler.ts`), so it needs credentialed CORS — an **exact-origin allowlist** (never a reflected origin), built from the apps' own URLs and `checkpoint.sso.allowed_domains` in `auth/config/checkpoint.php`, `Vary: Origin`, the preflight answered by Laravel, credentials only for listed origins, and a 403 for a credentialed request from an unlisted origin. The old nginx block (any origin, `Allow-Credentials: true`) let any website make credentialed calls to Checkpoint with a visitor's cookie. Until the middleware exists, a cross-origin call to `auth15/checkpoint` has no CORS headers at all and fails. **Market's `/api` needs none:** its own front end calls it same-origin (`credentials: 'same-origin'`), members reaches its internal API through a server-side proxy (`members/config/market.php`), coordinator uses a server-side base URL, and nginx already restricts `market15` to one IP — so removing the block there is safe. The live file `appfactory.studio.zone.conf` still has the old blocks (`www.appfactory.studio /portal/api` at lines 28–63, `/checkpoint`, `/parse` and seven `/api` blocks) and was not touched.
- **Tenant sites already work this way.** The tenant files (`<user>-www.conf`, e.g. `xrnsq…-www.conf` for `<ref>.sites.appfactory.studio`) have only `location /` with a plain proxy and no CORS directive, so the cross-origin decision for a tenant site is already entirely Laravel's — which today answers nothing, so cross-origin use of a tenant site fails until the middleware exists. The generator template `generations/http/nginx-vh.conf` still writes a Parse-style CORS block into its second location (`${location}`); that is the one to change.
- **The lab (`C:\projects\vagrant\lab2`) matches the "plain proxy" shape and has no CORS at all.** nginx owns 80/443 and terminates TLS, then proxies plain HTTP to Apache on `:808` (`provision/apache-backend.sh`); the tenant vhosts written by `brain/add-site.sh` and `brain/deploy-tenant.sh` are `location /` with `Host`, `X-Real-IP`, `X-Forwarded-Proto` and `proxy_pass http://localhost:808`, and no `/portal/api` location, no `Access-Control-*`, no rate limit. Platform vhosts are kept in one file, `appfactory.studio.zone.conf`, by `provision/vhost-lib.sh` (`vhost_put nginx "<name>"` replaces one marked block and leaves the rest). So the lab can test the Laravel middleware as it is, with no nginx change; to mirror the real server, `vhost_put` the www block with the `/portal/api` location above and copy `afs-proxy.conf` to `/etc/nginx/`.
- **Changed alongside, 2026-10-09:** the generator template `generations/http/nginx-vh.conf` (its second location is now a plain proxy with no CORS block — note the generator's sample data uses it for `/parse`, so a Parse location generated from it now relies on Parse's own headers); and the lab: `lab2/web/afs-proxy.conf` (a copy of the server's snippet), and `lab2/provision/deploy-app.sh`, which installs it as `/etc/nginx/afs-proxy.conf`, writes `/etc/nginx/conf.d/00-afs-externals.conf` (the `limit_req_zone`), and gives the `www` vhost the same `/portal/api` and `/portal/api/chat/stream` locations as `www15` on the server. The other lab apps, the Parse vhost and the tenant vhosts are unchanged: they already proxy with no CORS, which is the shape the server now has.

## 8. As built (2026-10-09)

**`w/www`** (all new classes under `app/Externals/Security/`, the middleware under `app/Http/Middleware/`):

| File | Role |
|---|---|
| `config/externals.php` | `cors_mode` (`EXTERNALS_CORS_MODE`, default `report`), `trusted_origins`, `require_origin_on_public_writes`, approved request headers, expose headers, preflight max-age, the path prefixes in scope (`portal/api`, `auth1/api`) |
| `Origins.php` | normal form of an origin; exact and `https://*.domain` matching; what a person may list |
| `ExternalsSecuritySettings.php` | the site's list and mode in `laravel_settings.json` → `externals`, cached 30 s, cleared on save; saving never touches the other keys |
| `SiteHosts.php` | the site's own hosts (request host, `APP_URL`, managed domain `Domain`/`Domain2`) |
| `OriginPolicy.php` | `own` / `listed` / `trusted` / `denied` |
| `RouteTiers.php` | `server` / `dashboard` / `session` / `public`, read from the middleware a route carries |
| `App\Http\Middleware\ExternalsCors` | **global** (`bootstrap/app.php`, appended after `trustProxies`): answers the preflight before routing, sets `Access-Control-Allow-Origin` (+ credentials, `Vary: Origin`) for a passing origin, refuses a data-changing request from any other origin with 403 `SDK_E_ORIGIN_DENIED`, refuses a public write with no `Origin`/`Referer`; signed server routes are never touched; modes `off` / `report` / `enforce` (report = the old nginx behaviour plus a log line `externals.cors.would_refuse`, once per origin and host per 10 minutes) |
| `App\Http\Middleware\PortalCorsMiddleware` | still a no-op (routes carry its alias); comment updated |
| `ExternalsSecurityController` + routes `GET/PUT portal/api/externals/security` | the dashboard setting (behind `ApiAuthMiddleware`); `SettingsController::update` no longer erases the `externals` key |
| `resources/js/portal/admin/controllers/settings/externalsSecurity.ts` + an "Allowed websites" accordion in `html/views/settings.html` | the dashboard UI (textarea of origins, mode select, read-only "always allowed"); type-checks clean under `strict`; not built or seen in a browser |

**`w/auth` (Checkpoint)**: `app/Http/Middleware/CheckpointCors.php` (global), `config/checkpoint.php` → `cors.mode` (`CHECKPOINT_CORS_MODE`, default `report`). Allowed = `checkpoint.sso.allowed_domains` (exact hosts, includes the desktop loopback `127.0.0.1` / `localhost`), `checkpoint.sso.allowed_domain_suffixes`, and the request's own host; credentials only for those; same off / report / enforce.

**nginx** and the lab: as in §7 above (`tmp/nginx/…`, `lab2/provision/deploy-app.sh`).

**Rollout (next)**: 1. Deploy `www` and `auth` with the defaults (`report`) **while the old nginx blocks are still live**: nginx hides every CORS header the application sends and adds its own, so nothing changes for browsers, and the application's logs (`externals.cors.would_refuse`, `externals.dashboard.would_refuse`, `checkpoint.cors.would_refuse`) already show which origins and which unauthenticated administrator calls enforce mode would refuse. 2. Swap in the new nginx files (`tmp/nginx/…`, the lab's `deploy-app.sh`): nginx now adds, hides and answers nothing, and Laravel's report-mode headers (the same reflect-with-credentials as before) take over — **never** a state where nginx still adds `Access-Control-Allow-Origin` and stops hiding Laravel's, because two values make the browser reject the answer. Do the `/checkpoint` swap only after step 1 has run on `auth`. 3. After a few days of logs: list each legitimate embedding website in that site's dashboard (Settings → Allowed websites), then set `enforce` per site (dashboard) or for the installation (`EXTERNALS_CORS_MODE=enforce`); Checkpoint last (`CHECKPOINT_CORS_MODE=enforce`).

## 9. Rollout after "enforce is the default" (2026-10-09)

Step 1 of the §8 rollout is no longer "nothing changes": the new code enforces from its first request. Either (a) list every embedding website per site first and deploy with the defaults, or (b) deploy with `EXTERNALS_CORS_MODE=report` and `CHECKPOINT_CORS_MODE=report` in each `.env`, read `externals.cors.would_refuse` / `externals.dashboard.would_refuse` / `checkpoint.cors.would_refuse`, list the websites, then delete those two lines. The nginx order is unchanged (Laravel first, then the nginx swap; never nginx adding `Access-Control-Allow-Origin` while not hiding Laravel's). `Origin: null` from the desktop app is admitted by Checkpoint (`checkpoint.cors.allow_null_origin`).
