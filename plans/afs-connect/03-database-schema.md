# 03 — Database Schema

> ⏳ **Planned changes (step 60):** `afsconnect_settings`' per-source boolean
> columns become two JSON maps — `sources_enabled` (member privacy) and
> `sources_allowed` (plan packaging), see
> [12-source-registry.md](12-source-registry.md) §12.4. A new
> `afsconnect_source_status` table records per-(member, source) sync health
> (`last_attempt_at`, `last_success_at`, `consecutive_failures`, `last_error`).
> `afsconnect_links` gains `orphaned_at` so links survive the deletion of their
> indexed item. Step 61 adds coordinator-owned `afsconnect_engine_projects`,
> `afsconnect_engine_commits` and `afsconnect_engine_seen_users` for the first
> `hosted` source; its items go straight into `afsconnect_index`.

All new tables live in **coordinator's** database (the shared apps DB),
using the `afsconnect_` prefix, consistent with the audit finding that this
should be its own namespace rather than `pm_*`
(see [01-current-state-audit.md](01-current-state-audit.md) §1). They are
created via a normal coordinator migration, e.g.
`coordinator/database/migrations/2026_XX_XX_000001_create_afsconnect_tables.php`.

Two supporting changes happen elsewhere: a `member_reference_num` backfill
in `dream_graph`, and a `features` column on `members`' `ProcessorPlan`.

## 3.1 `afsconnect_settings`

One row per member. The member-level and per-source-app on/off switches.

```php
Schema::create('afsconnect_settings', function (Blueprint $table) {
    $table->id();
    $table->string('member_reference_num')->unique();
    $table->boolean('enabled')->default(false);           // master member-level switch
    $table->boolean('source_notes')->default(true);        // per-source-app switches,
    $table->boolean('source_dream_graph')->default(true);  // only meaningful while
    $table->boolean('source_flow')->default(true);         // enabled = true
    $table->boolean('source_pdf_editor')->default(true);
    $table->timestamp('enabled_at')->nullable();
    $table->string('enabled_by_checkpoint_user_id')->nullable();
    $table->timestamp('disabled_at')->nullable();
    $table->timestamps();

    $table->index('enabled');
});
```

Written to by: coordinator's internal settings endpoint, called from the
Members dashboard settings panel and, indirectly, by the billing
enforcement listener (see [05](05-feature-toggle-and-billing.md)).

## 3.2 `afsconnect_index`

Denormalized, metadata-only cache of items across the four source apps.
This is what powers search, the summary dashboard, and the activity feed
without a live cross-app call on every page load.

```php
Schema::create('afsconnect_index', function (Blueprint $table) {
    $table->id();
    $table->string('member_reference_num')->index();
    $table->string('source_app', 32)->index();       // 'notes' | 'dream_graph' | 'flow' | 'pdf_editor'
    $table->string('source_type', 64);                // e.g. 'note', 'graph_project', 'flow_project', 'document'
    $table->string('source_id');                      // the source app's own primary key (string; several use UUIDs)
    $table->string('title')->nullable();
    $table->text('snippet')->nullable();               // short preview only, never full content
    $table->string('owner_checkpoint_user_id')->nullable();
    $table->unsignedInteger('pm_team_client_id')->nullable(); // ties to coordinator's pm_teams.client_id
    $table->string('url')->nullable();                 // deep link back into the source app's UI
    $table->json('tags')->nullable();
    $table->json('metadata')->nullable();               // small, source-defined key/values (counts, status, etc.)
    $table->timestamp('source_created_at')->nullable();
    $table->timestamp('source_updated_at')->nullable();
    $table->timestamp('indexed_at');
    $table->timestamps();

    $table->unique(['source_app', 'source_id'], 'afsconnect_index_source_unique');
    $table->index(['member_reference_num', 'source_app']);
    $table->index(['member_reference_num', 'source_updated_at']);
    $table->fullText(['title', 'snippet']); // cross-app search
});
```

`metadata` is intentionally small and source-defined (e.g. notes might send
`{"folder": "Sprint Planning", "word_count": 812}`; pdf_editor might send
`{"page_count": 12, "size_bytes": 481200}`). It is not a place to smuggle
full content.

## 3.3 `afsconnect_links`

The actual "connection" feature: attaching a coordinator entity (today:
projects and tasks) to an item in another app.

```php
Schema::create('afsconnect_links', function (Blueprint $table) {
    $table->id();
    $table->string('member_reference_num')->index();
    $table->string('linkable_type');       // 'pm_project' | 'pm_task'
    $table->unsignedBigInteger('linkable_client_id'); // pm_projects.client_id or pm_tasks.client_id
    $table->string('source_app', 32);
    $table->string('source_type', 64);
    $table->string('source_id');
    $table->string('created_by_checkpoint_user_id')->nullable();
    $table->timestamps();

    $table->unique(['linkable_type', 'linkable_client_id', 'source_app', 'source_id'], 'afsconnect_links_unique');
    $table->index(['member_reference_num', 'source_app', 'source_id']);
});
```

`afsconnect_links` references `afsconnect_index` rows loosely (by
`source_app` + `source_id`, not a hard FK) so a link survives even if the
index row is briefly stale or being re-synced.

## 3.4 `afsconnect_activity_log`

Feeds the cross-app activity timeline / analytics views. Append-only.

```php
Schema::create('afsconnect_activity_log', function (Blueprint $table) {
    $table->id();
    $table->string('member_reference_num')->index();
    $table->string('source_app', 32);
    $table->string('event_type', 64);      // 'created' | 'updated' | 'deleted' | 'shared' | 'linked' | 'unlinked'
    $table->string('source_type', 64);
    $table->string('source_id');
    $table->string('actor_checkpoint_user_id')->nullable();
    $table->json('payload')->nullable();    // small event-specific extras (e.g. {"linked_to": "pm_task:42"})
    $table->timestamp('occurred_at');
    $table->timestamps();

    $table->index(['member_reference_num', 'occurred_at']);
    $table->index(['source_app', 'event_type']);
});
```

Retention: pruned by a scheduled command after 180 days (configurable),
since it is an analytics feed, not an audit-of-record.

## 3.5 `afsconnect_webhook_events` (inbound processing log / idempotency)

```php
Schema::create('afsconnect_webhook_events', function (Blueprint $table) {
    $table->id();
    $table->string('source_app', 32);
    $table->string('event_id')->unique(); // idempotency key set by the sending app
    $table->string('event_type', 64);
    $table->json('payload');
    $table->timestamp('received_at');
    $table->timestamp('processed_at')->nullable();
    $table->string('status', 16)->default('pending'); // pending | processed | failed
    $table->text('error')->nullable();
    $table->unsignedTinyInteger('attempts')->default(0);
    $table->timestamps();

    $table->index(['status', 'received_at']);
});
```

Every inbound webhook is written here first (fast, synchronous insert), then
processed by a queued job. This makes ingestion idempotent (`event_id`
unique) and gives a retry/inspection surface without depending on the
sending app's own retry behavior.

## 3.6 Supporting change: `dream_graph` member scoping (prerequisite)

Per [01-current-state-audit.md](01-current-state-audit.md) §4,
`graph_projects` has no `member_reference_num`. Before dream_graph can ship
an `AfsConnectProvider`, add:

```php
// dream_graph: 2026_XX_XX_000001_add_member_reference_num_to_graph_projects_table.php
Schema::table('graph_projects', function (Blueprint $table) {
    $table->string('member_reference_num')->nullable()->after('owner_id')->index();
});
```

followed by a one-time backfill command
(`php artisan afs-connect:backfill-graph-member-reference`) that, for each
distinct `owner_id`, calls `FunctionsController::resolveMemberReferenceNum($ownerCheckpointUserId)`
via the existing brain lookup and fills the column. New rows populate it at
write time going forward (small change to `ProjectController::store`).
This is scoped as its own migration in dream_graph, independent of the rest
of afs-connect, and is a **Phase 0** task — see
[09-rollout-plan.md](09-rollout-plan.md).

## 3.7 Supporting change: plan entitlement in `members`

`apps/members/app/Models/Externals/Processor/ProcessorPlan.php` gets a `features`
JSON column (if it doesn't already carry one — confirm at implementation
time) so a plan can declare `{"afs_connect": true}`. If a `features` column
already exists for other purposes, add the `afs_connect` key to it rather
than adding a parallel column. See [05-feature-toggle-and-billing.md](05-feature-toggle-and-billing.md)
for how this is read.

```php
// members: 2026_XX_XX_000001_add_features_to_processor_plans_table.php (only if not already present)
Schema::table('processor_plans', function (Blueprint $table) {
    if (! Schema::hasColumn('processor_plans', 'features')) {
        $table->json('features')->nullable()->after('...');
    }
});
```

No new table is needed in `members` for settings storage — `members` is a
UI + billing surface for afs-connect, not a data owner for it (its database
is intentionally separate from the shared apps DB, per the member's own
description of the system).
