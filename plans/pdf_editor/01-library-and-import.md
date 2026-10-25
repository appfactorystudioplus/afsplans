# Step 1 (web) — Library store and import

**Desktop step:** [01-library-store-and-import.md](../../../../afsdesktop5/plans/pdf_editor/01-library-store-and-import.md) — read it first; this file records only the web delta. Status: planning only.

## What is the same

Import = copy; `original.pdf` is immutable; the model, `extra/`, thumbnails and history sit beside it; folders are metadata only (nested); a document id is stable; nothing is edited in place (D-W1).

## What differs

| Topic | Desktop | Web |
|---|---|---|
| Where it lives | `~/Documents/afsfiles/pdfs/<id>/` | the member's storage-node disk, `FunctionsController::memberPdfEditorStoragePath()/<id>/` (same on-demand disk the editor already uses) |
| Index | `manifest.json` rebuilt from folders | DB, authoritative: `pefeditor_documents` (existing) gains `folder_id`, `status` (`importing`/`ready`/`failed`), `page_count`, `original_sha256`, `model_version`, `trashed_at`; new `pefeditor_folders` (`id`, `member_reference_num`, `parent_id`, `name`, timestamps). All new tables/columns follow `w/AGENTS.md` §5 (apps DB, `pefeditor_` prefix, migrations owned by this app). |
| Import | file copy | **chunked, resumable upload** (D-W6): `POST /documents/uploads` (create session) → `PUT …/{session}/chunks/{n}` (8 MB) → `POST …/{session}/complete`; the server assembles into `original.pdf`, records the SHA-256, never rewrites it. The current single request capped at 50 MB is kept only for the legacy path until the library ships. |
| Existing documents | – | Migration: each existing `pefeditor_documents` row becomes a library document at the root; its current file becomes `original.pdf`; version rows stay as history. Dry-run command first; no file is deleted. |
| Delete | library trash 30 days | `trashed_at` + scheduled purge job (Laravel scheduler); quota counts trashed files until purge. |
| Quota | disk free space | per-member quota setting (default 5 GB), checked at upload-session creation. |

## Work

- **P0:** migrations (folders, new columns), models, `LibraryController` (list/create/move/rename/trash/restore folders and documents), chunk-upload endpoints, import migration command.
- **P1:** per-member quota and purge job; integrity check (re-hash on demand).
- **Tests:** PHPUnit against the real MySQL test DB (no SQLite rewrites) for upload assembly, resume after a dropped chunk, quota refusal, move/rename, trash/purge, authorisation (member cannot see another member's ids, shares still honoured); a browser test for resume after reload.

## Acceptance

A 400 MB PDF uploads with real progress, survives a dropped connection, lands byte-identical (hash equal), and appears in the library; legacy documents appear at the root with versions intact.
