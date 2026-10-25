# Graph-Location Style Picker — COMPLETE

Two prior single-style attempts at showing/navigating the nested-graph hierarchy (a breadcrumb trail, then a plain "Up + name" indicator) were both rejected. Rather than guess a third time, built several genuinely different approaches and added a live style-switcher to the sidebar so the choice can be made by looking at the real thing.

## The five variants

All live in [`resources/js/graph/shell/graphLocation/`](../../../apps/dream_graph/resources/js/graph/shell/graphLocation/):

- **`MinimalUp.tsx`** — Up button + current graph name (the previously-built one, kept for comparison alongside the others).
- **`JumpDropdown.tsx`** — a single button showing only the current graph's name; opening it lists every ancestor as an indented outline (root to current), no visible path inline at all.
- **`PopoverPath.tsx`** — a small pin-icon button toggling a floating card with the ancestry shown as a vertical dot-and-line stack (root at top, current at bottom) — a file-explorer-breadcrumb feel without being a horizontal trail.
- **`Stepper.tsx`** — a row of connected dots, one per nesting level, current one larger/filled; names only appear as hover tooltips, not inline text. Stays compact regardless of depth.
- **`TreeOnly.tsx`** — no indicator at all; relies entirely on the sidebar's existing "Tree" tab, keeping only the Esc-to-parent shortcut alive.

## The switcher

[`GraphLocationSwitcher.tsx`](../../../apps/dream_graph/resources/js/graph/shell/graphLocation/GraphLocationSwitcher.tsx) renders a `<select>` above the chosen variant; the selection persists to `localStorage` so it survives a reload while comparing. Wired into [`EditorShell.tsx`](../../../apps/dream_graph/resources/js/graph/shell/EditorShell.tsx) in place of the old single-style component, in the same sidebar-header spot (still hidden while the sidebar is collapsed to its icon rail, same as before — none of these need less room than a breadcrumb did).

**This is explicitly transitional** — once a favorite is picked, the switcher and the four unused variant files should be deleted and the winner wired in directly as the sidebar header's permanent content. Said so in the switcher's own docblock as a reminder for whoever does that cleanup (likely the very next step in this session).

## Verification
`npm run build` compiles cleanly (311 modules). Full vitest suite: 192/193 pass (1 pre-existing unrelated floating-point failure, unchanged). Visual comparison is on you — still blocked by the browser tool's per-action-approval gate on `dream_graph.test`.
