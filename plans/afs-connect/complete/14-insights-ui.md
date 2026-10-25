# Step 14 — Coordinator insights UI

## What was built

PM dashboard section on `#/afs-connect` surfaces read-only insight queries that were already exposed as API.

### Backend (existing)

- `GET /api/afs-connect/insights` — `AfsConnectInsightsController` + `AfsConnectInsightsService`
  - **undocumented_tasks** — active PM tasks with no `pm_task` afs-connect links
  - **orphaned_items** — index rows updated in the last 30 days with no link

### Frontend

- `fetchAfsConnectInsights()` in `coordinator/resources/js/src/afs-connect-api.ts`
- Two-column card block in `afsConnect.ts`:
  - **Open task** → `openTaskEditModal(client_id)`
  - **Details** on orphaned rows → item offcanvas via `openAfsConnectItemDrawer`

When both lists are empty, a success banner is shown.

## Verify

1. Open coordinator PM → **afs-connect**.
2. With an active unlinked task, confirm it appears under “Tasks without links” and opens the task editor.
3. With a recent indexed item that has no links, confirm “Recent unlinked items” and the detail drawer.
