# 03-data-model-and-files — Completion

**Completed:** 2026-08-01

**Status:** Complete. The workspace layout, `project.json` schema, ID generation, atomic
save semantics, canvas-theme persistence, and the import/export format all shipped as
specified. Delivered across plan phases 1 and 5.

## Summary

### Workspace root and layout (phase 1)

`src/main/flow/paths.ts` resolves the workspace to

```
~/Documents/afsfiles/flow/projects/{reference_id}/project.json
```

Folder name is the `reference_id` (e.g. `ref_a1b2c3d4e5f6`), generated in the main
process. `get-app-dirs` was extended with `flowDir` so the renderer can surface the path.

### Schema and store

`src/main/flow/types.ts` defines `FlowProjectRecord` and `ProjectScene`;
`src/main/flow/store.ts` implements create / list / get / save / delete. Save is atomic —
write to `.tmp`, then rename — which was phase 1's exit criterion against corrupt JSON on
a mid-write crash.

`src/main/flow/index.ts` registers the IPC handlers; `renderer/components/flow/ipc.ts`
wraps them in typed invokes, and `storage/projectStore.ts` presents the web-compatible
store interface on top.

### Canvas theme persistence

Theme preset (light / dark / charcoal) persists in `localStorage` rather than in
`project.json`, keeping it a per-machine display preference rather than project data.

### Import / export format (phase 5)

`src/main/flow/exportImport.ts` implements and validates the `flow-project-export/1`
envelope:

```json
{
  "format": "flow-project-export/1",
  "exportedAt": "2026-08-01T12:00:00.000Z",
  "project": { "id", "name", "reference_id", "scene", "revision", ... }
}
```

`store.importFromExport` **mints new local IDs** on every import — a fresh `reference_id`
and `id` — so importing a project that still exists locally cannot collide on the folder
name. Export and import both go through native save/open dialogs on the
`flow:export-project` and `flow:import-project` channels, surfaced as an Import toolbar
button and a per-row Export action.

### Cloud hook

The record carries the plan-wide `cloud: null` hook so the later cloud-sync work can
attach without rewriting local storage. See
[../../account-and-cloud-sync/](../../account-and-cloud-sync/).

## Files changed

- `src/main/flow/paths.ts` — workspace root resolution.
- `src/main/flow/types.ts` — `FlowProjectRecord`, `ProjectScene`.
- `src/main/flow/store.ts` — CRUD, atomic write, `importFromExport`.
- `src/main/flow/exportImport.ts` — `flow-project-export/1` build and validation.
- `src/main/flow/index.ts` — nine `flow:` IPC handlers plus native dialogs.
- `src/main/index.ts` — handler registration; `get-app-dirs` gains `flowDir`.
- `src/renderer/components/flow/ipc.ts` — typed invoke wrappers.
- `src/renderer/components/flow/storage/types.ts` — copied from web.
- `src/renderer/components/flow/storage/projectStore.ts` — IPC-backed store.
- `src/renderer/components/flow/shell/dashboardEvents.ts` — Import/Export wiring.
- `src/renderer/locales.json` — import/export strings, 4 languages.

## Verification steps

1. Create a project; confirm a folder appears under
   `~/Documents/afsfiles/flow/projects/`.
2. Restart the app — the project is still listed.
3. Delete — the folder is removed from disk.
4. Export a project, delete it locally, then import the file — the scene is restored under
   a **new** `reference_id`.
5. Import a malformed file — an error is shown and the workspace is not corrupted.

## Deviations from plan

- **`workspace.json` was not created.** The plan marks it optional; project discovery
  works by directory scan instead, so there is no index file to keep in sync.

## Follow-up work

- **Large embedded images** in Excalidraw `files` carry the same practical size limits as
  the web app; no desktop-specific cap or warning was added.
- **`sync.json`** — the optional per-project sync sidecar is not written yet; it arrives
  with the cloud phase.
