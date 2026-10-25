# 10 — Replace `grapesjs-plugin-forms` with an in-house plugin

## Context

[09](09-single-source-ui.md)'s follow-up found a real bug, not a missing-data issue: the
site-users Dashboard's icon buttons (sidebar-close, sidebar-open, the Profile/Security/Sessions
nav, Sign out, Sign out everywhere) were rendering as the literal text **"Send"** in the builder
canvas. Root cause is the third-party `grapesjs-plugin-forms` package
(`resources/js/portal/builder/core/config.ts`), which registers a `'button'` component type whose
`isComponent` is just `el.tagName === 'BUTTON'` — it claims *every* button on the page — and whose
`init()` inspects the parsed children: if there isn't exactly one plain-text-node child, it
discards whatever was actually there (an SVG icon, or an SVG followed by a `<span>` label — the
exact shape `buildDashboardMarkup` uses) and replaces it with the plugin's hard-coded default,
the string `'Send'`.

This is not cosmetic. `saveCurrentState()`
(`resources/js/portal/builder/core/editor/pages/pages.ts`) calls `editor.getHtml()` — the
already-corrupted live component tree — roughly 25ms after every render, and writes that back
into the page's stored `component`/snapshot, which is what a later publish stamps into
`laravel_builder_published`.

A point fix already landed:
`core/editor/siteUsersIconButtons.ts` *(deleted by this plan's P2 — the link is intentionally gone)*
registers a more-specific type matching only the five affected `data-su-*` buttons, with no
destructive `init()` of its own — GrapesJS resolves the most-recently-registered type first, so
it wins. Verified: all five buttons keep their real icon/label content now.

**That fix is a patch on a bad dependency, not a real solution.** It works because it happens to
know about five specific `data-su-*` attributes. It teaches nothing about why the next module to
use an icon+label button won't hit the same wall, and every current module (events, appointments,
ecommerce) was checked by hand this session and happened to avoid the trap only because none of
them use icon+label buttons — not because anything stops them from needing this same patch
next time. The plugin's actual defect — "I own every `<button>` tag on the page, unconditionally"
— is still there, in every project this editor opens.

**The decision:** stop taking that dependency's specific button-detection design as a fixed
constraint and write our own. `grapesjs-plugin-forms-afs` reproduces its block palette (form,
input, textarea, select, checkbox, radio, button, label, option) so nothing a tenant already
built with the Forms palette regresses, but detects its own components by an attribute *our own
blocks* stamp — never by bare tag name — so an arbitrary `<button>` (or `<select>`, `<input>`, …)
belonging to ecommerce, events, appointments, site_users, or any future module's own markup is
never at risk of being silently reinterpreted, no matter what it contains. Owning the code also
means the parsing/trait/styling behavior stops being whatever an upstream package happens to ship
— §"Enhancements" below is the concrete list of what we do with that.

## The mechanism: detect our own components, not any element that fits a tag name

`grapesjs-plugin-forms`'s core mistake is using **shape** (`tagName === 'BUTTON'`) as the
detection key for a **specific palette's** component. Shape is not ownership: any module's own
authored markup can produce a `<button>` without ever having gone through this plugin's block.

The replacement's `isComponent` for every type requires a **marker our own block content
stamps**, e.g. `data-afs-forms="button"` (kind-specific value per type: `form`, `input`,
`textarea`, `select`, `checkbox`, `radio`, `button`, `label`, `option` — matching this codebase's
existing convention of a `data-<module>-<thing>` attribute rather than a bare class):

```ts
editor.Components.addType('afs-button', {
    isComponent: (el) =>
        el.tagName === 'BUTTON'
        && (el.getAttribute('data-afs-forms') === 'button' || el.getAttribute('data-gjs-type') === 'button'),
    // ...
});
```

- **New drops from the palette** carry `data-afs-forms="button"` in the block's own `content`
  template — unambiguous, ours, never present on markup we didn't author.
- **The `data-gjs-type === 'button'` clause is compatibility, not detection.** GrapesJS stamps
  `data-gjs-type="<type>"` onto the exported HTML of any component whose type isn't the plain
  default — so a project that already has a *real* Forms-palette button from before this swap
  carries that attribute in its saved HTML already. Matching it here means an existing tenant's
  form doesn't silently stop being editable the moment the plugin swaps. It is deliberately **not**
  the primary detection path: relying on GrapesJS's own bookkeeping attribute long-term is
  exactly the kind of implicit coupling this replacement exists to get away from, which is why P1
  below backfills the real marker onto those legacy elements the first time each project opens
  post-swap, so this compatibility clause is a one-time bridge, not a permanent second code path.
- **No type gets a destructive `init()`.** Every type keeps whatever children were actually
  parsed — the button's whole "single text node or I overwrite you" logic is simply not
  reproduced. A button's label is a separate, explicit trait (`label`, `changeProp: true`,
  rendered into a dedicated `<span data-afs-forms-label>` child) rather than something inferred
  from — and capable of destroying — the component's actual content.

## Scope: parity first

Every type `grapesjs-plugin-forms` registers (`node_modules/grapesjs-plugin-forms/dist/index.js`
is the reference to diff against) gets a same-shaped replacement, so nothing a tenant already
built with these blocks changes behavior:

| Type | Current behavior to preserve | Destructive risk in the original? |
|---|---|---|
| `form` | tagName `form`, `droppable:':not(form)'`, `draggable:':not(form)'`, method trait, submit prevented in canvas | No |
| `input` | tagName `input`, non-droppable, `name`/`placeholder`/`type`/`required` traits, forces `autocomplete="off"` in canvas | No |
| `textarea` | extends input, same traits | No |
| `select` | non-droppable, default two-option `components`, `select-options` custom trait type (newline/`::`-delimited textarea editor) | No — the default-options fallback only fires on an empty select; existing `<option>` children still parse normally through `option`'s own type |
| `checkbox` / `radio` | `type=checkbox\|radio` attribute, `id`/`name`/`value`/`required`/`checked` traits, checked-state two-way binding, click prevented in canvas | No |
| `option` | tagName `option`, not layerable/droppable/draggable | No |
| `label` | extends GrapesJS's built-in `text` type, `for` trait | No |
| `button` | `type` trait (button/submit/reset), text content | **Yes — this is the bug.** The only type with a destructive `init()` |

Block palette: same eight blocks (Form, Input, Textarea, Select, Button, Label, Checkbox, Radio),
same "Forms" category, same icons — a tenant's block panel looks identical.

## Enhancements — the part a rewrite makes possible

Once this is our own code, the plugin doesn't have to stay a like-for-like port. Concrete,
scoped additions (not vague future work — each is buildable in the same pass):

- **Button**: `variant` trait (primary / secondary / ghost / outline) and `size` trait
  (sm / md / lg), styled through the *same* `--afs-*` custom-property tokens `branding.ts`
  already defines for every module's six design themes — so a Forms-palette button dropped next
  to an events or ecommerce block picks up the page's actual design instead of hard-coded plugin
  colors. An optional `icon` trait (a small fixed icon set, reusing the SVG constants pattern
  already established in `external/ui/site_users/ui.ts`) lets a tenant add a leading icon
  *through the trait panel*, composed with the label — not inferred from DOM shape the way the
  old plugin tried to.
- **New field types** the old plugin never had: `date`, `time`, `number` (with `min`/`max`/`step`
  traits), `range`, `color`, `tel`, `url`, `file`, `hidden` — each just an `input` variant with
  its own trait set.
- **Textarea**: `rows`/`cols`/`resize` traits (currently absent — it only inherits `input`'s
  traits verbatim, which don't fit a multi-line field).
- **Select**: an `option-groups` structure (`<optgroup>`) and a `multiple` trait.
- **Shared styling**: every type's default appearance keyed off `--afs-*` tokens rather than the
  plugin's own hard-coded colors, so `styleManager`'s sectors (already configured in `config.ts`)
  read as customizing *this page's design*, not fighting an unrelated default look.

**Explicitly optional, not committed in this plan:** a form "action" trait (submit to a URL vs.
no-op). This app's actual form-submission story (SurveyJS forms, `afsapi.js`, per-module
component scripts) needs its own investigation before deciding what a generic Forms-palette
`<form>` should do on submit — flagging it here so it isn't silently dropped, not promising it.

## Phases

| Phase | What | Gate |
|---|---|---|
| **P0** | Build `grapesjs-plugin-forms-afs` (new directory, e.g. `resources/js/portal/builder/core/editor/formsPlugin/`) implementing every type in the parity table with the marker-gated `isComponent` + legacy-`data-gjs-type` compat clause. Register it in `createEditor.ts` **alongside** the existing `grapesjs-plugin-forms` (not yet removed) so both can be compared in the same running editor. | Must type-check and pass a new dedicated unit-test file exercising every type's `isComponent` (own marker / legacy attribute / neither) before P1 starts. |
| **P1** | Migration: on project load, scan for legacy `[data-gjs-type="form\|input\|textarea\|select\|checkbox\|radio\|button\|label\|option"]` elements lacking our `data-afs-forms` marker and backfill it — same shape as `retired.ts`'s existing migration-on-open pattern (reuse that precedent, don't invent a new one). Test against a real (or realistically reconstructed) pre-swap saved project containing at least one of each old block type. | A migrated project's Forms-palette elements must still show the same traits in the Traits panel post-migration — verified live, not just asserted in a unit test. |
| **P2** | Remove `grapesjs-plugin-forms` from `config.ts`'s `plugins`/`pluginsOpts`, from `package.json`, and from `declarations.d.ts`; remove `siteUsersIconButtons.ts` and its `createEditor.ts` call site — once the real fix is in place, a second, narrower point-fix for the same defect is exactly the duplication this session has been removing all along, not something to keep as a belt-and-suspenders layer. | `tsc --noEmit`, `test:builder`, `check:modules`, `build-external-ui-webpack`, `build` all green; live re-verification that site_users/events/appointments/ecommerce buttons still render correctly with **no module-specific override left anywhere**. |
| **P3** | The enhancement pass (§ above) — new field types, button variant/size/icon traits, `--afs-*`-token styling. Deliberately after P0–P2 land clean, so the swap itself (the risky part — an existing tenant's saved forms must not regress) is verified in isolation before new surface area is added on top. | Live check: drop one of each new/enhanced block, confirm it themes correctly across at least two of the six existing designs. |
| **P4** (secondary, lower priority) | `resources/js/portal/admin/config/grapejsconfig.ts` (the MJML email-template editor) also loads `grapesjs-plugin-forms` — a second, unrelated consumer. Swapping it fully retires the npm dependency. MJML's own component model (`mj-button` etc.) may not even route through plain `<button>` parsing the same way — check before assuming P0's plugin drops in unchanged. | Not required to remove the package in P2 if this phase is deferred — note in the repo write-up which is true. |

**P0–P2 is the actual bug fix; P3 is the improvement the user asked for "if possible"; P4 is
independent cleanup that only matters if the goal is deleting the npm dependency entirely, not
just fixing the corruption.**

## Known residue P0–P2 does not fix by itself

The plugin swap stops *future* corruption. It does not repair HTML that already got corrupted
and already got saved server-side before the swap landed. If any real (non-local-testing)
project's `laravel_builder_projects.data` already has a Dashboard/etc. page whose stored
`component` contains the literal corrupted text, that page needs its affected subpages
re-rendered once (the same `renderSiteUsersTemplate`/`renderEventsTemplate`/
`renderAppointmentsTemplate` re-render already used for live verification all through 09) to
overwrite the bad snapshot — a content-repair pass, separate from and after the code fix.

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

Plus the new dedicated test file for the plugin's own type-detection logic (P0), and live
GrapesJS-canvas verification for P1–P3 exactly as this session did for 09 — DOM-inspecting actual
button/field content in the canvas iframe, not just visual screenshots.

## Repo

**www** only — this is internal to the builder editor's own dependencies, no plan-doc surface
changes elsewhere. One `w/www` commit, on request.
