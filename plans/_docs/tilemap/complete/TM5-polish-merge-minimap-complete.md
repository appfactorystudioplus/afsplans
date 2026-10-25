# TM5 (partial) — Polish Backlog: Merge-Down + Minimap — COMPLETE

**TM5's live-engine-bridge work remains blocked** (it needs JoystickEngine steps 40–41 and 45–47 actually implemented in code — I checked the engine repo again and confirmed only plan docs exist through step 57; the newest code commit is around step 17/18). Per your direction, I did not touch JoystickEngine. Instead I picked up TM5's **non-engine-dependent polish backlog** items from doc 07: layer merge-down and a minimap. Both are pure web-app features with zero backend changes needed. **79 TS tests pass** (up from 73), production build compiles (297 modules).

## What I built

**Layer merge-down** — [`model/mergeLayers.ts`](apps/dream_graph/resources/js/tilemap/model/mergeLayers.ts): a pure function that composites one layer's painted tiles onto the layer beneath it (painted cells overwrite; empty cells let the lower layer show through — the same visual result as viewing the two stacked). Wired into the store (`tilemapStore.mergeLayerDown`) which merges in-memory, flushes the result, deletes the merged-away layer via the existing endpoint, and reloads — no new backend code, since the existing chunk-save and layer-delete APIs already cover it. Guarded to require matching tilesets (mixing atlas indices from two tilesets into one layer would render wrong tiles — the frozen one-tileset-per-layer contract). A "Merge down" button appears on every non-bottommost row in the Layers panel.

**Minimap** — [`panels/Minimap.tsx`](apps/dream_graph/resources/js/tilemap/panels/Minimap.tsx): a small canvas showing every painted chunk across all visible layers (one colored square per chunk, color-coded by layer), the current camera's viewport as an outline, and click-to-pan. Reads live from the store's in-memory chunk data (not the load-time snapshot) so it stays accurate while painting. Docked above the Map Inspector.

## Tests
6 new: `mergeLayers.test.ts` — paints-onto-lower, empty-cell-shows-through, overwrite, chunk-boundary/negative-coordinate correctness, no-op on an empty upper layer, idempotent re-merge.

## Deliberately not attempted this round
- **Auto-tiling rules** and **tile animation preview** — each is a genuinely separate feature (Wang/blob rule authoring; frame-based per-tile animation), sized similarly to a TM-phase on its own, not "polish." The `tile_meta` JSON slot is already reserved for both (doc 01 §4).
- **WebGL `ChunkRenderer`** — the roadmap explicitly gates this on "if profiling demands," and nothing has demanded it.
- **Play-mode scene-slate rendering of referenced maps** — didn't get to this one; it's the next reasonable TM5 polish item if you want more.
- **The actual live bridge** (sync tokens, `since_version`, engine-side pull/push) — still hard-blocked on the engine repo.

## Where things stand
TM0–TM4 plus this TM5 slice are done, on branch `tilemap-editor`, nothing committed. Remaining TM5 polish items (play-mode scene-slate, auto-tiling, tile animation) are available to pick up next; the engine-bridge half of TM5 stays blocked until JoystickEngine catches up.
