# 06 — Engine Interchange: Export, Import, Tiled, Live Bridge

> **Requirement:** "in the future the tile map editor would be able to connect
> to the game engine and export the tile map to the game engine and vice versa."

Because the storage format clones the engine's decisions (doc 01 §3), the
interchange layer is thin: serialize what we already store into the engine's
file shapes (`.jtileset`/`.jtilemap`, engine steps 45.1/45.3), and parse them
back. Phase one is **file-based** (works today, no engine changes needed);
phase two is a **live bridge** over the same serializers.

## 1. Export to JoystickEngine (file-based)

`POST /api/tilemaps/{t}/export {format: 'joystick'}` → queued job → signed
download (shared export-job machinery, `../graph/03` §3). The artifact is a
zip shaped like a drop-in for a Joystick project's `Assets/`:

```
CaveMap.joystick-tilemap.zip
├── manifest.json                      ← what's inside + how to assemble it (§2)
├── Assets/
│   ├── Tilesets/
│   │   └── dungeon.jtileset           ← engine 45.1 YAML, verbatim shape
│   ├── Tilemaps/
│   │   ├── CaveMap.background.jtilemap  ← one .jtilemap PER LAYER
│   │   ├── CaveMap.terrain.jtilemap     (engine: one TilemapComponent = one
│   │   └── CaveMap.foreground.jtilemap   layer — doc 01 §3 contract)
│   └── Textures/
│       └── dungeon_tiles.png          ← the atlas asset's original file
```

### `.jtileset` (exactly engine 45.1)

```yaml
Tileset:
  Texture: "Textures/dungeon_tiles.png"
  TileSize: [16, 16]
  Spacing: 0
  Margin: 0
  Tiles:                     # sparse — only tiles with non-default metadata
    - Id: 12
      Solid: true
    - Id: 13
      Solid: true
      Tag: "spikes"
```

Unknown `tile_meta` keys (future autotile/animation, doc 03 §2) are **omitted**
from `.jtileset` v1 — the engine schema is the contract; nothing engine-side
should ever need to skip unknown fields.

### `.jtilemap` (exactly engine 45.3)

```yaml
Tilemap:
  Version: 1
  Chunks:
    - At: [0, 0]
      Data: "AQAAAAAAAAAOAAAADQAAAA…"
    - At: [1, 0]
      Data: "…"
```

`Data` is copied **byte-for-byte from `tilemap_chunks.data`** — no re-encode,
which is the whole payoff of freezing the encoding (doc 01 §3). Chunks sort by
`(cy, cx)` for deterministic output. Cell coordinates are already y-up; no
transform of any kind happens at export.

## 2. `manifest.json` — the assembly instructions

The engine's `.jscene` stores per-entity component data the web app doesn't
own (transforms, other components), so the export does **not** fabricate a
scene file. Instead the manifest tells an importer (human or tool) how to
assemble entities:

```jsonc
{
  "format": "joystick-tilemap/1",
  "exported_from": { "app": "dreamgraph", "project_uuid": "…", "tilemap_uuid": "…",
                     "version": 42, "exported_at": "2026-07-15T…" },
  "tilemap": { "name": "CaveMap", "cell_size": 1.0 },
  "layers": [                                  // bottom-to-top; suggested Z = index
    { "name": "background",
      "map": "Assets/Tilemaps/CaveMap.background.jtilemap",
      "tileset": "Assets/Tilesets/dungeon.jtileset",
      "tileset_uuid": "…",                     // identity: engine binds via .jmeta Origin (§6)
      "tint": [1, 1, 1, 1], "generate_colliders": false, "physics": null },
    { "name": "terrain",
      "map": "Assets/Tilemaps/CaveMap.terrain.jtilemap",
      "tileset": "Assets/Tilesets/dungeon.jtileset",
      "tileset_uuid": "…",
      "tint": [1, 1, 1, 1], "generate_colliders": true,
      "physics": { "friction": 0.5, "restitution": 0.0, "density": 1.0 },
      "collision_rects": [                     // §5.7 greedy-mesh output, per chunk —
        { "chunk": [0, 0],                     // a HINT; engine may re-mesh and ignore
          "rects": [[0, 0, 32, 4], [10, 4, 3, 9]] }   // x, y, w, h in cells
      ] }
  ]
}
```

`exported_from.tilemap_uuid` and each layer's `tileset_uuid` are the identity
spine (§6, doc 08 §4): the engine records them in each asset's `.jmeta`
`Origin` block on import, so a re-import updates in place and an engine→web
export addresses the right web record instead of forking. Texture asset uuids
ride the same way via the `asset_index` when a bundle carries them.

Consumption today: unzip into the game project; the files are engine-native,
and creating one entity per layer (drag the `.jtilemap`s in, Z from layer
order) is a one-minute manual step. A future engine editor menu
(**File → Import → Dream Graph Tilemap…**, a natural engine-plan follow-up
step) reads the manifest and does it in one click. `collision_rects` is
advisory — the engine's own greedy mesher (47.1) remains the authority.

Also available from the same endpoint: `format: 'json'` (the canonical
dreamgraph JSON slice — the doc 04 §5 shape, for Unity/Godot/custom pipelines)
and `format: 'png'` (flattened visual, for docs/review).

## 3. Import from JoystickEngine (vice versa)

`POST /api/projects/{p}/tilemaps/import` accepts a zip containing any mix of
`.jtileset` / `.jtilemap` / textures (typically the export zip above, or files
hand-copied from a game repo). Queued job, dry-run-able, with a **loud-skip
report** (engine 47.4's ethos):

1. Parse `.jtileset`s (YAML): resolve `Texture` against files in the zip →
   upload as assets (checksum-deduped against the project, `../graph/09` §3);
   missing textures = warning + tileset skipped.
2. Create/update tilesets: if a tileset with the same name + checksum-equal
   texture + identical slicing exists, **reuse it** (re-import is idempotent);
   else create. `Tiles` metadata → `tile_meta`.
3. Parse `.jtilemap`s: chunk `Data` strings are **stored verbatim** after
   validation (decode → counts sum to 1024 → recompute `used_count`;
   doc 03 §6 — never trust input, even our own format).
4. Assembly: with a `manifest.json` present, layers/order/physics come from
   it; bare `.jtilemap`s each become a single-layer map named from the
   filename, tileset chosen in the import dialog if ambiguous.
5. Import **into an existing map** (update-in-place by `tilemap_uuid` match
   from the manifest) is offered when it matches — this closes the loop:
   export → edit in engine (paint in the engine editor) → import back →
   the web map updates, version-stamped like any save.

## 4. Tiled import (engine 47.4's scope, server-side)

Same endpoint, `.tmx`/`.tsx` files. Scope copied verbatim from 47.4 — support
the common core, **skip the rest loudly** (per-item warnings in the report):

- `.tsx` tileset → tileset row (image → asset, tile size/spacing/margin;
  per-tile `solid`/`class` properties → `tile_meta.solid`/`tag`).
- `.tmx` orthogonal maps, **CSV or uncompressed base64** tile layers → one
  layer per Tiled layer, order preserved.
- Tiled's infinite-map `<chunk>` elements map onto our chunks (Tiled chunks
  are 16×16 by default — re-bucketed into 32×32 at import).
- Skipped with a listed warning: object layers, compressed layer data
  (zlib/zstd), flipped/rotated tile flags (top 3 GID bits masked, one warning
  with a count), isometric/hex orientation, image layers, external `.tsx`
  referenced but not included in the upload.

PHP implementation: `ext-simplexml` (no new dependency — pugixml's role in the
engine). GID → TileID mapping: Tiled GIDs are 1-based per tileset `firstgid`;
ours are `atlasIndex + 1` — one subtraction per tile, then RLE-encode into
chunks. A fixture `.tmx` set (CSV + base64 variants, engine 47.5's check)
lives in the Pest suite.

## 5. Round-trip guarantees (CI-enforced)

1. **Chunk codec:** decode → encode any chunk ⇒ byte-identical (shared
   fixtures across Vitest + Pest, doc 03 §6).
2. **Engine round trip:** export → import into a fresh project → export again
   ⇒ identical zips modulo manifest timestamps/uuids (mirrors `.dgpack`'s
   guarantee, `../graph/09` §5).
3. **Version stability:** `joystick-tilemap/1` never mutates; breaking changes
   mint `/2` with importers kept for all past versions.

## 6. The live bridge — committed both sides; full protocol in doc 08

> **Status change.** This was a "design sketch, not commitment." It is now a
> **committed, two-sided** feature: the engine plan folds its half into
> `plan/steps/47_tilemap_physics_scripting.md` §47.8 (riding its step-40 editor
> bridge and step-49.5 asset refresh), and the complete bidirectional wire
> protocol — endpoints, `since_version`/`base_version`/304/409 semantics,
> sync-token issuance & scope, SSE-vs-poll, whole-chunk conflict granularity,
> offline queueing, and the identity mapping — is specified in the new
> **[doc 08 — Live Sync Protocol](08-live-sync-protocol.md)**. This section is
> the orientation; doc 08 is the contract.

The engine already builds an **editor bridge** (step 40, `40_editor_bridge.md`)
and a VS Code extension over it (41). The web connection rides the same
architectural slot; both directions travel over the serializers of §§1–3.

- **Transport & auth:** the engine editor holds a per-project **sync token**
  (PAT-style, revocable, scoped to tilemap read/write), issued from the web
  app's project settings. No inbound connection to the user's machine — the
  engine polls or holds an SSE/websocket *to* the web app (Reverb, graph phase 8).
- **Pull (web → engine):** `GET /api/tilemaps/{uuid}/export?format=joystick&since_version=N`
  — 304 if unchanged, else the §1 zip; the engine writes it into `Assets/` and
  its file-watcher hot-reloads (engine 47.8). "Auto-sync on save."
- **Push (engine → web):** the engine's tilemap save hook POSTs changed chunks
  (same RLE strings, chunk-granular) to `POST /api/tilemaps/{uuid}/import` with
  `base_version`; our optimistic-version machinery (doc 03 §4) arbitrates the
  409 exactly as between two browser tabs. An open editor sees it on next
  refetch or realtime push (phase 8).
- **Identity is the engine's `.jmeta` `Origin` block** (engine 48.6, indexed in
  49.6) — `{ App: "dreamgraph", ProjectUuid, ObjectUuid, Checksum }`, where
  `ObjectUuid` is our `tilemap_uuid`/`tileset_uuid`/`asset_uuid`. This
  supersedes the earlier hand-wave about a `.jtilemap.meta` sidecar: the engine
  already gives every imported asset a `.jmeta`, so provenance lives there, and
  it is the mapping key in **both** directions. We assign the uuid; the engine
  binds to it and updates-in-place rather than forking (doc 06 §3, engine 49.6).
- **Still deferred, now with a home:** auth UX beyond paste-a-token, offline
  write queueing, and merge granularity finer than a whole chunk are designed
  in doc 08 and land in phase TM5 (doc 07), once the engine's step-40 bridge is
  real. Until a token is configured, the whole bridge is inert and §§1–4
  (file-based) are the entire interchange.

## 7. Verify

| Check | Expect |
|---|---|
| Export the doc 05 §9 test map → unzip | valid YAML matching engine 45.1/45.3 shapes; `Data` strings grep-identical to the DB rows; manifest lists layers in order |
| Hand-load the exported files in a JoystickEngine test scene (once engine step 45 lands) | renders identically to the web canvas (same tiles, same cells, y-up agreement) |
| Import that zip into a fresh web project | pixel-identical map (canvas diff vs. original); tilesets deduped on second import |
| Engine-edited `.jtilemap` re-imported over the original (update-in-place) | web map updates; version bumps; open editors get the 409/refetch flow |
| Tiled fixture maps (CSV + base64; one with flip flags + an object layer) | layers arrive stacked correctly; skip report lists exactly the unsupported items, once each |
| Malicious zip (path traversal, zip bomb, fake-PNG) | rejected at validation, zero partial writes (shared hardening with `.dgpack`, `../graph/09` §3) |
