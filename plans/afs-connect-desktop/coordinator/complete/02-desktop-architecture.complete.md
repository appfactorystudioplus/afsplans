# 02-desktop-architecture — Completion

**Completed:** 2026-07-31

**Status:** Complete. Nav placement, module layout, view shell, internal routing, IPC
surface, adapter swap, webpack handling, auth stub, and manager lifecycle all shipped.
Delivered in plan phases 0 and 3. The plan's "what not to do" list was honoured — the web
`appfactory3.ts` / jQuery stack was not ported.

## Summary

### Nav placement (phase 0)

The plan marks nav order mandatory, and it holds: **Studio → Coordinator → Notes → PDF →
Dream Graph**. Sidebar entry 📋 **Coordinator** under **Apps**, above Notes, with
`sidebar.coordinator` i18n across en/es/de/zh and a search entry (matching "Coordinator"
and "kanban") that navigates to the view.

### Module layout

`src/main/coordinator/` for the main process; `src/renderer/components/coordinator/` for
the renderer, split into `src/` (ported web modules and desktop adapters), `shell/` (view
HTML, events, router), and `styles/` (ten scoped stylesheets).

### Styling decision — Tailwind vs scoped CSS

The phase 0 spike resolved in favour of **scoped CSS under `#coordinator-root`**, with no
Tailwind added to the afsdesktop5 webpack build. afsdesktop5 already runs Bootstrap plus
Tabler plus per-app scoped CSS, and adding Tailwind 4 postcss for one app was judged not
worth the build complexity. Web Tailwind utilities were converted incrementally as modules
were copied from phase 3 onward.

### Internal routing (phase 3)

A lightweight shell replaced the web stack:

| Module | Role |
|--------|------|
| `bootstrap.ts` | Loads the workspace, injects the shell, wires events |
| `shell/shellHtml.ts` | Sidebar + main content markup |
| `shell/router.ts` | Hash router |
| `shell/projects.ts` | Project select, switch confirm, create |

Seven routes: `#/dashboard`, `#/kanban`, `#/task`, `#/table2`, `#/gantt`, `#/budget2`,
`#/settings`. They shipped as stubs in phase 3 — each showing its section title plus task
stats from `projectData.stats` — and were filled in one at a time across phases 4–10; see
[04-pm-views-and-modules.complete.md](04-pm-views-and-modules.complete.md).

### Project switcher

A sidebar `<select>` bound to `getProjects()` / `getCurrentProjectId()`. Switching confirms
with the user, calls `flushPersist()` and then `setCurrentProject()`, which persists to
`workspace.json` — so a debounced `.coordproj` write can never be lost to a project
switch.

### Adapter swap

`src/pm-api-desktop.ts` replaces the web `pm-api.ts`, turning every `fetch('/api/pm/*')`
into a `coordinatorIpc` call. This is what allowed the PM modules to be copied largely
intact.

### Auth stub (offline v1)

`src/state.ts` carries a slim workspace state with no Checkpoint dependency and an empty
`users[]` roster until the sync plan lands. `src/authz.ts` is a local admin stub. The
plan's `checkpointStub.ts` was not needed.

### Manager lifecycle

`CoordinatorManager` delegates to `bootstrapCoordinator()`, and `destroyUI()` is called
from `renderer.ts` when leaving the Coordinator view so pending saves are flushed on exit.

## Files changed

- `src/renderer/index.html` — sidebar nav entry, in mandated order.
- `src/renderer/views.ts` — `coordinator` view entry; exports `renderTemplate` for shell
  i18n substitution.
- `src/renderer/html/coordinator.html` — mount point `#coordinator-root`.
- `src/renderer/renderer.ts` — manager instantiation and `destroyUI()` on view exit.
- `src/renderer/components/sidebar.ts`, `search.ts` — nav label and search entry.
- `src/renderer/locales.json` — nav labels, `viewStub`, `switchConfirm`, `localWorkspace`,
  `projectLabel`, 4 languages.
- `src/renderer/components/coordinator/index.ts` — `CoordinatorManager`.
- `src/renderer/components/coordinator/bootstrap.ts` — shell mount, event wiring.
- `src/renderer/components/coordinator/shell/shellHtml.ts`, `router.ts`, `projects.ts`.
- `src/renderer/components/coordinator/ipc.ts` — typed invoke wrappers.
- `src/renderer/components/coordinator/src/pm-api-desktop.ts` — IPC adapter.
- `src/renderer/components/coordinator/src/state.ts`, `authz.ts` — offline state and admin
  stub.
- `src/renderer/components/coordinator/workspaceBridge.ts` — public exports for the manager.
- `src/renderer/components/coordinator/styles/shell.css`, `tokens.css`, `brand.css`.
- `src/main/coordinator/index.ts`, `paths.ts` — IPC registration and `COORDINATOR_DIR`.
- `src/main/index.ts` — `get-app-dirs` gains `coordinatorDir`.

## Verification steps

1. `npm run build`, then `npm start`.
2. Confirm sidebar order: Studio → **Coordinator** → Notes → PDF → Dream Graph.
3. Click **Coordinator**; **Open workspace folder** opens
   `~/Documents/afsfiles/coordinator/`.
4. Search "Coordinator" or "kanban" — the view navigates correctly.
5. Click each sidebar link — the hash changes and the content area swaps.
6. Switch project, restart the app — the current project is restored.
7. Open Notes, PDF, and Dream Graph — no regressions.

```
npm run build  ✅ success (2026-07-31)
```

## Follow-up work

- **AFS Connect hub route** (`#/afs-connect`) — deferred to the cloud-sync plan.
- **Full `appfactory3.ts` port** (~3800 lines of jQuery) — deliberately never done; the
  lightweight shell replaces it.
- **`checkpointStub.ts`** — unnecessary until the auth/cloud-sync plan.
