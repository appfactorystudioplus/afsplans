# 03 — Data Model and On-Disk Files

Offline projects must be **self-contained**, **diff-friendly where practical**, and
**forward-compatible** with cloud sync ([account-and-cloud-sync plan](../account-and-cloud-sync/README.md)).

## Design principles

1. **UUIDs everywhere** — match web schema (`graph_projects.uuid`, node UUIDs, etc.)
2. **One project = one folder** — easy backup, zip, sync later
3. **Atomic saves** — write temp file + rename (same as Notes Pro)
4. **Version fields preserved** — tilemap `version`, project `updated_at` for future 409 handling
5. **Assets by reference** — binary files on disk, manifest holds metadata
6. **Optional `cloud` block** — reserved for sync metadata (null in offline v1)

## Project folder layout

```
~/Documents/afsfiles/dream-graph/projects/
  MyStory.dgproj/
    manifest.json              # project root record
    catalogs/
      assets.json
      entities.json
      variable-sets.json
      templates.json
      texts.json
    assets/                      # binary blobs
      {assetUuid}.png
      {assetUuid}.ogg
    graphs/
      {graphUuid}.json           # nodes + connections + viewport
    tilemaps/
      {tilemapUuid}/
        document.json            # tilemap + layers metadata
        tilesets.json            # or reference project tilesets
        chunks/
          {layerUuid}/
            {cx}_{cy}.rle        # raw base64 or binary RLE file
    sync.json                    # (future cloud sync) cloud id, last synced version — optional
```

Alternative: single `project.json` for small projects — **defer** until perf requires split.

## manifest.json (project root)

Maps to `graph_projects` row:

```json
{
  "format": "dreamgraph-desktop/1",
  "uuid": "9f3c1e7a-…",
  "name": "My Story",
  "rootGraphUuid": "a1b2…",
  "settings": {},
  "createdAt": "2026-07-31T12:00:00Z",
  "updatedAt": "2026-07-31T18:00:00Z",
  "cloud": null
}
```

`format` version enables migrations. Bump when breaking on-disk layout.

## graphs/{uuid}.json

Maps to `GraphController::saveContent` payload:

```json
{
  "uuid": "…",
  "name": "Chapter 1",
  "parentNodeUuid": null,
  "viewport": { "x": 0, "y": 0, "zoom": 1 },
  "nodes": [
    {
      "uuid": "…",
      "type": "fragment",
      "x": 120, "y": 80, "width": 200, "height": 100,
      "data": { "text": "Hello", "parent_uuid": null }
    }
  ],
  "connections": [
    {
      "uuid": "…",
      "sourceNodeUuid": "…",
      "targetNodeUuid": "…",
      "sourcePin": 0,
      "targetPin": 0
    }
  ]
}
```

**Save semantics:** replace entire file on each autosave (matches web full-state sync).
Entry/Exit nodes on nested graphs: preserve on load; seed if missing (port `GraphService` logic).

## Tilemap document.json

Maps to tilemap + layers:

```json
{
  "uuid": "…",
  "name": "Level 1",
  "version": 42,
  "cellSize": 32,
  "viewport": { "x": 0, "y": 0, "zoom": 1 },
  "settings": {},
  "layers": [
    {
      "uuid": "…",
      "name": "Ground",
      "tilesetUuid": "…",
      "order": 0,
      "visible": true,
      "opacity": 1
    }
  ]
}
```

## Chunk files

Each `{cx}_{cy}.rle` contains base64 RLE string **or** raw bytes — pick one in
Phase 0 and document in `format` spec. Must round-trip with
`resources/js/tilemap/model/rle.ts` and PHP `RleCodec`.

Empty chunk = **file deleted** (sparse invariant from web).

## Tilesets

Project-level `catalogs/tilesets.json` + atlas PNG in `assets/`. Tile meta and
autotile rules inline or sidecar `tilesets/{uuid}.meta.json`.

## Cloud sync hooks (reserved — see account-and-cloud-sync plan)

`sync.json` when linked to web project:

```json
{
  "remoteProjectUuid": "…",
  "remoteBaseUrl": "https://graph.appfactory.studio",
  "lastSyncedAt": "…",
  "lastSyncedVersion": { "tilemapUuid": 42 },
  "conflictPolicy": "ask"
}
```

Do **not** store sync bearer tokens in exported `.dgpack` bundles.

## .dgpack import/export

Port web bundle structure from export jobs:

- Import: unzip → validate manifest → copy into `.dgproj/` layout
- Export: zip `.dgproj/` contents with `dreamgraph/1` manifest

Parity testing against web-generated bundles is Phase 6 exit criteria.

## Mapping web DB tables → files

| MySQL table | Offline location |
|-------------|------------------|
| `graph_projects` | `manifest.json` |
| `graph_graphs` | `graphs/{uuid}.json` |
| `graph_nodes` | inside graph JSON |
| `graph_connections` | inside graph JSON |
| `graph_assets` | `catalogs/assets.json` + `assets/*` |
| `graph_tilemaps` | `tilemaps/{uuid}/document.json` |
| `graph_tilemap_layers` | in document.json |
| `graph_tilemap_chunks` | `chunks/...` |
| `graph_tilesets` | catalogs + assets |
| `graph_tilemap_sync_tokens` | **N/A offline** — [engine live sync plan](../account-and-cloud-sync/02-engine-live-sync.md) |

## Migration strategy

`DreamGraphStore.openProject()` checks `manifest.format`:

- Unknown version → show "upgrade required" dialog
- `dreamgraph-desktop/1` → load natively
- Future: importer from web export only

## Single-writer assumption (offline v1)

No 409 conflict UI needed — one desktop instance per project file.
Keep `version` increment on each chunk flush anyway for sync readiness.
