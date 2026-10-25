# 08 — React Flow Integration Guide (Laravel + Vite + Tabler)

How the React island is wired into the existing Laravel/Tabler app, and the
React Flow techniques the other docs rely on.

## 1. Build & mount

**Packages**

```
npm i react react-dom @xyflow/react zustand
npm i -D @vitejs/plugin-react @types/react @types/react-dom
# as phases need them: elkjs cmdk react-dropzone zundo
# CodeMirror 6 (@codemirror/state, @codemirror/view, @codemirror/autocomplete) in phase 6
```

**vite.config.js**

```js
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/graph/main.tsx'],
      refresh: true,
    }),
    react(),
    tailwindcss(),
  ],
});
```

(Also fixes the current `app.js`/`app.ts` entry mismatch by replacing it.)

**Blade shell** (`resources/views/editor.blade.php`) — Laravel keeps auth/session;
React gets its context via data attributes:

```blade
<body data-bs-theme="{{ $theme }}">
  <div id="graph-editor-root"
       data-project="{{ $project->uuid }}"
       data-user='@json($userSummary)'
       data-csrf="{{ csrf_token() }}"></div>
  @vite(['resources/css/app.css', 'resources/js/graph/main.tsx'])
</body>
```

**main.tsx**

```tsx
import { createRoot } from 'react-dom/client';
import '@xyflow/react/dist/base.css';   // bare positioning styles only
import './canvas/theme.css';            // our Tabler-token theme (§2)
import { EditorShell } from './shell/EditorShell';

const el = document.getElementById('graph-editor-root')!;
createRoot(el).render(<EditorShell bootstrap={el.dataset} />);
```

Route: `GET /p/{project}/{any?}` → editor shell (auth + `can:view,project`
middleware). The React app reads/writes the rest of the path
(`/p/{uuid}/g/{graphUuid}`) with the History API.

## 2. Theming: React Flow ⇄ Tabler

Use `base.css` (not `style.css`) so React Flow ships almost no opinions, then map
its CSS variables onto Tabler tokens once, in `canvas/theme.css`:

```css
.react-flow {
  --xy-edge-stroke-default: var(--tblr-border-color-active, #9ba9b4);
  --xy-edge-stroke-width-default: 2;
  --xy-edge-stroke-selected-default: var(--tblr-primary);
  --xy-connectionline-stroke-default: var(--tblr-primary);
  --xy-handle-background-color-default: var(--tblr-primary);
  --xy-handle-border-color-default: var(--tblr-bg-surface);
  --xy-selection-border-default: 1px solid var(--tblr-primary);
  --xy-selection-background-color-default: rgba(var(--tblr-primary-rgb), .08);
  --xy-minimap-background-color-default: var(--tblr-bg-surface);
  --xy-controls-button-background-color-default: var(--tblr-bg-surface);
  --xy-controls-button-color-default: var(--tblr-body-color);
  --xy-background-color-default: var(--tblr-bg-canvas, var(--tblr-bg-surface-secondary));
}
```

- **Dark mode:** the existing toggle sets `data-bs-theme` on `<body>`; Tabler tokens
  resolve through it, so the variables above flip automatically. Additionally pass
  `colorMode={theme}` to `<ReactFlow>` (watch the body attribute with a
  `MutationObserver` → store) so library internals agree.
- **Grid:** replace the hand-rolled CSS grid sync from `graph-editor.ts` with the
  built-in `<Background variant="dots" gap={20}/>` — it pans/zooms itself.
- **Nodes are Tabler markup** — `card`, `avatar`, `badge`, `dropdown` classes work
  as-is (example in doc 06 §1). Keep chrome flat (no heavy shadows/blur) for 60 fps.
- Brand colors: nothing hard-coded in components; node accent colors come from
  `node.color` (project palette) and everything else from Tabler tokens.

## 3. Canvas wiring (`GraphCanvas.tsx`)

```tsx
const nodeTypes = registryToNodeTypes();   // built once from model/nodeTypes/*
const edgeTypes = { choice: ChoiceEdge };

<ReactFlow
  nodes={useProjectStore(selectFlowNodes)}     // mapped from document model
  edges={useProjectStore(selectFlowEdges)}
  nodeTypes={nodeTypes} edgeTypes={edgeTypes}
  onNodesChange={dispatchNodeChanges}          // deltas → commands (drag-end commits)
  onEdgesChange={dispatchEdgeChanges}
  onConnect={c => dispatch(new Connect(c))}
  onConnectEnd={openQuickCreatePicker}         // drag-to-empty-space node picker
  isValidConnection={registryValidate}
  onDrop={handlePaletteOrFileDrop} onDragOver={allowDrop}
  onDoubleClickNode={maybeSubmergeContainer}
  fitView minZoom={0.1} maxZoom={2.5}
  onlyRenderVisibleElements={nodeCount > 400}
  colorMode={theme} deleteKeyCode={['Backspace','Delete']}
  multiSelectionKeyCode="Shift" selectionOnDrag panOnDrag={[1,2]}  // left=select box, middle/right=pan
>
  <Background variant="dots" gap={20} />
  <MiniMap pannable zoomable nodeColor={n => n.data.color} />
  <Controls />
  <HelperLines />
</ReactFlow>
```

Bridge rules (details in doc 03 §4):

- Store is the single source of truth; selectors memoize `Node[]`/`Edge[]` and keep
  `data` references stable so `memo`'d node components skip re-renders.
- Drag movements are ephemeral; a `MoveNodes` command commits on drag end →
  one undo entry, one autosave delta.
- `screenToFlowPosition()` (from `useReactFlow()`) replaces the manual coordinate
  math currently in `graph-editor.ts`.
- Palette drag uses a custom MIME (`application/dreamgraph-node`) so the drop
  handler can distinguish palette items from OS file drops (assets, doc 05).

## 4. Feature → React Flow mechanism map

| Plan feature | Mechanism |
|---|---|
| Custom narrative nodes (doc 06 §1) | `nodeTypes` + Tabler JSX; `Handle` per pin |
| Container open button (doc 04) | `<NodeToolbar>` / corner button + `onDoubleClick` |
| Group frames | built-in subflows (`parentId`, `extent:'parent'`) — visual only |
| Choice labels / gated edges | custom edge + `<EdgeLabelRenderer>` |
| Minimap, zoom controls | `<MiniMap>`, `<Controls>` |
| Box select, multi-drag | built-in (`selectionOnDrag`) |
| Node resize (comments, frames) | `<NodeResizer>` |
| Connection rules | `isValidConnection` fed by NodeTypeRegistry |
| Quick-create on edge drop | `onConnectEnd` + floating picker (cmdk list) |
| Semantic zoom | `useStore(s => s.transform[2])` inside node components |
| Play-mode path highlight | edge/node `className` from traversal state |
| Auto-layout selection | elkjs → `MoveNodes` command (undoable) |
| Canvas PNG export | `html-to-image` on the viewport element |
| Helper lines / snap | `snapToGrid` + community helper-lines pattern |
| Large-graph perf | `memo` nodes, `onlyRenderVisibleElements`, flat chrome, per-graph loading (doc 04) |

## 5. Testing & pitfalls

- **Vitest + Testing Library:** node components render with a `<ReactFlowProvider>`
  wrapper; store/command logic tests need no DOM at all (that's the payoff of
  keeping the document model outside React Flow).
- **Playwright:** drive real drag/connect gestures for the happy path; React Flow
  renders `data-id` on nodes — stable selectors.
- Pitfalls to remember:
  - Don't recreate `nodeTypes`/`edgeTypes` objects per render (define at module
    scope) — it forces full re-mounts.
  - Interactive elements inside nodes need the `nodrag` class so text selection and
    button clicks don't move the node.
  - Wheel-zoom vs. page scroll: the canvas container must own the wheel event
    (default) — remove the old manual `wheel` handler.
  - `fitView` only after the graph payload has hydrated (guard with a ready flag)
    or the camera jumps.
  - SSR is irrelevant here (Blade shell), which avoids React Flow's main
    integration pitfall entirely.
