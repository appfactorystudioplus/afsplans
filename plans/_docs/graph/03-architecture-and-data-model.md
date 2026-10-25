# 03 — Architecture & Data Model (React Flow + Laravel)

## 1. High-level architecture

```
Browser — React island (mounted by a Blade shell page)
┌──────────────────────────────────────────────────────────────┐
│  <EditorShell>  breadcrumbs · toolbar · panel dock            │
│  ┌───────────────────┐ ┌───────────┐ ┌────────────────────┐  │
│  │ <GraphCanvas>      │ │ Inspector │ │ Panels: Palette,   │  │
│  │  @xyflow/react     │ │ (node/    │ │ Assets, Entities,  │  │
│  │  custom nodeTypes/ │ │  edge/    │ │ Variables, Search, │  │
│  │  edgeTypes         │ │  graph)   │ │ GraphTree, Play    │  │
│  └────────┬──────────┘ └─────┬─────┘ └─────────┬──────────┘  │
│           └────── ProjectStore (Zustand) ───────┘             │
│                   document model · command undo stack ·       │
│                   dirty tracking · autosave (debounced)       │
└───────────────────────────┬──────────────────────────────────┘
                            ▼ JSON REST API (Sanctum session auth)
Laravel 12 ─────────────────────────────────────────────────────
  Controllers → FormRequests → Services (GraphService, AssetService,
  ExportService, NodeTypeRegistry-php mirror for validation)
  Eloquent models · MySQL/SQLite · Storage disks (assets)
  Queues: thumbnails, exports, snapshots · Reverb (phase 8 realtime)
```

Key decisions:

- **React island, not a React app-wide rewrite.** `routes/web.php` serves a Blade
  shell (`editor.blade.php`) that renders navbar chrome and one
  `<div id="graph-editor-root" data-project="{uuid}">`. `main.tsx` mounts React
  there. Laravel keeps auth, sessions, and every non-editor page.
- **The document model owns truth.** `ProjectStore` (Zustand) holds *our* schema
  (§2). React Flow is a controlled view of it: store state is mapped to
  `nodes`/`edges` props; canvas interactions (`onNodesChange`, `onConnect`, drag
  stops) dispatch **commands** to the store. We never persist React Flow's internal
  format — export = our schema, stable forever.
- **Per-graph loading.** Only the open graph's nodes/edges hydrate the canvas;
  project catalogs (entities, variables, templates, asset metadata, graph tree)
  load once per session and are cached. This keeps unlimited nesting and big
  projects cheap.
- **Command-based undo.** Every mutation is a command object
  (`AddNode`, `MoveNodes`, `Connect`, `EditProperty`, `WrapInContainer`…) with
  `apply()`/`invert()`. The undo stack is per-graph; the same command batches feed
  the autosave payload.

## 2. Database schema

All content tables get both `id` (bigint PK) and `uuid` (stable public identifier —
survives export/import and is what game code references). `ON DELETE` noted where it
matters.

```
projects
  id, uuid, owner_id → users, name, slug, description,
  settings JSON (default language, grid size…), created_at, updated_at, deleted_at

graphs                              -- one canvas; nesting via parent_node_id
  id, uuid, project_id → projects (cascade),
  parent_node_id → nodes (nullable, unique, cascade)  -- NULL = project root graph
  name, viewport JSON {x, y, zoom},                   -- last camera per graph
  created_at, updated_at

nodes
  id, uuid, graph_id → graphs (cascade),
  type VARCHAR            -- 'dialogue_fragment' | 'hub' | 'condition' | 'instruction'
                          -- | 'jump' | 'container' | 'entry' | 'exit' | 'comment'
                          -- | 'group' | 'image' | …
  technical_name VARCHAR NULL,     -- writer-facing stable name, unique per project
  display_name VARCHAR NULL,
  x DOUBLE, y DOUBLE, width DOUBLE NULL, height DOUBLE NULL, color VARCHAR NULL,
  data JSON,              -- type-specific payload (text, speaker_uuid, expression…)
  template_id → templates NULL,
  sort_order INT,         -- stacking + deterministic export order
  created_at, updated_at
  INDEX (graph_id), INDEX (type), UNIQUE (uuid)

connections
  id, uuid, graph_id → graphs (cascade),
  source_node_id → nodes (cascade), source_pin SMALLINT DEFAULT 0,
  target_node_id → nodes (cascade), target_pin SMALLINT DEFAULT 0,
  label VARCHAR NULL, color VARCHAR NULL,
  condition TEXT NULL,    -- pin/choice script (phase 6)
  sort_order INT          -- choice ordering matters for menus
  INDEX (graph_id)

asset_folders
  id, uuid, project_id (cascade), parent_id → asset_folders NULL (cascade), name

assets
  id, uuid, project_id (cascade), folder_id → asset_folders NULL (null on delete),
  name, original_filename, disk, path, mime_type, kind ENUM(image,audio,video,doc),
  size_bytes BIGINT, width INT NULL, height INT NULL, duration_ms INT NULL,
  checksum_sha256 CHAR(64),        -- dedupe on upload
  meta JSON (exif, tags…), uploaded_by → users, created_at, deleted_at (soft)
  INDEX (project_id, folder_id), INDEX (checksum_sha256)

entities                            -- characters, items, factions…
  id, uuid, project_id (cascade), kind VARCHAR ('character','item','custom'…),
  technical_name, display_name, color,
  portrait_asset_id → assets NULL (null on delete),
  template_id → templates NULL, properties JSON, created_at, updated_at

variable_sets
  id, uuid, project_id (cascade), name  -- namespace, e.g. "quests"
variables
  id, uuid, variable_set_id (cascade), name, type ENUM(bool,int,string),
  default_value VARCHAR, description TEXT
  UNIQUE (variable_set_id, name)

templates                           -- user-defined property schemas (phase 5+)
  id, uuid, project_id (cascade), applies_to ENUM(node,entity), name, color
template_properties
  id, template_id (cascade), name, label,
  type ENUM(text,number,bool,select,entity_ref,asset_ref,slider),
  config JSON (options, min/max…), sort_order

texts                               -- localization (phase 7)
  id, project_id (cascade), object_uuid, field VARCHAR, lang CHAR(5), value TEXT
  UNIQUE (object_uuid, field, lang)

snapshots                           -- versioning (phase 8)
  id, project_id (cascade), created_by, label,
  payload LONGTEXT/JSON (or object storage), created_at

playthroughs                        -- saved simulation runs (phase 6)
  id, uuid, project_id (cascade), name, created_by,
  steps JSON (ordered node uuids + choices + variable states), created_at

comments                            -- review threads (phase 8)
  id, uuid, project_id (cascade), node_id → nodes (cascade), author_id,
  body TEXT, resolved_at NULL, parent_id NULL, created_at
```

Notes:

- **Nested graphs are a tree by construction**: `graphs.parent_node_id` points at a
  `container` node (unique index → one child graph per container). Deleting a
  container cascades its graph → nodes → deeper graphs, recursively.
- **Asset usage tracking** is computed: JSON references always use an `asset_uuid`
  key convention, so "where used" is a JSON scan (promote to an `asset_usages` pivot
  if profiling demands).
- **Group nodes** (visual frames) persist like any node (`type='group'`); children
  store `parent_uuid` in `data` — mapped to React Flow's `parentId` at hydrate time.
- SQLite in dev, MySQL in prod. JSON columns are rarely queried directly (search has
  its own path, doc 06 §8).

## 3. API design (REST, `routes/api.php`, Sanctum session auth)

```
GET    /api/projects                         list own projects
POST   /api/projects                         create (makes root graph automatically)
GET    /api/projects/{project}               project shell: settings + catalogs
                                             (entities, variable sets, templates,
                                              asset folder tree, graph tree summary)
PATCH  /api/projects/{project}
DELETE /api/projects/{project}

GET    /api/graphs/{graph}                   full graph: nodes + connections + breadcrumb path
PATCH  /api/graphs/{graph}                   rename, viewport
PUT    /api/graphs/{graph}/content           bulk save (autosave: changed nodes/connections)
POST   /api/graphs/{graph}/nodes             create node (returns uuid)
PATCH  /api/nodes/{node}                     move/resize/data edit
DELETE /api/nodes/{node}                     (cascades child graph if container)
POST   /api/nodes/{node}/child-graph         create+return child graph (nested feature)
POST   /api/graphs/{graph}/wrap              convert selection → container (server-side move)
POST   /api/graphs/{graph}/connections
DELETE /api/connections/{connection}

POST   /api/projects/{project}/assets        multipart upload (chunked >10 MB)
GET    /api/projects/{project}/assets        list w/ filters (folder, kind, tag, search)
PATCH  /api/assets/{asset}                   rename/move/tag
POST   /api/assets/{asset}/replace           new file, same uuid
DELETE /api/assets/{asset}                   soft delete; 409 + usage list if in use
GET    /api/assets/{asset}/usages

CRUD   /api/projects/{project}/entities
CRUD   /api/projects/{project}/variable-sets, /variables
CRUD   /api/projects/{project}/templates
CRUD   /api/nodes/{node}/comments            (phase 8)

GET    /api/projects/{project}/search?q=&type=&speaker=&uses_variable=
POST   /api/projects/{project}/export        queued job → downloadable JSON/CSV
POST   /api/projects/{project}/export/bundle .dgpack zip: project.json + all assets (doc 09)
POST   /api/nodes/{node}/export/bundle       subtree bundle (doc 09)
GET    /api/exports/{id}                     job status + signed download URL
POST   /api/projects/import/bundle           .dgpack → new project / restore (doc 09)
POST   /api/graphs/{graph}/import/bundle     subtree import into a graph (doc 09)
GET    /api/imports/{id}                     job status + dry-run/remap report
POST   /api/projects/{project}/import        Twine/articy JSON (phase 8)
POST   /api/projects/{project}/snapshots     create; POST …/restore
GET    /api/projects/{project}/stats         word counts, per-speaker reports
```

Conventions: route-model binding by **uuid**; `ProjectPolicy` on every route;
FormRequest validation everywhere; node `data` payloads validated against a per-type
schema (`NodeTypeRegistry` — defined once in TS, mirrored as JSON Schema files that
PHP validates with `opis/json-schema`, so client and server can't drift).

**Autosave:** command batches flush 1–2 s after the last mutation →
`PUT /graphs/{graph}/content` (upserts + deletes with client-generated uuids);
optimistic lock via a graph `version` stamp; on 409, refetch + toast + merge-or-fork
prompt. Realtime sync arrives in phase 8; the payload shape is already
command-based so the upgrade path is clean.

## 4. Frontend architecture (React + TypeScript)

Packages: `react`, `react-dom`, `@xyflow/react`, `zustand` (+`zundo` or custom
command stack), `@vitejs/plugin-react`. Optional as needed: `elkjs`/`@dagrejs/dagre`
(auto-layout), `cmdk` (command palette), `react-dropzone` (uploads),
CodeMirror 6 (script fields). No router library — the editor manages
`/p/{project}/g/{graph}` with the History API directly (one route shape).

```
resources/js/graph/
  main.tsx                    mount into #graph-editor-root
  api/client.ts               typed fetch client (all endpoints in §3)
  model/types.ts              Node, Connection, Graph, Entity, Variable, Asset…
  model/nodeTypes/            ★ NodeTypeRegistry — one folder per node type:
    dialogueFragment/          schema.ts (zod → JSON Schema), Component.tsx,
    container/  hub/  …        inspector.ts (field defs), palette.ts (icon/label)
  store/
    projectStore.ts            Zustand: catalogs, open graph, selection
    commands.ts                command objects + undo stacks (per graph)
    autosave.ts                dirty tracking, debounce, conflict handling
  canvas/
    GraphCanvas.tsx            <ReactFlow> wiring: nodeTypes/edgeTypes maps,
                               controlled state, onConnect/isValidConnection,
                               drop handlers (palette + files), helper lines
    edges/ChoiceEdge.tsx       labeled/conditional edges
    theme.css                  --xy-* variables mapped to Tabler tokens (doc 08)
  shell/
    EditorShell.tsx            layout: toolbar, breadcrumbs, dockable panels
    Breadcrumbs.tsx  GraphTree.tsx
  panels/
    inspector/                 driven by nodeTypes + templates
    assets/  entities/  variables/  search/  problems/
  play/                        simulation engine + play panel (doc 06)
  script/                      expression parser/interpreter (doc 06 §5)
```

- **NodeTypeRegistry is the extension point.** Adding "Quest node" = one folder:
  zod data schema, React component, inspector field list, palette entry. The
  registry feeds React Flow's `nodeTypes` prop, the Inspector, the palette, server
  validation (exported JSON Schema), and the exporter.
- **Store ↔ React Flow bridge:** selectors produce `Node[]`/`Edge[]` (memoized;
  node `data` objects kept referentially stable so `memo`'d components skip
  re-render); `onNodesChange`/`onEdgesChange` translate deltas into commands.
  Position changes only commit a command on drag *end* — intermediate positions are
  ephemeral canvas state, keeping undo and autosave clean.
- **Blade shell** provides: CSRF token, user, project uuid, theme attribute. Vite
  entries become `resources/js/graph/main.tsx` + `resources/css/app.css`.

## 5. Non-functional targets

- Graph open < 500 ms for 500-node graphs (payload ≈ a few hundred KB).
- 60 fps pan/zoom at 300 visible nodes: `memo` node components,
  `onlyRenderVisibleElements` when node count > threshold, light node chrome
  (flat Tabler cards), semantic zoom (doc 06 §1) to cut DOM depth when zoomed out.
- Autosave round-trip invisible; no blocking saves; offline banner + retry queue.
- All destructive ops soft-delete or confirm; snapshot before restore/import.
- Tests: Pest feature tests per endpoint; Vitest for store/commands, expression
  parser, traversal engine, exporter; Playwright happy-path
  (create → write chain → nest → upload asset → play → reload).
- Accessibility: React Flow keyboard nav enabled; all panels tab-navigable;
  color-blind-safe default node palette.
