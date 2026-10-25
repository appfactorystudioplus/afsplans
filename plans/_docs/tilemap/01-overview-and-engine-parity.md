# 01 — Overview & Engine Parity

## 1. What the engine plan builds (steps 45–47, summarized)

The JoystickEngine v2 plan implements tile maps in three steps. Everything in
this web plan is derived from — and format-compatible with — these decisions.

### Step 45 — Runtime: tileset asset, component, chunked rendering

- **`Tileset` asset (`.jtileset`, YAML):** a texture atlas sliced by
  `TileSize` (px) + `Spacing` + `Margin`; `Columns` derived from texture width;
  a **sparse per-tile metadata map** (`Solid: bool`, `Tag: string`) keyed by
  atlas index. Sampling is nearest-neighbor, no mips, clamp — pixel-art
  defaults — with a **half-texel UV inset** to prevent atlas bleeding.
- **`TilemapComponent` = one layer.** Multi-layer maps are multiple entities
  ordered by Z. One tileset per component.
- **Sparse chunked storage:** `TileID = uint32` (`0` = empty,
  `atlasIndex = id − 1`); 32×32-tile chunks in a hash map keyed by chunk
  coordinate (floor-division of cell coords); chunks track `UsedCount` (0 →
  erased) and `RenderDirty`/`PhysicsDirty` flags.
- **`.jtilemap` serialization:** per chunk, the 1024 tile ids are
  run-length-encoded as `(count, id)` u32 pairs, then base64'd — tile maps are
  extremely runny, so this is small and it round-trips byte-identically.
- **Rendering:** one static mesh per chunk, rebuilt only when its chunk is
  dirty; frustum culling per chunk. Editing one tile = one ≤4096-vert rebuild.

### Step 46 — Editing: Tile Palette panel, paint tools, scoped undo

- **Editing model:** select a tilemap → palette activates → pick tiles → paint
  in the viewport. Paint mode is *explicitly entered*, announces itself, and
  `Esc` exits.
- **Palette:** tileset combo + drop target; zoomable atlas grid where click
  selects a tile and **drag selects a rectangular multi-tile stamp**; per-tile
  `Solid`/`Tag` metadata editing writes through to the tileset; a **New
  Tileset** flow slices a texture with a live preview.
- **Tools** (all mutating through one door, `Stroke::Apply(cell, id)`):
  Brush `B` (stamp, N×N size, Bresenham line-stepping between mouse samples),
  Erase `E` (**RMB always erases**), Rect `R` (marquee, stamp tiled across),
  Fill `F` (scanline flood, fenced to painted bounds ±1 chunk), Picker `I` /
  Alt+click (eyedrops tile or rect-drags a stamp).
- **Grid + cursor overlay:** grid over painted-chunk bounds ±2 chunks at 15 %
  alpha; hovered cell/stamp outlined in brand orange; live rect marquee.
- **Scoped undo:** one mouse-down→up = one `TileStroke`
  (`cell, before, after` triples, deduped per cell); a capped stack owned by
  the paint tool; Ctrl+Z routes there only while paint mode is active.
- **Persistence:** scene save also writes dirty `.jtilemap`s; the dirty marker
  accounts for unsaved tile edits.

### Step 47 — Physics, scripting, Tiled import

- **Collision generation:** greedy meshing merges solid tiles into maximal
  rectangles per chunk (10 k tiles → dozens of rects; fixes fixture-count and
  ghost-collision seams at once); per-chunk rebuild on runtime edits; a debug
  overlay draws the merged rects.
- **C# API:** `GetTile/SetTile/GetTileTag/WorldToCell/CellToWorld/Clear`.
- **Tiled import:** `.tsx` → tileset, `.tmx` CSV/uncompressed-base64 layers →
  one map entity per layer; orthogonal only; unsupported features are
  **skipped loudly** with listed warnings; GID flip bits masked with a count.

## 2. Parity matrix — what the web editor duplicates, adapts, or defers

| Engine feature (step) | Web plan | Status |
|---|---|---|
| Tileset asset: atlas + slicing + sparse per-tile `Solid`/`Tag` (45.1) | `tilesets` table over an uploaded image asset; identical fields; per-tile metadata JSON | **Duplicate** (doc 03) |
| Nearest-neighbor, no-bleed sampling (45.1) | `imageSmoothingEnabled = false`; integer-aligned chunk blits; source rects sliced with the same math (half-texel inset not needed for Canvas2D `drawImage` with exact rects, but the slicing function is shared and inset-capable) | **Adapt** (doc 02 §4) |
| One component = one layer; multi-layer = Z-ordered entities (45.2) | One **tilemap document** holds ordered `tilemap_layers`; one tileset per layer; exports as one engine entity per layer | **Adapt** (docs 03, 06) |
| `TileID` u32, 0 = empty, `atlasIndex = id − 1` (45.2) | Identical | **Duplicate** |
| 32×32 sparse chunks, floor-div chunk coords, `UsedCount`, dirty flags (45.2) | Identical in-memory model in TS; chunks are also the persistence and autosave unit | **Duplicate** (docs 02, 03) |
| `.jtilemap` RLE(u32 pairs)+base64 (45.3) | The **same encoding** is the DB chunk payload and the wire format — export is transcoding | **Duplicate** (docs 03, 06) |
| Chunk-mesh rendering, rebuild-on-dirty, per-chunk culling (45.4) | Chunk → **offscreen canvas** cache, redraw-on-dirty, visible-range culling | **Adapt** (doc 02 §3) |
| Paint mode entry/exit semantics (46.1) | Weaker problem: the tilemap editor is a **separate view**, so "paint mode" is the whole view; tool `None`/pan is the neutral state | **Adapt** (doc 05 §1) |
| Ray → plane → inverse-transform cell math (46.1) | Screen → world is a 2D affine (pan/zoom) invert; no rotated maps in the web editor v1 | **Simplify** (doc 02 §5) |
| Tile Palette panel: combo, atlas grid, stamps, metadata strip, New Tileset (46.2) | Same panel, React + Tabler, `AssetPicker` for the texture | **Duplicate** (doc 05 §2–3) |
| Brush/Erase/Rect/Fill/Picker + keys + RMB-erase + Bresenham + fenced fill (46.3) | Identical behaviors and shortcuts | **Duplicate** (doc 05 §4) |
| Grid/cursor overlays (46.3) | Identical, drawn in a screen-space overlay pass | **Duplicate** (doc 05 §6) |
| Stroke-scoped undo (46.4) | Identical `TileStroke` model; per-map stack; **also feeds autosave** (the graph editor's command pattern, doc `../graph/03` §1) | **Duplicate+** (doc 05 §5) |
| Scene-save integration, dirty marker (46.5) | Chunk-granular autosave with version stamp — the app's standard autosave contract | **Adapt** (doc 03 §4) |
| Greedy-meshed Box2D fixtures (47.1) | No physics runtime in the browser — but the **greedy mesher is ported to TS** as a collision *preview overlay* (validates what the engine will generate) and its output ships in exports as a hint | **Adapt** (doc 05 §7, doc 06 §2) |
| C# scripting API (47.2) | Out of scope for the web editor (authoring tool, not runtime). The play-mode simulator may later read tile tags — deferred | **Defer** |
| Runtime `SetTile` during play (47.2) | Deferred with play-mode integration | **Defer** |
| Tiled `.tmx`/`.tsx` import at the 47.4 scope | Same scope, same loud-skip list, implemented server-side | **Duplicate** (doc 06 §4) |
| — (no engine equivalent) | Graph integration: `tilemap_ref` node, thumbnails, breadcrumbs, where-used | **New** (doc 04) |
| — (no engine equivalent) | `.dgpack` bundle inclusion; multi-user ownership via projects | **New** (docs 04 §5, 03) |

## 3. The format-compatibility contract (now co-owned — the engine committed its half)

These decisions are **frozen** because both sides depend on them; breaking any
one turns "export = transcoding" into "export = migration". Originally this was
*our* contract derived from the engine plan; as of engine steps 45–49 it is a
**two-sided, co-owned** contract — the engine plan now pins the same bytes from
its side (`plan/steps/45_tilemap_runtime.md` §45.5 restates points 1–7 verbatim
and vendors a copy of our shared RLE fixture into its own CI). A change is a
**versioned format event on both sides at once**, never a refactor on either.

1. `TileID` is u32; `0` = empty; `atlasIndex = id − 1`.
2. Chunks are 32×32 tiles; chunk coords are floor-division of cell coords
   (arithmetic shift `cell >> 5`, correct for negatives).
3. Chunk payload encoding is RLE `(count, id)` u32 **little-endian** pairs →
   base64, **row-major** within the chunk (`index = y*32 + x`); every
   `count ≥ 1`; run counts sum to exactly 1024. Identical bytes in the DB, on
   the wire, in `.dgpack`, and in `.jtilemap`. `used_count` is always
   recomputed on decode, never trusted (doc 03 §6; engine 45.3).
4. Cell coordinates are **y-up** (engine convention). The web canvas flips Y
   at render time (doc 02 §5); stored data never flips. An importer does **no**
   coordinate transform.
5. One tileset per layer. A layer exports as exactly one engine
   `TilemapComponent` entity; layer order = Z order.
6. The `.jtileset` YAML shape (engine 45.1) — field names, nesting, defaults;
   `Id` is the raw **atlas index** (not our `−1`-offset `TileID`); `Tiles`
   sparse, ascending by `Id`. Our `TilesetYamlCodec` and the engine's
   `Tileset::Load` are the two implementations.
7. The `.jtilemap` YAML shape (engine 45.3) — `Version: 1`,
   `Chunks: [{ At: [cx, cy], Data }]`, chunks sorted `(cy, cx)`.
8. The `joystick-tilemap/1` **manifest** (doc 06 §2) — the assembly
   instructions the engine's importer reads (engine 47.6); ours to version,
   but its `format` string and `exported_from` identity block are stable.
9. **Cross-app identity lives in the engine's `.jmeta` `Origin` block** (engine
   48.6, indexed in 49.6): `{ App, ProjectUuid, ObjectUuid, Checksum }`, where
   `ObjectUuid` is our `tileset_uuid`/`tilemap_uuid`/`asset_uuid`. This is the
   mapping key that makes re-import update-in-place instead of forking (doc 06
   §3, doc 08 §4). We assign the uuid; the engine binds to it and never invents
   its own for a synced object.

A CI round-trip test enforces #3: decode → re-encode any saved chunk ⇒
byte-identical (mirrors the engine's 45.6 "RLE round-trip" check). The **shared
fixture** (`rle-fixtures.json` — an array of `{ tiles: u32[1024], base64 }`
cases incl. all-empty, single-run, alternating, negative/large ids) is asserted
by three codecs: our Vitest `rle.ts` test, our Pest `RleCodecTest`, and the
engine's `TilemapSerializer` test (which vendors a copy — see engine 45.5's
provenance note). Keeping the copies identical is a manual sync step until a
shared package exists; the fixture is the anti-drift tripwire for all three.

## 4. Scope cuts, recorded up front (engine step 45 style)

- **No rotated/scaled map canvas in v1.** The engine paints on arbitrarily
  transformed entities via ray math; the web editor is a flat 2D document.
  Transform lives on the engine entity after export.
- **Axis-aligned collision preview only** — same cut as 47.1 (no slopes).
- **No animation/auto-tiling in v1.** Auto-tile rules (Wang/blob) are the most
  requested follow-up in every tile editor; the data model reserves a
  `tile_meta` JSON slot per tile so rules can land without a migration
  (doc 03 §2).
- **Web play-mode does not simulate tile physics.** The graph's play mode
  (doc `../graph/06` §5) may later *display* a referenced map as a scene
  backdrop; that is a rendering feature, not physics.
