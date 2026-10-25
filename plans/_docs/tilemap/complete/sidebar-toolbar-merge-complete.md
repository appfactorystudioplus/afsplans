# Toolbar Merged into Collapsible Left Sidebar — COMPLETE

**Follow-up UI restructure** to the earlier toolbar cleanup — moved every top-navbar action down into the existing left Nodes/Tree panel and made that panel collapsible.

## What changed

[`EditorShell.tsx`](../../../apps/dream_graph/resources/js/graph/shell/EditorShell.tsx): the separate top `<div className="navbar">` row is gone entirely. The left sidebar (previously just the Nodes/Tree palette, fixed 240px) now has three stacked sections:

1. **Header** — a collapse toggle (chevron) and the breadcrumb trail (hidden while collapsed — it needs horizontal room the icon rail doesn't have).
2. **Action rail** — every button that used to live in the top navbar: Back to Projects, the View dropdown (Stats, Tile Maps), the Data dropdown (Export/Import Bundle, Localization, Export Image SVG/PNG), Play, Present, and Save.
3. **Node browser** — the existing Nodes/Tree tabs and their content, unchanged, just moved below the action rail.

A new small `SidebarActionButton` helper (local to this file) renders each action as icon+label when expanded, icon-only (with a `title` tooltip) when collapsed — same button, same handler, just without its text, so collapsing never removes functionality.

## Collapse behavior

Toggling collapses the sidebar from 240px to a 48px icon-only rail:
- Action buttons stay usable (icons only, no labels).
- The View/Data dropdowns switch from `dropdown` to `dropend` while collapsed, so their menus open to the *right* of the narrow rail instead of underneath a tiny icon.
- The breadcrumb trail and the Nodes/Tree browser (palette list / graph tree) are hidden while collapsed — browsing or dragging node types from a 48px-wide rail isn't practically usable, so collapsing is meant for reclaiming canvas width when you don't need to browse, not as a permanently-usable narrow mode.

## Scope note: the tile map editor view is unaffected

When a `tilemap_ref` node opens the tile map editor (`TilemapView`), it replaces the graph's 3-column layout entirely and was never part of this sidebar — it already has its own "Back (Esc)" exit button that returns to the graph view, where the (now-relocated) "Back to Projects" button is available again. Confirmed this before touching anything, so there's no dead-end.

## Verification
`npm run build` compiles cleanly (306 modules). Full vitest suite: 192/193 pass (1 pre-existing unrelated floating-point failure, unchanged). Visual verification is pending — the browser tool's per-action-approval gate on `dream_graph.test` is still blocking me from screenshotting it myself, so this needs a manual look, especially the `dropend` menu positioning and the collapsed-rail button sizing, which are easiest to judge visually.
