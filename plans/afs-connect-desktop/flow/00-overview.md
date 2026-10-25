# 00 — Overview

## Problem statement

**Flow** (`apps/flow`) is a Laravel + Vite SPA for creating **drawing
projects** powered by **Excalidraw**. Users manage a project list, open a
canvas, draw, and save a JSON scene (`elements`, `appState`, `files`).

**Appfactory Studio** (`apps/afsdesktop5`) is the Electron shell. It already
hosts offline apps (Notes Pro, Dream Graph, Coordinator) using: renderer
manager → main-process store → IPC → files under `~/Documents/afsfiles/`.

Product need: bring Flow into the desktop so users can **sketch and diagram
offline** without a browser tab or Laravel backend.

## Why Flow is simpler than other ports

| App | Domain complexity | UI stack | Offline phases |
|-----|-------------------|----------|----------------|
| **Coordinator** | PM: 7 views, budget workflow | Vanilla TS + Chart.js | 0–11 |
| **Dream Graph** | Graphs + tilemaps + play mode + `.dgpack` | React (XYFlow) + large store | 0–6 |
| **Flow** | Projects + Excalidraw scene JSON | React (Excalidraw) + small dashboard | **0–5** |

Flow-specific draw/dashboard code in the web repo is ~**2,500 lines**. There is
**one** domain table (`flow_projects`) and **five** REST endpoints for projects
(web only — desktop uses IPC).

## Goals

1. **Create, open, edit, save, delete drawing projects locally** — no server.
2. **Excalidraw parity** — same scene shape as web; load/save round-trip.
3. **Dashboard** — project table with create + actions (open, rename, delete).
4. **Canvas theme presets** — light / dark / charcoal (port `canvasTheme.ts`).
5. **Nav integration** — Flow under **Apps** in afsdesktop5 sidebar.
6. **Brand styling** — match Appfactory orange glass shell (not default Tabler blue).
7. **Import/export** — single-file JSON backup (`flow-project-export/1`).

## Non-goals

- Laravel, MySQL, or `fetch('/api/flow/...')`
- Settings / PM teams UI (`#/settings` teams CRUD)
- Appfactory3 view framework (replace with lightweight shell like Coordinator)
- Dormant NotesApp code under `flow/resources/js/app/` (unused on web; do not port)
- Real-time collaboration

## Glossary

| Term | Meaning |
|------|---------|
| **Flow project** | One drawing document: `name`, `reference_id`, `scene`, `revision` |
| **Scene** | Excalidraw payload: `{ elements, appState, files }` |
| **reference_id** | Client-facing id (`ref_{uuid}`) used in routes and filenames |
| **Draw view** | Full-screen Excalidraw editor for one project |
| **Canvas preset** | UI theme override: `light` \| `dark` \| `charcoal` (stored in appState + localStorage) |
| **Storage adapter** | `projectStore.ts` API implemented via IPC instead of HTTP |

## Relationship to web Flow

| Layer | Web today | Desktop |
|-------|-----------|---------|
| Shell | Appfactory3 + Tabler Blade layout | `#flow-root` in `#main-content` |
| Routing | Hash `#/dashboard`, `#/draw/{ref}`, … | Same hash routes inside Flow view |
| Persistence | Laravel `flow_projects` JSON column | `projects/{ref}/project.json` |
| Draw UI | `DrawView.tsx` + `@excalidraw/excalidraw` | Same component; IPC adapter |
| Settings | PM teams via `/api/pm/workspace` | Not ported |

## Success criteria

Without network, a user can:

1. Open Flow from the Appfactory Studio sidebar.
2. Create a project, open it in the draw editor, sketch, save, close, reopen — scene intact.
3. Rename and delete projects from the dashboard.
4. Switch canvas theme (light/dark/charcoal) and have it persist for the session.
5. Restart the app — all projects still listed.
6. Export a project to JSON and import it back.

All criteria met — see [complete/](complete/README.md).
