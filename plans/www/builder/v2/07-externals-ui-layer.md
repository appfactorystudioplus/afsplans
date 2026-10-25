# 07 — The externals UI layer

## Context

The externals SDK is meant to split cleanly in two:

- **`resources/js/external/api`** → `public/external/api/sdk.js` → `window.Portal.*` —
  pure data. Fetch, session, events. A developer can take this bundle alone and
  build their own front end against the module system.
- **`resources/js/external/ui`** → `public/external/ui/sdk.js` → `window.Portal.ui.*` —
  the reference UI that paints what the API returns.

Today that split is only half done:

1. **`external/api/site_users/LoginFlow.ts` and `RegistrationFlow.ts` render DOM**
   (`root.innerHTML = …`, `addEventListener`) and sit in the *API* package. `site_users/07`
   §7.3 put them there deliberately; this step reverses that.
2. **Events and appointments have no `external/ui` presence at all.** Their entire runtime —
   list/detail/register views, booking flow, the design-theme markup added in
   [06](06-design-themes.md) — lives in the builder's serialized component scripts
   (`core/component_scripts/{events,appointments}_component_script.ts`, ~640 / ~400 lines).
   Those cannot `import`, cannot share helpers, and cannot be swapped by a developer.
3. Only `external/ui/ecommerce` exists, and even there the component script
   (`commerce_component_script.ts`) is still the load-bearing runtime — `external/ui`
   just adds cross-page chrome (toast, cart FAB) and a fallback `mount()` (E7).

This step makes `external/ui` the **reference runtime** for every page-level module
(ecommerce keeps its current split; events / appointments / site_users move their view
rendering into `external/ui`), and leaves `external/api` DOM-free. It is the foundation
[08](08-site-users-pages.md) builds the Site Users pages on.

## 7.1 What moves where

| Concern | Today | After 07 |
|---|---|---|
| `Portal.events.*` / `Portal.appointments.*` / `Portal.siteUsers.*` data calls | `external/api` | unchanged |
| Events 4-view render (`renderList`/`renderDetail`/`renderRegister`/`renderMyRegistrations`) | `events_component_script.ts` | `external/ui/events/ui.ts` |
| Appointments 5-view render (booking flow + read-only views) | `appointments_component_script.ts` | `external/ui/appointments/ui.ts` |
| Site Users auth forms (`LoginFlow`/`RegistrationFlow` + account/reset) | `external/api/site_users/*Flow.ts` | `external/ui/site_users/ui.ts` |
| Builder component script (serialized `js` column) | full runtime | **gone** — no script at all; `external/ui`'s own `install()` DOM-scan is the only caller |
| Design-theme chrome CSS (`{events,appointments}Chrome.ts`, `designStructCss`) | builder | **unchanged** — stays in the builder, written to the page `css` column |
| Editor-canvas preview (`{events,appointments}Templates.ts`, `renderEventsView`) | builder | **unchanged** — editor-only, no runtime |

The markup contract is the shared boundary: `external/ui` emits exactly the hooks the
builder chrome already styles — `<section data-afs-hero>`, `[data-afs-kicker]` /
`[data-afs-sub]` / `[data-afs-cta]`, `[data-afs-features]`, `[data-events-list]`,
`[data-events-ticket]`, `form[data-events-register]`, `[data-ap-flow]` / `[data-ap-list]`,
`footer[data-afs-footer]`. `06`'s hook list is the spec.

## 7.2 `external/api` — DOM-free

- **Delete** `external/api/site_users/LoginFlow.ts`, `RegistrationFlow.ts`.
- `external/api/site_users/index.ts` — `siteUsersNamespace` drops `LoginFlow`,
  `RegistrationFlow`. `SiteUsersClient` / `createSiteUsersClient` stay (pure).
- `SiteUsersClient` — remove `loginFlow()` / `registerFlow()` methods if present
  (verify: they are only referenced by the deleted files + the old
  `site_users_component_script.ts`).
- `portal.d.ts` —
  - `PortalSiteUsersInstance` loses `loginFlow()` / `registerFlow()`.
  - `PortalSiteUsersNamespace` loses `LoginFlow` / `RegistrationFlow` constructors.
  - These reappear only as behaviour inside `Portal.ui.siteUsers` (§7.4).
- Grep gate: `rg "innerHTML|createElement|addEventListener|querySelector" resources/js/external/api`
  returns nothing but type names (`mountSelector` string pass-throughs in
  `events/api.ts` / `appointments/api.ts` are fine — they hand a selector to the
  Direct Payments SDK, they do not touch the DOM).

## 7.3 `external/ui/{events,appointments}/ui.ts` — the reference runtime

Mirror `external/ui/ecommerce/ui.ts` exactly:

```ts
export const eventsUi = { install, mount };
```

- **`install()`** — waits for `window.Portal.events` (poll, 40 × 50ms), then on
  DOM-ready scans `document.querySelectorAll('[data-events-kind]')` and `mount()`s each,
  wires sibling-page nav (`[data-events-back]`, footer links), and re-scans on `pageshow`.
  Idempotent via a `data-afs-ui-mounted` attribute.
- **`mount(root)`** — reads `data-events-kind` / `data-event-ref` / `data-dp-ref` off
  `root`, dispatches to the view renderer, paints into `root`. Every path the current
  `events_component_script.ts` has: `renderList`, `renderDetail`, `renderRegister`
  (including the Direct-Payments `beginPayment` card flow), `renderMyRegistrations`
  (cancel + calendar), `showReceipt`. Port them verbatim, then delete the inline
  `esc`/`fmtDate`/`fmtMoney`/`siblingHref` copies in favour of a shared
  `external/ui/shared/` module.
- **`injectChrome()`** stays — the condensed per-design fallback CSS for a page
  published without the builder chrome. It is smaller now: the full six-design chrome
  is the builder's `css` column; this is the safety net.
- **`paintFooter()`** — the 3-column `footer[data-afs-footer]` from `06`.
- Editor guard: `mount()` is never called in the canvas — the builder's thin shim does
  the `isEditorMode()` check before delegating, and `install()`'s DOM scan only runs on
  a served page (no `.gjs-editor`).

`external/ui/appointments/ui.ts` — same shape, the 5 appointment views
(`booking` with the hold→book flow, `services`, `staff`, `next-available`,
`my-bookings`), consuming `window.Portal.appointments`.

New shared helpers: `external/ui/shared/dom.ts` (`esc`, `spinner`, `waitFor`),
`external/ui/shared/format.ts` (`fmtMoney`, `fmtDate`), `external/ui/shared/nav.ts`
(`siblingHref`, `paintFooter`, `injectChromeFallback` per module).

## 7.4 `external/ui/site_users/ui.ts`

`siteUsersUi = { install, mount }`. `mount(root)` reads `data-siteusers-kind`
(`login` | `register` | `dashboard` | `forgot-password` | `reset-password`),
`data-siteusers-module`, `data-redirect`; then:

```ts
const su = window.Portal.siteUsers.create(moduleId);
const config = await su.init();
```

- `login` — if `su.isAuthenticated()` → redirect to the dashboard sibling. Else email +
  password form → `su.login()`; SSO buttons from `config.sso_providers`; a
  "Forgot your password?" link to the `forgot-password` sibling. Absorbs `LoginFlow.ts`.
- `register` — honours `config.allow_registration` (closed → notice),
  `config.require_username`, `config.password_min_length`; `su.register()`; an
  email-verification notice when `config.require_email_verification`. Absorbs
  `RegistrationFlow.ts`.
- `dashboard` — `await su.me()` (401 → redirect to `login` sibling); profile card + edit
  (`su.updateProfile()`), change password (`su.changePassword()`), active sessions
  (`su.sessions()` / `su.revokeSession()`), sign out / sign out everywhere.
- `forgot-password` — email → `su.requestPasswordReset()`, always-success copy.
- `reset-password` — `?token=` from URL → new-password form → `su.resetPassword()`.

Emits `06`'s hero/panel hooks so `08`'s `siteUsersChrome.ts` themes it.

## 7.5 `external/ui/main.ts`

```ts
existing.ui = Object.assign({}, existing.ui || {}, {
    ecommerce: ecommerceUi,
    events: eventsUi,
    appointments: appointmentsUi,
    siteUsers: siteUsersUi,
});
ecommerceUi.install();
eventsUi.install();
appointmentsUi.install();
siteUsersUi.install();
```

`portal.d.ts` — `PortalUiNamespace` gains:

```ts
interface PortalUiModuleAPI { install(): void; mount(root: HTMLElement): Promise<void>; }
interface PortalUiNamespace {
    ecommerce: PortalUiEcommerceAPI;
    events: PortalUiModuleAPI;
    appointments: PortalUiModuleAPI;
    siteUsers: PortalUiModuleAPI;
}
```

## 7.6 Builder component scripts → deleted, not shimmed

**Decision: zero, not a shim.** `external/ui/{events,appointments}/ui.ts` `install()`
already scans `document.querySelectorAll('[data-events-kind]')` /
`[data-appointments-kind]` on `DOMContentLoaded` and mounts each root itself (the same
pattern `ecommerceUi.install()` uses for `[data-store-ref]`). A per-page caller is
redundant with that scan, and the editor canvas iframe never loads `external/ui/sdk.js`
in the first place (it is not built from `layout.blade.php` — confirmed: no `canvas:`
script config in `core/editor/index.ts`, so `window.Portal` is simply undefined there;
`isEditorMode()` guards existed only to stop a component script calling it). With no
script serialized for these modules, there is nothing that could run in canvas at all.

So: **delete** `core/component_scripts/events_component_script.ts` and
`appointments_component_script.ts` outright. `site_users_component_script.ts` is never
written in [08](08-site-users-pages.md) — `install()` covers it from day one.

`runtime` stays a **required** field on `BuilderModule` (`types.ts`), and `noRuntime()`
is the wrong tool: its own contract refuses it for "a module that plainly needs
behaviour" (`types.ts` — discussions is the one legitimate user, because it has *no*
client behaviour at all, §B26). Events/appointments/site_users have real, rich client
behaviour — it is just delivered by a bundle the page loads once, not text the page
carries. Claiming `noRuntime()` would misstate that. Add a third, equally honest shape:

```ts
// core/modules/types.ts
export interface ModuleRuntime {
    script?: (props: any) => void;
    readonly presentationalReason?: string;
    /** Set by externalUi(). The runtime is Portal.ui.<x>, self-installed by
     *  external/ui/sdk.js (loaded on every published page) — not a serialized script. */
    readonly externalUiReason?: string;
}

export function externalUi(reason: string): ModuleRuntime {
    if (!reason || reason.trim().length < 8) {
        throw new Error('[builder] externalUi() needs a reason a reviewer can read.');
    }
    return { externalUiReason: reason };
}
```

`core/modules/consistency.ts` check 2 (`runtime is empty`) — accept the new field
alongside `script` and `presentationalReason`:

```ts
} else if (typeof m.runtime.script !== 'function'
    && !m.runtime.presentationalReason
    && !m.runtime.externalUiReason) {
    fail(2, m.id, 'runtime is empty — use noRuntime("why") or externalUi("why")');
}
```

`compileRuntime.ts` `runtimeSource()` needs **no change** — it already does
`if (typeof fn !== 'function') return null` for `noRuntime()` modules, and an
`externalUi()` module's `runtime.script` is equally `undefined`, so it already emits
nothing for that module. Proven safe by the modules doing this today.

```ts
// events.module.ts
runtime: externalUi(
    'Rendered by Portal.ui.events (external/ui/sdk.js), which self-installs on every '
    + 'published page and mounts every [data-events-kind] root. No script is serialized '
    + 'into this page — see builder/v2/07-externals-ui-layer.md.',
),
```

Same for `appointments.module.ts`, and for `site_users.module.ts` in [08](08-site-users-pages.md).

- `eventsChrome.ts` / `appointmentsChrome.ts` / `branding.ts` `designStructCss` — **not
  touched**. The chrome CSS is still written to the page `css` column by `publish.ts`.
- `{events,appointments}Templates.ts` + `pageTypes.ts` `renderEventsTemplate` etc. — **not
  touched**. Editor canvas preview paints via `editor.setComponents()` directly and never
  depended on `runtime.script` executing in canvas.
- Existing tests that assert a serialized events/appointments script inside `js` (e.g.
  `compileRuntime.test.ts`'s events case, `events.test.ts` script-shape assertions) get
  rewritten to assert the **absence** of a script and the presence of the stamped
  `data-events-kind` root only — the same shape `noRuntime()` modules already prove out.

## 7.7 Local testability

Published pages currently hardcode the remote SDK hosts for non-production
(`BuilderPublishedController::show()` → `api-sandbox` / `api-ui-sandbox`), so a local
`external/ui` rebuild never reaches `www.test`.

- `BuilderPublishedController::show()` — the SDK root is chosen entirely by `APP_ENV`,
  three named states, no separate toggle: `'local'` → this app's own origin
  (`https://www.test/external/{api,ui}/sdk.js` — the bundles are served from
  `public/external/{api,ui}/sdk.js` like any other static asset); `'production'` →
  `https://api.appfactory.studio` / `https://api-ui.appfactory.studio`; anything else
  (development, staging, unset, a typo) → the sandbox hosts, the fail-safe default so a
  misconfigured `APP_ENV` never serves production.
- `resources/views/components/layout.blade.php` / `index.blade.php` — `afssdk` /
  `afssdkui` are now full `<script src>` values (this component is only ever used by the
  published-page path, so its prop contract could change freely): `src="{{ $afssdk }}"`.
- `publishedPageCsp()` — `$sdkHost` / `$sdkUiHost` are always a concrete origin now
  (never blank), deduped with `array_unique()` for the local case where both are
  `https://www.test`.
- `npm run build-external-webpack && npm run build-external-ui-webpack` refresh
  `public/external/{api,ui}/sdk.js`.

**Rollout residue.** The real `api-ui` / `api-ui-sandbox` deploy is separate infra and
out of scope here. Because there is no shim and no component script, an events /
appointments / site_users page published before that deploy renders **only its stamped
placeholder root** on production/sandbox — no script runs at all until the remote
`external/ui/sdk.js` carries `Portal.ui.{events,appointments,siteUsers}`. That is a
harder failure mode than the shim's error message would have been, and is the deliberate
cost of "zero" — flagged here so the deploy is not treated as optional cleanup. The
checklist: `build-external-ui-webpack` → upload `public/external/ui/sdk.js` to both
hosts → nothing to republish (pages already load the host URL, and start working the
moment the bundle does).

## 7.8 Acceptance

| # | Check | Expect |
|---|---|---|
| U1 | `rg "innerHTML\|createElement\|addEventListener" resources/js/external/api` | no matches |
| U2 | `npx tsc -p resources/js/external/ui/tsconfig.external.json --noEmit` | clean |
| U3 | `npx tsc -p resources/js/external/api/tsconfig.external.json --noEmit` | clean |
| U4 | `npm run build-external-ui-webpack` | `public/external/ui/sdk.js` rebuilt, `Portal.ui.{events,appointments,siteUsers}` present |
| U5 | `npm run test:builder` | green — `checkModuleRegistry` accepts `externalUi()`; events/appointments emit no `js` for their module |
| U6 | local: bind an events page, republish, open `/…-events-list` on `www.test` | renders via `Portal.ui.events.mount`; all six designs still distinct (06 D2–D5 re-run) |
| U7 | events detail → register → pay (demo DP) | full flow works from `external/ui` |
| U8 | appointments booking, services, my-bookings | render + book from `external/ui` |
| U9 | editor canvas — events/appointments page | still shows the design preview (unchanged path) |

## 7.9 Repo

**www** only. One commit. `builder/v2` for this plan (afsserver).
Follow with [08](08-site-users-pages.md).
