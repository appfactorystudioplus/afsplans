# 02-desktop-architecture — Completion

**Completed:** 2026-07-31

**Status:** Complete. Module layout, view shell, navigation, webpack handling, storage
adapter pattern, renderer bootstrap, and IPC channel naming all shipped as designed.
Delivered mainly in plan phase 0, with the editor shell arriving in phase 2.

## Summary

### Navigation and view wiring (phase 0)

Dream Graph registered as a Studio view: `dreamGraph` entry in `views.ts`, template at
`html/dreamGraph.html`, `DreamGraphManager` in `components/dreamGraph.ts`, instantiated
from `renderer.ts` on view switch. Sidebar entry 🕸️ **Dream Graph** under **Apps**, below
PDF, with en/es/de/zh i18n for title, placeholder, hint, and openFolder, plus a search
index entry. `get-app-dirs` gained `dreamGraphDir`.

### Module layout

Built to the proposed layout. Main process work lives in `src/main/dreamGraph/`; the
renderer side is `src/renderer/components/dreamGraph/` with `bootstrap.tsx`, `ipc.ts`,
`styles/`, and the two feature trees `graph/` and `tilemap/`. `graph/` is further split
into `api`, `canvas`, `model`, `panels`, `playMode`, `scripting`, `shell`, `store`, and
`util`.

### Renderer bootstrap (no web auth)

`bootstrap.tsx` mounts the React editor shell under `#dreamGraph-root`. There is no login
or session code on this path — the desktop app reads and writes local files only, which is
the plan's "no web auth" requirement.

### Storage adapter pattern

`graph/api/client.ts` keeps the web module's interface but routes every call over IPC
instead of REST. This is what let the graph modules be copied rather than rewritten; see
[04-graph-editor.complete.md](04-graph-editor.complete.md) for the copy map.

### Editor shell (phase 2)

`graph/shell/DesktopEditorShell.tsx` is the trimmed desktop shell, with the left sidebar
carrying Nodes / Tree / Catalogs tabs and, after later phases, the Play, Present, and Tile
Maps controls. `ProjectsView.tsx`, `GraphTree.tsx`, and `GraphLocation.tsx` complete the
shell.

### IPC channel naming

All channels use the `dream-graph:` prefix, per the plan's naming section. The surface
grew by phase — project CRUD in phase 1, graph and viewport in phase 2, catalogs and
assets in phase 3, tilemaps in phase 5, bundles and zips in phase 6.

### Webpack

`tsconfig.json` excludes `graph/**/__tests__/**` from the renderer build. Later phases
added `adm-zip` in place of `unzipper` specifically to avoid webpack trying to resolve
`@aws-sdk/client-s3`.

### Security (offline)

Renderer never touches `fs` directly. Binary asset import goes renderer → base64 → main
process `Buffer`, keeping file writes in the main process.

## Files changed

- `src/renderer/views.ts`, `src/renderer/html/dreamGraph.html`,
  `src/renderer/renderer.ts` — view registration and manager instantiation.
- `src/renderer/index.html` — sidebar nav entry.
- `src/renderer/components/dreamGraph.ts` — `DreamGraphManager`.
- `src/renderer/locales.json` — Dream Graph strings, 4 languages.
- `src/main/index.ts` — `registerDreamGraphIpc()` on app ready; `get-app-dirs` gains
  `dreamGraphDir`.
- `src/main/dreamGraph/index.ts` — IPC registration.
- `src/renderer/components/dreamGraph/bootstrap.tsx` — React mount.
- `src/renderer/components/dreamGraph/ipc.ts` — typed `dreamGraphIpc` wrappers.
- `src/renderer/components/dreamGraph/graph/api/client.ts` — IPC-backed adapter.
- `src/renderer/components/dreamGraph/graph/shell/` — `DesktopEditorShell.tsx`,
  `ProjectsView.tsx`, `GraphTree.tsx`, `GraphLocation.tsx`.
- `src/renderer/components/dreamGraph/styles/dream-graph-shell.css` — shell styles.
- `tsconfig.json` — test exclusion for the renderer build.

## Verification steps

1. `npm run build` then `npm start`.
2. Click **Dream Graph** in the sidebar (Apps, below PDF); the placeholder and **Open
   projects folder** button appear, and the button opens
   `~/Documents/afsfiles/dream-graph/projects/`.
3. Search "Dream Graph" — the view navigates correctly.
4. Open Notes and PDF — no regressions.

```
npm run build  ✅ success (2026-07-31)
```

## Follow-up work

- **Threading and performance** — the plan's section on moving heavy work off the main
  thread was not exercised; chunk writes are dirty-only but nothing was moved to a worker.
- **Testing strategy** — the plan describes one; `graph/**/__tests__/**` is excluded from
  the build and no desktop-side suite was added.
