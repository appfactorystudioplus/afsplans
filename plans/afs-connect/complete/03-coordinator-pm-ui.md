# Step 03 — Minimal coordinator PM afs-connect UI

**Status:** Completed  
**Date:** 2026-07-23  
**Repo:** `apps/coordinator`

## What was built

A first usable afs-connect section inside the coordinator PM SPA at **`#/afs-connect`**.

### User-visible behavior

1. On app load, `GET /api/afs-connect/status` runs.
2. If `enabled: true`, the sidebar shows **afs-connect** (plug icon).
3. Clicking the nav loads `#/afs-connect` with:
   - Summary cards (total items, 7-day updates, source app count)
   - Table of counts per source app
   - Search box (queries `/api/afs-connect/search`)
   - Recent activity list (from `/api/afs-connect/activity`)

If the feature is not entitled, the nav item stays hidden (`d-none`).

## Architecture

```
home.blade.php → main.ts → views.ts (registerViews)
  ├── index.html          sidebar nav (#nav-afs-connect-item)
  ├── afs-connect.html    section shell + #afs-connect-root mount
  ├── afs-connect-api.ts  fetch wrappers (status, summary, search, activity)
  └── afsConnect.ts       renderAfsConnect(), setAfsConnectNavVisible()
```

Navigation uses the same pattern as other PM views:

- Sidebar `data-section="afs-connect"` → `ui.ts` → `navigate` event → `views.ts` router → `afs-connect` subview listener → `renderAfsConnect()`.

## Files added / changed

| File | Change |
|------|--------|
| `resources/js/src/afs-connect-api.ts` | New API client |
| `resources/js/src/afsConnect.ts` | New render module |
| `resources/js/html/views/afs-connect.html` | New view template |
| `resources/js/views.ts` | Register subview + status nav toggle |
| `resources/js/html/index.html` | Sidebar link (hidden by default) |
| `resources/js/src/ui.ts` | Route `afs-connect` section to hash router |

## Intentionally minimal

This step does **not** include:

- Linking items to PM tasks from the UI (API exists; UI wiring comes later)
- Item detail drawer / live Brain preview
- Members settings panel for toggling sources
- Upsell / disabled-state screens (nav simply hidden)

## How to verify locally

1. Set coordinator `.env` afs-connect vars (see `plans/10-coordinator-env-variables.md`).
2. Enable member in `afsconnect_settings` and set `AFS_CONNECT_DEV_PLAN_ENTITLED=true` if members entitlements URL is not live.
3. Run migrations for `afsconnect_*` tables.
4. Reconcile or ingest sample items so summary/search have data.
5. Open coordinator PM → afs-connect nav should appear → visit `#/afs-connect`.

## Contract reference

[08-frontend-ux.md](../08-frontend-ux.md) (full UX is future work; this is the Phase 1 shell)
