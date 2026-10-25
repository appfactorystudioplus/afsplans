# TM4 — Engine Interchange (Export, Import, Tiled, .dgpack) — COMPLETE

**TM4 is complete** — the tile map editor can now export to JoystickEngine, import back, import Tiled maps, and its tilesets/tilemaps ride along in `.dgpack` project bundles. Production build compiles (295 modules), and **73 TS + 65 PHP tests pass** (the same 15 pre-existing, unrelated `GraphProject::factory()` failures remain from before this session — nothing of mine is broken).

## What I built (TM4)

**Collision preview** — [`greedyMesh.ts`](apps/dream_graph/resources/js/tilemap/model/greedyMesh.ts), a faithful port of engine step 47.1's algorithm (grow-right, then grow-strip-down over the solid mask), plus a `CollisionCache` that memoizes per-chunk results keyed to the same `renderDirty` flag the bitmap renderer already tracks — one re-mesh per edit, not per frame. Wired into a toolbar toggle that draws merged rects in translucent green, exactly the engine's own debug overlay.

**The frozen file format, in PHP** — [`TilesetYamlCodec`](apps/dream_graph/app/Support/TilesetYamlCodec.php) and [`TilemapYamlCodec`](apps/dream_graph/app/Support/TilemapYamlCodec.php) produce/parse the exact `.jtileset`/`.jtilemap` YAML shapes engine steps 45.1/45.3 document. Critically, the tilemap codec never touches chunk `Data` bytes — they're the same base64 RLE string stored in `graph_tilemap_chunks.data`, copied verbatim in both directions.

**Export to JoystickEngine** — [`TilemapExportService`](apps/dream_graph/app/Services/TilemapExportService.php) builds a zip (`manifest.json` + one `.jtileset` per referenced tileset + one `.jtilemap` per layer + atlas textures, deterministic ordering, an advisory `collision_rects` hint from a PHP port of the greedy mesher) or a canonical JSON slice. Rides the **exact same** queued-export/signed-download machinery the app already had for project/bundle exports (`Export` model, `GET /api/exports/{uuid}` polling) — no new status endpoint needed.

**Import from an engine zip** — [`TilemapImportService`](apps/dream_graph/app/Services/TilemapImportService.php) is a two-phase plan/execute importer: parse and validate everything (never trust imported bytes) with zero writes, then replay the plan in one transaction so a failure never leaves partial data. Handles manifest-driven assembly, bare `.jtileset`/`.jtilemap` files with no manifest, texture dedup by checksum, tileset reuse by matching slicing, and **update-in-place** when the manifest's `tilemap_uuid` matches an existing map in the target project (layers matched by name, replaced wholesale). Reuses the exact same zip-slip/zip-bomb hardening as `.dgpack` imports (factored into a shared [`ZipSafety`](apps/dream_graph/app/Support/ZipSafety.php) class).

**Tiled import** — [`TiledImporter`](apps/dream_graph/app/Support/TiledImporter.php) parses `.tmx`/`.tsx` via `ext-simplexml` at exactly engine step 47.4's scope: orthogonal only (isometric/hex rejected outright), CSV or uncompressed-base64 (compressed layers skipped with a warning), fixed-size and infinite/chunked maps (Tiled's own chunking re-bucketed into our 32×32), GID flip-flag masking with a count, object/image/group layers skipped loudly. Plugs into the same import endpoint — the service auto-detects a `.tmx` when there's no `manifest.json`. Ships with 5 hand-built fixture `.tmx`/`.tsx` files covering CSV, embedded-tileset base64, infinite chunks, compressed data, and isometric rejection.

**`.dgpack` bundle integration** — `ExportService` gained `tilesets`/`tilemaps` sections (additive, no schema bump), with the referenced-only closure for subtree exports (only maps a `tilemap_ref` node actually points at, via the same `_uuid`-suffix convention `_asset_uuid` already uses). `BundleImportService` gained matching import methods, wired into the correct dependency order (after assets/entities, before graphs — so `tilemap_ref` node data gets remapped through the same generic `_uuid`-suffix rewriter every other reference already uses). Restore mode now also wipes old tilemaps/tilesets first (a bug I caught and fixed along the way: both models are soft-deletable, so a plain `->delete()` in the wipe left the uuid occupied and collided with the bundle's re-insert — fixed to `forceDelete()`).

**Frontend** — [`TilemapExportImportDialog.tsx`](apps/dream_graph/resources/js/tilemap/panels/TilemapExportImportDialog.tsx), reusing the graph editor's existing `useJobPolling` hook so export/import progress and reports render identically to the app's established pattern. Wired into the `TilemapView` toolbar.

## Tests (26 new, 138 total tilemap tests across TM0–TM4)
`greedyMesh.test.ts` (9), `TilesetYamlCodecTest`/`TilemapYamlCodecTest` (10), `GreedyMeshTest` (4 PHP), `TilemapExportTest` (5), `TilemapImportTest` (6), `TiledImporterTest` (8) + `TilemapTiledImportTest` (4), `TilemapBundleTest` (4) — covering byte-identical round-trips, the 409-style "never partial writes" guarantee on malformed input, malicious-zip rejection, referenced-only subtree closure, and restore wiping stale content.

## Honestly deferred
- **`png` export format** is explicitly NOT implemented — it would need a server-side tile compositor duplicating the client's canvas renderer for a documentation nicety; the client-rendered thumbnail already covers preview needs.
- **Live browser verification** is still blocked by the app's Checkpoint login redirect (unchanged from earlier steps).

## Where things stand
- **TM0–TM4 done**, on branch `tilemap-editor`, nothing committed yet.
- **TM5 next (future)**: the live engine bridge — sync tokens, `since_version` conditional export, engine-side pull/push. This opens only once JoystickEngine steps 40–41 (editor bridge) and 45–47 (the tilemap runtime itself) actually exist in the engine repo.
