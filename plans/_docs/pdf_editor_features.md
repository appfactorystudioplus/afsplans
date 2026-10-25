Plenty — and your current architecture makes some of these surprisingly cheap. Grouped by how well they slot into what's already built:

## Quick wins (mostly reuse existing machinery)

- **Drag-to-reorder pages in the thumbnail rail** — the rail exists, pages are just an array, and export already assembles pages in editor order via `copyPages`. Reordering is basically a splice + `rebuildPages()`. Add rotate and duplicate-page to the same right-click menu.
- **Signatures** — draw in a small canvas popup (or type in a script font), convert to PNG data-URL, and it drops straight into your existing image-element pipeline: draggable, resizable, undoable, exports correctly. This is the single most-requested feature in PDF editors and you'd get it almost for free.
- **Search across the document** — the text runs are already extracted per page. A search box that filters them, highlights matches, and scrolls to the page (you already have `scrollIntoView` navigation) is a small feature with big utility.
- **True redaction** — you already built page flattening to solve the "hidden text survives whiteout" bug. Redaction is exactly that: black box + forced flatten, with a guarantee the text is really gone from the content stream. You can market a real security feature out of machinery you already have.
- **Merge & split** — pdf-lib's `copyPages` makes "insert pages from another PDF" and "export selected pages as a new document" straightforward, and the dashboard is a natural home for a "Combine PDFs" tile.

## Medium effort, high payoff

- **Custom fonts** (`@pdf-lib/fontkit`) — upload a TTF/OTF, embed it on export. This fixes your biggest fidelity limitation: edited text currently falls back to Helvetica/Times/Courier, so edits are visible on documents with distinctive fonts.
- **Form filling** — pdf-lib reads and fills AcroForm fields natively. Detect fields on open, overlay real inputs, write values back on export. Turns the app into a form-filler, which is half of why people open PDF editors at all.
- **Freehand pen + highlighter** — one new element type (a point path), rendered as SVG in the overlay and exported with pdf-lib's `drawSvgPath`. Highlighter is the same with multiply-blend yellow.
- **Snapping & alignment guides** — while dragging, snap to page center/margins and other elements' edges, drawing the familiar magenta guide lines. Pure editor polish, no export changes.
- **Watermarks / page numbers / headers-footers** — batch-stamp every page; trivial loop at export time, just needs a small dialog.

## Bigger bets

- **Autosave + version history** — you already regenerate the whole file each save; keep the previous `generated_name` files instead of deleting them, add a `pefeditor_document_versions` table, and offer "restore version" from the dashboard.
- **OCR for scanned PDFs** (tesseract.js in a worker) — makes image-only pages searchable and text-editable. Heavy dependency, but it's the feature that separates serious PDF tools from toys.
- **Sharing** — share a document with another member (read-only link or copy-to-their-workspace). Your member/brain infrastructure already gives you the identity plumbing.

If I were picking the next three: **signatures** (huge demand ÷ tiny effort), **page reorder in the rail** (completes the page-management story), then **custom fonts** (fixes the fidelity ceiling everything else sits under). Want me to build any of these?







============================== DONE

All five features are built, type-check clean, and the production build passes (the 12 pre-existing errors are all in unrelated scaffolding — `NotesApp.ts`/checkpoint modules — not anything I touched).

## What shipped

**Page reorder** — drag-and-drop thumbnails in the rail; undoable via a new `page-reorder` history command. `srcIndex` is per-page so reordering never affects export correctness.

**Signatures** — new toolbar button opens a modal to draw (canvas + pointer events, trimmed to ink bounds) or type (3 script-font choices) a signature; converts to PNG and drops into the exact same image-element pipeline as the Image tool (draggable, resizable, undoable). Last signature is remembered in localStorage.

**Autosave + version history** — every edit debounces a silent 15s autosave once a doc has been saved once. The server snapshots the outgoing file as a version on *every* save (explicit or auto) rather than deleting it — new `pefeditor_document_versions` table. A History panel lists versions with download/restore; restoring snapshots current content first, so it's always reversible.

**OCR** — a per-page "Make searchable" button runs Tesseract.js on the rendered page, turns each recognized line into a text element drawn at 0% opacity on export (invisible, but selectable/searchable in any PDF viewer) and dimmed-with-outline in the editor. Since OCR'd elements aren't tied to an original PDF region, they never trigger page-flattening — the scan stays pixel-exact.

**Sharing** — share with a specific member by email (new `getMemberByEmail` brain endpoint in `apps/www`, since none existed) or generate a link, each with view/edit permission. Backend access control unified under one `resolveAccess()` that checks ownership, member-shares, and link-tokens. Dashboard gets a "Shared with you" section; a `#/shared?token=...` route resolves links. View-only access disables Save and skips autosave with a clear status message rather than silently 403ing.

## Things worth knowing

- **`apps/www` was modified** (`BrainController.php`, `routes/portal.php`) — a small additive endpoint, but it's the shared production brain, not just pdf_editor. It needs deploying before email-lookup sharing works.
- **Three new migrations**, none run (per your standing preference): document versions, document shares, and the `www` app has no migration (just a new model-backed endpoint on an existing table).
- **"Public" links still require Appfactory Studio login** — the whole SPA gates on Checkpoint auth before rendering anything, so a link grants access to *any signed-in member*, not literally anyone on the internet. Building true anonymous access would mean a separate unauthenticated route bypassing the SPA shell — happy to do that as a follow-up if you want it.
- **`npm install tesseract.js` triggered a much larger install than expected** — `package.json` already listed a long tail of unrelated packages (tui-image-editor, ace-builds, express, mysql2, survey-js-ui, etc.) that had apparently never been installed; my one command resolved all of them, which is where the jump to 19 npm audit findings came from. None of it is something I added — worth a cleanup pass separately if that dependency bloat isn't wanted.
- OCR relies on Tesseract.js's default CDN (jsdelivr) for its WASM core and language data — first OCR run on a page will have a few seconds of download latency, cached by the browser after that.
- I couldn't click through any of this in a live browser this session (no test environment set up) — verification was type-check + build + careful code review. If anything misbehaves once deployed, send specifics and I'll fix it directly.




