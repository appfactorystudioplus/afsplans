# 02 — Product Vision & Articy Draft Comparison

## 1. Vision

**Dream Graph is a browser-based narrative design tool for game teams.** Writers and
designers build branching stories as node graphs: dialogues, choices, conditions, and
consequences. Everything a game needs — characters, variables, assets, localizable
text — lives in one project and exports cleanly to game engines.

Positioning: "Articy Draft in the browser" — no install, collaborative by design,
with a simpler learning curve.

### Target users

- **Narrative designers / writers** — spend all day in the flow editor and play mode.
- **Game designers** — variables, conditions, templates, balancing.
- **Programmers** — consume the JSON export; care about stable IDs and schemas.
- **Producers/reviewers** — read-only review, comments, word counts.

### Design principles

1. **The document model is the product.** The canvas library, panels, and even the
   backend serialize one well-specified JSON project model. Export = the model.
2. **Writer-first ergonomics.** Keyboard-driven fragment creation (Tab to chain a new
   dialogue line), inline text editing, fast search. A writer should rarely touch the mouse.
3. **Everything addressable.** Every node/entity/variable/asset has a stable UUID and
   a human "technical name" — the contract with game code.
4. **Depth through nesting, not canvas sprawl.** Container nodes with child graphs
   (doc 04) keep any single canvas readable.

## 2. Articy Draft feature map → what we build

| Articy Draft feature | What it is | Our plan | Phase |
|---|---|---|---|
| **Flow editor** | Node canvas for narrative flow | Core editor rebuild with narrative node types | 1–2 |
| **Flow Fragments** (submerge) | Container nodes holding sub-flows, unlimited nesting | **Nested graphs** — doc 04 | 3 |
| **Dialogues & Dialogue Fragments** | Speaker + spoken text + stage directions + menu text | Dialogue / DialogueFragment node types bound to entities | 5 |
| **Hubs & Jumps** | Junction points; links to any node anywhere | Hub node; Jump node with cross-graph target picker | 2, 5 |
| **Conditions & Instructions** | Script nodes that branch on / mutate variables | Condition + Instruction nodes with expression DSL | 6 |
| **Input/Output pins with scripts** | Pins can carry conditions/instructions | Pin-level condition/instruction fields (v2 of scripting) | 6 |
| **Entities** | Characters, items, factions with templates & portraits | Entity system + entity browser panel | 5 |
| **Global variables** | Namespaced bool/int/string variable sets | Variable sets + variables panel, usage tracking | 5 |
| **articy:expresso scripting** | C-like expression language in nodes | Small sandboxed expression language (doc 06 §4) | 6 |
| **Presentation / Simulation mode** | Play the story, make choices, watch variables | Play mode traversing the graph in-browser | 6 |
| **Templates** | User-defined property schemas on any object | Template system: property definitions + values | 5–6 |
| **Assets** | Imported images/audio/video with metadata | **Asset library** — doc 05 | 4 |
| **Locations** | Maps/floorplans with pins and zones | Location editor over an image asset (v2, optional) | 8 |
| **Journeys** | Saved simulation paths for QA | Saved playthroughs in play mode | 6 |
| **Search & Navigation** | Global search, filtered queries | Project-wide search index + jump-to-node | 5+ |
| **Word counts / reports** | Writing statistics | Project statistics panel | 7 |
| **Localization** | Text export/import per language | Localization-ready text table + CSV/XLIFF export | 7 |
| **Unity/Unreal importers** | Official engine plugins | Documented stable JSON export first; engine plugins later | 7 |
| **Multi-user server, branching** | Server projects, partial claims/locks | Real-time presence + node locking (later CRDT) | 8 |
| **Version control** | Project history | Snapshots + restore; diff view | 8 |
| **Voice-over management** | Match audio takes to dialogue lines | VO asset ↔ fragment binding + coverage report | 7–8 |
| **Custom queries** | SQL-like search over project objects | Saved filter queries (power-user search) | 7 |
| **Detail-level zoom** | Nodes simplify as you zoom out | Semantic zoom via React Flow zoom-aware nodes | 2–3 |
| **Reports** | Word counts, object statistics | Statistics panel + per-speaker/branch reports | 7 |
| **Import** | XLSX/other project import | Twine (Twee) + articy JSON importers | 8 |

### Deliberately skipped (v1)

- Windows desktop client, DLC/marketplace, articy's XLSX template import, voice-over
  pipeline management, and the full location zoning toolset. These are either
  desktop-specific or low value relative to cost for a v1.

## 3. Canonical user journeys

1. **Write a scene**: create project → create "Chapter 1" container → open it (nested
   graph) → chain dialogue fragments with Tab → assign speakers by typing `@guard` →
   branch with a Hub + two choices → gate one choice with `Condition: met_guard == true`.
2. **Art pass**: open Assets panel → drag 12 portraits in → tag them `portraits` →
   assign to characters → portraits now render on every dialogue node of that speaker.
3. **Playtest**: hit ▶ Play → read lines, pick choices → watch the variable inspector
   flip `met_guard` → save the playthrough as "QA path A".
4. **Ship it**: Export → JSON → programmer loads it with the documented schema; node
   UUIDs match what the writer sees in the inspector.
