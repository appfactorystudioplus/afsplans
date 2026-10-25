# Step 4 (web) — Save, export and fidelity

**Desktop step:** [04-save-export-and-fidelity.md](../../../../afsdesktop5/plans/pdf_editor/04-save-export-and-fidelity.md). Status: planning only.

## Semantics (identical)

**Save** writes the application format (model + delta + `extra/`) to the server; **Export** produces a PDF or `.afspdf` bundle for the user; the fidelity ladder S0 pass-through / S1 content-stream surgery / S2 rebuild / S3 flatten is the same shared code; no in-place edits — the stored `original.pdf` never changes.

## Web deltas

- **Export runs in a Worker** with `@cantoo/pdf-lib`; output is streamed to the destination when possible.
- **Choosing where it goes:** Chromium → File System Access API `showSaveFilePicker` (user picks the directory and name); Firefox/Safari → a normal download using the browser's own location setting. The UI states which behaviour applies.
- **"Overwrite existing"** is not offered on the web (the browser cannot overwrite without a picker handle); the picker's own confirmation applies.
- **Server-side copy of an export** can optionally be stored as a new library document ("Save a copy to my library").
- **Version history** (existing `pefeditor_document_versions`) now snapshots the **model revision**, not just a PDF: restore replaces the delta; "restore original" is always available.
- **Memory:** export of > 500 pages processes page chunks and uses `Blob` parts to avoid one giant ArrayBuffer; the earlier `@cantoo/pdf-lib` large-file lessons (copy untouched pages, don't load everything) carry over.
- **Encrypted/signed PDFs:** same warnings as the desktop; decrypt-on-import happens in the Worker and the decrypted copy is what is stored as the working document, with the encrypted original kept as `original.pdf`.

Tests: shared fidelity harness (Step 11) on the Node side plus a Playwright export of three fixtures in Chromium and Firefox; hash-equality of untouched pages (S0); picker vs download paths.
