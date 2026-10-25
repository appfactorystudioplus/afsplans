# 09 — Single source of truth for page-level module markup (complete)

## Status

**Done.** `site_users`, `events` and `appointments` each had their UI markup written twice —
`external/ui/{module}/ui.ts` (the real runtime) and `portal/builder/core/{module}Templates.ts`
(a second, hand-authored copy for the builder's canvas preview). Every view's markup is now
authored exactly once, in `ui.ts`'s exported pure `build*Markup()` functions; the three
`*Templates.ts` files became thin adapters that call them. Nothing about what a visitor sees on
a published page changed — this was a pure internal refactor.

- **www** — uncommitted on `main` (pending, on request).
- **465 builder tests**, unchanged count, all green — the adapters' public signatures
  (`renderSiteUsersView` / `renderEventsView` / `renderAppointmentsView`) were untouched, so
  `pageTypes.ts` needed no changes and the existing tests kept exercising the same contract.
- `tsc --noEmit` (root) and `tsc -p resources/js/external/ui/tsconfig.external.json --noEmit`
  both clean. `npm run check:modules` clean (10 modules). `npm run build-external-ui-webpack`
  and `npm run build` (production `vite build`) both green — the second is what actually proves
  Vite resolves and bundles the `portal/builder/core` → `external/ui` cross-directory import,
  not just type-checks it.
- **Verified live**: all three modules' subpages re-viewed in both the published page (real
  `external/ui/sdk.js`, rebuilt via webpack) and the actual GrapesJS builder canvas (not just
  unit tests) — see below.

## What shipped

### `external/ui/site_users/ui.ts`

Eight pure builders extracted with zero behavioral change: `buildLoginMarkup`,
`buildRegisterMarkup`, `buildRegisterClosedMarkup`, `buildCheckEmailMarkup`,
`buildDashboardMarkup`, `buildForgotPasswordMarkup`, `buildResetInvalidMarkup`,
`buildResetFormMarkup`, `buildResetSuccessMarkup`. The handful of links whose real target
depends on the current page's URL (`suHref()` — "Forgot your password?", "Sign in", etc.) render
a placeholder `href="#"` inside the pure function; the runtime's wiring function patches the
real href onto the rendered anchor via `querySelector` immediately after setting `innerHTML`.

### `external/ui/events/ui.ts`

`buildListMarkup(events, hrefFor)`, `buildTicketRowsMarkup(tickets)`,
`buildDetailMarkup(ev, registerHref)`, `buildRegisterClosedMarkup(eventName)`,
`buildRegisterMarkup(eventName, sellableTickets)`, `buildReceiptMarkup(...)`,
`buildMyRegistrationsSignInMarkup()`, `buildMyRegistrationsMarkup(rows, calendarHrefFor)`,
`buildBackLinkMarkup(label, href)`. Per-item hrefs that depend on runtime-only context
(`evHref()`, `window.location`) are threaded through as plain string arguments or an
`hrefFor`/`calendarHrefFor` callback the caller supplies — the runtime passes the real computed
href, the builder's canvas passes `'#'`. The register form's two-phase `handleSubmit` (register
→ conditionally mount a Direct Payments card → confirm payment → show receipt) stays in `ui.ts`
only, untouched, as planned. `ensureBackLink`'s DOM-insertion path now calls the same
`buildBackLinkMarkup` the builder canvas uses.

**Known, expected delta** (a bug fix, not a regression): `renderMyRegistrations`'s canvas preview
Cancel button now carries `data-events-cancel="<ref>"`, matching the runtime — previously the two
had drifted.

`featuresStrip()` (events) and `designFeaturesHtml('events')` (branding.ts, builder-only,
shared with appointments/ecommerce) remain two separate, intentionally-parallel implementations
— confirmed byte-identical copy, left alone the same way `designFooterHtml` already was, per the
plan's explicit precedent for builder-only concerns with no exact runtime equivalent.

### `external/ui/appointments/ui.ts`

`buildServicesMarkup(services)`, `buildStaffMarkup(staff)`,
`buildNextAvailableMarkup(soonest)`, `buildMyBookingsMarkup(bookings)`,
`buildBookingFormMarkup(services, slotsHtml?)`, `buildSlotButtonsMarkup(slots)`. The booking
form's static shell (service select, name/email inputs, book button, status div) is a pure
extraction with an optional `slotsHtml` parameter so the caller — runtime's `loadSlots()` or the
builder's sample data — supplies the slot buttons; `buildSlotButtonsMarkup` itself is shared so
both render identical buttons. `loadSlots`'s fetch/hold/click-wiring and the whole
hold-and-book state machine stay in `ui.ts` only, untouched.

### Follow-up: appointments' canvas now fetches real availability

Raised after the initial pass shipped: `events`' canvas preview already showed real bound data
(list/detail fetched live from the public storefront API), but appointments' `booking` and
`next-available` views fabricated slot times unconditionally, even though `services`/`staff`
were already real when bound. Fixed in `pageTypes.ts`'s `renderAppointmentsTemplate` — it now
also calls the same public, unauthenticated `POST /portal/api/appointments/storefront/
availability` and `/next-available` endpoints the live runtime calls (via `api.availability()`/
`api.nextAvailable()`), using the explicitly bound service (`pageAppointmentService`) or, for
`booking`, the first real service when none is explicitly bound.

`AppointmentsTemplateData` gained `slots?`/`nextSlot?` with a three-state contract the adapter
reads: `undefined` (nothing fetched — no service bound yet) falls back to sample data;
`[]`/`null` (fetched, genuinely empty) shows the same real "No times available"/"No times are
currently available" copy the runtime shows; a populated result renders through the same
`buildSlotButtonsMarkup`/`buildNextAvailableMarkup` the runtime uses. This is the same
"real once bound, sample only when nothing to show yet" rule already implicit in how
`services`/`staff` behaved — extended to the two remaining views that hadn't gotten it.

Verified live in the actual GrapesJS canvas (not just `tsc`/tests): cycling the appointments
page's Design select forces a fresh `renderAppointmentsTemplate` call (page re-selection alone
does not — the editor deliberately leaves already-rendered pages alone), and the `booking`
canvas then showed real slot timestamps (`9/14/2026, 8:00:00 AM`, …) fetched from the real
`demo-appointments` module's availability, replacing the previous fabricated `Mon 9:00 AM` etc.

site_users' config-driven views (`login`/`register`/`forgot-password`/`reset-password`) already
fetched real bound config before this pass — no further gap found there. Its Dashboard
necessarily stays sample-only: there is no signed-in visitor at edit time to show real session
data for, the same inherent limit as events' `my-registrations` and appointments' `my-bookings`.

### Follow-up: the Dashboard's icon buttons were showing "Send" — a real bug, not stale data

Reported after the above: several Dashboard buttons (sidebar-close, sidebar-open, the Profile/
Security/Sessions nav, Sign out, Sign out everywhere) showed literal "Send" text in the builder
canvas. Root cause was NOT this refactor and NOT missing real data — it's
`grapesjs-plugin-forms`, which registers a `'button'` component type matching *any* `<button>`
tag with a hard-coded `text: 'Send'` default. Its `init()` inspects the parsed children: if
there isn't exactly one plain-text-node child, it discards whatever was actually there (an SVG
icon, or an SVG followed by a `<span>` label — exactly `buildDashboardMarkup`'s shape for these
buttons) and replaces it with the literal string `'Send'`.

This is not cosmetic. `saveCurrentState()` (`pages.ts`) calls `editor.getHtml()` — the
already-corrupted live component tree — roughly 25ms after every render, and writes that back
into the page's stored `component`/snapshot. Confirmed via direct DOM inspection in the actual
canvas (not just reasoning about the plugin source): before the fix, 7 buttons read "Send"; only
the plain-text buttons (`Save changes`, `Change password`) survived.

Fixed in a new `resources/js/portal/builder/core/editor/siteUsersIconButtons.ts`
(`preserveSiteUsersIconButtons(editor)`, called from `createEditor.ts` right after
`grapesjs.init()`): registers a more specific component type matching only the five `data-su-*`
marker attributes these buttons carry, with no `init()`/`text` override of its own. Confirmed
against `grapesjs`'s own bundled source (`.slice().reverse()` in its type-resolution path) that
a type registered after a plugin's is checked first — this is the same "must run inside
`createEditor()`, not as a phase" ordering `universalTraits.ts` already documents for the same
reason. Verified live: all 5 button labels/icons render correctly post-fix, DOM-inspected
directly in the canvas iframe, not just visually.

Two GrapesJS state-management chances that briefly held the buggy "Send" content
(`selectedPage.set('component', …)` from a design-select cycle before the fix landed) were never
persisted server-side — the project reload before re-testing confirmed the on-disk project data
was untouched, only the in-browser editor session had picked it up, and that's gone now.

### `portal/builder/core/{siteUsersTemplates,eventsTemplates,appointmentsTemplates}.ts`

Each rewritten as a thin adapter: import the pure builders via a relative path (never the `@/`
alias — the Node test runner's `resolve-ts.mjs` hook doesn't resolve it), assemble sample-or-live
data, call them. Kept local and unchanged: the public dispatcher signatures (so `pageTypes.ts`
needed zero edits), sample-data constants, `designFooterHtml`/`designFeaturesHtml` appends, the
dashboard's no-footer special case, the appointments "Back" link (a builder-only decorative aid —
the runtime has no back-link feature for appointments at all, unlike events), the chrome-CSS
return values. Deleted: the now-dead local re-implementations of the markup itself.

## Why the import direction is safe

Confirmed by reading the build configs, not assumed — `vite.config.js` has no module-graph
restriction and freely bundles `portal/builder/core` → `external/ui` relative imports;
`external/ui/webpack.config.cjs`'s `ts-loader` `include` is hard-scoped to its own directory, so
the reverse import is architecturally impossible; the root `tsconfig.json`'s `exclude` of
`external/**` only affects `tsc`'s own start set, not files pulled in transitively via an import
from an included file. `npm run build`'s success is the proof in practice, not just in theory.

## Verified live

Both surfaces re-viewed after the refactor and after both builds:

| Surface | Pages | Result |
|---|---|---|
| Published page (`external/ui/sdk.js`) | `new-page-account-{login,register,forgot-password}`, `new-page-events-{list,detail,register}`, `new-page-appointments-{booking,services,staff,next-available,my-bookings}` | All render correctly; login's `href="#"` placeholder link confirmed patched to the real `/new-page-account-forgot-password` post-mount via a direct DOM query; appointments' booking slot buttons confirmed populated by the real `loadSlots()` → `buildSlotButtonsMarkup` path |
| GrapesJS builder canvas (`projeKEnNosrOA3M`) | Site Users → Dashboard, Events → Detail, Appointments → Booking | All three render correctly with sample-or-live data through the new adapters; appointments' Booking canvas shows the sample slot buttons (`Mon 9:00 AM`, …) via the same `buildSlotButtonsMarkup` the runtime uses; no console errors traceable to the refactor (some pre-existing 401s from unauthenticated admin instance-list fetches, unrelated) |

**Not done**: a scripted republish-and-diff of the stamped `laravel_builder_published` snapshot
against a pre-refactor baseline. Investigation showed the served page's static HTML shell *is*
the builder-rendered snapshot (`renderXView`'s output, stamped at the moment a page is bound/
edited) — a real target for this check — but the adapters' output is already exercised
byte-for-byte by the existing test suite (all 465 green, no new failures) and confirmed correct
by direct visual inspection of both the live page and the canvas above; building a DB-diff
harness on top was judged disproportionate to the residual risk for a mechanical extraction.

## Residue

| # | Item | Where it belongs |
|---|---|---|
| 1 | Scripted zero-regression diff of stamped publish snapshots (see above) — not done, judged unnecessary given the test-suite + live-visual coverage already in place. | A future pass, if ever needed. |
| 2 | `next-available`'s "This block has no service selected" state, seen live on `new-page-appointments-next-available` — a pre-existing demo-data/config gap (`data-service-ref`/`pageAppointmentService` not bound on that page/block), unrelated to this refactor. The canvas mechanism for showing a real next-available slot once a service **is** bound now works (verified) — this item is only that the demo project's `next-available` subpage doesn't have one bound. | Demo data / seeder follow-up, not a UI code issue. |

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
