# 09 — Single source of truth for page-level module markup

## Context

`site_users`, `events` and `appointments` each have their UI markup written
**twice**, independently, by hand:

- `resources/js/external/ui/{events,appointments,site_users}/ui.ts` — the real,
  interactive runtime shipped in `external/ui/sdk.js`, loaded on published
  pages.
- `resources/js/portal/builder/core/{events,appointments,siteUsers}Templates.ts`
  — a second, independently-authored copy of the same markup, used only for
  the builder's own canvas preview (and the placeholder HTML stamped into a
  page the moment it's bound, before any visitor loads it live).

This was deliberate at the time ([07](07-externals-ui-layer.md) — the canvas
must never load the live `external/ui/sdk.js` runtime, so it can't run
anything mid-edit), but the *consequence* — two hand-maintained copies of the
same markup with no automated check that they agree — is a defect, confirmed
live during [08](08-site-users-pages.md)'s dashboard redesign: `ui.ts` and
`siteUsersTemplates.ts` had to be updated by hand in lockstep, and a parity
gap already exists between events' `renderMyRegistrations` (runtime stamps
`data-events-cancel="<ref>"` on its Cancel button; the builder's preview
button has no such attribute).

**The fix:** the markup is authored exactly once, in `external/ui/*/ui.ts`.
The builder imports it. Nothing about *what a visitor sees on a published
page* changes — this is a pure internal refactor, not a redesign. Scope:
**site_users, events, appointments** (not ecommerce — its runtime hydrates
tenant-*designed* markup rather than generating it fresh, a structurally
different problem 07's own residue notes left alone on purpose).

## The pattern, applied to all three modules

Every view in every one of these three modules already splits cleanly into
two layers once you look at what each render function actually does:

1. **Markup** — build an HTML string from already-resolved data. Pure,
   synchronous, no DOM mounting, no network call, no event listener.
2. **Wiring** — fetch the real data (`su.me()`, `api.events()`, …), call the
   markup builder, set `root.innerHTML`, then attach the interactive bits
   (submit handlers, nav switching, live API calls).

For **site_users**, every view (`login`, `register`, `dashboard`,
`forgot-password`, `reset-password`) is a clean split — zero logic that isn't
either pure markup or pure wiring.

For **events** and **appointments**, most views are the same clean split
(`list`/`detail`, `services`/`staff`/`next-available`/`my-bookings`). Two
views carry a genuine runtime state machine interleaved with their markup and
need a finer cut:

- **events `register`** — the form markup (ticket select, quantity, name,
  email, phone, promo, an empty `<div data-events-card>` mount point, submit
  button, status div) is a clean, shared, static extraction. The two-phase
  `handleSubmit` (register → conditionally mount a Direct Payments card →
  confirm payment → show receipt) is genuine runtime state and stays in
  `ui.ts` only, unchanged. `showReceipt`'s markup is a clean extraction too.
- **appointments `booking`** — the surrounding form markup (service select,
  name/email, book button, status div) is a clean extraction. The slot list
  itself is data-driven (`loadSlots()` fetches live availability and wires a
  hold-on-click handler per slot) — extract the **slot-button markup**
  (`buildSlotButtonsMarkup(slots)`) as a pure function so both sides render
  identical slot buttons, one from sample data and one from a live fetch;
  `loadSlots`'s fetch/hold/click-wiring stays runtime-only.

Everywhere else (`renderMyRegistrations`'s row markup, `renderMyBookings`,
etc.) the same mechanical extraction applies — cut the template-literal
portion out into an exported pure function, leave the fetch/click-wiring
behind calling it.

## Implementation, per module (repeat 3×: site_users, events, appointments)

### 1. `external/ui/{module}/ui.ts` — add exported pure builders

For each view's render function, extract the markup into a new exported
function taking already-resolved data and returning an HTML string — e.g.
`buildLoginMarkup(config)`, `buildDashboardMarkup(user)`,
`buildRegisterMarkup(event, tickets)`, `buildTicketRowsMarkup(rows)`,
`buildSlotButtonsMarkup(slots)`. The existing `renderX(root, …)` functions
change to: resolve data → `root.innerHTML = buildXMarkup(...)` → continue
wiring exactly as today. **No behavioral change** — this is lifting an
existing string literal into a named function, not rewriting it.

Split points already identified by reading both sides:
- `resources/js/external/ui/site_users/ui.ts` — all 5 views, fully clean.
- `resources/js/external/ui/events/ui.ts` — register form markup vs
  `handleSubmit` (stays, payment state machine); registration-row markup vs
  cancel-click wiring (stays).
- `resources/js/external/ui/appointments/ui.ts` — booking form shell vs
  `loadSlots`/hold/book (wiring stays); slot-button markup inside `loadSlots`
  becomes `buildSlotButtonsMarkup`.

Keep using each module's own existing local helpers (`esc`, `fmtDate`,
`fmtMoney` from `../shared/dom`/`../shared/format`) — nothing about those
changes.

### 2. `portal/builder/core/{events,appointments,siteUsers}Templates.ts` — become adapters

Import the new pure builders with a **relative** import (this codebase's
established convention inside `core/` — never the `@/` alias, which the
Node-based test runner's `resolve-ts.mjs` hook does not resolve):

```ts
import { buildLoginMarkup, buildDashboardMarkup, … } from '../../../external/ui/site_users/ui';
```

Rewrite each internal `renderX` function to assemble the right-shaped data
(sample fallback when nothing's bound yet, or the live-fetched data
`pageTypes.ts` already passes in) and call the imported builder — deleting
the duplicated template-literal HTML. **Keep unchanged:**

- The public dispatcher signature — `renderEventsView(subpage, data, name,
  theme)` / `renderAppointmentsView(...)` / `renderSiteUsersView(...)` —
  stays exactly as `pageTypes.ts` already calls it. **`pageTypes.ts` needs no
  changes at all.**
- Builder-only concerns that have no runtime equivalent: sample-data
  constants (`SAMPLE_EVENTS`, `SAMPLE_TICKETS`, `SAMPLE_SITE_USERS_CONFIG`,
  appointments' sample slots), the `designFooterHtml(name)` append, the
  dashboard's no-footer special case, the chrome-CSS return value.
- Delete now-dead local re-implementations once nothing calls them:
  `esc`/`fmtDate`/`fmtMoney`/`field`/`panel`/icon constants/`backLink` — these
  currently exist a second time in each `*Templates.ts` purely because the
  markup did; once the markup is imported, these are unused.

Chrome files (`{events,appointments,siteUsers}Chrome.ts`) are **not** part of
this refactor — the CSS token system (`branding.ts`, `DESIGN_THEMES`,
`designStructCss`) is shared builder-side infrastructure across a 4th module
(ecommerce) too, and `external/ui`'s webpack build is hard-walled to its own
directory (`ts-loader`'s `include` in `webpack.config.cjs`) — it cannot
import `branding.ts` even if we wanted it to. The runtime's own
`injectChrome()` fallback CSS is a deliberately-smaller, separately-scoped
safety net for a page published without builder chrome, not a duplicate of
the same thing — left alone.

## Why the import direction is safe

Confirmed by reading the actual build configs, not assumed:

- **Builder (Vite)**: `vite.config.js` has no `include`/`exclude` restricting
  the module graph and a `'@': '/resources/js'` alias covering the whole
  tree — nothing stops it following a relative import from
  `portal/builder/core/` into `external/ui/`. It will simply bundle the
  imported file's compiled output into the builder's own chunk, same as any
  other local import.
- **`external/ui` (webpack)**: `webpack.config.cjs`'s `ts-loader` rule has
  `include: [path.resolve(__dirname, "./")]` — hard-scoped to its own
  directory. It is architecturally *impossible* for `external/ui` to import
  from `portal/builder` (the build would fail to find a loader for the
  file). The dependency can only run one way — builder importing from
  external/ui — which is exactly the direction this plan uses. No risk of
  an accidental reverse/circular dependency.
- Root `tsconfig.json` excludes `resources/js/external/**` from its own root
  file list, but that only affects which files `tsc` starts from — a file
  directly imported by an *included* file is still pulled into the
  type-check program and checked with the root config's compiler options.
  `tsc --noEmit` will genuinely check the cross-import, not silently skip
  it.
- No existing precedent for this *specific* pair (`portal/builder` ↔
  `external/ui`), but cross-directory imports elsewhere in this repo
  (`portal/* ↔ checkpoint/*`, `portal/* ↔ afs/*`, `external/api/* →
  portal/*`) confirm it's a normal pattern here, not a first-of-its-kind
  risk.

## Tests

`resources/js/portal/builder/tests/{events,appointments,site_users}.test.ts`
import the adapter files' public functions directly — signatures are
unchanged, so these should continue to pass as-is. Two known, expected
deltas to watch for and accept (they're bug fixes, not regressions):
- events' `renderMyRegistrations` preview gaining `data-events-cancel="<ref>"`
  on its sample Cancel buttons, now that it shares the row-markup builder
  with the runtime.
- Any other hook-text mismatch the full suite surfaces — fix by aligning the
  test to the now-single-sourced markup, not by re-diverging the markup.

## Verification

```
cd w/www
npx tsc --noEmit
npx tsc -p resources/js/external/ui/tsconfig.external.json --noEmit
npm run check:modules
npm run test:builder
npm run build-external-ui-webpack
npm run build
```

`npm run build` (the real Vite production build, not `tsc --noEmit` alone) is
the one that actually proves Vite can resolve and bundle the cross-directory
import in practice, not just type-check it.

**Zero-regression check**, since this is meant to be invisible on
already-published pages: fetch and save the current HTML for a page per
subpage per module before touching code (`new-page-account-*`,
`new-page-events-*`, `new-page-appointments-*`). After the refactor,
republish those same pages the same way `06`/`08` did it (a throwaway script
driving the real `compileRuntime`/`stampPageModuleHtml` + an in-place
`laravel_builder_published` row update) and diff old vs. new HTML — expect
byte-identical except the two known parity fixes above. Clean up the
throwaway script afterward, same as every other pass.

## Repo

**www** only — this is internal to the builder/externals JS, no plan-doc
surface changes elsewhere. One `w/www` commit, on request.
