# Step 9 (web) — Fonts and text editing

**Desktop step:** [09-fonts-and-text-editing.md](../../../../afsdesktop5/plans/pdf_editor/09-fonts-and-text-editing.md). Status: planning only.

Font ranking, automatic selection from a selected text (or next best), searchable font box and embedding are shared code. Deltas:

- **Distribution:** the same curated families (the desktop's `assets/pdf-fonts/` TTFs with `catalog.json` and licences) are published as static files under `/fonts/pdf/` on the app origin, with licence files alongside. The "core" set (~40 MB on desktop) is **not downloaded up front**: the catalog (small JSON) loads at start, each family loads on demand through `FontFace` when previewed or applied, and the most common ~10 families are prefetched during idle time.
- **Cache:** `Cache API` keyed by family + file hash; survives reloads; evicted by size cap.
- **On-demand extra families** (the larger Google Fonts set): fetched via our server from Fontsource (no browser calls to third parties), stored on our storage once, then served from our origin.
- **No system fonts** (same decision; `queryLocalFonts` is not used).
- **Embedding on export** uses TTF subsets via `@cantoo/fontkit` (never WOFF2 for embedding — the known accented-composite crash applies equally in the browser).
- **Missing-font behaviour** is identical: next-best match with an explanation, shown in the font box.

Tests: ranking parity on the shared fixtures; first-paint with only the catalog; offline reuse from cache; export of a document using five families validates in pdf.js; licence file present for every served family.
