# 09-two-factor-authentication — Completion

**Status:** Code complete and build/lint-verified across all three touched repos
(`packages/checkpoint-auth`, `auth`, `members`). No live database was available in this
session, so the new migrations were written but not executed, and the full T-series
acceptance table (§8 of the plan) needs a running stack — see Follow-up work. Step 12
(JoystickEngine_v2 / `checkpoint_cpp`) was explicitly excluded from this session's scope
by the user's own decision, despite the plan's own note that it should ship with or
before this step — see §5 below for the accepted risk.

## Summary

Primary-user 2FA worked, web-only. Secondary users had none — verified zero matches for
`2fa|twoFactor|TwoFactor` in `SecondaryUserService.php`/`SecondaryUserController.php`
before starting. This step built secondary-user 2FA end to end and closed the `user_id`
collision risk between primary and secondary user id spaces.

### 1. New shared HMAC package (user's explicit choice, not the plan's own assumption)

The plan assumed members already proxies secondary-user admin actions to auth over "the
existing HMAC channel." Verified that channel doesn't exist: `applications.ts` calls
auth's `/checkpoint/secondary/*` endpoints **directly from the browser** with the
primary's own bearer token; members has HMAC patterns only for `market`/`afs-connect`
(`MarketSignature.php`/`AfsConnectSignature.php`), never auth. Per the user's direction,
built a **new, shared** `CheckpointServiceSignature` + `checkpoint.service` middleware in
`packages/checkpoint-auth` (a real published package five other apps already consume from
`github.com/appfactorystudioplus/checkpoint-auth.git`) rather than a members-only or
auth-only implementation — mirrors the existing, already-proven `AfsConnectSignature`/
`AfsConnectServiceAuthMiddleware` scheme exactly (timestamp + HMAC-SHA256 over
`{ts}.{method}.{path}.{bodyHash}`, `hash_equals`, 401 on failure) rather than inventing a
new one. 5 new unit tests (signed/verified round-trip, wrong secret, tampered body,
expired timestamp, empty-key fail-closed) — all pass, and the package's full existing
suite (20 tests) still passes.

### 2. Schema (auth) — first tracked migrations for these tables

Verified `checkpoint_2fa`, `checkpoint_2fa_pending`, and `checkpoint_secondary_users` have
**no migration or DDL file anywhere in the repo** — they exist only in the live database.
Wrote the first tracked migrations: `user_type` added to both 2FA tables with a
**NOT NULL DEFAULT 'normal'** (backfills every existing row atomically, satisfying the
plan's own "backfill before composite key" ordering without a separate UPDATE step); the
existing single-column unique key on `checkpoint_2fa.user_id` is discovered via
`information_schema` at migrate-time and replaced with `(user_type, user_id)` — not
assumed by Laravel-convention name, since the table predates Laravel migrations, and the
migration refuses to proceed (with an explicit exception) if `user_id` turns out to be a
bare PRIMARY KEY rather than a separate index, since that's a materially different shape
than assumed. New `checkpoint_secondary_2fa_policy` table exactly per plan §4.2. A fourth
migration adds `attempts` to `checkpoint_2fa_pending` for rate limiting (§5.4, see below).

**Not run.** No database connection was available in this sandbox; running these against
production is a deployment step for the user to review and execute.

### 3. Auth-side service layer

- **`TwoFactorService`** rekeyed: every method takes `(string $userType, int $userId)`.
  Updated all existing call sites for the rekey — two in `AuthenticationService.php`
  (`login()`/`loginWithUsername()`'s 2FA branches), five in `AuthController.php`
  (setup/enable/disable/status/recovery-codes) — all pass the literal `'normal'`, since
  they're all primary-only contexts. `verifyAndComplete()` (primary, completes a Delight
  Auth session) refactored to share its core with a new `verifyPendingToken()`
  (user-type-agnostic: validates code/recovery-code, consumes recovery codes, deletes the
  pending row, but does NOT touch Delight Auth) — `SecondaryUserService`'s own verify
  endpoint calls the shared core instead of duplicating the TOTP/recovery-code logic.
- **`SecondaryUserService::buildLoginResponse()`** gained the three-outcome branch (§5.2):
  resolves the effective policy (`resolveRequire2faPolicy()`, most-specific-wins between a
  per-user row and the account default) before minting tokens; not required → unchanged;
  required + enrolled → `requires_2fa` + pending token, no tokens; required + not enrolled
  → `requires_2fa_enrolment` + a new JWT-based enrolment token (`createTwoFactorEnrolmentToken()`,
  mirroring the existing `account_selection` token pattern), no tokens. Both `login()` and
  `selectAccount()` call `buildLoginResponse()`, so the branch covers both without
  duplication. New methods: `beginTwoFactorEnrolment()`, `confirmTwoFactorEnrolment()`,
  `verifyTwoFactor()`, `resetTwoFactor()`, `setTwoFactorPolicy()`, `getTwoFactorAdminStatus()`.
- **New endpoints** under `/checkpoint/secondary/2fa/*`: `enrol/begin`, `enrol/confirm`,
  `verify` (all authenticated by the enrolment/pending token itself, not a user bearer
  token — the user isn't signed in yet); `status`, `reset`, `policy` behind the new
  `checkpoint.service` middleware — **never reachable with a user bearer token** (T10).
- **Rate limiting (§5.4)**: `checkpoint_2fa_pending.attempts` burns a pending token after 5
  failed codes (deletes the row, forcing login to restart); a 15-minute/10-attempt cache
  counter per `(userType, userId)` closes the "restart login for a fresh token" dodge;
  enrolment-token attempts tracked in cache (keyed by a hash of the token, since it's a
  stateless JWT with no row to increment) with the same 5-attempt burn. Pending and
  enrolment token lifetime bumped from the old 5-minute default to the plan's specified 10
  minutes.

### 4. Hosted login UI (`auth`)

Extended the existing primary 2FA modal/flow rather than building a parallel one: the
secondary path reuses `#modal-two-factor` (only the verify endpoint differs, tracked via a
`pendingTwoFactorKind` flag), and a new `#modal-two-factor-enrol` handles forced
enrolment — QR rendered **client-side** via the `qrcode` npm package (added this session;
zero-dependency, no vulnerabilities of its own per `npm audit`) from the `otpauth://` URI,
secret also shown as selectable text, a recovery-codes panel requiring an explicit
"I have saved these" checkbox before the modal (`data-bs-backdrop="static"`,
`data-bs-keyboard="false"`) can be dismissed. After confirming enrolment, the original
login is retried automatically (credentials held in memory only, for this page load) since
enrolling doesn't itself authenticate. Full production Vite build verified — the one
pre-existing `tsc` error (`_testing/register-secondary.ts`, a stale 2-arg call to
`register()` unrelated to this step) is confirmed not part of the real build graph, same
finding as the desktop client's equivalent `_testing/` harness in step 07.

### 5. JoystickEngine_v2 — explicitly out of scope, risk documented

Verified the plan's own claim directly by reading `libs/checkpoint_cpp/src/SecondaryUsers.cpp`:
its JSON parsing is tolerant of unknown fields (`nlohmann::json::value()` throughout), so
adding `requires_2fa`/`requires_2fa_enrolment` does **not** crash anything — but the
outcome logic only recognizes two shapes (`multiple_accounts` or a completed login with
tokens), so a third outcome falls through to a generic `MALFORMED_RESPONSE` instead of an
actionable message. Per the user's explicit choice, step 12's `SecondaryUsers.cpp` fix
(the exact patch is already specified in plan 12 §4.2) was **not** applied this session.
**Accepted, live risk**: any secondary user under a `require_2fa` policy who signs into a
Joystick-Engine-based game gets a confusing generic error instead of being told to use the
browser-based flow, until step 12 lands separately.

### 6. Members

- **New HMAC-signed proxy** (`SecondaryTwoFactorAdminController`, new routes under the
  existing `checkpoint.verify` group) — the only thing in this app that calls auth's
  service-authenticated endpoints; also re-verifies the target secondary user belongs to
  the calling primary's own roster before proxying `reset`/`policy` (auth's endpoint has no
  way to check that itself). `CheckpointTokenVerifier`/`VerifyCheckpointToken` extended
  (additively) to also expose the caller's own Checkpoint `user_id`, needed as
  `super_user_id`/`set_by_checkpoint_user_id` — existing callers reading only
  `member_reference_num` are unaffected.
- **Admin UI** (`applications.ts` + `app_settings.html`): account-wide and per-user
  require-2FA toggles, a status badge column (`Not required` / `Required — not enrolled`
  / `Enrolled`), and a "Reset 2FA" action whose confirmation copy states plainly what it
  does and doesn't do, matching plan §6.2's exact required wording.
- **Primary's own 2FA management — found already built, not missing as the plan claimed.**
  `settings.html`'s "Two-Factor Authentication" card and `SettingsApp.ts`'s full
  setup/enable/disable/regenerate controller **already existed**, contradicting plan 09
  §6.3's "members has no management UI for it." I initially didn't find this and wrote a
  second, parallel implementation with different modal ids — caught it via a full `tsc`
  type-check that flagged the pre-existing code's own latent bug, backed out my
  duplicate, and instead fixed the two real, pre-existing problems in `SettingsApp.ts`:
  1. **`TwoFactorSetupResponse`/`TwoFactorStatus` in `checkpoint/types.ts` didn't match
     what the PHP endpoints actually return** (`qr_code_uri`/nested `recovery_codes:
     {total,remaining}` declared vs. the real `qr_code_url`/flat
     `recovery_codes_remaining`) — never caught because nothing had exercised
     `setupTwoFactor()` end-to-end before. Corrected both interfaces to match
     `TwoFactorService.php`'s actual response shape.
  2. **A live security bug**: because of finding 1, `setup.qr_code_uri` was always
     `undefined`, so `SettingsApp.ts`'s QR rendering always fell through to a
     third-party image service (`api.qrserver.com`) — shipping the user's TOTP secret
     off-domain in a URL, unconditionally, not as a rare fallback. Replaced with
     client-side rendering via `qrcode` (added to members' `package.json` this session),
     matching plan §6.1's requirement for the hosted login page, which applies here for
     the same reason.

## Files changed

- `packages/checkpoint-auth/src/CheckpointServiceSignature.php`,
  `src/Http/Middleware/VerifyCheckpointServiceAuth.php` — new.
- `packages/checkpoint-auth/src/CheckpointAuthServiceProvider.php`,
  `config/checkpoint.php` — `checkpoint.service` middleware alias, `service_peer_keys` config.
- `packages/checkpoint-auth/tests/CheckpointServiceSignatureTest.php` — new, 5 tests.
- `auth/composer.json`, `members/composer.json` — require `afs/checkpoint-auth`.
- `auth/database/migrations/2026_08_23_0000{01,02,03,04}_*.php` — new.
- `auth/app/Services/Checkpoint/TwoFactorService.php` — rekeyed; `verifyPendingToken()`
  extracted; rate limiting.
- `auth/app/Services/Checkpoint/AuthenticationService.php`,
  `auth/app/Http/Controllers/Checkpoint/AuthController.php` — rekey call-site updates.
- `auth/app/Services/Checkpoint/SecondaryUserService.php` — 3-outcome branch; enrol/verify/
  reset/policy/status methods; enrolment-attempt limiting.
- `auth/app/Http/Controllers/Checkpoint/SecondaryUserController.php`,
  `auth/routes/checkpoint.php` — new endpoints, `checkpoint.service`-guarded group.
- `auth/resources/views/login.blade.php`, `resources/js/login.ts`,
  `resources/js/checkpoint/secondary-users.ts`, `resources/js/i18n/en.json`,
  `auth/package.json` (`qrcode`, `@types/qrcode`) — secondary code-entry + enrolment UI.
- `members/app/Http/Controllers/Checkpoint/SecondaryTwoFactorAdminController.php` — new.
- `members/app/Http/Middleware/VerifyCheckpointToken.php`,
  `members/app/Services/Checkpoint/CheckpointTokenVerifier.php` — expose caller's `user_id`.
- `members/routes/web.php` — new proxy routes.
- `members/resources/js/applications/applications.ts`,
  `resources/js/templates/views/app_settings.html` — admin controls.
- `members/resources/js/controllers/SettingsApp.ts`,
  `resources/js/checkpoint/types.ts` — fixed pre-existing type mismatch + third-party QR
  leak (found during this step, not originally scoped, but directly relevant).
- `members/resources/js/i18n/{en,de,es,fr,zh}.json` — new admin-UI strings.
- `members/package.json` (`qrcode`, `@types/qrcode`).

## Verification steps

- `packages/checkpoint-auth`: `./vendor/bin/phpunit tests/` — 20/20 pass (5 new).
- `php -l` on every new/changed PHP file across all three repos — clean.
- `auth`: `npx vite build` — succeeds; `npx tsc --noEmit` — only the one pre-existing,
  confirmed-dead `_testing/register-secondary.ts` error.
- `members`: `npx vite build` — succeeds. `npx tsc --noEmit --ignoreDeprecations 5.0`
  (the committed tsconfig's `ignoreDeprecations: "6.0"` doesn't run under the installed
  5.9.3 compiler — a pre-existing environment mismatch, not touched) — every remaining
  error, checked individually, is pre-existing and outside this session's changed files
  (`afsConnectSettings.ts` null-safety, `ApplicationContextManager` missing members,
  stale `initializeCheckpointClient()` call-site arity). Zero new errors from this
  session's own files after the `SettingsApp.ts`/`types.ts` fix.
- Read-verified `/checkpoint/secondary/2fa/{status,reset,policy}` are the only three
  routes wrapped in the `checkpoint.service` middleware group (T10's shape, not yet
  runtime-tested against a real secondary user's token — see below).
- **Not done this session** (needs a real database and running stack):
  - Running the four new migrations.
  - The plan's full T1–T14 acceptance table and its testing matrix — in particular T10
    (a secondary user's own token genuinely rejected by `/status`/`/reset`/`/policy`),
    T14 (primary id N / secondary id N independent state), and the full forced-enrolment
    flow end to end.
  - `CHECKPOINT_SERVICE_KEY_MEMBERS` needs to be set to the same value in both auth's and
    members' `.env` — not something to generate or commit from this session.

## Follow-up work

1. Run the four new migrations against a real environment; review the `checkpoint_2fa`
   index-discovery migration's output first (it throws instead of guessing if
   `user_id` turns out to be a bare PRIMARY KEY rather than a separate unique index).
2. Set `CHECKPOINT_SERVICE_KEY_MEMBERS` (matching value) in both auth's and members' env,
   and tag/publish a new version of the `checkpoint-auth` package so
   `composer install`/`composer update` actually resolves the new classes for the five
   existing consumers plus auth and members — not done from this sandbox (no git push,
   no package registry access).
3. Manually run the plan's §8 test table, especially T10 and the full enrolment flow —
   needs a running app and real backend.
4. Step 12 (JoystickEngine_v2) remains not started — the accepted regression (§5 above)
   is live risk until it lands. Still flagged in `TODOS.md`.
5. The `qrcode`/`@types/qrcode` dependency was added independently to both `auth` and
   `members` (they don't share a JS package). If a shared frontend package is ever
   introduced for Checkpoint clients, this is a candidate to de-duplicate.
