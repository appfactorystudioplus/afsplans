# PDF Editor: Search, Redaction, Snapping, Freehand, Watermarks, Merge/Split, Form Filling

## Context

`apps/pdf_editor` is a browser-based PDF editor (Laravel shell + a single ~2,460-line
`resources/js/editor.ts` doing all the real work via pdf.js for rendering/extraction and
pdf-lib for export). An earlier brainstorming pass (`apps/www/_docs/pdf_editor_features.md`)
listed a set of features; five were already shipped (page reorder, signatures, autosave/version
history, OCR, sharing). This plan implements the remaining seven: **Merge & split, Form filling,
Freehand pen + highlighter, Snapping & alignment guides, Watermarks/page numbers/headers-footers,
True redaction, and Search across the document.**

Scope for this session is `apps/pdf_editor` only — `apps/docs` is explicitly out of scope (per
user instruction), except for one small deliverable: a todo doc under `apps/www/_docs` for the
deferred "interactive form fields" enhancement (see Phase 3).

Given the size of this (new element types, a new freehand-draw interaction pattern, an AcroForm
detection/fill pipeline), this is being delivered in **three phases**, each implemented and
verified in the running app before moving to the next.

## Architecture recap (how the existing code works, since every feature below hooks into it)

- **`Page`** (`editor.ts:67`): `{ width, height, srcIndex, elements: El[], erasures: Box[], node, bgCanvas, elLayer }`.
  `srcIndex` is the page index in `state.pdfjsDoc`/`state.originalPdfBytes`, or `null` for a blank/new page.
- **`El`** union (`editor.ts:65`): `TextEl | ImageEl | ShapeEl`, each with shared `BaseEl` fields
  (`id, x, y, w, h, node, src?, dirty`). `src` marks "this element came from the original PDF at this Box".
- **History**: a flat `Command[]` + `historyIndex`, generic over `El` (`el-change/el-create/el-delete`)
  and `Page` (`page-add/page-delete/page-reorder`). `MUTABLE_KEYS` drives what `snapshotEl`/`applySnapshot` touch.
- **Export** (`exportPdf()`, `editor.ts:2129`): builds a fresh `PDFDocument`. Untouched source pages are
  copied verbatim via `copyPages` (crisp vectors). Pages with edited/deleted original content
  (`pageNeedsFlatten()`, `editor.ts:2125`) are instead rasterized from `page.bgCanvas` (which already has
  extracted text/images erased from it) and every element is redrawn on top — this is what guarantees
  deleted/hidden original content doesn't survive in the output's content stream.
- **Adding a new `El` variant touches a fixed checklist**: `MUTABLE_KEYS`, `specOf`/`buildFromSpec`
  (clipboard), `syncElNode`, `makeElNode`, the `exportPdf()` switch, and `renderThumb()`. Every feature
  below that adds an element type follows this checklist so copy/paste/duplicate/undo/redo/thumbnails
  work for free (they all already dispatch generically over `El`).
- **Toolbar** (`resources/js/templates/editor.html`): tool buttons use `data-tool`, wired generically
  in `mountEditor()`; modal-driven features (signature, history, share) get dedicated buttons wired
  individually — new modal features follow that same pattern.
- **Backend** (`DocumentController.php`) only stores/versions whatever bytes `exportPdf()` produces —
  no server-side PDF processing. Nothing here requires backend changes.

---

## Phase 1 — Search, True Redaction, Snapping & Alignment

Purely additive: no new page/source model, minimal-to-no export changes beyond redaction.

### 1. Search across the document
- New toolbar button + a search panel (small bar, similar treatment to `#hint-bar` but interactive:
  text input, prev/next, match count, close).
- State: `searchQuery`, flat list of matching `TextEl`s gathered across `state.pages` in order,
  `matchIndex`.
- Match: case-insensitive substring over `el.text` for `type === 'text'` elements (covers both real
  extracted PDF text and OCR'd text — same underlying `TextEl`).
- On navigate: `page.node.scrollIntoView(...)` + toggle `.search-match` / `.search-match-current`
  CSS classes on matched elements' nodes for highlight. Enter cycles next, Shift+Enter previous, Esc closes.
- No `exportPdf()` or data-model changes.

### 2. True redaction
- New `RedactEl extends BaseEl { type: 'redact' }` (no configurable fill/stroke — always solid black).
- New `'redact'` tool, added to the `Tool` union and wired through `placePendingTool` exactly like
  `rect`/`ellipse` (click to place a default-size box, then drag resize handles) — reuses the existing
  placement UX rather than inventing a new one.
- Editor rendering: semi-opaque black + dashed outline + small "REDACTED" label so the user can tell
  what's covered while editing, without it being a fully opaque black hole in the workspace.
- **Export correctness** (the actual "true redaction" part): extend `pageNeedsFlatten()` to also return
  `true` when a page contains any `redact` element. This forces that page down the rasterize path —
  the same machinery already built to fix "hidden text survives whiteout" — so whatever was under the
  box is gone from the page's text/content stream, not just visually covered. Add a `'redact'` case in
  `exportPdf()`'s per-element switch that draws a fully opaque black rectangle (reusing the existing
  `whiteout()` helper's shape, just black) on top of the flattened raster.
- Checklist: `MUTABLE_KEYS.redact = ['x','y','w','h','dirty']`, `specOf`/`buildFromSpec`, `syncElNode`,
  `makeElNode`, `renderThumb()` (draw a black box). Context menu, undo/redo, copy/paste all work for free.

### 3. Snapping & alignment guides
- Editor-only enhancement to `startDrag`/`startResize` (`editor.ts:1120` / `:1148`) — no data model,
  history, or export changes.
- New `computeSnapTargets(page, excludeEl)`: page-space vertical lines (page center-x, left/right
  margins at a fixed inset, each other element's left/centerX/right) and horizontal lines (page
  center-y, top/bottom margins, each other element's top/centerY/bottom).
- `SNAP_THRESHOLD_PX` (screen px, converted via `/state.zoom`): inside the drag/resize `move()`
  closures, after computing the prospective position, check the dragged element's candidate edges
  against targets; snap exactly on hit, and show a guide line.
- Two reusable guide-line `<div>`s per page (`.snap-guide-v`, `.snap-guide-h`), created once, positioned
  and shown only while a snap is active, hidden on pointerup. New SCSS rules alongside the existing
  `.h` resize-handle rules in `app.scss`.

**Phase 1 verification**: `npm run build` (vite build/typecheck) in `apps/pdf_editor`, then drive the
running editor via the browser preview tool — draw a redaction box over known text, download, re-open
the exported PDF and confirm the text is truly gone (not just covered); search for a known word and
confirm scroll+highlight+next/prev; drag an element near a page edge/another element and confirm it
snaps with a visible guide line.

---

## Phase 2 — Freehand Pen + Highlighter, Watermarks/Page Numbers/Headers-Footers

### 4. Freehand pen + highlighter
- New `PathEl extends BaseEl { type: 'path'; points: {x:number;y:number}[]; mode: 'pen'|'highlighter'; color: string; strokeWidth: number }`.
  Points stored relative to the element's bounding box so moves (existing `startDrag`) translate for free.
- Two new tools (`'pen'`, `'highlighter'`) with distinct defaults (pen: 2pt black solid; highlighter:
  14pt yellow, ~0.4 opacity). This is a **new interaction pattern** distinct from click-to-place:
  pointerdown begins live capture, pointermove appends points, pointerup finalizes — computes the
  bounding box, normalizes points into it, creates the element, pushes `el-create`.
- Rendering: `makeElNode` renders an inline `<svg>` sized to the box; path `d` built from points
  (quadratic-curve-through-midpoints smoothing for a nicer pen feel).
- Move-only in v1 — **no resize handles** for `path` elements (`addHandles` skips `type==='path'`);
  called out as a scoped-down limitation.
- Export: pdf-lib `page.drawSvgPath(d, { x, y, borderColor, borderWidth, borderOpacity })`. Highlighter's
  "multiply blend" is approximated via `borderOpacity` since pdf-lib's public API doesn't expose true
  blend modes — documented limitation, not a true multiply blend.
- Checklist: `MUTABLE_KEYS.path = ['x','y','w','h','dirty','color','strokeWidth']`, clipboard spec,
  `renderThumb()` (draw the same path at thumb scale).

### 5. Watermarks / page numbers / headers-footers
- One new modal (`#stamp-modal`) with 3 tabs (Watermark / Page Numbers / Header-Footer), following the
  existing `#sig-modal` Draw/Type tab pattern.
- **No new element type** — all three insert plain `TextEl`s via the existing `createTextEl()` +
  `pushCommand({kind:'el-create', ...})` path, so results are fully editable/movable/deletable/undoable
  afterward like any other text, for free.
- **Watermark tab**: text, font size, color, opacity, rotation angle (default 45°), apply to
  all-pages/current-page. Requires adding an optional `rotation?: number` field to `TextEl`:
  `syncElNode` applies a CSS `transform: rotate()` (sign-flipped, since CSS is clockwise-positive and
  PDF space is counterclockwise-positive) around the element's center; `exportPdf()`'s text branch
  passes pdf-lib's `rotate: degrees(el.rotation ?? 0)` (new `degrees` import from `pdf-lib`) with
  matching anchor-point math.
- **Page Numbers tab**: format string with `{n}`/`{N}` placeholders, corner picker, font size. Applied
  once per page at insertion time — **static snapshot, not a live field** (adding/removing pages later
  won't renumber existing stamps); documented as a known v1 limitation.
- **Header/Footer tab**: left/center/right text for header and footer, font size; up to 6 `TextEl`s per
  targeted page.
- Batch application pushes one `el-create` command per inserted text element (consistent with the
  existing granular-undo pattern elsewhere, e.g. page-reorder-by-one).

**Phase 2 verification**: `npm run build`, then in-browser: draw a pen stroke and a highlighter stroke,
export, confirm visible in the downloaded PDF; apply a rotated watermark to all pages and confirm
rotation/opacity in the export; apply page numbers and a header/footer and confirm placement and that
they're individually editable afterward.

---

## Phase 3 — Merge & Split, Form Filling

### 6a. Merge (insert pages from another PDF)
- New "Insert PDF…" editor toolbar action: file picker → load the chosen file's bytes via a
  **temporary** `pdfjsLib.getDocument()` call (not stored in `state` — avoids a multi-source document
  model refactor entirely).
- For each inserted page: `newPage(w, h, null)` (`srcIndex: null`, so it's never a candidate for the
  copy-verbatim export fast-path), run the *existing* `convertPageToElements()` extraction pipeline
  against it (identical to opening a PDF today), and mark the resulting `Page.rasterBg = true` (new
  field). In `exportPdf()`, extend the background-image condition (currently
  `flatten && page.srcIndex !== null`) to also draw `page.bgCanvas` when `page.rasterBg`, so merged
  pages render as a flattened raster + editable-overlay, same visual model as a permanently-flattened
  original page.
- Appends at the end of `state.pages`; each inserted page pushes its own `page-add` command (batch of N,
  consistent with existing granular history).
- Lives in the **editor** (appends into the currently-open document) rather than the dashboard —
  dashboard-level "combine two saved documents" is a materially different feature and out of scope here.

### 6b. Split (multi-select in page strip)
- Page strip (`buildPageStrip()` / `.page-thumb`) gets a "Select" mode toggle. While active, each
  thumbnail shows a checkbox overlay; clicking toggles selection instead of navigating.
- `selectedPages: Set<Page>`; toolbar shows "Export N pages" (enabled once ≥1 selected) + Select
  all/Clear.
- `exportPdf()` is parameterized to accept an optional page subset (defaulting to `state.pages`),
  reusing 100% of the existing per-page copy/flatten/draw logic, just iterating the filtered list.
  Downloads as `<name>-selection.pdf`; does **not** modify the open document. Exiting select mode
  restores normal click-to-navigate.

### 7. Form filling (flatten-to-static values)
- On `openPdfBytes`, load a pdf-lib `PDFDocument` for the original bytes (already done at export time;
  reuse the same load here), call `.getForm()` → `.getFields()`, and resolve each field's widget
  rectangle + owning page (cross-referencing widgets against `original.getPages()` — a known-fiddly,
  version-sensitive area of pdf-lib's API, flagged as an implementation risk to work through carefully).
- New `FieldEl extends BaseEl { type: 'field'; fieldType: 'text'|'checkbox'|'dropdown'; name: string; value: string; checked?: boolean; options?: string[]; fontSize: number }`.
  **Scope: text fields and checkboxes fully supported; dropdowns via a native `<select>`; radio groups
  and signature fields explicitly out of scope for v1.**
- `makeElNode` renders a real `<input>`/checkbox/`<select>` inside `.pel-content` (always "live", no
  separate edit-mode toggle needed) instead of the contenteditable div text elements use.
- Each field element gets `el.src` set to its widget rect, exactly like extracted text — deleting a
  field element whites out that region on export via the existing erasure path, no changes needed there.
- **Export decision (confirmed): flatten to static content.** `exportPdf()` gets a `'field'` case that
  draws the current value directly onto the page — text via the same font/whiteout approach as `'text'`
  elements, checkboxes as a checkmark + box outline, dropdowns as the selected option's label — baked in
  exactly like every other element this editor already flattens. The exported PDF's form fields are
  **not** interactive afterward; this matches how the rest of the editor treats "original content" edits
  and avoids the much harder problem of preserving/merging the AcroForm dictionary through the
  copy-pages export step.
- **Deliverable**: write `apps/www/_docs/pdf_editor_form_filling_todo.md` documenting the deferred
  "keep fields live/interactive" enhancement — what it would require (preserving the AcroForm
  dictionary through `copyPages`, `form.updateFieldAppearances()`, the specific pdf-lib risk areas found
  during this implementation) so it's ready to pick up later.

**Phase 3 verification**: `npm run build`, then in-browser: insert a second PDF's pages and confirm
they render/extract correctly and export intact; select a subset of pages in the strip and export,
confirm the downloaded PDF contains only those pages in order; open a PDF with real form fields (a
sample AcroForm PDF), confirm detected fields render as live inputs, fill several, export, and confirm
the values appear baked into the output PDF.

---

## Cross-cutting execution notes
- Follow the exact "new `El` variant checklist" above for `redact`, `path`, and `field` — this is what
  makes copy/paste, duplicate, undo/redo, and thumbnails work without extra code.
- No existing test suite for this app (`package.json` has no `test` script; `vitest` is a bare
  dependency) — verification is `npm run build` for typecheck/build correctness plus hands-on browser
  verification per phase via the preview tool, not automated tests.
- Each phase is implemented and verified before the next begins; I'll report back after each phase
  rather than silently barreling through all three.
