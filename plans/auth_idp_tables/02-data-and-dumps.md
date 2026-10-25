# 02 — Existing accounts, dumps and documents (P1)

Fixes **AF6, AF7** and the places that state the old rule. Step 01 gives every environment the right empty tables; this step deals with what is already stored.

## 2.1 What exists, by environment

| Environment | In the shared database | In Auth's own database | Known from |
|---|---|---|---|
| Lab | `admin_users` (1 row, legacy `admin`, `super_admin`, created 2026-02-28) and `admin_sessions` (4 rows) | neither table | queried 2026-10-10 |
| Dev, local | not checked | not checked | — |
| Production | not checked | not checked | step 03 §3.2 lists the read-only checks for the server agent |

## 2.2 The copy rule: accounts move, sessions do not

Sessions are short-lived tokens (`expires_at`); carrying them is pointless and would let an old token live on in a new place. Only **accounts** are copied. The shared copies are **left in place** (not dropped) until a later, separate, backed-up cleanup (P2).

An Artisan command in Auth, `admin:import-from-shared`, tagged `auth_idp_tables`:

- Reads `admin_users` on the `checkpoint` connection and writes to the default connection.
- **Dry run by default** (`--apply` to write): prints how many accounts it would copy, their `username`, `email`, `role`, `is_active` — never the password value.
- Copies an account only if no row with the same `username` or `email` exists in Auth's own table; keeps `id` when free, assigns a new one on conflict (nothing references `admin_users.id` except `admin_sessions`, which is not copied).
- Idempotent: a second run copies nothing.
- Refuses to run when the two connections point at the same database and table (then there is nothing to move).
- Works across servers (reads and writes through the two connections, no cross-database SQL), so it is not tied to both databases being on one MySQL server.

Whether the lab's legacy `admin` account is worth carrying, or only the seeder's default `admin` account (forced to change its password at first login, step 01 §1.5) is better, is the owner's call (OD1). Either way the command is the same tool.

## 2.3 The lab's dumps and the restore (so the next restore is right)

The lab restores by running every app's migrations and then importing row data (`Vagrantfile:226–228`). With Option A:

| Item | Today | After |
|---|---|---|
| `lab2/databases/data-only/v2/testdb_appfactorystudio.data-only.sql` | one `INSERT INTO admin_users` (the legacy `admin`) | remove it (the line, with its header comment) so the shared database no longer receives IDP accounts |
| `lab2/databases/production/testdb_appfactorystudio.data-only.sql` | one `INSERT INTO admin_users` | the same removal, **if** the owner keeps this file in step; it is the production-derived export |
| a new `lab2/databases/data-only/v2/afs_idpdb_test.data-only.sql` | does not exist | holds the `admin_users` row(s) to carry (decision OD1); empty-but-valid if none |
| `Vagrantfile` | `db-import` for the shared database only | a second provisioner `db-import-idp` (args: `afs_idpdb_test`, the new file); the existing script already refuses a database with no tables and uses `INSERT IGNORE`, so it needs no change |
| `lab2/databases/full/testdb_appfactorystudio.sql` | `CREATE TABLE admin_sessions` (`:30`), `admin_users` (`:46`) | remove the two tables from this export |
| `lab2/databases/full/afs_idpdb_test.sql` | no admin tables | add the two tables (same shape as step 01) |
| `00_ISSUES.md` I2's "edit-in-place" rule | exports are edited so a rebuild does not undo a fix | followed: the exports and the migration change in the same pass |

`import-databases.sh` is unchanged. Remember the provisioner's order: `app-auth` (which now creates the tables in `afs_idpdb_test`) runs before `db-import-idp`.

## 2.4 Documents that state the old rule (update in the same pass)

| Document | Statement to change |
|---|---|
| `w/AGENTS.md` §5 "Table prefixes" | `checkpoint_*, admin_*, ext_*` are listed as "the Checkpoint IdP" in the shared database. Say: `checkpoint_*` and `ext_*` are shared; `admin_*` (the IDP staff panel) are in Auth's own database |
| `w/AGENTS.md` §5 "Which database is which" | the `afs_idpdb_test` row says "auth's own Laravel tables, and nothing else": add the IDP admin tables |
| `plans/00_ISSUES.md` I9 databases table | "`afs_idpdb_test` — auth (private Laravel tables)": add the admin tables, and correct the seeder line ("Super-admin and admin users from `.env` (`SUPER_ADMIN_*`, `ADMIN_*`)"), which is not true of Auth: the seeder creates the hard-coded `admin` account with a forced first-login password change (step 01) |
| `plans/DATABASE-SCHEMA-AUDIT.md` | the `afs_idpdb_test` row (9 tables, 0 unmigrated): becomes 11 tables |
| the Checkpoint migration docblock and the guard test docblock | step 01 |

## 2.5 Work

| Phase | Work |
|---|---|
| **P1** | `admin:import-from-shared` with tests; the dump and export edits and the new import provisioner in `lab2`; the document changes |
| **P2** | After every environment has been verified and backed up: drop the unused `admin_users` / `admin_sessions` from the shared databases (a deliberate, separate decision; a hand-run `DROP` is a destructive change and needs the owner's explicit go-ahead each time) |
