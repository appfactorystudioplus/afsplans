# 05 — Tilemap Subsystem

The **tilemap editor** is the largest port surface after the story graph. On the
web it is a separate full-screen view (`TilemapView`) backed by canvas rendering
and chunk-based persistence.

## What “tiled” means in Dream Graph

**Not** a port of [Tiled Map Editor](https://www.mapeditor.org/) the desktop app.

Dream Graph includes a **custom integrated tilemap subsystem**:

- **Tilesets** — texture atlas + per-tile metadata + autotile terrain rules
- **Layers** — each references one tileset, ordered stack
- **Sparse chunks** — 32×32 tile regions stored as RLE-encoded tile ID arrays
- **Tools** — brush, erase, rectangle, fill, picker, autotile, pan
- **Undo/redo** — stroke-based stack (client-only today)
- **Engine interchange** — export/import `joystick-tilemap/1` zip (`.jtileset`,
  `.jtilemap`, manifest, RLE bytes verbatim)

The web tilemap editor can **import** engine/Tiled interchange zips but maintains
its own document model in MySQL.

## Architecture (preserve in desktop)

```
TilemapView.tsx          React shell — toolbar, panels, layout
  └── <canvas>           TilemapSurface.ts — imperative render loop
        └── ChunkRenderer + greedy mesh
tilemapStore.ts          Zustand — doc, layers, flush scheduler
LayerTiles / chunk.ts    Sparse chunk model
rle.ts                   Encode/decode — MUST match PHP RleCodec
tilemapClient.ts         → LocalAdapter save/load
```

**Do not rewrite TilemapSurface in React** — the web split is intentional for
performance.

## Navigation from graph

1. User selects `tilemap_ref` node on graph
2. Double-click or Open → `tilemapNav.open(tilemapUuid, fromNode)`
3. `EditorShell` swaps to `TilemapView`
4. On back: `tilemapStore.flush()` then return to graph

Desktop must preserve `tilemapNav` Zustand store behavior.

## Persistence flow (offline)

Mirror web `tilemapStore.flush()`:

1. Collect chunks where `saveDirty === true`
2. RLE-encode each to base64
3. IPC `dream-graph:save-tilemap-chunks` with:
   ```json
   {
     "baseVersion": 41,
     "chunks": [{ "layer": "uuid", "cx": 0, "cy": 0, "data": "…" }],
     "meta": { "viewport": { "x", "y", "zoom" } }
   }
   ```
4. Main process:
   - Validate `baseVersion` against `document.json` (increment; 409 only when sync enabled)
   - Write chunk files
   - Update `document.json` version + viewport

Debounce: **1.5 seconds** after last paint (match web).

## Tooling parity checklist

| Tool | Web file | Phase |
|------|----------|-------|
| Brush | `tools/brush.ts` | 5a |
| Erase | `tools/erase.ts` | 5a |
| Rect fill | `tools/rect.ts` | 5b |
| Flood fill | `tools/fill.ts` | 5b |
| Picker | `tools/picker.ts` | 5a |
| Autotile | `autotile.ts` + rules panel | 5c |
| Pan | `tools/pan.ts` | 5a |
| Layer panel | `LayerPanel.tsx` | 5b |
| Tile palette | `TilePalette.tsx` | 5a |
| Minimap | `Minimap.tsx` | 5d |
| Map inspector | `MapInspector.tsx` | 5b |

## Tileset creation

1. Import PNG or pick from project assets
2. Slice grid → populate `tile_meta` (solid, tags, animation frames)
3. Autotile rules editor (terrain → NESW bitmask → tile candidates)

Port `NewTilesetDialog` and meta editor panels.

## Autotile

`autotile.ts` reads neighbor tiles, matches terrain rules, selects tile index.
Rules stored on tileset — port `AutotilePanel.tsx` and PATCH-equivalent local save.

## Export to engine

Port `TilemapExportService::exportJoystickBytes()` logic to main process:

Output zip:

```
manifest.json
layers/
  {layerName}.jtilemap
tilesets/
  {name}.jtileset
textures/
  atlas.png
```

RLE bytes must be **bit-identical** to web export for same input (Phase 6 test).

## Import from engine

Port `TilemapImportController` flow:

- Accept `joystick-tilemap/1` zip via open dialog
- Create or update tilemap document + chunks locally
- Optionally set `Origin`-style metadata for future engine sync

## Engine live sync (future — see account-and-cloud-sync plan)

When online + token configured:

| Direction | Endpoint | Desktop role |
|-----------|----------|--------------|
| Pull | `GET /api/tilemaps/{uuid}/export?since_version=N` | Poll or SSE client |
| Push | `POST /api/tilemaps/{uuid}/import` | After local flush |
| Token | `POST /api/projects/{id}/tilemap-sync-tokens` | UI in project settings |

Conflict (`409`): offer merge/fork UI (port plan step 46 §46.6 from Joystick).

Reference: `JoystickEngine_v2/plan/dream-graph-sync-system.md` and
`apps/www/_docs/tilemap/08-live-sync-protocol.md`.

**Offline v1:** export zip manually; no bearer token UI.

## Tilemap acceptance tests (Phase 5 complete)

1. Create tilemap from graph `tilemap_ref` node.
2. Paint with brush, erase, undo/redo strokes.
3. Add second layer, reorder, toggle visibility.
4. Save, restart app, tiles intact.
5. Export `joystick-tilemap/1` zip; import into Joystick engine manually.
6. Autotile rule: paint grass, verify edge tiles auto-select.
7. Large map (100×100 chunks sparse) — flush under 200ms per debounced save.

## Performance notes

- `TilemapSurface` render tick — only dirty chunks redraw
- IPC chunk batch — cap payload size; split if >4MB
- Main process: avoid reading all chunks on every flush; write dirty only
