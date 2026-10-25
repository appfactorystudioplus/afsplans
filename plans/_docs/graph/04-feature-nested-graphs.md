# 04 — Feature: Nested Graphs (Levels-Deep Nodes)

> **Requirement:** "a node should have a button that opens up a new node graph and
> continue — that node represents a node graph for that node."

This is Articy Draft's signature **Flow Fragment / submerge** interaction. A
*container node* on the canvas stands for an entire child graph; opening it swaps the
canvas to that child graph; nesting is unlimited (Chapter → Scene → Conversation → …).

## 1. UX design

### The container node

- New node type `container` (palette name: **"Flow"** or **"Fragment"**): rounded
  rectangle, distinct color band, 1 input pin / N output pins (default 1).
- Chrome on the node:
  - **↘ Open button** (corner icon) — enters the child graph. **Double-click** on the
    node body does the same (Articy's submerge gesture).
  - **Badge**: child node count (e.g. `12 nodes`), so writers can see which fragments
    have content. Empty containers show a dashed outline.
  - Optional **preview strip**: names of the first few child nodes (v2).
- Any container can also carry normal properties (display name, description, color,
  template) — it's a real node, not just a folder.

### Navigation

- **Breadcrumb bar** above the canvas: `Root ▸ Chapter 1 ▸ Tavern Scene ▸ Bartender
  Talk`. Click any crumb to jump up. Always visible; root crumb = project name.
- **Up button / `Esc`**: go to parent graph, with the container node selected and
  centered (so you always know where you came from).
- **Browser history**: each graph has a URL (`/p/{project}/g/{graph-uuid}`), so
  back/forward traverse the nesting naturally and graphs are deep-linkable
  (paste a link to a scene in Slack).
- **Graph tree panel** (sidebar): the project's containment tree as an outline;
  click to open any graph directly. This is the replacement for canvas sprawl.
- Per-graph **viewport memory**: store `{x, y, zoom}` on the `graphs` row so
  re-entering a graph restores your camera.

### Pin pass-through (entries/exits)

The parent node's pins must connect to the flow *inside* the child graph:

- When a child graph is created, it is seeded with an **Entry node** (one per parent
  input pin) and an **Exit node** (one per parent output pin). These are slim,
  non-deletable-while-referenced marker nodes pinned to the left/right.
- Semantics: traversal entering the container through input pin *i* continues at
  Entry *i*; reaching Exit *j* inside continues from the container's output pin *j*
  in the parent graph. The simulator (doc 06 §5) implements exactly this.
- Adding/removing an output pin on the container adds/removes the matching Exit node
  (removal blocked if that Exit has incoming connections — show a warning).

### Creating & destroying

- The child graph is **created lazily** on first open (`POST /nodes/{node}/child-graph`)
  so dropping containers stays instant.
- **Convert selection → container** (context menu): wraps selected nodes into a new
  container node, moves them into the fresh child graph, and re-routes boundary
  connections through Entry/Exit pins. This is *the* refactoring tool for taming a
  crowded canvas (Articy has this; writers love it).
- Deleting a container warns with the descendant count ("Deletes 47 nodes in 3 nested
  graphs") and soft-deletes; DB cascade handles the subtree (doc 03 §2).
- Copy/paste of a container deep-copies its subtree with fresh UUIDs.

## 2. Data model (already in doc 03)

- `graphs.parent_node_id → nodes` (unique) — containment tree, root graph has NULL.
- No cycle risk: a child graph is created *under* an existing node and containers are
  never re-parented across their own subtree (guard the move/paste path with an
  ancestor check anyway).
- Depth: unlimited logically; enforce a sane cap (e.g. 32) in the service layer to
  keep breadcrumbs and recursive queries bounded.

Breadcrumb query: walk `graph → parent_node → its graph → …` upward; it's ≤ depth
queries, or one recursive CTE. Cache the materialized path on the graph row
(`path` column, e.g. `/rootGraphUuid/nodeUuid/graphUuid/...`) if it shows up in profiles.

## 3. Frontend implementation (React Flow)

- `ProjectStore.openGraph(uuid)`:
  1. flush pending autosave for the current graph,
  2. `GET /api/graphs/{uuid}` (nodes + connections + breadcrumb),
  3. swap the store's open-graph slice — React Flow being a controlled component,
     the canvas re-renders from the new `nodes`/`edges` selectors,
  4. restore viewport via `setViewport` (from `graphs.viewport`), push history state.
- **Container node component** (`nodeTypes/container/Component.tsx`): Tabler card
  with color band, child-count badge, and an `<NodeToolbar>`/corner button for
  **Open**; `onDoubleClick` = submerge. Output handles rendered from the node's pin
  count (`data.outputs`), so adding a pin is a data edit, not a new node type.
- **Note on React Flow subflows:** React Flow's built-in `parentId` subflows nest
  nodes *visually on the same canvas*. We deliberately do **not** use that for this
  feature — story nesting must scale to hundreds of nodes per level, which demands
  per-graph loading and a fresh canvas per level. `parentId` is reserved for the
  cosmetic **Group frame** node (doc 06 §1) within a single graph.
- **Semantic zoom preview** (v2): below a zoom threshold, the container component
  can render a tiny auto-laid-out thumbnail of its child graph (from the cached
  graph-tree summary) — Articy's detail-level zoom feel.
- The **undo stack is per-graph** (partitioned on navigation) — undoing an edit you
  can't see is a footgun.
- `Breadcrumbs.tsx` + `GraphTree.tsx` read the cached graph-tree summary from the
  project shell payload and update optimistically on create/delete.

## 4. Edge cases

| Case | Behavior |
|------|----------|
| Open empty container | Create child graph with Entry/Exit seeded; land centered |
| Jump node targeting a node in another (deeper) graph | Allowed — navigation follows the target (doc 06 §2) |
| Two users in different levels of same project | Fine — per-graph loading isolates them (locking in phase 8) |
| Container with 0 outputs | Legal (dead-end scene); simulator treats it as flow end |
| Export | Containment is explicit in export: nodes carry `children: [...]` or graphs carry `parent_node` — programmers get the tree, not a flat soup |
| Search result inside a nested graph | Result row shows breadcrumb path; click opens that graph and selects the node |

## 5. Acceptance criteria (phase 3 exit)

1. Drop a Flow node, double-click it → new child graph opens with Entry/Exit nodes;
   breadcrumbs show the path; browser Back returns to the parent with the node selected.
2. Nesting 5+ levels deep works; graph tree panel mirrors the structure live.
3. Connections through container pins survive save/reload and round-trip through export.
4. "Convert selection to Flow" wraps 10 selected nodes, preserving all boundary
   connections' behavior.
5. Deleting a mid-tree container removes exactly its subtree; undo restores it
   (soft-delete restore) within the session.
