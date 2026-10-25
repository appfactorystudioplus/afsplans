# 05-source-first-sync-engine — Completion

**Completed:** 2026-07-31

**Status:** Complete. The source-first spine was ported and is what every PM view writes
through. Delivered in plan phase 2, with the per-view sync origins wired as phases 4–9
landed. The plan's testing strategy was not carried over.

## Summary

### Principle

`ProjectData.source[]` stays canonical; derived views are rebuilt from it rather than kept
in parallel. That held through the whole port — kanban, table, tasks, gantt, and dashboard
all project from `source[]`.

### Core modules (copied first)

Copied from web coordinator `resources/js/src/` into
`src/renderer/components/coordinator/src/`:

| Module | Role |
|--------|------|
| `types.ts` | Shared PM types |
| `project-sync.ts` | Projection between `source[]` and derived views |
| `project-data-manager.ts` | `ProjectData` lifecycle and stats |
| `project-persist.ts` | Debounced persist |
| `activity/` | Activity log |

`dayjs` was added to `package.json` as a `ProjectDataManager` dependency.

### Desktop adapters

| Module | Role |
|--------|------|
| `src/pm-api-desktop.ts` | Replaces `fetch('/api/pm/*')` with `coordinatorIpc` |
| `src/state.ts` | Slim workspace state; no Checkpoint, empty `users[]` until sync |
| `workspaceBridge.ts` | Public exports consumed by `CoordinatorManager` |

Swapping the API adapter — rather than editing the spine — is what let `project-sync.ts`
and `project-data-manager.ts` stay close to their web originals.

### SyncOrigin attribution

Every write names where it came from, so the projection knows which derived view to trust
and which to rebuild. The origins wired up across later phases: `'tasks'`, `'kanban'`,
`'table'`, and `'budget2'`, each passed through `persistProject(id, origin)`.

### Debounced persist

`persistProject()` debounces, then writes the whole `.coordproj` over IPC.
`flushPersist()` forces a write, and the shell calls it before a project switch and from
`CoordinatorManager.destroyUI()` on leaving the view — so a debounce window can never
swallow a save.

### Load-time sync

`loadWorkspaceFromDesktop()` hydrates the spine on every view open, running
`projectDataFromSeed` followed by `syncDerivedViews` so stats and derived views are correct
before anything renders. Project rows and the workspace header show synced stats — total,
in progress, completed — from `projectData.stats` rather than raw array lengths.

### Kanban column persistence and Budget2 isolation

Both nuances from the plan are respected: kanban column definitions persist alongside the
projection rather than being re-derived, and `budget2Data` / `budget2Archive` sit outside
the task projection entirely, persisted under their own origin.

## Files changed

- `src/renderer/components/coordinator/src/types.ts` — copied from web.
- `src/renderer/components/coordinator/src/project-sync.ts` — copied from web.
- `src/renderer/components/coordinator/src/project-data-manager.ts` — copied from web.
- `src/renderer/components/coordinator/src/project-persist.ts` — copied from web.
- `src/renderer/components/coordinator/src/activity/` — copied from web.
- `src/renderer/components/coordinator/src/pm-api-desktop.ts` — new IPC adapter.
- `src/renderer/components/coordinator/src/state.ts` — new offline state.
- `src/renderer/components/coordinator/workspaceBridge.ts` — new public surface.
- `package.json` — `dayjs`.

## Verification steps

1. Open **Coordinator** — seeded projects show task counts from synced stats, not raw
   array length.
2. The current-project header shows a stats bar (for example `12 tasks · 3 in progress ·
   5 done`).
3. **Set current** on another project — the stats bar updates.
4. DevTools console shows no failed `/api/pm` fetch calls; the spine modules are IPC-only.

```
npm run build  ✅ success (2026-07-31)
```

## Follow-up work

- **Testing strategy not ported.** The plan devotes a section to it; no desktop-side tests
  for the spine were written, and the projection is covered only by the manual checks
  above and by `verifyPersistRoundTrip()` in the import path — see
  [03-data-model-and-files.complete.md](03-data-model-and-files.complete.md).
- **Checkpoint user roster** — `users[]` stays empty until the sync plan, which is why the
  assign-users modal and the team-workload chart have nothing to show.
- **`load-demo-data.ts`** was not ported; the main-process store seeds disk instead.
