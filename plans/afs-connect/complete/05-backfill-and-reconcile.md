# Step 05 — Backfill job and reconcile command

**Status:** Completed  
**Date:** 2026-07-23  
**Repo:** `apps/coordinator`

## What was built

Historical index population when a member first enables afs-connect, plus richer manual/scheduled reconciliation.

### New job

`app/Jobs/AfsConnect/AfsConnectBackfillMemberJob.php`

- Dispatched from `AfsConnectSettingsController` when `enabled` transitions **false → true**
- Calls `AfsConnectAggregatorService::backfillMember()`
- Uses `since = 1970-01-01T00:00:00Z` and `limit = 500` against Brain `/changes`

### Aggregator enhancements

`AfsConnectAggregatorService`:

| Method / constant | Purpose |
|-------------------|---------|
| `BACKFILL_SINCE` | Sentinel ISO8601 for full historical pull |
| `reconcileMember()` | Now returns `{ ok, items_applied, websites_updated }` |
| `backfillMember()` | Full www backfill wrapper |

### Artisan command

`php artisan afs-connect:reconcile`

| Option | Effect |
|--------|--------|
| *(none)* | All enabled members; incremental (no `since`) |
| `--member=MR-8841` | Single member |
| `--since=2026-07-01T00:00:00Z` | Pass cursor to Brain |
| `--backfill` | Full historical pull (`since = 1970-01-01`) |
| `--queue` | Dispatch `AfsConnectBackfillMemberJob` for `--member` |

Scheduled every **15 minutes** in `routes/console.php` (unchanged).

Deploy these together on production (partial deploy causes runtime errors):

- `app/Console/Commands/AfsConnectReconcileCommand.php`
- `app/Services/AfsConnect/AfsConnectAggregatorService.php`
- `app/Services/AfsConnect/AfsConnectIndexService.php`
- `app/Jobs/AfsConnect/ProcessAfsConnectWebhookJob.php` (optional, for queued ingest)
- `app/Jobs/AfsConnect/AfsConnectBackfillMemberJob.php` (optional, for `--queue`)

The command defines its own `BACKFILL_SINCE` constant so it does not depend on the aggregator constant during partial deploys.

```bash
grep -n "addOption" app/Console/Commands/AfsConnectReconcileCommand.php
php artisan optimize:clear
php artisan afs-connect:reconcile --help
```

`--help` must list `--member`, `--since`, `--backfill`, and `--queue`. If it does not, production is still running the old command file (only `protected $signature = 'afs-connect:reconcile';` with no options).

After deploying, clear cached commands if needed:

```bash
php artisan optimize:clear
```

## How backfill triggers

```
Members app POST /api/internal/afs-connect/v1/settings { enabled: true }
  → AfsConnectSettingsController saves row
  → if was disabled and now enabled:
       AfsConnectBackfillMemberJob::dispatch(member_reference_num)

Job / command
  → WwwBrainClient::changes(member, since=1970, limit=500)
  → index upsert per entitled item
  → website sync status updated
```

## Manual ops examples

```bash
# Incremental reconcile for all enabled members
php artisan afs-connect:reconcile

# Full backfill one member inline
php artisan afs-connect:reconcile --member=MR-8841 --backfill

# Queue backfill for worker
php artisan afs-connect:reconcile --member=MR-8841 --queue
```

## Files changed

- `app/Jobs/AfsConnect/AfsConnectBackfillMemberJob.php` (new)
- `app/Services/AfsConnect/AfsConnectAggregatorService.php`
- `app/Console/Commands/AfsConnectReconcileCommand.php`
- `app/Http/Controllers/Api/Internal/AfsConnectSettingsController.php`

## Not in this step (follow-ups)

- ~~Pagination cursor loop when Brain returns `has_more`~~ — implemented: www returns `has_more` / `next_cursor`; coordinator loops up to 200 pages in `AfsConnectAggregatorService::reconcileMember()`
- Non-www source app backfill clients (notes, flow, etc.)
