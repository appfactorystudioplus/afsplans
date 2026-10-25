# 03 — Data Model & API

Extends the graph schema (`../graph/03` §2) with four tables. Same conventions:
every content table gets `id` (bigint PK) + `uuid` (stable public identifier,
survives export/import, what game code references); route-model binding by
uuid; `ProjectPolicy` everywhere; FormRequests + schema validation.

## 1. Ownership model: project-level resources, not node children

Tile maps and tilesets belong to the **project**, not to a graph node. A
`tilemap_ref` node (doc 04) merely points at one:

- Multiple nodes (in any graph, at any nesting depth) can reference the same
  map — "the Cave" appears in three scenes, edits show everywhere.
- Deleting a node never deletes a map; deleting a map with references gets the
  asset-style safe-delete flow (usage list, explicit confirm, referencing
  nodes render a "missing tilemap" placeholder — `../graph/05` §2 pattern).
- Tilesets are shared across maps the same way (many layers, one dungeon
  tileset).

## 2. Database schema

```
tilesets
  id, uuid, project_id → projects (cascade), name,
  texture_asset_id → assets (RESTRICT — the atlas texture; deleting the asset
                             follows the asset safe-delete flow, which lists
                             the tileset as a usage),
  tile_width SMALLINT, tile_height SMALLINT,      -- pixels in the atlas
  spacing SMALLINT DEFAULT 0, margin SMALLINT DEFAULT 0,
  columns SMALLINT,        -- derived from texture width at save time, cached
                           -- (needed server-side for validation + export
                           --  without opening the image)
  tile_count INT,          -- derived, cached (columns × rows)
  tile_meta JSON,          -- sparse, keyed by atlas index (string keys):
                           -- {"12": {"solid": true}, "13": {"solid": true,
                           --   "tag": "spikes"}}
                           -- extra keys allowed (future: autotile rules,
                           --  animation frames) — doc 01 §4
  created_at, updated_at, deleted_at (soft)
  INDEX (project_id)

tilemaps                   -- one map document (what a tilemap_ref node opens)
  id, uuid, project_id → projects (cascade), name,
  cell_size DOUBLE DEFAULT 1.0,     -- world units per tile (engine 45.2);
                                    -- pure export metadata, invisible on canvas
  settings JSON,           -- background color, grid prefs, per-map defaults
  viewport JSON,           -- {x, y, zoom} camera memory (graphs.viewport parity)
  thumbnail_asset_id → assets NULL (null on delete),   -- §5
  version INT DEFAULT 0,   -- optimistic lock for chunk autosave (§4)
  created_at, updated_at, deleted_at (soft)
  INDEX (project_id)

tilemap_layers
  id, uuid, tilemap_id → tilemaps (cascade),
  tileset_id → tilesets (RESTRICT — a layer always has a tileset; deleting a
                          tileset requires reassigning or deleting its layers),
  name, sort_order INT,    -- bottom-to-top draw order = engine Z order
  visible BOOL DEFAULT 1, locked BOOL DEFAULT 0,
  opacity DOUBLE DEFAULT 1.0, tint VARCHAR NULL,       -- engine Tint parity
  generate_colliders BOOL DEFAULT 0,                   -- engine 47.1 flag
  physics JSON NULL,       -- {friction, restitution, density} (engine 47.3's
                           -- three floats) — export metadata only
  created_at, updated_at
  INDEX (tilemap_id), UNIQUE (tilemap_id, sort_order) deferred/managed in service

tilemap_chunks             -- the payload table; one row = one 32×32 chunk
  id, layer_id → tilemap_layers (cascade),
  cx INT, cy INT,          -- chunk coords (floor-div of cell coords, y-up)
  data TEXT,               -- base64(RLE (count,id) u32 LE pairs) — the frozen
                           -- encoding (doc 01 §3); identical bytes to .jtilemap
  used_count SMALLINT,     -- denormalized from decode; 0 never stored — empty
                           -- chunks are DELETEd (sparse invariant, engine 45.2)
  updated_at
  UNIQUE (layer_id, cx, cy), INDEX (layer_id)
```

Notes:

- **No per-cell rows, ever.** A row per tile is the classic web-tilemap
  mistake (a 512×512 map = 262 k rows). The chunk row mirrors the engine's
  serialization unit exactly; a typical painted chunk RLE-encodes to tens of
  bytes.
- `tile_meta` uses string keys because JSON objects require them; the TS and
  PHP codecs treat keys as u32 atlas indices.
- Reference scanning: node `data` payloads point at maps via the
  `tilemap_uuid` key (doc 04 §2), which plugs into the existing
  `…_uuid` usage-scan convention (`../graph/03` §2 notes).

## 3. API (REST, extends `routes/api.php`)

```
# Tilesets
GET    /api/projects/{project}/tilesets            list (catalog; metadata only)
POST   /api/projects/{project}/tilesets            create {name, texture_asset_uuid,
                                                    tile_width, tile_height, spacing,
                                                    margin} → server opens the image
                                                    once to derive columns/tile_count
PATCH  /api/tilesets/{tileset}                     rename; re-slice (same fields;
                                                    re-derives; bumps updated_at —
                                                    clients re-render affected maps)
PATCH  /api/tilesets/{tileset}/tile-meta           partial merge: {"12": {...},
                                                    "13": null} (null deletes a key)
DELETE /api/tilesets/{tileset}                     409 + layer/usage list if referenced

# Tilemaps
GET    /api/projects/{project}/tilemaps            catalog: uuid, name, layer count,
                                                    painted-tile count, thumbnail url
POST   /api/projects/{project}/tilemaps            create {name, cell_size?} — also
                                                    creates layer "Layer 1" if a
                                                    default tileset uuid is passed
PATCH  /api/tilemaps/{tilemap}                     rename, cell_size, settings, viewport
DELETE /api/tilemaps/{tilemap}                     409 + referencing-node list unless
                                                    force=1 (then nodes placeholder)
GET    /api/tilemaps/{tilemap}                     document shell: map row + layers +
                                                    per-layer chunk INDEX (coords +
                                                    used_count only, no data) +
                                                    referenced tilesets inline
GET    /api/tilemaps/{tilemap}/chunks              ?layer={uuid}&bbox=cx0,cy0,cx1,cy1
                                                    → chunk data rows (range-limited;
                                                    the surface fetches what the
                                                    camera can see + margin, then
                                                    widens in the background)
PUT    /api/tilemaps/{tilemap}/chunks              autosave flush (§4)

# Layers
POST   /api/tilemaps/{tilemap}/layers              {name, tileset_uuid, above?: uuid}
PATCH  /api/tilemap-layers/{layer}                 name/visible/locked/opacity/tint/
                                                    generate_colliders/physics/tileset
POST   /api/tilemaps/{tilemap}/layers/reorder      {ordered_uuids: [...]}
DELETE /api/tilemap-layers/{layer}                 confirm client-side w/ tile count

# Interchange (doc 06)
POST   /api/tilemaps/{tilemap}/export              {format: 'joystick'|'json'|'png'}
                                                    → queued job → signed URL
POST   /api/projects/{project}/tilemaps/import     multipart: .zip of .jtilemap/
                                                    .jtileset(+textures), or .tmx/.tsx
                                                    → queued job → report (loud skips)
GET    /api/tilemap-jobs/{id}                      status/report/download (shared
                                                    export-job machinery, graph 03 §3)
```

## 4. Autosave: chunk-granular, stroke-driven

The graph editor autosaves command batches; the tilemap editor autosaves
**dirty chunks** — the chunk is already the edit-dirty unit (engine 45.2), so
the persistence grain falls out for free:

1. Every stroke's `apply()` marks touched chunks dirty in
   `autosave.ts` (`Set<layerUuid:cx,cy>`); layer/map property edits mark a
   `meta` dirty flag.
2. Debounce 1–2 s after the last mutation →
   `PUT /api/tilemaps/{t}/chunks` with:
   ```json
   { "base_version": 41,
     "chunks": [ {"layer": "…", "cx": 3, "cy": -1, "data": "AQAAAA…"},
                 {"layer": "…", "cx": 4, "cy": -1, "data": null} ],
     "meta": { "viewport": {…} } }
   ```
   `data: null` = chunk became empty → DELETE row (sparse invariant).
3. Server: validate (§6), upsert/delete in one transaction, `version++`,
   return `{version: 42}`.
4. **409 on version mismatch** (someone else saved): refetch the chunk index,
   re-pull conflicting chunks, toast + merge-or-fork prompt — the same
   contract as graph autosave (`../graph/03` §3), so the toolbar dirty/saved
   indicator, offline retry queue, and eventual phase-8 realtime upgrade are
   shared code.
5. Navigating away (view switch, tab close via `visibilitychange`) force-flushes.

Undo interplay: undoing a saved stroke just dirties the same chunks again —
the server stores state, not history, so undo needs no server support
(engine 46.4 parity: the stack is client-side and session-scoped).

## 5. Thumbnails

The graph node preview and the catalogs need map thumbnails. Server-side
rendering would mean a PHP tile renderer — waste. Instead the **client renders
the thumbnail**: on autosave flush (throttled to ≥ 10 s apart), the surface
composites visible layers of the painted bounds into a ≤ 512 px offscreen
canvas → `canvas.toBlob('image/png')` → upload as an internal asset →
`PATCH tilemaps.thumbnail_asset_id`. Consumers (node component, catalog cards,
`.dgpack` preview) just show an asset image. Staleness is acceptable by design
(a thumbnail is a hint, not truth).

## 6. Server-side validation (chunks are untrusted input)

Per chunk on `PUT`: base64 decodes; byte length ≤ 8 KB and even multiple of 8;
RLE counts sum to exactly 1024; `used_count` recomputed server-side (never
trusted); tile ids are **not** ranged against the tileset (a tileset re-slice
may legitimately orphan ids — the renderer draws a missing-tile placeholder,
engine-parity with a missing atlas entry; the problems panel can flag them,
doc 04 §6). Request caps: ≤ 256 chunks per flush, standard rate limits.
`tile_meta` PATCH validates against a JSON Schema (solid: bool, tag: string
≤ 64 chars, unknown keys allowed but size-capped).

Pest coverage per endpoint, plus: RLE round-trip property test shared with the
TS suite via fixture files (same fixtures assert both codecs — the doc 01 §3
contract test); policy tests (non-member 403 on every route); the
delete/409/force flows.
