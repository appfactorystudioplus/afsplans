# Dream Graph — Story Node Graph Application Plan

**Source app:** `apps/dream_graph` (Laravel 12 + Vite + TypeScript)
**Canvas:** **React Flow (`@xyflow/react`)** mounted as a React island in the
existing Blade/Tabler shell — themed with Tabler tokens and the project's brand colors.
**Goal:** Evolve the current prototype into a full storytelling node-graph
application for game narrative design, in the spirit of **Articy Draft**
(https://www.articy.com) — dialogue flows, characters, variables, assets,
nested graphs, scripting, simulation, and game-engine export.

## Document index

| Doc | Contents |
|-----|----------|
| [01-current-state-analysis.md](01-current-state-analysis.md) | What exists today in `apps/dream_graph`: stack, code walkthrough, gaps, housekeeping issues, and the canvas-library decision (React Flow) |
| [02-vision-and-articy-comparison.md](02-vision-and-articy-comparison.md) | Product vision, target users, Articy Draft feature map and what we adopt/skip |
| [03-architecture-and-data-model.md](03-architecture-and-data-model.md) | React-island + Laravel architecture, full database schema, API design, frontend structure (Zustand document model, NodeTypeRegistry, command undo) |
| [04-feature-nested-graphs.md](04-feature-nested-graphs.md) | **Requested feature:** nodes that contain child graphs, unlimited levels deep — full design incl. React Flow implementation |
| [05-feature-asset-management.md](05-feature-asset-management.md) | **Requested feature:** asset upload + management (images, audio, video) — full design |
| [06-advanced-features.md](06-advanced-features.md) | Advanced feature catalog: dialogue nodes, entities, variables & scripting, play/simulation mode, templates, semantic zoom, problems panel, saved queries, reports, voice-over, Twine/articy import, export, localization, collaboration |
| [07-roadmap.md](07-roadmap.md) | Phased implementation roadmap (0–8) with task breakdowns, exit criteria, dependencies, and risks |
| [08-react-flow-integration.md](08-react-flow-integration.md) | React Flow ⇄ Laravel/Vite/Tabler integration: mounting, theming with Tabler tokens + dark mode, canvas wiring, feature→mechanism map, testing & pitfalls |
| [09-bundle-export-import.md](09-bundle-export-import.md) | **Requested feature:** `.dgpack` zip bundle — all assets + `project.json` configuration (node placement, text, asset refs); export (project/subtree) and hardened import (new/restore/merge, dry-run) |

## One-paragraph summary

Today the app is a **client-side-only Drawflow canvas demo**: draggable demo nodes
(math/logic/form), zoom, undo/redo, dark mode — with **no persistence, no projects,
no real node model, and no backend API**. The plan replaces Drawflow with a
**React Flow island** (nodes are Tabler-styled React components; built-ins themed
via CSS variables mapped to Tabler tokens, dark mode included) on top of a
**library-independent document model** (Zustand store + Laravel REST schema). On
that base we build: (1) real persistence — projects → graphs → nodes → connections
with autosave and command-based undo, (2) narrative node types (dialogue fragments
with speaker portraits, hubs, conditions, instructions, jumps) with writer-first
keyboard flows, (3) **nested graphs** — container nodes open their own child graph,
breadcrumb-navigable, unlimited depth, with Entry/Exit pin pass-through, (4) an
**asset library** (upload, folders, tags, thumbnails, "where used", safe delete),
and (5) the Articy-style systems: entities/characters, global variables, a small
sandboxed expression language, a play/simulation mode with saved playthroughs and
full-screen presentation, templates, semantic zoom, a story-lint problems panel,
reports, localization + voice-over management, Twine/articy import, versioned JSON
export for Unity/Unreal/Godot, **portable `.dgpack` zip bundles** (all assets + a
configuration file capturing node placement, text, and asset references — export,
share, and re-import whole projects or single subtrees), and team collaboration
(presence, locking, comments, snapshots).
