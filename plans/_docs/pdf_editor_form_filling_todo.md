# PDF Editor: Form Filling — Interactive Fields (deferred)

## Current behavior (shipped)

The editor detects AcroForm fields (text fields, checkboxes, dropdowns) when
opening a PDF and lifts each one into a real, editable overlay control —
`FieldEl` in `resources/js/editor.ts`. Filling them in works exactly like
editing any other extracted content: an untouched field is left alone and its
page exports by copying the original page verbatim (so an unfilled field
stays live and fillable in the output, unchanged). The moment a field is
edited it's marked dirty, which — via the same `pageNeedsFlatten()` mechanism
already used for edited text — forces that page to flatten: the page becomes
a raster, and the field's current value is drawn on top as **static, baked-in
content** (plain text for text fields, a checkmark + box for checkboxes, the
selected label for dropdowns). See the `'field'` case in `exportPdf()`.

**The tradeoff**: once a field has been filled in via the editor, the
exported PDF's copy of that field is no longer a live AcroForm field. It's
flattened, exactly like every other kind of edit this app makes to original
content. This was a deliberate scope decision (see the session that shipped
this) — it reuses the entire existing export pipeline with zero new
machinery, and is consistent with how the rest of the editor already treats
"edited original content." Radio groups, option lists, signature fields, and
buttons are also out of scope for v1 and are skipped entirely during
detection.

## What "keep fields live/interactive" would require

If a future pass wants the exported PDF to remain a fillable AcroForm (so
recipients can still edit values in Acrobat/other viewers, not just view a
flattened snapshot), the current copy-pages export architecture doesn't
support that for free. Specifically:

1. **The AcroForm dictionary isn't carried through `copyPages`.**
   `exportPdf()` copies individual *pages* from the original document into a
   fresh `PDFDocument` (`out`). Page-level content (including widget
   annotations) comes along with `copyPages`, but the *AcroForm* dictionary —
   the catalog-level structure that centrally lists every field, its type,
   default appearance, and value — lives on the document, not the page. It
   needs to be explicitly rebuilt or copied into `out`'s catalog, and
   cross-referenced against the (re-numbered) copied widget annotations.

2. **Filling should happen on the source document, before copying.** The
   correct approach is almost certainly: instead of drawing field values as
   flat content in `out`, fill the values directly into `original`'s own
   `PDFForm` (`original.getForm().getTextField(name).setText(value)`, etc.)
   *before* `copyPages` runs, so the filled appearance streams travel with
   the page copy naturally. This avoids the catalog-merging problem for the
   *page content* but the AcroForm dictionary itself would still need
   handling — see #1 — for the copied document to still present the fields
   as SUBMITTABLE/interactive rather than just visually-filled.

3. **`form.updateFieldAppearances()`** needs to run after setting values so
   each widget's `/AP` (appearance stream) actually reflects the new value —
   pdf-lib does this automatically for some field types on `setText`/`check`,
   but it's worth confirming per field type rather than assuming.

4. **Multi-widget fields.** A single field can have multiple widgets (e.g.
   the same "date" field repeated on several pages). The current `FieldEl`
   implementation treats each widget as an independent element with its own
   value — editing one instance doesn't update the others. A "keep it live"
   implementation should probably decide whether to preserve that
   per-instance independence (diverging from real AcroForm semantics, where
   one field value is shared across all its widgets) or properly sync
   sibling widgets, which is a design decision worth raising with product
   before building.

5. **Mixed pages.** A page that's *otherwise* untouched but has one filled
   field currently flattens the *entire* page (all elements redrawn on top of
   a raster) per the existing `pageNeedsFlatten` rule. An interactive-fields
   implementation would instead want to leave that page's *non-field*
   content (vectors, other text) completely alone and only touch the
   AcroForm/widget structure — a meaningfully different code path than
   "flatten and redraw everything," and would need its own carve-out in
   `exportPdf()`.

## Known risk areas found while building the current (flattened) version

- **pdf-lib's widget→page resolution isn't a single public one-liner.**
  `PDFForm.prototype.findWidgetPage` (the method pdf-lib itself uses
  internally) is `private`, so this session reimplemented its exact logic in
  `findWidgetPage()` in `editor.ts` using only public APIs (`widget.P()`,
  falling back to `PDFDocument.prototype.findPageForAnnotationRef` +
  `PDFContext.prototype.getObjectRef`). This is a `.d.ts`-verified,
  version-pinned pattern (checked against the installed `pdf-lib` types
  directly) but is worth re-verifying against `PDFForm.js`'s source if
  `pdf-lib` is ever upgraded, since it mirrors private internals rather than
  a stable public contract.

- **`PDFDocument.getForm()`'s behavior on a PDF with no AcroForm at all**
  wasn't empirically verified (no live browser test was run this session per
  the user's instruction to skip that) — the detection code wraps it in
  try/catch and simply skips field detection on any failure, so worst case is
  "no fields detected," not a crash.

## Suggested next step

Before investing in live/interactive fields, confirm it's actually wanted:
flattened-but-filled is what most "quick fill a PDF form" tools ship (it's
what the current implementation optimizes for), and true AcroForm
preservation is meaningfully more engineering for a feature that mostly
matters if users need to *re-edit* the same field values after export in
another PDF tool. If confirmed, start with item #2 above (fill into
`original`'s form before `copyPages`) as the smallest step that gets partial
value, before tackling the AcroForm-dictionary-merging problem in #1.
