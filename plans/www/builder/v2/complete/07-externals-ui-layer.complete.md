# 07 — The externals UI layer (complete)

## Status

**Done.** `external/api` is DOM-free; `external/ui` is the reference runtime for
every page-level module. Events and appointments moved off their serialized
builder component scripts entirely — `Portal.ui.events` / `Portal.ui.appointments`
self-mount every `[data-*-kind]` root, and `external/api/site_users`'s
`LoginFlow` / `RegistrationFlow` moved to `external/ui/site_users` (built here,
consumed by [08](../08-site-users-pages.md) (not yet built)).

- **www** — one commit on `main` (pending).
- **452 builder tests**, **22 new `external/ui` tests**
  (`npm run test:external-ui`, new script). `tsc --noEmit` clean for the
  builder, `resources/js/external/api/tsconfig.external.json`, and
  `resources/js/external/ui/tsconfig.external.json`.
- `npm run build-external-webpack` / `build-external-ui-webpack` both green;
  `public/external/ui/sdk.js` grew 79 KiB → 156 KiB for the three new modules.
- Verified live on `local` (below) — full events registration incl. receipt,
  appointments hold→book against real availability, the new
  `Portal.appointments.staff()` method.

## What shipped

### `external/api` — DOM-free (§7.2)

Deleted `external/api/site_users/LoginFlow.ts` / `RegistrationFlow.ts` and
`SiteUsersClient`'s `loginFlow()` / `registerFlow()` methods (they
dynamic-`import()`ed those files). `siteUsersNamespace` now exports only
`create` / `Client`. `portal.d.ts` — `PortalSiteUsersInstance` /
`PortalSiteUsersNamespace` lost the same four members.

New, filling a real gap rather than a drift: `PortalAppointmentsAPI.staff()` /
`ExternalAppointmentsAPI.staff()` — the old component script's `staff` view hit
`/appointments/{ref}/staff` with a raw `fetch()`; there was no typed method to
call. `PortalAppointmentStaff { reference_num, name }` added to `portal.d.ts`.

`rg "innerHTML|createElement|addEventListener" resources/js/external/api` now
returns nothing.

### `external/ui/{events,appointments}/ui.ts` — the reference runtime (§7.3)

Ported view-for-view from the deleted `events_component_script.ts` (~640
lines) / `appointments_component_script.ts` (~400 lines): four events views
(list/detail/register incl. the Direct-Payments card flow/my-registrations)
and five appointments views (booking incl. hold-before-fill/services/staff/
next-available/my-bookings). Same markup hooks as [06](06-design-themes.complete.md)
— the builder's chrome CSS themes this output unchanged.

Two real differences from the deleted scripts, not just relocation:

1. **Appointments now goes through `window.Portal.appointments`** (typed),
   not raw `fetch()` against `/portal/api/appointments/storefront/*`. Events
   already used `window.Portal.events`; this closes the inconsistency.
2. **Shared helpers instead of copy-paste** — `external/ui/shared/{dom,format,nav}.ts`
   (`esc`, `waitFor`, `fmtMoney`, `fmtDate`, `siblingHref`, `paintFooter`,
   `mountAll`, `onReady`). A serialized component script cannot `import`; a
   normal bundled module can, so `esc`/`fmtDate`/etc. exist once now, not
   three times.

`install()` (both modules) waits for `window.Portal.<module>`, then scans
`[data-*-kind]` on `DOMContentLoaded` and `pageshow` and mounts each — the same
pattern `ecommerceUi.install()` already used for `[data-store-ref]`.
`injectChrome()` fallback CSS is condensed (the full six-design chrome now
lives in the builder's `css` column).

### `external/ui/site_users/ui.ts` — new (§7.4)

`siteUsersUi = { install, mount }`, absorbing `LoginFlow` / `RegistrationFlow`
plus three views the old `external/api` package never had a UI for at all:
dashboard, forgot-password, reset-password. Consumed by
[08](../08-site-users-pages.md) (not yet built); built here because it belongs with the
other three under one `Portal.ui` namespace.

### `external/ui/main.ts` (§7.5)

`Portal.ui` gains `events`, `appointments`, `siteUsers` alongside `ecommerce`;
each `install()`s on load. `portal.d.ts` `PortalUiModuleAPI { install(),
mount(root) }` + `PortalUiNamespace` updated to match.

### Builder — zero, not a shim (§7.6)

**Deleted** `core/component_scripts/events_component_script.ts` and
`appointments_component_script.ts` outright — no replacement script. Confirmed
first that the editor canvas iframe never loads `external/ui/sdk.js` at all (no
`canvas:` script config in `core/editor/index.ts`), so there is nothing that
could run mid-edit; `isEditorMode()` guards in the old scripts existed only to
stop *that* script reaching `window.Portal`, a problem that does not exist once
there is no script.

`runtime` stays required on `BuilderModule`. `noRuntime()` was the wrong tool —
its own contract refuses a module that plainly needs behaviour, and these do.
Added a third, equally-disciplined shape: **`externalUi(reason)`** →
`{ externalUiReason: reason }`. `events.module.ts` / `appointments.module.ts`
now declare it; `consistency.ts` check 2 accepts it alongside `script` and
`presentationalReason`; `compileRuntime.ts` needed no change — `runtimeSource()`
already returns `null` for a module with no `.script`, proven by `noRuntime()`
modules.

`events_components.ts` / `appointments_components.ts` (the legacy per-kind
`addType` registrations, kept so an old `module-events-*` / `module-appointments-*`
component still opens) dropped their `script:` property — nothing to reference
any more; the attribute-based `Portal.ui.*` scan mounts a legacy-typed root the
same as any other.

### Local testability (§7.7)

Published pages hardcoded the remote `api-sandbox` / `api-ui-sandbox` hosts
outside production, so a local externals rebuild never reached a served page.
`BuilderPublishedController::show()` now chooses the SDK root purely from
`APP_ENV` — three named states, no separate env toggle: `local` → this app's
own origin (`https://www.test/external/{api,ui}/sdk.js`, served from
`public/external/{api,ui}/sdk.js` like any other static asset — no upload
required); `production` → `https://api.appfactory.studio` /
`https://api-ui.appfactory.studio`; anything else (development, staging,
unset, a typo) → the sandbox hosts, the fail-safe default. `layout.blade.php`
/ `index.blade.php` changed to treat `afssdk` / `afssdkui` as full
`<script src>` values rather than a `{host}` fragment (that component is only
ever used by the published-page path — no other view consumes the old
convention). `publishedPageCsp()` dedupes `$sdkHost` / `$sdkUiHost` with
`array_unique()` for the local case, where both are `https://www.test`.

## Verified live

Re-served the design-themes demo pages (`06`) after regenerating their
published rows — same `gen-module-pages.mjs` + in-place row-refresh tooling,
run again and deleted afterward. `js` column is now **empty** for both modules
(`js bytes: 0` on every subpage) — confirmed the compiled page carries no
script at all.

| URL | Result |
|---|---|
| `/new-page-events-list` | Renders via `Portal.ui.events.mount` — vivid design, identical to before the migration. No console errors. |
| `/new-page-events-detail?event=demo-tech-conf` | Hero, note, tickets — unchanged. |
| `/new-page-events-register?event=demo-tech-conf` → submit (free Community Pass) | **Full round trip**: `register()` → real `registration_reference_num`, receipt painted, footer. |
| `/new-page-events-my-registrations` | Correct empty state (session-scoped, as before). |
| `/new-page-appointments-booking` | Slate design; `Portal.appointments.availability()` loads real slots; clicking one calls `hold()` (`200 OK`, "Time held…"); `book()` called on confirm — hit a genuine `AP_E_SLOT_TAKEN` from repeated test runs today, and the UI displayed the server's message correctly (proves the error path, not just the happy path). |
| `/new-page-appointments-services` | All three seeded services, correct pricing (`$120.00` / `$180.00` / free shows no price). |
| `/new-page-appointments-staff` | **New `Portal.appointments.staff()` method** — "Alex Morgan", "Sam Rivera" render correctly against the real endpoint. |

## Residue

| # | Item | Where it belongs |
|---|---|---|
| 1 | Real `api-ui` / `api-ui-sandbox` deploy | separate infra; until it ships, a page published on sandbox/prod before that deploy renders only its placeholder root for these three modules (§7.7's noted cost of "zero") |
| 2 | Ecommerce keeps its current split (`commerce_component_script.ts` load-bearing, `external/ui/ecommerce` supplementary) | not touched — its hydrate-designed-markup logic overlaps the two in a way this step did not attempt to unwind |
| 3 | `resources/js/portal/admin/portal.d.ts` — a second, drifted copy with neither `PortalUiNamespace` nor the `siteUsers` shape this step changed | pre-existing drift, unrelated to this change; not updated |
| 4 | `resources/js/external/api/systemsusers/*` — its own orphaned `LoginFlow.ts` / `RegistrationFlow.ts`, imported by nothing except `samples/main-example.ts` | dead code predating this step; left alone (scheduled for removal, `site_users/02` P10) |

## Verification

```
cd w/www
npx tsc --noEmit
npx tsc -p resources/js/external/api/tsconfig.external.json --noEmit
npx tsc -p resources/js/external/ui/tsconfig.external.json --noEmit
npm run test:builder
npm run test:external-ui
npm run build-external-webpack
npm run build-external-ui-webpack
```

Then, with `APP_ENV=local`, open
`/new-page-events-list` and `/new-page-appointments-booking` on `local` — view
source shows `<script src="/external/ui/sdk.js">`, and the network tab shows no
inline page script for either module.
