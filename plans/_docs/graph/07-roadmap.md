# 07 — Implementation Roadmap (React Flow + Laravel)

Phases are shippable increments; each ends with the app in a usable state.
Sizes are rough relative t-shirt estimates for one developer.

## Phase 0 — Housekeeping & React Flow foundation (S/M)

- [ ] Remove unrelated code from `resources/js`: `appfactory3.ts`, `checkpoint/`,
      `app/` (notes app), `auth/`, `globalAuth.ts`, `appwindow.ts`; drop the
      `appfactory.studio/sdk.js` script tag if unused; delete unrelated binaries
      under `resources/js/assets/`; verify `.vscode/sftp.json` holds no credentials.
- [ ] Remove Drawflow + its CDN tags, Font Awesome CDN, and the old
      `graph-editor.ts`/`home.blade.php` editor internals (keep the Tabler shell).
- [ ] Add React toolchain: `@vitejs/plugin-react`, React 18, `@xyflow/react`,
      `zustand`; new Vite entries (fixes the `app.js`/`app.ts` mismatch) — doc 08 §1.
- [ ] Blade shell page + `main.tsx` mount; `canvas/theme.css` mapping `--xy-*` vars
      to Tabler tokens; dark-mode `colorMode` sync — doc 08 §2.
- [ ] Scaffold `resources/js/graph/` structure (doc 03 §4): store, api client,
      NodeTypeRegistry with two placeholder types, `GraphCanvas.tsx` with
      Background/MiniMap/Controls, palette drag→drop creation.

**Exit:** the old demo is gone; a React Flow canvas in Tabler dark/light theme can
place and connect placeholder nodes at 60 fps.

## Phase 1 — Persistence, projects, auth (M)

- [ ] Migrations: `projects`, `graphs`, `nodes`, `connections` (doc 03 §2).
- [ ] Laravel Breeze (or the umbrella app's auth) + `ProjectPolicy` on all routes.
- [ ] API: projects CRUD, `GET /graphs/{g}`, `PUT /graphs/{g}/content`,
      node/connection endpoints; FormRequests + JSON-Schema `data` validation;
      Pest tests per endpoint.
- [ ] Projects dashboard page (list/create/open) replacing the "Custom" stub tab.
- [ ] ProjectStore + typed API client; command-based undo/redo; autosave
      (debounced batches, version stamp, 409 handling, dirty indicator).
- [ ] History-API routing `/p/{project}/g/{graph}`.

**Exit:** graphs survive reload; undo/autosave/conflict flows work; projects isolated
per user.

## Phase 2 — Editor core & writer basics (M)

- [ ] Inspector panel driven by NodeTypeRegistry (select node/edge → edit data,
      name, color); `nodrag` interaction polish inside nodes.
- [ ] First narrative types replace placeholders: **Fragment (text-only), Hub,
      Comment (resizable), Group frame** (`parentId` subflow).
- [ ] Multi-select box, copy/paste/duplicate, delete-confirm, arrow-nudge,
      align/distribute, snap grid, helper lines.
- [ ] Canvas context menus; custom `ChoiceEdge` with labels.
- [ ] **Tab-to-continue** chaining + drag-edge-to-canvas quick-create picker
      (`onConnectEnd`).
- [ ] Semantic zoom v1 (title-only chips below 40% zoom).

**Exit:** a writer can outline a branching scene comfortably — fast, keyboard-driven,
readable when zoomed out.

## Phase 3 — Nested graphs (M) — doc 04

- [ ] `container` node type (+`NodeToolbar` Open button, double-click submerge,
      child-count badge) + `POST /nodes/{node}/child-graph` + Entry/Exit seeding.
- [ ] Breadcrumbs, Esc-to-parent (select+center the container), browser history,
      per-graph viewport memory, per-graph undo partitioning.
- [ ] Graph tree sidebar panel (live outline of the containment tree).
- [ ] Convert-selection-to-container refactor (`POST /graphs/{g}/wrap`).
- [ ] Cascade delete with descendant-count warning; deep-copy paste.

**Exit:** doc 04 §5 acceptance criteria pass.

## Phase 4 — Asset management (M) — doc 05

- [ ] `assets` + `asset_folders` migrations; medialibrary (or slim service) +
      thumbnail queue; signed serving route; MIME/quota validation; SVG sanitize.
- [ ] Assets panel (folder tree, virtualized grid, react-dropzone upload queue,
      details drawer, tags, search).
- [ ] `AssetPicker` component; Image node; canvas file-drop → upload + node.
- [ ] Usages endpoint + safe delete + replace-in-place; checksum dedupe prompt.

**Exit:** doc 05 §5 acceptance criteria pass. (Independent of Phase 3 — can run in
parallel.)

## Phase 5 — Story systems: entities, variables, dialogue (L)

- [ ] `entities`, `variable_sets`, `variables`, `templates`, `template_properties`
      migrations + CRUD APIs + panels.
- [ ] Dialogue + DialogueFragment node types (speaker binding, portrait on node,
      menu text, stage directions); `@name` completion; Jump node with cross-graph
      target picker and double-click-to-follow.
- [ ] Templates v1: define schemas, attach, Inspector renders them.
- [ ] Global search (`Cmd+K`, cmdk) + entity references view + variable usage counts.
- [ ] Problems panel v1 (orphans, broken jumps, missing speakers).

**Exit:** user journeys 1 & 2 (doc 02 §3) work end-to-end.

## Phase 6 — Scripting & play mode (L)

- [ ] Expression parser/interpreter (TS, Pratt) + exhaustive Vitest suite;
      Condition/Instruction node types; CodeMirror script fields with variable
      autocomplete + validation badges; connection-level conditions/menu gating.
- [ ] Play mode: traversal engine (container Entry/Exit + Jump semantics), play
      panel + full-screen presentation view, variable inspector with edit-in-place,
      step-back, canvas path highlight/centering.
- [ ] Playthrough save/replay; problems panel adds script errors + undefined vars.

**Exit:** user journey 3 works; a full demo story plays start-to-finish; grammar
spec document published.

## Phase 7 — Export, import bundles, localization, reports (M/L)

- [ ] Canonical JSON export (`dreamgraph/1`, deterministic order) as queued job +
      signed download + webhook/CLI fetch; `export-schema.md`.
- [ ] **Bundle export/import (.dgpack, doc 09):** zipstream export (project +
      subtree) with manifest + `project.json` + all assets; hardened import
      (zip-slip/bomb guards, MIME re-sniff, quota) with new/restore/subtree-merge
      modes, dry-run report, checksum asset dedupe; CI round-trip test.
- [ ] CSV/XLSX text export/import round-trip; `texts` table + translation grid view.
- [ ] Statistics panel (words per speaker/subtree, node counts, storage) + saved
      search queries; canvas PNG export.
- [ ] Sample Unity (or Godot) consumer repo proving schema + expression spec.

**Exit:** user journey 4 works; localization round-trips; producers get reports.

## Phase 8 — Collaboration, VO, import, polish (L, ongoing)

- [ ] Snapshots + restore + diff view; project duplicate/templates.
- [ ] Reverb websockets: presence avatars, graph soft-locking; node comment threads
      + reviewer role.
- [ ] Voice-over binding + coverage report; play mode plays bound audio.
- [ ] Twine (Twee 3) importer; articy JSON importer (best-effort + report).
- [ ] Performance pass (500+ node graphs, `onlyRenderVisibleElements` tuning),
      Playwright E2E suite, seeded "Tavern Demo" onboarding project.
- [ ] Optional: locations editor (image map + pins), entity relationship map.

## Dependency notes

- Phase 3 (nesting) needs 1 (persistence) but not all of 2's polish.
- Phase 4 (assets) is independent of 3 — parallelizable.
- 5 needs 2 (inspector/registry); 6 needs 5 (variables); 7 needs 5–6 to be
  meaningful; 8 items are largely independent of each other.

## Risks

| Risk | Mitigation |
|---|---|
| React island bloats into app-wide React creep | Hard rule: React lives under `resources/js/graph/`; Blade owns everything else |
| Node components re-render storms on big graphs | Referentially-stable `data`, `memo` everywhere, drag-end-only commits, semantic zoom (doc 08 §3–5) |
| Autosave conflicts / data-loss fears | Version stamp + 409 flow in Phase 1; snapshots pulled early if cheap |
| Scripting scope creep | Freeze the doc 06 §5 grammar for v1; additions only via built-in whitelist |
| Export schema churn breaking game pipelines | Version from day one; never mutate `dreamgraph/1`, only add `dreamgraph/2` |
| JSON `data` blobs become unqueryable | Registry validates on write; promote hot fields to columns when profiling says so |
| xyflow abandonment (unlikely) | Document model + command layer are library-free; only `canvas/` + node components would need porting |
