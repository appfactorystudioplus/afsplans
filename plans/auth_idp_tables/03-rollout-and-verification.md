# 03 — Rollout, verification, rollback

## 3.1 Order matters: the forced password change ships with the migration

AF9: the moment `admin_users` exists in Auth's own database, `AdminUserSeeder` creates `admin` / `admin123` as `super_admin` on the next `db:seed`. The owner keeps that account, so **step 01 §1.5 (the forced password change, enforced on the server) must be in the same release as the migration, never after it.** In every environment the sequence is below. **The agent runs none of it**; each command is the owner's to run, in the order and at the time the owner chooses.

1. Deploy the Auth code (migration + seeder + command + guard test).
2. `php artisan migrate --force` in `w/auth` (plain; never `migrate:fresh`, which AGENTS.md §5 reserves for explicit, per-time authorisation).
3. Either `php artisan db:seed --force` (creates `admin` with the default password) or `php artisan admin:import-from-shared` (dry run, then `--apply`) — the owner chooses per environment (OD1).
4. The **owner** signs in at `/admin/login` (the agent never types credentials), is stopped by the password-change modal, and sets a new password.

Note, for the owner's own judgement only: between the seed and the first login the account has the known default password. Who can reach `/admin/login` is decided by `AdminIpMiddleware` and the owner's settings (`ADMIN_ALLOW_ALL_IPS`, `ADMIN_ALLOWED_IPS`); this plan does not set or change them.

## 3.2 Production first needs facts (read-only; for the server agent)

Nothing in this plan is run on production until these are answered. Append to the server-agent handoff (`plans/handoff-share-server.md`) when the owner says so. Hide all passwords and hashes; paths and names are fine.

1. Where the tables are, and how big: `SELECT table_schema, table_name, table_rows FROM information_schema.tables WHERE table_name IN ('admin_users','admin_sessions');` — in `afsdb_identity_provider` (Auth's own), `afsdb_appfactorystudio` (shared), or both.
2. In each place found, the accounts without credentials: `SELECT id, username, email, role, is_active, last_login, created_at FROM admin_users;` — **never `password`**.
3. Auth's `.env` (values for the keys only): `DB_DATABASE` and `MAIN_DB_NAME` only.
4. Auth's code level: `git -C <auth> log --oneline -1`, and whether `database/migrations/2026_07_13_000001_create_checkpoint_tables.php` contains `admin_users` (`grep -c`). `php artisan migrate:status | grep -i checkpoint` in Auth.
5. **How Auth is deployed:** does any deploy step run `php artisan db:seed` (or `SeedRunner`)? This decides whether `admin` / `admin123` may already exist on production today (a table that already exists in `afsdb_identity_provider`, made by `admin:create`, would already have been seeded) and so whether that password must be changed there right away.
6. Whether `https://<auth host>/admin/login` is reachable from outside, and a count of `admin_users` errors in `storage/logs/laravel.log` (`grep -c`, not the lines).

The answers decide how step 02's copy is used and whether a production password must be rotated immediately (OD2).

## 3.3 The lab — what changes, in order (each lab change needs the owner's say-so)

1. Code in `w/auth` and the dump/export edits in `lab2` (reviewed, then committed when told).
2. `vagrant provision brain --provision-with app-auth` (runs `migrate --force` for Auth with the lab `.env`), then `--provision-with db-import-idp` once the new dump exists.
3. Seed or import (3.1 step 3). No `.env` change is needed.

## 3.4 Acceptance on the lab

| # | Check |
|---|---|
| A1 | `afs_idpdb_test` now has `admin_users` and `admin_sessions` (11 tables); `testdb_appfactorystudio` is unchanged by this release (its legacy copies still there) |
| A2 | `php artisan migrate:status` in Auth shows `2026_10_10_000001_create_idp_admin_tables` ran; the `migrations` row is in `afs_idpdb_test` |
| A3 | `db:seed` creates exactly one `super_admin` `admin` with the default password; a second `db:seed` creates none |
| A4 | `admin:import-from-shared` without `--apply` lists the legacy account (username, email, role — no password) and writes nothing; with `--apply` copies it; a second run copies nothing |
| A5a | While the account still has the default password, a protected API route called from the owner's signed-in session answers `403 ADMIN_PASSWORD_CHANGE_REQUIRED`, and the modal cannot be closed |
| A5 | **The owner** signs in at `https://auth.10-0-0-243.sslip.io/admin/login`; the admin panel loads; its "database" info shows `afs_idpdb_test` |
| A6 | After A5 the account's `last_login` changed and one `admin_sessions` row exists in `afs_idpdb_test` |
| A7 | A rebuild of the lab (the Vagrant sequence: apps migrate, then the two imports) ends with the IDP tables in `afs_idpdb_test` holding the carried account(s) and nothing in the shared copy from the dump |
| A8 | The Auth guard test and the new tests pass (run once, at the end) |

## 3.5 Rollback

The change is additive: a new migration that only creates tables, a seeder that is stricter, a command, a test. To go back, redeploy the previous Auth code. The new tables stay (the migration's `down()` is inert on purpose); nothing in the shared database was dropped or altered, so the old behaviour (reading from Auth's own database) is exactly as before the release. The legacy copies are untouched until step 02 §2.5 P2.

## 3.6 What stays unverified until it is run

The IDP login on any environment (never attempted by the agent); production's state (3.2 unanswered); the dev environment; whether anything outside the repositories (a script, a cron job, a hand-kept SQL) creates or reads `admin_users` in the shared database.

## 3.7 Status (2026-10-10)

| Check | State |
|---|---|
| A1 `afs_idpdb_test` has the admin tables (11 tables); the shared database unchanged | **done on the lab databases** (migration run from this PC; see [02's report](complete/02-data-and-dumps.complete.md) §3) |
| A2 migration recorded in `afs_idpdb_test.migrations` | **done** (batch 2) |
| A3 `db:seed` creates exactly one `super_admin` `admin` with the default password; again creates none | **done** once; the second-run case is covered by a test |
| A4 `admin:import-from-shared` | **done** as a dry run on the lab (the legacy `admin` is "already there"); `--apply` is covered by tests |
| A5, A5a, A6 sign-in, the modal, the held API, `last_login` and a session row | **open** — needs the new code on the lab's running Auth app (the lab runs on another computer) and the owner to sign in |
| A7 a full lab rebuild ends with the right tables and imports | **open** — needs the rebuild on that computer |
| A8 tests | **done** — guard 4/4; 19 database tests / 94 assertions (see [01's report](complete/01-the-change-in-auth.complete.md) §3) |
| §3.2 production checks | **open** — for the server agent / owner |

To finish A5–A7 on the lab: commit and push `w/auth` and `lab2`; pull on the computer that runs the VMs; `vagrant provision brain --provision-with app-auth`, then `--provision-with db-import-idp`; sign in at `/admin/login` as `admin` / `admin123`; the modal must appear and block the panel until a new password is chosen.
