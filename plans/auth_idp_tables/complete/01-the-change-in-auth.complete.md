# 01 — The change in `w/auth`: completion report

> **Status: built and tested 2026-10-10. Not committed. Not deployed to the lab's running Auth app** (the lab runs on another computer with its own checkout; see §5). The IdP login and the password-change screen were **never exercised in a browser**.
> This document says what was actually built. For questions about current behaviour it supersedes [01-the-change-in-auth.md](../01-the-change-in-auth.md).

## 1. What was built, against the plan

| Plan | Built as |
|---|---|
| §1.1 migration on the default connection | `database/migrations/2026_10_10_000001_create_idp_admin_tables.php`: `admin_users` and `admin_sessions`, each guarded by `hasTable`, plain `Schema::` (the default connection, `DB_DATABASE`), the shape the old Checkpoint migration built (including the named indexes and the cascading foreign key); `down()` inert. No column was added (§1.4 of the plan changed from a stored flag to a password-based check on 2026-10-10 before building). |
| §1.2 out of the Checkpoint migration | `2026_07_13_000001_create_checkpoint_tables.php`: the two blocks and the two commented drop lines removed; docblock now says 12 tables and where the admin tables went. |
| §1.3 guard test | `tests/Unit/Database/CheckpointSchemaConnectionTest.php`: `SHARED_PREFIXES = ['checkpoint_']`; new `IDP_TABLES` rule (must be on the default connection) and a test that a migration creates them there. It now skips comment lines (the old Checkpoint migration had commented `Schema::connection('checkpoint')->dropIfExists('admin_…')` lines that the new rule would have flagged). 4 tests, 7 assertions, passing. |
| §1.4 hard-coded account | `AdminUserService::DEFAULT_PASSWORD = 'admin123'`, defined once and used by `AdminUserSeeder`, `admin:create --default` and the check. The seeder's closing notice and the command's warning now say the person will be asked to choose a new password at sign-in. `CreateAdminUser::ensureTableExists()` is **removed**; with the table missing the command says "run `php artisan migrate`" and creates nothing. The commented-out line in `AdminController::login()` that held what looked like a real password is deleted. |
| §1.5 forced change, decided by the password | `AdminUserService::mustChangePassword()` — `Hash::check` of the stored hash against the default, cached under the admin id plus a SHA-256 fingerprint of the hash (so a password change changes the key; with no cache available it decides without it; a hash of another algorithm counts as "not the default"). `must_change_password` is a **derived** boolean in the login result and in `GET /admin/api/me` (only for the signed-in account; not in the admin list). `AdminPasswordChangeMiddleware` (after `AdminAuthMiddleware`, alias `admin.password`): while the default password is in use every `admin/api/*` route except `me`, `password`, `logout` answers `403 ADMIN_PASSWORD_CHANGE_REQUIRED`; HTML pages still load. `POST /admin/api/password` (`AdminController::changePassword`, `AdminUserService::changePassword`): needs the current password; 8–54 characters, no whitespace, not the default, not the current one; on success stores the hash and deletes the admin's **other** sessions, keeping the current one. Errors are `422` with `ADMIN_INVALID_CREDENTIALS`, `ADMIN_PASSWORD_INVALID`, `ADMIN_PASSWORD_DEFAULT`, `ADMIN_PASSWORD_SAME`. The modal is in `resources/views/admin/layout.blade.php`: static, cannot be dismissed, "Sign out" only, navigation disabled, same fields, rules and wording as `w/www`; it opens when `/me` says `must_change_password`. |
| §1.6 tests | See §3. |
| (02 §2.2) the copy command | `app/Console/Commands/ImportAdminUsersFromShared.php` — see [02's report](02-data-and-dumps.complete.md). |

## 2. Files (uncommitted, repo `w/auth`)

Modified: `app/Console/Commands/CreateAdminUser.php`, `app/Http/Controllers/Admin/AdminController.php`, `app/Services/Admin/AdminUserService.php`, `bootstrap/app.php` (alias), `database/migrations/2026_07_13_000001_create_checkpoint_tables.php`, `database/seeders/AdminUserSeeder.php`, `resources/views/admin/layout.blade.php`, `routes/admin.php`, `tests/Unit/Database/CheckpointSchemaConnectionTest.php`.
New: `app/Console/Commands/ImportAdminUsersFromShared.php`, `app/Http/Middleware/AdminPasswordChangeMiddleware.php`, `database/migrations/2026_10_10_000001_create_idp_admin_tables.php`, `tests/Feature/IdpAdmin/{IdpAdminTestCase,IdpAdminTablesTest,AdminDefaultPasswordTest,AdminCommandsTest}.php`.

## 3. Verification

| What | Result |
|---|---|
| Guard test (`tests/Unit/Database`) | **4 tests, 7 assertions, OK** |
| The new database tests, on the lab MariaDB (`10.0.0.243`) | **19 tests, 94 assertions, OK** (first run 18/19: a mistake in my own test setup — a pre-existing local row took id 1, so the copied account correctly got a new id; the test was fixed, not the command) |
| What they cover | migrate creates both tables in `DB_DATABASE` and **not** in the shared database, and `checkpoint_*` stay in the shared one; a second `migrate` is a no-op; the foreign key cascades; an existing table is left alone; the seeder makes exactly one `super_admin` whose hash verifies against the default and leaves an existing `admin` alone; login reports `must_change_password: true` and opens an `admin_sessions` row; an account with another password gets `false` and the API; while the default password is in use `/admin/api/admins`, `/stats` and `PUT /settings` answer 403 `ADMIN_PASSWORD_CHANGE_REQUIRED` and `/me` still works; the change rejects a wrong current password, too short, too long, spaces, the default, and the same password; a good change releases the API, ends the other session (a second token then gets 401), blocks the old password and stops asking; the cached answer follows the new hash; `admin:create --default` and typed credentials; `admin:create` no longer creates the table; the import command (dry run writes nothing and prints no hash, `--apply` copies only missing accounts keeping a free id, repeatable, shared copy untouched, nothing to do without a shared table) |
| How they run | They need a real MariaDB (the standing rule) and **skip themselves** under `phpunit.xml`'s default SQLite, and unless both database names start with `testdb_phpunit` and differ. Created for this: `testdb_phpunit_auth` (auth's own) and `testdb_phpunit_auth_main` (the shared one) on `10.0.0.243` (OD3). Command in `tests/Feature/IdpAdmin/IdpAdminTestCase.php`'s docblock. |
| Whole Auth suite, default settings | 55 tests, 281 assertions, 19 skipped (the new ones), **4 failures that are not from this work**: three in `CheckpointTemplateConsistencyTest` (the Checkpoint registry template has drifted; the message says to run `php artisan checkpoint:build-member-template`) and `ExampleTest` (`GET /` serves an error page by design). Neither mentions the admin tables. I did not run the suite before the change, so "not from this work" rests on those tests having no connection to it, not on a before/after comparison. |
| Layout | The Blade layout compiles to valid PHP and both inline scripts parse (Node syntax check). |

## 4. Decisions applied

OD2 as planned (server decides from the password, API held too). OD3: real MariaDB test databases, `phpunit.xml` untouched.

## 5. Not verified

- **No sign-in was attempted** (the agent never types credentials), and the **modal was never seen in a browser**; Tabler's bundle may not expose `window.bootstrap`, so the layout falls back to showing the modal by hand — untested.
- **The lab's running Auth app was not redeployed.** The lab VMs run on another computer; their `/vagrant/afs-eco/w/auth` is that computer's checkout (dated 2026-09-29, without these files), and `vagrant status` here shows the VMs as "not created". The new code reaches the lab when it is committed, pulled there, and `vagrant provision brain --provision-with app-auth` is run. Until then the lab's old code serves `/admin/login` against tables that now exist, without the forced change.
- `vendor` and a `composer install` on the lab, Auth on production, and `dev` were not touched or checked.
- The `Vagrantfile` change (step 02) could not be syntax-checked here (no Ruby on this PC).
