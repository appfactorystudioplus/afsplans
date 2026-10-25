# 01 — Current state audit

Every claim below was verified by reading the file at the given path/line on 2026-09-18, not
inferred from naming or documentation.

## 1. Today's login flow, end to end

1. A downstream app (e.g. coordinator, [`resources/js/main.ts:20-32`](../../../../w/coordinator/resources/js/main.ts))
   calls `handler.handlePageLoad()` on every page load. If the result is not authenticated,
   it immediately does `window.location.href = handler.buildLoginUrl('normal', CHECKPOINT_APPS.COORDINATOR)`
   — a full-page redirect, with **no button click required**. This matters for this plan: the
   downstream apps already behave exactly as a silent-SSO consumer should. Nothing about
   them needs to change.
2. [`ClientHandler.buildLoginUrl()`](../../../../w/coordinator/resources/js/checkpoint/client-handler.ts:314-335)
   builds `https://auth.<env-domain>/login?target_domain=<host>&redirect_url=<origin>&app_name=<app>`.
3. `auth/routes/web.php:10` serves the login blade unconditionally:
   ```php
   Route::get('/login', function () {
       return view('login');
   });
   ```
   No auth, no session check, nothing — it is a plain view.
4. [`auth/resources/js/login.ts`](../../../../w/auth/resources/js/login.ts)'s `DOMContentLoaded`
   handler (starting line 126) parses URL params, then goes straight to attaching the login
   form's `submit` listener (line 251). There is no branch anywhere in this file that asks
   "is this browser already signed in to Checkpoint" before the form is shown.
5. The user must type credentials. Only on submit does `ssoLogin()` run
   ([`AuthenticationService.php:230`](../../../../w/auth/app/Services/Checkpoint/AuthenticationService.php)),
   which performs the real password check and, as a side effect of success, mints an SSO
   **ticket** (not a session).
6. The ticket round-trips back to the app ([`login.ts:404-411`](../../../../w/auth/resources/js/login.ts))
   and is exchanged for a token pair via `ssoExchangeToken()`, stored in that app's own
   `localStorage`.

**The credential form in step 4 is unconditional.** That is the entire root cause: there is
no step between "app redirects here" and "show the form" that could possibly notice a
recent, still-valid login for a different app.

## 2. The cookie-mode plumbing exists, and is fully unused

`AuthenticationService.php` implements a complete, independent second mechanism —
**cookie-based SSO** — that was clearly built for exactly this problem and never finished
being wired up:

| Method | Location | What it does |
|---|---|---|
| `setSsoCookie(int $userId, array $userData)` | `AuthenticationService.php:651` | Mints a long-lived `sso_session`-type JWT via `JwtService::createSsoSessionToken()` and sets it as an `HttpOnly` cookie (`checkpoint_sso` by default). |
| `validateSsoCookie()` | `AuthenticationService.php:679` | Reads that cookie, validates the JWT, returns `{user_id, user, expires_at}` or `null`. |
| `getTokensFromSsoCookie(string $domain)` | `AuthenticationService.php:616` | The piece a real silent-reauth endpoint needs: validates the cookie **and** mints a domain-scoped access/refresh token pair from it, after checking `isDomainAllowed($domain)` — the same allow-list check the ticket path already uses. |
| `clearSsoCookie()` | `AuthenticationService.php:709` | Expires the cookie. |
| `getSsoMode()` | `AuthenticationService.php:729` | Returns `config('checkpoint.sso.mode')` — `'ticket'` or `'cookie'`. |
| `JwtService::createSsoSessionToken()` | `JwtService.php:194` | The token-minting half of `setSsoCookie()`. Already implemented, already correct. |

**Every one of these is called from exactly one place in the entire estate**:
`auth/public/checkpoint/_old/index.php` (and its near-duplicate `_old/_index.php`) — a
pre-Laravel-controller, raw-PHP router that the live app no longer serves requests through.
Confirmed by grep: `setSsoCookie|getSsoMode` across `auth/**/*.php` matches only
`AuthenticationService.php` (the definitions) and the two `_old/*index.php` files (the only
callers).

The dead router's SSO surface, for reference — this is effectively the missing half of
today's live `routes/checkpoint.php`:

```php
// _old/index.php:188-228 (dead, not routed by the live app)
GET  /auth/sso/session       -> getTokensFromSsoCookie($domain)   // <-- the endpoint this plan needs
POST /auth/sso/cookie/set    -> setSsoCookie($user_id, $user)
POST /auth/sso/cookie/clear  -> clearSsoCookie()
GET  /auth/sso/validate      -> validateSsoCookie()
GET  /auth/sso/mode          -> getSsoMode(), isSsoEnabled()
```

None of these five routes exist in the live `auth/routes/checkpoint.php`.

## 3. One live endpoint already does *part* of the right thing

`AuthController::ssoInit()` ([`AuthController.php:296-311`](../../../../w/auth/app/Http/Controllers/Checkpoint/AuthController.php)) is
routed live at `GET|POST /checkpoint/auth/sso/init` (`routes/checkpoint.php:94-95`) and its
body is:

```php
public function ssoInit(Request $request): JsonResponse
{
    $result = $this->checkpoint->auth()->validateSsoCookie();
    $result = $result ?? ['authenticated' => false, 'callback_url' => $request->input('callback_url')];
    return response()->json(['success' => true, 'data' => $result]);
}
```

So the *validation* half of cookie SSO is already reachable over HTTP, right now, in every
environment. Two things stop it from mattering:

1. **Nothing calls it.** `login.ts` never fetches `/checkpoint/auth/sso/init` on page load.
2. **Nothing sets the cookie it validates.** Since `setSsoCookie()` has no live caller
   (§2), `validateSsoCookie()` inside `ssoInit()` will always find no cookie and always
   return the "not authenticated" branch — even if this endpoint were called.
3. It also isn't quite the right shape for this plan's use: it returns raw session info,
   not a domain-scoped token pair. `getTokensFromSsoCookie($domain)` is the method that
   returns what a client actually needs (§2), and it has no route at all.

## 4. `SSO_COOKIE_DOMAIN` is unset in all four environments

`config/checkpoint.php:187`:

```php
'cookie_domain' => env('SSO_COOKIE_DOMAIN', '.appfactory.studio'),
```

Grepped `SSO_COOKIE_DOMAIN` across all four of `auth/_env/{local,vagrant,dev,prod}/.env` —
**zero matches in any of them.** So `config('checkpoint.sso.cookie_domain')` is always
`.appfactory.studio`, regardless of environment. Each environment's actual `auth` host:

| Environment | `auth` `APP_URL` host | Does `.appfactory.studio` match it or a parent of it? |
|---|---|---|
| `local` | `auth.test` | **No.** |
| `vagrant` | `auth.appfactory.test1` | **No.** |
| `dev` | `auth15.appfactory.studio` | Yes (parent domain). |
| `prod` | *(auth.appfactory.studio, per `checkpoint-server-auth`'s glossary)* | Yes (exact/parent). |

A cookie's `Domain` attribute must be the current host or a **superdomain** of it; if it is
neither, the browser drops the `Set-Cookie` header entirely — silently, no error visible to
the server. So even fixing §1–§3 today would produce a fix that *appears* to work (because
whoever tests it first happens to test against `dev` or `prod`-shaped domains) and silently
does nothing in `local` or `vagrant` — the two environments `DEVELOPMENT_MODE.md` says are
supposed to be verified end to end on this machine. Detail and the corrected per-environment
values are in [03-env-variables.md](03-env-variables.md).

## 5. `logout()` does not clear the cookie

`AuthenticationService::logout()` ([`AuthenticationService.php:451-459`](../../../../w/auth/app/Services/Checkpoint/AuthenticationService.php)):

```php
public function logout(): array
{
    try {
        $this->auth->logOut();
        return ['message' => 'Successfully logged out'];
    } catch (\Exception $e) {
        return ['message' => 'Logged out'];
    }
}
```

No call to `clearSsoCookie()`. This is not a problem today only because nothing sets the
cookie in the first place (§2). It becomes a real bug the moment §1–§4 are fixed without
also fixing this: a user who "logs out" of coordinator, **in that same browser**, would
still have a live SSO session cookie, and the very next app they open **in that browser**
would silently sign them back in — which looks to the user exactly like logout not working.
This is deliberately scoped to "that browser": clearing a browser-scoped cookie cannot,
and must not, reach a different browser or a native client's own separate token — see
[00-overview.md](00-overview.md) §1.3 for why an earlier draft of this plan conflated the
two.

## 6. `SSO_MODE` is dead configuration, adjacent but separate

`config/checkpoint.php:133`: `'mode' => env('SSO_MODE', 'ticket')`, read only by
`getSsoMode()` (§2 table), which is itself only called from the same dead `_old` router. All
four environments set `SSO_MODE=ticket` explicitly in their `_env/<mode>/.env` — a value
that currently has zero effect on running code. Noted for completeness in
[00-overview.md](00-overview.md) F6; left alone by this plan (D1: cookie is a *check in
front of* ticket delivery, not a mode switch).

## 7. Not relevant to this gap, but adjacent enough to name

`resources/js/checkpoint/browser-auth.ts:200` builds a URL against
`/checkpoint/auth/sso/init` for a PKCE-based native-app deep-link flow (desktop/mobile
"open system browser" login). It hits the same route as §3 for an unrelated purpose (an
authorization-code-style exchange for native clients) and is untouched by this plan — it
does not read or write `checkpoint_sso`, and nothing here changes its behavior.

## 8. There is no token revocation mechanism today

`JwtService::validateToken()` ([`JwtService.php:101-147`](../../../../w/auth/app/Services/Checkpoint/JwtService.php))
is a pure signature-and-expiry check: `JWT::decode()` against the configured secret, an
expiry check baked into the library, and an optional `type` claim match. **No database
lookup, no session-id check, no blacklist.** `AuthenticationService::verifyToken()`
([line 371](../../../../w/auth/app/Services/Checkpoint/AuthenticationService.php)) and
`refreshToken()` ([line 423](../../../../w/auth/app/Services/Checkpoint/AuthenticationService.php))
call it and otherwise trust the result completely.

`logout()` ([line 451](../../../../w/auth/app/Services/Checkpoint/AuthenticationService.php))
and `logoutEverywhere()` ([line 467](../../../../w/auth/app/Services/Checkpoint/AuthenticationService.php))
only call into Delight\Auth's own `logOut()`/`logOutEverywhere()`, which manage Delight's
session/remember-me state for the *login* step — they have no relationship to an
already-issued Checkpoint access or refresh JWT, because nothing about verifying that JWT
ever looks Delight's session state up again.

**Consequence, confirmed by reading the code rather than by testing it live**: a primary
user who has valid tokens in `notes`, `flow`, and `coordinator` keeps fully working tokens
in all three no matter which one they "log out" of — right up to each token's own `exp`
(1 hour access / 30 days refresh by default, `config/checkpoint.php`'s
`jwt.expiry`/`jwt.refresh_expiry`). That is exactly correct behavior for *ordinary* logout
(G2) — it should not reach the other two. But it also means the user's separately-requested
*explicit* "log out of everywhere" capability (G8) is impossible without adding real
revocation somewhere, deliberately reached only by that one distinct action — see F7/D8 in
[00-overview.md](00-overview.md) and the mechanism in [02-design.md](02-design.md) §6.2.

## 9. The client already calls an everywhere-logout endpoint that does not exist (F9)

`resources/js/checkpoint/auth.ts` — present, verified identical, in every one of the seven
web apps' copies (`auth`'s own canonical copy included) — already has:

```typescript
// auth.ts:277-286
/**
 * Logout from all devices
 */
async logoutEverywhere(): Promise<void> {
    await this.http.post('/auth/logout/all', undefined, true);
    await this.http.clearTokens();
    this.currentUser = null;
    this.currentMember = null;
    this.notifyAuthChange(false);
}
```

Grepped `routes/checkpoint.php` for `logout` — it defines exactly one route,
`POST /logout` → `AuthController::logout`. **There is no `/logout/all` route, and
`AuthController` has no `logoutEverywhere` method.** Any app calling
`checkpoint.auth.logoutEverywhere()` today gets a 404.

This is the same shape as F2/F3 (a client or server half exists, the other doesn't), and it
is the natural, minimal place to build G8: the client-side call already exists, in every
copy, unused — including `afsdesktop5`'s and `afsmobile_flutter`'s own independent ports
(confirmed in [05-native-clients-and-sdk-copies.md](05-native-clients-and-sdk-copies.md)).
Building G8 does not require inventing a new endpoint shape or touching any SDK copy — it
requires adding the missing server route and method, and wiring the revocation mechanism
(§8) into that method specifically, not into `logout()`.

`AuthenticationService::logoutEverywhere()` (referenced in §8 above,
[line 467](../../../../w/auth/app/Services/Checkpoint/AuthenticationService.php)) already
exists too, and already calls Delight\Auth's own `logOutEverywhere()` — but, like `logout()`,
that call has no relationship to an already-issued Checkpoint JWT. So even if the missing
route were added today with no other change, calling it would still not revoke anything —
it would just be Delight's own session cleanup, reachable but ineffective. [02-design.md](02-design.md)
§6.2 is what actually closes this.

## 10. `verifyToken()` already distinguishes primary from secondary — refresh tokens do not

`AuthenticationService::verifyToken()` ([lines 371-414](../../../../w/auth/app/Services/Checkpoint/AuthenticationService.php))
checks `$decoded->user->type === 'secondary_user'` to tell the two kinds apart, because a
secondary access token's `user` claim is nested differently
(`SecondaryUserService::buildLoginResponse()`'s own `{type, app_name, super_user_id, user}`
shape — see the comment at lines 384-391). This works for **access** tokens because the
`user` payload passed into `JwtService::createTokenPair()` differs by caller.

It does not work for **refresh** tokens. `createTokenPair()`'s refresh branch
([`JwtService.php:55-58`](../../../../w/auth/app/Services/Checkpoint/JwtService.php)) mints
`createToken(['sub' => $userId, 'type' => 'refresh'], $refreshExpiry)` — no `user` payload
at all, for either caller. A refresh token alone carries no signal distinguishing "this
`sub` is a row in the primary `users` table" from "this `sub` is a row in the secondary
users table" (`SecondaryUserService.php`'s own `$this->tableName`, a separate table with its
own auto-increment id space, confirmed at [line 1098](../../../../w/auth/app/Services/Checkpoint/SecondaryUserService.php)).

This matters specifically because of what F7/D8 adds: a revocation lookup keyed by `sub`.
Consulting that lookup on the refresh path without also knowing the token's kind risks
matching a primary user's revocation record against an unrelated secondary user's refresh
token that happens to carry the same numeric id from a different table. [02-design.md](02-design.md)
§6.2.5 (D9) closes this by adding a `token_kind` claim to both token types at mint time, so the
refresh path can check kind before ever touching the revocation table — the same way the
access path already can.

## 11. Summary: what exists vs. what this plan adds

| Piece | Exists today? | This plan |
|---|---|---|
| Cookie minting on login (`setSsoCookie`) | Implemented, uncalled | Call it from `login()`/`loginWithUsername()`/`ssoLogin()` on success |
| Cookie validation (`validateSsoCookie`) | Implemented, reachable via dead-end `ssoInit` | Reuse via a new endpoint that also mints tokens |
| Domain-scoped token mint from cookie (`getTokensFromSsoCookie`) | Implemented, no route | Add a route: `GET /checkpoint/auth/sso/session` |
| Cookie clearing on logout (`clearSsoCookie`) | Implemented, uncalled | Call it from `logout()` |
| Login page silent check | Does not exist | Add to `login.ts`, gated by fail-open error handling |
| Correct `SSO_COOKIE_DOMAIN` per environment | Does not exist | Add to all four `_env/<mode>/.env` files |
| Rollout flag (silent reauth) | Does not exist | New `SSO_SILENT_REAUTH_ENABLED` |
| Everywhere-logout client call (§9) | Implemented in every SDK copy, calls a route that doesn't exist | Add the missing route + controller method — no SDK change |
| Token revocation (§8) | Does not exist at all | New `checkpoint_primary_logout_watermarks` table + checks in `verifyToken()`/`refreshToken()`, written **only** by everywhere-logout, never by ordinary `logout()` |
| Primary/secondary token disambiguation on refresh (§10) | Does not exist | New `token_kind` claim on both access and refresh tokens |
| Rollout flag (everywhere-logout) | Does not exist | New `SSO_CROSS_APP_LOGOUT_ENABLED`, independent of the silent-reauth flag |
