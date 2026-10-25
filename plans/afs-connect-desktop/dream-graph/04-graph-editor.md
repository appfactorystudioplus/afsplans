# 04 — Graph Editor Port Scope

Story graph subsystem — what to port, in what order, and acceptance criteria.

## Dependencies (renderer)

From `apps/dream_graph/package.json` (minimum):

- `@xyflow/react`
- `react`, `react-dom`
- `zustand`
- Tabler icons (or substitute with existing app icons initially)

## Module copy map

| Web module | Desktop action |
|------------|----------------|
| `graph/shell/EditorShell.tsx` | Use; strip SettingsView / web auth |
| `graph/shell/ProjectsView.tsx` | Adapt — list from IPC not API |
| `graph/store/projectStore.ts` | Use; inject adapter for load/save |
| `graph/canvas/GraphCanvas.tsx` | Copy as-is |
| `graph/model/nodeTypes/*` | Copy all 13 types + registry + pins |
| `graph/panels/*` | Copy palette, inspector, graph tree |
| `graph/api/types.ts` | Copy |
| `graph/api/client.ts` | Replace with adapter interface |
| `graph/playMode/*` | Phase 4 |
| `graph/scripting/*` | Phase 4 |
| `checkpoint/*` | **Do not port** — auth is a separate studio plan |

## Node types — phased enablement

| Phase | Types | Rationale |
|-------|-------|-----------|
| 2a | `fragment`, `hub`, `group`, `note`, `comment` | Core writing |
| 2b | `condition`, `instruction` | Branching + variables |
| 2c | `container`, `entry`, `exit` | Nested graphs |
| 2d | `tilemap_ref` | Requires tilemap subsystem (Phase 5) |
| 2e | `image` | Requires asset pipeline (Phase 3) |

## EditorShell layout (preserve)

```
┌────────────┬──────────────────────────┬────────────┐
│ Left 240px │ GraphCanvas (React Flow)   │ Inspector  │
│ breadcrumb │                          │ or Play 320px│
│ actions    │                          │              │
│ Palette /  │                          │              │
│ GraphTree  │                          │              │
└────────────┴──────────────────────────┴────────────┘
```

Collapse sidebar to 48px — port CSS from `graph-editor.scss` scoped under
`#dreamGraph-root`.

## Autosave behavior

Match web:

- `isDirty` flag in `projectStore`
- 5 second debounce after last edit
- Manual Save button forces immediate flush
- Status indicator: "Saved" / "Saving…" / error

IPC call: `dream-graph:save-graph-content` with full node/edge payload.

## Viewport persistence

On pan/zoom end → update graph JSON viewport field (debounced separately, 300ms).

## Nested graphs

Port `GraphService` behaviors:

- Create child graph on first container expansion
- Seed Entry/Exit nodes
- Breadcrumb trail from root
- Max depth 32 — enforce in UI

## Features explicitly deferred

| Feature | Reason |
|---------|--------|
| Graph undo/redo | Unwired on web; add desktop-only if prioritized |
| Auto-layout | Not on web |
| Global node search | Not on web |
| Presentation mode polish | Phase 4 |
| PNG/SVG export | Client-side already — enable Phase 6 |

## Acceptance tests (Phase 2 complete)

1. Create new project → empty root graph with palette visible.
2. Add fragment nodes, connect with edges, drag, nudge with arrows.
3. Edit text in inspector; persists after app restart.
4. Create container, enter nested graph, return via breadcrumb.
5. Hub node with 3 branches connects correctly (pin indices).
6. Condition node shows true/false handles; expression editable.
7. No network requests in DevTools during editing.

## Risk: React Flow + Electron

- Verify `@xyflow/react` works in webpack bundle (no SSR assumptions).
- Pointer events inside `#main-content` — same as Notes Pro React mount.
- Minimap and d3-zoom — test on Windows HiDPI (`RENDER_SCALE` not needed for vector UI).
