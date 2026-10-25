# 02 — Existing accounts, dumps and documents: completion report

> **Status: built 2026-10-10 except the P2 cleanup (dropping the unused shared copies), which is deliberately not done. Not committed.** Supersedes [02-data-and-dumps.md](../02-data-and-dumps.md) for current behaviour.

## 1. What was built

| Plan | Built as |
|---|---|
| §2.2 copy command | `w/auth` `php artisan admin:import-from-shared [--apply]` (`app/Console/Commands/ImportAdminUsersFromShared.php`): reads `admin_users` on the `checkpoint` connection, writes the default connection; dry run unless `--apply`; prints username/email/role/active, never a hash; copies an account only when no row has the same username or email, keeping the id when free; repeatable; refuses when both connections are the same database; does nothing when the shared table is absent; sessions are never copied; the shared copy is never changed. Tested (see 01's report). |
| §2.3 dumps | In `lab2/databases`: the one `INSERT INTO admin_users` removed from `data-only/v2/testdb_appfactorystudio.data-only.sql` and from `production/testdb_appfactorystudio.data-only.sql`; a new **empty** `data-only/v2/afs_idpdb_test.data-only.sql` (lab starts clean, OD1(b); verified to load through the import script's `INSERT IGNORE` filter); the removed legacy account and its 4 expired sessions kept, **unimported**, in `legacy/admin-console.legacy-rows.sql`. |
| §2.3 exports | `full/testdb_appfactorystudio.sql`: both `CREATE TABLE`s, their index, auto-increment and constraint blocks, and the trailing "Checkpoint admin console data" block removed. `full/afs_idpdb_test.sql`: the two tables added (the new migration's indexes and foreign key), the migrations ledger row `(5, 2026_10_10_000001_create_idp_admin_tables, 1)` and its `AUTO_INCREMENT` raised to 6. |
| §2.3 Vagrantfile | A second provisioner `db-import-idp` after `db-import` (args: `afs_idpdb_test` and the new dump). `import-databases.sh` is unchanged; `app-auth` runs before it. |
| §2.4 documents | `w/AGENTS.md` §5 (the `afs_idpdb_test` row now includes the IdP admin tables; the prefixes list moves `admin_*` out of the shared group, with a note on the 2026-10-10 change); `plans/00_ISSUES.md` I9 (databases table; the `SeedRunner` row corrected — Auth has no `SUPER_ADMIN_*`; the "known warning" line explained); `plans/DATABASE-SCHEMA-AUDIT.md` (the `afs_idpdb_test` row). |

## 2. Verification

- The edited `full/afs_idpdb_test.sql` imported into a throwaway database on the lab MariaDB: **11 tables, `admin_sessions` and `admin_users`, the foreign key `admin_sessions_admin_id_foreign`, 5 migration rows**. The empty data-only dump loaded after it. Dropped afterwards.
- The edited `full/testdb_appfactorystudio.sql` **could not be import-tested**: it stops at line 30 on the collation `utf8mb4_0900_ai_ci` (a MySQL 8 collation MariaDB does not have) on the `analytics` table, which was already in the file before this work. The edit itself was checked by search: no `admin_users` / `admin_sessions` reference remains in either shared export or either shared data-only dump.
- The data-only dumps changed by exactly one line each; no line-ending churn.

## 3. Applied to the lab's databases (2026-10-10)

From this PC, with the local Auth code and the lab databases supplied through process environment (no `.env` was edited or switched):

- `php artisan migrate --force`: only `2026_10_10_000001_create_idp_admin_tables` ran (batch 2). `afs_idpdb_test` now has 11 tables.
- `php artisan db:seed --class=AdminUserSeeder --force`: created `admin` / `admin@example.com`, `super_admin`, with the default password; verified the hash matches the default and that the code treats it as "must change".
- `php artisan admin:import-from-shared` (dry run): lists the legacy `admin` as "already there" (same username) and copies nothing.
- The shared database's copies are untouched (`admin_users` 1 row, `admin_sessions` 4 rows).

## 4. Not done

- **P2: dropping the unused `admin_users` / `admin_sessions` from the shared databases** — a destructive change that needs the owner's explicit go-ahead (OD4).
- Production and dev: nothing run; the production checks are in [03](../03-rollout-and-verification.md) §3.2.
- The lab's **running** Auth app still has the old code (see 01's report §5), so the acceptance checks that need a sign-in (A5, A6) and the rebuild check (A7) are open.
- The shared export's MariaDB collation problem (above) is untouched.
