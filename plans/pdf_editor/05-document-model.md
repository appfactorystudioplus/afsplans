# Step 5 (web) — Document model

**Desktop step:** [05-document-model.md](../../../../afsdesktop5/plans/pdf_editor/05-document-model.md). Status: planning only.

The model schema, delta operations (including page ops), `extra/` data and the `.afspdf` bundle are **shared verbatim** (D-W2); the web adds only persistence:

- Files on the storage node: `model/document.json`, `model/pages/<n>.json` (one file per page, fetched lazily), `assets/<sha256>.<ext>` (deduplicated), `extra/*.json`, `history/`.
- Endpoints: `GET/PUT /documents/{id}/model/manifest`, `GET/PUT …/pages/{n}`, `PUT …/delta` (with `If-Match` revision), `GET/PUT …/assets/{hash}` (idempotent; the server verifies the hash). The server treats all of it as opaque files plus a revision counter (D-W4).
- Schema version is carried in the manifest; a mismatch older than the client triggers a client-side migration, newer shows "update the app".
- `.afspdf` import/export is a zip read/written in the browser; the server never unpacks it.

Tests: shared model round-trip fixtures; revision conflict (409); asset dedupe; partial page fetch.
