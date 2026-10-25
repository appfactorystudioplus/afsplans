# www_brain — completion (phases P0–P4)

**Built 2026-09-26** across `www`, the new `packages/brain-client`, and the seven apps. Plan:
[`../README.md`](../README.md). Uncommitted at the time of writing. Not enabled in production.

## What was built

### `www` — the Brain

| Area | Files |
|------|-------|
| Schema (9 tables) | `database/migrations/2026_09_26_00000{1,2,3}_*` — registry, manifests, settings, setting history, mail handlers, handler history, mail log |
| Models | `app/Models/Brain/BrainApp*.php` (7) |
| Logic | `app/Services/Brain/Apps/` — `SchemaValidator` (restricted JSON-schema subset), `ManifestValidator`, `ManifestService` (idempotent, immutable versions, orphaned/invalid flagging), `SettingsService` (effective values, ETag, compare-and-set, history, rollback, reset), `AppMailService`, the `AppMailTransport` seam and `BillingMailerTransport` |
| App-facing surface | `routes/api.php` group `internal/brain/apps/{app}` (`brain.only` + `brain.surface`): `manifest`, `settings` (ETag/304), `mail/send`, `heartbeat`; `BrainAppSurfaceController` |
| Staff surface | `routes/portal.php` group `api/v1/brain/apps` (`brain.only` + `portal.admin.auth` + `portal.level99`): apps, overview, settings, history, rollback, mail, test send, mail log; `BrainAppsController` |
| Guards | `brain.surface:MEMBERS` on the enforcement route so no other app's key can reach it; per-app keys `BRAIN_SURFACE_KEY_<APP>` in `config/brain.php`; mail limits |
| Registry seeding | `php artisan brain:apps-seed` |
| Mail | `BillingMailer::sendOneJson()` (split out of `sendOne()`); **a fix to `FunctionsController`** (`GetClientRequestToken()` / `ClietRequestTokens()` were bare calls to static methods, so `BillingMailer` and `MailHandlerSender` could never reach the send) |
| UI | `resources/js/portal/admin/controllers/brain/apps/brain_apps.ts`, two views in `brainView.ts`, an Apps button on the Brain landing page |

### `afs/brain-client` (`packages/brain-client`, 22 tests)
Signer, `Brain::setting()` / `settings()` / `mail()`, a cache with `If-None-Match`, a circuit breaker, rate-limited
warnings, re-validation of every value against the app's own manifest schema, a `FallbackMailer` hook for `essential`
events, and the commands `brain:register-manifest` (idempotent; `--strict` to fail a deploy) and `brain:send-test`.

### Apps
- **`notes` (pilot):** package, `config/brain_manifest.php` (`maintenance.enabled`, `maintenance.message`, mail event
  `brain_test`), `BrainMaintenance` middleware (503 with the Brain's message; `up` and `internal/*` exempt), env, 7 tests.
- **`flow`, `dream_graph`, `pdf_editor`, `coordinator`, `market`, `members`:** package, an empty manifest, env.
- **Env sets:** `BRAIN_SURFACE_KEY_<APP>` in `www/_env/*`; `BRAIN_APP_NAME`, `WWW_BRAIN_SURFACE_URL`,
  `WWW_BRAIN_SURFACE_KEY`, `BRAIN_CLIENT_ENABLED` in each app's. Random keys for `local` and `vagrant`; `dev` and `prod`
  are **empty**.

## Verified

| Check | Result |
|---|---|
| `www` new tests (validators, app surface, staff API) | 91 pass, plus the 28 from the enforcement work; the Direct Payments, Brain and Unit suites together: 220 pass |
| `brain-client` tests | 22 pass |
| `notes` tests | 9 pass (7 new) |
| `members` tests | 81 pass, unchanged with the package installed |
| Whole `www` suite | 708 tests; the same 3 unrelated failures and 1 error as before this work |
| `tsc --noEmit` on `www` including the new UI | 0 errors |
| Lab: migrations, `brain:apps-seed` | 3 migrations ran, 7 apps added |
| Lab: `notes` registers its manifest | `Manifest 2026.09.26-1 created (added: 2 …)`; the Apps list showed it with a last-seen time |
| Lab: the other six apps register | `flow`, `dream_graph`, `pdf_editor`, `coordinator`, `market`, `members` each created their (empty) manifest with their own key; all seven show a last-seen time |
| Lab: staff API with a throwaway level-99 token (created, then deleted) | list, set, reset, history, rollback, disable/enable all worked |
| Lab: Brain sets `maintenance.enabled` | `notes` answered **503 with the Brain's message within about 45 s** (one cache lifetime); `up` stayed 200; the API answered JSON 503 |
| Lab: rollback | history recorded it as a new revision with source `rollback`; `notes` recovered within about 35 s |
| Lab: **disable the app on the Brain** | `notes` fell back to its defaults (200) — the "use defaults" path — and re-enabling applied the setting again |
| Lab: mail through the route | Clean `502 failed` (`status=failed via=fallback error=provider_failed`), log rows recorded with `transport_exception`, no address stored; **never a `500`** |

## Not done, or not verified

- **A real email has not been delivered anywhere.** The legacy send pipeline needs the production host layout (it writes
  debug files under `/home/appfactorystudio/websites/dev/www15/storage/store/` and shells out to an MJML transform), so it
  cannot run on the lab. The path was proven up to the transport seam and its failure handling.
- **The Apps screens have not been opened in a browser.** They type-check, and the API behind them was driven in the lab.
- **`brain:register-manifest` is not in any deploy script.**
- **The package is installed through a `path` repository** (see [06 §6.2](../06-app-integration.md)), which will not work
  on a real server: publish `packages/brain-client` as its own repository and switch each app to a `vcs` entry first.
- **The lab was down when this pass reached it** (`web` aborted, `dns` and `web2` off — after several long `composer` runs); it was restarted with `scripts/start-lab.sh` and `web`'s resolver re-pointed at the lab DNS (the documented fix). `docker.service` on `web` was still `activating` afterwards; nothing here uses it.
- **`members`:** `composer` could not resolve its dependencies on this host without `ext-gmp` (a `badcow/dns` requirement
  unrelated to this work), so it needs `composer update afs/brain-client --ignore-platform-req=ext-gmp` where that
  extension is missing.
- Production keys, tenant provisioning starting from `_env/www/.env`, and provider sender-domain verification.
- No per-app sender override, no typed confirmation, no manifest viewer.

## Lab data left behind

Seven registry rows; `notes`' manifest; a handful of setting-history rows (all rolled back or reset — the app is at its
defaults) and mail-log rows for the failed test sends.
