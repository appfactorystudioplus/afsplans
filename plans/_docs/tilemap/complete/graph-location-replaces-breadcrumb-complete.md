# Breadcrumb Trail Replaced with a Simple "Up" Indicator — COMPLETE

The user rejected the breadcrumb-style representation of the nested-graph hierarchy outright ("it should not be a breadcrumb element, try something else") — not just the collapsed-dropdown version from the earlier toolbar cleanup, but the whole breadcrumb concept.

## What changed

Deleted [`Breadcrumbs.tsx`](../../../apps/dream_graph/resources/js/graph/shell/Breadcrumbs.tsx) and replaced it with [`GraphLocation.tsx`](../../../apps/dream_graph/resources/js/graph/shell/GraphLocation.tsx) — no path, no trail, no dropdown. It shows exactly two things: an "Up" button (go to parent graph) and the current graph's name as plain text. Full hierarchy browsing (seeing every ancestor/descendant, jumping anywhere in the tree) is left entirely to the sidebar's existing "Tree" tab (`GraphTree.tsx`), which already highlights the active graph — this indicator doesn't try to duplicate that.

Esc-to-parent keyboard behavior is preserved (same handler, now driving the Up button instead of a breadcrumb click).

`EditorShell.tsx`'s import and render site were updated (`Breadcrumbs` → `GraphLocation`), and a stale docblock reference to "Breadcrumbs component" in `client.ts` was corrected. The underlying `breadcrumb` field name in the store/API was left alone — it still accurately describes the root-to-current ancestry data being fetched; only the display component changed.

## Verification
`npm run build` compiles cleanly (306 modules). Full vitest suite: 192/193 pass (1 pre-existing unrelated floating-point failure, unchanged) — no tests referenced the old `Breadcrumbs` component, so nothing needed updating there. Visual confirmation is on you — still blocked by the browser tool's per-action-approval gate on `dream_graph.test`.
