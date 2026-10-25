# TM5 (continued) — Auto-Tiling Rules — COMPLETE

**Auto-tiling is complete** — the tile map editor now supports painting a named "terrain" and having the correct edge/corner tile variant chosen automatically, instead of hand-picking one of many near-identical atlas tiles per cell. **198 TS tests pass** (18 new), **75 PHP tests pass** (7 new), production build compiles (306 modules). Same pre-existing, unrelated `scripting.test.ts`/`GraphProject::factory()` failures as before — nothing of mine regressed.

## The design decision, stated up front

Auto-tiling has no single standard: RPG Maker's A2 sheets, Godot's autotile grid, and Tiled's Wang terrain sets each assume a **different fixed sheet layout**. Hardcoding any one of them would silently produce wrong tiles the moment a real tileset didn't happen to follow that exact convention — a correctness risk I wasn't willing to take on a guess. Instead I built a **4-directional (N/E/S/W) bitmask scheme with an artist-authored rule table**: for each of the 16 possible same-terrain neighbor patterns, the artist assigns one or more candidate atlas tiles themselves, by clicking. This is the same fundamental model as Tiled's Terrain Brush, just without a baked-in layout — it works with whatever a tileset actually contains. 8-directional ("47-tile blob") autotiling, which additionally distinguishes inner corners, is a documented extension, not implemented here.

A cell "belongs" to a terrain simply by having one of that terrain's candidate tiles painted on it — no separate per-cell terrain storage was needed.

## What I built

**The algorithm** — [`model/autotile.ts`](apps/dream_graph/resources/js/tilemap/model/autotile.ts): `computeBitmaskAt` (N/E/S/W same-terrain neighbor check), `stableCandidateIndex` (a deterministic hash so a cell with multiple candidate tiles always resolves to the same one — variety across cells, no flicker on repaint), `buildTerrainLookup` (classifies any tile id by whichever terrain's candidates list it), and `applyAutotile` (the whole paint/erase/recompute loop: touched cells are marked, their neighbors are recomputed, only terrain cells are ever touched, edits are returned for undo).

**Storage** — a new `autotile_rules` JSON column on `graph_tilesets` (migration + model cast), shape `{ terrainName: { rules: { "0": [atlasIndex, ...], ... } } }`. A `PUT .../tilesets/{tileset}/autotile-rules` endpoint does a full replace (rule-table edits are a cohesive per-terrain unit, unlike tile-meta's per-tile partial merge), with thorough structural validation (pattern keys 0-15, integer candidates, size cap).

**Store + surface wiring** — `tilemapStore` gained `activeTerrain` and an `activeTileset()` getter; a new `'autotile'` tool. `TilemapSurface.paintAutotileAt` steps the Bresenham path since the last pointer sample (same continuous-drag UX as Brush), calls `applyAutotile`, and records every returned edit into the active stroke — so **undo works identically to every other tool**, without any special-casing.

**The rule editor UI** — [`panels/AutotileRuleEditor.tsx`](apps/dream_graph/resources/js/tilemap/panels/AutotileRuleEditor.tsx), a new "Autotile" tab in the left dock: add/rename/delete terrains; a 16-pattern grid, each showing a tiny N/E/S/W neighbor diagram and its assigned tile count; clicking a pattern opens a compact atlas picker (reusing the tileset's own slicing math) where clicking tiles toggles them as candidates. The toolbar gained an Autotile tool button (**T** shortcut) and a terrain dropdown that only appears while that tool is active.

## Tests (25 new)
- [`autotile.test.ts`](apps/dream_graph/resources/js/tilemap/model/__tests__/autotile.test.ts) (18) — bitmask correctness for every direction and combination, deterministic candidate selection, terrain classification (including cross-terrain exclusion and the empty-tile edge case), and the full `applyAutotile` loop: isolated cells, adjacency, full-surround, incremental neighbor recompute on new paint, edge reversion on erase, unauthored-pattern no-ops, and idempotent repainting.
- [`AutotileRulesTest.php`](apps/dream_graph/tests/Feature/AutotileRulesTest.php) (7) — full-replace round-trip, overwrite-not-merge semantics, clearing, structural validation rejections, cross-project 404.

## Where things stand
TM0–TM4, the TM5 polish slice, the play-mode wiring, the `source_pin`/`target_pin` fix, and auto-tiling are all done, on branch `tilemap-editor`, nothing committed. The remaining TM5 item from the original polish backlog is **tile animation preview** (frame-based per-tile animation) — a similarly separate, feature-sized effort. Let me know if you'd like that next, or something else.
