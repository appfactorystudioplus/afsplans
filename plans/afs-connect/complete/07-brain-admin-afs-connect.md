# Step 07 — Brain admin afs-connect access control

**Status:** Completed  
**Date:** 2026-07-23  
**Repos:** `apps/www`, `apps/coordinator`

## Problem this solves

Member administration already lives in **Brain → Memberships** (`brain_membership.ts`). Ops should enable afs-connect for a member from that screen — not by editing coordinator env vars, running artisan commands, or touching raw DB rows.

Before this step, the only way to turn a member on was indirect (coordinator internal API, SQL on `afsconnect_settings`, or reconcile with `--force`). That was too complicated for day-to-day admin work.

## What was built

### Brain UI

New **afs-connect** tab on every member detail view:

| Control | Purpose |
|---------|---------|
| Enable toggle | Turns afs-connect on/off for this member (`afsconnect_settings.enabled` on coordinator) |
| Source app toggles | Notes, Dream Graph, Flow, PDF Editor |
| Website module toggles | Forms, Direct Payments, Sponsorships, Appointments, Subscribers, Analytics, E-commerce, Events |
| Disabled website domains | One domain per line — excluded from www indexing |
| Status badges | Global / Plan / Member / Effective PM access |

**Navigation:** Brain → Memberships → open member → **afs-connect** tab.

### www portal API (Brain admin proxy)

| Method | Route | Role |
|--------|-------|------|
| GET | `/portal/api/v1/memberships/{ref}/afs-connect` | Load settings + entitlement diagnostics |
| PUT | `/portal/api/v1/memberships/{ref}/afs-connect` | Save settings |

Files:

- `app/Http/Controllers/Portal/Admin/MembershipAfsConnectController.php`
- `app/Services/AfsConnect/CoordinatorSettingsClient.php` — signed HTTP to coordinator

The controller verifies the member exists in `brain_memberships`, proxies to coordinator, and clears www’s settings cache on save.

### Coordinator Brain-admin bypass

`AfsConnectSettingsController` (coordinator internal API):

- When the caller is signed service app **`www`** and sends `brain_admin=1`, **global** and **plan** entitlement checks are skipped on enable.
- GET with `brain_admin=1` includes `entitlement` + `diagnostics` in the JSON so the Brain tab can explain current state.

This lets Brain admins enable a member even when Checkpoint global or plan gates would block the members-app settings API.

### Frontend wiring

- `resources/js/portal/admin/html/views/html/modules/brain/brain_memberships_afs_connect_content.html`
- `initMembershipAfsConnectView()` in `brain_membership.ts`
- View registered in `brainView.ts` as `brain:memberships:afs-connect`
- Tab link added to all member detail HTML templates

## How it works end-to-end

```
Brain admin opens member → afs-connect tab
  → GET /portal/api/v1/memberships/{ref}/afs-connect
  → www CoordinatorSettingsClient (signed as app "www")
  → GET coordinator /api/internal/afs-connect/v1/settings?member_reference_num=…&brain_admin=1
  → returns enabled, sources, disabled_website_domains, entitlement, diagnostics

Admin toggles Enable + sources → Save
  → PUT /portal/api/v1/memberships/{ref}/afs-connect
  → POST coordinator /api/internal/afs-connect/v1/settings { brain_admin: true, enabled: true, … }
  → coordinator saves afsconnect_settings row
  → if enabled changed false → true: AfsConnectBackfillMemberJob dispatched (automatic)
```

## Glossary (plain language)

### Backfill

**Backfill** = coordinator pulls existing data from Brain/www and builds the searchable afs-connect index for that member.

When you **first enable** a member, coordinator queues `AfsConnectBackfillMemberJob`. That job asks Brain for historical changes and writes rows into `afsconnect_index` so PM search/linking has something to show.

You do **not** need a separate “Run backfill” button in Brain for normal use — save with Enable **on** triggers it automatically.

Manual backfill (ops / debugging only):

```bash
php artisan afs-connect:reconcile --member=m1xTHpRmdtH2Y --backfill
```

Use that if the queue worker was down when you enabled the member, or you want to re-index from scratch.

### Global Checkpoint flag

afs-connect has a **platform-wide kill switch** stored in Checkpoint (auth app), feature key `afs_connect`, table `checkpoint_feature_flags`.

| Layer | Question it answers | Where it lives |
|-------|---------------------|----------------|
| **Global** | Is afs-connect turned on for the whole platform? | Checkpoint `checkpoint_feature_flags` |
| **Plan** | Does this member’s subscription tier include afs-connect? | Members entitlements API or `brain_memberships.membership` tier |
| **Member** | Is this specific member enabled? | Coordinator `afsconnect_settings.enabled` — **Brain tab controls this** |
| **Source** | Which apps/modules feed the index? | Coordinator `afsconnect_settings.source_*` columns |

**“Global Checkpoint is still off”** means layer 1 is disabled: the product feature is switched off everywhere, like a master power switch.

Brain admin can still **save member enabled = true** (Brain bypass skips global/plan on the settings write). But **coordinator PM** may hide the afs-connect nav until global is on — PM checks full entitlement, not just the member row.

To turn global on (production auth DB):

```sql
-- auth database
UPDATE checkpoint_feature_flags
SET enabled = 1
WHERE feature_key = 'afs_connect';
```

Or use whatever Checkpoint admin UI exists for feature flags. Coordinator caches the global flag for ~60 seconds.

Staging override (coordinator `.env` / config, not for production long-term):

```
AFS_CONNECT_GLOBAL_ENABLED=true
```

Then `php artisan config:cache` on coordinator if config is cached.

## Deploy checklist

1. Deploy **coordinator** (updated `AfsConnectSettingsController`).
2. Deploy **www** (controller, client, routes, rebuilt JS).
3. Confirm matching service keys:
   - www: `AFS_CONNECT_SERVICE_KEY`, `AFS_CONNECT_COORDINATOR_URL`
   - coordinator: `AFS_CONNECT_SERVICE_KEY_WWW` in `peer_keys.www`
4. Rebuild www frontend assets.
5. Open a test member → afs-connect tab → enable → save.
6. On coordinator, confirm queue worker processes `AfsConnectBackfillMemberJob` (or run reconcile `--backfill` manually once).

**If the browser shows HTTP 404 on `/memberships/{ref}/afs-connect`:** the JS bundle is new but www15 PHP/routes are not. Deploy `routes/portal.php`, `MembershipController.php`, and coordinator client classes, then on www15:

```bash
php artisan route:clear
php artisan config:clear
# re-run config:cache only if you normally use it
```

## Troubleshooting

### cURL error 60 (SSL hostname mismatch)

The **URL is still correct** if `.env` has `AFS_CONNECT_COORDINATOR_URL=https://coordinator15.appfactory.studio`. The failure is TLS: the certificate served for that connection does not list `coordinator15.appfactory.studio` (often missing nginx vhost/cert for coordinator15 while www15 already has one).

**Until infra adds a cert for coordinator15:**

- With `APP_ENV=local` (or any non-`production` env), www now **defaults** `coordinator_verify_ssl` to off so server-to-server calls work.
- After deploy, run `php artisan config:clear` (and `config:cache` again if you use it).

**Override in `.env` if needed:**

```env
AFS_CONNECT_COORDINATOR_VERIFY_SSL=false
```

**Long-term:** provision Let’s Encrypt + nginx `server_name coordinator15.appfactory.studio`, then set `AFS_CONNECT_COORDINATOR_VERIFY_SSL=true` on production.

## Files changed

**www**

- `app/Http/Controllers/Portal/Admin/MembershipController.php` (`showAfsConnect`, `updateAfsConnect`)
- `app/Services/AfsConnect/CoordinatorSettingsClient.php` (new)
- `app/Services/AfsConnect/CoordinatorHttp.php` (new)
- `routes/portal.php`
- `resources/js/portal/admin/controllers/brain/brain_membership.ts`
- `resources/js/portal/admin/views/brainView.ts`
- `resources/js/portal/admin/utils/templates.ts`
- `resources/js/portal/admin/html/views/html/modules/brain/brain_memberships_afs_connect_content.html` (new)
- `resources/js/portal/admin/html/views/html/modules/brain/brain_memberships_*_content.html` (tab link)

**coordinator**

- `app/Http/Controllers/Api/Internal/AfsConnectSettingsController.php` (Brain admin bypass + diagnostics on GET)

## Not in this step

- “Run backfill” button in Brain UI (automatic on first enable is enough for v1)
- ~~Checkpoint global flag toggle from Brain~~ — see [10-checkpoint-global-flag-brain.md](./10-checkpoint-global-flag-brain.md)
- ~~Members self-service settings panel~~ — see [11-members-self-service-afs-connect.md](./11-members-self-service-afs-connect.md)
