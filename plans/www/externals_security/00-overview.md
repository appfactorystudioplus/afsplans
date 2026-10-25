# 00 — Externals API security: overview

Status: **built 2026-10-09; `enforce` is the default mode (changed later the same day, see §8); not deployed.** Repositories `w/www` and `w/auth` (Checkpoint). The "As built" section at the end says what changed from the plan below; completion records are in [`complete/`](complete/). The owner asked for this after the externals PHP was grouped under `Externals` ([`../externals_namespace`](../externals_namespace/00-overview.md)).

## 1. How the externals API is used (the owner's model)

There is no externals API for a developer to run from their own servers. Everything is configured in the admin dashboard, and the client-side SDK always calls back to the same place: **`<ref>.sites.appfactory.studio`**, which is at once the admin dashboard, the API, and the host of the site the builder deployed.

| Caller | Where it runs | Needs a CORS decision? |
|---|---|---|
| **A. A builder-deployed site** | a page on `<ref>.sites.appfactory.studio` | **No.** The builder sets the SDK's base URL to the page's own origin (`Portal.httpUrl = window.location.origin`, e.g. `direct_payments_component_script.ts:80`), so the page and the API share a host. |
| **B. The same site on a connected custom domain** | a page on `example.com`, which nginx proxies to the same application | **No**, for the same reason: it calls its own host. |
| **C. The SDK embedded on some other website** | a page on `other.com` calling `<ref>.sites.appfactory.studio` | **Yes.** Only these sites go on the dashboard's allowed-website list. |
| **D. The dashboard** | an administrator, logged in | **No CORS** — same host, and its own guard (the Checkpoint bearer, `ApiAuthMiddleware`). Refunds and other changes are made here, by a logged-in person. |
| **E. Servers calling in** | payment-provider webhooks, the Brain | Not browsers. Each has its own proof (the provider's signature; the Brain's signed request). |

A signed-in **end user** of a site is a separate guard and is **not** part of an API request. The API does not know or care whether the visitor is logged in on the page.

## 2. What is true today (read on 2026-10-09)

1. **nginx decides CORS, and decides "everyone".** For `/portal/api` the dev copy sends `Access-Control-Allow-Origin: $http_origin` (whatever website asks) and `Access-Control-Allow-Credentials: true`, answers the preflight itself (`return 204`), and hides any CORS header the application sends. The allowed-headers list is copied from Parse and does not include `X-Idempotency-Key` or any key header. The tenant-vhost template (`generations/http/nginx-vh.conf`) has the same block. The dashboard and the SDK share one policy.
2. **Laravel's CORS middleware does nothing** (`PortalCorsMiddleware` is an intentional no-op; its comment says nginx owns CORS).
3. **There is a model to copy.** `SiteUsersModuleService::checkOrigin` already does what is wanted for site users: an empty allowed-origin list means "same origin only"; otherwise the request's `Origin` (or the `Referer`, if there is none) must be on the list. `WwwAfsConnectTenantFromRequest::tryFromManagedDomain()` finds the site from the request host. Per-module public throttles exist for site users, ecommerce, appointments, events and forms.
4. **Most routes have no route-level authentication.** Of the 430 routes served by the `Externals` controllers, **236 (147 that change data) carry no authentication middleware** in the route definition; 194 use `ApiAuthMiddleware` or a site-user session. Some of the 236 may check inside the controller, and the code says some do not (`ProcessorIntentController` and `DirectPaymentsController` "carry no request authentication today"). Step [02](02-route-inventory.md) is the list.

## 3. The design

Four rules, in the order they should be built:

1. **The site is whatever host the request arrives on.** Not anything the page sends (a reference number in a URL or body is an identifier, not a secret). Every public route is scoped to that site.
2. **Laravel, not nginx, decides which websites may call.** Allowed = the site's own hosts (its `sites.appfactory.studio` name and its connected custom domains) **plus** the list the owner of the site sets in the dashboard. Everything else gets no CORS headers, and a request that changes data is refused. nginx only proxies. Specification and the nginx file: step [01](01-cors-and-nginx.md).
3. **The dashboard stays same-origin and logged in.** No CORS headers are ever sent for dashboard routes (except a configured list of trusted application origins if the dashboard UI is ever served from another host). Dashboard-class routes that have no authentication today get it (step 02).
4. **Nothing the page sends is believed.** Prices come from the catalogue, "paid" comes only from the provider, files and text are size-limited and escaped. This is the Direct Payments work already filed in [`../direct_payments/TODOS.md`](../direct_payments/TODOS.md); it matters more than the other three, because origin checks stop browsers and not scripts.

Not in the first build, decided later: a **publishable key** per site (revocation, metering) and **short-lived tokens** for one flow (a checkout token bound to one cart and invoice).

## 4. Phases

| Phase | Work | Safe to ship alone? |
|---|---|---|
| **P0** | Laravel CORS middleware in *report* mode: it behaves like today (reflects the origin) but logs which requests it would refuse; nginx keeps its block. | Yes — changes nothing for browsers. |
| **P1** | nginx change (the example in `packages/afsserver/tmp/nginx-externals-cors.example.conf`) and the template; then Laravel *enforces*. Dashboard setting: the site's allowed websites. | Together; Laravel first in report mode. |
| **P2** | The route inventory worked through: dashboard-class routes get authentication; public routes get site scoping and throttles; abuse controls on mail send, submit and upload routes. | Per area. |
| **P3** | Only if needed: publishable key, one-flow tokens. | — |

## 5. Decisions for the owner

| # | Question | Recommendation |
|---|---|---|
| **S1** | Which origins pass automatically? | The site's `<ref>.sites.appfactory.studio` name and every domain connected to that site through the domains feature (so builder sites need nothing set up). |
| **S2** | A public request that changes data and carries **no `Origin` and no `Referer`** (a script, `curl`) — allow or refuse? | Refuse in enforce mode (a casual script gets nothing), allow reads. It does not stop a determined caller, who can send any header; it removes the easy abuse. |
| **S3** | Where is the allowed-website list kept? | One list per site (the site's `website_reference_num`), edited in the dashboard; the per-module lists site users have today are migrated into it. |
| **S4** | Endpoints that return one end user's own data ("my orders", "my payment methods", "my sessions") — they depend on a site-user login today. | Take them out of the public API and serve them under the site-user guard, or switch them to one-flow tokens. Not decided here. |
| **S5** | Publishable key now or later? | Later (P3). With the site found from the host and the origin list, a key adds revocation and metering but no extra protection against a script. |
| **S6** | Is a dashboard UI ever served from a different host than `<ref>.sites…`? | If yes, those hosts go in a configured trusted-origin list; if no, dashboard routes get no CORS at all. |

## 6. Not covered

The Brain surface (signed requests), the Parse servers and Checkpoint, and the members application. They are separate surfaces with their own guards.

## 7. As built (2026-10-09)

**Changes to the design, found while building** (the sections above are the plan as written; where they differ, this section wins):

1. **Credentials are sent to allowed origins.** The SDK calls the API with `credentials: 'include'` (the site-user session cookie, the dashboard's cookie), and the browser refuses a cross-origin answer to such a call unless it carries `Access-Control-Allow-Credentials: true`. So an origin that passes (its own host, the allowed-websites list, a trusted application) gets credentials; the danger the plan named was "reflect **any** origin with credentials", and that is gone. Everything else gets no CORS header.
2. **The dashboard's login is a cookie as well as a bearer token.** `ApiAuthMiddleware` accepts `Authorization: Bearer …` **or the `portal_auth_token` cookie**, and the portal routes carry no CSRF middleware. With the old nginx block (any origin, credentials) a page on any website could make a state-changing call to the dashboard API with an administrator's cookie. The origin check in enforce mode closes that (a data-changing request from an unlisted origin is refused before the route runs), for the public and the dashboard routes alike.
3. **Dashboard-tier routes use the same allowed set** (own hosts, allowed websites, trusted applications) instead of "no CORS ever": the SDK's documents and notifications calls come from embedding websites with a login, and the platform's own applications call the dashboard API cross-origin.
4. **The "site" is this installation.** Each tenant runs its own copy of this application and database (`subdomain_properties`, `laravel_settings`), so the per-site list lives in that installation's `laravel_settings` document and "own hosts" are the request host, the application URL and the managed domain's `Domain` / `Domain2` (each with and without `www.`) — not a lookup in the Brain's claims table.

**Decisions S1–S6 as built:**

| # | Built as |
|---|---|
| S1 | Automatic: the request's own host, `APP_URL`'s host, the managed domain (`Domain`, `Domain2`, with/without `www.`). |
| S2 | Refuse in `enforce` mode (a public request that changes data with neither `Origin` nor `Referer`); switchable with `EXTERNALS_REQUIRE_ORIGIN_ON_WRITES`. A `Referer` alone is judged like an origin. Dashboard, session and signed-server routes are not subject to it. |
| S3 | One list per installation in `laravel_settings.json` → `externals.allowed_websites` (max 50; `https://` only, `http://localhost` allowed, `https://*.example.com` wildcard), with `externals.cors_mode`. The per-module site-user lists (`SiteUserModule.allowed_origins`) are **not** migrated yet and keep working through their own middleware. |
| S4 | **Not done.** The "my orders / my payment methods / my sessions" endpoints stay as they are (they use the site-user session or a guest token). Listed under "Not done" in the completion records. |
| S5 | Later, unchanged. |
| S6 | `EXTERNALS_TRUSTED_ORIGINS` (comma separated) in the environment; empty by default. |

**Environment** (all optional): `EXTERNALS_CORS_MODE=enforce` (the default; `off` / `report` / `enforce`; a site's dashboard setting overrides it), `EXTERNALS_TRUSTED_ORIGINS=`, `EXTERNALS_REQUIRE_ORIGIN_ON_WRITES=true`; in `w/auth`: `CHECKPOINT_CORS_MODE=enforce` (default) and `CHECKPOINT_CORS_ALLOW_NULL_ORIGIN=true` (default).

## 8. Enforce is the default (owner decision, 2026-10-09, after the first build)

`EXTERNALS_CORS_MODE` and `CHECKPOINT_CORS_MODE` now default to **`enforce`** (also the fallback when a site's saved mode is missing or invalid). `report` and `off` stay available per site (dashboard) or per installation (environment) for a learning period.

Consequences, in plain words:

- **Deploying is now enforcing.** The earlier rollout ("deploy in `report`, read the logs, then enforce") only works if `EXTERNALS_CORS_MODE=report` / `CHECKPOINT_CORS_MODE=report` are set in the `.env` at deploy time and removed afterwards. Without that, a website that embeds the SDK and is not yet on its site's allowed list is refused from the first request. List the embedding websites (Settings → Allowed websites) **before** the first deploy, or deploy with `report` set.
- **91 administrator routes need the dashboard login immediately** (`externals.dashboard` follows the mode). Anything that called them without a bearer token or the `portal_auth_token` cookie gets 401. The dashboard sends the cookie; the three native apps do not call these routes (below).
- **A public write with no `Origin` and no `Referer` is refused** (S2) — `curl` and scripts, and any native client that calls a public write route.
- **Checkpoint admits the `null` origin** (the Electron desktop app) — see the audit.

### Client-app audit (2026-10-09): afsbrain_flutter, afsmobile_flutter, afsdesktop5

Read in full for how they reach `www` (the Brain / externals API) and Checkpoint. **None of the three embeds the SDK, none sends cookies to `www`, and none is a website, so none is on a "listed website" list.** What matters under `enforce` is the `Origin` header.

| App | Language / where it runs | Calls | `Origin` header | Effect under `enforce` |
|---|---|---|---|---|
| **afsbrain_flutter** (Brain staff app) | Dart, Android | `PORTAL_API_URL` (host + `/portal/api`) with `Authorization: Bearer <device token>` — one client, `lib/core/api/brain_api_client.dart`; every path is in `lib/core/api/api_paths.dart` (`/auth/me`, `/v1/brain/staff/*`, `/v1/memberships/*`, `/v1/money/*`, `/v1/brain/member/*`, `/v1/brain/apps`, `/v1/brain/storage`); `REGISTRY_URL` (no token); the Linode API (`LINODE_TOKEN`) | none (native `http` client) | **Nothing refused.** Of the 131 routes under those prefixes: 99 are server tier (`brain.only` / level 99, never touched by the origin check), 28 dashboard tier (`ApiAuthMiddleware`, `tenant.admin`, `portal.admin.auth`), 4 public — and of those only `GET validate-username` / `validate-email` are called (a public *write* with no `Origin` would be refused: `register` and `reserve-reference`, which the app does **not** call). |
| **afsmobile_flutter** (member app) | Dart, Android / iOS | Checkpoint (`CHECKPOINT_URL`, through `packages/checkpoint`); `GET {BRAIN_URL}/portal/api/v1/brain/me/domains` (server tier, `lib/repositories/domains_repository.dart`); the member's own domain `{Address2}/portal/api/v1/email-accounts` (GET, POST `private` / `public`; `tenant.admin`, dashboard tier; `lib/services/tenant_client.dart`, https only, Checkpoint bearer; `lib/repositories/domain_email_repository.dart`); `members …/api/v1/calendar` and `/v1/storage` (`w/members`, not this work); the communications service; a demo call to a public test host (`todos_repository.dart`) | none | **Nothing refused.** |
| **afsdesktop5** (Electron) | TypeScript; renderer loaded from a file (`mainWindow.loadFile`, `src/main/index.ts`) | Renderer (Chromium `fetch`): Checkpoint `/checkpoint/auth/*`, `/authz/*` with `credentials: 'include'` + bearer (`src/renderer/checkpoint/http-client.ts`); communications service with `credentials: 'omit'` (`src/renderer/call/communicationsApi.ts`). Main process (Node): members `/api/v1/storage/*` and calendar sync (`src/main/cloud/client.ts`, `src/main/calendar/sync/client.ts`). **No call to `www`.** | renderer: **`Origin: null`** (a page loaded from a file); main process: none | **Found and fixed:** `CheckpointCors` refused `null` in `enforce` (and sent no header for it in `report`), which would have stopped desktop sign-in. It now admits `null` (`checkpoint.cors.allow_null_origin`, default true), answered with `Access-Control-Allow-Origin: null` + credentials; safe because Checkpoint's cookies are SameSite=Lax and the browser does not send them on a cross-site call (the app's login is the bearer token). `www` is unaffected (desktop never calls it). **Not verified**: the communications service (`w/communications`) must also admit `null` for the desktop call client — it was not read. |

How the routes were checked: a script (scratchpad `client_route_tiers.php`) walked the router for every prefix above and printed each route's tier and what `enforce` does to a request with no `Origin`. Mobile and Brain apps use `https` URLs from `_env/<mode>/.env`; the desktop app takes `CHECKPOINT_URL`, `COMMUNICATIONS_URL`, `MEMBERS_URL` from its own `_env`. Nothing in the three apps sets an `Origin`, `Referer` or idempotency header, and none relies on a CORS answer.

What this means for other first-party callers: a *browser* application on another host that calls `www` (for example `members` or a communications web client) is a different origin and **must** be in `EXTERNALS_TRUSTED_ORIGINS` or on the site's allowed-websites list; `members/resources/js` has no live cross-origin call to `www` today (the references are comments).

