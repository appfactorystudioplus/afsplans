# 06 — Design themes + brand colours (complete)

## Status

**Done.** A module-wide **Design** picker (Classic / Bold / Minimal / Editorial)
for the events, ecommerce **and appointments** page-level modules; the tenant's
brand accent overlaid through it; and a live editor-canvas preview for events and
appointments.

- **www** commit `53a3cb0ca` — events + ecommerce (17 files: `branding.ts`,
  `eventsTemplates.ts`, `branding.test.ts` new).
- **www** commit `bfd3022db` — appointments (`appointmentsChrome.ts`,
  `appointmentsTemplates.ts` new; `pageTypes.ts`, `publish.ts`, `pageLevel.ts`,
  `appointments_component_script.ts`, `index.blade.php`, tests).
- **packages/afsserver** — [06 plan](../06-design-themes.md) + this file +
  README row + `05` residue #1 closed.
- **471 builder tests**, `tsc --noEmit` clean, `npm run build` green.

| Phase | Status |
|---|---|
| 6.1 Model — `branding.ts` | Done — 4 themes, `resolveTheme`, `themeCssVars`, `readableInk` |
| 6.2 Brand into the builder | Done — `main.ts` `fetchBrandSettings` → `initEditor({brand})` → `createPageTypes` / `createPublish` |
| 6.3 Page panel | Done — `#page-event-design` / `#page-commerce-design`; `syncDesign` / `currentDesign` |
| 6.4 Events editor preview | Done — `eventsTemplates.ts` + `renderEventsTemplate` (live fetch, mirrors `renderStoreTemplate`) |
| 6.5 Published pages | Done — chrome CSS → `var(--afs-*)` + `[data-design]` blocks (events/ecommerce/appointments); `pageLevel` stamps `data-design`; `publish.ts` bakes brand accent; `ecommerceTemplates` hero/footer take the theme; appointments runtime colours → `var(--afs-*)` |
| 6.6 Acceptance | D1/D6 stub-tested; D2–D5 verified live for events (below); appointments verified via JSDOM runtime tests + stamp |
| 6.7 Residue | brand baked at publish; ecommerce cards neutral; appointments runtime keeps inline styles (now var-based) |

## Appointments (added same feature)

`appointmentsChrome.ts` + `appointmentsTemplates.ts` (new) mirror the events
pair. `pageTypes.ts` gains `#page-appointment-design`, `renderAppointmentsTemplate`
(fetches `/portal/api/appointments/storefront/appointments/{ref}/services` +
`/staff`, paints `renderAppointmentsView` for the 5 subpages), and
`renderCurrentAppointmentsPage`; the design sync uses the shared
`syncDesign('pageAppointmentDesign', …)` / `currentDesign` helpers. `publish.ts`
`DESIGN_KIND_SEL` and the chrome branch handle all three types.
`appointments_component_script.ts` gets an `injectChrome()` fallback, a
`data-appointments-ready` flag, and its hard-coded `#2563eb` / `#cbd5e1` swapped
to `var(--afs-*)`. `index.blade.php` gets `#appointments-design-section`.

**Verified live** (www `05eb9ea3e`): `AppointmentsDemoSeeder` (mirrors
`EventsDemoSeeder`) creates a published "Demo Studio Bookings" appointment —
free Consultation + two paid sessions, two staff, service↔staff links, Mon–Fri
9–5 hours so `/availability` returns slots. Bound to `new-page-appointments`
with `design=editorial` + brand `#911818`:

- `/new-page-appointments-services` / `-staff` — warm cream section band, serif
  ("Georgia") heading, `10px` editorial card radius.
- `/new-page-appointments-booking` — 24 real time slots, `#911818` Book button.

That commit also reworked the runtime's read-only views (`services` / `staff` /
`next-available` / `my-bookings`) to emit `<section>` + `[data-ap-list]` +
`<article>` — the same hooks `renderAppointmentsView` uses — so the chrome styles
them by design rather than by hard-coded inline styles.

## Verified live

Bound **Demo Tech Conference 2026** to `new-page-events` with `design=bold` and
brand `primaryColor1 = #911818` (the value in this DB), published all four views,
opened `/new-page-events-detail?event=demo-tech-conf`:

- module root carries `data-design="bold"`; `--afs-accent` computes to `#911818`.
- dark solid hero band, white heading, heavy weight, 2px-radius ticket cards —
  the Bold design.
- **"Register" / submit buttons are `#911818`** — brand accent wins over the
  design's own accent, exactly as `resolveTheme` intends.
- `/new-page-events-register` — form renders, `data-events-ready="1"`, submit
  `background: rgb(145,24,24)`.

## What shipped

### `core/branding.ts`

`DesignId` = `classic | bold | minimal | editorial`. `DESIGN_THEMES[id]` is a
token record (`accent`, `accentInk`, `accent2`, `surface`, `sectionInk`, `ink`,
`muted`, `line`, `radius`, `font`, `headingWeight`, `heroStyle`) — system fonts
only. `resolveTheme(design, brand)` overlays `brand.primaryColor1` → `accent`
(`accentInk` re-derived via `readableInk` luminance), `primaryColor2` /
`accentColor1` → `accent2`; non-hex values ignored. `themeCssVars(theme)` → the
`--afs-*` declarations. `SettingsBrand` copied in — the builder never imports
`portal/admin`.

### Editor

`main.ts` `fetchBrandSettings()` (GET `/portal/api/settings`, best-effort) runs
in the `bootstrap` `Promise.all` and passes `brand` into `initEditor`.
`pageTypes.ts`: `syncDesign(prop, id)` writes `pageEventDesign` /
`pageCommerceDesign` across the parent + children; the design `<select>` change
re-renders; `renderEventsTemplate(eventRef, design, page)` fetches
`/portal/api/events/storefront/events[/{ref}]` and paints `renderEventsView`
into the canvas with the theme's `--afs-accent !important` prepended, in the
`template-events-{design}` CssRule group. The events `page:select` branch calls
it whenever the canvas is still the bind placeholder.

`eventsTemplates.ts` `renderEventsView(subpage, data, name, theme)` emits only
the runtime hooks (`[data-events-list]`, `[data-events-ticket]`,
`form[data-events-register]`, `[data-events-back]`); `eventsChromeCss` (keyed on
`data-design`) does all the styling. Thin data → built-in sample content.

### Published pages

`eventsChrome.ts` / `ecommerceChrome.ts` — literals replaced with `var(--afs-*)`;
`[data-*-kind]` carries the `classic` defaults and
`[data-*-kind][data-design="…"]` blocks override. `pageLevel.ts`
`pageModuleAttributes` stamps `data-design`. `publish.ts` adds the design
properties to the compile binding and prepends
`[data-*-kind]{--afs-accent:… !important}` to the page `css` when Settings has a
brand colour. `ecommerceTemplates.ts` `heroSection` / `footerSection` take an
optional `theme` and every `render` forwards it; product-card inner styling is
left neutral. `events_component_script.ts` `injectChrome()` emits the
token-based fallback with the `[data-design]` blocks.

## Residue

| # | Item | Where |
|---|---|---|
| 1 | Brand colour baked into `css` at publish — a Settings change needs a republish | `BuilderPublishedController` could inject `--afs-accent` from `LaravelSetting` at serve time |
| 2 | Ecommerce product cards stay neutral white | fold into the design if per-card theming is asked for |
| 3 | Appointments has no Design picker | its own step |
| 4 | `*Chrome.ts` `[data-design]` blocks duplicate `branding.ts` tokens (CSS can't import) | kept honest by `branding.test.ts`'s token-completeness check |
| 5 | Live `.test` builder needs the tenant to reload the editor to pick up this JS | `npm run build` done; a running editor caches the old bundle |

## Verification

```
cd w/www
npx tsc --noEmit
npm run test:builder          # 476
npm run build
```

Browser (logged-in builder on `local`): open project `projeKEnNosrOA3M` → Events
page → **Design** select → pick each of the four → the canvas restyles
immediately, the `#911818` accent constant. Same on an E-Commerce page. Then
`/new-page-events-detail?event=demo-tech-conf` reflects the published design.
