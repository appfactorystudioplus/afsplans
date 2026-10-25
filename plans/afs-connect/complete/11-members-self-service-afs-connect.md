# Step 11 — Members self-service afs-connect settings

**Status:** Completed  
**Date:** 2026-07-24  
**Repo:** `apps/members`

## What was built

Members dashboard **Settings → General** includes an **AFS Connect** card:

- Master enable toggle (with confirm on first enable)
- Per-app toggles: Notes, Dream Graph, Flow, PDF Editor
- Plan / global availability messaging from coordinator `GET /api/afs-connect/status`

### API (members app)

| Method | Path |
|--------|------|
| GET | `/api/v1/afs-connect/settings?user_id={primary_user_id}` |
| PUT | `/api/v1/afs-connect/settings?user_id={primary_user_id}` |

Server proxies to coordinator `internal/afs-connect/v1/settings` with signed `members` service auth (same entitlement rules as non–Brain-admin writes: plan + global required to enable).

## Env (members)

```env
AFS_CONNECT_COORDINATOR_URL=https://coordinator15.appfactory.studio
AFS_CONNECT_SERVICE_KEY=…
AFS_CONNECT_COORDINATOR_VERIFY_SSL=false
```

Coordinator must accept `members` in `peer_keys` / `AFS_CONNECT_SERVICE_KEY_MEMBERS`.

## Verify

1. Log in to members dashboard as a Pro+ member.
2. Settings → General → AFS Connect → enable → Save.
3. Coordinator PM `#/afs-connect` nav appears when entitlement allows.
4. Brain admin www modules remain Brain-only (member UI does not expose www_* source toggles).

## UX reference

[08-frontend-ux.md](../08-frontend-ux.md) — Members dashboard panel (states 1–3).
