# Step 10 — Checkpoint global flag (Brain admin)

**Status:** Completed  
**Date:** 2026-07-24  
**Repos:** `apps/auth`, `apps/www`

## What was built

Brain **Memberships → afs-connect** tab now includes a **Platform global (Checkpoint)** card to read and toggle the `afs_connect` row in `checkpoint_feature_flags`.

### Auth API

| Method | Path | Auth |
|--------|------|------|
| GET | `/checkpoint/authz/features/{featureKey}` | None (coordinator entitlement lookup) |
| PUT | `/checkpoint/authz/features/{featureKey}` | Header `X-Checkpoint-Ops-Key` |

### Brain www proxy

| Method | Path |
|--------|------|
| GET | `/portal/api/v1/memberships/afs-connect/global-flag` |
| PUT | `/portal/api/v1/memberships/afs-connect/global-flag` |

## Env (must match on auth + www)

```env
CHECKPOINT_BRAIN_OPS_KEY=your-shared-secret
CHECKPOINT_URL=https://auth15.appfactory.studio
```

Coordinator picks up global changes within ~60s (cache). Optional: `AFS_CONNECT_GLOBAL_ENABLED=true` still overrides on coordinator only.

## Verify

1. Seed or insert `afs_connect` in `checkpoint_feature_flags` (see members `AfsConnectFeatureFlagSeeder`).
2. Set `CHECKPOINT_BRAIN_OPS_KEY` on auth and www; deploy both.
3. Brain → member → afs-connect tab → toggle **Global afs-connect enabled** → Save global flag.
4. `curl https://auth15…/checkpoint/authz/features/afs_connect` → `"enabled": true`.
