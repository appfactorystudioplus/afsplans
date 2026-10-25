# Graph-Location Indicator Finalized: Popover Path — COMPLETE

The user picked **"Popover Path"** after comparing it live against a breadcrumb trail, a plain "Up + name" indicator, an indented jump dropdown, and a dot stepper.

## Cleanup

- The winning variant (`graphLocation/PopoverPath.tsx`) became [`shell/GraphLocation.tsx`](../../../apps/dream_graph/resources/js/graph/shell/GraphLocation.tsx) — same component, renamed and moved out of the comparison folder, docblock rewritten to describe it as the permanent design rather than one of several options.
- Deleted the entire `graphLocation/` folder: the switcher and the four rejected variants (`MinimalUp`, `JumpDropdown`, `Stepper`, `TreeOnly`).
- `EditorShell.tsx` import/usage restored to the single-row header layout (collapse toggle + indicator inline) it had before the switcher needed a second row for its `<select>`.

## A second overflow-clipping bug, caught before it shipped

Restoring that single-row layout, I nearly reintroduced the exact bug reported earlier in this thread: the row wrapping `<GraphLocation />` originally used `overflow-hidden` (to truncate long graph names) — but that class also clips the popover's floating card, since it's an `overflow: hidden` ancestor of an absolutely-positioned child that extends past it. Caught this while re-adding the wrapper and used `style={{ minWidth: 0 }}` instead — the standard flexbox fix that lets a child's own `text-truncate` (which sets `overflow: hidden` only on itself, not on siblings) actually take effect inside a flex row without clipping anything else nearby.

## Verification
`npm run build` compiles cleanly (306 modules — back down from 311 now that the four rejected variants are gone). Full vitest suite: 192/193 pass (1 pre-existing unrelated floating-point failure, unchanged). Visual confirmation is on you — still blocked by the browser tool's per-action-approval gate on `dream_graph.test` — but the specific bug class (overflow clipping a popover) that broke two of the five variants earlier was deliberately avoided this time by using `minWidth: 0` instead of `overflow-hidden`.
