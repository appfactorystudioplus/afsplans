# 04 — Implementation Phases

Step-by-step build order for Flow in Appfactory Studio. **Complete each phase
before starting the next.**

Lessons from Coordinator / Dream Graph / PDF ports:

- Do not add a second global CSS framework
- Scope styles under `#flow-root`
- Build and verify webpack after each phase
- Swap `projectStore` only — keep `DrawView.tsx` logic intact

---

## Phase 0 — Shell placeholder

**Goal:** Navigable empty view proves wiring.

### Tasks

- [ ] Add `flow` to `views.ts`, `html/flow.html`, `FlowManager`
- [ ] Sidebar nav under **Apps** (icon ✏️, label "Flow")
- [ ] i18n keys `sidebar.flow`, `flow.title`
- [ ] Search entry in `search.ts`
- [ ] Extend `get-app-dirs` with `flowDir`
- [ ] Placeholder UI: title + "Open Folder" button (IPC stub)
- [ ] `#main-content.flow-view-active` layout class if needed (full-height editor later)

### Exit criteria

- Click nav → view swaps without error
- Webpack build succeeds (note Excalidraw **not** added yet)
- Other apps unaffected

**Estimate:** 0.5 day

---

## Phase 1 — Local project store

**Goal:** Main-process CRUD on disk; no UI beyond smoke test.

### Tasks

- [ ] `main/flow/store.ts` — create/list/get/save/delete per [03-data-model-and-files.md](03-data-model-and-files.md)
- [ ] `main/flow/paths.ts`, `main/flow/index.ts` — register IPC handlers
- [ ] Wire handlers in `main/index.ts`
- [ ] `renderer/components/flow/ipc.ts` — typed invoke wrappers
- [ ] `renderer/components/flow/storage/types.ts` — copy from web
- [ ] `renderer/components/flow/storage/projectStore.ts` — IPC implementation

### Exit criteria

- DevTools smoke: invoke create → get → save → list → delete via temporary button or unit test in main
- Project folder appears under `~/Documents/afsfiles/flow/projects/`
- Atomic write verified (no corrupt JSON on simulated crash — best effort)

**Estimate:** 1–2 days

---

## Phase 2 — Dashboard

**Goal:** Project list UI; create, open, delete.

### Tasks

- [ ] Port/adapt `flow/dashboard.ts` → `shell/dashboardEvents.ts` + view HTML
- [ ] Coordinator-style `bootstrap.ts` + `router.ts` (dashboard route only)
- [ ] `shell/shellHtml.ts` — minimal internal nav (Dashboard only for now)
- [ ] Create project modal (Bootstrap modal — already in app shell)
- [ ] Delete with confirm (replace `window.confirm` with app modal if desired)
- [ ] Row actions: Open, Edit (stub → Phase 4), Delete
- [ ] `styles/tokens.css`, `styles/shell.css`, `styles/dashboard.css` — brand orange
- [ ] Load workspace on `FlowManager.initUI()`

### Exit criteria

- Create "Test drawing" → appears in list
- Restart app → project still listed
- Delete removes folder from disk
- Open navigates to `#/draw/{ref}` (draw view can be stub "coming soon" until Phase 3)

**Estimate:** 2–3 days

---

## Phase 3 — Draw editor (Excalidraw)

**Goal:** Full canvas edit + save/load.

### Tasks

- [ ] Add `@excalidraw/excalidraw` to `package.json`
- [ ] Copy `views/DrawView.tsx`, `canvasTheme.ts` into `components/flow/`
- [ ] `shell/drawViewHtml.ts` — toolbar (Back, Save, theme menu) + `#flow-draw-mount`
- [ ] Router: `#/draw/{referenceId}` → mount/unmount DrawView
- [ ] Wire Save button → `saveActiveProject()`
- [ ] Wire Back → navigate dashboard; **unmount** React root on exit
- [ ] `styles/draw.css` — full-height layout, hide app chrome overflow issues
- [ ] Handle load error → toast + return to dashboard

### Exit criteria

- Open project → Excalidraw renders
- Draw shapes → Save → reload app → shapes persist
- Switch light/dark/charcoal preset → reflected in UI
- Leaving draw view does not leak React roots (check via repeated open/close)
- No `fetch('/api/flow')` in network tab

**Estimate:** 3–5 days

---

## Phase 4 — Rename + polish

**Goal:** Feature parity with web draw workflow (minus settings teams UI).

### Tasks

- [ ] Port `projectEdit.ts` → `#/edit/{referenceId}` view
- [ ] Dashboard "Edit" action → rename flow
- [ ] Autosave on draw exit (optional toggle — default: prompt or auto-save like web manual save)
- [ ] Open workspace folder button (IPC)
- [ ] Empty state copy + brand pass on dashboard/draw toolbars
- [ ] `FlowManager.destroyUI()` cleanup

### Exit criteria

- Rename project from dashboard edit view
- All Phase 3 tests still pass
- Switching away from Flow view while draw open cleans up without error

**Estimate:** 1–2 days

---

## Phase 5 — Import / export (stretch)

**Goal:** Backup and optional web handoff.

### Tasks

- [ ] Export single project JSON (`flow-project-export/1`) via save dialog
- [ ] Import via open dialog + validation
- [ ] Dashboard actions: Export, Import

### Exit criteria

- Export → delete local → import → scene restored
- Invalid file shows error, no workspace corruption

**Estimate:** 1–2 days

---

---

## Risk register

| Risk | Mitigation |
|------|------------|
| Excalidraw bundle size | Accept; code-split draw route lazy import if needed |
| Excalidraw CSS conflicts | Scope under `#flow-root`; test with dark app shell |
| Electron `window.alert` in dashboard delete | Replace with shared modal component |
| React double-mount bugs | Strict unmount in router; copy Dream Graph patterns |
| Large embedded images in `files` | Document size limits; same as web |

---

## Suggested first sprint

1. Phase 0 + Phase 1 (shell + store)
2. Phase 2 dashboard only
3. Phase 3 spike: one project opens Excalidraw and saves a rectangle

Demo before Phase 4 polish.

---

## Completion checklist (Phases 0–5)

- [x] Sidebar Flow nav works
- [x] Local projects CRUD
- [x] Excalidraw draw/save/load
- [x] Rename project
- [x] Canvas themes
- [x] Brand-styled shell
- [x] Import/export JSON
- [x] Webpack build green
- [x] No regression in Coordinator, Dream Graph, Notes

Notes: [complete/](complete/README.md).
