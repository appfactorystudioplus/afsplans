# Toolbar Polish + Palette Drag-and-Drop Fix — COMPLETE

Follow-up to [toolbar-cleanup-complete.md](toolbar-cleanup-complete.md), addressing three things found during live use.

## 1. "Back to Projects" — icon only

[`EditorShell.tsx`](../../../apps/dream_graph/resources/js/graph/shell/EditorShell.tsx): the button now shows only the `ti-home` icon, with the label moved to `title`/`aria-label` for a tooltip and screen readers instead of inline text.

## 2. Play and Save buttons — matched width

Both buttons now carry `style={{ minWidth: '84px' }}`. Without it, "Play" and "Save"/"Saved" (which changes length depending on dirty state) rendered at different widths and visually jittered the toolbar. The presentation icon-only button stays compact — only the two text-bearing buttons needed matching.

## 3. Palette drag-and-drop onto the canvas — root cause found

Dragging a node from the palette and dropping it on the canvas did nothing, with no console error. The cause: [`Palette.tsx`](../../../apps/dream_graph/resources/js/graph/panels/Palette.tsx)'s `PALETTE_DND_TYPE` was a custom MIME type (`'application/dreamgraph-node'`). Safari/WebKit is documented to silently return `""` from `dataTransfer.getData()` for non-standard drag types on drop — no exception, the drop just no-ops, which matches "nothing happens" exactly (Chrome and Firefox tolerate custom types fine, which is why this kind of bug often ships unnoticed).

**Fix:** switched `PALETTE_DND_TYPE` to the standard `'text/plain'`. This is safe against unrelated text drags (dragging highlighted text or a URL onto the canvas) because [`projectStore.ts`](../../../apps/dream_graph/resources/js/graph/store/projectStore.ts)'s `addNodeAt()` already no-ops on any string that isn't a registered node type — garbage input just does nothing, same as before.

Also added an `onDragEnter` handler alongside the existing `onDragOver` on [`GraphCanvas.tsx`](../../../apps/dream_graph/resources/js/graph/canvas/GraphCanvas.tsx)'s drop wrapper (both must call `preventDefault()` for some browser/engine combinations to treat an element as a valid drop target at all) — a standard cross-browser HTML5 DnD hardening, on top of the MIME-type fix.

## Verification
`tsc --noEmit` clean, `npm run build` compiles (306 modules), full vitest suite 189/190 (same one pre-existing unrelated failure, unchanged). The drag-and-drop fix could not be visually confirmed on my end — the browser tool's per-action-approval gate on `dream_graph.test` is still blocking screenshots/console access for me — so this needs a manual retry in the browser to confirm the fix actually resolves it.
