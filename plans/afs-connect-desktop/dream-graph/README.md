# Dream Graph — Desktop (Appfactory Studio) Port Plan

Port the web **Dream Graph** app (`apps/dream_graph`) into the Electron desktop
**Appfactory Studio** (`apps/afsdesktop5`) as an **offline-first** editor. Cloud
sync and engine live-sync live in a **[separate progressive plan](../account-and-cloud-sync/README.md)**;
**Checkpoint sign-in is also separate** and is not part of this document set.

## How to use this plan

Work **phase by phase**. Each phase has exit criteria. Do not skip Phase 0–2;
they establish the same patterns used successfully for PDF and Notes Pro.

| Doc | Purpose |
|-----|---------|
| [00-overview.md](00-overview.md) | Goals, non-goals, glossary, relationship to web + engine |
| [01-web-app-audit.md](01-web-app-audit.md) | What Dream Graph is today (architecture, features, gaps) |
| [02-desktop-architecture.md](02-desktop-architecture.md) | Electron shell, IPC, storage adapter, view wiring |
| [03-data-model-and-files.md](03-data-model-and-files.md) | Entities, on-disk layout, version locking offline |
| [04-graph-editor.md](04-graph-editor.md) | Story graph (React Flow) port scope |
| [05-tilemap-subsystem.md](05-tilemap-subsystem.md) | Tilemap editor, chunks, RLE, autotile, engine interchange |
| [06-implementation-phases.md](06-implementation-phases.md) | **Step-by-step build order with exit criteria** |

Cloud sync and engine live sync: **[../account-and-cloud-sync/README.md](../account-and-cloud-sync/README.md)** (progressive plan — not part of offline MVP).

## Related documents (outside this folder)

| Document | Relevance |
|----------|-----------|
| `apps/dream_graph/` | Source web app |
| `JoystickEngine_v2/plan/dream-graph-sync-system.md` | Engine ⇄ web tilemap live sync protocol |
| `JoystickEngine_v2/plan/_guides/afs_connect_wiring.md` | Engine hosted-source context (not desktop auth) |
| `apps/www/_docs/tilemap/08-live-sync-protocol.md` | Wire protocol for tilemap pull/push (canonical) |
| `apps/afsserver/plans/afs-connect/` | How Dream Graph appears in coordinator today |
| `apps/afsdesktop5/src/renderer/components/notesPro/` | Reference desktop port pattern (IPC store, manager, shell) |

## Desktop port pattern (match afsdesktop5)

Follow the incremental approach used for **PDF** and **Notes Pro**:

- New view key in `views.ts` (e.g. `dreamGraph`) — **do not modify** unrelated views
- HTML template string + `DreamGraphManager.initUI()` on navigate
- Styles scoped under `#dreamGraph-root` in global `style.css` or co-located CSS via `style-loader`
- **Main process** owns file I/O (`main/dreamGraph/store.ts` mirroring `main/notesPro/store.ts`)
- **Renderer** talks only through IPC (`components/dreamGraph/ipc.ts`)
- No Laravel, no web auth redirect, no `fetch('/api/...')` in offline v1

## Phase summary (headline)

| Phase | Deliverable |
|-------|-------------|
| **0** | Placeholder view + nav + i18n |
| **1** | Local project folder + open/save manifest |
| **2** | Graph editor MVP (canvas, core node types, autosave to disk) |
| **3** | Catalogs (assets, variables, entities) local |
| **4** | Play mode (local traversal, no cloud playthrough) |
| **5** | Tilemap editor (full subsystem) |
| **6** | Import/export `.dgpack` parity with web |

Cloud sync and engine live sync are tracked in **[../account-and-cloud-sync/](../account-and-cloud-sync/)** (after offline MVP + studio auth).

Detail and acceptance tests: [06-implementation-phases.md](06-implementation-phases.md).
