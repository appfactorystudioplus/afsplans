# Top Toolbar Cleanup — COMPLETE

**The top toolbar was cluttered** — six flat, equally-weighted buttons (Export Image dropdown, Stats, Export/Import, Localization, Tile Maps, Play/Present group, Save) sat in a single row with no grouping, and the breadcrumb trail next to "Back to Projects" grew one inline item per nested-graph level with no upper bound.

## Breadcrumb trail: collapsed into a dropdown

[`Breadcrumbs.tsx`](../../../apps/dream_graph/resources/js/graph/shell/Breadcrumbs.tsx) previously rendered every ancestor graph as its own `<li>` in a flat `<ol>` — for a deeply nested story (Chapter → Scene → Sequence → Dialogue → ...) this row grows unbounded and was eating toolbar width. It now renders:

`📊 Project Name  [⋯ ▾]  ›  📋 Current Graph (N levels deep)`

The `⋯` control only appears once there's at least one ancestor above the current graph, and its dropdown holds *every* intermediate level (not a truncated "first/last" set) so any ancestor is still a single click away — the toolbar's width is now constant regardless of nesting depth. The current graph is always shown inline (not inside the dropdown), and Esc-to-parent behavior is unchanged.

## Toolbar actions: grouped into labeled clusters

In [`EditorShell.tsx`](../../../apps/dream_graph/resources/js/graph/shell/EditorShell.tsx), the six standalone buttons collapsed into two labeled dropdown clusters, leaving Play/Present and Save as the only prominent standalone controls (they're primary actions, not secondary tools):

- **View** — Stats, Tile Maps
- **Data** — Export/Import Bundle, Localization, Export Image (SVG), Export Image (PNG)

This went with the "labeled clusters" option (over a single catch-all "More" menu) since Stats/Tile-Maps and Export-Import/Localization/Image-export are functionally distinct enough to warrant their own grouping rather than one undifferentiated list.

## Verification
`tsc --noEmit` clean, `npm run build` compiles (306 modules), full vitest suite: 189/190 pass (1 pre-existing unrelated floating-point failure in `scripting.test.ts`, unchanged by this work). No behavior changed — every action (Stats, Tile Maps, Export/Import, Localization, both image exports, breadcrumb navigation, Esc-to-parent) is still reachable, just regrouped. Visual verification is pending — the browser tool's per-action-approval gate on `dream_graph.test` is still blocking me from screenshotting it myself, so this needs a manual look in the browser.
