# 05 — Editor Tools & UX

The editing model, in one sentence (engine 46's, adapted): **open a tile map →
the Tile Palette is docked → pick a tileset and tiles → paint on the canvas
with brush/erase/rect/fill/picker tools, on a grid overlay, with undo** — the
Tiled/Unity workflow, in the browser.

## 1. View layout & mode model

```
┌─ toolbar: tools B E R F I · brush size ◂ 1 ▸ · grid ☑ · collision ☐ · zoom ─┐
│ ┌───────────┐  ┌──────────────────────────────────┐  ┌──────────────────┐  │
│ │ Tile      │  │                                  │  │ Layers           │  │
│ │ Palette   │  │        <canvas>                  │  │  ☰ foreground 👁 │  │
│ │ (§2)      │  │        TilemapSurface            │  │  ☰ terrain    👁🔒│  │
│ │           │  │                                  │  │  ☰ background 👁 │  │
│ │           │  │                                  │  ├──────────────────┤  │
│ │           │  │                                  │  │ Map Inspector    │  │
│ └───────────┘  └──────────────────────────────────┘  └──────────────────┘  │
└─ status bar: cell (x, y) · chunk (cx, cy) · tiles painted · save indicator ─┘
```

The engine's paint-mode entry/exit problem (46.1) mostly dissolves — this
whole view *is* paint mode. What remains:

- Tool `None` (`Esc` from any tool, or `V`) = **pan/select** neutral state:
  drag pans, click reports the cell in the status bar. Every tool's cursor is
  distinct (CSS cursors), and the active tool is always visible in the
  toolbar — modes announce themselves (46.1's lesson).
- All painting targets the **active layer** (Layers panel, §8); locked or
  hidden layers refuse edits with a status-bar nudge, not a modal.
- `Esc` with tool `None` already active = leave the view (back to the source
  graph, doc 04 §2).

## 2. The Tile Palette panel (engine 46.2, React edition)

```
┌─ Tile Palette ─────────────────────────┐
│ Tileset: dungeon              [▾] [+]  │  ← combo over project tilesets; [+] = New Tileset (§3)
│ ┌────────────────────────────────────┐ │
│ │ atlas grid — the tileset texture   │ │  click = select tile
│ │ drawn at pixel zoom (slider),      │ │  drag  = rectangular multi-tile stamp
│ │ cell borders from tileRectPx(),    │ │  selected cell(s) outlined in brand
│ │ selected cells outlined            │ │  orange (Tabler primary)
│ └────────────────────────────────────┘ │
│ Tile 12   Solid ☑   Tag [spikes     ]  │  ← per-tile metadata (§3) — multi-
│                                        │    select edits all selected tiles
└────────────────────────────────────────┘
```

- The grid is one `<canvas>` inside the React panel (not 4 000 DOM cells) —
  drawn from the same `tileRectPx()` slicing function as the renderer
  (doc 02 §4, "one source of truth"), with hover/selection hit-testing from
  the inverse of that math.
- **Drag = stamp:** a rectangular drag stores a `w×h` array of TileIDs in the
  store's `stamp`; the brush paints the whole stamp anchored at the cursor —
  engine 46.2's "half of what makes tile painting fast."
- Switching the palette's tileset combo switches which tileset the *active
  layer* uses only via an explicit prompt (a layer has one tileset —
  doc 01 §3 contract); normally the combo follows the active layer.
- The panel's tileset selection, stamp, and tool live in `tilemapStore`, so
  the vanilla surface reads them without React involvement (doc 02 §2).

## 3. Tileset creation & metadata

**New Tileset dialog** (engine 46.2's flow, with the asset system underneath):

1. Pick an image: `AssetPicker` (`../graph/05` §4) or drop/upload a file
   directly in the dialog (uploads as a normal asset first).
2. Set tile width/height, spacing, margin — over a **live slicing preview**
   (the atlas with grid lines overlaid; wrong numbers are visually obvious
   immediately).
3. Name it → `POST /tilesets` (doc 03 §3). The palette selects it.

**Per-tile metadata** (engine 45.1 / 46.2): selecting tile(s) in the palette
shows the metadata strip — `Solid` checkbox, `Tag` text field. Edits save
through `PATCH /tilesets/{t}/tile-meta` (debounced, partial-merge). Solid
tiles get a small corner marker in the palette grid when the collision
overlay (§7) is on. **Re-slice** (edit tile size on an existing tileset) is
allowed but warns with the affected-maps count — it re-keys atlas indices.

## 4. The tools (engine 46.3, verbatim where possible)

All tools mutate through exactly one door — `stroke.apply(cell, tileId)` —
which records `(cell, before, after)` into the active stroke (§5) and marks
the chunk dirty (render + autosave). Same table, same keys:

| Tool | Key | Behavior |
|---|---|---|
| **Brush** | `B` | Paints the stamp anchored under the cursor; drag = continuous with **Bresenham line-stepping** between pointer samples (fast drags leave no gaps). Brush size N (toolbar, `[`/`]`) paints an N×N block of the stamp |
| **Erase** | `E` | Same traversal, writes `EMPTY_TILE`. **Right-button always erases** regardless of active tool — the muscle-memory shortcut (contextmenu suppressed on canvas) |
| **Rect** | `R` | Drag a marquee; live preview overlay; on release, fill (or erase) the rectangle with the stamp **tiled across it** |
| **Fill** | `F` | Scanline flood fill (iterative, not recursive) from the clicked cell over the connected same-TileID region — **fenced to painted-chunk bounds ± 1 chunk** so a void click can't run away |
| **Picker** | `I` / Alt+LMB | Reads the tile under the cursor into the stamp; **Alt+drag rect-picks a multi-tile stamp** from the map — the eyedropper |

Additional web-side behaviors:

- Hover preview: with Brush active, the stamp footprint ghosts at ~35 %
  opacity under the cursor (cheap on canvas; the engine couldn't do this as
  easily and it's a big usability win).
- Shift+click with Brush = line from the last painted cell (Photoshop
  convention) — costs one reuse of the Bresenham step.
- Touch/pen: single-pointer draws with the active tool; two-pointer =
  pan/pinch-zoom; pen barrel button maps to erase where reported
  (Pointer Events, doc 02 §6).

## 5. Undo — stroke-scoped, per-map (engine 46.4)

```ts
interface TileStroke {                 // one pointer-down → up = one undo unit
  layerUuid: string;
  edits: Map<CellKey, { before: TileID; after: TileID }>;  // deduped per cell
}
class TileUndoStack {                  // per open map; lives in tilemapStore
  push(s: TileStroke): void;           // cap 256 strokes (web memory is cheap;
                                       //  engine used 64), drop oldest
  undo(): void;                        // write `before`s, dirty chunks, feed autosave
  redo(): void;
}
```

- Rect/Fill also produce one stroke each; layer-property and metadata edits
  are *not* in this stack v1 (paint-scoped, exactly like the engine — the
  honest tooltip: "Undo covers tile painting only").
- Ctrl+Z / Ctrl+Shift+Z (and Cmd variants) bind in the **tilemap view's
  keyboard scope** (doc 02 §6), so the graph's command undo and the paint
  undo can never collide — the structural version of engine 46.4's dispatch-
  order rule.
- The stack clears when the map document closes. Undone strokes re-dirty
  their chunks, so autosave persists undo states like any edit (doc 03 §4).
- Forward path: when the graph's command stack and this one ever unify,
  `TileStroke` becomes one command type — nothing is throwaway (engine 46.4's
  same note).

## 6. Grid & cursor overlays (engine 46.3)

Drawn in the screen-space overlay pass (doc 02 §3, step 5), after all layers:

- **Grid lines** over painted-chunk bounds ± 2 chunks at ~15 % alpha (an
  infinite grid is noise — engine rule); line weight thickens every 32 cells
  (chunk boundaries) at high zoom. Toggle in the toolbar, persisted in map
  `settings`.
- **Hovered cell / stamp footprint** outlined in the brand orange; **rect
  marquee** live during Rect drags; fill-region preview on Fill hover is
  deferred (compute cost unbounded — revisit with profiling).
- **Chunk debug view** (dev toggle): chunk borders + `(cx, cy)` + used-count —
  the web analog of the engine's Tracy-assisted verification.

## 7. Collision preview overlay (engine 47.1, as a QA tool)

The TS port of the greedy mesher (`model/greedyMesh.ts`, doc 02 §2) runs over
solid tiles (per tileset `tile_meta`) of layers with `generate_colliders` on,
per chunk, and the overlay draws the merged rects in translucent green —
exactly the engine's collider debug view. Purpose here:

1. **Author confidence:** see what the engine will generate before exporting
   ("a few merged rects, not per-tile confetti" — 47.5's check, shifted left).
2. **Export hint:** the exporter can embed the rects (doc 06 §2) so a future
   engine importer can skip re-meshing or cross-check.
3. **Port fidelity test:** fixture cases assert the TS mesher's output
   matches the engine's expectations (same maximal-rectangle algorithm:
   grow right, then grow the full strip down, mark consumed).

Recompute is per-dirty-chunk and memoized; the overlay toggle lives in the
toolbar next to the grid toggle.

## 8. Layers panel & Map Inspector

**Layers panel** (engine has none — multi-layer maps are its multi-entity
scenes; the web document needs the UI):

- Rows bottom-to-top (draw order), drag-to-reorder (`POST /layers/reorder`);
  per-row: name (double-click rename), 👁 visibility, 🔒 lock, active-layer
  highlight.
- Row selection sets the **active layer**: paint target + palette tileset
  follow it.
- `+` adds a layer (name + tileset, defaulting to the active layer's
  tileset); context menu: duplicate, merge-down (v2), delete (confirms with
  tile count).
- Per-layer properties (when selected) render in the **Map Inspector** below:
  opacity, tint, `generate_colliders` + the three physics floats
  (engine 47.3's exact set), tileset reassignment (guarded, §2).

**Map Inspector** (map-level, no layer selected): name, `cell_size` (world
units per tile — export metadata with a tooltip saying exactly that),
background color, stats line (layers / chunks / tiles painted — engine 46.5's
stats line), and the Export… / Import… buttons (doc 06).

## 9. Verify (engine 45.5/46.6-style checklist)

| Check | Expect |
|---|---|
| New map → New Tileset from an uploaded png (16×16, spacing 1) → paint | tiles appear under the cursor, crisp at every zoom (nearest-neighbor, DPR-aware); slicing preview matched the palette grid |
| Fast scribble | no gaps (Bresenham); no dropped strokes when the pointer leaves the canvas (pointer capture) |
| 2×3 stamp from palette drag | brush paints the block; rect tiles it across a marquee |
| Fill enclosed region / void click | region fills; void fill stops at the painted-bounds fence |
| RMB in any tool | erases |
| Paint at negative coords around the origin | lands correctly (floor-div chunks); grid/overlay agree |
| Ctrl+Z ×5, redo ×5 | exact round trip; chunk bitmaps update each step |
| Reload mid-edit after autosave settles | map identical (chunk round-trip); camera restored (viewport memory) |
| Two layers, different tilesets, reorder | draw order follows panel order; painting hits only the active layer; hidden/locked layers refuse edits |
| Collision overlay on a 100×100 solid block | a few merged green rects, not 10 000 (log the count) |
| Esc, Esc | tool → None, then back to the source graph with the node selected (doc 04 §2) |
| Second browser tab edits the same map | first tab's next flush gets 409 → refetch/merge toast, no silent loss |
