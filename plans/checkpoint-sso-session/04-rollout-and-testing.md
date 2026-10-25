# 04 — Rollout and testing

**Status**: both mechanisms' core Phase 1–2 checks (backend plumbing, `local`/`vagrant`
enablement — env vars set in both, but only `vagrant` actually exercised) have been
executed against `vagrant`, including a real password login (`james2`) confirming the
genuine `HttpOnly`/`Secure`/`SameSite=Lax`/`Domain` `Set-Cookie` round trip end to end — see
[complete/02-design.complete.md](complete/02-design.complete.md) for what was actually run
and the real corrections found only by running it, across three passes. **Still open**: the
`local` environment itself (never exercised, only configured — per `DEVELOPMENT_MODE.md`
currently saying `vagrant`, that stays true until the user changes the file, not just asks
for "local" in conversation); Phase 2's "second-browser" check for everywhere-logout; and
all of Phase 4 (`dev`/`prod`), which stays the user's own call per D4/D8.

## 1. Phases

### Phase 1 — backend plumbing, `local`, both flags off

Land the changes from [02-design.md](02-design.md) §2 (cookie set on login, `ssoSession()`
route, cookie cleared on ordinary logout) and §6 (the `checkpoint_primary_logout_watermarks`
migration, `token_kind` claim, revocation checks in
`verifyToken()`/`refreshToken()`/`getTokensFromSsoCookie()`, the new
`POST /checkpoint/auth/logout/all` route and controller method, the watermark write in
`logoutEverywhere()` **only**) with both `SSO_SILENT_REAUTH_ENABLED=false` and
`SSO_CROSS_APP_LOGOUT_ENABLED=false` in `_env/local/.env`. Run the new migration
(`php artisan migrate --database=checkpoint`, or whichever artisan invocation targets the
`checkpoint` connection in this app). At this point every new endpoint/check is inert and no
user-visible behavior has changed — this phase is purely "does the plumbing work in
isolation."

Verify with `php artisan tinker` against the `local` environment (per `DEVELOPMENT_MODE.md`,
this machine's own services are genuinely reachable):

```php
$auth = app(\App\Services\Checkpoint\CheckpointService::class)->auth();

// Silent sign-on plumbing:
// after a real login() call has run and returned success:
$auth->validateSsoCookie(); // should now return the session, not null
$auth->getTokensFromSsoCookie('auth.test'); // should mint a token pair

// Ordinary-logout isolation — the thing this plan must NOT break:
$pair = $auth->login('someone@example.com', 'password');
$auth->verifyToken($pair['access_token']); // succeeds
$auth->logout();
$auth->verifyToken($pair['access_token']); // must STILL succeed — ordinary logout never revokes

// Everywhere-logout plumbing — mint a pair, confirm it verifies, invoke logoutEverywhere(), confirm it no longer does:
$pair2 = $auth->login('someone@example.com', 'password');
$auth->verifyToken($pair2['access_token']); // succeeds
$auth->logoutEverywhere();
$auth->verifyToken($pair2['access_token']); // must now throw AUTH_TOKEN_REVOKED
$auth->validateSsoCookie(); // should now return null — the current browser's cookie is cleared too

// The cookie re-mint gap (§6.2.4) — confirm it's actually closed, not just designed:
// mint an SSO cookie, call logoutEverywhere() from a DIFFERENT session, then:
$auth->getTokensFromSsoCookie('auth.test'); // must return null, not a freshly-minted pair

// Secondary-user isolation — confirm the watermark never touches a secondary token:
// (using a real secondary login/token pair for an unrelated secondary account)
$auth->logoutEverywhere(); // primary everywhere-logout again
$secondaryResult['access_token']; // must still verify successfully via SecondaryUserService — unaffected
```

### Phase 2 — enable in `local`, verify both actions and their boundary

Flip both `SSO_SILENT_REAUTH_ENABLED=true` and `SSO_CROSS_APP_LOGOUT_ENABLED=true` in
`_env/local/.env`. Using Laragon (per `DEVELOPMENT_MODE.md`'s `local` mode):

1. Sign in to `members.test`, then separately open `coordinator.test` in the same browser
   profile and confirm the credential form never appears (silent sign-on, G1).
2. **Isolation test (G2, the specific thing the user flagged as a mistake in an earlier
   draft)**: with both apps still showing as signed in, log out of `members.test` using its
   ordinary "Sign out" button. Confirm `coordinator.test` is **completely unaffected** —
   reload it and confirm it is still signed in, and confirm no `AUTH_TOKEN_REVOKED` appears
   in its network tab. Only `members.test` itself, and that one browser's SSO cookie
   (confirmed by visiting a third app and seeing the form reappear), should be affected.
3. **Everywhere-logout test (G8)**: sign back in to `members.test` and `coordinator.test`.
   From `members.test`'s account settings (or wherever the entry point lands, per
   [02-design.md](02-design.md) §6.3), trigger the new "Log out of all devices" action.
   Confirm `coordinator.test` is now rejected on its next request/reload — inspect the
   network tab for the `AUTH_TOKEN_REVOKED` response specifically, not just the eventual
   redirect to login, so a false pass (e.g. it only redirected because of an unrelated
   error) can't be mistaken for the real mechanism working.
4. Separately, sign in a **secondary** user to `members.test` and to `coordinator.test`
   with two different sets of secondary credentials. Trigger everywhere-logout for the
   *primary* per step 3. Confirm both secondary sessions are completely unaffected (G6/A11)
   — this is the check most likely to be skipped because it requires a secondary account to
   already exist for testing.

### Phase 3 — `vagrant`, the environment most likely to reveal a domain bug

This is the environment where F4 (`SSO_COOKIE_DOMAIN` defaulting to a value that doesn't
match `auth.appfactory.test1`) would have silently broken everything before this plan fixed
it — so it is the one phase where confirming the fix *actually took effect*, not just that
the code shipped, matters most. Per `AGENTS.md` §6, `vagrant` is genuinely reachable end to
end through the lab's own DNS/nginx/Apache; use it, do not infer from `local` passing.

1. `packages\afsserver\bin\use-env.ps1 vagrant` (or confirm it's already active) in `auth`.
2. Sign in to any one app via `https://<app>.appfactory.test1`.
3. **Open the browser's own dev tools and inspect the cookie jar for
   `auth.appfactory.test1`** — confirm `checkpoint_sso` is actually present, with `Domain`
   matching `_env/vagrant/.env`'s `SSO_COOKIE_DOMAIN`. This is acceptance A5, and it is the
   one check that catches "the server thinks it set a cookie but the browser silently
   dropped it" — a server-side log or `tinker` session cannot see that failure mode, only
   the browser can.
4. Repeat all four checks from Phase 2 (silent sign-on, ordinary-logout isolation,
   everywhere-logout, secondary-user isolation) against the lab's domains.
5. **Second-browser check, specific to `vagrant`'s genuinely-reachable posture**: sign in to
   an app in one browser (e.g. Chrome) and the same primary account in a second, distinct
   browser (e.g. Edge/Firefox), so there are two separate `checkpoint_sso` cookies in play.
   Trigger everywhere-logout from the first browser. Confirm the *second* browser's next
   silent-reauth attempt is refused (§6.2.4's cookie re-mint gap) rather than silently
   minting a fresh token — this is the one check that cannot be simulated from a single
   browser's dev tools or from `tinker` alone.

### Phase 4 — `dev`, then `prod`

Config-only flips (both flags, in the same order as Phase 2/3) once Phases 2–3 have run
clean. Per `DEVELOPMENT_MODE.md`'s `remote` posture note (these are not this machine's own
services), this phase's actual execution and verification is the user's call, not something
to run from here — say what would need verifying (the same checks as Phase 2/3) rather than
assuming it passed.

## 2. Test matrix

Run against at least the pair in each row, in whichever environment is under test.

### Silent sign-on (G1)

| First app signed in to | Second app visited (same browser) | Expected |
|---|---|---|
| `members` | `coordinator` | No form; lands authenticated |
| `coordinator` | `notes` | No form; lands authenticated |
| `notes` | `flow` | No form; lands authenticated |
| `flow` | `dream_graph` | No form; lands authenticated |
| `dream_graph` | `pdf_editor` | No form; lands authenticated |
| `pdf_editor` | `market` | No form; lands authenticated (market's weaker `checkpoint.verify` middleware does not change this — the login-page check is upstream of it) |
| *(any of the above, before logout)* | secondary-user login to any app | Unaffected — behaves exactly as before this plan (A8) |
| *(any of the above)* | wait past `SSO_COOKIE_LIFETIME`, then visit a second app | Form reappears — silent session actually expires |

**`www` has no row here** — it is not one of the seven apps this mechanism applies to (G9).

### Ordinary logout — isolation (G2, the corrected behavior)

| Signed in on | Log out on | Expected on every *other* session |
|---|---|---|
| Browser, two apps (e.g. `members` + `coordinator`) | `members`, ordinary "Sign out" | With `SSO_LOGOUT_PROPAGATION_ENABLED` on (G10, below): `coordinator`'s already-open tab clears its own session and redirects to login on its next load/focus check. With it off (or before G10 shipped): `coordinator` **unaffected** until its own token naturally expires — that same browser's *other, not-yet-visited* apps lose silent-reauth (cookie cleared) either way. |
| A browser and `afsdesktop5`, same primary user | `afsdesktop5`'s ordinary "Sign out" | The browser session **unaffected** — this is the exact scenario the user named |
| A browser and `afsmobile_flutter`, same primary user | The browser's ordinary "Sign out" | `afsmobile_flutter`'s session **unaffected** |
| Two different browsers, same primary user | One browser's ordinary "Sign out" | The other browser **unaffected**, including its own silent-reauth capability (its own cookie is untouched) |

### Everywhere logout (G8) — the new feature

| Signed in on | Trigger everywhere-logout from | Expected everywhere else |
|---|---|---|
| Browser (2+ apps) + `afsdesktop5` + a second browser | Any one of them | Every other one rejected on its next request/reload/silent-reauth attempt (A16) |
| *(any of the above)* | — | Propagation is bounded by the existing ≤60s positive-verification cache for an active session, or "next request" for an idle one — not instant (A17) |
| Primary + secondary both signed in to the same app | Primary triggers everywhere-logout | Secondary session on that app, and on every other app, completely unaffected (G6/A11) |
| Secondary user, signed in to two apps | Secondary attempts to call the everywhere-logout endpoint | Refused with `AUTH_SECONDARY_NOT_PERMITTED` (§6.2.2) — the capability does not exist for a secondary identity, not merely "does nothing" |
| *(any of the above)* | — | Refresh-token path specifically: let an access token expire, force a refresh, after everywhere-logout — refresh is rejected the same as verification would be |

**`afsdesktop5` and `afsmobile_flutter` rows are detailed in
[05-native-clients-and-sdk-copies.md](05-native-clients-and-sdk-copies.md) §7**, not
duplicated here — same phases, same flags, just exercised from the native client instead of
a browser tab.

### Logout propagation within one browser (G10) — found by testing, added after the first two

| Signed in on | Action | Expected |
|---|---|---|
| Browser, two apps (e.g. `members` + `coordinator`), both authenticated | `members`, ordinary "Sign out" | `coordinator`'s tab clears its own local session and lands on login the next time it's reloaded or regains focus — verified live (A19) |
| Browser, one app, still validly authenticated | *(no logout — just reload, or switch away and back)* | Stays authenticated; the liveness check returns `active: true` and nothing happens (no false positive) |
| `SSO_LOGOUT_PROPAGATION_ENABLED=false` | `members`, ordinary "Sign out" | `coordinator` behaves exactly as it did before G10 — unaffected until its own token expires (A20) |
| `/checkpoint/auth/sso/status` returns a network error or a 5xx | *(any app polling it)* | Nothing happens — never treated as "logged out" (A20's fail-closed requirement) |
| A secondary user, signed in to one app | *(secondary logout on any app)* | No poll runs at all — `startLogoutWatch()` only starts for `userType === 'normal'` (G6) |

**`afsdesktop5` and `afsmobile_flutter` are not exercised here** — confirmed structurally
inapplicable in [05-native-clients-and-sdk-copies.md](05-native-clients-and-sdk-copies.md) §9,
not merely untested.

## 3. Rollback

Set the relevant flag(s) back to `false` in the affected environment's `_env/<mode>/.env`
and re-run `use-env`. All three capabilities roll back independently:

- **`SSO_SILENT_REAUTH_ENABLED=false`**: config-only, instantaneous. The `checkpoint_sso`
  cookie left in users' browsers becomes inert (the endpoint 404s regardless of cookie
  state, per [02-design.md](02-design.md) §2.2) — it does not need to be actively cleared.
- **`SSO_CROSS_APP_LOGOUT_ENABLED=false`**: config-only, instantaneous. The
  `/logout/all` route 404s again (matching the client's pre-existing, previously-unreachable
  call), and existing rows in `checkpoint_primary_logout_watermarks` become inert reads (the
  check is skipped entirely when the flag is off) — no need to truncate the table.
  **Ordinary `logout()` was never gated by this flag and is never affected by toggling it.**
- **`SSO_LOGOUT_PROPAGATION_ENABLED=false`**: config-only, instantaneous. `/sso/status`
  404s; every app's `checkSsoLiveness()` already treats a 404 identically to "still logged
  in" (fail-closed, §7.2), so the poll becomes a harmless no-op network call rather than
  something that needs removing. Toggling this flag back on later needs no further action —
  the client-side code is already deployed and waiting.
- None of the three rollbacks requires a schema reversal; the migration from §6.2.4 can stay
  applied either way.
- Silent sign-on and both kinds of logout need no change to roll back in any downstream app
  (`members`, `coordinator`, `dream_graph`, `flow`, `notes`, `pdf_editor`, `market`,
  `afsdesktop5`, `afsmobile_flutter`), since none of them were changed for those two
  mechanisms (G4, [05](05-native-clients-and-sdk-copies.md)). **G10 is the exception**: the
  seven web apps did receive new SDK code (§7.2's `startLogoutWatch()`/`checkSsoLiveness()`),
  but rolling it back needs no redeploy either — the flag alone makes it inert, per above.
  `afsdesktop5`/`afsmobile_flutter` needed no change for G10 to begin with ([05](05-native-clients-and-sdk-copies.md) §9).
  `www` was never touched by any of the three (G9).

## 4. Open follow-ups (explicitly not resolved by this plan)

- **SSO session lifetime vs. ticket lifetime.** The cookie defaults to 30 days
  (`SSO_COOKIE_LIFETIME`); a ticket lives 60 seconds. A stolen cookie is a materially longer
  window than a stolen ticket. Whether to shorten it, add idle-timeout behavior, or leave it
  is a security/product call for the user, not a default to change while restoring the
  plumbing.
- **Revocation on a password change with no explicit logout.** Whether changing a password
  *without* triggering everywhere-logout should also write the revocation watermark (so a
  compromised-password scenario can't be waited out) is a separate, unresolved question
  worth raising with the user before Phase 4 — the mechanism (§6.2.4's table) would support
  it directly if wanted, but it is a distinct product decision from what this plan builds.
- **Where the "log out of everywhere" UI lives.** [02-design.md](02-design.md) §6.3
  recommends `members` as the natural home and leaves surfacing it elsewhere (other web
  apps, `afsdesktop5`, `afsmobile_flutter`) as a future enhancement using the same
  already-existing SDK call — not required for this plan.
- **`SSO_MODE` / `getSsoMode()` cleanup** (F6). Dead configuration, harmless, left alone —
  candidate for a separate, unrelated cleanup pass.
- **Transitional refresh-token ambiguity window** (§6.2.5's note in
  [02-design.md](02-design.md)): tokens minted before this ships have no `token_kind` claim
  and are treated as `'normal'`. Self-resolves within `jwt.refresh_expiry` of shipping; not
  actively remediated, just bounded and disclosed.
