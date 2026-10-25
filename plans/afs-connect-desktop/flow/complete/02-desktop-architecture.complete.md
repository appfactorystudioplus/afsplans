# 02-desktop-architecture — Completion

**Completed:** 2026-08-01

**Status:** Complete. The shell, navigation, internal router, storage-adapter swap, draw
view lifecycle, webpack handling, and destroy/cleanup all shipped as designed in
`02-desktop-architecture.md`. Delivered across plan phases 0, 2, 3, and 4.

## Summary

### View shell and navigation (phase 0)

Flow registered as a first-class Appfactory Studio view: `flow` added to `views.ts` with
`html/flow.html`, a `FlowManager` with `initUI`/`destroyUI`, and instantiation from
`renderer.ts` on view switch. Sidebar entry ✏️ **Flow** sits under **Apps** after Dream
Graph, with `sidebar.flow` / `flow.*` i18n keys across en/es/de/zh and a `search.ts` entry
that navigates to the view.

### Module layout

Built to the proposed layout — `src/renderer/components/flow/` holds `bootstrap.ts`,
`ipc.ts`, `canvasTheme.ts`, `routing.ts`, and the `shell/`, `views/`, `storage/`, and
`styles/` subdirectories.

### Internal routing (phases 2–4)

Coordinator-style hash router at `shell/router.ts` with three routes — `#/dashboard`,
`#/draw/{referenceId}`, `#/edit/{referenceId}` — mounted by `bootstrap.ts`. Each route has
an HTML builder and an events module:

| Route | HTML | Events |
|-------|------|--------|
| `#/dashboard` | `shell/dashboardViewHtml.ts` | `shell/dashboardEvents.ts` |
| `#/draw/{ref}` | `shell/drawViewHtml.ts` | `shell/drawEvents.ts` |
| `#/edit/{ref}` | `shell/projectEditViewHtml.ts` | `shell/projectEditEvents.ts` |

`shell/shellHtml.ts` supplies the brand header and the open-folder button.

### Storage adapter swap (phase 1 wiring)

The plan's central constraint — swap `projectStore` only, keep `DrawView.tsx` logic
intact — was held. `storage/projectStore.ts` reimplements the web store's interface over
IPC, `storage/types.ts` is copied from web, and `DrawView.tsx` was adapted rather than
rewritten. There is no `fetch('/api/flow')` on this path; the renderer talks only to the
main process.

### Draw view lifecycle (phase 3)

`@excalidraw/excalidraw` mounts into `#flow-draw-mount`. The router calls
`unmountDrawEditor` when leaving the draw route, so repeated open/close does not leak
React roots — the plan's explicit risk-register item, and one of phase 3's exit criteria.

### Webpack (phase 3)

Three `roughjs/bin/*` aliases added to `webpack.config.js:67-69`, matching the web Flow
vite config. No second global CSS framework was introduced; styles are scoped under
`#flow-root` across `tokens.css`, `brand.css`, `shell.css`, `dashboard.css`, and
`draw.css`.

### IPC channel naming

Nine channels on the `flow:` prefix, per the plan's naming section — `list-projects`,
`get-project`, `create-project`, `save-project`, `delete-project`,
`open-workspace-folder`, `export-project`, `import-project`.

### Destroy / cleanup (phase 4)

`FlowManager.destroyUI()` unmounts the draw editor and removes the layout classes, so
switching away from Flow mid-draw leaves no stale roots or `#main-content` state.

## Files changed

- `src/renderer/views.ts`, `src/renderer/html/flow.html`, `src/renderer/renderer.ts` —
  view registration and manager instantiation.
- `src/renderer/index.html` — sidebar nav entry.
- `src/renderer/locales.json` — `sidebar.flow` and the full `flow.*` string set, 4 languages.
- `src/renderer/components/search.ts` — Flow search entry.
- `src/renderer/components/flow/index.ts` — `FlowManager`.
- `src/renderer/components/flow/bootstrap.ts`, `routing.ts` — shell mount + router init.
- `src/renderer/components/flow/shell/` — `shellHtml.ts`, `router.ts`, and the three
  view HTML/events pairs.
- `src/renderer/components/flow/views/DrawView.tsx` — adapted from web Flow.
- `src/renderer/components/flow/canvasTheme.ts` — light / dark / charcoal presets.
- `src/renderer/components/flow/styles/` — five stylesheets, scoped under `#flow-root`.
- `src/renderer/components/flow/ipc.ts` — typed invoke wrappers.
- `webpack.config.js` — `roughjs/bin/*` aliases.
- `package.json` — `@excalidraw/excalidraw`.

## Verification steps

1. `npm run build` then `npm start`.
2. Click **Flow** in the sidebar; confirm the shell header and **Open projects folder**
   render, and that search for "Flow" navigates correctly.
3. Open a project, draw, **Save**, **Back to Dashboard**, re-open — shapes persist.
4. Toggle the canvas theme dropdown; the preset persists in `localStorage`.
5. Switch to Coordinator and back with a draw view open — no console errors from leaked
   React roots, and Coordinator, Dream Graph, and Notes show no regression.

## Deviations from plan

- **`@excalidraw/excalidraw` is pinned at `^0.18.1`**, not the `^0.18.0` recorded when
  phase 3 landed.
- **Lazy code-splitting of the draw route was not done.** The risk register offers it as a
  mitigation for bundle size if needed; the bundle was accepted as-is.
- **`window.confirm` was not replaced** with a shared modal component on the delete path,
  which the risk register lists as a mitigation.

## Follow-up work

- **Settings subview** — deferred per the plan; the web app's settings/teams UI has no
  desktop equivalent.
- **Autosave on draw exit** — deliberately omitted; desktop matches the web app's manual
  Save.
