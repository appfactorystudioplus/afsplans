# Fix: `source_pin`/`target_pin` Persistence Gap — COMPLETE

**Fixed, and it turned out to be bigger than originally described.** What I'd flagged as "Condition-node branch identity is lost after a save+reload" was actually two separate, compounding bugs — one in the backend validation layer that was silently wiping several connection fields on *every* autosave (not just pins), and one in the frontend that never encoded/decoded pin identity in the first place. Both are fixed. **162 TS tests pass** (14 new), **68 PHP tests pass** (3 new), build compiles cleanly. Every new regression test was verified to actually fail without its corresponding fix (git-stashed the fix, reran, confirmed red, restored, confirmed green) before being counted as done.

## Bug #1 (bigger than expected): autosave was silently wiping connection fields

`SaveGraphContentRequest`'s wildcard validation rules for `connections.*` only listed `uuid`/`source_node_uuid`/`target_node_uuid`. I confirmed empirically (via `php artisan tinker` against Laravel's `Validator`) that `FormRequest::validated()` **drops any sibling key without its own rule** for wildcard array rules — it's not "unvalidated," it's *absent* from the array `GraphController::saveContent()` then reads. That controller already correctly read and wrote `source_pin`, `target_pin`, `label`, `color`, and `condition` — they just never survived the validation step in front of it, every single time the 5-second autosave fired. The identical gap existed for node `width`/`height`.

**Practical impact before the fix:** dialogue choice labels, connection colors, and condition-gated branches were being reset to their defaults (`null`/`0`) on the *very next* autosave after being set — even though creating a connection via the single-connection endpoint (`POST /graphs/{graph}/connections`, which does have complete validation) worked fine. Resizing a Comment/Group frame node and reloading lost the resize too.

**Fix:** added the missing rules to `SaveGraphContentRequest` (`app/Http/Requests/SaveGraphContentRequest.php`) with a docblock explaining the `validated()` gotcha so it doesn't regress the next time a field is added to the payload without a matching rule.

## Bug #2: the frontend never encoded/decoded handle identity

Even with the backend now round-tripping `source_pin`/`target_pin` correctly, `projectStore.ts` never computed a non-zero pin from a React Flow edge's `sourceHandle`/`targetHandle`, nor reconstructed a handle id from a loaded pin. The columns are plain `SMALLINT`s (an ordinal pin-index design), but the actual node components use heterogeneous string handle ids — Condition's `'true'`/`'false'`, Entry/Exit's `'out'`/`'in'`, Container's dynamic `'output_N'`, and no id at all (React Flow's default) for Fragment/Hub/`tilemap_ref`/Image/Comment/Group/Note.

**Fix:** [`model/nodeTypes/pins.ts`](apps/dream_graph/resources/js/graph/model/nodeTypes/pins.ts) — a per-node-type registry mapping handle-id strings to pin indices and back (container's is a function of `data.outputs`, since its output count is dynamic). Wired into `projectStore.openGraph()` (decode `source_pin`/`target_pin` → `sourceHandle`/`targetHandle` using each endpoint node's own type) and `saveGraph()` (encode the reverse), so a Condition node's true/false branch — and any future node type with more than one handle — actually survives a save and reload.

## Tests
17 new, all confirmed to fail-then-pass against their fix:
- [`GraphContentPersistenceTest.php`](apps/dream_graph/tests/Feature/GraphContentPersistenceTest.php) (3) — connection pin/label/color/condition survive save+reload; a **second** autosave of the same unchanged connection doesn't regress it (the exact failure mode, since autosave fires repeatedly); node width/height survive save+reload.
- [`pins.test.ts`](apps/dream_graph/resources/js/graph/model/nodeTypes/__tests__/pins.test.ts) (13) — every node type's pin↔handle mapping in both directions, dynamic container outputs, unrecognized-handle fallback to pin 0, unknown node types.
- [`pinPersistence.test.ts`](apps/dream_graph/resources/js/graph/store/__tests__/pinPersistence.test.ts) (1) — a store-level test (apiClient mocked) proving the actual `openGraph`→`saveGraph` wiring round-trips a Condition node's false-branch pin, not just the pure mapping functions in isolation.

## Not touched (flagged, not fixed)
Investigating this, I noticed `Inspector.tsx` edits node color via `style.backgroundColor` on the React Flow node, but `saveGraph()`'s `apiNodes` mapping never sends a `color` field to the backend at all (unlike connections, which do send `color`). Node color persistence may have its own gap, but it's a different shape of problem — deciding whether color should live in `data.color` or as a passed-through `style` field is a wire-format design choice, not a "field got stripped by validation" bug like the two above. I left it alone rather than guessing at the intended convention.

## Where things stand
Everything remains on branch `tilemap-editor`, nothing committed. This closes out the persistence gap you asked me to fix.
