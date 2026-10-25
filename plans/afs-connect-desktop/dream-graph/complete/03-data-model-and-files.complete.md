# 03-data-model-and-files — Completion

**Completed:** 2026-07-31

**Status:** Complete. The `.dgproj` folder layout, manifest, graph files, tilemap documents
and chunk files, tileset catalog, cloud-sync hooks, and the `.dgpack` bundle format all
shipped. Delivered across plan phases 1, 3, 5, and 6.

## Summary

### Project folder layout (phase 1)

`src/main/dreamGraph/store.ts` creates and manages the on-disk project:

```
~/Documents/afsfiles/dream-graph/projects/{Name}.dgproj/
  manifest.json
  catalogs/     (empty JSON arrays seeded)
  assets/
  graphs/       (root graph JSON on create)
  tilemaps/     (empty dir seeded)
```

`DreamGraphStore` implements `listProjects`, `createProject`, `getProject`,
`deleteProject`, and the folder helpers. `manifest.json` gains an `updatedAt` stamp on
saves.

### Graph files (phase 2)

Graphs are stored as `graphs/{uuid}.json`, saved through `dream-graph:save-graph-content`
(nodes plus connections) with camera state kept separately via
`dream-graph:save-viewport`. Nested graphs are created by
`dream-graph:create-child-graph`.

### Catalogs and assets (phase 3)

`importAsset` writes binaries into `assets/` and records them in `catalogs/assets.json`;
`getAssetPath` resolves a uuid to an absolute path so the renderer can build a `file://`
URL. `listCatalog` / `saveCatalog` cover assets, entities, variable-sets, and templates.
Catalog writes are debounced 5s behind a `catalogsDirty` flag, matching the graph autosave
pattern.

### Tilemap documents and chunks (phase 5)

`src/main/dreamGraph/tilemapPersistence.ts` owns tilemap documents, chunks, layers, and
tilesets. `rle.ts` handles chunk decode and `used_count`. `imageSize.ts` reads PNG/JPEG
dimensions and derives the tile grid with the same formula as the web
`TilemapService::deriveGrid`, so a tileset created from an atlas gets matching `columns`
and `tile_count`. Only dirty chunks are written back.

### `.dgpack` bundles (phase 6)

`bundleExport.ts` packs a `.dgproj` folder into a `.dgpack` zip carrying a `dgpack/1`
manifest; `bundleImport.ts` unpacks one into a new `.dgproj`. Exposed on the Projects
screen as **Import .dgpack** plus a per-project **Export** action.

### Cloud sync hooks

The reserved hooks from the plan are in place so the later sync work can attach without
reshaping local storage — see
[../../account-and-cloud-sync/](../../account-and-cloud-sync/).

### Single-writer assumption

Held as designed for offline v1: one desktop process owns the workspace, so no file
locking or merge logic was built.

## Files changed

- `src/main/dreamGraph/store.ts` — project CRUD, catalogs, asset import, tilemap CRUD.
- `src/main/dreamGraph/index.ts` — the full `dream-graph:` IPC surface.
- `src/main/dreamGraph/tilemapPersistence.ts` — documents, chunks, layers, tilesets.
- `src/main/dreamGraph/rle.ts` — chunk decode and `used_count`.
- `src/main/dreamGraph/imageSize.ts` — PNG/JPEG dimensions, `deriveTileGrid`.
- `src/main/dreamGraph/bundleExport.ts`, `bundleImport.ts` — `.dgpack` round-trip.
- `src/renderer/components/dreamGraph/ipc.ts` — typed wrappers for all of the above.
- `src/renderer/components/dreamGraph/graph/util/assetUrl.ts` — IPC → `file://` URL cache.
- `src/renderer/components/dreamGraph/graph/shell/ProjectsView.tsx` — import/export
  controls.

## Verification steps

1. Create a project named **Test Project**; confirm `{Name}.dgproj` appears under the
   projects root with the seeded subfolders.
2. Restart the app — the project is still listed. Delete it — the folder is removed after
   confirmation.
3. Import a PNG under **Catalogs → Assets**, attach it to an Image node, restart — the
   thumbnail still renders from `file://`.
4. Paint a tilemap, exit, restart — tiles persist.
5. Export a project as `.dgpack`, import it into a fresh projects folder — graph and
   tilemaps open intact.

```
npm run build  ✅ success (2026-07-31)
```

## Deviations from plan

- **Web-canonical `project.json` was not produced.** The plan's mapping of web DB tables
  to files anticipated a desktop bundle a member could import on the web. `.dgpack` wraps
  the raw `.dgproj` folder instead, so the web round-trip remains a stretch goal.

## Follow-up work

- **Migration strategy** — the plan has a section for it; nothing versioned or migrating
  was built, since `dgpack/1` and the manifest are still on their first revision.
- **`sync.json` / cloud fields** are reserved but unwritten until the sync phase.
