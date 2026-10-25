# Step 2 (web) — Library view

**Desktop step:** [02-library-view.md](../../../../afsdesktop5/plans/pdf_editor/02-library-view.md). Status: planning only.

The library becomes the app's first screen (the current dashboard already lists documents; it is extended, not replaced). Everything in the desktop step applies — nested folder tree, breadcrumb, grid/list, thumbnails, search, sort, filters, New menu (blank, text-to-PDF, from template, merge, images→PDF, import) — with these web deltas:

- **Thumbnails** are generated in the browser at import (first page, Worker) and uploaded to `thumbs/` beside the document; the server serves them with caching headers.
- **Search** across names is a server query (`LIKE` + existing `fuse.js` for the client-side filtered list); full-text over recognised text is a later addition fed from the model's search index.
- **Drag and drop** of files onto the library starts chunked uploads ([Step 1](01-library-and-import.md)); dragging a document onto a folder moves it (one request).
- **Shares** (existing) show as badges; shared-with-me documents appear in a separate section, read-only unless edit was granted.
- **Routing** uses the existing Laravel routes (`/library/{folder?}`, `/editor/{id}`); no SPA router is added.
- **Strings** go through the app's existing translation mechanism (en/es/de/zh).

Tests: Playwright flows for folder create/rename/move, drag-drop upload, sort/filter, empty state, four languages, no console errors.
