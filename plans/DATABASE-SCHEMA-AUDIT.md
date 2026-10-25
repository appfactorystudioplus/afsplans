# Database schema audit — what exists, what creates it, what nothing reads

**Measured 2026-08-30** against the live MySQL instance and the checked-in exports in
`C:\projects\afs-eco\databases`. A snapshot, not a plan: this is how the database actually
works right now, so that the next session can tell a missing table from a dead one without
re-deriving it.

Companion documents: `handoff.md` (the narrative), `00_ISSUES.md` I2 (the fault that
prompted this), `TODOS.md` (the open items below, filed for later).

Published as an artifact for reading:
<https://claude.ai/code/artifact/233d6c5c-5efa-4064-b855-b1c2eda87662>

> **Method.** Table classification is based on real query call sites and Eloquent `$table`
> bindings, **not** name matching. That distinction matters: www's `User` model binds to
> `laravel_users`, so the older `users` table is dead despite the obvious-looking name.
> `packages/wwwafsportal_v1` is excluded throughout.

---

## 1. The four databases

All four match their checked-in export exactly — nothing missing, nothing extra — and all
eight migrating projects report zero pending.

| Database | Live | From migrations | Legacy | Owning projects |
|---|---:|---:|---:|---|
| `testdb_appfactorystudio` | 200 | 137 | **62** | www (34 migrations); auth's Checkpoint schema on the `checkpoint` connection; members ships none by design |
| `testdb_afs_apps_db` | 89 | 80 | **8** | coordinator (22), dream_graph (30), notes (11), pdf_editor (6), flow (4) |
| `testdb_afsdb_market` | 45 | 44 | 0 | market (54) |
| `afs_idpdb_test` | 9 (11 from 2026-10-10: + `admin_users`, `admin_sessions`, `plans/auth_idp_tables`) | 8 (10) | 0 | auth (5) |

Laravel's own `migrations` ledger is excluded from the legacy column in every row — it is
the one table in each database that no migration creates by design.

**The legacy column is the whole point of this document.** It is schema that predates
Laravel and that no migration will ever recreate. In `testdb_afsdb_market` and
`afs_idpdb_test` it is zero: those two are fully migration-owned and need nothing further.

---

## 2. The 62 legacy tables in the shared database

| Class | Count | Meaning | Disposition |
|---|---:|---|---|
| **In use** | 33 | Queried by live methods | Keep — present and correct |
| **Unread, holds data** | 6 | No reader anywhere, but rows exist | Open — §5 |
| **Dead** | 23 | Empty *and* no reader anywhere | Drop — §5, blocked |

### In use (33)

Reached through the ported `DB` class (`app/Http/Controllers/DB.php`), not Eloquent.
`notifications` alone has 13 call sites in live public methods such as
`FunctionsController::add_notification()`.

```
notifications  subscribers  general_data_storage  members_webbuilder  mail_handlers
configurations  forms  direct_payments  users_admin  subscriber_articles  sponsorships
subscriber_catalogs  users_sponsor  usedids  integration_connections  events
users_email_copy  system_running_services  payments  money_accounts  member_auth_keys
forms_progress  files  documents  auth_check  analytics  subscriber_deliveries
form_submissions  system_que  sponsorship_object  processor_customers  donations  contactus
```

### Dead — empty, and read by nothing (23)

Two superseded generations, plus a few singletons the `laravel_*`-prefixed generation
replaced (`sessions` → `laravel_sessions`, `password_reset_tokens` →
`laravel_password_reset_tokens`).

```
checkpoint_acl_grants  checkpoint_acl_role_hierarchy  checkpoint_allocation_log
checkpoint_feature_overrides  checkpoint_recovery_codes  checkpoint_two_factor
checkpoint_user_allocations  checkpoint_user_roles  checkpoint_user_traits
ext_admin_users  ext_admin_users_2fa  ext_admin_users_audit_log
ext_admin_users_confirmations  ext_admin_users_otps  ext_admin_users_remembered
ext_admin_users_resets  ext_admin_users_throttling
events_tickets  laravel_queue_jobs  password_reset_tokens  sessions  subscriptions
system_monitor
```

The `checkpoint_*` names here are the generation **before** `checkpoint_2fa`. Do not wire
new code to them; auth's migration owns the current set on the `checkpoint` connection.

---

## 3. The `engine_*` tables belong to something not in this repo

Seven tables in `testdb_afs_apps_db` carry an `engine_` prefix. Five — `engine_users`,
`engine_admins`, `engine_releases`, `engine_release_downloads`,
`engine_release_download_targets` — hold 1–3 seed rows each. The two added on 2026-08-30
for export parity, `engine_sessions` and `engine_password_reset_tokens`, are empty.

**No project in this estate reads any of them.** They belong to a JoystickEngine
release/download portal whose code is not in this repository. The rows were left in place
as the only copy of that data. Together with the bare `notes` table (1 row, superseded by
the notes app's own `notes_*` set) they are the whole of that database's legacy surface.

---

## 4. Schema specified by the JoystickEngine plan steps

Only 2 of the 110 steps in `JoystickEngine_v2/plan/steps/` specify database schema. Both
are AFS Connect work landing in `testdb_afs_apps_db`, owned by coordinator.

### Step 061 — AFS Connect engine — **built**

All three tables exist: `afsconnect_engine_projects`, `afsconnect_engine_commits`,
`afsconnect_engine_seen_users`, created by
`coordinator/database/migrations/2026_07_28_190000_create_afsconnect_engine_tables.php`.

The step's `engine_connect_items` / `engine_connect_activity` / `engine_connect_task_cache`
are correctly **absent**. Draft one needed them to mirror coordinator's store across a
relay; the final design removes the relay and the step says so explicitly. **Their absence
is the design, not a gap** — do not "fix" it.

### Step 060 — AFS Connect platform — **partly built**

Built: `sources_enabled` and `sources_allowed` JSON columns on `afsconnect_settings`,
`min_tier_index`, the `AllocationGateway` seam.

Not built:

- **`afsconnect_source_status`** — the staleness surface and circuit breaker. Step 060
  §461–472 gives it a full `Schema::create`. No migration anywhere creates it and it exists
  in no database.
- **`orphaned_at`** — link orphaning instead of silent loss. No occurrence in coordinator.

Also outstanding: the twelve per-source boolean columns the JSON maps were meant to replace
(`source_dream_graph`, `source_flow`, `source_notes`, `source_pdf_editor`,
`source_www_analytics`, `source_www_appointments`, `source_www_direct_payments`,
`source_www_ecommerce`, `source_www_events`, `source_www_forms`, `source_www_sponsorships`,
`source_www_subscribers`) are still live in `AfsConnectSetting`'s `$fillable` and `$casts`,
so both representations coexist.

---

## 5. Open items

Filed in `TODOS.md` under "Database schema audit". Repeated here so this document stands
alone.

1. **23 dead tables are still present.** The `DROP` was blocked by the permission
   classifier on 2026-08-30 and was not routed around. Each was verified empty immediately
   before the attempt. **If you drop them, remove them from
   `databases/testdb_appfactorystudio.sql` in the same pass** — otherwise the next rebuild
   restores them and the cleanup silently undoes itself.
2. **Six unread tables hold rows** and were therefore left alone:
   `checkpoint_two_factor_pending` (9), `company_employee_verify` (9), `users` (2),
   `laravel_systemsusers_config` (2), `domain_auth_sessions` (2), `key_tokens` (1). The
   `users` rows are the generation `laravel_users` replaced — both hold the same 2 users.
3. **`afsconnect_source_status` needs a coordinator migration.** Coordinator owns the apps
   database and already ships the other `afsconnect_*` migrations, so it belongs there —
   **not** in a legacy-schema file, which is only for tables no migration creates.

---

## 6. Things that will mislead you

- **A table's name does not tell you whether it is live.** `users` and `laravel_users` both
  exist and both hold the same 2 rows; only the second is read. Check the model's `$table`.
- **`engine_connect_*` missing is correct.** See §4.
- **Export parity is not the same as "complete".** All four databases match their exports,
  and 23 of those tables are still dead. Parity only means the two agree.
- **The legacy-schema files are not a catch-all.** `testdb_appfactorystudio.legacy-schema.sql`
  holds 25 tables; the shared database's real legacy surface is 62. The other 37 live in
  the full `.sql` export only, which is why `handoff.md` §5's four-step recipe produces a
  database 37 tables short of what the export describes.
