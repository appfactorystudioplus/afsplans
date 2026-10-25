# Tile Map Editor — Completed-Step Log

One markdown file per implementation step (TM0–TM5) as it lands, holding the
completion writeup for that step (the same summary reported when the step
finished). Files are prefixed with the step id so they sort in order.

The plan itself lives one level up in `_docs/tilemap/` (README + docs 01–07);
this folder is the running record of what has actually been built.

| File | Step | Status |
|------|------|--------|
| [TM0-foundation-complete.md](TM0-foundation-complete.md) | Data model, RLE codec, API | ✅ complete |
| [TM1-editor-view-complete.md](TM1-editor-view-complete.md) | Surface, store, brush/erase, routing | ✅ complete |
| [TM2-tools-palette-layers-complete.md](TM2-tools-palette-layers-complete.md) | Palette, New Tileset, rect/fill/picker, layers | ✅ complete |
| [TM3-graph-integration-complete.md](TM3-graph-integration-complete.md) | tilemap_ref node, catalog, navigation, thumbnails | ✅ complete |
| [TM4-engine-interchange-complete.md](TM4-engine-interchange-complete.md) | Engine export/import, Tiled import, .dgpack sections | ✅ complete |
| [TM5-polish-merge-minimap-complete.md](TM5-polish-merge-minimap-complete.md) | Layer merge-down, minimap (non-engine polish) | 🟡 partial — engine bridge blocked |
| [TM5-play-mode-scene-slate-complete.md](TM5-play-mode-scene-slate-complete.md) | tilemap_ref pass-through fix + scene-slate rendering | 🟡 partial — engine bridge blocked |
| [TM5-wire-play-mode-complete.md](TM5-wire-play-mode-complete.md) | Wired PresentationMode + PlayModePanel into EditorShell | 🟡 partial — engine bridge blocked |
| [source-pin-target-pin-fix-complete.md](source-pin-target-pin-fix-complete.md) | Fixed autosave field-stripping + handle/pin encoding gap | ✅ complete |
| [TM5-autotile-rules-complete.md](TM5-autotile-rules-complete.md) | Auto-tiling rules (4-directional bitmask, artist-authored) | ✅ complete |
| [TM5-tile-animation-preview-complete.md](TM5-tile-animation-preview-complete.md) | Tile animation preview (looping frames, live in editor) | ✅ complete |
| [create-project-response-shape-fix-complete.md](create-project-response-shape-fix-complete.md) | Fixed createProject response-shape bug found during live Herd verification | ✅ complete |
| [toolbar-cleanup-complete.md](toolbar-cleanup-complete.md) | Collapsed breadcrumb trail + grouped toolbar buttons into labeled clusters | ✅ complete (pending visual verification) |
| [toolbar-polish-and-dnd-fix-complete.md](toolbar-polish-and-dnd-fix-complete.md) | Icon-only back button, matched Play/Save widths, fixed palette drag-and-drop (Safari custom-MIME-type bug) | ✅ complete (pending visual verification) |
| [genid-secure-context-fix-complete.md](genid-secure-context-fix-complete.md) | Fixed the real drag-and-drop crash: crypto.randomUUID() unavailable over plain http | ✅ complete — confirmed working |
| [fouc-fix-complete.md](fouc-fix-complete.md) | Moved @vite CSS/JS from bottom of body into head, fixing the flash-of-unstyled-content on load | ✅ complete — confirmed working |
| [sidebar-toolbar-merge-complete.md](sidebar-toolbar-merge-complete.md) | Merged top navbar into the left sidebar; made sidebar collapsible to an icon rail | ✅ complete (pending visual verification) |
| [remove-dead-dashboard-sidebar-complete.md](remove-dead-dashboard-sidebar-complete.md) | Removed the unused static Tabler dashboard `<aside>` sidebar from the Blade shell | ✅ complete (pending visual verification) |
| [graph-location-replaces-breadcrumb-complete.md](graph-location-replaces-breadcrumb-complete.md) | Replaced the breadcrumb trail with a simple Up-button + current graph name | ⚠️ superseded (also rejected) |
| [graph-location-variant-picker-complete.md](graph-location-variant-picker-complete.md) | 5 selectable graph-location styles added for live comparison; pick one, then delete the rest | ⚠️ superseded (picked, cleaned up) |
| [graph-location-finalized-complete.md](graph-location-finalized-complete.md) | Popover Path chosen as final; switcher + 4 rejected variants deleted | ✅ complete (pending visual verification) |
| [move-main-entry-to-js-root-complete.md](move-main-entry-to-js-root-complete.md) | Moved resources/js/graph/main.tsx to resources/js/main.tsx and updated all references | ✅ complete — confirmed working |
| [remove-theme-toggle-button-complete.md](remove-theme-toggle-button-complete.md) | Removed the light/dark theme toggle button from the header | ✅ complete — confirmed working |
| [bootstrap-js-dropdown-positioning-fix-complete.md](bootstrap-js-dropdown-positioning-fix-complete.md) | Fixed off-screen dropdowns app-wide: Bootstrap's JS (with Popper) was never actually loaded | ❌ wrong — caused a regression, reverted |
| [dropdown-positioning-css-fix-complete.md](dropdown-positioning-css-fix-complete.md) | Reverted the bad JS fix; real fix is an unconditional CSS override for dropdown-menu-end/dropend | ❌ still didn't work, superseded |
| [dropdown-positioning-scss-fix-complete.md](dropdown-positioning-scss-fix-complete.md) | Converted to SCSS; fix now cancels Popper's inline transform outright instead of fighting it | ❌ solved the wrong problem, superseded |
| [dropdown-real-fix-wrong-alignment-class-complete.md](dropdown-real-fix-wrong-alignment-class-complete.md) | Actual bug: toggle sits on the left (order-md-last is a no-op with one item), needed dropdown-menu-start not -end | ✅ complete (pending visual verification) |

Work is on branch `tilemap-editor` in `apps/dream_graph`.
