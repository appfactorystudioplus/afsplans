# Step 06 — Task linking UI

**Status:** Completed  
**Date:** 2026-07-23  
**Repo:** `apps/coordinator`

## What was built

PM users can attach afs-connect indexed items to a task from the **task edit offcanvas** (and full-page task edit view).

### UI location

- `#taskAfsConnectSection` in `index.html` (offcanvas) and `task_edit.html`
- Hidden (`d-none`) until `isAfsConnectEnabled()` returns true

### New module

`resources/js/src/afsConnectTaskLinks.ts`

| Export | Purpose |
|--------|---------|
| `PM_TASK_LINKABLE_TYPE` | Constant `'pm_task'` for link API |
| `renderTaskAfsConnectLinks(taskId)` | Renders panel for one task |

### API client additions

`afs-connect-api.ts`:

- `fetchAfsConnectLinks(linkableType, linkableClientId)`
- `createAfsConnectLink(payload)`
- `deleteAfsConnectLink(linkId)`
- `isAfsConnectEnabled()` — cached status for edit panel

### Integration

`tasks.ts` → `populateEditModal()` calls `renderTaskAfsConnectLinks(task.id)` after sub-tasks render.

## User flow

1. Open task edit (kanban card, task list, etc.)
2. If afs-connect is entitled, **afs-connect links** section appears below Sub-Tasks
3. Existing links listed with **Remove**
4. Search box queries `/api/afs-connect/search`
5. **Attach** on a result calls `POST /api/afs-connect/links`:

```json
{
  "linkable_type": "pm_task",
  "linkable_client_id": 42,
  "source_app": "www_forms",
  "source_type": "www_form",
  "source_id": "FR-123",
  "website_domain": "example.com",
  "website_reference_num": "WR-456"
}
```

Backend verifies the item exists in `afsconnect_index` before creating the link.

## Files changed

- `resources/js/src/afsConnectTaskLinks.ts` (new)
- `resources/js/src/afs-connect-api.ts`
- `resources/js/src/tasks.ts`
- `resources/js/html/index.html`
- `resources/js/html/views/task_edit.html`

## Not in this step

- ~~Kanban card badges showing link count~~ — see [09-kanban-badges-item-drawer.md](./09-kanban-badges-item-drawer.md)
- ~~Live item preview drawer from task links~~ — same
- Link display on task list rows without opening edit

## Contract reference

[04-api-contracts.md](../04-api-contracts.md) §4.3 (`POST /links`, `GET /links`)
