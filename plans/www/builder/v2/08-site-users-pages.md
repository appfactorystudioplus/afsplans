# 08 — Site Users as a page-level module

## Context

Site Users is the last module a website actually needs and the one the builder never
finished. `site_users/12` shipped it as **palette blocks** (Login / Register / User Menu
dragged onto a page). That is the wrong surface for the same reason ecommerce, events and
appointments moved off it in v2: an auth area is *pages*, not a box nested inside one page,
and "this route requires sign-in" has to be a property of the route, not of a component
someone can drag out of a wrapper.

This step makes `site_users` a `surface: 'page'` module like the other three. Binding a
Site Users module instance to a page creates its subpages — **Login, Register, Dashboard,
Forgot password, Reset password** — each a real published route. It gets the same
six-theme Design picker and live editor preview as [06](06-design-themes.md), the Dashboard
route gets real server-side protection, and a seeder creates a working module + member so
the flow is exercisable from a fresh `db:seed`.

Depends on [07](07-externals-ui-layer.md): the runtime is `Portal.ui.siteUsers.mount`,
not a fat component script.

Decisions taken with the user:
- **Subpages**: login, register, dashboard, forgot-password, reset-password (5).
- **The old Login / Register / User Menu blocks**: all three retired. A page-level module
  must not add palette blocks (`checkPageModulesDoNotAddBlocks`). User Menu is dropped;
  a header/nav widget is a separate future concern.
- **Design themes**: full parity now — `siteUsersChrome.ts` + `designStructCss` + the
  `#site-users-design-section` picker + `renderSiteUsersTemplate` canvas preview + the
  publish brand-accent bake.
- **Dashboard guard**: both — a `laravel_builder_routes` row written when the dashboard
  subpage binds (so `BuilderPublishedController` refuses the HTML to a guest), plus the
  runtime's `su.me()` → redirect-to-login fallback.

## 8.1 The backend already exists

`site_users/04–06` shipped the schema and API. Nothing server-side is new except the
seeder and the auto-written route-protection row.

| Need | Exists |
|---|---|
| Register / login / logout / me / update / change-password / sessions | `SiteUsersPublicController` + `SiteUsersAuthService` (`/portal/api/siteusers/public/*`) |
| Forgot / reset / verify / resend | same controller, `SiteUsersVerificationService` |
| Module config for the editor preview | `GET /portal/api/siteusers/public/config?module=<ref>` → `SiteUserModule::toPublicConfigArray()` + `sso_providers` |
| Instance list for the picker | `GET /portal/api/siteusers/status` → `siteUsersInstances` provider (`core/modules/instances.ts`) |
| Route protection | `laravel_builder_routes` + `BuilderRoute::forSlug()` inheritance + `BuilderPublishedController::show()` guard (`site_users/12` §12.5) |
| SDK | `window.Portal.siteUsers.create(moduleId)` — `login`, `register`, `me`, `updateProfile`, `changePassword`, `sessions`, `revokeSession`, `logout`, `requestPasswordReset`, `resetPassword` (`portal.d.ts` `PortalSiteUsersInstance`) |

## 8.2 The module declaration

`core/modules/site_users.module.ts` — rewrite to the events/appointments shape:

```ts
export const siteUsersModule: BuilderModule = {
    surface: 'page',
    id: 'site_users',
    version: 2,                       // shape change — was a block module
    label: 'Site Users',
    icon: 'fa fa-user',
    category: 'Site Users',
    availability: { requires: ['site_users'], siteUsers: 'none' },
    instanceRefTrait: 'data-siteusers-module',
    traits: [
        { name: 'data-siteusers-module', label: 'Site Users module',
          type: 'instance-select', module: 'site_users', required: true },
    ],
    universalTraits: [ /* the "Visible to" trait — KEPT; every module contributes it */ ],
    pageBinding: {
        pageType: 'site-users',
        subpages: ['login', 'register', 'dashboard', 'forgot-password', 'reset-password'],
        refField: 'data-siteusers-module',
        pageProperty: 'pageSiteUsers',
    },
    runtime: externalUi(
        'Rendered by Portal.ui.siteUsers (external/ui/sdk.js), which self-installs on '
        + 'every published page and mounts every [data-siteusers-kind] root. No script '
        + 'is serialized into this page — see builder/v2/07-externals-ui-layer.md.',
    ),
    // no register() — page-level modules add no blocks; no component_scripts file at all (07 §7.6)
};
```

- `core/site_users_components.ts` + the old block registration — **delete**. `retired.ts`
  gains the three old types (`module-siteusers-login` / `-register` / `-menu`) so a page
  that still has one migrates to a placeholder (07 §7.5.1 pattern, `PAGE_LEVEL_RETIRED_KINDS`).
- `universalTraits` (`data-siteusers-visible-to`) stays exactly as it is — it is consumed
  by `universalTraits.ts` for *all* modules and is unrelated to the surface change.
- `core/modules/index.ts` — no line change (already imported); the consistency gate
  (`checkPageModulesDoNotAddBlocks`, `checkModuleRegistry`) must stay green.

## 8.3 `pageLevel.ts` + kinds

- `SUBPAGE_LABELS` — `login: 'Log in'`, `register: 'Register'`, `dashboard: 'Dashboard'`,
  `'forgot-password': 'Forgot password'`, `'reset-password': 'Reset password'`.
- `SITE_USERS_KIND` map — identity (`login→login`, …); `kindForSubpage('site_users', …)`
  returns `{ name: 'data-siteusers-kind', value }`.
- `authDefaultForSubpage('dashboard')` → `'page'` (joins `orders` / `my-bookings` /
  `my-registrations`).
- `pageModuleAttributes` — a `site_users` branch: stamp `data-design` from
  `pageSiteUsersDesign`; the module ref comes from `pageProperty` via the generic path
  (already handled). Add `data-redirect` from `pageSiteUsersRedirect` when set.

## 8.4 Runtime — `external/ui/site_users/ui.ts`

Delivered by [07](07-externals-ui-layer.md) §7.4. **No component script exists for this
module at all** (07 §7.6, "zero" — `runtime: externalUi(...)`). `Portal.ui.siteUsers.install()`
scans `[data-siteusers-kind]` on `DOMContentLoaded` and mounts every root itself; that is
the only caller, on every subpage this module creates.

## 8.5 Chrome + design themes

`core/siteUsersChrome.ts` — mirror `eventsChrome.ts`:

- `[data-siteusers-kind]` scope, `themeCssVars(DESIGN_THEMES.classic)` default, generated
  `[data-design]` token blocks (`designTokenBlocks`), spinner + `:not([data-siteusers-ready])`.
- `designStructCss('[data-siteusers-kind]', { hero: 'section[data-afs-hero]',
  card: '[data-su-panel]', grid: '[data-su-forms]', cta: '[data-su-submit], button' })`
  — the auth form is the "card", the panel stack is the "grid". All six looks apply.
- Form styling: `[data-su-field]` label+input pairs, `[data-su-status]` (error / success),
  `[data-su-panel]` (dashboard sections), `[data-su-session]` rows, SSO buttons,
  `footer[data-afs-footer]`.
- `cssHasSiteUsersChrome`, `withSiteUsersChrome(css, opts)`.

Wire-in (each mirrors the events/appointments line already there):
- `publish.ts` — chrome branch `else if (pageBinding.pageType === 'site-users') css =
  withSiteUsersChrome(...)`; `DESIGN_KIND_SEL['site-users'] = '[data-siteusers-kind]'`;
  `pageBinding.properties` gains `pageSiteUsers`, `pageSiteUsersDesign`, `pageSiteUsersRedirect`.
- `pageLevel.ts` `pageModuleAttributes` (§8.3).
- `pageTypes.ts` `ensureEditorModuleReadyCss` — add `'site-users'` to the kind list.

## 8.6 Editor canvas preview

`core/siteUsersTemplates.ts` — `renderSiteUsersView(subpage, config, name, theme) →
{ html, styles }`, emitting the same hooks `external/ui/site_users/ui.ts` paints, driven
by `config` (which fields, SSO providers) or sample defaults. `styles: siteUsersChromeCss`.

`pageTypes.ts` — `renderSiteUsersTemplate(moduleRef, design, targetPage)` mirroring
`renderEventsTemplate` (`pageTypes.ts:362`):
- fetch `GET /portal/api/siteusers/public/config?module=<ref>` (public, no auth),
- `renderSiteUsersView`, `stampPageModuleHtml` with `pageType: 'site-users'`,
- `themedStyles = '[data-siteusers-kind]{'+brandAccentVar(theme)+'}\n'+styles`,
- persist + `pages.setSnapshot`, `applyPageStyles` in group `template-site-users-${design}`,
  `injectFrameStyles`, `ensureEditorModuleReadyCss`.
- `renderCurrentSiteUsersPage()` wrapper; `currentDesign('pageSiteUsersDesign')`.

Hook the same three triggers events uses: `bindSelectedInstance('site-users', …)`,
the `page:select` placeholder branch, the design-select `change`.

## 8.7 Blade + panel wiring

`resources/views/portal/builder/index.blade.php`:
- page-type `<select>` — add `<option value="site-users">Site Users</option>`.
- `#site-users-select-section` — `<select id="page-site-users-select">` + a "Create one"
  link to `/portal/admin1#/siteusers`.
- `#site-users-design-section` — `<select id="page-site-users-design">` with the six
  options (copy the events block verbatim).

`pageTypes.ts` — the DOM grabs (`siteUsersSection`, `siteUsersDesignSection`,
`siteUsersSelect`, `siteUsersDesignSelect`), `showConditionalSections` /
`loadPageType` branches, the select `change` handlers, `syncDesign('pageSiteUsersDesign', …)`.
All are one-for-one copies of the appointments wiring.

## 8.8 Dashboard route protection

When `ensureBoundSubpages` creates the `dashboard` child (or on republish), write a
`laravel_builder_routes` row for its slug:

```
subpaths = <dashboard slug>
requires_auth = true
site_users_id = <bound module ref>
unauthenticated_behaviour = 'redirect'
login_route = <login slug>
```

- The write path: `publish.ts` `collectPagesForPublish` already loops every page and has
  `pageBinding`. Add: when `pageType === 'site-users'` and `pageSubpage === 'dashboard'`
  and a module ref is bound, `POST /portal/api/siteusers/routes` (the existing
  `SiteUsersRouteProtectionController::putRoute`, which runs `assertHierarchyAllows`).
  This is a **best-effort** call after the transactional publish — a failure logs, it does
  not fail the publish (route config saves independently, `12` §12.7).
- The runtime fallback (`external/ui/site_users/ui.ts` `dashboard`): `su.me()` 401 →
  `location.href = <login sibling>`. Covers the pre-protection window and a
  `not_found`-mode route.
- `BuilderRoute::forSlug()` inheritance means protecting `…-dashboard` is enough; nothing
  else to do for nested dashboard pages a tenant adds later.

## 8.9 Seeder — `SiteUsersDemoSeeder`

`database/seeders/SiteUsersDemoSeeder.php`, modelled on `EventsDemoSeeder`:

- `env('APP_ENV') === 'production'` → return.
- `SiteUserModule::updateOrCreate(['reference_num' => 'demo-site-users'], [...])` —
  `name: 'Demo Site Users'`, `is_active: true`, `allow_registration: true`,
  `require_email_verification: false`, `require_username: false`,
  `password_min_length: 8` (demo-friendly), `session_lifetime_days: 30`,
  `allowed_origins: null`.
- The initial member — idempotent on `reference_num`:
  - `SiteUser::updateOrCreate(['reference_num' => 'demo-usr-owner'], ['site_users_id' =>
    'demo-site-users', 'email' => 'member@appfactory.studio', 'display_name' =>
    'Demo Member', 'is_active' => true, 'email_verified_at' => now()])`.
  - `SiteUserIdentity::updateOrCreate(['user_id' => $user->id, 'type' => 'password'],
    [...])` then `$identity->setPassword('demo-member-pass')` + save (meets the
    8-char min). Log the credentials once at seed time.
- `DatabaseSeeder::run()` — after the appointments block:
  ```php
  if (DB::table('laravel_site_user_modules')->where('reference_num', 'like', 'demo-%')->count() === 0) {
      $this->call(SiteUsersDemoSeeder::class);
  }
  ```

## 8.10 Demo bound pages + verification

Same tooling as `06` (throwaway `gen-module-pages.mjs` + in-place published-row refresh):

1. Bind `demo-site-users` to `new-page-account` in `projeKEnNosrOA3M` → 5 subpages
   (`new-page-account-login` … `-reset-password`), `pageSiteUsersDesign = 'classic'`.
2. Insert the `laravel_builder_routes` row for `new-page-account-dashboard`.
3. `npm run build-external-ui-webpack`; local SDK override (07 §7.7) live.
4. Verify on `www.test`:
   - `/new-page-account-register` → create an account → lands on `…-dashboard`.
   - `/new-page-account-login` signed out → the form; signed in → redirects to dashboard.
   - `/new-page-account-dashboard` signed out → **redirected before any HTML**
     (server guard) → `…-login`.
   - Dashboard: edit display name, change password, revoke a session, sign out.
   - `/new-page-account-forgot-password` → success copy; `…-reset-password?token=…`.
   - Flip the design through all six → distinct looks, brand accent `#911818` constant.

## 8.11 Tests + docs

- Builder tests: `site_users.module` page binding + no blocks (`checkPageModulesDoNotAddBlocks`);
  `renderSiteUsersView` emits the runtime hooks per subpage (`[data-su-form]`,
  `[data-su-panel]`, `footer[data-afs-footer]`); `pageModuleAttributes` stamps
  `data-siteusers-kind` + `data-design`; `kindForSubpage('site_users', 'dashboard')`;
  `authDefaultForSubpage('dashboard') === 'page'`; `retired.ts` migrates the three old
  types; `checkInstanceProviders` still green.
- `external/ui`: `renderSiteUsersView`-equivalent hook assertions in an `external/ui`
  test if the package has a harness; otherwise rely on the builder mirror + live check.
- `check-modules.mjs`, `npx tsc --noEmit` (builder), `npx tsc -p .../external/ui/...`,
  `npm run test:builder`, `npm run build`, `npm run build-external-ui-webpack`.
- Server: a `SiteUsersDemoSeeder` feature test — `php artisan db:seed --class=SiteUsersDemoSeeder`
  twice is a no-op; the member can `login()`.
- Docs: this file + `complete/08-site-users-pages.complete.md`;
  `complete/00_extras.md` E46 (07) + E47 (08); update the `builder/v2/README.md`
  "other seven modules" line (site users is no longer a block module); a pointer in
  `site_users/README.md` and `site_users/12`'s completion doc (blocks retired,
  `07`/`08` supersede §12.3).

## 8.12 Repo

**www** for code, **packages/afsserver** for the plan. One www commit for
[07](07-externals-ui-layer.md), one for 08. `main` only, on request.
