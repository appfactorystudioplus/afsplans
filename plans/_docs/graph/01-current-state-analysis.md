# 01 — Current State Analysis (`apps/dream_graph`)

Analyzed 2026-07-14.

## 1. Stack overview

| Layer | Technology | Notes |
|-------|-----------|-------|
| Backend | Laravel 12, PHP ^8.2 | Fresh skeleton — no custom controllers, models, or migrations beyond the defaults |
| Frontend build | Vite 7 + `laravel-vite-plugin` 2, TypeScript | Two entries: `resources/js/app.js` (see mismatch below) and `resources/js/graph-editor.ts` |
| Graph library | **Drawflow 0.0.60** | Vanilla-JS node editor; unmaintained (last release ~2022) |
| UI framework | Tabler 1.4 (Bootstrap 5.3), Tabler Icons webfont | Tailwind 4 is also installed via `@tailwindcss/vite` but effectively unused by the editor |
| Styling | `resources/css/graph-editor.css` (231 lines) + `resources/js/styles/styles.scss` | Grid background, node theming, dark mode |
| Database | Default Laravel migrations only | `users`, `cache`, `jobs` — **nothing graph-related** |

## 2. Routing & pages

`routes/web.php` has a single route:

```php
Route::get('/', function () {
    $afssdk = env('APP_ENV') === 'production' ? 'api' : 'api-sandbox';
    return view('home', ['afssdk' => $afssdk]);
});
```

- `resources/views/home.blade.php` — the graph editor page. Tabler vertical-sidebar
  layout: draggable node palette (Basic / Math / Logic sections), a "Custom" tab with
  hard-coded "Project Alpha/Beta" cards, top navbar with zoom controls, undo/redo,
  theme toggle, and a stub user menu.
- `resources/views/welcome.blade.php` — unused default Laravel welcome page.
- The page loads an external script `https://{api|api-sandbox}.appfactory.studio/sdk.js`
  and CDN copies of Font Awesome and Drawflow CSS.

**There are no API routes, no save/load endpoints, and no auth on the page**
(the "Logout" button is `confirm()` + `alert()`).

## 3. The editor (`resources/js/graph-editor.ts`, 436 lines)

What it actually does:

- **Drawflow init** with `reroute` + curvature fix.
- **Zoom**: buttons + mouse-wheel, clamped 0.3–3.0, with a CSS grid background that
  scales/pans in sync with the canvas (`updateGridBackground`).
- **Theme toggle**: dark/light via `data-bs-theme` + `localStorage`.
- **Palette drag & drop**: sidebar `.submenu-item[data-node-type]` elements are
  draggable; drop on the canvas computes Drawflow coordinates and calls `createNode`.
- **Node vocabulary** (hard-coded `getNodeConfig`): `input`, `output`, `text`,
  `button`, `add`, `subtract`, `multiply`, `divide`, `if`, `compare`, `form`.
  Each is a fixed `{name, inputs, outputs, class, icon}` plus an HTML template string
  (some with inline `onclick="alert(...)"` demo handlers).
- **Undo/redo**: snapshot history of `editor.export()` JSON, max 50 entries, wired to
  `nodeCreated/nodeRemoved/connectionCreated/connectionRemoved` events (via 100 ms
  `setTimeout` — fragile, can capture mid-transition states).

## 4. What's missing for the product we want

| Capability | Status |
|-----------|--------|
| Persistence (save/load graphs) | ❌ none — refresh loses everything |
| Projects / multiple graphs | ❌ UI stubs only |
| Auth on the editor | ❌ stub buttons |
| Story-oriented node types (dialogue, condition, hub…) | ❌ demo math/form nodes |
| Node inspector / property editing | ❌ only inline `df-*` inputs |
| Nested graphs | ❌ (Drawflow "modules" exist but are unused) |
| Assets (upload, library) | ❌ |
| Characters/entities, variables, scripting | ❌ |
| Simulation / play mode | ❌ |
| Export for game engines | ❌ (only in-memory `editor.export()`) |
| Multi-select, copy/paste, minimap, context menus | ❌ Drawflow lacks most of these natively |
| Undo/redo | ⚠️ works but snapshot-based and event-timing dependent |
| Zoom/pan/grid, dark mode, drag-drop palette | ✅ working |

## 5. Housekeeping issues (fix before/alongside Phase 1)

1. **Vite input mismatch**: `vite.config.js` and both Blade views reference
   `resources/js/app.js`, but the file on disk is `resources/js/app.ts`.
2. **Unrelated code mixed into the repo** — none of it is imported by the graph
   editor page and it should be removed or moved to its own package:
   - `resources/js/appfactory3.ts` (~3,800 lines, "Appfactory Studio" component framework)
   - `resources/js/checkpoint/` (an auth/OAuth client SDK, ~4,500 lines + `CHECKPOINT.md`)
   - `resources/js/app/` (`NotesApp.ts`, `noteExplorer.ts`, `markdownEditor.ts` — a notes app)
   - `resources/js/auth/`, `globalAuth.ts`, `appwindow.ts`
3. **Unrelated binary assets** under `resources/js/assets/` (dozens of images/PDFs,
   several MB) that have nothing to do with the graph app — they bloat the repo and
   any build that globs assets. Remove from this app.
4. **Duplicate/CDN dependencies**: Drawflow CSS is loaded from both the npm bundle
   and jsDelivr; Font Awesome CDN is loaded although Tabler Icons is bundled.
5. **External `appfactory.studio/sdk.js`** script tag — decide whether this app really
   depends on it; if the graph editor is standalone, drop it.
6. `.vscode/sftp.json` — check it holds no credentials before it stays in git.

## 6. Canvas library — DECIDED: React Flow (`@xyflow/react`)

Drawflow (current) is unmaintained since ~2022 and lacks multi-select, minimap,
groups, edge labels, and port typing — everything in this plan would fight it.
Building from scratch was rejected: 2–3 months of canvas engineering (edge routing,
hit testing, culling, selection) that is orthogonal to the actual product.

**Decision: replace Drawflow with React Flow, mounted as a single React island on
the editor page.** Rationale:

- Nodes are plain React components → dialogue nodes with portraits, badges, and
  inline editing are ordinary JSX, and **Tabler classes work inside them directly**;
  built-ins (minimap, controls, edges, handles) are themed via CSS variables mapped
  to Tabler tokens, including dark mode (see doc 08).
- Ships the exact features this plan needs: multi-select, box/lasso selection,
  minimap, subflow/group nodes, edge labels + custom edges, `NodeToolbar`,
  `NodeResizer`, connection validation, helper lines, viewport culling.
- Actively maintained (MIT), largest ecosystem of any node-graph library; most
  Articy-like web tools are built on it.
- The migration cost is at its lifetime minimum: the current editor is ~430 lines of
  demo code with nothing worth porting.

The React footprint stays contained: Blade renders the shell page and mounts one
React app; the rest of the Laravel app is untouched. All state still lives in **our
own document model** (store + REST schema) — React Flow renders it and emits intents,
so the export format and backend never depend on the library's internal format.
Integration specifics live in [doc 08](08-react-flow-integration.md).
