# 07 — Implementation Roadmap (Tilemap TM0–TM5)

Phases are shippable increments (graph-roadmap convention,
`../graph/07-roadmap.md`); sizes are rough t-shirt estimates for one
developer. Prefix **TM** keeps them distinct from graph phases 0–8.

## Prerequisites from the graph roadmap

| Needs | From | Why |
|---|---|---|
| Persistence, autosave contract, ProjectPolicy, API conventions | Graph **Phase 1** | tilemap endpoints/autosave clone its machinery |
| Asset upload + `AssetPicker` | Graph **Phase 4** | tileset textures and thumbnails are assets |
| NodeTypeRegistry + Inspector | Graph **Phase 2** | the `tilemap_ref` node type (TM3) |
| `.dgpack` pipeline | Graph **Phase 7** | bundle inclusion (TM4 ties in) |

TM0–TM2 can start any time after graph Phases 1+4; TM3 additionally needs
Phase 2. Nothing here blocks any graph phase.

## TM0 — Data model & tilesets (M)

- [ ] Migrations: `tilesets`, `tilemaps`, `tilemap_layers`, `tilemap_chunks`
      (doc 03 §2).
- [ ] `rle.ts` + PHP `RleCodec` with **shared fixture files**; CI round-trip
      property tests (the doc 01 §3 contract).
- [ ] Tileset endpoints (CRUD, tile-meta patch, server-side slice derivation);
      tilemap/layer/chunk endpoints incl. range-limited chunk fetch and the
      `PUT chunks` autosave flush with version stamp + 409 (doc 03 §3–4);
      Pest coverage incl. policy tests and chunk-payload validation (§6).
- [ ] New Tileset dialog with live slicing preview over `AssetPicker`/upload
      (doc 05 §3); Tilesets section of the catalog panel.

**Exit:** tilesets can be created from uploaded images and re-sliced; chunk
rows round-trip byte-identically through the API; all endpoints policy-guarded.

## TM1 — The tilemap view & paint core (M/L)

- [ ] `resources/js/tilemap/` scaffold (doc 02 §2 layout); `tilemapStore`
      (zustand/vanilla); `EditorShell` view router + per-view panel dock +
      `/p/{project}/t/{tilemap}` History routing (doc 02 §1).
- [ ] `TilemapSurface`: DPR-aware canvas, dirty-render rAF scheduling, camera
      (pan/zoom-to-cursor, y-up transform), pointer capture + input routing,
      view-scoped keyboard (doc 02 §3–6).
- [ ] `ChunkRenderer` offscreen-canvas cache + visible-range culling;
      `chunk.ts` model (floor-div, UsedCount, dirty flags) with negative-coord
      unit tests.
- [ ] **Brush + Erase only** (incl. Bresenham + RMB-erase), single layer,
      stamp = single tile; stroke recording; chunk-granular autosave wired to
      the shared save indicator; viewport memory.
- [ ] Vitest for camera/chunk/rle; Playwright: create → paint → reload →
      canvas-diff persists.

**Exit:** doc 02 §7 performance budget met on the 512×512 test region; paint →
reload survives; two tabs hit the 409 flow instead of losing strokes.

## TM2 — Full toolset & layers (M)

- [ ] Tile Palette panel: atlas grid canvas, click/drag stamp selection,
      per-tile Solid/Tag metadata strip (doc 05 §2–3).
- [ ] Rect, Fill (fenced scanline), Picker (+Alt+click / Alt+drag); brush
      size + `[` `]`; hover ghost preview; Shift+line (doc 05 §4).
- [ ] `TileUndoStack` (256-cap, per map, view-scoped Ctrl+Z) (doc 05 §5).
- [ ] Grid overlay + cursor/stamp/marquee overlays + chunk debug toggle
      (doc 05 §6).
- [ ] Layers panel + Map Inspector: add/reorder/rename/visibility/lock/
      opacity/delete; active-layer paint targeting; layer property
      persistence (doc 05 §8).
- [ ] Touch/pen pass (pointer events, pinch zoom).

**Exit:** the doc 05 §9 verify checklist passes end to end.

## TM3 — Graph integration (M) — doc 04

- [ ] `tilemap_ref` node type folder (schema/component/inspector/palette);
      `pickTilemap()` modal; lazy create-and-assign flow.
- [ ] Navigation: `openTilemap` with provenance, breadcrumbs
      (`… ▸ ⌗ Cave Map`), Esc-to-source-node (selected + centered), browser
      back/forward across views; cross-view autosave flush ordering.
- [ ] Tilemaps catalog panel (list, new, rename, duplicate, usage-guarded
      delete + node placeholder, drag-row-to-canvas → node).
- [ ] Client-rendered thumbnails (throttled toBlob → internal asset) shown on
      nodes + catalog (doc 03 §5).
- [ ] Project shell payload grows tilemap/tileset catalogs; `Cmd+K` search
      entries; problems-panel checks (dangling refs, orphaned tile ids).

**Exit:** drop a Tile Map node → create a map from it → paint → Esc back to
the node (selected, thumbnail showing) → reload → deep link to
`/p/…/t/…` works; deleting a referenced map warns and placeholders.

## TM4 — Interchange: engine files, Tiled, bundles (M/L) — doc 06

- [ ] Export job: `joystick` zip (`.jtileset`/`.jtilemap` per layer + textures
      + `manifest.json`, verbatim chunk bytes, deterministic order), plus
      `json` and `png` formats.
- [ ] Import job: engine zip (tileset dedupe/reuse, verbatim-after-validation
      chunks, manifest assembly, update-in-place by uuid) with dry-run +
      loud-skip report; zip hardening shared with `.dgpack`.
- [ ] Tiled `.tmx`/`.tsx` importer at the 47.4 scope (CSV + uncompressed
      base64, orthogonal, GID flag masking, 16×16→32×32 chunk re-bucketing);
      fixture suite.
- [ ] `greedyMesh.ts` + collision preview overlay + `collision_rects` manifest
      hint (doc 05 §7, doc 06 §2).
- [ ] `.dgpack`: `tilesets`/`tilemaps` sections in `project.json`, import
      pipeline step-4 slotting, subtree referenced-only closure (doc 04 §5);
      round-trip CI tests (doc 06 §5).

**Exit:** doc 06 §7 verify table passes (engine-side hand-load deferred until
engine step 45 exists — the file-shape assertions run against the engine
plan's YAML fixtures meanwhile).

## TM5 — Live engine bridge & polish (L, future)

**Design is no longer blocked** — the bridge protocol doc 06 §6 promised is
written ([doc 08 — Live Sync Protocol](08-live-sync-protocol.md)), and the
engine committed its half (`plan/steps/47_tilemap_physics_scripting.md` §47.8,
plus the `.jmeta` `Origin` identity in engine 48.6/49.6). **Implementation**
still opens only when JoystickEngine steps 40–41 (editor bridge) and 45–47 are
actually built — the engine repo's pull/push client is *its* plan's work, doc
08 §7. Split into the two halves that can land independently:

**TM5a — server surface for sync (unblocked now; no engine dependency):**

- [ ] `since_version` conditional export (304 when
      `tilemaps.version ≤ since_version`); ETag/`If-None-Match` on the export
      job (doc 08 §2).
- [ ] Per-project **sync tokens**: issue/list/revoke UI in project settings;
      `tilemap:read`/`tilemap:write` ability scoping; token auth guard on the
      export/import endpoints (doc 08 §3).
- [ ] Import `update-in-place` addressed by `base_version` + `tilemap_uuid`
      with the standard 409 (doc 08 §5) — reuses the doc 03 §4 autosave
      version machinery, so this is mostly wiring, testable without an engine.
- [ ] Realtime notify to open web editors on an accepted engine push (rides
      graph Phase 8's Reverb) (doc 08 §6).

**TM5b — engine-paired end-to-end (needs the engine bridge real):**

- [ ] Live pull/push exercised against the engine's step-47.8 client; the
      round-trip and conflict rows of doc 08 §8 pass end-to-end.
- [ ] Offline write queueing + reconciliation (doc 08 §5.3).

**Polish backlog** (independent of the bridge): auto-tiling rules (the reserved
`tile_meta` slot), minimap, merge-down, tile animation preview, WebGL
`ChunkRenderer` if profiling demands, play-mode scene-slate rendering of
referenced maps.

## Risks

| Risk | Mitigation |
|---|---|
| Format drift between web and engine (the whole value prop) | The doc 01 §3 frozen contract + shared fixtures in CI; any change to `rle.ts`/`RleCodec`/chunk size is a versioned format event, never a refactor |
| Engine steps 45–47 change shape before they're built (they're plan, not code) | The frozen contract is now **co-owned**: engine 45.5 restates points 1–9 verbatim and vendors our `rle-fixtures.json` into its own CI, so a shape change breaks the engine's tests too — drift is caught symmetrically, not just on our side. Everything outside the contract travels via the manifest, which stays ours to version |
| Canvas 2D hits a perf wall on huge maps | `ChunkRenderer` is one class behind an interface; PixiJS/WebGL swap is contained (doc 02 §2); budget tests in TM1 catch it early |
| The vanilla-TS surface and React panels drift into two state worlds | One `zustand/vanilla` store is the only channel; `surface/` import ban enforced by lint rule |
| Chunk autosave conflicts corrupt maps | Version stamp + whole-chunk upserts (no partial merges) + 409 refetch — a chunk is always internally consistent |
| Tileset re-slice orphans painted tiles | Warn with affected-map count; renderer draws placeholders, problems panel lists them; no silent data mutation |
| Scope creep toward a full game editor (animation, autotiling, objects) | v1 cuts recorded in doc 01 §4; `tile_meta` reserves the extension slot; new features must land as engine parity or manifest-versioned extras |
