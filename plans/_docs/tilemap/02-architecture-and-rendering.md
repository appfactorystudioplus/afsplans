# 02 — Architecture & Rendering

## 1. The multi-view editor shell

Today the editor shell has one center view: the React Flow graph canvas
(`../graph/03` §4). The tilemap editor becomes the **second** center view, and
the shell grows a tiny view router:

```
EditorShell (React, existing)
├── Breadcrumbs · toolbar · save indicator · panel dock      (shared chrome)
├── center view = one of:
│   ├── <GraphCanvas>      React Flow — nodes & edges         (existing)
│   └── <TilemapView>      thin React wrapper that mounts…
│       └── TilemapSurface       ★ framework-free TS canvas engine
│           (its own <canvas>, pointer/keyboard handlers, rAF loop)
└── panels (React, docked): Palette/Inspector/Assets/… per view
```

- **Store:** `projectStore.openView` becomes a discriminated union:
  `{ kind: 'graph', graphUuid } | { kind: 'tilemap', tilemapUuid }`.
  Opening either kind flushes the other's pending autosave first — the same
  contract `openGraph()` already has (`../graph/04` §3).
- **URLs:** graphs keep `/p/{project}/g/{graph}`; tile maps get
  `/p/{project}/t/{tilemap}`. Same History-API handling, so back/forward
  crosses views naturally and tile maps are deep-linkable.
- **Panel dock is per-view:** graph view shows Palette (nodes) / Inspector /
  Assets / Entities…; tilemap view shows **Tile Palette / Layers / Map
  Inspector** (+ the shared Assets panel). The dock component takes its tab
  set from the active view's descriptor — a registry keyed by view kind, the
  same extension-point pattern as `NodeTypeRegistry`.

## 2. Technology choice: framework-free canvas core, React panels

The user-facing decision from the requirements: *"you do not have to use React
throughout — use whatever is best and efficient."* The split:

| Piece | Tech | Why |
|---|---|---|
| Paint surface (`TilemapSurface`) | **Vanilla TypeScript + Canvas 2D**, zero React | A paint surface is an imperative, per-frame, pointer-driven system. React's reconciliation adds nothing and gets in the way of a rAF loop; the engine's own editor (ImGui + renderer) is the same shape. One class owns the `<canvas>`, the camera, the chunk cache, and the active tool. |
| Docked panels (palette, layers, inspector, dialogs) | **React + Tabler** (existing island) | They are forms and lists — React's sweet spot — and they must reuse `AssetPicker`, theming, modals, and the store. Rebuilding those vanilla would be waste. |
| Shared state | **`zustand/vanilla` store** (`tilemapStore`) | Zustand stores work identically inside and outside React: `TilemapSurface` reads/subscribes imperatively; panels use the hook wrapper. One store, two consumption styles — this is the whole React/non-React bridge, ~0 glue code. |
| Document model + commands + autosave | Plain TS modules (no framework), same layering as the graph's `model/` + `store/commands.ts` | Library-independence rule carries over: nothing in the document model may import React *or* canvas APIs. |

**Why Canvas 2D and not WebGL/PixiJS:** with chunk → offscreen-canvas caching
(§3), the per-frame cost is `visibleChunks × drawImage` — typically < 100 blits
of pre-rendered bitmaps, trivially 60 fps. WebGL buys nothing until zoom-outs
show thousands of chunks; if profiling ever demands it, the renderer is one
class behind an interface (`ChunkRenderer`) and PixiJS (or raw WebGL quads) is
the drop-in upgrade. Starting with Pixi would add a 400 KB dependency, its own
asset lifecycle, and context-loss handling for a v1 that doesn't need any of it.
(Same "no cleverer invalidation until proven otherwise" stance as engine 45.4.)

### Directory layout

```
resources/js/graph/                       (existing island root — shared shell)
  shell/EditorShell.tsx                   grows the view router (§1)
  model/nodeTypes/tilemapRef/             the graph node (doc 04)
resources/js/tilemap/                     ★ new — the tilemap editor
  model/
    types.ts               Tileset, TilemapDoc, Layer, Chunk, TileID…
    chunk.ts               get/set tile, UsedCount, dirty flags, floor-div math
    rle.ts                 encode/decode RLE(u32 pairs) ⇄ base64  (contract, doc 01 §3)
    greedyMesh.ts          TS port of engine 47.1 (collision preview + export hint)
  store/
    tilemapStore.ts        zustand/vanilla: open doc, layers, selection, tool state
    strokes.ts             TileStroke + per-map undo stack (doc 05 §5)
    autosave.ts            dirty-chunk tracking → debounced PUT (doc 03 §4)
  surface/
    TilemapSurface.ts      owns <canvas>: rAF loop, camera, input routing
    ChunkRenderer.ts       chunk → offscreen-canvas cache (§3)
    overlay.ts             grid, cursor/stamp outline, marquee, collision rects (§4)
    camera.ts              pan/zoom, screen ⇄ world ⇄ cell transforms (§5)
    tools/                 brush.ts erase.ts rect.ts fill.ts picker.ts (doc 05 §4)
  panels/                  React: TilePalettePanel, LayersPanel, MapInspector,
                           NewTilesetDialog, TilemapsCatalogPanel (doc 04 §3)
  api/tilemapClient.ts     typed client for the doc 03 endpoints
  TilemapView.tsx          the React wrapper: mounts/destroys TilemapSurface,
                           lays out panels, wires keyboard scope
```

Rule (mirrors the graph plan's anti-creep rule): **nothing outside
`resources/js/tilemap/` may import from `surface/`** — the rest of the app
talks to the store and the API client only.

## 3. Rendering: chunk → offscreen-canvas cache

Direct analog of engine 45.4 ("one mesh per chunk, rebuilt on dirty"):

```ts
// ChunkRenderer.ts — the web version of TilemapRenderer::SyncChunk
class ChunkRenderer {
  // key: `${layerId}:${cx},${cy}` → cached bitmap of that chunk
  private cache = new Map<string, { canvas: OffscreenCanvas; dirty: boolean }>();

  /** Redraw one chunk's 32×32 tiles into its cached bitmap. Called only when dirty. */
  syncChunk(layer: Layer, cx: number, cy: number, chunk: Chunk): void {
    const px = CHUNK_SIZE * layer.tileset.tileW;   // bitmap is at native tile
    const py = CHUNK_SIZE * layer.tileset.tileH;   // resolution, not screen res
    const oc = this.getOrCreate(layer, cx, cy, px, py);
    const ctx = oc.getContext('2d')!;
    ctx.clearRect(0, 0, px, py);
    ctx.imageSmoothingEnabled = false;             // nearest-neighbor (45.1 rule)
    for (let ty = 0; ty < CHUNK_SIZE; ty++)
      for (let tx = 0; tx < CHUNK_SIZE; tx++) {
        const id = chunk.tiles[ty * CHUNK_SIZE + tx];
        if (id === EMPTY_TILE) continue;
        const src = layer.tileset.tileRectPx(id - 1);   // shared slicing math
        // y-up cells → y-down bitmap: row (CHUNK_SIZE-1-ty) (see §5)
        ctx.drawImage(layer.tileset.image, src.x, src.y, src.w, src.h,
          tx * layer.tileset.tileW, (CHUNK_SIZE - 1 - ty) * layer.tileset.tileH,
          layer.tileset.tileW, layer.tileset.tileH);
      }
  }
}
```

Frame loop (`TilemapSurface.render`), per visible layer bottom-to-top:

1. Compute the visible **chunk range** from the camera (one rect intersection —
   this is the culling; off-screen chunks cost nothing, mirroring Filament's
   per-chunk frustum cull).
2. For each visible chunk: `syncChunk` if dirty (editing one tile dirties one
   chunk → one ≤ (32·tileW)×(32·tileH) bitmap redraw — the web version of
   "one ≤4096-vert rebuild, imperceptible").
3. `drawImage(chunkBitmap, …)` at the camera transform, with
   `imageSmoothingEnabled = false` on the main context too, and destination
   rects **snapped to integer device pixels** — this plus native-resolution
   bitmaps is what prevents seams/shimmer between chunk blits (the Canvas2D
   equivalent of the engine's half-texel inset + no-mips rules).
4. Layer `opacity`/`tint` apply at blit time (`globalAlpha`; tint via a cached
   tinted variant only if/when the feature is used — engine parity keeps tint
   in the schema now).
5. Overlay pass in screen space (§4).

The main canvas is sized to `clientSize × devicePixelRatio` (re-checked on
`ResizeObserver` + `matchMedia('resolution')` change) so pixel art stays crisp
on retina/zoomed displays.

Dirty-render scheduling: no free-running rAF. The surface renders on demand —
store mutation, camera change, pointer move that changes the hovered cell —
via a `requestRender()` that coalesces into one rAF. Idle cost is zero
(engine parity: "sync pass ~0 when nothing is dirty").

Cache eviction: LRU cap ≈ 512 chunk bitmaps (~128 MB worst case at 16px tiles
is actually ~8 MB — bitmaps are tiny; the cap is a safety rail, not a tuning
knob). Evicted chunks just re-sync on next visibility.

## 4. Tileset images & slicing

- The tileset's source texture is a normal project **asset** (doc `../graph/05`);
  the tilemap editor loads it as an `ImageBitmap`
  (`createImageBitmap(blob)` — decodes off-main-thread).
- `Tileset.tileRectPx(atlasIndex)` is the **single source of truth** for
  slicing (margin/spacing/tileW/tileH → source rect). The palette grid, the
  chunk renderer, the New Tileset preview, and the exporter all call it —
  engine 46.2's "one source of truth for slicing" rule.
- Because Canvas2D `drawImage` samples exact integer source rects (no mip
  chain, no filtering when smoothing is off), atlas bleeding cannot occur the
  way it does on GPU quads; the function still accepts an optional half-texel
  inset parameter so the *export* path can write engine-identical UV
  expectations and a future WebGL renderer can turn it on.

## 5. Coordinates: y-up storage, y-down screen

Frozen in doc 01 §3: **cell coordinates are y-up**, identical to the engine,
so exported data never flips. The web side handles the mismatch entirely at
the presentation boundary:

```
world (y-up, cells × cellSize)  ←— stored, exported, mutated
   ↕ camera.worldToScreen(p) = { x: (p.x - cam.x) * zoom,
                                 y: canvasH - (p.y - cam.y) * zoom }
screen (y-down CSS pixels)      ←— pointer events, overlay drawing
```

- `camera.screenToCell(mouse)`: invert the affine, then
  `floor(world / cellSize)` — the 2D simplification of engine 46.1's ray math
  (no rotated maps in v1, doc 01 §4).
- Chunk bitmaps are drawn y-down internally (row flip in `syncChunk`, §3) so
  blitting stays a single `drawImage` per chunk.
- `CellToChunk` uses arithmetic-shift floor division (`cx = cell.x >> 5`),
  same as the engine — correct for negative cells; the 45.5 negative-coords
  check is a unit test here.

## 6. Input routing

`TilemapSurface` owns pointer + keyboard while the view is focused:

- **Pointer Events** (not mouse events): one code path for mouse, trackpad,
  pen, and touch; `setPointerCapture` during strokes so fast drags leaving the
  canvas don't drop the stroke.
- Middle-drag or Space+drag = pan; wheel = zoom-to-cursor (standard editor
  camera); pinch-zoom via two-pointer distance on touch.
- Left button routes to the active tool; **right button always erases**
  (engine 46.3 rule) and `contextmenu` is suppressed on the canvas.
- Keyboard shortcuts register through the app's existing shortcut layer with
  a **view scope**: tilemap bindings (B/E/R/F/I, [ ] brush size, Ctrl+Z…) are
  active only when the tilemap view is; the graph view's bindings sleep, and
  vice versa. This is engine 46.4's "paint mode is earlier in the dispatch
  order" made structural — two views can't fight over Ctrl+Z.

## 7. Performance budget (engine 45.5 / graph doc 03 §5 style)

| Scenario | Target |
|---|---|
| Open a map with a 512×512 painted region (~256 chunks, 3 layers) | first paint < 600 ms (chunk data fetch is range-limited, doc 03 §3; visible chunks sync lazily) |
| Pan/zoom across that map | 60 fps; render cost = visible-chunk blits only; zero when idle |
| Scribble fast with a 4×4 stamp | no gaps (Bresenham), ≤ 2 chunk re-syncs/frame typical, no GC hitches (chunk arrays are preallocated `Uint32Array`s) |
| Flood-fill an enclosed 10 k-tile region | < 50 ms including chunk dirtying (scanline fill on typed arrays) |
| Undo ×20 | instant; only touched chunks re-sync |
| Memory | `Uint32Array` per loaded chunk (4 KB) + LRU-capped bitmaps; a 1024×1024-cell map fully loaded ≈ 4 MB tile data |

Vitest covers `rle.ts` (round-trip, fuzz), `chunk.ts` (negative coords,
UsedCount, chunk erasure), `greedyMesh.ts` (port fidelity against fixture
cases), `camera.ts` (transform inverses). Playwright drives the happy path:
create map → new tileset from upload → paint → reload → pixels persist
(canvas screenshot diff).
