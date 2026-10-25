# TM0 — Foundation (Data model, codec, API) — COMPLETE

**TM0 (the foundation phase) is complete and fully tested.** Implementation of the tile map editor started in `apps/dream_graph` on a new branch `tilemap-editor` (cut from a clean `main`; nothing committed yet — commit only when asked).

## What I found first
The graph app is far more built than the plan assumed: persistence, autosave, assets + `AssetPicker`, the NodeTypeRegistry, `EditorShell`, import/export, play mode, and scripting all already exist. So the TM0–TM4 prerequisites are satisfied and I could build straight on top.

## What I built (TM0)
- **Migrations** — `graph_tilesets`, `graph_tilemaps`, `graph_tilemap_layers`, `graph_tilemap_chunks` (matching the existing `graph_` prefix and doc-comment style).
- **The frozen chunk codec, in both languages** — `RleCodec.php` and `rle.ts` + `constants.ts`. Both are verified **byte-identical** against a single shared fixtures file (`resources/js/tilemap/model/__tests__/rleFixtures.json`) that the PHPUnit and Vitest suites both read — this is the doc 01 §3 format contract that makes engine export a transcode.
- **Models + a service** — the four Eloquent models plus `TilemapService` holding the two non-trivial operations: deriving a tileset's grid from its atlas image, and the optimistic-locked, chunk-granular autosave flush.
- **Three controllers + FormRequests + 17 routes** — tilesets (CRUD, tile-meta partial-merge, server-side slicing), tilemaps (document shell, range chunk fetch, `PUT chunks` autosave with 409 conflict, reference-guarded delete), and layers (add-above / reorder / edit / delete).
- **Tooling** — wired up Vitest, which had never actually been installed despite `.test.ts` files existing (added the dep, `vitest.config.ts`, and a `test` script).

## Test results
My new tests are all green: **`TilemapApiTest` (13)**, **`RleCodecTest` (8)**, **`rle.test.ts` (26)** — covering grid derivation, the 422 on bad tile size, tile-meta merge/delete, tileset-in-use 409, seed-layer creation, chunk store + server-recomputed `used_count`, the **409 stale-version flow**, null/empty-chunk deletion, malformed-payload rejection, layer reorder, reference-guarded map delete, and cross-project 404.

Two sets of **pre-existing failures that are not mine**, flagged honestly:
- 15 PHP tests (`ApiTest`, `NestedGraphsTest`) fail on `GraphProject::factory()` — the app's model factories were never written (only `UserFactory` exists).
- 1 TS test (`scripting.test.ts`) has a failing rounding assertion — existing code that was simply never run before because Vitest wasn't installed.

## What's next
**TM1** is the editor view itself: the framework-free Canvas2D `TilemapSurface` + `ChunkRenderer`, the `zustand/vanilla` store, the `EditorShell` view router with `/p/{project}/t/{tilemap}` routing, a brush+erase paint core, and the chunk-autosave client.
