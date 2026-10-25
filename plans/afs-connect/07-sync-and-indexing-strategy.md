# 07 — Sync & Indexing Strategy

Three mechanisms keep `afsconnect_index` accurate, each covering the
other's failure mode.

## 1. Webhook push (primary, near-real-time)

Each source app adds one Laravel event listener per domain event that
should be reflected cross-app, e.g. in `notes`:

```php
// app/Providers/EventServiceProvider.php (notes)
protected $listen = [
    NoteSaved::class => [DispatchAfsConnectWebhook::class],
    NoteTrashed::class => [DispatchAfsConnectWebhook::class],
    NoteDeleted::class => [DispatchAfsConnectWebhook::class],
];
```

`DispatchAfsConnectWebhook`:

1. Checks `AfsConnectEntitlementGuard`-equivalent logic inline (cheap local
   check — is this member's `source_notes` toggle even on? if not, skip
   entirely, don't send).
2. Builds the item payload (§4.3 shape), queues a job
   (`SendAfsConnectWebhookJob`) rather than sending synchronously — a note
   save must never be slowed down or failed by coordinator being briefly
   unreachable.
3. The queued job POSTs to coordinator with a generated `event_id`
   (`{app}-{ULID}`), retries with exponential backoff (Laravel's default
   job backoff) up to 5 attempts, then lands in the failed_jobs table for
   the reconciliation sweep (below) to eventually cover.

Coordinator's `AfsConnectIngestController` writes to
`afsconnect_webhook_events` synchronously (fast insert, idempotency check on
`event_id`), returns `202`, and a queued job
(`ProcessAfsConnectWebhookJob`) does the actual `afsconnect_index`
upsert/delete plus an `afsconnect_activity_log` append.

```mermaid
sequenceDiagram
    participant Note as Note model (notes app)
    participant Event as NoteSaved event
    participant Listener as DispatchAfsConnectWebhook
    participant Job as SendAfsConnectWebhookJob (queued)
    participant Ingest as Coordinator ingest endpoint
    participant Proc as ProcessAfsConnectWebhookJob (queued)
    participant Index as afsconnect_index

    Note->>Event: save()
    Event->>Listener: handle()
    Listener->>Listener: source toggle on? (skip if not)
    Listener->>Job: dispatch()
    Job->>Ingest: POST /ingest (signed, event_id=notes-01J...)
    Ingest->>Ingest: insert afsconnect_webhook_events (idempotent)
    Ingest-->>Job: 202 Accepted
    Ingest->>Proc: dispatch()
    Proc->>Index: upsert row
    Proc->>Proc: append afsconnect_activity_log
```

## 2. Scheduled reconciliation (safety net)

Coordinator's scheduler (`routes/console.php` /
`bootstrap/app.php`'s `withSchedule`) runs, every 15 minutes:

```php
$schedule->command('afs-connect:reconcile')->everyFifteenMinutes()->withoutOverlapping();
```

`AfsConnectReconcileCommand`:

1. Selects distinct `member_reference_num` from `afsconnect_settings` where
   `enabled = true`.
2. For each member, for each enabled source app, calls
   `GET /api/internal/afs-connect/v1/changes?since=<max(afsconnect_index.indexed_at) for that member+app>`
   (falls back to epoch if no rows yet — this is also how a **first-time
   backfill** happens when a member enables afs-connect, triggered
   immediately by the settings endpoint rather than waiting for the next
   15-minute tick — see [04-api-contracts.md](04-api-contracts.md) §4.2).
3. Applies the same upsert/delete logic as the webhook processor (shared
   service method, not duplicated code).
4. Batches members/apps through `Http::pool()` to keep the sweep fast; a
   single member's slow/unreachable source app does not block others.

This bounds the maximum staleness of the index to ~15 minutes even if every
webhook for a member was somehow lost, and is what makes onboarding a
member with years of existing notes/graphs/docs tractable without a bespoke
one-off migration script.

## 3. On-demand live fetch (freshest, most expensive, used sparingly)

Covered in [02-architecture.md](02-architecture.md) — opening a specific
item always goes live to the source app, bypassing the index entirely for
content. The index is only ever used to decide *what to show a link to*,
never to render the thing itself.

## Failure handling

| Failure | Handling |
|---|---|
| Source app unreachable during reconcile | Skip that (member, app) pair this cycle, log, retry next cycle; other members/apps unaffected. |
| Webhook delivery exhausts retries | Lands in `failed_jobs` (sending app) for visibility; reconciliation sweep still catches the change within 15 min regardless. |
| Coordinator ingest processing job fails | `afsconnect_webhook_events.status = 'failed'` with `error`; a small `afs-connect:retry-failed-ingest` command retries these; reconciliation is the ultimate backstop. |
| Duplicate delivery (webhook + reconciliation both bring the same change) | Idempotent by design — `afsconnect_index` upsert keyed on `(source_app, source_id)`; `afsconnect_webhook_events.event_id` unique constraint no-ops exact webhook replays. |
| Member disables a source mid-flight | Next reconcile / any new webhook for that source is rejected by the entitlement guard (§06); a cleanup job (`afs-connect:purge-disabled-source`) removes that app's rows from `afsconnect_index` for that member so stale metadata doesn't linger in search results. |

## Future optimization: direct-DB fast path (explicitly deferred, not built in v1)

If, once this ships, reconciliation sweeps or backfills for very large
accounts prove too slow via the HTTP contract, a source app **may** — as an
internal implementation detail, without changing the public contract in
§04 — implement its `changes()`/`summary()` provider methods using a
direct read-only query against its own tables instead of round-tripping
through its own web stack, since (per
[01-current-state-audit.md](01-current-state-audit.md) §2) all five apps
already share one physical database and this pattern has precedent
(`TeamSettingsService`). This stays entirely inside that app's own
`AfsConnectProvider` implementation — coordinator's aggregator and the API
contract are unaffected either way. Not needed for v1; noted so it isn't
"discovered" later as a surprising option.
