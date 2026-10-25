# 04 — Database schema

## Completion document

When this plan step is completed, write `complete/04-database-schema.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments (PHPDoc on models,
migrations, and services). Every column whose meaning is not obvious from its name gets a
comment in the migration.

---

> **Extended by [storage-sharding](../storage-sharding/04-placement.md) §4.** The tables below
> gained a `device` column (`members_storage_sessions`, `members_storage_nodes`, NULL until a
> session is placed) and two new tables: `members_storage_placements` (the sticky per-bucket
> device preference) and `members_storage_operations` (`storage-sharding/10` §1.1, journaling a
> tree mutation's device fan-out). All three are `www` migrations, per that plan's D2/AGENTS §5,
> not a members migration as this document's own convention would otherwise imply.

All tables live in the members database. Prefix `members_storage_`, not bare `storage_` as
first drafted here — the platform's `{app}_` convention (`brain_`, `afsconnect_`,
`ext_member_`) applies at the *owning app*, and these tables are the storage feature's own
inside `members`, hence `members_storage_nodes`, `members_storage_bucket_usage`, and so on —
confirmed against every migration and model that actually shipped.

## 1. Why `user_files` is replaced, not extended

`user_files` (migration `2026_01_22_000000`) has:

- `user_id` — the wrong key, and because of audit defect S1 it holds the same value for
  every member, so its existing rows cannot be attributed to anyone.
- `folder_path` as a string on each file row — folders have no identity, so an empty
  folder cannot exist and a folder rename is an N-row rewrite (audit C6).
- no bucket column, no hash, no version, no unique constraint (audit C3).

Every one of those is structural. The table is retired; see
[13-rollout-and-testing.md](13-rollout-and-testing.md) §3 for what happens to its rows.

## 2. The tree: `storage_nodes`

One row per file **and** per folder. A single table with a discriminator, rather than two,
because almost every operation (move, rename, delete, list, permission-check) applies to
both and would otherwise be written twice.

```php
Schema::create('storage_nodes', function (Blueprint $table) {
    $table->id();

    // Tenant + placement --------------------------------------------------
    $table->string('member_reference_num', 64);
    $table->unsignedTinyInteger('bucket');           // 0–4, doc 08
    $table->foreignId('parent_id')->nullable()       // null = bucket root
          ->constrained('storage_nodes')->cascadeOnDelete();

    // Identity ------------------------------------------------------------
    $table->enum('kind', ['file', 'folder']);
    $table->string('name', 255);                     // ONE segment, real name, as uploaded
    $table->string('rel_path', 4096);                // full path from bucket root, '/'-joined
    $table->char('rel_path_hash', 64);               // sha256(rel_path) — index key, see §2.1

    // File payload (null for folders) -------------------------------------
    $table->unsignedBigInteger('size_bytes')->nullable();
    $table->char('content_sha256', 64)->nullable();
    $table->string('mime_type', 255)->nullable();

    // Sync bookkeeping (doc 07) -------------------------------------------
    $table->unsignedBigInteger('version')->default(1);
    $table->char('etag', 64);
    $table->timestamp('client_modified_at')->nullable(); // source mtime, preserved
    $table->softDeletes();                               // tombstone for delete propagation

    $table->timestamps();

    // Constraints ---------------------------------------------------------
    $table->unique(['member_reference_num', 'bucket', 'rel_path_hash'], 'storage_nodes_path_unique');
    $table->index(['member_reference_num', 'bucket', 'parent_id'], 'storage_nodes_listing');
    $table->index(['member_reference_num', 'updated_at'], 'storage_nodes_delta');
    $table->index(['member_reference_num', 'content_sha256'], 'storage_nodes_dedupe');
});
```

### 2.1 Why `rel_path_hash` and not a unique index on `rel_path`

MySQL's InnoDB index key is capped at 3072 bytes; a `utf8mb4` `varchar(4096)` cannot be
indexed, and prefix-indexing it would make the uniqueness constraint wrong — two paths
sharing a long prefix would collide. `sha256(rel_path)` is fixed-width, indexable, and
exact. `rel_path` remains for display and query; `rel_path_hash` carries the constraint.

**This unique index is acceptance criterion A5.** It is what makes a duplicate structurally
impossible rather than merely checked-for, including under two concurrent uploads racing
the same path — the second gets a constraint violation the service translates into a
conflict response.

### 2.2 Why both `name` and `rel_path` are stored

Denormalised on purpose. `name` is the single segment and is what gets validated and
displayed; `rel_path` makes listing a subtree, delta sync, and the uniqueness constraint
one indexed lookup instead of a recursive walk. The cost is that a folder rename must
rewrite descendants' `rel_path` — bounded, transactional, and done in one `UPDATE … WHERE
rel_path LIKE 'old/prefix/%'` rather than row-by-row.

### 2.3 Case and Unicode collisions

The unique index is on the **normalised** path — see [06-name-validation.md](06-name-validation.md)
§4. `rel_path_hash` is computed over the NFC-normalised, case-folded form when the member's
account is in *portable mode* (default), and over the raw NFC form in *POSIX mode*. Storing
which mode produced the hash matters if that setting can ever change, so:

```php
$table->enum('collision_mode', ['portable', 'posix'])->default('portable');
```

Changing the mode requires a re-hash sweep, which is why it is an account-level setting
changed rarely, not a per-upload flag.

## 3. The ledger: `storage_bucket_usage`

**This table is the authoritative byte count for enforcement** (decision D4). Not Parse,
not a `SUM()` over `storage_nodes`, not `du`.

```php
Schema::create('storage_bucket_usage', function (Blueprint $table) {
    $table->id();
    $table->string('member_reference_num', 64);
    $table->unsignedTinyInteger('bucket');            // 0–4

    $table->unsignedBigInteger('used_bytes')->default(0);      // committed, live files
    $table->unsignedBigInteger('reserved_bytes')->default(0);  // in-flight sessions, §4

    // Retention state (doc 08 §6)
    $table->enum('state', ['active', 'retired', 'purging'])->default('active');
    $table->timestamp('retired_at')->nullable();
    $table->timestamp('purge_after')->nullable();

    $table->timestamp('reconciled_at')->nullable();   // last ledger↔disk agreement
    $table->timestamps();

    $table->unique(['member_reference_num', 'bucket']);
});
```

### 3.1 Why a counter and not `SUM(size_bytes)`

A quota check runs on **every session open and every commit**. `SUM()` over a member with
200 000 files, under a lock, per upload, is the kind of thing that works in testing and
falls over on the first member who actually uses the product. The counter is O(1), updated
in the same transaction as the node row, and verified out-of-band by the reconcile sweep
(§6).

The tradeoff is that the counter can drift if a bug writes a node without updating usage.
The reconcile job exists precisely to detect that, and acceptance A13 requires zero drift.

### 3.2 `used_bytes` vs `reserved_bytes`

`used_bytes` counts committed files. `reserved_bytes` counts bytes promised to open upload
sessions. Quota answers use `used + reserved` so two concurrent sessions cannot each be
told they fit when together they do not (audit gap; architecture §8).

Reservations are released on commit (converted to `used`), on cancel, and by the session
expiry sweep. A leaked reservation is therefore bounded by the 48-hour session TTL, and
the reconcile job reports any reservation older than that as an anomaly.

## 4. Upload sessions: `storage_sessions` + `storage_session_files`

```php
Schema::create('storage_sessions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('member_reference_num', 64);
    $table->unsignedTinyInteger('bucket');            // routing decided at open, doc 08 §3
    $table->enum('status', ['open', 'committing', 'completed', 'failed', 'cancelled', 'expired']);
    $table->enum('conflict_policy', ['fail', 'skip', 'replace', 'keep_both'])->default('fail');
    $table->string('origin', 32);                     // 'web' | 'desktop' | 'mobile' — telemetry only
    $table->unsignedBigInteger('declared_bytes');     // sum of manifest sizes = the reservation
    $table->timestamp('expires_at');
    $table->timestamps();

    $table->index(['member_reference_num', 'status']);
    $table->index('expires_at');                      // sweep
});

Schema::create('storage_session_files', function (Blueprint $table) {
    $table->uuid('id')->primary();                    // the fileId in the wire protocol
    $table->foreignUuid('session_id')->constrained('storage_sessions')->cascadeOnDelete();

    $table->string('rel_path', 4096);
    $table->char('rel_path_hash', 64);
    $table->unsignedBigInteger('declared_size');
    $table->char('declared_sha256', 64)->nullable();  // optional; verified at commit when present

    $table->unsignedBigInteger('received_bytes')->default(0);  // mirror of .part length
    $table->enum('state', ['pending', 'uploading', 'complete', 'committed', 'failed', 'skipped']);
    $table->text('error')->nullable();

    $table->timestamps();
    $table->unique(['session_id', 'rel_path_hash']);  // a manifest cannot list a path twice
});
```

**`received_bytes` is a mirror, not the truth.** `GET /sessions/{id}` reports the actual
length of the `.part` file on disk (architecture §4). The column exists so the sweep and
the UI can report progress without stat-ing thousands of files; a disagreement is resolved
in favour of the disk.

## 5. Retention: `storage_retention_events`

An append-only audit of every plan-driven change to bucket availability. Deleting a
member's data is destructive and irreversible; it must be explainable afterward.

```php
Schema::create('storage_retention_events', function (Blueprint $table) {
    $table->id();
    $table->string('member_reference_num', 64);
    $table->enum('event', [
        'plan_changed', 'bucket_retired', 'grace_started',
        'warning_sent', 'bucket_purged', 'bucket_restored',
    ]);
    $table->unsignedTinyInteger('bucket')->nullable();
    $table->unsignedTinyInteger('plan_before')->nullable();
    $table->unsignedTinyInteger('plan_after')->nullable();
    $table->unsignedBigInteger('bytes_affected')->nullable();
    $table->unsignedInteger('files_affected')->nullable();
    $table->json('detail')->nullable();
    $table->timestamp('occurred_at');

    $table->index(['member_reference_num', 'occurred_at']);
});
```

`bucket_restored` exists because an upgrade during the grace window must un-retire the
bucket, and that has to be as auditable as the retirement was.

## 6. Reconciliation

`php artisan storage:reconcile {--member=} {--fix}` — scheduled nightly, runnable ad hoc.

| Check | Detects | `--fix` action |
|---|---|---|
| `SUM(storage_nodes.size_bytes)` per bucket vs `storage_bucket_usage.used_bytes` | Ledger drift from a missed decrement | Rewrite the counter from the sum |
| Node rows with no file on disk | Failed commit, manual deletion | Soft-delete the row, log |
| Files on disk with no node row | Interrupted commit, out-of-band copy | Adopt into the tree, or quarantine to `.trash/orphans/` |
| `reserved_bytes > 0` with no open session | Leaked reservation | Zero it |
| Sessions past `expires_at` | Abandoned uploads | Cancel, delete `.part`, release reservation |
| `.staging/` dirs with no session row | Crash during session create | Delete after 48 h |

Report-only by default. `--fix` is opt-in because the "files on disk with no node row"
case can legitimately mean *a migration is in progress* and adopting rows mid-migration
would be wrong.

Acceptance A13 is this command reporting zero drift after the test matrix runs.

## 7. Models

| Model | Table | Notes |
|---|---|---|
| `StorageNode` | `storage_nodes` | `parent()`/`children()` self-relations; global scope on `member_reference_num` is **not** used — see below |
| `BucketUsage` | `storage_bucket_usage` | All mutation through `UsageLedger`, never `save()` from a controller |
| `StorageSession` / `StorageSessionFile` | `storage_sessions*` | |
| `RetentionEvent` | `storage_retention_events` | Append-only; no `update()`/`delete()` |

**No Eloquent global scope for tenancy.** A global scope is invisible at the call site and
silently absent in raw queries, `DB::table()`, and job payloads — the failure mode is a
query that quietly returns another member's rows. Instead, every repository method takes
`member_reference_num` as its first required argument, so omitting it is a compile-time
shaped error rather than a runtime data leak. This is a direct response to audit S1, whose
root cause was exactly an implicit, invisible identity.

## 8. Migration list

| Migration | Purpose |
|---|---|
| `…_create_storage_nodes_table.php` | §2 |
| `…_create_storage_bucket_usage_table.php` | §3 |
| `…_create_storage_sessions_table.php` | §4 |
| `…_create_storage_session_files_table.php` | §4 |
| `…_create_storage_retention_events_table.php` | §5 |
| `…_drop_user_files_table.php` | **Last, and only after** [13](13-rollout-and-testing.md) §3 sign-off. Ships in a later release than the rest. |
