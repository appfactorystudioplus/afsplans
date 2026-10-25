# 04-graph-editor — Completion

**Completed:** 2026-07-31

**Status:** Complete. The React Flow canvas, all node types, nesting, autosave, viewport
persistence, catalogs and assets, and local play mode all shipped. Delivered across plan
phases 2, 3, and 4. The plan's explicitly deferred features stayed deferred.

## Summary

### Dependencies

`react`, `react-dom`, `@xyflow/react`, `zustand`, `bootstrap`, `@tabler/icons-webfont` —
the renderer set the plan specifies. Bootstrap and Tabler rather than Tailwind, so the
editor matches the Studio shell.

### Module copy map (phase 2)

Graph modules were copied from web into
`src/renderer/components/dreamGraph/graph/` and adapted only where they touched the
network:

| Area | Location |
|------|----------|
| Canvas | `canvas/GraphCanvas.tsx` + React Flow theme |
| Store | `store/projectStore.ts` — Zustand project + graph state |
| API adapter | `api/client.ts` — IPC in place of web REST |
| Scripting | `scripting/` — parser + interpreter, copied intact |
| Panels | `panels/Palette.tsx`, `Inspector.tsx` |
| Shell | `shell/DesktopEditorShell.tsx`, `GraphTree.tsx`, `GraphLocation.tsx` |

### Node types

All 12 types registered. Enablement was phased exactly as the plan's table sets out:

- **2a** — fragment, hub, group, note, comment
- **2b** — condition, instruction, with a variable catalog stub on disk
- **2c** — container, entry, exit
- **Phase 3** — image, with the asset picker
- **Phase 5** — `tilemap_ref`, a stub until the tilemap subsystem landed

### Autosave and viewport persistence

Graph content autosaves on a 5s debounce, and manual save works alongside it. Viewport
camera state persists on a 300ms debounce through its own IPC channel, keeping camera
writes off the content path.

### Nested graphs (2c)

Containers open child graphs via `dream-graph:create-child-graph`, with breadcrumb
(`GraphLocation`) and graph tree (`GraphTree`) navigation back up.

### Catalogs and assets (phase 3)

Sidebar **Catalogs** tab with three panels, all in
`graph/panels/`: `AssetsPanel` (import, list, delete), `EntitiesPanel`, and
`VariablesPanel`. `AssetPicker` is the modal used from nodes and `AssetThumbnail` renders
lazily from a `file://` URL, so thumbnails survive restart. `CatalogsPanel` hosts the
Assets / Entities / Vars tabs.

### Play mode (phase 4)

`graph/playMode/` holds the local simulation, per the plan's copy map:

| Module | Role |
|--------|------|
| `TraversalEngine.ts` | Copied from web; drives traversal using the existing scripting parser and interpreter |
| `graphAdapter.ts` | React Flow `nodes`/`edges` → `StoryGraph` |
| `variableInit.ts` | Seeds a session from Catalogs → Vars defaults |
| `PlayModePanel.tsx` | Docked playback panel, Bootstrap-styled |
| `PresentationMode.tsx` | Full-screen overlay via `createPortal` |
| `VariableInspector.tsx` | Read-only live variable state |
| `nodeDisplay.ts` | Human-readable node labels |

**Play** and **Present** sit in the left sidebar of `DesktopEditorShell`. The panel
supports start/stop, hub choices with condition gating (blocked choices carry a badge),
auto-traversal of condition and instruction nodes, a coverage bar and step count, and
canvas highlighting of the current node. Parse and eval failures return a
`TraversalResult.error` and surface as an inline warning rather than crashing.
Presentation mode adds ↑/↓ choice movement, Enter to select, and Esc to exit.

## Files changed

- `package.json` — `@xyflow/react`, `zustand`, `bootstrap`, `@tabler/icons-webfont`.
- `src/renderer/components/dreamGraph/graph/canvas/`, `store/`, `api/`, `model/`,
  `scripting/` — copied and adapted graph modules.
- `src/renderer/components/dreamGraph/graph/panels/` — `Palette.tsx`, `Inspector.tsx`,
  `CatalogsPanel.tsx`, `AssetsPanel.tsx`, `AssetPicker.tsx`, `AssetThumbnail.tsx`,
  `EntitiesPanel.tsx`, `VariablesPanel.tsx`.
- `src/renderer/components/dreamGraph/graph/playMode/` — eight modules, above.
- `src/renderer/components/dreamGraph/graph/shell/DesktopEditorShell.tsx` — sidebar tabs,
  Play and Present controls.
- `src/renderer/components/dreamGraph/graph/util/assetUrl.ts` — `file://` URL cache.
- `src/renderer/components/dreamGraph/styles/dream-graph-shell.css` —
  `.dg-presentation-mode` and related classes.

## Verification steps

1. Create or open a project; drag fragment, hub, condition, instruction, and container
   nodes from the palette, connect them, and edit properties in the Inspector.
2. Double-click a container — the nested graph opens; navigate back via breadcrumb.
3. Wait 5s or click **Save**, restart the app — the graph is intact.
4. DevTools Network tab shows no HTTP requests during editing.
5. Import a PNG under **Catalogs → Assets**, add an Image node, pick the asset, restart —
   the thumbnail still renders.
6. Under **Vars**, create a set `stats` with `gold` (int, default 0). Build
   fragment → hub with two branches, a condition (`gold >= 10`) on one and an instruction
   (`gold += 5`) on the other. Click **Play → Start Playback**; blocked branches and
   variable changes show in the inspector. **Present** opens full screen; Esc exits.

```
npm run build  ✅ success (2026-07-31)
```

## Deviations from plan

- **Catalog panels are in `graph/panels/`, not `graph/panels/catalogs/`.** The phase-3
  notes recorded the nested path; the shipped tree is flat.

## Follow-up work

Deferred deliberately, matching the plan's "features explicitly deferred" section:

- Stats panel, localization dialog, and settings view — web-only extras.
- Server playthrough save/load and playthrough export to cloud.
- Step-back replay from history — a stub on web too.
- Tilemap catalog integration in presentation; a `tilemap_ref` shows its label and uuid.
