# 01 — Web App Audit

Audit of `apps/dream_graph` as of plan authoring. Use this as the feature
checklist for porting; verify against live source before each phase.

## Stack summary

| Layer | Technology |
|-------|------------|
| Backend | Laravel 12, Eloquent, queue jobs for export/import |
| Frontend | React 18 + TypeScript, Vite 7 |
| Graph canvas | **@xyflow/react** v12 (not Cytoscape/D3) |
| Tilemap render | HTML5 Canvas (`TilemapSurface`) — imperative |
| State | Zustand (`projectStore`, `tilemapStore`, `tilemapNav`, …) |
| Styling | Bootstrap 5 + Tabler + Tailwind 4 + SCSS |
| Auth | Checkpoint + Parse SDK (`resources/js/checkpoint/`) |

**SPA entry:** `resources/js/main.tsx` → `EditorShell` in `#graph-editor-root`.

## View routing (`EditorShell`)

```
SettingsView          (Checkpoint teams — online only)
ProjectsView          (list / create / import)
Editor
  ├── TilemapView     (when tilemapNav.openUuid set)
  └── Graph layout    (GraphCanvas + sidebars + inspector)
PresentationMode      (fullscreen play)
```

URL state via `history.pushState`: `/p/{projectUuid}`, `/p/{projectUuid}/t/{tilemapUuid}`.

## Core subsystems

### A. Story graph editor

- Infinite pan/zoom canvas, minimap, theme toggle
- **13 node types** in `resources/js/graph/model/nodeTypes/registry.ts`:
  `fragment`, `hub`, `condition`, `instruction`, `container`, `entry`, `exit`,
  `tilemap_ref`, `image`, `comment`, `group`, `note`
- Nested graphs via `container` → lazy child graph + Entry/Exit pins (max depth 32)
- Group frames via React Flow `parentId` ↔ `data.parent_uuid`
- Drag-from-palette, context menus, keyboard nudge, Tab-to-chain
- Breadcrumb + Graph Tree sidebar
- Inspector for selection properties
- **No auto-layout** (manual placement only)
- **No graph undo/redo** wired (`commands.ts` exists but unused)
- Autosave: 5s debounce → `PUT /api/graphs/{uuid}/content` (full state)

### B. Project catalogs (per project)

Loaded once into Zustand; mutations mostly immediate API calls:

- **Assets** — images/audio/video/docs, folders, thumbnails
- **Entities** — characters with templates
- **Variable sets / variables** — bool/int/string
- **Templates** — node/entity property schemas
- **Texts** — localization strings

`saveCatalogs()` in store is a **stub** — no batch flush on web either.

### C. Play mode & scripting

- Expression lexer/parser/interpreter (`resources/js/graph/scripting/`)
- `TraversalEngine` simulates story flow
- `PlayModePanel`, `PresentationMode`, `VariableInspector`
- Playthrough DB table exists; full client persistence partially documented

### D. Tilemap editor (“tiled” feature)

**Not** the Tiled.app editor — Dream Graph’s **integrated tilemap subsystem**.

| Concept | Implementation |
|---------|----------------|
| Tileset | Atlas image + `tile_meta` + autotile rules |
| Tilemap | Document with `version`, viewport, settings |
| Layer | Ordered stack bound to one tileset |
| Chunk | Sparse 32×32 region, base64 RLE tile IDs |
| Tools | brush, erase, rect, fill, picker, autotile, pan |
| Undo | stroke-based `UndoStack` (client-only) |
| Save | 1.5s debounce → `PUT .../chunks` with `base_version` |
| Conflict | HTTP 409 → client re-queues (multi-tab on web) |

**Graph link:** `tilemap_ref` node holds `tilemap_uuid`; double-click opens
`TilemapView` via `tilemapNav`.

**Engine bridge (online):**

- Sync tokens: `graph_tilemap_sync_tokens` (read/write abilities)
- `GET /api/tilemaps/{uuid}/export?since_version=N` — conditional pull
- `POST /api/tilemaps/{uuid}/import` — chunk push
- SSE notify channel **not implemented** (polling fallback)

### E. Export / import

- `.dgpack` zip — project, restore, subtree merge (queued jobs on web)
- Canonical JSON `dreamgraph/1`
- Localization CSV/XLSX
- Tilemap: `joystick-tilemap/1` zip + JSON slice
- Stats dashboard (client-computable from loaded data)

### F. Settings / teams

- Hybrid React + Blade `settings.html`
- Checkpoint PM API for teams/users — **not offline-viable**

## API surface (grouped)

Base path: `/api`

| Group | Key endpoints |
|-------|-----------------|
| Projects | CRUD `/projects` |
| Graphs | GET/PATCH graph; **PUT `/graphs/{uuid}/content`** full save |
| Nodes/connections | CRUD under graph |
| Assets | CRUD + upload + thumbnail serve |
| Entities, variables, templates | CRUD under project |
| Tilesets | CRUD + tile-meta + autotile-rules |
| Tilemaps | CRUD + **PUT chunks** + layer CRUD |
| Export/import | bundle jobs + download URLs |
| Engine sync | export/import with bearer token |
| Internal | `/internal/afs-connect/v1/*` |

Full route list: `apps/dream_graph/routes/api.php`.

## Persistence patterns (critical for port)

### Graph save — full-state sync

Client sends **all** nodes and edges. Server upserts by UUID, deletes rows
absent from payload (with Entry/Exit protection). Desktop must replicate this
merge semantics locally or keep one JSON file per graph.

### Tilemap save — chunk delta + version lock

Client sends only dirty chunks + `base_version`. Server rejects stale writes
with 409. Offline desktop can use single-writer (no 409 UI needed initially)
but should keep `version` field for future sync.

### Assets

Multipart upload → storage disk → serve via API URL. Desktop: copy file into
project folder, store relative path in manifest.

## Key source files (port priority)

| Priority | Path |
|----------|------|
| P0 | `resources/js/graph/shell/EditorShell.tsx` |
| P0 | `resources/js/graph/store/projectStore.ts` |
| P0 | `resources/js/graph/canvas/GraphCanvas.tsx` |
| P0 | `resources/js/graph/api/client.ts` → becomes adapter |
| P0 | `resources/js/graph/api/types.ts` |
| P0 | `resources/js/graph/model/nodeTypes/*` |
| P1 | `resources/js/tilemap/store/tilemapStore.ts` |
| P1 | `resources/js/tilemap/surface/TilemapSurface.ts` |
| P1 | `resources/js/tilemap/model/chunk.ts`, `rle.ts` |
| P1 | `resources/js/tilemap/api/tilemapClient.ts` |
| P2 | `resources/js/graph/playMode/*` |
| P2 | `resources/js/graph/scripting/*` |
| P3 | Export/import job handlers → port logic to main process |

Backend services to **reimplement in TypeScript or main process** for offline:

- `app/Services/GraphService.php` — nested graph helpers
- `app/Services/TilemapService.php` — chunk flush, version bump
- `app/Services/TilemapExportService.php` — zip assembly
- Export/import bundle builders (from Laravel jobs)

## Known gaps / debt (plan accordingly)

- API authorization disabled in dev in several controllers
- Graph undo/redo scaffolded but unwired
- No global graph node search on web
- Realtime tilemap SSE not built
- Engine-side sync client not built (JoysticEngine_v2 plan §9)
- No end-to-end live sync test documented

## What desktop can reuse vs rewrite

| Reuse as-is (renderer) | Rewrite / adapter |
|------------------------|-------------------|
| React components, Zustand stores | `main.tsx` auth bootstrap |
| Graph canvas, node renderers | All `fetch('/api/...')` calls |
| TilemapSurface canvas logic | Asset URL resolution |
| RLE/chunk TS modules | Laravel export zip (→ Node in main) |
| Scripting interpreter | Queue polling for exports |
| Play mode UI | Checkpoint settings |
