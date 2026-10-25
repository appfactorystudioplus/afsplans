# 03 — Data Model and Files

On-disk layout for Flow projects in Appfactory Studio.

## Workspace root

Extend `get-app-dirs` (main process) with:

```
~/Documents/afsfiles/flow/
├── workspace.json          # optional manifest (project index, currentProject)
└── projects/
    └── {reference_id}/
        └── project.json    # full FlowProjectRecord payload
```

Example: `~/Documents/afsfiles/flow/projects/ref_a1b2c3d4/project.json`

## `workspace.json` (optional)

```json
{
  "version": 1,
  "updatedAt": "2026-08-01T12:00:00.000Z",
  "projects": [
    { "referenceId": "ref_a1b2c3d4", "name": "Wireframes", "updatedAt": "..." }
  ]
}
```

Lightweight index for fast dashboard listing without scanning every folder.
If omitted, `listProjects` reads all `projects/*/project.json` (Dream Graph pattern).

## `project.json` schema

```json
{
  "id": "proj_8f3c2a1b-....",
  "reference_id": "ref_a1b2c3d4",
  "name": "Wireframes",
  "revision": 3,
  "scene": {
    "elements": [],
    "appState": { "theme": "dark", "flowCanvasPreset": "charcoal" },
    "files": {}
  },
  "created_at": "2026-07-11T10:00:00.000Z",
  "updated_at": "2026-08-01T08:30:00.000Z"
}
```

### Field rules

| Field | Rule |
|-------|------|
| `id` | Generated on create: `proj_{uuid}` |
| `reference_id` | Generated on create: `ref_{uuid}` — **folder name** |
| `name` | User-visible title; 1–500 chars |
| `revision` | Increment on each successful save |
| `scene` | Nullable until first save; Excalidraw shape |
| `created_at` / `updated_at` | ISO 8601 strings |

## ID generation (main process)

```typescript
import { randomUUID } from 'crypto';

function mintProjectIds() {
  const uuid = randomUUID();
  return {
    id: `proj_${uuid}`,
    reference_id: `ref_${uuid.replace(/-/g, '').slice(0, 12)}`,
  };
}
```

Same prefix convention as web `ProjectController` for export compatibility.

## Save semantics

1. **Atomic write:** write to `project.json.tmp` then rename (Notes Pro pattern).
2. **Revision bump:** `revision += 1` on every save.
3. **Scene merge:** save with `{ name, scene }` updates both; name-only save for rename.
4. **Delete:** remove `projects/{reference_id}/` directory recursively.

## Empty / new project defaults

On create:

```json
{
  "scene": null,
  "revision": 0
}
```

First open in Draw view: Excalidraw mounts with empty canvas. First save writes
initial scene.

## Canvas theme persistence

Two layers (match web):

1. **Global preset** — `localStorage` key from `canvasTheme.ts` (renderer)
2. **Per-scene** — `appState.flowCanvasPreset` inside saved scene JSON

Desktop uses same keys so imported projects keep theme.

## Import/export format

Single-file export for backup / handoff:

```
flow-project-export/1
{
  "format": "flow-project-export/1",
  "exportedAt": "...",
  "project": { ...FlowProjectRecord }
}
```

Desktop: file dialog + IPC `flow:export-project` / `flow:import-project`.
Import mints new local IDs to avoid folder collisions.
