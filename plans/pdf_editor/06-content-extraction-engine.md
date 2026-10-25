# Step 6 (web) — Content extraction engine

**Desktop step:** [06-content-extraction-engine.md](../../../../afsdesktop5/plans/pdf_editor/06-content-extraction-engine.md). Status: planning only.

The engine is shared TypeScript (D-W2) and runs in a **Worker** against pdf.js 6.x. Deltas:

- **pdf.js version gap:** the desktop is on 5.4.530, the web on 6.1.200. The engine must not use private fields (that is the point of the desktop engine); it depends only on public `getOperatorList()`, `getTextContent()`, `getAnnotations()`, resources and `page.render` with `OffscreenCanvas`. A version matrix test runs the same fixtures on both.
- **No DOM:** canvases are `OffscreenCanvas` (Chromium, Firefox, Safari 17+). Browsers lacking it get a clear unsupported message rather than a degraded path.
- **Memory:** per-page extraction results are written to the model pages as they complete and dropped from memory; the Worker never holds more than a window of pages.
- **Legacy path:** the existing `editor.ts` eager lifter stays as `engine = "legacy"` until the parser meets the Step 11 thresholds, selectable per document, mirroring the desktop switch.

Tests: shared fixtures, parity of extracted element lists between Node (desktop harness) and browser Workers (Playwright), Firefox and Chromium.
