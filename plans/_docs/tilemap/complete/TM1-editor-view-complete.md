# TM1 — Editor View (Surface, store, brush/erase, routing) — COMPLETE

**TM1 is complete** — the tile map editor view now exists and is wired into the app. The production build compiles cleanly (282 modules) and all 52 TS + 21 PHP tilemap tests pass. My code is fully type-clean; the 45 pre-existing `tsc` errors are all in graph/checkpoint files I never touched.

## What I built (TM1)

**The framework-free paint core** (`resources/js/tilemap/surface/`) — exactly the React/non-React split the plan calls for:
- [`TilemapSurface.ts`](apps/dream_graph/resources/js/tilemap/surface/TilemapSurface.ts) — owns the `<canvas>`, a DPR-aware buffer, an rAF-coalesced **dirty-render loop** (zero cost when idle), pointer-capture input, wheel zoom-to-cursor, space/middle-drag pan, and **right-click-always-erases**.
- [`ChunkRenderer.ts`](apps/dream_graph/resources/js/tilemap/surface/ChunkRenderer.ts) — the web analog of the engine's `SyncChunk`: each chunk rasterizes once into a native-resolution, nearest-neighbor bitmap and is thereafter blitted, rebuilt only when its `renderDirty` flag is set.
- [`camera.ts`](apps/dream_graph/resources/js/tilemap/surface/camera.ts) — the y-up↔y-down transforms (stored data never flips), plus `visibleChunkRange` culling.
- [`tools/paint.ts`](apps/dream_graph/resources/js/tilemap/surface/tools/paint.ts) — Bresenham line-stepping + N×N block stamping as pure, unit-tested functions.

**The model + state** — [`chunk.ts`](apps/dream_graph/resources/js/tilemap/model/chunk.ts) (sparse `LayerTiles` with dirty tracking and the sparse-empty invariant), [`tilemapStore.ts`](apps/dream_graph/resources/js/tilemap/store/tilemapStore.ts) (one `zustand/vanilla` store shared by the surface and React — the entire bridge), [`strokes.ts`](apps/dream_graph/resources/js/tilemap/store/strokes.ts) (stroke-scoped undo/redo), and chunk-granular **autosave with the 409 conflict → requeue-and-retry** flow against the TM0 optimistic-lock endpoint.

**The view integration** — [`TilemapView.tsx`](apps/dream_graph/resources/js/tilemap/TilemapView.tsx) (toolbar, save indicator, view-scoped keyboard so Ctrl+Z can't collide with the graph editor), a minimal [`TilemapMenu`](apps/dream_graph/resources/js/tilemap/panels/TilemapMenu.tsx) entry point, and a **view router in `EditorShell`**: a "Tile Maps" navbar button, the tilemap view swapping in over the graph canvas, and `/p/{project}/t/{tilemap}` History routing with back/forward support.

**Tests** (52 total): the RLE contract (26), chunk model incl. negative-coordinate math (8), camera transform inverses + zoom-anchor + culling (9), and paint line-stepping/block-stamping/erase (9).

## One honest caveat
The TM1 tile picker is a numeric tile-id input, not a visual palette — that (and the New Tileset dialog needed to create a tileset from an uploaded image) is **TM2**. So end-to-end *interactive* painting in the browser needs TM2 before it's fully exercisable by hand; the paint/model/camera logic itself is covered by the unit tests now.

## Where things stand
- **TM0 + TM1 done**, all on branch `tilemap-editor`, nothing committed yet.
- **TM2 next**: Tile Palette panel (atlas grid + drag-stamp + Solid/Tag metadata), New Tileset dialog with live slicing, the Rect/Fill/Picker tools, and the Layers panel + Map Inspector.
