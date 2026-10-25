# 03-data-model-and-files — Completion

**Completed:** 2026-07-31

**Status:** Complete. The workspace envelope, `.coordproj` payloads, load and save
pipelines, ID assignment, first-run seeding, atomic writes, and the portable export format
all shipped. Delivered in plan phases 1 and 11.

## Summary

### Workspace envelope and layout (phase 1)

```
~/Documents/afsfiles/coordinator/
  workspace.json              # envelope: users, projects, teams, currentProject
  projects/
    1.coordproj               # persist-shaped payload (source, kanban cols, budget, …)
    2.coordproj
    3.coordproj
```

`src/main/coordinator/store.ts` (`CoordinatorStore`) owns both halves — the envelope and
the per-project files — with shapes in `types.ts`.

### First-run behaviour

`seed.ts` seeds three demo projects on first launch — Website Redesign, Mobile App,
Marketing Campaign — matching web dev mode. The JSON lives in
`src/main/coordinator/seed/`, copied from `apps/coordinator/resources/js/data/demo/` and
bundled into the main process (~37 KB).

### IPC surface

| Handler | Purpose |
|---------|---------|
| `coordinator:load-workspace` | Load or seed the workspace plus all project payloads |
| `coordinator:save-workspace` | Patch workspace metadata |
| `coordinator:load-project` | Load a single `.coordproj` payload |
| `coordinator:save-project` | Atomic write of a single project |
| `coordinator:create-project` | New project plus empty `.coordproj` |
| `coordinator:delete-project` | Remove from the workspace and delete the file |
| `coordinator:open-workspace-folder` | Open the OS file manager |

### Atomic write pattern

Project saves are atomic, which is what makes the restart-and-survive exit criterion hold.
Editor changes route through `persistProject(id, origin)`, debounced, then out over IPC.

### Import / export (phase 11)

`src/project-import-export.ts` implements the portable single-project format:

```json
{
  "format": "coordinator-project-export/1",
  "exportedAt": "2026-07-31T…",
  "project": { "name": "…", "description": "…" },
  "payload": { … projectDataToPersistPayload … }
}
```

Legacy raw payload files carrying only `source` and `kanbanColumns` are also accepted on
import. The import flow parses, validates via `projectDataFromSeed`, runs
`verifyPersistRoundTrip` over source tasks, kanban column definitions, and
`budget2Data`/`budget2Archive`, then creates and saves the project and reloads the
workspace. Native `dialog-save-file` / `dialog-open-file` IPC replaces the browser
download path.

Entry points: **Settings → Projects** (Import project, Export current) and a per-row
Export button.

### Future sync hooks

Reserved as the plan specifies, so the cloud work can attach without reshaping local
storage — see [../../account-and-cloud-sync/](../../account-and-cloud-sync/).

## Files changed

- `src/main/coordinator/store.ts` — workspace and project CRUD, atomic writes.
- `src/main/coordinator/seed.ts`, `seed/*.json` — first-run demo data.
- `src/main/coordinator/types.ts` — workspace and project file shapes.
- `src/main/coordinator/paths.ts` — `COORDINATOR_DIR`, `projects/`.
- `src/main/coordinator/index.ts` — the seven IPC handlers.
- `src/renderer/components/coordinator/ipc.ts` — typed invoke wrappers.
- `src/renderer/components/coordinator/src/project-persist.ts` — debounced persist.
- `src/renderer/components/coordinator/src/project-import-export.ts` — export/import and
  `verifyPersistRoundTrip()`.
- `src/renderer/html/coordinator.html` — workspace panel (phase 1; replaced by the shell
  mount in phase 3).

## Verification steps

1. Delete `~/Documents/afsfiles/coordinator/` to test a fresh seed; open **Coordinator** —
   three projects appear with task counts.
2. **New project** — a fourth appears and `4.coordproj` lands on disk.
3. **Set current** — the badge moves and `currentProject` updates in `workspace.json`.
4. **Delete** a project with confirm — the file is removed from `projects/`.
5. Restart — remaining projects are still listed.
6. **Settings → Projects → Export current**, then delete the project and re-import — task
   tree, kanban columns, and budget all match.
7. Per-row export works for non-current projects.

```
npm run build  ✅ success (2026-07-31)
```

## Follow-up work

- **Full workspace backup zip** — a stretch goal in the plan; only per-project JSON
  shipped.
- **Automated round-trip test** — none written; `verifyPersistRoundTrip()` plus manual
  checks were judged sufficient for v1.
- **Teams and users in `workspace.json`** — teams currently persist in `localStorage` and
  the user roster stays empty until the sync plan; see
  [04-pm-views-and-modules.complete.md](04-pm-views-and-modules.complete.md).
- **Validation / healing** — the plan has a section for it; no repair pass was built
  beyond import-time validation.
