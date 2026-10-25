# 11-secondary-user-dashboard — Completion

**Status:** Code complete and build/lint-verified across `auth` and `members`. No live
database or running stack was available in this session, so the full SD1–SD12 acceptance
table needs manual verification — see Follow-up work. All of step 10's amendments (§7) that
this step supersedes are reflected.

## Summary

Reverses part of step 10: secondary users now sign in to members and reach a restricted
launcher surface, instead of being refused outright.

### 1. A real, previously-missing gap found before building anything

Verified members' own native login page (`resources/js/login.ts` +
`resources/views/login.blade.php` — a near-identical sibling of auth's hosted login page,
not a redirect to it) had **no secondary-login attempt at all** — only
`checkpoint.auth.login/loginWithUsername/ssoLogin`. Its SDK copy
(`resources/js/checkpoint/secondary-users.ts`) still had `login()`/`selectAccount()` (never
stripped, so plan 10 §4.2/§7's "reversal" needed no undoing), but nothing in the login page
ever called them, and the SDK copy had none of step 09's 2FA additions either. Without this,
SD1 ("a secondary user signs in to members") would have been impossible regardless of how
complete the shell/launcher backend was. Ported the same secondary-login + 2FA + forced
enrolment flow already built and working on auth's hosted login page (step 09) to members'
own login page — types/methods in `secondary-users.ts`, the login/2FA/enrolment logic in
`login.ts`, and the enrolment modal in `login.blade.php` — plus fixed the identifier
field's label (previously "Email or username", no hint of the `username@identifier`
scoped-login format secondary users need).

### 2. Auth-side: self-service secondary 2FA (plan §6)

The existing `/checkpoint/secondary/2fa/*` endpoints (step 09) only supported the forced
first-login enrolment flow, authenticated by a one-time enrolment token. Plan 11 §6 needs
more: a secondary user re-enrolling a new device or regenerating recovery codes from an
**already-authenticated** session (members `/security`), which needs bearer-token auth
instead. Added `SecondaryUserController::requireSecondaryCaller()` (resolves the caller's
own id via `requireAuth()`, refusing a primary) and four new self-service methods/routes
under `/checkpoint/secondary/2fa/self/*` (`status`, `enrol/begin`, `enrol/confirm`,
`recovery-codes`), backed by new `SecondaryUserService` methods reusing the existing
`TwoFactorService` calls directly (no enrolment-token resolution needed — the caller is
already verified). Deliberately **no self-service disable** — plan §6's table: the primary
owns reset (lost device), the secondary owns their own device, but never gets to turn the
policy off themselves.

### 3. Members: token verification extended, not replaced

`CheckpointTokenVerifier`/`VerifyCheckpointToken` (members' own middleware — confirmed it
does NOT use the shared `checkpoint-auth` package's `checkpoint.member` middleware the
plan's pseudocode names; extending members' own is lower-risk given unrelated in-flight
work already touching this exact area) already called `/checkpoint/auth/token/verify`,
which — verified by reading `AuthenticationService::verifyToken()` directly — already
handled secondary tokens correctly server-side; the members-side verifier just discarded
`user_type`/`user` from the response. Extended (additively) to also expose those, and the
middleware to accept a `primary_only` parameter and set `checkpoint_user_type`/
`checkpoint_user` request attributes.

### 4. Members: two route groups (plan §5.2)

The existing `checkpoint.verify` group (freelance, market, storage, VoIP, afs-connect,
secondary-user admin) is now `checkpoint.verify:primary_only` — every route in it was
already genuinely owner-only data. New launcher group at `/api/v1/me/*`
(`MeController`) — any authenticated identity, returns only the caller's own claims:
`show` (identity + account context), `apps` (from the verified token's own `apps` claim,
never a request param — SD11), and the four 2FA self-service proxies (forwarding the
caller's own bearer token to whichever auth-side endpoint set applies —
`/checkpoint/auth/2fa/*` for a primary, `/checkpoint/secondary/2fa/self/*` for a
secondary). This is 6 endpoints, not the plan's illustrative "three or four" — each is a
distinct, minimal, own-data-only action; the count is larger than the sketch but the two
load-bearing rules (allow-list, own data only) both hold.

### 5. Frontend: identity-branched shell

`views.ts`'s giant `registerViews()` renamed to `registerPrimaryViews()` (byte-identical
body — SD9), with a new thin `registerViews()` wrapper branching on
`app.window.userType`. That field didn't previously exist on `AppWindow` — the plan's own
pseudocode (`app.window.user?.type`) doesn't match this codebase's `AuthState` shape
(`userType` lives on `authState` itself, not nested under `user`) — added the assignment
in `main.ts` and the `AppWindow.userType` type. New `views/secondary.ts` registers exactly
three sub-views (`secondary-main`, `secondary-apps`, `secondary-security`) — no owner
sub-view exists in this identity's routing table at all (SD5). New
`resources/js/checkpoint/app-urls.ts` (CheckpointAppName → URL, `GAME_EDITOR`
deliberately absent per Q13's decided default: an explanatory tile, not a dead link).
Three new templates (`secondary_shell.html`, `secondary_apps.html`,
`secondary_security.html`) — minimal per Q14, header shows the secondary's own name with
the super user's as account context (§5.3), never in the identity slot.

## Files changed

- `auth/app/Services/Checkpoint/SecondaryUserService.php` — 4 self-service 2FA methods.
- `auth/app/Http/Controllers/Checkpoint/SecondaryUserController.php` — `requireSecondaryCaller()` + 4 endpoints.
- `auth/routes/checkpoint.php` — `/checkpoint/secondary/2fa/self/*` group.
- `members/app/Http/Controllers/Checkpoint/MeController.php` — new.
- `members/app/Http/Middleware/VerifyCheckpointToken.php`,
  `members/app/Services/Checkpoint/CheckpointTokenVerifier.php` — `user_type`/`user`
  exposed; `primary_only` mode.
- `members/routes/web.php` — owner group renamed `:primary_only`; new launcher group.
- `members/resources/js/checkpoint/secondary-users.ts` — 2FA types/methods ported from auth's copy.
- `members/resources/js/login.ts`, `resources/views/login.blade.php` — secondary login,
  2FA, and enrolment flow; identifier label fix.
- `members/resources/js/checkpoint/app-urls.ts` — new.
- `members/resources/js/checkpointHelper/appwindow.ts` — `userType` field.
- `members/resources/js/main.ts` — assigns `app.window.userType`.
- `members/resources/js/views.ts` — `registerPrimaryViews()` extraction; branching `registerViews()`.
- `members/resources/js/views/secondary.ts` — new.
- `members/resources/js/templates/views/secondary_{shell,apps,security}.html` — new.
- `members/resources/js/i18n/en.json` — new `secondary.*` and `login.2fa_enrol.*` strings
  (English only — see Follow-up).
- `members/package.json` — `qrcode`/`@types/qrcode` already present from step 09; reused,
  not re-added.

## Verification steps

- `php -l` on every changed/new PHP file (auth + members) — clean.
- `members`: `npx tsc --noEmit --ignoreDeprecations 5.0` — every remaining error checked
  individually; all are pre-existing (same `initializeCheckpointClient()` 0-arg call-site
  pattern and `ApplicationContextManager` typing gaps already present throughout this
  codebase before this session, per steps 09/10's own findings). Zero new errors from this
  session's files. `npx vite build` — succeeds (`login` bundle grew 5→8.7kB, `main` bundle
  reflects the new views/secondary module). `npx vitest run` — 10 files, 55 passed, 1
  pre-existing skip.
- Read-verified `AuthenticationService::verifyToken()` (auth) already normalizes both
  token kinds correctly — confirmed before extending members' verifier rather than
  building a parallel path.
- Read-verified `normalizeAppsPayload()` (step 10) still the only path for granting apps —
  unaffected by this step.
- **Not done this session** (needs a real database, running auth + members stack, and a
  browser): the full SD1–SD12 table. In particular SD1 (a real secondary user reaching
  the shell), SD6/SD7 (an actual secondary token hitting owner routes and getting
  `secondary_not_permitted`, and hitting the three-plus launcher endpoints successfully),
  and the enrolment flow end to end on members' own login page (only auth's copy of this
  flow was exercised in the browser previously, in step 09 — members' port was written by
  mirroring it exactly but not itself run).

## Follow-up work

1. Run the plan's §8 SD1–SD12 acceptance table against a real stack.
2. i18n: `secondary.*` and `login.2fa_enrol.*` strings were added to `en.json` only, not
   the other four locale files (`de`/`es`/`fr`/`zh`) members otherwise maintains — a
   scope-limiting call given this session's overall size, not an oversight. Follow-up:
   translate and add them.
3. Q15 (password yes / email no, self-service from `/security`) — not implemented. It's
   listed under the plan's "Open questions" with a decided default but isn't one of the
   SD1–SD12 acceptance criteria, so it was consciously deferred rather than treated as
   required for this step.
4. `apps()`/`show()` on `MeController` re-fetch from the verified token server-side even
   though `app.window.user`/`app.window.member` (already loaded at page load) contain
   equivalent data — deliberate, favoring a fresh per-request check over trusting
   long-lived in-memory state, but worth knowing this is a small amount of redundant data
   already available client-side.
5. `packages/afsserver/plans/checkpoint-server-auth/10-primary-only-enforcement.md`'s own
   completion doc could be cross-linked from here (and vice versa) given how much this
   step revises — not done, purely organizational.
