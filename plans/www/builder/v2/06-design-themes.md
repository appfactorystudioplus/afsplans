# 06 — Design themes + brand colours

After [05](05-events-parity.md) the events module worked, but two gaps stayed
open:

1. The events editor canvas showed only the dashed bind placeholder — no way to
   see the design without publishing. Ecommerce has `renderStoreTemplate` (fetch
   the store's products, paint the canvas); events had nothing.
2. One look per view. Ecommerce's Products view has four layout variants; every
   other view and every events view has exactly one, and nothing reads the
   tenant's brand colours (`brand.primaryColor1` is set and ignored).

This step adds a **module-wide Design** — one picker per ecommerce / events /
**appointments** page bind, four options — and wires the tenant's brand accent
through it, in the editor and on the published page. Events and appointments get
the live editor preview.

## 6.1 Model

`core/branding.ts` is the single source of truth.

- `DesignId` = `classic | bold | minimal | editorial | vivid | slate` — six
  designs, distinct enough to be obviously different.
- `DESIGN_THEMES[id]` — a token set: `accent`, `accentInk`, `accent2`,
  `surface`, `sectionInk`, `ink`, `muted`, `line`, `radius`, `font`,
  `headingWeight`, `heroStyle`, plus `footerSurface` / `footerInk` for the
  design footer band. System fonts only (published-page CSP blocks external
  fonts).
- `resolveTheme(design, brand)` — the design with `brand.primaryColor1` overlaid
  as `accent` (and `accentInk` recomputed for contrast); `primaryColor2` /
  `accentColor1` overlay `accent2` when set. Non-hex brand values are ignored.
- `themeCssVars(theme)` — the `--afs-*` custom properties (incl. the footer pair).
- `designFooterHtml(name?)` — the shared events/appointments footer markup
  (structure only; `*Chrome.ts` styles `footer[data-afs-footer]`).

The `[data-design]` token blocks in the three `*Chrome.ts` files are
**generated** from `DESIGN_THEMES` at module load — one source of truth, no
hand-maintained CSS duplication.

The builder does **not** import from `portal/admin`; `SettingsBrand` is copied
into `branding.ts`.

## 6.2 Brand settings into the builder

`resources/js/portal/builder/main.ts` `bootstrap()` fetches `/portal/api/settings`
(`fetchBrandSettings`, best-effort) and passes `brand` into
`initEditor({ …, brand })`. `core/editor/index.ts` threads it to
`createPageTypes({ …, brand })` and `createPublish({ …, brand })`.

## 6.3 The Page panel

`index.blade.php` gains `#events-design-section` (`#page-event-design`) and
`#commerce-design-section` (`#page-commerce-design`), each a 4-option `<select>`,
shown when the matching page type is chosen and an instance is bound.

`pageTypes.ts`:
- `syncDesign(prop, id)` writes `pageEventDesign` / `pageCommerceDesign` onto the
  parent + every child (mirrors `syncEventDirectPayment`); `currentDesign(prop)`
  reads it with a `classic` fallback.
- the design `<select>` `change` → `syncDesign` + re-render the current view.
- `loadPageType` restores the select value; `bindSelectedInstance` seeds the
  design into `ensureBoundSubpages` extras.

## 6.4 The editor preview (events)

`core/eventsTemplates.ts` (new) — `renderEventsView(subpage, data, name, theme)`
returns `{ html, styles }` per subpage. The markup carries the same hooks the
published runtime paints (`[data-events-list]`, `[data-events-ticket]`,
`form[data-events-register]`, `[data-events-back]`); all styling is in
`eventsChromeCss` keyed on `data-design`, so the templates emit only structure.
Thin/absent data falls back to built-in sample content.

`pageTypes.ts` `renderEventsTemplate(eventRef, design, targetPage)` mirrors
`renderStoreTemplate`: `showPageLoadingOverlay`, fetch
`/portal/api/events/storefront/events[/{ref}]` (public), `renderEventsView`,
`stampPageModuleHtml`, persist + snapshot, `applyPageStyles` in the
`template-events-{design}` group, `editor.setComponents`, `injectFrameStyles`,
`ensureEditorModuleReadyCss`. Runs on the events `page:select` branch whenever
the canvas is still the bind placeholder, on the event pick, and on a design
change.

## 6.5 Published pages

- `ecommerceChrome.ts` / `eventsChrome.ts` — every colour / radius / font is now
  `var(--afs-*)`; the defaults on `[data-*-kind]` are `classic` and
  `[data-*-kind][data-design="bold"|"minimal"|"editorial"]` blocks override.
- `pageLevel.ts` `pageModuleAttributes` stamps `data-design` on the module root
  from `page.properties.page{Event,Commerce}Design`.
- `publish.ts` `collectPagesForPublish` adds the design properties to the binding
  and, when a brand colour is set, prepends
  `[data-*-kind]{--afs-accent:… !important;…}` to the page `css` so the brand
  accent wins over the design default. **Brand colour is baked at publish — a
  Settings change needs a republish** (residue).
- `ecommerceTemplates.ts` `heroSection` / `footerSection` take an optional
  `theme` and use its tokens; every `render` forwards it. Product-card inner
  styles are unchanged (neutral content) — the hero, chrome, buttons and
  sections carry the design.
- `events_component_script.ts` `injectChrome()` emits the token-based fallback
  with the `[data-design]` blocks.

## 6.6 Acceptance

| # | Check | Expect |
|---|---|---|
| D1 | `resolveTheme('classic', {primaryColor1:'#911818'})` | `accent === '#911818'`, other tokens untouched, `accentInk` recomputed |
| D2 | Events page, pick each design | canvas restyles immediately; brand accent constant across all four |
| D3 | E-Commerce page, pick a design | hero + chrome + buttons follow the design |
| D4 | Publish an events page | module root carries `data-design`; `css` has the brand `--afs-accent` when Settings has one |
| D5 | Served `/…-events-detail` | reflects the chosen design + brand colour |
| D6 | `check-modules.mjs` / `test:builder` | green |

D1, D6 and the stamp are stub-tested (`branding.test.ts`, `events.test.ts`).
D2–D5 need a `local` tenant session.

## 6.7 Residue

| Item | Where |
|---|---|
| Brand colour baked at publish, not read at serve time | a `BuilderPublishedController` change, if a live Settings→page link is wanted |
| Ecommerce product-card styling stays neutral | fold into the design if per-card theming is asked for |
| `*Chrome.ts` `[data-design]` blocks are generated from `branding.ts`, but the runtime scripts' `injectChrome()` fallbacks still hand-list the six designs | the serialised runtime can't `import`; `branding.test.ts` pins the count |
| Footer nav links are `href="#"` placeholders (events uses sibling hrefs) | a later pass could resolve real slugs |

## 6.9 Structural pass (follow-up)

The token-only designs still read as one page in six palettes. `branding.ts`
gained `designStructCss(kindSel, { hero, card, grid, cta })` — the per-design
STRUCTURAL CSS (card treatments, hero layouts, an under-hero `[data-afs-features]`
strip, a three-column footer, decorative `::before` / `::first-letter`),
generated once and shared by events + appointments chrome. Templates and runtime
scripts emit the new hooks (`<section data-afs-hero>`, `[data-afs-kicker]` /
`[data-afs-sub]` / `[data-afs-cta]`, `[data-afs-note]`, `[data-afs-features]`)
and carry more copy per view. See `complete/00_extras.md` E45. Ecommerce keeps
the token-only blocks (its templates own their hero/footer).

## 6.8 Repo

**www** for code, **packages/afsserver** for this plan. One www commit on `main`.
