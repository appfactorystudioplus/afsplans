# 01 — Web App Audit

Audit of `apps/flow` as the source for the desktop port. Focus on **what to
port**, **what to skip**, and **adapter boundaries**.

## Repository layout (relevant paths)

```
flow/
├── app/Http/Controllers/Api/ProjectController.php   # REST CRUD
├── app/Models/FlowProject.php
├── database/migrations/..._create_flow_projects_table.php
├── routes/api.php                                     # /api/flow/projects
└── resources/js/
    ├── app.ts                                         # Web bootstrap — replace on desktop
    ├── flow/                                          # ★ Port (adapt)
    │   ├── bootstrap.ts                               # Appfactory3 subviews — replace
    │   ├── dashboard.ts                               # Project list UI
    │   ├── projectEdit.ts                             # Rename form
    │   ├── routing.ts                                 # Hash routes
    │   ├── canvasTheme.ts                             # Preset themes
    │   └── navbar.ts                                  # Theme toggle — simplify
    ├── views/DrawView.tsx                             # ★ Port (keep)
    ├── storage/
    │   ├── projectStore.ts                            # ★ Replace with IPC adapter
    │   └── types.ts                                   # ★ Port types as-is
    ├── settings/                                      # ✗ Not ported (PM teams)
    ├── checkpoint/                                    # ✗ Not ported
    ├── checkpointHelper/                              # ✗ Not ported
    └── app/NotesApp.ts                                # ✗ Dead code — ignore
```

## User-facing views (web)

| View | Hash | Template | Port? |
|------|------|----------|-------|
| Dashboard | `#/dashboard` | `html/views/dashboard.html` | ✅ Phase 2 |
| Draw | `#/draw/{referenceId}` | `html/views/draw.html` | ✅ Phase 3 |
| Project edit | `#/edit/{referenceId}` | `html/views/project-edit.html` | ✅ Phase 4 |
| Settings | `#/settings` | `html/views/settings.html` | ❌ Defer |

## API surface (web)

| Method | Path | Desktop equivalent |
|--------|------|-------------------|
| GET | `/api/flow/projects` | `flow:listProjects` IPC |
| POST | `/api/flow/projects` | `flow:createProject` IPC |
| GET | `/api/flow/projects/{ref}` | `flow:getProject` IPC |
| PUT | `/api/flow/projects/{ref}` | `flow:saveProject` IPC |
| DELETE | `/api/flow/projects/{ref}` | `flow:deleteProject` IPC |

`projectStore.ts` is the **only** module that calls these endpoints for the draw
workflow. Replacing it (or adding `projectStore.desktop.ts`) is the main
integration point.

## Data model (web)

From migration + `storage/types.ts`:

```typescript
interface FlowProjectRecord {
  id: string;              // server: proj_{uuid}
  name: string;
  reference_id: string;    // ref_{uuid} — primary client key
  scene: ProjectScene | null;
  revision: number;
  created_at?: string;
  updated_at?: string;
}

interface ProjectScene {
  elements?: unknown[] | null;
  appState?: Record<string, unknown> | null;
  files?: Record<string, unknown> | null;
}
```

Desktop omits web-only member scoping fields. Keep `revision` as a save counter.

## Draw editor (`DrawView.tsx`)

- Wraps `@excalidraw/excalidraw` 0.18
- Loads project via `getProject(referenceId)`
- Saves via `saveProject(referenceId, { name, scene })`
- Tracks `activeApi` / `activeProject` module singletons
- Subscribes to canvas preset changes from `canvasTheme.ts`
- Uses React 18 `createRoot` per mount point (`flow-draw-mount`)

**Port strategy:** Copy component + theme module; change imports to desktop
`projectStore`. No Excalidraw fork required for MVP.

## Dependencies to add (afsdesktop5)

| Package | Web version | Notes |
|---------|-------------|-------|
| `@excalidraw/excalidraw` | ^0.18.0 | CSS import required |
| `react` / `react-dom` | ^18.x | Already in afsdesktop5 for Dream Graph |

Flow web uses **esbuild JSX** without `@vitejs/plugin-react`; desktop webpack
already handles TSX for Dream Graph — reuse same loader config.

## Infrastructure to **not** port

| Module | Lines (approx) | Reason |
|--------|----------------|--------|
| `checkpointHelper/static/appfactory3.ts` | ~3,800 | Replaced by Coordinator-style shell |
| `checkpoint/*` | ~2,000+ | Not ported |
| `settings/*` | ~800 | PM API deferred |
| `app/NotesApp.ts` | broken import | Unused |

## Complexity verdict

**Domain: small** (CRUD + JSON blob). **Integration: medium** (Excalidraw in
Electron, CSS size, save on unmount). **Overall: ~40% of Dream Graph effort,
~25% of Coordinator effort** for offline MVP.
