# 02 — Authentication: the device token — Completion

**Verified:** 2026-10-04 (PHP lint, route registration, command registration and a no-database capability smoke test; Flutter `analyze` clean, 49 app tests pass).

**Status:** **Built; server tests written but NOT run, lab exit check not yet done.** Nothing in 02 is missing from the code. Two things
remain and both need you: running the PHPUnit files against a reachable MySQL test database, and the P0 exit check (mint a lab token, open the app).

## What was built

| Plan item | State | Evidence |
|-----------|-------|----------|
| §2.4 `brain:device-token` command (BS1) | ✅ | `w/www/app/Console/Commands/BrainDeviceToken.php`; `php artisan list` shows it. Options `--member --label --days --list --revoke --revoke-all` as specified. Refuses off the Brain, for a member not exactly level 99, for a user that is missing/inactive, for a missing label, for a non-integer or `< 1` `--days`. Prints one `BRAIN_DEVICE_TOKEN=` line once; `--list` prints no token; `--revoke` deletes the `laravel_user_tokens` row and stamps `revoked_at`; revoking twice is a no-op |
| §2.4 registry table | ✅ | `database/migrations/2026_10_04_000001_create_brain_device_tokens_table.php` (guarded, explicit index names) and `app/Models/BrainDeviceToken.php`. Holds no token string; authentication never reads it |
| §2.5 steps 0–3 (app handshake) | ✅ | `afsbrain_flutter/lib/session/session_cubit.dart`; every row of the table has a test in `test/session/session_cubit_test.dart` |
| §2.5 failure screens with no form | ✅ | `lib/core/widgets/session_failure_page.dart`; `test/core/widgets/session_failure_page_test.dart` asserts no `TextField`/`TextFormField` for every failure kind |
| §2.5 any 401 re-runs the handshake | ✅ | `BrainApiClient.onUnauthenticated` → `SessionCubit.revalidate()` (test in `brain_api_client_test.dart`) |
| §2.7 token only to the configured host | ✅ | client never follows a redirect (3xx → `unexpectedRedirect`), registry fetch sends no token — both tested |
| §2.8 server tests | ⏳ written, not run | `tests/Feature/Brain/BrainDeviceTokenCommandTest.php` (11 tests: not-Brain, not level 99, inactive, label required, create + never-expires default, `--days`, bad `--days`, `--list` no token, `--revoke`, `--revoke-all`, no token in the log) and `BrainStaffWhoamiTest.php` (7 tests: 401 no token, 404 for level 0 and 98, 404 off the Brain, 200 shape and honest capabilities, device label + expiry, expired token 401, no token string in the body) |

## Not verified — and why

- **PHPUnit was not run.** `phpunit.xml` points at MySQL `192.168.56.11` (the old host-only lab network), which is unreachable from this PC, and the
  only live database is lab2's, which this work must not write to. The tests follow the existing `BrainAppsStaffTest` conventions, create each
  table only if absent, never drop one, and delete the `bft_`/`bfw_` rows they make. Run them where the test database is reachable:
  `php artisan test --filter=BrainDeviceTokenCommandTest` and `--filter=BrainStaffWhoamiTest` (in `w/www`).
- **Nothing has been deployed to lab2.** The live `whoami` URL still answered 404 when probed, as expected before deployment.
- **Lab exit check** (§2.8, P0): "with a freshly minted lab token in `.env`, the app reaches the Members screen and the header shows `appfactorystudio` and the
  `LAB2` badge." Today the app reaches a placeholder screen headed `appfactorystudio`; the Members screen is P1.

## Divergences

- The command class is `App\Console\Commands\BrainDeviceToken` and imports the model as `DeviceTokenRow` (both are named `BrainDeviceToken` in the plan).
- The migration is dated `2026_10_04_000001`.

## To finish 02 (all yours)

1. Deploy `w/www` to lab2 (`vagrant provision brain --provision-with app-www` on the machine hosting the VMs) and run the migration there.
2. On the Brain: `php artisan brain:device-token --label="<phone name>"`; paste the printed line into `afsbrain_flutter/_env/lab2/.env` and `.env`.
3. `cd afsbrain_flutter && flutter run` (phone on the lab Wi-Fi, lab CA installed, debug build).
