# Flow Desktop Port — Completion Notes

**Status:** ✅ Complete (plan phases 0–5)
**Date:** 2026-08-01
**Target app:** `afsdesktop5` (Appfactory Studio)
**Plan reference:** [04-implementation-phases.md](../04-implementation-phases.md)

Flow (Excalidraw drawing projects) is ported to Appfactory Studio as a local-first desktop
module. Users can create/list/open/rename/delete projects, draw on an Excalidraw canvas,
save/load scenes, switch canvas themes, and import/export JSON backups.

## Completion documents

One completion document per plan document — source basename plus `.complete.md`, recording
status, summary, files changed, verification steps, and follow-up work.

| Doc | Completion | Status |
|-----|------------|--------|
| [00-overview.md](../00-overview.md) | — | Framing; no code |
| [01-web-app-audit.md](../01-web-app-audit.md) | — | Audit of the web app; no code |
| [02-desktop-architecture.md](../02-desktop-architecture.md) | [02-desktop-architecture.complete.md](02-desktop-architecture.complete.md) | ✅ Complete |
| [03-data-model-and-files.md](../03-data-model-and-files.md) | [03-data-model-and-files.complete.md](03-data-model-and-files.complete.md) | ✅ Complete |
| [04-implementation-phases.md](../04-implementation-phases.md) | [04-implementation-phases.complete.md](04-implementation-phases.complete.md) | ✅ Complete |

## Phase-to-document mapping

The plan's build order is phased; these notes are filed by subject document. Phases 0, 2,
3, and 4 landed the shell, router, editor, and cleanup — recorded in
[02-desktop-architecture.complete.md](02-desktop-architecture.complete.md). Phases 1 and 5
landed the on-disk store and the export format — recorded in
[03-data-model-and-files.complete.md](03-data-model-and-files.complete.md). The full table
is in
[04-implementation-phases.complete.md](04-implementation-phases.complete.md).

## Build verification

```
npm run build  ✅ success (2026-08-01)
```

Added dependency: `@excalidraw/excalidraw` (currently `^0.18.1`).
Webpack aliases for `roughjs/bin/*`, matching the web Flow vite config.

## Local data path

`~/Documents/afsfiles/flow/projects/{reference_id}/project.json`

## What is not here

Cloud sync — see
[../../account-and-cloud-sync/05-flow-cloud-sync.md](../../account-and-cloud-sync/05-flow-cloud-sync.md).
It is blocked on desktop studio auth (phase C1), which has not started.
