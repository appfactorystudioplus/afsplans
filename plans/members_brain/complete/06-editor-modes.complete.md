# 06 — Editor Modes (P6) — Complete

## Status

Done. The allocation editor is now dual-mode: form and JSON share one underlying draft, with representability checks, mode-switching with warnings, and touched-path tracking for merge semantics. **Nothing committed.**

## Summary

**One state, two projections** (§6.2) — a single draft is edited through the form (controls) or JSON editor (raw), and switching modes re-renders from the draft without transferring between buffers.

**Representability checks** (§6.3) — the form detects unrepresentable content (orphan counters, unknown resources, malformed entries) on load and before switching to form mode, and presents a warning with the option to remove orphan counters.

**Pending changes survive mode switches** (§6.2 rule 2) — touched paths are tracked by both editors (form on every input, JSON by diffing against pristine), so `Review & Save` reports changes accurately regardless of which mode last touched the data.

**Parse internals stripped from JSON buffer** (§6.6) — objectId, createdAt, updatedAt, and ACL are removed before the JSON editor renders, so what the operator edits is what will be written (BR22).

**Mode recorded in audit** (§6.7) — saves track whether the edit came from form or JSON mode; if both were used before saving, it records `json` (the wider surface).

## Files created

None.

## Files modified

| File | Change |
|---|---|
| `www/resources/js/portal/admin/controllers/brain/brain_membership.ts` | Refactored renderAllocationForm to implement dual-mode editor (§6), added AllocationEditorState interface, detectUnrepresentableContent/stripParseInternals/computeTouchedPaths helpers, mode toggle UI with warning dialog, JSON editor view, updated buildAllocationCard to track touched paths |

## Implementation details

### State Management (§6.2)

`AllocationEditorState` holds:
- `pristine` — immutable baseline for diff
- `draft` — editable document (form and JSON both modify this)
- `touched` — Set of JSON-pointer paths the operator changed
- `mode` — 'form' or 'json'
- `loadedAt`, `updatedAt` — timestamps for UI display

### Representability Check (§6.3)

`detectUnrepresentableContent()` scans the draft for:
- Unknown resource ids (not in registry.resources)
- Unknown top-level fields (not member_resources, domains, pm_projects, ac_*, or Parse internals)
- Malformed entries (used not a number, entry not an object)
- Orphan counters (ac_* field whose domain doesn't exist in domains[])
- Wrong-shaped containers (domains as object instead of array)

Returns array of `UnrepresentableContent` items (path, reason, isOrphanCounter flag).

### Mode Switching (§6.4)

**Form → JSON:** Always safe; draft already is the document.

**JSON → Form (with unrepresentable content):** Shows warning dialog listing unrepresentable items:
- Regular items offer "Stay in Form" or "Show JSON" buttons
- Orphan counters additionally offer [remove] action (immediate delete from draft)
- Switched to form still shows persistent unrepresentable-content banner

### Touched Path Tracking (§6.2)

- **Form mode:** Input event handlers call `touched.add()` with JSON-pointer path when a value changes
- **JSON mode:** On every change event, `computeTouchedPaths()` diffs pristine vs current to populate touched set
- **Used for:** Merge semantics (§7) — only touched paths are written to Parse; untouched data remains server state

### Parse Internals Stripping (§6.6)

`stripParseInternals()` removes objectId, createdAt, updatedAt, ACL before passing draft to JSON editor. These metadata fields reappear on reload (from Parse), so the operator sees a clean document.

### Save Integration (§6.7)

Save payload now includes `mode: state.mode` so audit row records whether the change came from form or JSON. If operator used both modes before saving, mode recorded as 'json'.

### Domain Controls (§4.6 integration)

Domain cards show [remove] and [Zero counters] buttons:
- Remove: deletes domain from domains[] and cleans up ac_* counters for that domain
- Zero counters: sets all counters in that domain to 0
- Both track touched paths to ensure changes are recognized

## Verified

Against form inputs and JSON editor renders:

- ✓ Touched paths tracked on form input changes
- ✓ Touched paths computed from JSON diff
- ✓ Mode toggle works (form → JSON → form)
- ✓ Unrepresentable content detected (orphan counter, unknown resource)
- ✓ Warning dialog shows for switching to form with unrepresentable content
- ✓ Orphan counter [remove] button deletes the counter
- ✓ Parse internals (objectId, createdAt, etc.) absent from JSON buffer
- ✓ Pending changes count reflects touched path count
- ✓ Discard restores pristine and clears touched
- ✓ Save payload includes mode field
- ✓ Domain [remove] and [Zero] buttons track touched paths
- ✓ TypeScript compiles (lib-target warnings only, not code errors)

## Not verified

Needs a live form and real member Parse object:

1. **End-to-end mode switching with real data** — mock drafts used above
2. **Form render with live unrepresentable content** — persistent banner tested in isolation
3. **Save through full pipeline** — payload structure correct, server integration not tested
4. **JSON editor anchors not scrolling page** (§6.6) — vanilla-jsoneditor already handles this, preserved
5. **Merge semantics during concurrent writes** (§7) — touched-path tracking proven, concurrency handler untested

## Follow-up

**Step 7 (Save Semantics)** needs to:
1. Wire up the merge logic that uses touched[] to decide which paths to write
2. Implement concurrency check against updatedAt
3. Send touched paths alongside draft to save endpoint

Current save just passes draft without touched paths; Step 7 adds the merge.

**Operator guidance:** Operators should know:
- Mode toggle button stays in the same row as Save/Discard buttons
- Pending changes count does not move when toggling modes (both edit the same draft)
- Unrepresentable content is preserved; removing it requires JSON mode or [remove] action
- Save logs which mode was used for troubleshooting mixed-mode edits
