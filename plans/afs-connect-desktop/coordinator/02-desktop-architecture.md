# 02 — Desktop Architecture

How Coordinator lives inside **Appfactory Studio** (`apps/afsdesktop5`).

## Reference patterns

| App | Pattern | Coordinator fit |
|-----|---------|-------------------|
| **Notes Pro** | Main store + IPC + manager + scoped CSS | ✅ Primary reference for persistence |
| **Dream Graph** | Large React subsystem + main store | ❌ Coordinator is vanilla TS, not React |
| **PDF** | Renderer-only, shared dialogs | ❌ Coordinator needs structured local store |

Coordinator follows **Notes Pro** for main-process I/O and **Dream Graph** for phase
discipline (incremental copy, scoped styles, build-after-each-phase).

## Nav placement (mandatory)

Insert in `src/renderer/index.html` under **Apps**, **between Studio and Notes**:

```html
<div class="section-title">Apps</div>
<div class="nav-item" data-view="studio">…</div>
<div class="nav-item" data-view="coordinator">   <!-- NEW -->
    <i class="ti ti-layout-kanban"></i>
    <span data-i18n="sidebar.coordinator">Coordinator</span>
</div>
<div class="nav-item" data-view="notesPro">…</div>
<div class="nav-item" data-view="pdf">…</div>
<div class="nav-item" data-view="dreamGraph">…</div>
```

### 7-file wiring chain

| # | File | Change |
|---|------|--------|
| 1 | `src/renderer/index.html` | Nav item (above Notes) |
| 2 | `src/renderer/views.ts` | Import `html/coordinator.html`, add `coordinator` key |
| 3 | `src/renderer/html/coordinator.html` | Shell container |
| 4 | `src/renderer/components/coordinator/index.ts` | `CoordinatorManager.initUI()` |
| 5 | `src/renderer/renderer.ts` | Import manager, `navigate()` branch |
| 6 | `src/renderer/components/sidebar.ts` | `navMap`: `'coordinator': 'sidebar.coordinator'` |
| 7 | `src/renderer/locales.json` | `sidebar.coordinator`, `coordinator.title` (+ es/de/zh) |
| 8 | `src/renderer/components/search.ts` | Search item → `navigateCallback('coordinator')` |

**Do not modify** unrelated views (`pdfEditor`, legacy `notes`).

## Proposed module layout

```
src/
  main/
    coordinator/
      index.ts              # registerCoordinatorIpc()
      store.ts              # CoordinatorStore — workspace read/write
      paths.ts              # coordinatorDir under get-app-dirs
      normalize.ts          # Payload validation / healing (optional)
  renderer/
    html/coordinator.html
    components/coordinator/
      index.ts              # CoordinatorManager — initUI(), mount internal router
      ipc.ts                # coordinatorIpc typed invoke wrappers
      bootstrap.ts          # Adapted main.ts — no Checkpoint redirect
      views.ts              # Internal hash routes (copied from web)
      html/                 # View templates (copied from web)
      src/                  # PM modules (copied from web, adapted)
      styles/               # Scoped SCSS/CSS
      checkpointStub.ts     # Local auth stub (offline v1)
    views.ts                # coordinator: renderTemplate(coordinatorHtml)
    renderer.ts             # CoordinatorManager on navigate
```

Register IPC in `src/main/index.ts` inside `app.whenReady()`:

```typescript
import { registerCoordinatorIpc } from './coordinator';
// ...
registerCoordinatorIpc();
```

Extend `get-app-dirs` (or equivalent) with `coordinatorDir`:

```
~/Documents/afsfiles/coordinator/
  workspace.json              # envelope: currentProject, users, projects, teams
  projects/
    {id}.coordproj            # projectDataToPersistPayload per project
  backups/                    # optional Phase 11
```

## View shell HTML

Minimal outer container — internal Appfactory3 router owns the interior:

```html
<div id="coordinator-root" class="coord-shell">
  <!-- Internal sidebar + content injected by bootstrap.ts -->
</div>
```

All coordinator CSS prefixed with `#coordinator-root` to avoid colliding with
afsdesktop5 shell styles (same rule as `#dreamGraph-root`, `#notesPro-root`).

## Internal routing strategy

Web Coordinator uses **Appfactory3 hash router** (`#/kanban`, `#/table2`, …).

**Recommendation:** Keep Appfactory3 inside `#coordinator-root` rather than
rebuilding navigation in React. Steps:

1. Copy `checkpointHelper/static/appfactory3.ts` and `views.ts` into coordinator module.
2. Replace Blade/Vite asset loading with webpack `import` of HTML templates as strings.
3. On `CoordinatorManager.initUI()`:
   - Mount shell HTML into `#coordinator-root`
   - Call adapted `bootstrap.ts` (load workspace via IPC, register routes)
   - Navigate to `#/dashboard` or last-used hash (store in `localStorage`)

**Alternative (not recommended for v1):** Reimplement routes as React Router —
high cost, no user benefit for offline MVP.

## IPC surface (offline v1)

Prefix: `coordinator:*`

| Handler | Args | Returns |
|---------|------|---------|
| `coordinator:load-workspace` | — | `PmWorkspaceResponse` |
| `coordinator:save-workspace` | workspace metadata patch | `{ ok, error? }` |
| `coordinator:load-project` | `projectId: number` | `ProjectData` payload |
| `coordinator:save-project` | `projectId`, payload | `{ ok, error?, version? }` |
| `coordinator:create-project` | `{ name, description? }` | `{ ok, project }` |
| `coordinator:delete-project` | `projectId` | `{ ok, error? }` |
| `coordinator:open-workspace-folder` | — | Opens dir in OS file manager |
| `coordinator:export-project-json` | `projectId`, save path | `{ ok, path? }` |
| `coordinator:import-project-json` | open path | `{ ok, projectId? }` |

Debounced save: renderer calls `save-project` after `persistProject()` debounce
(same ~1.5s pattern as web); main process writes atomically (temp file + rename).

## Adapter swap (replace pm-api.ts)

Web:

```typescript
// pm-api.ts
fetch('/api/pm/workspace', { headers: pmHeaders() })
```

Desktop:

```typescript
// coordinator/src/pm-api-desktop.ts
export async function fetchWorkspace() {
  return coordinatorIpc.loadWorkspace();
}
export async function saveProjectPayload(id, payload) {
  return coordinatorIpc.saveProject(id, payload);
}
```

Replace imports in copied `state.ts` — single seam, no `fetch()` in offline v1.

## Webpack considerations

Coordinator web uses Vite + Tailwind 4. afsdesktop5 uses webpack + existing Bootstrap/Tabler.

| Concern | Approach |
|---------|----------|
| Tailwind | Convert critical utility classes to scoped SCSS **or** add Tailwind as postcss step (Phase 0 spike) |
| HTML templates | Import as raw strings (html-loader or template literals) |
| Chart.js | Load from CDN in coordinator bootstrap **or** npm `chart.js` |
| SortableJS | Add to afsdesktop5 `package.json` |
| Bundle size | Lazy-load budget2 submodule on first `#/budget2` navigation (Phase 9) |

**Critical constraint:** Do not change webpack `publicPath`. Match pdf.js / dream-graph worker patterns.

## Auth stub (offline v1)

Web `main.ts` blocks until Checkpoint session exists. Desktop bootstrap:

```typescript
// checkpointStub.ts
export const app = {
  window: {
    user: { id: 1, name: 'Local User' },
    member: { user_id: 1, reference_num: 'local' },
  },
  isAuthenticated: true,
};
```

Real Checkpoint integration: [../account-and-cloud-sync/03-coordinator-pm-sync.md](../account-and-cloud-sync/03-coordinator-pm-sync.md).

## Manager lifecycle

```typescript
export class CoordinatorManager {
  private mounted = false;

  initUI() {
    const root = document.getElementById('coordinator-root');
    if (!root) return;
    if (!this.mounted) {
      void bootstrapCoordinator(root);
      this.mounted = true;
    } else {
      // Re-navigate to current hash on re-entry
      window.dispatchEvent(new HashChangeEvent('hashchange'));
    }
  }
}
```

Teardown: on navigate away from coordinator, pause debounced saves (flush pending).

## Build verification rule

After **every phase**: `npm run build` must succeed. Note bundle size delta in
completion doc. Old apps (Studio, Notes, PDF, Dream Graph) must remain functional.

## What not to do

- Do not mount Checkpoint login redirect in offline v1
- Do not add `#/afs-connect` route until cloud sync plan
- Do not copy Laravel PHP into main process
- Do not use `fetch('/api/...')` in renderer for PM data
- Do not place Coordinator nav under Integrations (user requirement: Apps, above Notes)
