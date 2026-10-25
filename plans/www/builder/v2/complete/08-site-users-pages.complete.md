# 08 — Site Users as a page-level module (complete)

## Status

**Done.** `site_users` moved from a block module (Login / Register / User Menu, each a
component with its own serialized script) to a `surface: 'page'` module — the same move v2
already made for ecommerce, events and appointments. Binding a module instance to a page
creates five real published routes: Login, Register, Dashboard, Forgot password, Reset
password. Dashboard is guarded server-side; the other four render via the shared six-design
Design picker and live editor preview 06 already gave events/appointments.

- **www** — uncommitted on `main` (pending, on request).
- **465 builder tests** (13 new in `site_users.test.ts`; 8 pre-existing pinned-registry tests
  updated — see Residue). `tsc --noEmit` clean. `npm run check:modules` clean (10 modules, no
  consistency-gate violations). `npm run build` (production `vite build`) green.
- `SiteUsersDemoSeeder` run twice against the real local DB: one module row, one member row,
  second run a no-op; the member's password identity verifies (`checkPassword()` true).
- **Verified live** (below) against the real `/portal/api/v1/builder/publish` endpoint and
  `BuilderPublishedController` — not just unit tests.

## What shipped

### The module declaration (§8.2)

`core/modules/site_users.module.ts` rewritten to `surface: 'page'`, `version: 2` (shape
change), `pageBinding: { pageType: 'site-users', subpages: ['login', 'register', 'dashboard',
'forgot-password', 'reset-password'], refField: 'data-siteusers-module', pageProperty:
'pageSiteUsers' }`, `runtime: externalUi(...)`. **No `register()` at all** — the one
deliberate difference from events/appointments, which keep `register()` only to hold
`addType()` for a page still open with a legacy block (07 §7.6); site_users' three retired
block types migrate onto a page view via `retired.ts`'s raw-HTML/class-name scan
(`findRetiredTypeInHtml`), which needs no GrapesJS type registered at all. `universalTraits`
(`data-siteusers-visible-to`) is untouched — contributed to every block, unrelated to this
module's own surface change.

`core/site_users_components.ts` and `core/component_scripts/site_users_component_script.ts`
deleted outright (nothing else imported them).

### `pageLevel.ts` + `retired.ts` (§8.3)

`SUBPAGE_LABELS` gains `login` / `dashboard` / `forgot-password` / `reset-password`
(`register` already existed, shared with events). `SITE_USERS_KIND` is an identity map onto
`data-siteusers-kind`. `authDefaultForSubpage('dashboard')` joins `orders` / `my-bookings` /
`my-registrations` in defaulting to page auth. `pageModuleAttributes` stamps `data-design`
from `pageSiteUsersDesign` and `data-redirect` from `pageSiteUsersRedirect` (read by
`external/ui/site_users/ui.ts`'s `suHref()` for where Dashboard sends a signed-in visitor).

`retired.ts`: `module-siteusers-login` → Login, `module-siteusers-register` → Register,
`module-siteusers-menu` → **Dashboard** (User Menu is dropped as a concept — a header/nav
widget is a separate future concern per the plan's own decision — but the account hub it
pointed at is the Dashboard page, so that is where a page still carrying the old block lands).
All three copy the block's `data-redirect-to` onto `pageSiteUsersRedirect` via `extra`.

### Chrome + canvas preview (§8.5, §8.6)

**New** `core/siteUsersChrome.ts` (mirrors `eventsChrome.ts`) and `core/siteUsersTemplates.ts`
(mirrors `eventsTemplates.ts`). Both are written against the *actual* markup
`external/ui/site_users/ui.ts` already paints (07 shipped this runtime) — `[data-su-forms]`,
`[data-su-panel]`, `[data-su-field]`, `[data-su-status]`, `[data-su-link]`,
`[data-su-session]`, `.su-btn` / `.su-btn-secondary`, `footer[data-afs-footer]` — rather than
the other way around, so canvas and published page cannot drift. `designStructCss` is called
with `hero: 'section[data-afs-hero]'`, `card: '[data-su-panel]'`, `grid: '[data-su-forms]'`,
`cta: '.su-btn, button'`, giving all six designs the same structural treatment events and
appointments get.

`renderSiteUsersView(subpage, config, name, theme)` renders all five subpages from a
`SiteUsersPreviewConfig` (the shape `GET /portal/api/siteusers/public/config` returns) —
Register reads `allow_registration` / `require_username` / `password_min_length` /
`sso_providers` the same way the runtime does, including the "Registration closed" state.

### Editor + publish wiring (§8.6, §8.7, §8.8)

`pageTypes.ts` — `renderSiteUsersTemplate` (one fetch, not two: every subpage of a module
shares one config, unlike events/appointments which vary by ref) + `renderCurrentSiteUsersPage`
+ the DOM grabs / `bindSelectedInstance` branch / `showConditionalSections` /
`loadPageType` / `page:select` / design-select-change wiring, each a one-for-one copy of the
events pattern. `ensureEditorModuleReadyCss`'s kind list gains `'siteusers'`.

`publish.ts` — `withSiteUsersChrome` in the chrome if/else chain, `DESIGN_KIND_SEL['site-users']`,
`FIRST_VIEW['site-users'] = 'login'`, `pageBinding.properties` gains `pageSiteUsers` /
`pageSiteUsersDesign` / `pageSiteUsersRedirect`.

**Dashboard route protection (§8.8)** — `collectPagesForPublish` collects every Dashboard
subpage of a bound Site Users page into `pendingSiteUsersDashboards` (subpaths + module ref +
the sibling Login page's slug, found via `findSubpagePage`), and after a successful publish
the button handler `PUT`s each to `/portal/api/siteusers/routes` — best-effort: a failure
`console.error`s and does not undo or fail the publish that already succeeded (site_users/12
§12.7's rule for route config saving independently of publishing).

`resources/views/portal/builder/index.blade.php` — `<option value="site-users">Site Users
</option>` on the page-type select, plus `#site-users-select-section` / `#site-users-design-
section`, copied verbatim from the events blocks.

### Seeder (§8.9)

`database/seeders/SiteUsersDemoSeeder.php` — `demo-site-users` module (registration open, no
email verification, 8-character password minimum — deliberately more permissive than the
schema default of 12, for a frictionless demo signup) + one member (`demo-usr-owner`,
`member@appfactory.studio` / `demo-member-pass`, logged once at seed time). Wired into
`DatabaseSeeder::run()` alongside the events/appointments demo blocks, gated on
`laravel_site_user_modules` having no `demo-%` row yet.

## Verified live

No admin browser session was used for this (see Residue — the standing rule now is: I don't
log into anything myself). Instead, a throwaway `.mjs` script
(`resources/js/portal/builder/tests/verify-siteusers-publish.mjs`, deleted after use — the
`gen-module-pages.mjs` convention [06](06-design-themes.complete.md) already established) drove
the **real** production code — `compileRuntime`, `stampPageModuleHtml`, `renderSiteUsersView`,
`withSiteUsersChrome`, `resolveTheme` — and POSTed the result to the real, unauthenticated
`/portal/api/v1/builder/publish` endpoint (routes/portal.php's `api/v1/builder` group carries
no `ApiAuthMiddleware` — a pre-existing gap, not something this step introduced) against a
throwaway project (`publishRelease()` versions a whole project at once, so reusing the shared
demo project would have deactivated its other pages).

| Check | Result |
|---|---|
| `GET /verify-siteusers-login` | `200`, `data-siteusers-kind="login"`, `data-siteusers-module="demo-site-users"`, both SDK `<script src>` tags resolve to `https://www.test/external/{api,ui}/sdk.js` (local env) |
| `GET /verify-siteusers-register`, `-forgot-password`, `-reset-password` | `200`, correct `data-siteusers-kind` each |
| `GET /verify-siteusers-dashboard`, no session | **`302` to `/verify-siteusers-login`, body is the redirect page (402 bytes) — no dashboard markup ever leaves the server.** The `laravel_builder_routes` row (`requires_auth: true`, `site_users_id: demo-site-users`, `login_route: /verify-siteusers-login`) was written directly via `tinker` (same effect as the admin-authenticated `SiteUsersRouteProtectionController::saveRoute()`, exercised without needing that admin session) |
| Design flip (`classic` → `bold` republish of Login alone) | Served wrapper's `data-design` flips to `"bold"`; served CSS's `[data-siteusers-kind][data-design="bold"]` block matches `DESIGN_THEMES.bold` exactly (`--afs-accent:#0f172a`, `--afs-radius:2px`, …) |

Cleaned up after: the throwaway project deleted via the real `DELETE
/portal/api/v1/builder/projects/{ref}` endpoint, its 6 `laravel_builder_published` rows and the
`laravel_builder_routes` row deleted via `tinker`, both throwaway scripts removed. `demo-site-
users` / `demo-usr-owner` (the seeder's rows) were left in place — they are the permanent demo
data, not throwaway.

**Not verified live:** the actual register → land-on-dashboard and sign-in → redirect-to-
dashboard round trips, and cycling the Design picker through all six designs in the real
GrapesJS canvas. Both need either an authenticated portal admin session (to drive the builder
UI) or a throwaway end-user account exercising the public register/login API — the former I
will not do myself (see Residue), and the latter was judged out of scope for "verify the
publish/serve path" and left for the user or a follow-up pass to drive interactively.

## Residue

| # | Item | Where it belongs |
|---|---|---|
| 1 | Interactive browser verification (register/login/dashboard round trip in a real session; cycling all six designs in the GrapesJS canvas) | Needs a human-driven (or explicitly user-authorized) portal admin session — not something to script around. |
| 2 | `site_users/12-builder-integration.md` deferred item #2 ("Account and Password-reset builder **blocks**, follow the Login block's shape") is resolved, but as **pages**, not blocks — noted in that plan's completion doc rather than built as originally envisioned. |
| 3 | Same residue events/appointments already carry: real `api-ui` / `api-ui-sandbox` deploy pending: `Portal.ui.siteUsers` renders only a placeholder on a page published on sandbox/prod before that ships (07 §7.7's noted cost of "zero"). |
| 4 | `resources/js/portal/admin/portal.d.ts` drift (07's residue #3) — unrelated, still not touched. |

## Verification

```
cd w/www
npx tsc --noEmit
npm run check:modules
npm run test:builder
npm run build
```

Server: `php artisan db:seed --class=SiteUsersDemoSeeder` (idempotent — safe to run twice).
