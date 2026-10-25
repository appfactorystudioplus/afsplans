# TM5 (continued) — Wire PresentationMode + PlayModePanel into EditorShell — COMPLETE

**Play mode is now actually reachable in the app** — the gap I flagged last time (both components existed but nothing rendered them) is closed. **148 TS tests pass** (5 new), production build compiles (303 modules, up from 297). The one failure remains the same pre-existing, unrelated `scripting.test.ts` rounding assertion.

## What I built

**[`graphAdapter.ts`](apps/dream_graph/resources/js/graph/playMode/graphAdapter.ts)** — converts the live React Flow canvas state (`projectStore`'s `nodes`/`edges`) into the library-independent `StoryGraph` shape `TraversalEngine`/`PlayModePanel`/`PresentationMode` all expect, keeping the engine free of any React Flow import (the design goal stated in its own README). Picks an `Entry` node as the traversal start when one exists.

**`EditorShell.tsx`** wiring:
- A memoized `storyGraph` built from the currently open graph.
- Two new toolbar buttons: **▶ Play** (toggles a docked `PlayModePanel` in place of the Inspector) and **⛶ Present** (opens `PresentationMode` full-screen). Both disabled when there's no open graph.
- `onNodeHighlight` dispatches a real React Flow selection change (deselect everything, select the current node) so the canvas highlights wherever play mode currently is — this callback existed as a prop on both components already but had never been implemented anywhere.
- `PresentationMode`'s exit (`onClose`) and `PlayModePanel`'s stop (`onPlayModeChange(false)`) both return `playMode` to `'off'`.
- Both components now receive `projectUuid`, which is what makes last session's scene-slate work (rendering a `tilemap_ref` node's map thumbnail) actually resolve to a real thumbnail in the running app instead of just being reachable in principle.

## Tests
5 new: [`graphAdapter.test.ts`](apps/dream_graph/resources/js/graph/playMode/__tests__/graphAdapter.test.ts) — node/edge conversion, Condition-node `sourceHandle` ('true'/'false') pass-through, default handle fallback, Entry-node detection, and the no-Entry-node case.

## A gap I found while wiring this (didn't fix, flagging)

Building the adapter, I confirmed a real, pre-existing issue: `graph_connections` has `source_pin`/`target_pin` columns, but `projectStore.openGraph()`/`saveGraph()` never read or write them. A Condition node's `true`/`false` branch edges only carry their handle identity while connected live in the current browser session — **after a save + page reload, that association is lost**, and Play Mode testing of Condition branching would misbehave. This is a persistence gap in the graph app's own Phase 6 work, not something introduced here or in the tilemap plan's scope, so I left it alone and documented it in `graphAdapter.ts`'s docblock rather than silently working around it. Worth a dedicated fix if Condition-node testing becomes a priority.

## Still can't verify live
The app requires Checkpoint SSO (an external auth server at `auth15.appfactory.studio`) even in local dev — I don't have credentials, so I still can't click through Play/Present in a real browser. Build and the full test suite are the verification I have.

## Where things stand
TM0–TM4, the TM5 polish slice, and this wiring are all done, on branch `tilemap-editor`, nothing committed. The tilemap plan's original ask is now fully realized end-to-end, including the play-mode payoff: paint a tile map → reference it from a `tilemap_ref` node → play/present the story → see the map rendered as a scene slate when the traversal reaches it.
