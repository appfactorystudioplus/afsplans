# 04 — Graph Integration: the Tile Map Node & Navigation

> **Requirement:** "as the user is designing in the dream graph, a node
> references a tile map — which is a whole separate view to create/edit the
> tile map."

The interaction model deliberately clones the **container-node submerge**
pattern (`../graph/04`) that writers already learn for nested graphs: a node
on the canvas stands for a bigger thing; opening it swaps the center view;
breadcrumbs and browser history bring you back. The difference: a child graph
is *owned* by its container node, while a tile map is a **shared project
resource** the node merely references (doc 03 §1) — that difference drives the
create/delete/copy semantics below.

## 1. The `tilemap_ref` node type

One new folder in the NodeTypeRegistry (`../graph/03` §4) —
`model/nodeTypes/tilemapRef/`:

- **schema.ts** (zod → JSON Schema, server-validated like every type):
  ```ts
  { tilemap_uuid: z.string().uuid().nullable(),  // null = not yet assigned
    caption: z.string().default('') }            // optional writer note
  ```
  The key is named `tilemap_uuid` so the existing `…_uuid` usage scanner
  finds references with zero new machinery (`../graph/03` §2 notes).
- **Component.tsx:** Tabler card with a distinct color band and a ⌗ grid icon;
  body shows the map's **thumbnail** (from `thumbnail_asset_id`, doc 03 §5,
  natural-size-capped like the Image node) + map name + a stats line
  ("3 layers · 2 140 tiles" from the cached catalog). Unassigned refs render a
  dashed outline with a **"Choose or create tile map"** affordance (empty-
  container parity). Chrome: **↘ Open button** (`NodeToolbar`) and
  **double-click = open the tilemap view** — the submerge gesture.
- **inspector.ts:** map picker (searchable modal over the tilemaps catalog —
  the `AssetPicker` pattern, promise-based `pickTilemap() → uuid`), caption
  field, **Open in Tile Map Editor** button, and a "used by N other nodes"
  line linking to the usage list.
- **palette.ts:** palette entry "Tile Map" in a "Game" section.
- Pins: 1 in / 1 out by default (it can sit inline in a flow — "enter the
  cave" passes through its map node), and like any node it carries
  display name/color/template.

Node lifecycle vs. the map (the sharing rules, stated once):

| Action on the node | Effect on the tile map |
|---|---|
| Create node | none (ref is null until assigned; "create new map" from the node makes the map *then* assigns it — lazy, like child graphs) |
| Copy/paste/duplicate node | new node references the **same** map (shallow ref — shared resource; contrast: containers deep-copy). "Duplicate map…" is an explicit catalog action |
| Delete node | map untouched; usage count decrements |
| Delete map (catalog) | 409 + usage list; on force, refs keep their uuid and render the **missing-tilemap placeholder** (never crash — asset-delete parity, `../graph/05` §2) |

## 2. Opening a map: navigation, breadcrumbs, history

`projectStore.openTilemap(uuid, { fromNode? })`:

1. Flush pending autosave for the current view (graph command batches *or*
   dirty chunks — both stores expose the same `flush()` contract).
2. `GET /api/tilemaps/{uuid}` (document shell + chunk index, doc 03 §3); the
   surface begins range-fetching visible chunks immediately.
3. Swap `openView` to `{kind: 'tilemap', tilemapUuid}` — `EditorShell` swaps
   the center view and the panel dock tab set (doc 02 §1).
4. Push History state `/p/{project}/t/{uuid}`; restore the map's saved
   `viewport` camera.

**Breadcrumbs:** a tile map has no containment parent, so the crumb trail is
**provenance, not containment**: the graph path you came from plus the map —
`Root ▸ Chapter 1 ▸ Cave Scene ▸ ⌗ Cave Map`. The `fromNode` context (graph
uuid + node uuid) is carried in the History state entry. Clicking any graph
crumb returns to that graph; **Esc / the Up button** returns to the source
graph **with the `tilemap_ref` node selected and centered** — exact container
parity, so the "where did I come from" muscle memory transfers. A map opened
without provenance (deep link, catalog panel) shows `Root ▸ ⌗ Cave Map` and
Esc goes to the root graph.

Browser back/forward traverse graph↔map view switches naturally because both
are plain History entries. Unsaved-work safety: both views autosave, so view
switches are never destructive; the shared toolbar save indicator reflects
whichever store is dirty.

## 3. The Tilemaps catalog panel

A dock tab (grouped with Entities/Assets — the project-resource panels):

- List/grid of maps: thumbnail, name, layer + tile counts, updated-at;
  search box; sort.
- Actions: **New tile map** (name + optional starting tileset), rename,
  duplicate (server-side deep copy: map + layers + chunks, fresh uuids),
  delete (usage-guarded, §1), **export/import** (doc 06 entry points).
- Row click opens the map (provenance = none); drag a row **onto the graph
  canvas** to create a `tilemap_ref` node already wired to it (mirrors
  palette drag→drop; MIME `application/dreamgraph-node` with prefilled data).
- A **Tilesets** section below (or sibling tab): tileset cards (atlas
  preview strip, tile size badge), New Tileset (doc 05 §3), edit slicing,
  usage counts.

The project shell payload (`GET /api/projects/{p}`, `../graph/03` §3) grows
`tilemaps` and `tilesets` catalog summaries so panels and node components
render without extra round-trips — same catalog-caching rule as entities.

## 4. Cross-feature touchpoints

- **Search (`Cmd+K`):** tilemaps and tilesets join the index (name → open).
  Node search already finds `tilemap_ref` nodes via display name/caption.
- **Problems panel:** new checks — `tilemap_ref` with null/dangling uuid;
  layer whose tileset was force-deleted; chunk tile ids orphaned by a tileset
  re-slice (doc 03 §6).
- **Templates/entities:** a template property of type `tilemap_ref` is a
  cheap follow-up (registry already supports `entity_ref`/`asset_ref` kinds);
  deferred until asked for.
- **Play mode (later):** the simulator treats `tilemap_ref` as pass-through;
  optionally the presentation view shows the map thumbnail as a scene slate.
  No physics/tile queries in web play mode (doc 01 §4).

## 5. `.dgpack` bundles (extends doc `../graph/09`)

`project.json` (canonical `dreamgraph/1` export) gains two top-level sections,
additive — additive fields don't bump the format version (`../graph/09` §5):

```jsonc
"tilesets": [ { "uuid": "…", "name": "dungeon",
    "texture_asset_uuid": "…",             // resolves via asset_index as usual
    "tile_width": 16, "tile_height": 16, "spacing": 0, "margin": 0,
    "tile_meta": { "12": { "solid": true }, "13": { "solid": true, "tag": "spikes" } } } ],
"tilemaps": [ { "uuid": "…", "name": "Cave Map", "cell_size": 1.0,
    "settings": {}, "layers": [ { "uuid": "…", "name": "terrain",
        "tileset_uuid": "…", "sort_order": 0, "visible": true, "opacity": 1.0,
        "generate_colliders": true, "physics": null,
        "chunks": [ { "at": [0, 0], "data": "AQAAAA…" } ] } ] } ]  // frozen encoding
```

- Chunk `data` is the same base64 RLE string as the DB/wire/engine format —
  the bundle inherits the round-trip guarantee.
- Deterministic ordering rules apply (sort by sort_order then uuid; chunks by
  `(cx, cy)`), keeping byte-identical re-exports.
- Import pipeline (`../graph/09` §3) slots tilesets/tilemaps into step 4
  (catalogs — after assets, since tilesets need the atlas asset remap;
  before graphs, since node `data.tilemap_uuid` rewriting needs the map remap
  table). Subtree bundles include the **referenced-only closure**: maps
  referenced by exported nodes, plus their tilesets and atlas textures.
- Thumbnails are regenerated client-side after import (doc 03 §5), not
  bundled.
