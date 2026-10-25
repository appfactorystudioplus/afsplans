# Web PDF editor — overview (the browser counterpart of the desktop PDF plan)

Status (2026-10-12): **planning only — nothing is implemented.** This plan covers the Laravel web editor (`w/pdf_editor`, a separate git repository) and **points to the desktop plan** for every design that is shared. It exists because the desktop plan ([`afsdesktop5/plans/pdf_editor`](../../../../afsdesktop5/plans/pdf_editor/00-overview.md)) leans on things a browser does not have — above all the **C++ add-on** — and on a local library folder, so each desktop step needs a decision here: *reuse as is*, *adapt*, or *replace with a web-specific design*.
(Vocabulary, as everywhere in this repo's plans: a **step** is one numbered file; a **phase** (`P0`, `P1`, …) is a division of work inside it. **Step numbers are the same in both plans** — Step 7 here is Step 7 there — so a reader can move between them; each file here records only the **web delta** and says "read the desktop step" for everything shared. Completion records go in `complete/`, one per step file, written when the step is finished and verified.)

## What was asked

The owner asked for the desktop PDF application to become a library-based editor with import-as-copy, automatic OCR, a faithful "application format", templates and the web editor's page tools — and, separately, that **the web editor get its own plan, pointing at the desktop plan files, investigating what the web can and cannot do** (in particular the C++ add-on work).

## Findings: the web editor as it stands (read from `w/pdf_editor`, 2026-10-12)

- **Stack:** Laravel (PHP) with a Vite + TypeScript front end (`resources/js/editor.ts`, 3 700+ lines, plus `dashboard.ts`, `views.ts`, shell/templates). Dependencies: `pdfjs-dist` **6.1.200** (the desktop uses 5.4.530), `pdf-lib` and `@cantoo/pdf-lib`, **`tesseract.js` 7**, `fuse.js`. Auth is Checkpoint (`checkpoint.member` middleware); the app is one of five in `…_afs_apps_db` with the `pefeditor_` table prefix.
- **Storage model:** a document is **one uploaded PDF file** on the member's **storage-node disk** (`FunctionsController::memberPdfEditorStoragePath`, a per-member on-demand disk). Tables: `pefeditor_documents` (member, name, generated_name, path, size), `pefeditor_document_versions` (a snapshot of the outgoing file on every update — this is the version history), `pefeditor_document_shares` (member shares and link tokens with view/edit). Uploads are capped at **50 MB** (`max:51200`). An afs-connect ingest dispatcher is notified on upsert.
- **Editor behaviour (already there):** it lifts text and images from every page eagerly into editable elements (the same idea the desktop view used before this plan), draws a raster background, exports through `pdf-lib` (copy untouched source pages; flatten edited pages; embed standard fonts), and has page tools: **add blank page, insert another PDF's pages, delete page, drag-to-reorder thumbnails, select pages and export them as a new PDF, watermark / page numbers / header-footer stamps, signature (draw/type), pen and highlighter, shapes, redact (cover), image insert, AcroForm field detection, search, per-page OCR to an invisible text layer (tesseract.js), autosave to the server, version history and restore, member/link sharing.** The same text-width problem as the desktop's (Helvetica substituted for narrower fonts) and the same raster-everything limits apply.
- **Not there:** a library with folders, import-time analysis or progress, an application format, templates, true redaction, fonts beyond the standard three, merging documents from a library.

## The investigation: what the web can and cannot reuse

| Desktop plan element | Web verdict | Why / what instead |
|---|---|---|
| Parser, model, delta, page operations, template engine, layout engine, text/typography logic, font ranking, ipsum generator, search index ([Steps 5, 6, 9, 13, 14](../../../../afsdesktop5/plans/pdf_editor/00-overview.md)) | **Reuse as shared TypeScript** | pure TypeScript, DOM-free; runs in a Web Worker. Shared by copying with a hash manifest (see D-W2). |
| **Tesseract (WASM)** as the recognition engine ([Step 7](../../../../afsdesktop5/plans/pdf_editor/07-image-text-analysis-and-ocr.md)) | **Reuse — it is already the web's engine** | tesseract.js is a dependency today; the desktop choice of a multiplatform Tesseract was made partly so both apps share engine and results. Web adds: self-hosted worker/core/traineddata (no third-party CDN), on-demand language packs from our own storage. |
| **C++ `pdfvision` add-on** ([Step 8](../../../../afsdesktop5/plans/pdf_editor/08-native-addon-pdf-vision.md)) | **Cannot run in a browser** | a Node-API `.node` module; no equivalent. Three ways forward (investigated in [08](08-vision-without-native.md)): the **TypeScript twins** the desktop plan already requires (the baseline, runs in a Worker), a **WebAssembly build of the dependency-free core** of the C++ (needs the Emscripten toolchain, which is **not installed** here — a spike that needs the owner's OK to install it), or a **server-side job** running a native CLI built from the same core. Recommendation: TypeScript twins first, WASM second, server last. |
| FFmpeg in the add-on (decode, scale, filter graph) | **Replace** | the browser decodes and scales with `createImageBitmap`, `OffscreenCanvas` and canvas filters; the design in Step 8 keeps FFmpeg-dependent functions out of the *core* so the core compiles to WASM. |
| Windows OCR via PowerShell, Apple Vision | **Not applicable** | desktop-only optional providers. |
| Local library folder `~/Documents/afsfiles/pdfs/`, `original.pdf` copy, folders as metadata | **Adapt** | the library is **server-side**: DB rows + files on the member's storage node ([Steps 1–2](01-library-and-import.md)). The invariants carry over (import = copy, original immutable, nested folders as metadata, Save = model, Export = output). |
| Analysis window (hidden BrowserWindow) | **Replace** | Web Workers + `OffscreenCanvas`; a long job is a page-level job object with progress and cancel ([Step 3](03-open-progress-and-sessions.md)). |
| 40 MB bundled fonts | **Adapt** | no installer: fonts are **static files on our origin** (self-hosted, licence files alongside), loaded per family on demand through `@font-face`/`FontFace`; the "core set" is the prefetch list. Same font index and ranking ([Step 9](09-fonts-and-text-editing.md)). |
| `@cantoo/fontkit` embedding, `@cantoo/pdf-lib` export | **Reuse** | both run in the browser (the web already uses `@cantoo/pdf-lib`); bundle size is the only cost (lazy-loaded chunk). |
| pdf.js private `_intentStates` access | **Drop (both)** | the parser of Step 6 removes the dependency; web pdf.js is 6.x so the old trick would not have carried over anyway. |
| Save-to-library / Export-to-a-chosen-directory | **Adapt** | Save = upload the model; Export = a download, with the **File System Access API** (`showSaveFilePicker`) to let Chromium users pick the exact location and a normal download elsewhere ([Step 4](04-save-export-and-fidelity.md)). |
| Highlight editable items ([Step 10](10-editable-highlight-and-selection.md)) | **Reuse** | CSS + the shared page view-model. |
| Fidelity harness ([Step 11](11-fidelity-testing-and-corpus.md)) | **Reuse the Node harness; add a browser run** | the harness imports the shared TypeScript modules; a Playwright run covers the real browser path. |
| Templates, page tools ([Steps 13–14](13-templates.md)) | **Reuse the engines and formats; web UI** | the web already has several tools; they move onto the shared reducers. |
| `.afspdf` bundle | **Reuse the format** | upload/download of the same zip; server stores model and assets as files. |

## Architecture decisions

| # | Decision | Notes |
|---|---|---|
| D-W1 | **Same invariants as the desktop:** the editor works on the stored copy, the original is immutable, Save writes the application format, Export produces output, nothing is edited in place, folders are metadata. | Desktop D2, D3, D17. |
| D-W2 | **One TypeScript code base, shared by copy.** The shared modules (parser, model, delta/page ops, layout/template engine, text, fonts logic, search, ipsum) live in the desktop repo under `src/shared/pdf/` (created by the refactor in desktop Step 12) and are **copied into the web repo** with a hash manifest and `npm run test-shared-pdf-parity`, the same contract the estate already uses for `portal.d.ts` (never import another repo's source; copy and keep it current). | Avoids a monorepo conversion; makes drift a failing test. |
| D-W3 | **Heavy work in Workers** (parser, analysis, OCR, export), progress messages to the page, cancel via `AbortSignal`; no heavy work on the UI thread. | Desktop D10. |
| D-W4 | **Server stores files and indexes, not logic.** Laravel keeps DB rows (documents, folders, templates, jobs) and file paths on the storage node; the model/assets are plain files; the server never parses PDFs (a server-side vision job is an optional later addition, [08](08-vision-without-native.md)). | Keeps the PHP side simple and the privacy story clear. |
| D-W5 | **Automatic OCR runs in the browser** after the editor opens (Workers), results stored in the model; opt-out in settings. | Desktop Step 7; tesseract.js is already the engine. |
| D-W6 | **Large files:** chunked, resumable uploads (tus-style or Laravel chunk endpoints) replace the 50 MB single request; the limit becomes a setting (default 500 MB); progress is real. | Desktop Step 1 import copy. |
| D-W7 | **Self-hosted assets only:** pdf.js worker (already inlined), tesseract worker/core/language data, fonts — no third-party CDN at run time (privacy, CSP, offline-ish behaviour). | Today tesseract.js defaults to a CDN. |

## Order of work and milestones

Mirrors the desktop milestones; the web can start M1 immediately because the server side is small.

```
shared modules exist (desktop Step 12 P1) ─┬─> W-M1: library + folders + chunked upload + progress + Save/Export (Steps 1–4)
                                           ├─> W-M2: fonts (self-hosted, search, auto-select) + highlight (Steps 9–10)
                                           ├─> W-M3: parser/model/lossless export in a Worker (Steps 5–6)
                                           └─> W-M4: OCR automatic + vision twins (Steps 7–8), WASM spike
                                               W-M6: templates + page tools on shared engines (Steps 13–14)
```

## Risks

| Risk | Mitigation |
|---|---|
| Browser memory/CPU limits on big PDFs | Workers, page-at-a-time model files, streaming upload, memory budget per tab, warnings for > 500 pages. |
| Shared code drifts between repos | Hash manifest + parity test (D-W2); changes made in the desktop repo first. |
| WASM build needs a toolchain not installed here | Spike gated on the owner's OK to install Emscripten ([08](08-vision-without-native.md)); TypeScript twins are the shipped baseline meanwhile. |
| `SharedArrayBuffer`/threads need cross-origin isolation headers | Not required by the baseline; a WASM threaded build would need COOP/COEP on the app origin — noted as a precondition, not assumed. |
| Server disk growth (originals + models + history) | Per-member quotas and the trash/purge rules mirrored from the desktop; sizes shown in the library. |
| Schema ownership | New tables follow `AGENTS.md` §5 (apps database, `pefeditor_` prefix, migrations owned by this app). |

## Definition of done

Every step file 01–14 has a completion record; the web library, import/export, fonts, highlight, automatic OCR, templates and page tools work end to end in the real app against the shared fixtures; the shared-code parity test is green; the capabilities that depend on native code are documented as TypeScript/WASM/server with their measured speed.

## Out of scope

Real-time collaboration, sync between the desktop and the web library (the formats are shared so it can be added), native mobile.
