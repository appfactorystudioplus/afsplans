# 06 — Advanced Features Catalog (Articy-inspired, React Flow edition)

Ordered roughly by narrative-tool value. Phases refer to doc 07. Every canvas-facing
feature notes the React Flow mechanism that makes it cheap.

## 1. Narrative node vocabulary (phase 5) — the heart of the tool

All node types are registry folders (doc 03 §4): zod schema + React component +
inspector fields + palette entry.

| Node type | Pins | Data | Canvas look (React Flow) |
|---|---|---|---|
| **Dialogue** (container specialization) | 1 in / n out | title, description | Tabler card, 💬 badge, color band |
| **Dialogue Fragment** | 1/1 | `speaker_uuid`, `text`, `menu_text`, `stage_directions` | Portrait avatar + speaker band + text body; inline edit on double-click |
| **Hub** | n/1 or 1/n | label | Small circle node — junction/choice point |
| **Choice** (edge-level) | — | `menu_text`, `condition` on the connection | Custom `ChoiceEdge` with label chip + lock icon when gated |
| **Condition** | 1 in / 2 out | `expression` | Diamond; green/red source handles |
| **Instruction** | 1/1 | `script` | Code chip, e.g. `gold += 10` |
| **Jump** | 1 in / 0 out | `target_node_uuid` | Arrow chip; double-click navigates to target (cross-graph) |
| **Container/Flow** | 1/n | doc 04 | Card + child-count badge + Open toolbar |
| **Comment/Note** | 0/0 | markdown, color | Sticky note (`NodeResizer` for sizing) |
| **Group frame** | 0/0 | label, color | React Flow `parentId` subflow — visual-only frame; dragging the frame moves members |
| **Image** | 0/0 | `asset_uuid` | Renders the asset (doc 05) |
| **Entry/Exit** | — | doc 04 §1 | Slim edge-pinned markers |

**Writer ergonomics (the Articy killer features worth cloning):**

- **Tab-to-continue:** with a fragment selected, `Tab` creates a connected next
  fragment and focuses its text box — a linear scene never touches the mouse.
  `Shift+Tab` = sibling choice branch from the same source.
- **Drag-edge-to-empty-canvas → node picker** (`onConnectEnd`): release a connection
  on empty space and a small palette pops to create-and-connect the chosen type —
  Blueprint-style flow building.
- **`@name` speaker completion** in text fields; unknown name → offer to create the
  entity inline.
- **Semantic zoom** (`useStore(zoomSelector)` in node components): below ~40% zoom,
  fragments render as title-only chips; below ~15%, colored blocks. Keeps huge
  canvases legible and fast — Articy's detail-level zoom.
- **Auto-layout of selection** (elkjs layered layout) for untangling a pasted or
  imported chain; full-graph layout stays manual-trigger only (writers hate
  surprise re-layouts).
- **Connection validation** (`isValidConnection`): registry-driven rules — e.g. an
  Exit node accepts no outgoing edges, Condition's true/false handles keep colors.

## 2. Entities: characters, items, factions (phase 5)

- Entity browser panel: kind tabs (Characters / Items / Custom), Tabler cards with
  portrait + color; side-sheet editor (name, technical name, color, portrait via
  asset picker, description, template properties).
- Speaker binding on fragments (`speaker_uuid`); node renders portrait + color band.
- **References view** per entity: every line they speak, every property pointing at
  them ("where does the Bartender appear?") — breadcrumbed, click-to-open.
- **Relationship map** (v2): entities as nodes, typed relations as edges — same
  React Flow canvas, different node/edge types. Cheap to build, very Articy.
- Entities export with UUIDs — game code binds them to prefabs/actors.

## 3. Global variables (phase 5)

- Variable sets = namespaces (`quest.main_progress`, `stats.gold`).
- Panel: table CRUD, type (bool/int/string), default, description; **usage count**
  column (click = search results of referencing scripts).
- Rename refactors script references project-wide (or blocks rename while used, v1).

## 4. Templates & custom properties (phase 5–6)

- Template = named property schema attachable to node types or entities:
  text/number/bool/select/entity_ref/asset_ref/slider fields with config.
- Inspector renders template sections automatically (same field-renderer the
  registry uses); values live in `properties`/`data` JSON.
- This is how game-specific data (quest IDs, audio cues, camera hints) rides along
  and reaches the export without code changes.

## 5. Scripting language (phase 6) — conditions & instructions

A tiny sandboxed expression language (Articy's `articy:expresso` analog):

- **Conditions:** `quest.stage >= 2 && !inventory.has_key` → bool.
- **Instructions:** `stats.gold += 10; quest.stage = 3;`
- Grammar: literals, variable refs (`set.name`), `&& || !`, comparisons, `+ - * /`,
  assignments (`= += -=`), `;` sequencing. **No loops, no user functions**; a small
  built-in whitelist later (`random(a,b)`, `seen("node_uuid")`, `visits(...)`).
- Implementation: ~300-line Pratt parser in TS → AST → interpreter over a variable
  store. **The same evaluator powers play mode, and the grammar ships as a spec** so
  game-side runtimes (C#/GDScript/C++) implement it identically. Never `eval()`.
- Editor: CodeMirror 6 field with variable autocomplete + validation on blur;
  invalid script = red badge on the node + entry in the Problems panel.

## 6. Play / Simulation mode (phase 6) — Articy's Presentation mode

The feature that sells the tool to writers:

- ▶ Play from any node or graph entry. Play panel (dockable) **or full-screen
  presentation view** (portrait, speaker, line — pitch-meeting friendly).
- Choices = outgoing connections' menu texts; condition-failing choices hidden or
  shown-disabled (toggle). Traversal: fragments/instructions auto-advance; Hubs
  present choices; Conditions branch; containers submerge via Entry pins; Exits
  bubble up; Jumps teleport.
- **Variable inspector** alongside: live values, edit-in-place to force branches;
  per-step change highlighting. **Step back** = state snapshot per step.
- **Canvas trace:** current node highlighted & centered; the taken path tints
  edges (React Flow edge `style` per traversal state).
- **Playthroughs / journeys:** save a run (choices + end state) with a name; replay;
  runs get flagged when later edits break them (referenced node deleted).
- **Coverage report** (v2): % of fragments reached across all saved playthroughs —
  finds unreachable content.

## 7. Validation — the Problems panel (starts phase 5, grows forever)

Continuous lint of the story: dead-end fragments, orphan nodes, broken Jump targets,
invalid scripts, undefined variables, fragments with no speaker, containers whose
Exits are unreachable, missing assets. Each problem row = breadcrumbed jump-to-node.
Severity tiers (error/warning/info); export can be gated on zero errors.

## 8. Search, navigation & queries (phase 5, grows continuously)

- **Global search (`Cmd+K`, cmdk):** nodes by text/name, entities, variables,
  assets; grouped results with breadcrumbs; Enter = open graph + select + center.
- Filters: node type, speaker, "uses variable X", "in subtree Y", has-template.
- **Saved queries** (phase 7): power-user filter combos persisted per project
  ("all unvoiced Bartender lines"), pinnable as smart views — Articy's custom
  queries without inventing a SQL dialect.
- Backend: v1 = SQL `LIKE` over generated text columns; v2 = Laravel Scout
  (Meilisearch) with typo tolerance.

## 9. Export & engine integration (phase 7)

- **Canonical JSON export:** manifest + entities + variables + templates + graph
  tree (nested `children`) + all node data, UUIDs and technical names, versioned
  schema (`"schema": "dreamgraph/1"`), deterministic ordering → clean git diffs.
  Documented in `export-schema.md` when implemented.
- **Project bundle (.dgpack):** a zip of `project.json` (the canonical export —
  node placement, all text, asset references) **plus every asset binary**, with
  manifest, subtree export, and safe re-import (new project / restore / merge
  into another project). Full design: **doc 09**.
- **CSV/XLSX text export** for localization (one row per fragment field per language).
- **Canvas PNG/SVG export** (React Flow viewport snapshot via `html-to-image`) —
  design docs and pitch decks.
- Later: importer packages for Unity (ScriptableObjects) / Unreal (DataTables) /
  Godot (Resource) + the expression-evaluator runtime in C#/GDScript.
- Webhook/CLI: `POST /export` + signed download URL → CI pulls narrative data.

## 10. Localization & voice-over (phase 7–8)

- Every text field keyed (`{node_uuid}.{field}`); `texts` table per language;
  authoring language in the editor + a translation grid view + CSV round-trip.
- Word counts per language/speaker in the stats panel.
- **Voice-over management:** bind audio assets to fragments
  (naming-convention auto-match `VO_{technical_name}.*` + manual override);
  coverage report (lines without takes); play mode plays bound audio — the demo
  becomes a radio play.

## 11. Reports & statistics (phase 7)

Project dashboard: total words, words per speaker, per chapter/subtree; node counts
by type; branch factor stats; longest path estimate; asset storage usage; recently
edited graphs. Exportable CSV. (Producers ask for this constantly; Articy calls
them reports.)

## 12. Import (phase 8)

- **Twine (Twee 3)** importer — passages → fragments, links → connections; the
  single biggest onboarding funnel for indie writers.
- **articy JSON** importer (their export format) — flows/entities/variables mapped
  best-effort with a report of what didn't translate.
- Import always creates a snapshot first and lands in a new top-level container.

## 13. Collaboration & versioning (phase 8)

- **Snapshots** first (cheap): manual + nightly + pre-import/restore; diff view
  (added/removed/changed nodes list, text diffs per fragment).
- **Presence** (Laravel Reverb websockets): avatars of who's in which graph; soft
  **graph locking** ("Alice is editing Tavern Scene — read-only for you") is 10% of
  the cost of CRDTs and solves 90% of team friction. Yjs CRDT only if concurrent
  same-graph editing becomes a proven need.
- **Comments/review:** threads pinned to nodes (`comments` table), resolve flow,
  reviewer role (read+comment); comment markers as a React Flow node badge.

## 14. Optional AI assists (clearly out of core scope, phase 9+ if ever)

Kept separate deliberately: draft alternative line phrasings, tone/consistency
checks against an entity's voice sheet, summarize a subtree. All opt-in,
never auto-writing into the document. Listed for roadmap completeness only.

## 15. Quality-of-life backlog (sprinkle into every phase)

Minimap (built-in) · box/lasso multi-select + arrow-nudge · copy/paste incl.
cross-graph with reference preservation · duplicate · align/distribute · helper
lines · edge reroute handles · node color swatches + project legend · canvas context
menus · command palette (`Cmd+K` doubles as action runner) · configurable snap grid ·
autosave indicator + offline banner · project duplicate/template projects · sample
"Tavern Demo" project seeded on first login · spell-check toggle (browser native +
custom dictionary per project) · focus mode (dim everything but the selected chain).
