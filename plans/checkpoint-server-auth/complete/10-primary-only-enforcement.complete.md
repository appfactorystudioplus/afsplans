# 10-primary-only-enforcement — Completion

**Status:** Code complete for this step's actual (non-superseded, non-deferred) scope.
Most of this plan document is marked superseded by step 11 or explicitly deferred to ship
with it (§4 entirely, layer 3 for members web, layer 4, P6/P7, sequencing steps 10c/10d) —
none of that was implemented here, per the plan's own instructions. What remains — layer 1
(not grantable), layer 2 (not mintable), and registering `APPFACTORY_MOBILE` — is done and
verified. Layer 3 for desktop/mobile (P9) and the registration of `APPFACTORY_MOBILE` in
mobile's own config were already complete from steps 07/08.

## Summary

### 1. `APPFACTORY_MOBILE` registered (§5)

Added across every registry the plan names:

- `auth/app/Services/Checkpoint/CheckpointAppName.php` — new constant, added to `all()`.
- `members/resources/js/checkpoint/app-names.ts` — added to `CHECKPOINT_APPS` + a label;
  confirmed **not** added to `UI_APP_ID_TO_CHECKPOINT` (verified via a new test, below).
- `afsdesktop5/src/renderer/checkpoint/app-names.ts` — mirrored for registry parity; this
  client's own `THIS_APP` identity is unaffected (still `APPFACTORY_STUDIO`).
- `afsmobile_flutter/lib/config.dart` — already done in step 08 (`checkpointAppName =
  'APPFACTORY_MOBILE'`); verified present, not re-added.

### 2. Layer 1 — not grantable (§3.1, P1)

`UI_APP_ID_TO_CHECKPOINT` already excluded `MEMBERS`/`APPFACTORY_STUDIO` before this
session — verified by reading it first. Added the plan's required comment (why each name
is absent, and why the omission is a security control) and a new test file
(`app-names.test.ts`, 4 tests, run via vitest — all pass) asserting all three names'
absence, plus a regression assertion that the six apps secondary users are sold with
(P11) are still exactly what's offered.

### 3. Layer 2 — not mintable (§3.2, P2–P4)

Added `CheckpointAppName::PRIMARY_ONLY_APPS` (`[APPFACTORY_STUDIO, APPFACTORY_MOBILE]`,
`MEMBERS` deliberately excluded per the plan's own reasoning) and `isPrimaryOnly()`.
Enforcement split into two shapes, deliberately different, matching the plan's own §8
rollout guidance:

- **New grants** (`register()`, and `updateUser()`'s app-grant path — both funnel through
  the same `normalizeAppsPayload()`, which already had an identical immediate-reject
  pattern for `MEMBERS`) — reject **immediately** with `SECONDARY_APP_NOT_PERMITTED`.
  There's no existing session to protect here, so there's no reason to delay enforcement
  (P2, P4).
- **Login paths** (`login()`, `selectAccount()`) — a new `guardPrimaryOnlyApp()` that
  **observes (logs) by default** and only rejects when
  `config('checkpoint.primary_only_apps.enforce')` is explicitly turned on
  (`CHECKPOINT_PRIMARY_ONLY_APPS_ENFORCE` env, default `false`). This is the plan's own
  explicit instruction (§8, "10b"): layer 1 says no secondary user should hold a
  Studio/Mobile grant today, but layer 1 is a UI constant, not a hard database
  constraint — rejecting immediately risks silently signing out an existing session over
  data that was never supposed to exist. Flip the env var once the observe-mode logs
  confirm nothing legitimate depends on it (P3).

**Verified, not assumed:** `SecondaryUserController::update()` does not currently pass an
`apps` parameter through to `updateUser()` at all — the only presently-reachable grant
path is `register()`. `normalizeAppsPayload()` is still guarded regardless, so the
service-layer method is safe whenever/if a grants-update API is wired up, but the P4
criterion ("an app-grant update cannot attach...") has no live route to test against
today beyond registration.

### 4. Explicitly not done (superseded or deferred, per the plan's own text)

- §4 (members web client assertion) — superseded by plan 11 §5; members branches to a
  different shell instead of refusing the session.
- Layer 3 for members web (§3.3) — same reason.
- Layer 4, members API `primary_only` route-group split (§3.4) — "ships with step 11, not
  before" (§8, "10d").
- P6/P7 acceptance criteria — superseded by P6′/P7′, which belong to step 11.

## Files changed

- `auth/app/Services/Checkpoint/CheckpointAppName.php` — `APPFACTORY_MOBILE`,
  `PRIMARY_ONLY_APPS`, `isPrimaryOnly()`.
- `auth/app/Services/Checkpoint/SecondaryUserService.php` — `guardPrimaryOnlyApp()`;
  primary-only rejection in `normalizeAppsPayload()`; guard calls in `login()`/
  `selectAccount()`.
- `auth/config/checkpoint.php` — `primary_only_apps.enforce` config.
- `auth/tests/Unit/Checkpoint/CheckpointAppNameTest.php` — new, 5 tests (not run — no
  `vendor/` in this sandbox; syntax-checked via `php -l` only).
- `members/resources/js/checkpoint/app-names.ts` — `APPFACTORY_MOBILE`; layer-1 comment.
- `members/resources/js/checkpoint/app-names.test.ts` — new, 4 tests, run and passing.
- `afsdesktop5/src/renderer/checkpoint/app-names.ts` — `APPFACTORY_MOBILE` mirrored.

## Verification steps

- `php -l` on every changed/new auth PHP file — clean. No `vendor/` directory exists for
  `auth` in this sandbox (never `composer install`ed here), so `CheckpointAppNameTest`
  could not actually be executed — syntax-only verification, same limitation as step 09.
- `members`: `npx vitest run` — 10 test files, 55 passed (1 pre-existing skip), including
  the new 4-test `app-names.test.ts`. `npx tsc --noEmit --ignoreDeprecations 5.0` — no new
  errors introduced (pre-existing errors unrelated to this step's files, same as step 09's
  finding). `npx vite build` — succeeds.
- `afsdesktop5`: `npx tsc --noEmit` — zero errors, exit 0.
- Read-verified both `normalizeAppsPayload()` call sites (`register()`, `updateUser()`)
  funnel through the same guarded function — no third path exists.

## Follow-up work

1. Set `CHECKPOINT_PRIMARY_ONLY_APPS_ENFORCE=true` in auth's env once observe-mode logs
   (from `guardPrimaryOnlyApp()`) have been reviewed and confirm no real secondary user
   currently holds a Studio/Mobile grant — per the plan's own rollout sequencing.
2. Run `CheckpointAppNameTest` for real once `auth` has a usable `vendor/` — not possible
   from this sandbox.
3. Layer 4 (members API primary_only split) ships with step 11 — not started.
4. Noted but out of scope: `auth/app/Services/Checkpoint/CheckpointAppName.php` has no
   `MARKET` constant at all, while both `members` and `afsdesktop5`'s TS registries do.
   This predates this session and isn't something plan 10 asked to fix, but it's a real
   registry drift worth a look separately (adjacent to step 12's own findings about
   registry sync).
