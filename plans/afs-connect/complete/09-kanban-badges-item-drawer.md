# Step 09 — Kanban link badges + item detail drawer

**Status:** Completed  
**Date:** 2026-07-24  
**Repo:** `apps/coordinator`

## What was built

PM users see **afs-connect link counts on Kanban cards** and can open an **item detail offcanvas** from task links or the Kanban badge flow.

### Kanban badges

- After each `renderKanban()`, if afs-connect is entitled, the UI calls `GET /api/afs-connect/links/counts?linkable_type=pm_task&linkable_client_ids=…` for all visible card task ids (max 500).
- Cards with one or more links show a plug badge (`1 link` / `N links`).
- Clicking the badge opens a modal listing links; **View** opens the item drawer.

### Item detail drawer

- Bootstrap offcanvas `#afsConnectItemOffcanvas` loads `GET /api/afs-connect/items/{sourceApp}/{sourceId}`.
- Shows indexed title/snippet/metadata, optional live Brain preview for `www_*` sources, and expandable raw JSON.

### Task edit panel

- Existing link rows gain **View** (drawer) in addition to **Remove**.
- Attach/unlink refreshes Kanban badges.

## New / changed files

| File | Change |
|------|--------|
| `app/Services/AfsConnect/AfsConnectLinkService.php` | `countsByClientIds()` |
| `app/Http/Controllers/Api/AfsConnect/AfsConnectLinksController.php` | `counts` action |
| `routes/api.php` | `GET /api/afs-connect/links/counts` |
| `resources/js/src/afs-connect-api.ts` | `fetchAfsConnectLinkCounts`, `fetchAfsConnectItemDetail` |
| `resources/js/src/afsConnectKanbanBadges.ts` | Badge render + task links modal |
| `resources/js/src/afsConnectItemDrawer.ts` | Item offcanvas |
| `resources/js/src/kanban.ts` | Refresh badges after render |
| `resources/js/src/afsConnectTaskLinks.ts` | View + badge refresh |
| `resources/js/html/index.html` | Modal + offcanvas shells |
| `resources/js/styles/dashboard.scss` | Badge + drawer width |

## Verify

1. Enable afs-connect for a member with indexed items and attach at least one link to a task on the Kanban board.
2. Open **Kanban** — card shows link badge; click badge → modal → **View** → item drawer.
3. Open task edit — **View** on a linked row opens the same drawer.

## Contract reference

[04-api-contracts.md](../04-api-contracts.md) §4.3 (`GET /items`, `GET /links`)
