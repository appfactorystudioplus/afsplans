# 09 — Feature: Project Bundle Export / Import (.dgpack zip)

> **Requirement:** export a zip file containing **all assets** plus a
> **configuration file** describing how nodes, text, and assets are placed —
> and import it back.

This is the portable, self-contained form of a project: one file that carries the
entire story (graphs, node positions, all text, entities, variables, templates,
localized strings) *and* every referenced media file. Use cases:

- **Backup / archival** — a project snapshot you can store anywhere.
- **Transfer** — move a project between environments (local ⇄ staging ⇄ another
  user's account) with zero manual asset re-uploading.
- **Sharing & templates** — hand a starter project or a finished demo to a teammate.
- **Subtree sharing** — export one container (a chapter, a reusable dialogue set)
  and import it into a different project.

## 1. Bundle format

Extension **`.dgpack`** (plain zip, so anyone can open it). Layout:

```
MyStory.dgpack
├── manifest.json            ← what this bundle is (read this first, always small)
├── project.json             ← the configuration file: full canonical export
├── texts/                   ← localization tables, one file per language (phase 7+)
│   ├── en-US.json
│   └── de-DE.json
├── assets/
│   ├── 018f3c2e-….png       ← original files, stored as {asset_uuid}.{ext}
│   ├── 018f3c2f-….mp3
│   └── …
└── preview.png              ← optional canvas snapshot of the root graph (nice in file pickers)
```

### manifest.json

```json
{
  "format": "dgpack/1",
  "app_version": "0.9.0",
  "kind": "project",                  // "project" | "subtree"
  "project_uuid": "018f…",
  "name": "MyStory",
  "exported_at": "2026-07-14T18:20:00Z",
  "exported_by": "user uuid",
  "counts": { "graphs": 14, "nodes": 812, "connections": 1240, "assets": 63 },
  "assets_bytes": 48123904,
  "checksums": { "project.json": "sha256:…" }
}
```

### project.json — the configuration file

Exactly the **canonical `dreamgraph/1` export schema** (doc 06 §9) — the bundle does
not invent a second format; it wraps the canonical one and adds the binaries:

- `settings`, `entities`, `variable_sets`, `templates`
- `asset_index`: for every asset — `uuid`, `name`, `folder_path`
  (materialized, e.g. `"Portraits/Chapter1"`), `tags`, `mime_type`, `kind`,
  `checksum_sha256`, `file` (relative path in `assets/`), dimensions/duration
- `graphs`: the containment tree (nested `children`), and per graph:
  `viewport` and its `nodes` (uuid, type, technical/display name, **x, y, width,
  height**, color, `data` payload incl. all text fields and `…asset_uuid`
  references) and `connections` (source/target uuid+pin, label, condition,
  sort_order)

So "how the node, text and assets are placed" is fully covered: node placement =
`x/y/width/height` + graph tree; text = `data` fields (+`texts/` for translations);
asset placement = `asset_index.folder_path` for the library and `asset_uuid`
references inside node/entity data for usage.

**Rules:** UTF-8, deterministic key and array ordering (sorted by `sort_order`,
then uuid) so identical projects produce byte-identical `project.json` — diffable
and testable. Asset binaries are stored uncompressed in the zip (`store` method)
since media is already compressed; JSON is deflated.

## 2. Export

### UX

- Project dashboard + editor **⋯ menu → "Export bundle (.dgpack)"**; options:
  include assets (default on), include localization languages, include preview.
- **Subtree export:** context menu on a container node or graph-tree entry →
  "Export as bundle…" — bundles that subtree's graphs/nodes plus only the assets,
  entities, and variables referenced within (referenced-only closure), with
  `kind: "subtree"`.
- Export runs as a **queued job** with a progress toast → signed, expiring
  download URL (same pipeline as the JSON export, doc 03 §3).

### Backend

- `POST /api/projects/{project}/export/bundle` (and
  `POST /api/nodes/{node}/export/bundle` for subtrees) → job id;
  `GET /api/exports/{id}` → status + download URL.
- Build with **streamed zip writing** (`maennchen/zipstream-php`) into a temp file
  on the `exports` disk — never hold assets in memory; skip-and-report assets whose
  files are missing on disk (broken references become a manifest `warnings` list,
  not a failed export).
- `ExportService` produces `project.json` from the same serializer as the plain
  JSON export (one code path, doc 06 §9); `AssetService` streams each file by uuid.
- Retention: generated bundles auto-delete after N days (config) via scheduled job.

## 3. Import

### UX

1. Projects dashboard → **"Import bundle"** (or drag a `.dgpack` onto the page);
   subtree bundles can also be imported from inside the editor
   (graph-tree context menu → "Import bundle here…").
2. Upload (chunked, same limits machinery as assets, doc 05) → server validates →
   **preview step**: name, counts, asset size, format version, warnings
   (e.g. "3 assets missing from bundle", "made with a newer app version").
3. User picks the mode, confirms, watches progress, lands in the imported project.

### Import modes

| Mode | Behavior |
|---|---|
| **New project** (default) | Creates a fresh project. UUIDs are **kept** (they don't collide across projects, and keeping them preserves game-code references). Name gets "(imported)" suffix if taken. |
| **Restore over existing** | Only offered when `project_uuid` matches an owned project: snapshot the current state first (doc 06 §13), then replace contents wholesale. This is the backup-restore path. |
| **Subtree into current project** (`kind: "subtree"` or any bundle) | Imports the graph tree under a chosen container/graph. **UUIDs are re-minted** (a subtree may be imported twice); entities/variables/templates are matched by `technical_name` first (reuse on match — the Bartender stays one Bartender), created on miss. A remap report lists every merge decision. |
| **Dry run** | Any mode with `dry_run: true` → full validation + remap report, zero writes. The preview step uses this. |

### Backend

- `POST /api/projects/import/bundle` (new/restore) and
  `POST /api/graphs/{graph}/import/bundle` (subtree) — multipart or chunked upload
  ref; `mode`, `dry_run` params. Queued `ImportBundleJob`; progress via polling
  (`GET /api/imports/{id}`) — websocket push in phase 8.
- `ImportService` pipeline, everything inside one DB transaction per phase with
  the whole import wrapped so failure = clean rollback (uploaded asset files for a
  failed import are garbage-collected):
  1. **Validate** manifest (`format` version gate — `dgpack/1` only, forward-minor
     tolerant), `project.json` against the export JSON Schema, checksum check.
  2. **Plan** — build the uuid keep/remap table + entity/variable matching; emit
     the report (this is the dry-run output).
  3. **Assets** — for each `asset_index` entry: if an asset with the same
     `checksum_sha256` already exists in the target project, **reuse it** (remap
     the uuid); else store the file (quota-checked, MIME re-sniffed — never trust
     the bundle), create the row, recreate `folder_path` folders, queue thumbnails.
  4. **Catalogs** — templates → variables → entities (dependency order).
  5. **Graphs** — insert the tree top-down (graphs → nodes → connections),
     rewriting every remapped uuid inside `data` payloads via the same
     `…asset_uuid`/`…_uuid` key conventions the usage scanner relies on (doc 03 §2).
  6. **Verify** — referential sweep (all uuid references resolve); problems-panel
     entries for anything intentionally imported broken (e.g. jump to a node the
     subtree bundle didn't include → flagged, not fatal).

### Security (bundles are untrusted input)

- Zip hardening: reject entry paths with `..` or absolute paths (zip-slip), entry
  count + per-file and total uncompressed size caps (zip-bomb), decompression
  ratio guard.
- Assets re-validated exactly like direct uploads: server-side MIME sniff,
  extension normalization from sniffed type, SVG sanitization, image dimension
  caps, project quota enforced *before* extraction of each file.
- `project.json` size cap; JSON Schema validation before any DB write; import
  runs as the uploading user with normal `ProjectPolicy` checks.
- **A bundle is data, never instructions:** nothing in it is executed; scripts in
  node data remain inert text until the sandboxed interpreter (doc 06 §5) runs
  them in play mode, which has no I/O capabilities by design.

## 4. Frontend implementation notes

- `panels/` gains `ImportExportDialog.tsx`: mode picker, dry-run report table
  (creates/merges/warnings), progress bar (poll), Tabler modal styling.
- Drag-a-`.dgpack`-file detection sits beside the asset file-drop branch in the
  dashboard/editor drop handlers (`.dgpack`/`application/zip` + manifest sniff).
- Export/import availability and progress surface in the existing autosave/status
  indicator area of the toolbar.

## 5. Compatibility & versioning

- `dgpack/1` wraps `dreamgraph/1`. Bump rules follow the export schema: additive
  fields never bump; breaking changes create `dgpack/2` with an importer that
  still reads `/1` (importers are kept for all past versions — bundles are
  long-lived archives).
- Round-trip guarantee (tested in CI): export → import as new project → export
  again ⇒ the two `project.json` files are identical except `project_uuid`,
  timestamps, and (for subtree mode) remapped uuids.

## 6. Acceptance criteria (ships with phase 7)

1. Export a project with 50 nodes across 3 nesting levels and 10 assets → one
   `.dgpack`; unzip by hand shows manifest, readable `project.json`, and all 10
   original files.
2. Import it into a second account as a new project → identical canvas layout
   (positions, colors, sizes), working portraits/images, entities/variables intact;
   round-trip export matches (§5).
3. Restore mode: mangle a project, import its earlier bundle with "Restore" →
   pre-restore snapshot exists; project content matches the bundle.
4. Subtree flow: export one container, import it twice into another project →
   two independent copies; the Bartender entity was merged by technical name, not
   duplicated; remap report says so.
5. A crafted malicious zip (path traversal entry, 10:1000 compression bomb,
   fake-PNG executable) is rejected at validation with a clear error and zero
   partial writes.
6. A bundle referencing 2 GB of assets on a project with a 1 GB quota fails the
   dry run with a quota message before any upload processing.
