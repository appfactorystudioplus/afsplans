# Dream Graph — Tile Map Editor Plan

**Source app:** `apps/dream_graph` (Laravel 12 + Vite + TypeScript, React Flow island — see `../graph/`)
**Feature origin:** JoystickEngine v2 plan steps 45–47
(`JoystickEngine_v2/plan/steps/45_tilemap_runtime.md`, `46_tilemap_editor.md`,
`47_tilemap_physics_scripting.md`) — this plan duplicates that tile map editor
for the browser and wires it into the Dream Graph editor.
**Goal:** a user designing a story/game in the node graph can drop a **Tile Map
node**; opening it swaps the editor to a **full tile map editing view**
(palette, brush/erase/rect/fill/picker tools, layers, undo) backed by the same
chunked data format the engine uses — so tile maps export to JoystickEngine
(and import back) losslessly, today via files and later via a live bridge.

## Document index

| Doc | Contents |
|-----|----------|
| [01-overview-and-engine-parity.md](01-overview-and-engine-parity.md) | What engine steps 45–47 define, the feature-by-feature parity matrix (duplicate / adapt / defer), and the format-compatibility contract that makes engine interchange cheap |
| [02-architecture-and-rendering.md](02-architecture-and-rendering.md) | Multi-view editor shell (graph view = React Flow, tilemap view = framework-free canvas core + React panels), the chunked offscreen-canvas renderer (web analog of the engine's chunk meshes), coordinate conventions, performance budget |
| [03-data-model-and-api.md](03-data-model-and-api.md) | Database schema (`tilesets`, `tilemaps`, `tilemap_layers`, `tilemap_chunks`), REST API, chunk-granular autosave, validation |
| [04-graph-integration.md](04-graph-integration.md) | The `tilemap_ref` node type, project-level Tilemaps catalog + panel, navigation/breadcrumbs/history between views, thumbnails, "where used", `.dgpack` bundle inclusion |
| [05-editor-tools-and-ux.md](05-editor-tools-and-ux.md) | Tile Palette panel, tileset creation with live slicing preview, the five paint tools, multi-tile stamps, stroke-scoped undo, layers panel, grid & collision-preview overlays, keyboard/pointer input |
| [06-engine-interchange.md](06-engine-interchange.md) | Export to `.jtileset`/`.jtilemap` (+ manifest) for JoystickEngine, import from the engine, Tiled `.tmx`/`.tsx` import, round-trip guarantees, and the (now committed, two-sided) live engine bridge overview |
| [07-roadmap.md](07-roadmap.md) | Phased implementation roadmap (TM0–TM5) with exit criteria, dependencies on the graph roadmap (`../graph/07-roadmap.md`), and risks |
| [08-live-sync-protocol.md](08-live-sync-protocol.md) | The bidirectional live sync wire protocol (web ⇄ engine): conditional pull, versioned push, per-project sync tokens, SSE notify, whole-chunk conflict resolution, offline queueing, identity mapping. The web end of engine step 47.8 |

## One-paragraph summary

The engine plan builds a Tiled/Unity-style tile map workflow natively in the
C++ editor: a **tileset asset** (atlas + per-tile `Solid`/`Tag` metadata), a
**sparse chunked map** (32×32-tile chunks, RLE+base64 serialization), a
**palette + paint tools + stroke-scoped undo** editing loop, and **greedy-meshed
physics + a C# API + Tiled import**. This plan rebuilds that workflow in
`apps/dream_graph` as a **second editor view** beside the React Flow graph
canvas: the paint surface is a framework-free TypeScript canvas engine (chunk →
offscreen-canvas caching mirrors the engine's chunk-mesh rebuild strategy 1:1),
while the docked panels (palette, layers, inspector) stay React so they reuse
the existing `AssetPicker`, Tabler theming, and store infrastructure. Tile maps
and tilesets are **project-level resources** (like entities and assets): a new
`tilemap_ref` graph node references a map by uuid, renders a live thumbnail,
and opens the tilemap view on double-click — same submerge/breadcrumb/History-API
pattern as nested graphs. The storage format deliberately clones the engine's
decisions (u32 tile ids with 0 = empty, 32×32 chunks, RLE `(count,id)` pairs →
base64, one tileset per layer, y-up cell coordinates) so **export to the engine
is transcoding, not translation**: a zip of `.jtileset` + `.jtilemap` + atlas
textures + a layer manifest, with the reverse import, Tiled import at step 47.4's
exact scope, and a versioned REST surface that the engine-side bridge (engine
step 47.8, protocol in doc 08) syncs against live in both directions. The
format-compatibility contract (doc 01 §3) is now **co-owned**: the engine plan
restates it verbatim and vendors this repo's shared RLE fixture into its own CI,
so drift breaks tests on both sides.
