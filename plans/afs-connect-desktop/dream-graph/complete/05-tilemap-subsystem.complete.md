# 05-tilemap-subsystem — Completion

**Completed:** 2026-07-31

**Status:** Complete for offline editing and engine interchange. All five sub-phases
(5a–5e) landed, and the engine export/import format shipped with plan phase 6. One
acceptance criterion — the large-sparse-map flush budget — was never benchmarked.

## Summary

### Sub-phases

| Sub-phase | Scope | Result |
|-----------|-------|--------|
| **5a** | `TilemapView`, surface, brush/erase/pan, palette, chunk IPC | ✅ |
| **5b** | Rect/fill/picker tools, layers panel, map inspector | ✅ (web modules + grid derivation) |
| **5c** | Autotile rules editor + terrain paint tool | ✅ |
| **5d** | Minimap, save indicator, collider debug toggle | ✅ |
| **5e** | `tilemap_ref` navigation + graph sidebar Tile Maps menu | ✅ |

### Renderer

`src/renderer/components/dreamGraph/tilemap/` holds `TilemapView.tsx` plus `api`, `model`,
`panels`, `store`, `surface`, and `util`. `TilemapView` carries the full toolbar with
palette, layers, and autotile tabs, the minimap, and the inspector. `TilemapSurface` is an
imperative canvas implementing the rect, fill, picker, and autotile tools. The store
autosaves chunks on a 1.5s debounce. `tilemapClient` talks over IPC and atlases load from
`file://`.

### Navigation from graph (5e)

A **Tile Maps** button in the editor sidebar opens a `TilemapMenu` modal — list, create,
rename, delete, and add a ref node. `tilemapNav.openUuid` swaps the view to `TilemapView`
and flushes chunks on exit. A `tilemap_ref` node opens on double-click, offering a
choose-or-create modal, with local thumbnails.

### Tileset creation and autotile

Creating a tileset from an atlas PNG/JPEG derives `columns` and `tile_count` from the
image dimensions using the same formula as the web `TilemapService::deriveGrid`. Autotile
terrain rules persist in `catalogs/tilesets.json` and drive the terrain paint tool (T).

### Export to and import from the engine (with phase 6)

The `joystick-tilemap/1` interchange shipped in the main process:

| Module | Role |
|--------|------|
| `yamlCodecs.ts` | Tileset/tilemap YAML encode/decode, matching the web PHP `TilesetYamlCodec` / `TilemapYamlCodec` |
| `greedyMesh.ts` | Collision rect hints in the export manifest |
| `tilemapExport.ts` | Builds the zip via `archiver`; JSON export helper |
| `tilemapImport.ts` | Imports a `joystick-tilemap/1` zip in manifest mode via `adm-zip` |

`TilemapExportImportDialog.tsx` drives it from the toolbar, flushing chunks before export
and using native dialogs through the main process. Import runs a dry-run first, showing
the layer plan before committing.

`adm-zip` was chosen over `unzipper` to stop webpack trying to resolve
`@aws-sdk/client-s3`.

## Files changed

- `src/main/dreamGraph/tilemapPersistence.ts`, `rle.ts`, `imageSize.ts` — persistence,
  chunk decode, grid derivation. (Also recorded in
  [03-data-model-and-files.complete.md](03-data-model-and-files.complete.md).)
- `src/main/dreamGraph/yamlCodecs.ts`, `greedyMesh.ts`, `tilemapExport.ts`,
  `tilemapImport.ts` — engine interchange.
- `src/main/dreamGraph/store.ts`, `index.ts` — tilemap CRUD and export/import handlers
  with native dialogs.
- `src/renderer/components/dreamGraph/tilemap/` — `TilemapView.tsx` and the `api`,
  `model`, `panels`, `store`, `surface`, `util` trees.
- `src/renderer/components/dreamGraph/graph/shell/DesktopEditorShell.tsx` — Tile Maps
  button, view swap, `tilemap_ref` handling.
- `package.json` — `js-yaml`, `archiver`, `adm-zip`.

## Verification steps

**Paint and persistence**

1. Open a project → add a **Tile Map** node → create a map → open the editor.
2. Exercise brush (B), erase (E), rectangle (R), fill (F), picker (I), pan (V).
3. **Layers** tab — add a layer, reorder, toggle visibility.
4. Exit, re-open, restart the app — tiles persist.

**Sidebar catalog (5e)**

5. In the graph editor sidebar click **Tile Maps**; create a map, **Add to graph**,
   rename, and delete with confirm.

**Tileset and autotile (5b–5c)**

6. **Catalogs → Assets** — import a tile atlas PNG.
7. Tilemap editor **Palette → New Tileset** — slice the grid; the palette renders.
8. **Autotile** tab — define terrain rules and paint with the autotile tool (T).

**Engine interchange**

9. **Export / Import** in the toolbar → **Export → Joystick zip**; optionally import the
   result in the Joystick engine to confirm interchange.
10. **Import → Joystick zip** — the dry-run shows the layer plan before the import lands.

```
npm run build  ✅ success (2026-07-31, pre-phase-6 tilemap work)
npm run build  ✅ success (2026-07-31, after phase 6)
```

## Not verified

- **Large sparse map flush under 200ms** — an acceptance criterion in the plan, never
  benchmarked. The architecture writes only dirty chunks, which is the design the budget
  assumes, but no measurement was taken.

## Follow-up work

- **Bare-mode tilemap import** — only manifest-mode `joystick-tilemap/1` is supported.
- **Tiled `.tmx` import** — out of scope.
- **Engine live sync** and the **409 conflict merge UI** — tracked in
  [../../account-and-cloud-sync/02-engine-live-sync.md](../../account-and-cloud-sync/02-engine-live-sync.md).
