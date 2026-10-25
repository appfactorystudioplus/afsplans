# 02-design — Completion

## Status

**All three mechanisms built and verified end to end against the vagrant lab.** Silent
sign-on (G1, §§1–5), everywhere-logout (G8, §6.2), and logout propagation within one browser
(G10, §7) are all live behind their own flags (`SSO_SILENT_REAUTH_ENABLED`,
`SSO_CROSS_APP_LOGOUT_ENABLED`, `SSO_LOGOUT_PROPAGATION_ENABLED`), enabled in
`local`/`vagrant`, explicitly `false` in `dev`/`prod` per the phased rollout (D4/D8/D10) —
flipping those remains the user's own call. Ordinary logout's scoping (G2) required no code
change beyond clearing the SSO cookie, as the design already established.

This document was updated across four passes: everywhere-logout first, silent sign-on added
once it shipped, a third pass closing the one gap the second pass had explicitly named — a
genuine `HttpOnly` cookie round trip from a real password login, done once the user supplied
real credentials (`james2`) for exactly that purpose — and a fourth pass (G10) triggered by
the user reporting, after the first three passes, that logging out of `members` still left
`coordinator` signed in. All verification in every pass ran against `vagrant`, per
`DEVELOPMENT_MODE.md` — a request mid-session to also verify `local` was not acted on, since
only that file, not conversational wording, decides which environment is live (see the
plan's own conversation history for the correction).

The design doc (`02-design.md`) was corrected to match shipped code, and this completion doc
extended, as part of finishing each pass — not after being asked about it — in every pass
from the second one onward. The first pass only produced them once asked directly.

## Summary

**Everywhere-logout** (first pass): built the missing server half of the client SDK's
already-existing `logoutEverywhere()` call, added the revocation watermark and `token_kind`
claim, gave `members` a UI entry point.

**Silent sign-on** (second pass): built the login-page silent-reauth check, the cookie set
on every primary login path, the cookie cleared on ordinary logout, and — the one piece the
original design got wrong — a *ticket*-minting path for the cookie, not a token-pair one,
because token pairs cannot cross into a different app's origin the way this platform's
existing consuming apps expect a primary login to arrive. Also closed the "cookie re-mint
gap" (§6.2.4) that was designed but explicitly left unbuilt in the first pass, since it only
becomes live once a cookie actually exists to re-mint from.

**Logout propagation within one browser** (fourth pass, G10 — not part of the original
design at all): the user reported that logging out of `members` left an already-open,
already-authenticated `coordinator` tab signed in. This was correct per G2's own scoping
(each app's tokens are independent and ordinary logout was never supposed to reach across
apps) but not what the user actually wanted: within **one browser**, one logout should reach
every app already open in it — just not any other browser or device. Built a new pure
liveness endpoint (`GET /checkpoint/auth/sso/status`) and wired every app's shared
`ClientHandler` to poll it on page load and on regaining focus, self-logging-out on a
confirmed 401. This is the one piece of this plan that touches the shared `checkpoint/`
client SDK (see [05-native-clients-and-sdk-copies.md](../05-native-clients-and-sdk-copies.md)
§9) — copied into all seven web apps, confirmed inapplicable to both native clients.

## Files changed (silent sign-on pass)

**`apps/auth`**
- `app/Services/Checkpoint/AuthenticationService.php`:
  - `login()`, `loginWithUsername()`, `completeTwoFactorAuth()` — each now calls
    `setSsoCookie()` on success (§2.1).
  - `logout()` — now calls `clearSsoCookie()` in both its success and failure branches (§2.3).
  - `getTokensFromSsoCookie()` — refactored to share validation with a new
    `decodeValidSsoCookie(string $domain): ?object` private helper; behavior unchanged.
  - **New** `getSsoTicketFromCookie(string $domain): ?string` — mints a ticket via
    `JwtService::createSsoTicket()`, not a token pair; this is what `ssoSession()` actually
    calls (see divergence below).
  - `decodeValidSsoCookie()` — the everywhere-logout watermark check lives here now, closing
    §6.2.4's gap for both `getTokensFromSsoCookie()` and `getSsoTicketFromCookie()` at once.
- `app/Http/Controllers/Checkpoint/AuthController.php` — new `ssoSession()` method, gated by
  `checkpoint.sso.silent_reauth_enabled`, returning `{sso_ticket}`.
- `routes/checkpoint.php` — `GET /sso/session`.
- `config/checkpoint.php` — `sso.silent_reauth_enabled` key.
- `_env/local/.env`, `_env/vagrant/.env` — `SSO_SILENT_REAUTH_ENABLED=true`, `SSO_COOKIE_DOMAIN`
  set to the correct value for each environment (`.test`, `.appfactory.test1` — closing F4,
  which had been designed in `03-env-variables.md` since the planning pass but never actually
  written into any real `.env` file until now).
- `_env/dev/.env`, `_env/prod/.env` — `SSO_SILENT_REAUTH_ENABLED=false`, `SSO_COOKIE_DOMAIN=.appfactory.studio`.
- `.env` (vagrant's live copy) — synced to match; config cache cleared on the VM after every
  change made during verification.
- `resources/js/login.ts` — new `redirectWithSsoTicket()` helper; the silent-reauth check
  itself, gated on `ssoTargetDomain && ssoRedirectUrl`; both pre-existing inline
  ticket-redirect blocks (manual login, post-2FA) rewritten to call the new helper instead of
  duplicating the URL-building logic.

No file under any of the seven web apps' or two native clients' own trees was touched, per
[05-native-clients-and-sdk-copies.md](../05-native-clients-and-sdk-copies.md)'s prediction —
confirmed again, not just carried over from the earlier pass. (G10, the fourth pass below,
is the first thing in this plan to break that pattern, deliberately and for a documented
reason.)

## Divergence from the §2.2/§3 design sketch (silent sign-on pass) — found during implementation

**The original sketch had the silent-reauth endpoint return a domain-scoped token pair
(`getTokensFromSsoCookie()`'s existing shape) directly to the browser.** This does not work.
Every consuming app's SDK (`ClientHandler.handlePageLoad()`, identical in all seven web-app
copies) only knows how to complete a *primary* login from an `sso_ticket` URL parameter on
the redirect back — the exact shape a manual `ssoLogin()` already produces. A raw
access/refresh pair has no path into a different origin's `localStorage`; a URL parameter is
the only hand-off channel between `auth`'s origin and the target app's. Returning tokens
directly would have meant a silent "success" on the login page immediately followed by the
target app finding no usable parameter and falling back to its own unauthenticated state —
worse than doing nothing, because it would look like it worked until the very next click.

This was found by tracing what `login.ts`'s consuming side (`client-handler.ts`) actually
does with the redirect, not by re-reading the original design. The fix:

1. `AuthenticationService::getTokensFromSsoCookie()`'s validation logic (cookie presence,
   type, domain, everywhere-logout watermark) was extracted into a shared private
   `decodeValidSsoCookie(string $domain): ?object`.
2. A new `getSsoTicketFromCookie(string $domain): ?string` reuses that same validation but
   mints an SSO ticket instead of a token pair.
3. `AuthController::ssoSession()` calls the new ticket method, not the token-pair one.
4. `login.ts`'s silent check treats the response as `{ sso_ticket }` and redirects through
   the exact same `redirectWithSsoTicket()` helper the manual-login and post-2FA paths now
   share — one URL-building code path for every way a ticket can arrive at this page.

`getTokensFromSsoCookie()` itself was left behaviorally unchanged and is not dead code — it
remains a real, independent capability for a same-origin caller that can use tokens
directly; it is simply not what powers the browser-redirect flow. `02-design.md` was
corrected in place (not just recorded here) to show the ticket-based version as the shipped
code, with an inline note on why the token-pair version in the original sketch was wrong.

A second, smaller correction: the sketch read `checkpoint.baseUrl` to build the fetch URL.
At the time, `CheckpointClient` exposed no such public property (only `ssoDomain`, a
different value) — `login.ts` is already served from `auth`'s own origin, so a relative path
(`/checkpoint/auth/sso/session?domain=...`) resolves correctly with no client construction
needed at all. (The G10 pass below adds a `baseUrl` getter to `CheckpointClient` for a
different caller that genuinely needs to build a cross-origin URL; `login.ts`'s own relative
path was left as-is since it never needed one.)

## Verification performed (silent sign-on pass)

All against the vagrant lab, via the actual browser pane (not just `curl`) for the
end-to-end frontend check, since this mechanism is inherently cookie-based and a `curl`
call alone cannot demonstrate that the *browser* — not just the server — accepts and
resends the cookie correctly.

| # | Check | Method | Result |
|---|---|---|---|
| 1 | Valid `checkpoint_sso` cookie + allowed domain → ticket | `curl -b` with a minted `sso_session` token | ✅ `200`, `sso_ticket` present |
| 2 | No cookie → 401 | `curl`, no cookie | ✅ `401`, `AUTH_NO_SSO_SESSION` |
| 3 | Valid cookie + disallowed domain → 400 | `curl -b` against `evil.example.com` | ✅ `400`, `AUTH_SSO_DOMAIN_NOT_ALLOWED` |
| 4 | The minted ticket exchanges into a real token pair, identically to a manual login's ticket | `curl -X POST /sso/exchange` | ✅ `200`, full token pair + member |
| 5 | Everywhere-logout closes the cookie re-mint gap (§6.2.4) | Confirmed a cookie worked, triggered everywhere-logout via a separate token, retried the *same* cookie | ✅ First call `200`; after logout, same cookie → `401` |
| 6 | `SSO_SILENT_REAUTH_ENABLED=false` → 404, then restored | `curl`, flag toggled and reverted on the VM | ✅ `404`, `AUTH_SSO_SILENT_REAUTH_DISABLED` |
| 7 | **Fail-open in a real browser**: fresh tab, no cookie, visits `/login?target_domain=...&redirect_url=...` | Browser pane navigation + console/network inspection | ✅ 401 logged (expected, not a JS exception), credential form rendered normally |
| 8 | **Full success path in a real browser**: same tab, a valid `checkpoint_sso` cookie set (via `document.cookie` — not genuinely `HttpOnly`, but sent identically on same-origin `fetch`), same URL revisited | Browser pane | ✅ Credential form never rendered; browser navigated straight to `coordinator.appfactory.test1`, landing on its authenticated dashboard |
| 9 | Check 8's landing page is genuinely authenticated, not just visually loaded | Network log on the coordinator tab | ✅ `GET /api/pm/workspace → 200` — an authenticated API call succeeded |
| 10 | `auth` frontend build | `npm run build` | ✅ succeeded, `login.ts` compiled into `login-*.js` with no errors |
| 11 | **The genuine `HttpOnly` `Set-Cookie` round trip** — real credentials (`james2`), a real `POST /checkpoint/auth/login/username` | `curl -D -` to inspect the raw header | ✅ `Set-Cookie: checkpoint_sso=...; domain=.appfactory.test1; secure; HttpOnly; SameSite=Lax` — every attribute exactly as configured |
| 12 | That real, `HttpOnly` cookie used for silent reauth via `curl`'s cookie jar | `curl -b` against `/checkpoint/auth/sso/session?domain=coordinator.appfactory.test1` | ✅ `200`, `sso_ticket` present |
| 13 | The full flow in a real browser with real credentials: fresh tab → credential form → typed `james2`/real password → landed on coordinator authenticated | Browser pane, form fill + submit | ✅ Dashboard showed "JA" (James's real identity), distinct from the earlier synthetic-cookie test's "EQ" |
| 14 | Revisiting the login-redirect URL in the *same* browser session, now holding the real cookie | Browser pane navigation | ✅ Credential form never rendered; silent reauth landed back on coordinator, confirmed authenticated via three successful `GET /api/pm/workspace` calls |
| 15 | Ordinary `logout()`'s `clearSsoCookie()` against a real cookie, from within the actual browser (not a separate `curl` context) | `fetch(..., {credentials:'include'})` executed in-tab, then the redirect URL revisited | ✅ Credential form reappeared — the real cookie was genuinely cleared, not just a same-request response header |

Everything previously flagged as "not verified" — a genuine `HttpOnly` cookie from a real
password login, and its full round trip through silent reauth — is now closed, using real
credentials (`james2`) supplied by the user specifically for this purpose.

Temporary verification scripts (`_tmp_mint_token.php`, `_tmp_mint_sso_cookie.php`) and the
test rows they produced were deleted after use; the disabled-flag toggle was reverted; the
real session opened for checks 13–15 was logged out afterward so no lingering authenticated
state was left in the browser pane.

## Files changed (G10 pass — logout propagation within one browser)

**`apps/auth`**
- `app/Services/Checkpoint/AuthenticationService.php` — new `hasValidSsoSession(string $domain): bool`,
  a one-line wrapper around the already-shared `decodeValidSsoCookie()`.
- `app/Http/Controllers/Checkpoint/AuthController.php` — new `ssoStatus()`, gated by
  `checkpoint.sso.logout_propagation_enabled`, returning `{active: true}` or a 401
  `AUTH_NO_SSO_SESSION` — mints nothing, unlike `ssoSession()`.
- `routes/checkpoint.php` — `GET /sso/status`.
- `config/checkpoint.php` — `sso.logout_propagation_enabled` key.
- `_env/local/.env`, `_env/vagrant/.env`, `.env` — `SSO_LOGOUT_PROPAGATION_ENABLED=true`.
- `_env/dev/.env`, `_env/prod/.env` — `SSO_LOGOUT_PROPAGATION_ENABLED=false`.
- `resources/js/checkpoint/index.ts` — new public `baseUrl` getter on `CheckpointClient`,
  mirroring the existing `ssoDomain` getter.
- `resources/js/checkpoint/client-handler.ts` — `handlePageLoad()` now starts a watch
  (`startLogoutWatch()`) when it resolves to an authenticated primary user; new
  `checkSsoLiveness()` polls `/checkpoint/auth/sso/status` and, on a confirmed 401, calls the
  existing `logout()` and redirects via the existing `buildLoginUrl('normal')` — no new
  local-storage-clearing logic, reusing what already existed.
- `resources/js/login.ts` — one stale comment corrected (it claimed `CheckpointClient` had
  no public `baseUrl`, which was true until this pass added one); no behavior change.

**`apps/members`, `apps/coordinator`, `apps/dream_graph`, `apps/flow`, `apps/notes`,
`apps/pdf_editor`, `apps/market`** — `resources/js/checkpoint/index.ts` and
`client-handler.ts` updated to match `auth`'s canonical copy exactly (confirmed
byte-identical modulo each app's own pre-existing CRLF line endings). No other file in any
of the seven changed for this pass.

**`apps/members`** additionally — `resources/js/views.ts`, `resources/js/views/secondary.ts`,
`resources/js/controllers/SettingsApp.ts`: replaced three separate hand-rolled
`window.location.hostname.includes('members.') ? ... : ...` guesses for the post-logout
redirect domain (found while diagnosing the user's report — all three resolved to the stale
`auth15.appfactory.studio`, wrong for the vagrant lab's `.appfactory.test1` domains) with the
already-existing, environment-driven `checkpointObjects.baseUrl`. This is a bug fix
independent of G10 itself, found in the course of investigating the same report.

## Divergence (G10 pass) — found only by testing, not anticipated by any earlier pass of this design

**None of §§1–6 anticipated G10 at all.** The original design's logout split (§6.1/§6.2)
correctly scoped ordinary logout to "the session it was called from," and every acceptance
criterion up through A18 was written and verified against that scope. What none of the
earlier passes tested was an *already-open, already-authenticated tab of a different app* —
every verification of ordinary logout's isolation (A3, A10) checked that a *future* visit to
another app was correctly affected (silent-reauth lost) or unaffected (a different
browser/device), never an *already-loaded* tab that makes no further request on its own.
`coordinator`'s own tokens are independent of `members`' and stay valid by construction
(§4) — nothing in §§1–6 was wrong, the scope itself was just narrower than what "log out"
means to a user with two tabs open. This was found by the user actually using two apps at
once after everywhere-logout and silent sign-on had both already shipped and been verified —
not by re-reading the design.

## Verification performed (G10 pass)

All against the vagrant lab, via the actual browser pane with two real tabs — a single tab
cannot demonstrate propagation *between* apps.

| # | Check | Method | Result |
|---|---|---|---|
| 16 | A freshly-loaded, already-authenticated `coordinator` tab, SSO session still valid | Browser pane, page load | ✅ No redirect — the liveness check found `active: true` and did nothing (no false positive) |
| 17 | Log out of `members` (real credentials, `james2`), then reload the still-open `coordinator` tab | Browser pane, two tabs | ✅ `coordinator` redirected to `auth.appfactory.test1`'s login page — the credential form, not the dashboard |
| 18 | `GET /checkpoint/auth/sso/status` with the cookie already cleared | Implicit in check 17 (the actual request `checkSsoLiveness()` made) | ✅ 401 `AUTH_NO_SSO_SESSION`, matching `ssoSession()`'s existing error code for consistency |
| 19 | `members`/`coordinator` frontend builds | `npm run build`, both apps | ✅ succeeded, no errors, `client-handler.ts`'s new code compiled into both bundles |
| 20 | The `members` post-logout-redirect bug (`auth15.appfactory.studio`) found while investigating this report | Browser pane, real login/logout, all three fixed call sites | ✅ Logout now lands on `auth.appfactory.test1`, confirmed for the ordinary "Sign out" path live |

**Not separately re-verified via `curl`** for this pass — the mechanism is inherently
cross-tab and cookie-based, so the two-tab browser test above is the meaningful check; a
single `curl` call against `/sso/status` would only re-confirm what checks 1–3 (the
`sso/session` pass) already established about `decodeValidSsoCookie()`'s own correctness,
which `hasValidSsoSession()` reuses unchanged.

**The `visibilitychange`-triggered check (switching back to an already-open tab without
reloading it) was not independently exercised live** — the browser-pane tool used for
verification fronts tabs without generating a real page-visibility transition the way an
actual OS-level tab switch does, so `document.visibilityState` never changed in either tab
during testing. The reload-triggered check (17/18 above) exercises the same
`checkSsoLiveness()` code path the visibility listener calls, so the logic that matters
(the fetch, the 401 handling, the redirect) is verified; only the specific DOM event wiring
that also calls it on focus is unverified by a live test. Flagged, not glossed over.

## Follow-up work

- **`dev`/`prod` rollout** for all three flags remains the user's own call, per D4/D8/D10 —
  all three stay `false` in both environments.
- **The `local` environment itself** has still never been exercised — only `vagrant`, across
  all four passes. Every SSO flag is set correctly in `_env/local/.env`, but nothing has been
  run against it, and per `DEVELOPMENT_MODE.md` currently saying `vagrant`, nothing should be
  until that file itself changes.
- **The `visibilitychange` live-tab-switch path** (G10) should be verified with a real
  browser window/OS-level tab switch when one is available, not just this tool's tab
  fronting — see the G10 verification notes above.
- **Pre-existing, unrelated uncommitted changes**, flagged in the first completion pass,
  were not touched again this session and remain exactly as they were.
- **The transitional `token_kind` window** (D9's own note, unaffected by later passes) still
  self-resolves within `jwt.refresh_expiry` of the everywhere-logout pass having shipped.

## Process note

The design-doc correction and this completion write-up happened as part of finishing the
work, not after being asked whether they had, in every pass from the second onward. The
first pass only produced them once the user asked directly. The G10 pass additionally wrote
the plan additions themselves (G10, D10, §7's design, new acceptance criteria) before
implementation started, rather than only documenting after the fact — the investigation
(confirming the propagation gap live, tracing the exact hardcoded-domain bug it surfaced in
`members`) came first, then the design was written into `00-overview.md`/`02-design.md`, then
the code, matching the plan-first convention this whole plan folder is meant to model.
