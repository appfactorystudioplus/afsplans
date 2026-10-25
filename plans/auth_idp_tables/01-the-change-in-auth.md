# 01 — The change in `w/auth` (P0)

Fixes **AF1, AF2, AF4, AF8, AF9, AF10, AF12**. The IDP code already reads Auth's own database; the change makes the schema, the seeders, the command and the guard test agree with it, and gives any account that still has the default password a **forced password change**, decided by the password itself, as in `w/www`. `AdminUserService::authenticate` and the routes' structure stay; the small additions are listed in §1.5. **No column is added to `admin_users`.**

## 1.1 A migration that creates the IDP tables on the default connection

New file `database/migrations/2026_10_10_000001_create_idp_admin_tables.php`, tagged `auth_idp_tables`:

- `admin_users` and `admin_sessions` created with plain `Schema::create`, **each guarded** by `if (! Schema::hasTable(...))` (the estate rule), on the **default** connection (so the database is `DB_DATABASE`).
- The shape is exactly the existing **migration** shape (AF10), because the code and all dumps were built against it: `increments('id')`; `email` 255 unique; `username` 100 unique; `password` 255; `name` 255 nullable; `role` enum `super_admin|admin|viewer` default `admin`; `is_active` default true; `last_login` nullable; `last_ip` 45 nullable; timestamps; the two named indexes (`idx_admin_users_email`, `idx_admin_users_username`).
  `admin_sessions`: `increments('id')`; `unsignedInteger admin_id`; `token` 255; `ip_address` 45 nullable; `user_agent` text nullable; `expires_at`; `created_at` `useCurrent()`; indexes `idx_admin_id`, `idx_token`, `idx_admin_sessions_expires_at`; foreign key `admin_id` → `admin_users.id` on delete cascade.
- If a table already exists on the default connection (made earlier by `admin:create`), the guard skips it; nothing on an existing table is altered.
- `down()` is **inert**, like the Checkpoint migration (a rollback must not delete the staff accounts).

## 1.2 Take the IDP tables out of the Checkpoint migration

In `2026_07_13_000001_create_checkpoint_tables.php`, remove the `admin_users` and `admin_sessions` blocks (`:212–249`) and correct the docblock ("14 tables" → 12, and a line saying the IDP admin tables live in Auth's own database, created by `2026_10_10_000001`). Environments that already ran it keep their recorded migration and their (now unused) shared copies; fresh installs stop creating them. The `checkpoint_secondary_2fa_policy` block that follows is untouched.

## 1.3 The guard test says the right thing

`tests/Unit/Database/CheckpointSchemaConnectionTest.php`:

- `SHARED_PREFIXES` becomes `['checkpoint_']`.
- A new constant `IDP_TABLES = ['admin_users', 'admin_sessions']` and a second test: any migration touching those must be on the **default** connection (the test reports the file, line and connection, as the first does). The docblock and failure message are corrected to say what each connection holds.
- The existing "connection is configured from `MAIN_DB_*`" test stays.

(Pure file parsing; no database, matching the existing test.)

## 1.4 The initial account stays hard-coded

**Owner decision (2026-10-10):** the seeder keeps creating `admin` / `admin@example.com` with the password `admin123` as `super_admin`, and the system prompts for a new password while an account still has the default password, as the `w/www` admin dashboard does (`resources/js/portal/admin/main.ts` `promptPasswordChangeIfNeeded`). The seeder is **kept**, nothing is read from `.env`, and the `SUPER_ADMIN_*` idea of the first draft is withdrawn (those keys do not exist in Auth).

What changes:

1. The default password is defined **once**, as a constant on `AdminUserService` (`DEFAULT_PASSWORD = 'admin123'`), and used by the seeder, by `admin:create --default` (`CreateAdminUser.php:119–123`) and by the check in §1.5. Today the literal is repeated in each.
2. `AdminUserSeeder`: same account as today; "already exists → leave it" stays; the closing notice says the person will be asked to change the password at login.
3. `ensureTableExists()` (`CreateAdminUser.php:171–179`) is **removed** and replaced by a check that says "run `php artisan migrate`" when the table is missing, so there is one definition of the schema (AF10).
4. A comment in `AdminController::login()` (`:125`) holds what looks like a real password (`// $this->adminService->update(1, [...])`). It is deleted in the same change (AF12).

## 1.5 How the forced change works — decided by the password, as in `w/www`

**How `w/www` does it (read from `login.ts:106`, `main.ts:225–324`, `_sources/src/users/api.ts:155`):** after a successful login the browser compares the typed password with the literal `ChangeMe123!` and sets a `sessionStorage` flag; `promptPasswordChangeIfNeeded` shows a static modal (no close button, navigation disabled) with *new password* and *confirm* (8–54 characters, no spaces, must match), calls `POST /users/current/password`, clears the flag and shows a toast. The prompt is driven by what the password **is**: it returns on every login, in any browser, until the password is changed. The server itself enforces nothing.

Auth does the same, deciding on the server, with no stored flag:

| Part | Behaviour |
|---|---|
| Decision | "Must change" = the account's stored hash verifies against `AdminUserService::DEFAULT_PASSWORD` (`Hash::check`). Any account that still has `admin123`, however it was created, is prompted; an account whose password was changed never is. Nothing is stored. |
| Cost | A bcrypt check on every protected request would be slow, so the result is cached under a key made from the admin's id **and a fingerprint of the stored hash** (`sha256` of the hash), with no expiry needed: when the password changes the hash changes and so does the key. |
| Login and `/me` | `AdminUserService::authenticate` and `GET /admin/api/me` include a computed boolean `must_change_password` (derived, not stored) so the screen knows to open the modal. |
| Enforcement | A small middleware, `AdminPasswordChangeMiddleware`, in the protected group after `AdminAuthMiddleware`: while the signed-in admin still has the default password, every protected route **except** `POST /admin/api/password`, `GET /admin/api/me` and `POST /admin/api/logout` answers `403 ADMIN_PASSWORD_CHANGE_REQUIRED`; the HTML pages still load (they render the modal). So the API stays blocked until the password is changed, not only the screen. |
| Change | New `POST /admin/api/password` with `current_password` and `new_password`. Checks: the current password verifies; the new one is 8–54 characters, has no whitespace, is **not** the default and differs from the current. On success: store the new hash, **delete this admin's other `admin_sessions` rows** (a leaked default-password session ends), keep the current one, answer success. Failures use the existing `{success:false, error:{message, code}}` shape. |
| Screen | The admin layout (`resources/views/admin/layout.blade.php`) gets the static modal with the same fields, rules and wording as `w/www`, disables navigation until done, and shows a toast. It opens when `/admin/api/me` says `must_change_password` is true. |
| Strings | English first; the keys follow the estate's i18n pattern so other languages can follow (`new_way` D27 is the same open question). |

Differences from `w/www`, deliberate: the decision is made on the server instead of by a literal in the browser, and the API is held until the change is made. Same prompt, same rules, same wording.

## 1.6 Tests

| Test | Proves |
|---|---|
| the updated guard test (1.3) | the IDP tables are never created on `checkpoint`, `checkpoint_*` never on the default |
| a migration test on a **real MariaDB** test database | `migrate` creates both tables in `DB_DATABASE`, not in `MAIN_DB_NAME`; a second `migrate` is a no-op; the foreign key works; an existing table is left alone. **Auth's `phpunit.xml` runs SQLite in memory by default (`:26–27`)**; the standing rule is to test against the real database, so these tests need a MariaDB test database for Auth (the way `w/www` uses `testdb_phpunit_www` on `10.0.0.243`): a new `testdb_phpunit_auth`, given through the `DB_*` environment of the run, `phpunit.xml` untouched (OD3) |
| seeder tests | creates exactly one `super_admin` `admin` whose hash verifies against the default; a second run creates none; with an existing `admin` it changes nothing |
| `AdminUserService` tests | the seeded account signs in, the result carries `must_change_password: true`, and a session row exists in `admin_sessions` (the path that failed on a table made by `admin:create` alone); an account with another password gets `false`; after a password change the cached answer follows the new hash |
| middleware / endpoint tests | while the password is the default, `GET /admin/api/stats` and `PUT /admin/api/settings` answer 403 `ADMIN_PASSWORD_CHANGE_REQUIRED`; `/me`, `/logout` and the change endpoint work; the change rejects a wrong current password, a short password, a password with spaces, the default password, and the same password; a good change makes a protected route answer normally and removes the admin's other sessions |
| `admin:create` tests | `--default` creates the account from the one constant; typed credentials create a normal account (prompted only if the typed password happens to be the default); with the table missing it tells the user to run `migrate` and creates nothing |

Run at the end of the P0 work, once (standing rule). The modal itself is checked in the acceptance run (step 03), not by a browser test.

## 1.7 Work

| Phase | Work |
|---|---|
| **P0** | 1.1–1.5 and the tests of 1.6; then, by the owner: `migrate` Auth on the lab, seed, sign in and change the password (step 03 §3.4) |
| **P1** | Remove the dead `AdminController` copy from `w/www` (AF11) — a separate change, in `w/www`, the owner's call |
