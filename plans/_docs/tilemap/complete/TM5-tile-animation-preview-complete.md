# TM5 (continued) — Tile Animation Preview — COMPLETE

**Tile animation preview is complete** — a tile can now be defined as an ordered, looping sequence of frames that plays live in the editor viewport, so an artist can see water shimmer or a torch flicker while painting, without exporting to the engine to check it. **188 TS tests pass** (8 new), **81 PHP tests pass** (6 new), production build compiles (306 modules). Same pre-existing unrelated failures as every prior step — nothing regressed.

## The scope decision, stated up front

This is explicitly a **preview** feature: animation metadata lives in the same `tile_meta` slot Solid/Tag already use, but it is **not** part of the frozen `.jtileset` v1 export schema — `TilesetYamlCodec::encode` only ever writes `Solid`/`Tag`, by design (per the TM4 docblock: unknown `tile_meta` keys are omitted from engine export). So this feature is entirely web-editor-side for now; exporting it to the engine is a natural future step once the engine plan defines its own animated-tile representation, not something I should guess at here. Playback is forward-looping only, one shared frame duration per tile (no per-frame timing, no ping-pong) — the common case, and a documented cut if more control is ever needed.

## The bug I found and fixed before it could bite

Investigating how to add the `animation` field to tile metadata, I found `UpdateTileMetaRequest` had the **exact same validation gap** I'd just fixed for connections: its rules only listed `solid`/`tag`, so `FormRequest::validated()` would have silently stripped any `animation` field the moment I added it to the frontend — despite the file's own docblock claiming "unknown metadata keys are allowed." I verified this empirically with `php artisan tinker` (same technique as the connections fix) before and after adding proper rules for `animation.frames`/`animation.frameDurationMs`, so this feature didn't ship with a silent-data-loss bug baked in from day one.

## What I built

**Rendering** — the interesting architectural piece. `ChunkRenderer.bitmapFor` now **skips** drawing any tile with an `animation` definition into the static per-chunk bitmap (leaving it transparent) and caches a small list of those cells alongside the bitmap. `TilemapSurface.render()` draws each animated cell's **current frame** as a cheap per-tile overlay pass every tick, using a shared clock and a pure `currentFrameAtlasIndex(animation, elapsedMs)` helper. Critically, the surface only keeps ticking (`requestAnimationFrame`) **while an animated tile is actually visible on screen** — the moment none are, it goes back to the existing zero-idle-cost model the whole chunk-cache architecture already guarantees. The expensive, cached static bitmaps are never touched by animation at all.

**Data model** — [`model/tileMeta.ts`](apps/dream_graph/resources/js/tilemap/model/tileMeta.ts) gained `tileAnimationFor` and `currentFrameAtlasIndex`; `TileMeta` gained an optional `animation: { frames: number[]; frameDurationMs: number }`.

**Editor UI** — the existing Solid/Tag metadata strip in the Tile Palette gained an "Animated" checkbox; when on, it shows the ordered frame list (removable, minimum 2 — matching the backend's rule), a frame-duration input, and a "+ Frame" button that opens a compact click-to-pick atlas grid to append the next frame. Every save sends the tile's **complete** current entry (solid + tag + animation together), not a partial diff — the backend replaces a tile's whole metadata entry per patch, so this is the same discipline the connections fix required.

## Tests (14 new)
- [`tileMeta.test.ts`](apps/dream_graph/resources/js/tilemap/model/__tests__/tileMeta.test.ts) (+8) — frame classification, tile-id-to-atlas-index mapping, forward-loop frame selection at various elapsed times, wraparound, and the zero-frames edge case.
- [`TileAnimationMetaTest.php`](apps/dream_graph/tests/Feature/TileAnimationMetaTest.php) (6) — round-trip persistence (verified against a **fresh DB fetch**, not just the response echo, to actually prove it isn't silently dropped), clearing animation while preserving other fields, and structural validation (minimum 2 frames, required duration, duration bounds, non-integer frames rejected).

## Where things stand
This closes out every item from the original TM5 polish backlog (merge-down, minimap, play-mode scene-slate, auto-tiling, tile animation). TM0–TM5, the play-mode wiring, and the `source_pin`/`target_pin` fix are all done, on branch `tilemap-editor`, nothing committed. The only remaining piece of the whole tilemap plan is the **live engine bridge**, which stays blocked on JoystickEngine steps 40–41/45–47 actually landing in code.
