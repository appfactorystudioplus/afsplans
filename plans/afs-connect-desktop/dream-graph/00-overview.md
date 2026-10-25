# 00 — Overview

## Problem statement

**Dream Graph** (`apps/dream_graph`) is a web-based narrative design tool: story
graphs (React Flow), scripting/conditions, asset catalogs, and an integrated
**tilemap editor** for level layout. Members use it in the browser with data
stored in MySQL via Laravel APIs and Checkpoint authentication.

**Appfactory Studio** (`apps/afsdesktop5`) is the Electron desktop shell. It
already hosts offline-capable apps (Notes Pro, PDF) using a consistent pattern:
renderer manager + main-process store + IPC + local files under
`~/Documents/afsfiles/`.

Product need: bring Dream Graph into the desktop app so writers and designers
can work **without a browser tab**, **without network**, and with projects
stored locally — with optional **cloud sync** later (after a separate Appfactory
Studio authentication plan; not covered here).

## Goals (offline v1)

1. **Open, edit, and save Dream Graph projects locally** — no server required.
2. **Graph editor parity** — canvas, node types, nested graphs, inspector,
   play mode (local simulation).
3. **Tilemap editor parity** — paint tools, layers, tilesets, chunk persistence,
   undo/redo, export to engine interchange format.
4. **Import/export** — `.dgpack` bundles compatible with the web app where
   practical (round-trip is a stretch goal for early phases).
5. **Same UX shell** as web where possible — reuse TypeScript/React modules
   from `dream_graph/resources/js` with a **storage adapter** swap.
6. **Incremental delivery** — placeholder view first, then graph, then tilemap,
   matching PDF/Notes port discipline.

## Goals (future — separate plans)

7. **Cloud sync** — see [../account-and-cloud-sync/01-cloud-sync.md](../account-and-cloud-sync/01-cloud-sync.md).
8. **Engine tilemap live sync** — see [../account-and-cloud-sync/02-engine-live-sync.md](../account-and-cloud-sync/02-engine-live-sync.md).
9. **afs-connect** metadata visibility in coordinator (already exists for web;
   desktop-hosted projects may register later).

> **Out of scope:** Checkpoint / studio sign-in — tracked in
> [../account-and-cloud-sync/](../account-and-cloud-sync/) (progressive), not this Dream Graph port plan.

## Non-goals (offline v1)

- **Not** running Laravel or MySQL inside Electron.
- **Not** multi-user realtime collaboration on one open project file.
- **Not** settings/teams UI that depends on web PM API (stub or hide).
- **Not** queued export jobs — synchronous export in main process instead.
- **Not** AFS Connect ingest on every local save.
- **Not** replacing the web app — both coexist; desktop is offline-first.
- **Not** porting every web feature on day one (see phased plan).

## Glossary

| Term | Meaning |
|------|---------|
| **Dream Graph** | Laravel + React SPA at `apps/dream_graph` |
| **Graph** | A single React Flow document (`graph_graphs` row): nodes + connections |
| **Project** | Top-level container (`graph_projects`): catalogs, tilemaps, root graph |
| **Node** | Typed vertex (fragment, hub, condition, tilemap_ref, container, …) |
| **Tilemap** | 2D tile layer document with sparse 32×32 chunks |
| **Chunk** | RLE-encoded tile region; key `(layerUuid, cx, cy)` |
| **`.dgpack`** | Zip bundle export format (project or subtree) |
| **`joystick-tilemap/1`** | Engine interchange zip for tilemaps |
| **Origin block** | Engine `.jmeta` UUID linking local asset ↔ remote tilemap |
| **Storage adapter** | Interface replacing `fetch('/api/...')` with IPC or HTTP |
| **Offline store** | Main-process module persisting project tree to disk |

## Relationship to web Dream Graph

| Layer | Web today | Desktop offline v1 |
|-------|-----------|-------------------|
| UI | React in Blade shell | Same React modules in Electron `#main-content` |
| State | Zustand stores | Same stores; adapter changes persistence |
| Auth | Web session (Checkpoint on web) | No auth gate in offline v1 |
| Persistence | Laravel + MySQL | JSON + binary files via IPC |
| Assets | Laravel Storage + `/api/assets/{uuid}` | Files in project folder |
| Tilemap save | `PUT .../chunks` + version lock | Local version field + atomic file write |
| Export | Queue jobs + poll | Main-process sync zip write |

## Relationship to Joystick Engine

The engine is a **client** of Dream Graph for tilemaps (pull/push with sync
tokens). Desktop Dream Graph offline mode does **not** require engine connectivity.
Future phases may:

- Export `joystick-tilemap/1` zips for manual import into engine (same as web).
- Optionally run a local sync client that talks to cloud Dream Graph when
  online (mirror `DreamGraphSync` plan in JoystickEngine_v2).

See [../account-and-cloud-sync/02-engine-live-sync.md](../account-and-cloud-sync/02-engine-live-sync.md).

## Apps and repos involved

| Repo / app | Role in this plan |
|------------|-------------------|
| `apps/dream_graph` | Source UI + domain logic to port/adapt |
| `apps/afsdesktop5` | Target Electron shell |
| `apps/afsserver/plans/afs-connect-desktop/dream-graph/` | This plan |
| `JoystickEngine_v2/plan/dream-graph-sync-system.md` | Engine sync protocol context |
| `apps/www/_docs/tilemap/08-live-sync-protocol.md` | Canonical tilemap sync API |

## Success criteria (offline MVP)

A user can, without network:

1. Create or open a local Dream Graph project from Appfactory Studio sidebar.
2. Edit a story graph with fragment/hub/condition nodes and save reliably.
3. Open a tilemap from a `tilemap_ref` node, paint tiles, undo, save.
4. Export a tilemap as `joystick-tilemap/1` zip for engine handoff.
5. Close and reopen the app — project state intact.

Cloud sync is **explicitly deferred** to
[../account-and-cloud-sync/](../account-and-cloud-sync/) with schema hooks documented in [03-data-model-and-files.md](03-data-model-and-files.md).
