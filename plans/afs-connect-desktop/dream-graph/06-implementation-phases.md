# 06 — Implementation Phases

Step-by-step build order for Dream Graph in Appfactory Studio. **Complete each
phase before starting the next.** Exit criteria are mandatory.

Aligns with PDF/Notes incremental port lessons:

- Do not monolith-copy the entire web app in one PR
- Do not add a second CSS framework to the app shell
- Do not modify unrelated views
- Build and verify webpack after each phase

---

## Phase 0 — Shell placeholder

**Goal:** Navigable empty view proves wiring.

### Tasks

- [ ] Add `dreamGraph` to `views.ts`, `html/dreamGraph.html`, `DreamGraphManager`
- [ ] Sidebar nav under **Apps** (icon 🕸️ or similar)
- [ ] i18n keys `sidebar.dreamGraph`, `dreamGraph.title`
- [ ] Search entry
- [ ] Extend `get-app-dirs` with `dreamGraphDir`
- [ ] Placeholder UI: "Dream Graph — coming soon" + Open Folder button

### Exit criteria

- Click nav → view swaps without error
- Webpack build succeeds (~bundle size note in commit message)
- Old apps unaffected

**Estimate:** 0.5–1 day

---

## Phase 1 — Local project CRUD

**Goal:** Create/open/list projects on disk.

### Tasks

- [ ] `main/dreamGraph/store.ts` — list/create/open/save `manifest.json`
- [ ] IPC handlers registered in `main/dreamGraph/index.ts`
- [ ] `renderer/components/dreamGraph/ipc.ts`
- [ ] Projects list UI (simple HTML or minimal React)
- [ ] Seed empty root graph JSON on create (Entry node optional Phase 2)

### Exit criteria

- Create "Test Project" → folder appears under `~/Documents/afsfiles/dream-graph/projects/`
- Restart app → project still listed
- Delete project (with confirm) optional but nice

**Estimate:** 2–3 days

---

## Phase 2 — Graph editor MVP

**Goal:** Edit story graphs offline.

### Sub-phases

**2a — Canvas + core nodes**

- [ ] Webpack: add `@xyflow/react`, `zustand`
- [ ] Copy graph modules (see [04-graph-editor.md](04-graph-editor.md))
- [ ] `LocalStorageAdapter` for graph load/save
- [ ] Mount `EditorShell` without auth
- [ ] Node types: fragment, hub, group, note, comment

**2b — Branching**

- [ ] condition, instruction nodes
- [ ] Variable catalog stub (in-memory JSON)

**2c — Nesting**

- [ ] container, entry, exit
- [ ] Breadcrumb + graph tree
- [ ] Port nested graph creation logic

### Exit criteria

- Full graph editing per [04-graph-editor.md](04-graph-editor.md) acceptance tests
- 5s autosave to disk; manual save works
- No API calls

**Estimate:** 1–2 weeks

---

## Phase 3 — Catalogs and assets

**Goal:** Image nodes, entity references, variables for play mode.

### Tasks

- [ ] Asset pickers write files to `assets/` via IPC
- [ ] `catalogs/*.json` load/save
- [ ] `image` node type enabled
- [ ] Entity + variable set editors (port panels)

### Exit criteria

- Insert image node from local PNG
- Thumbnail displays after restart
- Variables editable in catalog panel

**Estimate:** 1 week

---

## Phase 4 — Play mode (local)

**Goal:** Simulate story in desktop without server playthrough save.

### Tasks

- [ ] Port `TraversalEngine`, scripting interpreter
- [ ] `PlayModePanel` docked in editor
- [ ] `PresentationMode` fullscreen optional
- [ ] Variable inspector during play

### Exit criteria

- Walk hub branches with conditions evaluating correctly
- Instructions mutate variable state in session
- No crash on unknown expression — show error inline

**Estimate:** 1 week

---

## Phase 5 — Tilemap subsystem

**Goal:** Full tilemap editor parity.

See [05-tilemap-subsystem.md](05-tilemap-subsystem.md).

### Sub-phases

**5a — Core paint:** TilemapView, Surface, brush/erase/pan, palette, layers  
**5b — Layers + tools:** rect, fill, picker, inspector  
**5c — Autotile:** rules editor + terrain tool  
**5d — Polish:** minimap, export dialog UI  
**5e — Graph link:** `tilemap_ref` open/close navigation  

### Exit criteria

- All tilemap acceptance tests in doc 05 pass
- Chunk RLE round-trip matches web codec

**Estimate:** 2–3 weeks

---

## Phase 6 — Import / export bundles

**Goal:** Interoperability with web Dream Graph and engine.

### Tasks

- [ ] Port `.dgpack` export to main process (sync)
- [ ] Import `.dgpack` → create `.dgproj` folder
- [ ] `joystick-tilemap/1` export/import
- [ ] Localization CSV optional
- [ ] Stats panel (client-side compute)

### Exit criteria

- Export project from desktop → import on web (same member) — **stretch**
- Export tilemap zip → import in Joystick engine — **required**
- Bundle round-trip without data loss for graph + tilemap

**Estimate:** 1–2 weeks

---

## Risk register

| Risk | Mitigation |
|------|------------|
| Webpack bundle too large | Code-split tilemap view lazy import |
| PHP export logic hard to port | Port to Node script shared with web or reimplement from tests |
| React Flow Electron bugs | Phase 0 spike; pin @xyflow version |
| Chunk RLE mismatch | Shared test vectors from web Vitest + desktop unit tests |
| Scope creep | Strict phase gates; tilemap only after graph MVP stable |

---

## Suggested first sprint (after plan approval)

1. Phase 0 + Phase 1 (shell + project CRUD)
2. Phase 2a spike: single graph loads in Electron with one fragment node

Stop and demo before committing to full module copy.

---

## After offline MVP

Cloud sync, studio authentication, and engine live sync are **not** Dream Graph phases 7–8. They live in the progressive plan:

**[../account-and-cloud-sync/README.md](../account-and-cloud-sync/README.md)**
