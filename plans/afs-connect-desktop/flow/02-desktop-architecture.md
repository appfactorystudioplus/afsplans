# 02 — Desktop Architecture

How Flow lives inside **Appfactory Studio** (`apps/afsdesktop5`).

## Reference patterns

| Existing port | Reuse for Flow |
|---------------|----------------|
| `main/notesPro/store.ts` | Atomic JSON read/write, project folders |
| `components/coordinator/shell/router.ts` | Hash routing between dashboard / draw / edit |
| `components/dreamGraph/index.ts` | React mount inside Electron view |
| `components/coordinator/styles/tokens.css` | Brand orange tokens |

Flow combines **Coordinator's lightweight shell** with **Dream Graph's React
mount** — but with far fewer modules.

## Proposed module layout

```
src/
  main/
    flow/
      index.ts          # ipcMain.handle registrations
      store.ts          # FlowStore — list/create/get/save/delete
      paths.ts          # flowDir under get-app-dirs
  renderer/
    html/flow.html      # <div id="flow-root"></div>
    components/flow/
      index.ts          # FlowManager — initUI / destroyUI
      ipc.ts            # typed invoke wrappers
      bootstrap.ts      # inject shell, init router
      workspaceBridge.ts
      shell/
        shellHtml.ts    # sidebar: Dashboard | (draw is full content)
        router.ts       # dashboard | draw/:ref | edit/:ref
        dashboardViewHtml.ts
        drawViewHtml.ts
        projectEditViewHtml.ts
      storage/
        projectStore.ts # same function signatures as web; calls ipc
        types.ts        # copied from web
      views/
        DrawView.tsx    # copied from web; import path fixes
      canvasTheme.ts    # copied from web
      styles/
        tokens.css      # --flow-* brand tokens
        shell.css
        dashboard.css
        draw.css
    views.ts            # flow: renderTemplate(flowHtml)
    renderer.ts         # import flow styles; FlowManager on navigate
```

## View shell

Minimal HTML — interior owned by Flow bootstrap (like Coordinator):

```html
<div id="flow-root" class="flow-shell"></div>
```

Styles scoped under `#flow-root`. Do **not** load web Blade CDN jQuery/Bootstrap
globally; afsdesktop5 already has Tabler/Bootstrap for the app shell.

## Navigation (afsdesktop5 sidebar)

Add under **Apps** (suggested position: after Dream Graph):

```html
<div class="nav-item" data-view="flow">
  <span class="nav-icon">✏️</span><span class="nav-label">Flow</span>
</div>
```

Wire `FlowManager.initUI()` in `renderer.ts` when `viewName === 'flow'`.

## Internal routing

Coordinator-style hash routes inside `#flow-root`:

| Hash | View |
|------|------|
| `#/dashboard` | Project list |
| `#/draw/{referenceId}` | Excalidraw editor |
| `#/edit/{referenceId}` | Rename project |

No `#/settings` in offline v1.

## Storage adapter

Web `projectStore.ts` signatures become IPC-backed:

```typescript
// renderer/components/flow/storage/projectStore.ts
export async function listProjects(): Promise<FlowProjectRecord[]>;
export async function createProject(name: string): Promise<FlowProjectRecord>;
export async function getProject(referenceId: string): Promise<FlowProjectRecord | null>;
export async function saveProject(referenceId: string, payload: { name?: string; scene: ProjectScene }): Promise<FlowProjectRecord>;
export async function updateProjectName(referenceId: string, name: string): Promise<FlowProjectRecord>;
export async function deleteProject(referenceId: string): Promise<void>;
```

Main process implements the same shapes; renderer never touches filesystem
directly.

## Draw view lifecycle

```
navigate #/draw/{ref}
  → router renders drawViewHtml (toolbar + #flow-draw-mount)
  → renderDrawEditor(ref, 'flow-draw-mount', callbacks)
  → Excalidraw mounts

leave draw view
  → optional autosave prompt OR silent save (decide in Phase 3)
  → unmountDrawEditor('flow-draw-mount')
```

Match web `bootstrap.ts` `onExit` behavior: always unmount React root to avoid
leaks when switching projects.

## Webpack notes

- Add `@excalidraw/excalidraw` to `afsdesktop5/package.json`
- Import `@excalidraw/excalidraw/index.css` once in `DrawView.tsx` or `renderer.ts`
- Expect bundle size increase (~2–4 MB) — note in Phase 0 commit
- Do **not** change webpack `publicPath` (PDF port lesson)

## IPC channel naming

Prefix `flow:` for consistency:

| Channel | Args | Returns |
|---------|------|---------|
| `flow:listProjects` | — | `FlowProjectRecord[]` |
| `flow:createProject` | `{ name }` | `FlowProjectRecord` |
| `flow:getProject` | `{ referenceId }` | `FlowProjectRecord \| null` |
| `flow:saveProject` | `{ referenceId, name?, scene }` | `FlowProjectRecord` |
| `flow:deleteProject` | `{ referenceId }` | `{ ok: true }` |
| `flow:openWorkspaceFolder` | — | `{ ok, path? }` |

Register in `main/index.ts` alongside coordinator / dreamGraph handlers.

## Destroy / cleanup

`FlowManager.destroyUI()` must:

1. `unmountDrawEditor` if draw view active
2. Flush pending save (if any)
3. Remove `#main-content.flow-view-active` class

Same discipline as `CoordinatorManager.destroyUI()`.
