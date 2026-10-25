# 03-database-schema — completion record

**Plan file:** [`../03-database-schema.md`](../03-database-schema.md)
**Rollout phase:** K1 (§11.1)
**Repo:** `w/www`
**Date:** 2026-09-13
**Status:** ✅ Shipped and applied to the local database — the sections below; §3.3 (return
requests, K9) and §3.6 (later cleanup, not this plan) are explicitly out of scope here

## What shipped

### §3.2 — `laravel_site_user_connections`

- Migration `2026_09_13_000010_create_site_user_connections_and_mode_snapshots.php` — table
  created exactly as specified: `site_users_id`, `module_key`, `instance_key` (default `'*'`),
  `guest_checkout`, `label`, `settings` (json), `status`, `created_by`, the
  `su_conn_instance_uq` unique on `(module_key, instance_key)`, and
  `su_conn_module_status_idx`.
- `App\Models\Externals\SiteUsers\SiteUserConnection` — `mode()`, `isModuleWide()`, `setting()`,
  `scopeActive()`/`scopeVisible()`, `toAdminArray()`. Two methods the plan's shown class body
  calls but never defines (`isActive()`, `isReadOnly()`) were added — both one-line reads of
  `status`, needed for `toAdminArray()`'s `read_only` key and for K1's own `activeFor()` logic
  in the registry (below) to compile at all.

### §3.4 — the backfill, and the X29 snapshot

- Pulled out of the migration into `App\SiteUsers\SiteUsersConnectionsBackfill`
  (`captureSnapshots()`, `backfill()`) so it is a unit `ConnectionsBackfillTest` calls directly
  against an ad-hoc sqlite schema, not something only exercisable by running a real MySQL
  migration — the migration file calls the same two methods.
- `laravel_site_user_mode_snapshots` created in the same migration file, and
  `captureSnapshots()` runs **before** `backfill()`, per the ordering the plan's own docblock
  insists on.
- Backfill semantics match §3.4 exactly: `off` produces no row; a store's own `site_users_id`
  wins when it names a real active module; otherwise the sole active module is used; two or
  more candidate modules with no way to choose is skipped and logged (`Log::warning`, matching
  the plan's `logger()->warning(...)` call); re-running is a no-op; the Direct Payments
  module-wide row is created for the first `link_payment_customers=true` module found and every
  later one is skipped and logged, matching the "two modules cannot both hold `*`" rule.
- On the local database: **0 rows backfilled** — no ecommerce store, event or appointment
  calendar in `testdb_appfactorystudio` currently has a non-`off` `site_users_mode`, so both
  tables were created empty. `siteusers:connections-audit` confirms a clean report on this
  deployment (exit 0).

### §3.7 — deleting a user (X20)

- `App\SiteUsers\SiteUsersUserDeletion::detachAndDelete()` — one transaction: nulls
  `site_user_reference` **and** `site_user_id` (the dual-write window means a reader still on
  the bigint must not keep resolving a deleted user's old rows either — not explicit in the
  plan text, added because leaving the bigint live would reproduce X20 for exactly as long as
  step 3 hasn't cut reads over) on every table in `TABLES`; deletes `laravel_site_user_customer_links`
  and (`Schema::hasTable()`-guarded, since it doesn't exist until K9) `laravel_ecommerce_return_requests`
  outright; deletes `laravel_site_user_payers` too — not named in the plan's table, but it is
  the same shape (a payments identity keyed to the user) and DP6 introduced it after this plan
  document was written.
- Wired into `SiteUsersAdminController::deleteUser()`, replacing the bare `$user->delete()`.
  The response gained a `detached` key (table → row count) — additive, no existing test or
  caller asserts the old shape.
- `TABLES` is twelve tables, not the plan's thirteen-item list, because `laravel_site_user_customer_links`
  and `laravel_ecommerce_return_requests` are in `DELETE_TABLES` instead (deleted, not nulled) —
  see the identifier-migration completion doc for the two table-name corrections against the
  real schema.

### §3.8 — test schema helper

- `tests/Support/CreatesSiteUsersSchema.php` gained `laravel_site_user_connections`,
  `laravel_site_user_mode_snapshots`, and a `site_user_reference` column on its existing
  `laravel_site_user_customer_links` block (needed the moment `linkCustomer()` started
  dual-writing it).
- `tests/Support/CreatesConnectionsFixtures.php` — the `connect()` helper, verbatim from the
  plan.

## Not implemented

- **§3.5 — the derived-column write-through's actual wiring to a caller.** The class
  (`SiteUsersDerivedColumns`) exists and is unit-correct (see the doc-04 completion record) but
  nothing calls `sync()` yet — there is no admin write until K4, and K2 is what re-points the
  readers this column exists to keep honest for one release. Documented here so a reader of
  this file isn't surprised the column-sync machinery is inert.
- **§3.3 — `laravel_ecommerce_return_requests`.** K9's table, not K1's — every reference to it
  in this K1 work (`SiteUsersUserDeletion`, the audit command) is `Schema::hasTable()`-guarded
  and does nothing until that table exists.
- **§3.6 — later cleanup.** Explicitly not this plan; noted only so the grep gate it describes
  isn't mistaken for something K1 should have run.

## Files

**New:**
`database/migrations/2026_09_13_000010_create_site_user_connections_and_mode_snapshots.php`,
`app/Models/Externals/SiteUsers/SiteUserConnection.php`, `app/SiteUsers/SiteUsersConnectionsBackfill.php`,
`app/SiteUsers/SiteUsersUserDeletion.php`

**Modified:**
`tests/Support/CreatesSiteUsersSchema.php`,
`app/Http/Controllers/Externals/SiteUsers/SiteUsersAdminController.php`

**New (tests):**
`tests/Support/CreatesConnectionsFixtures.php`,
`tests/Unit/SiteUsers/SiteUserConnectionModelTest.php`,
`tests/Feature/SiteUsers/ConnectionsBackfillTest.php`,
`tests/Feature/SiteUsers/SiteUsersUserDeletionTest.php`

## Acceptance

| Criterion | Status | Evidence |
|---|---|---|
| `mode()`, `isModuleWide()`, `setting()` dot paths, casts | ✅ | `SiteUserConnectionModelTest` (7 tests) |
| `required`/`guest` columns become rows with the right `guest_checkout`; `off` produces none | ✅ | `ConnectionsBackfillTest::test_required_and_guest_columns_become_rows_with_the_right_guest_checkout`, `test_off_produces_no_row` |
| Two modules with no store column → skipped and logged | ✅ | `ConnectionsBackfillTest::test_two_modules_with_no_store_column_is_skipped_and_logged` |
| Re-running writes nothing new | ✅ | `ConnectionsBackfillTest::test_rerunning_writes_nothing_new` |
| X29 — the snapshot survives the first derived sync | ✅ | `ConnectionsBackfillTest::test_capture_snapshots_records_only_non_off_modes_and_is_idempotent`; the sync itself is not yet wired to a caller (see Not implemented), so the "survives a sync" half of X29 is proven by construction (nothing writes over it) rather than by running a sync in this phase |
| X20 — deleting a user nulls `site_user_reference` on every table in `TABLES`; customer links and return requests deleted; orders survive | ✅ | `SiteUsersUserDeletionTest` (6 tests) |
| `TABLES` matches every `site_user_reference` column in the schema | ✅ | `SiteUsersUserDeletionTest::test_tables_constant_matches_every_site_user_reference_column_in_the_schema`, pinned against the identifier migration's own table list |

## Verification

```bash
cd w/www
php artisan test tests/Unit/SiteUsers/SiteUserConnectionModelTest.php \
  tests/Feature/SiteUsers/ConnectionsBackfillTest.php \
  tests/Feature/SiteUsers/SiteUsersUserDeletionTest.php
php artisan migrate:status | grep 2026_09_13_00001
php artisan siteusers:connections-audit
```

## Residue

- **`isActive()`/`isReadOnly()` on `SiteUserConnection`** — gap in the plan's own shown class
  body, filled here; noted so K12's disconnection work (which the plan says re-specifies
  `isReadOnly()`'s semantics) doesn't assume it's new.
