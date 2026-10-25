# 02 — Design

This document covers two related but separable mechanisms, plus the corrected split within
the second one: §§1-5 are **silent sign-on** (logging in to one app, in a browser, signs
you in to the rest of that same browser's apps); §6 is **logout**, split into two
unmistakably different actions — **ordinary logout** (scoped to the one session it was
called from, §6.1) and **everywhere logout** (a new, explicit, separate capability, §6.2).
They ship behind independent flags ([03-env-variables.md](03-env-variables.md)) and can be
enabled, tested, or rolled back one at a time.

## 1. Shape of the fix (silent sign-on)

```
TODAY

  App              Checkpoint /login              Checkpoint backend
   |  redirect          |                                |
   |------------------->|  always render form            |
   |                     |                                |
   |                (user types password)                 |
   |                     |------ ssoLogin() ------------->|
   |                     |<----- ticket ------------------|
   |<---- ticket --------|                                |
   | exchange ticket for token, store in localStorage     |


AFTER THIS PLAN

  App              Checkpoint /login              Checkpoint backend
   |  redirect          |                                |
   |------------------->|-- GET /sso/session?domain= --->|
   |                     |     (reads checkpoint_sso cookie)
   |                     |<---- 200 {tokens} or 401 ------|
   |                     |
   |          [401: fall through to exactly today's form + ssoLogin() path]
   |          [200: skip the form entirely]
   |<---- ticket/redirect (same shape login.ts already produces) --|
   | exchange for token, store in localStorage — unchanged
```

The only new decision point is the one box added at the top of the "AFTER" flow. Everything
below it — ticket exchange, per-app storage, the downstream apps' own code — is identical to
today, per [00-overview.md](00-overview.md) G4/non-goals.

## 2. Backend changes (`apps/auth`)

### 2.1 Set the cookie on successful login

In `AuthenticationService.php`, `login()` (line 140), `loginWithUsername()` (line 185), and
`ssoLogin()` (line 230) each already have `$userId` and `$userData` in scope at the point
they build the success response. Add one call in each, right after that point:

```php
$this->setSsoCookie($userId, $userData);
```

This is unconditional — writing a cookie has no observable effect until something reads it,
so it does not need the rollout flag (D4). The 2FA-completion paths
(`completeTwoFactorAuth()` / secondary equivalents in `login.ts`) that currently issue
tokens after a *second* round trip need the same call added at their point of success,
since a 2FA-enabled user's session should be just as eligible for silent reauth as anyone
else's — do not special-case them into a state where 2FA users are the one group who always
re-enters credentials.

### 2.2 Expose a silent-reauth endpoint over HTTP

**As shipped — a real correction, not just the controller wiring this section originally
sketched.** The original draft had this endpoint call `getTokensFromSsoCookie($domain)` and
hand its domain-scoped **token pair** straight back as `data`. That does not work: every
consuming app's SDK (`ClientHandler.handlePageLoad()`, identical in every copy) only knows
how to complete a *primary* login from an `sso_ticket` URL parameter on the redirect back —
the same shape `ssoLogin()` already produces. A raw access/refresh pair has nowhere to go
once it crosses from `auth`'s origin into a different app's origin; the target app's own
localStorage can only be written by that app's own JavaScript, and a URL parameter is the
only hand-off channel that exists. This was found only by tracing what the *consuming* app
actually does with the redirect, not by re-reading this sketch.

The fix: `AuthenticationService` gains a new `getSsoTicketFromCookie(string $domain): ?string`,
which shares its cookie/domain/watermark validation with `getTokensFromSsoCookie()` via a new
private `decodeValidSsoCookie(string $domain): ?object` helper, but mints an **SSO ticket**
(`JwtService::createSsoTicket()`) instead of a token pair. `getTokensFromSsoCookie()` itself
is untouched in behavior — still a legitimate, independent capability for a same-origin
caller that can use tokens directly — it just isn't what powers the browser-redirect flow.

Add to `AuthController.php`:

```php
/**
 * Silent re-authentication. Returns an SSO ticket — the same shape a manual
 * login produces, not a bare token pair — if this browser already holds a
 * valid Checkpoint SSO session cookie, so the login page can skip the
 * credential form entirely and redirect exactly the way it already does
 * after a real login.
 *
 * Trusts: the checkpoint_sso HttpOnly cookie, validated the same way
 * validateSsoCookie() validates it for ssoInit() — AND checked against the
 * everywhere-logout watermark inside getSsoTicketFromCookie() itself: a
 * cookie minted before the primary user's last everywhere-logout is treated
 * as absent, not as a valid session to mint a fresh ticket from. Without
 * this check, a browser that still holds an old-but-unexpired SSO cookie
 * could silently re-establish a session immediately after the user believed
 * they had logged out of every browser and device — see §6.2.4.
 * Rejects: no cookie, an expired/invalid cookie, a cookie predating an
 * everywhere-logout, or a domain not present in sso.allowed_domains — each
 * returns 401, never a partial/guessed session.
 * Fails: closed. Any exception here must surface as "no session found,"
 * not as an authenticated response.
 */
public function ssoSession(Request $request): JsonResponse
{
    if (!config('checkpoint.sso.silent_reauth_enabled', false)) {
        return $this->errorResponse('Silent re-authentication is disabled', 'AUTH_SSO_SILENT_REAUTH_DISABLED', 404);
    }

    try {
        $domain = $request->input('domain') ?? $request->getHost();
        $ticket = $this->checkpoint->auth()->getSsoTicketFromCookie($domain);

        if (!$ticket) {
            return $this->errorResponse('No valid SSO session found', 'AUTH_NO_SSO_SESSION', 401);
        }

        return response()->json(['success' => true, 'data' => ['sso_ticket' => $ticket]]);
    } catch (AuthException $e) {
        return $this->errorResponse($e->getMessage(), $e->getErrorCode(), 400);
    } catch (\Exception $e) {
        return $this->errorResponse('Silent re-authentication failed', 'AUTH_SSO_SESSION_FAILED', 500);
    }
}
```

Route it in `routes/checkpoint.php`, alongside the existing `sso/*` group:

```php
Route::get('/sso/session', [AuthController::class, 'ssoSession']);
```

The route name and shape still mirror the dead router's `GET /auth/sso/session`
(§2 in [01-current-state-audit.md](01-current-state-audit.md)) — only what it returns
changed, from that dead code's own token-pair shape to a ticket.

The `config('checkpoint.sso.silent_reauth_enabled', false)` check inside the controller
(not just at the login-page call site) means the endpoint is inert by default even if
something calls it directly — this is the actual mechanism behind D4's rollout flag,
detailed in [03-env-variables.md](03-env-variables.md) and
[04-rollout-and-testing.md](04-rollout-and-testing.md).

### 2.3 Clear the cookie on ordinary logout

`AuthenticationService::logout()` (line 451):

```php
public function logout(): array
{
    try {
        $this->auth->logOut();
        $this->clearSsoCookie();
        return ['message' => 'Successfully logged out'];
    } catch (\Exception $e) {
        $this->clearSsoCookie();
        return ['message' => 'Logged out'];
    }
}
```

Clear it in **both** branches — a logout that failed partway through the underlying
Delight\Auth call should not leave the SSO session cookie as the one thing still valid.

**This is the entire change to `logout()`.** No revocation watermark is written here — see
§6.1 for why that boundary matters and is deliberate, not an oversight.

## 3. Frontend changes (`apps/auth` login page only)

`login.ts`'s `DOMContentLoaded` handler (§1 in
[01-current-state-audit.md](01-current-state-audit.md)) already computes `ssoTargetDomain`
and `ssoRedirectUrl` before touching the form. Insert the silent check there, before the
form's submit listener is even relevant:

**As shipped** — two corrections from the original sketch, both downstream of §2.2's
ticket-not-tokens fix: the response shape is `{ data: { sso_ticket } }`, not a token pair,
so the redirect helper only ever needs to build one URL shape; and there is no
`checkpoint.baseUrl` to read (`CheckpointClient` only exposes `ssoDomain`, a different
value) — a relative path resolves correctly since this script is already served from
`auth`'s own origin.

```typescript
/**
 * Redirect to the target app with an SSO ticket — the exact URL shape every
 * consuming app's ClientHandler.handlePageLoad() already knows how to
 * complete a primary login from. Shared by the manual-login path (already
 * existed twice in this file, lines ~400 and ~529 before this change) and
 * the silent-reauth path below, so both produce an identical redirect
 * regardless of whether the user typed credentials or the login page found
 * an existing SSO session cookie.
 */
function redirectWithSsoTicket(redirectUrl: string, ticket: string, user: unknown, member?: unknown): void {
    const separator = redirectUrl.includes('?') ? '&' : '?';
    const userParam = encodeURIComponent(JSON.stringify(user || {}));
    const memberParam = member ? `&member=${encodeURIComponent(JSON.stringify(member))}` : '';
    window.location.href = `${redirectUrl}${separator}sso_ticket=${encodeURIComponent(ticket)}&user=${userParam}${memberParam}`;
}

if (ssoTargetDomain && ssoRedirectUrl) {
    (async () => {
        try {
            // Relative path — this page is already same-origin with the
            // endpoint it's calling.
            const response = await fetch(
                `/checkpoint/auth/sso/session?domain=${encodeURIComponent(ssoTargetDomain)}`,
                { credentials: 'include' }
            );

            if (response.ok) {
                const body = await response.json() as { data?: { sso_ticket?: string } };
                const ticket = body.data?.sso_ticket;
                if (ticket) {
                    redirectWithSsoTicket(ssoRedirectUrl, ticket, {});
                }
                // 200 with no ticket in the body — fall through to the form below
            }
            // any non-2xx (401 no session, 404 disabled, 5xx) falls through below
        } catch {
            // network error — fall through below
        }
    })();
}
// existing code: render the form, wire the submit handler, exactly as today
```

The two pre-existing inline redirect blocks (manual ticket login, and the post-2FA
redirect) were both rewritten to call `redirectWithSsoTicket()` too — the low-risk cleanup
this change needed anyway, now actually done rather than merely proposed.

**Fail-open is load-bearing here, not a nicety.** Every branch that isn't an explicit `200`
with a usable ticket falls through to exactly today's code path — the credential form. A
flaky network, a Checkpoint restart mid-request, a malformed response, or the endpoint
being disabled (§2.2's 404) must all look identical to "no session," never to "authenticated
anyway." This satisfies acceptance A9, and was confirmed live: a fresh browser with no
`checkpoint_sso` cookie hit the endpoint, got 401, and rendered the form exactly as before —
no `console` exception, just the network layer's own logged 401, which is expected.

If `ssoTargetDomain`/`ssoRedirectUrl` are absent (e.g. someone hits `/login` directly with
no query params), the silent check is skipped entirely and today's plain-login form renders
as it always has — this path is for the app-redirect flow specifically.

## 4. What does not change

- `ssoExchangeToken()`, the per-app `localStorage` token, and every one of the seven web
  apps' own frontend code: **untouched.** They already redirect eagerly (§1 in
  [01-current-state-audit.md](01-current-state-audit.md)) and already handle whatever comes
  back from the redirect; a silently-completed login looks to them identical to a
  form-completed one.
- Secondary-user login (`isSecondaryScopedLogin()` branch in `login.ts`): untouched. It
  never sets or reads `checkpoint_sso` before or after this plan (A8). This extends to
  logout (both kinds) and token refresh too, per §6.4 — a secondary user still signs in to
  every app separately, and logging one out never has any cross-app effect, in either
  direction.
- `www`'s own login system: untouched, out of scope entirely — see
  [00-overview.md](00-overview.md) §1/G9. It is not one of the seven apps this design
  applies to.
- `checkpoint.member` / `checkpoint.verify` middleware, app scoping, everything in
  `checkpoint-server-auth`: untouched, per [00-overview.md](00-overview.md) non-goals.

## 5. Security posture (silent sign-on)

- **Cookie flags** (`config/checkpoint.php:189-191`, already correct, unchanged by this
  plan): `HttpOnly` — never readable by any app's JavaScript, including Checkpoint's own
  login page script, which only ever reaches it indirectly through the server-side
  `/sso/session` endpoint. `Secure` — sent only over HTTPS, which every one of the four
  environments serves (`AGENTS.md` §6: Laragon's self-signed pair locally, the lab's mkcert
  wildcard in vagrant, real certificates in dev/prod). `SameSite=Lax` — **must** stay `Lax`,
  not `Strict`: the cookie has to be sent on the cross-site top-level `GET` navigation from
  an app's redirect to `auth.../login`, which is exactly the request `Strict` would block.
  Getting this wrong reintroduces the entire problem this plan fixes.
- **Domain scoping**: `decodeValidSsoCookie()` — the shared helper behind both
  `getTokensFromSsoCookie()` and `getSsoTicketFromCookie()`, §2.2 — calls
  `isDomainAllowed($domain)` before decoding anything (same allow-list the ticket path
  uses), so the new endpoint inherits this for free (A7). A request for a domain outside
  `sso.allowed_domains` is rejected the same way ticket-mode already rejects it — verified
  live, `AUTH_SSO_DOMAIN_NOT_ALLOWED`.
- **Blast radius of a stolen `checkpoint_sso` cookie**: identical in kind to a stolen SSO
  ticket today, but longer-lived (30-day default vs. 60-second ticket expiry,
  `config/checkpoint.php:195`). This is a real, new consideration this plan introduces and
  is called out as an open follow-up in
  [04-rollout-and-testing.md](04-rollout-and-testing.md) §4 rather than resolved here.

## 6. Logout: two distinct actions

The user identified a real design mistake in this plan's first draft: it wired the
everywhere-reaching mechanism into the *ordinary* "Sign out" button, so logging out of the
mobile app would have logged out every browser too. That is backwards from how logout
should work. This section is the corrected design: two actions, two different server
methods, and exactly one of them is allowed to ever reach another session.

### 6.1 Ordinary logout — scoped to the one session it was called from (G2)

This is §2.3, already specified above, and it is now the **entire** scope of `logout()`:
clear that app's own token client-side (already true today, unchanged), and — for a web
app specifically — clear the `checkpoint_sso` cookie for that one browser, so that browser
stops silently re-authenticating into other apps. It never writes anything a different
browser or device could ever read. `afsdesktop5` and `afsmobile_flutter` call this same
method for their own "Sign out," and it needs no change there either — their existing
"clear local state" behavior already matches this scope exactly (confirmed in
[05-native-clients-and-sdk-copies.md](05-native-clients-and-sdk-copies.md)).

**Why this boundary is drawn at the method, not just at a flag or a comment**: `logout()`
has no branch that could reach a revocation table even if someone wanted to add one later
by mistake — the watermark-writing code lives entirely inside a different method (§6.2),
called from a different route. Keeping them physically separate is what makes "ordinary
logout must never go cross-device" hard to violate accidentally in a future change, not
just documented as a rule.

### 6.2 Everywhere logout — a new, explicit, separate capability (G8)

This is genuinely new functionality the user asked to have added: one action that ends a
primary user's session on every browser and device at once. It is deliberately **not**
something ordinary logout escalates into — it must be its own button, its own confirmation,
its own server call.

#### 6.2.1 The client already calls it — only the server is missing (F9, D7)

`resources/js/checkpoint/auth.ts`, present identically in every app's copy
(§9 in [01-current-state-audit.md](01-current-state-audit.md)):

```typescript
async logoutEverywhere(): Promise<void> {
    await this.http.post('/auth/logout/all', undefined, true);
    await this.http.clearTokens();
    this.currentUser = null;
    this.currentMember = null;
    this.notifyAuthChange(false);
}
```

This already exists, unused, in every one of the seven web apps' copies **and** in
`afsdesktop5`'s and `afsmobile_flutter`'s own independently-ported copies. Any app or
native client can call `checkpoint.auth.logoutEverywhere()` today — it just 404s. Closing
this needs no SDK change anywhere; it needs the missing server half.

#### 6.2.2 Add the missing route and controller method

**As shipped** — verified against the real `CheckpointService::requireAuth()`/`verifyToken()`
return shapes rather than assumed. Two things changed from an earlier draft of this
section, both because the assumed shape was wrong, not because the design changed: secondary
status is a top-level `user_type` key on `requireAuth()`'s result, not nested under
`user`; and `requireAuth()` takes no arguments (it reads the bearer token from the global
`request()` helper itself) — `setup2fa()` and `enable2fa()` already call it that way
elsewhere in this file, so this matches the existing convention rather than the
`changePassword()`-style call that (harmlessly) passes `$request` in.

```php
// AuthController.php
/**
 * Ends the primary user's session everywhere — every browser, every
 * device — not just the caller's own session.
 *
 * Trusts: an already-authenticated request (requireAuth()), exactly like
 * every other self-service auth action.
 * Rejects: a secondary-user token — this is a primary-only capability;
 * secondary sessions have no cross-app concept to end (G6). Also refuses
 * outright, with 404, when checkpoint.sso.cross_app_logout_enabled is off
 * — the single gate point for this whole feature (D4's SSO_SILENT_REAUTH_ENABLED
 * pattern, mirrored here). Nothing else in the everywhere-logout path
 * (verifyToken()/refreshToken()/decodeValidSsoCookie()'s watermark checks)
 * needs its own separate flag check: with this route always 404ing while
 * disabled, the watermark table can never be written to, so those checks
 * stay harmless no-ops rather than needing to be gated individually.
 * Fails: closed — if the watermark write fails, the response reports
 * failure rather than claiming success while nothing was actually revoked.
 */
public function logoutEverywhere(Request $request): JsonResponse
{
    if (!config('checkpoint.sso.cross_app_logout_enabled', false)) {
        return $this->errorResponse('Everywhere-logout is disabled', 'AUTH_CROSS_APP_LOGOUT_DISABLED', 404);
    }

    try {
        // No argument — requireAuth() reads the bearer token from request()
        // itself; verified against the real method signature, not assumed.
        $user = $this->checkpoint->requireAuth();

        // Top-level user_type, not user.type — verifyToken() (the method
        // requireAuth() calls) surfaces secondary status as a sibling of
        // `user`, not nested inside it. Checking the wrong path here would
        // have let a secondary user call this successfully.
        if (($user['user_type'] ?? null) === 'secondary_user') {
            return $this->errorResponse('Not available for secondary users', 'AUTH_SECONDARY_NOT_PERMITTED', 403);
        }

        // The verified id from THIS request's bearer token, passed in — see
        // §6.2.6 for why logoutEverywhere() cannot derive it itself.
        $result = $this->checkpoint->auth()->logoutEverywhere((int) $user['user_id']);

        return response()->json(['success' => true, 'message' => $result['message']]);
    } catch (CheckpointException $e) {
        // What requireAuth() actually throws when the bearer token is
        // missing or invalid — carries its own status code (401).
        return $this->errorResponse($e->getMessage(), $e->getErrorCode(), $e->getStatusCode());
    } catch (AuthException $e) {
        return $this->errorResponse($e->getMessage(), $e->getErrorCode(), 401);
    } catch (\Exception $e) {
        return $this->errorResponse('Logout failed', 'AUTH_LOGOUT_EVERYWHERE_FAILED', 500);
    }
}
```

```php
// routes/checkpoint.php, alongside the existing /logout route
Route::post('/logout/all', [AuthController::class, 'logoutEverywhere']);
```

The path matches the client's existing call exactly (§6.2.1) — no SDK change, on either
side of the pairing, is needed. `AuthController.php` needs one added `use` import,
`App\Services\Checkpoint\CheckpointException`, alongside its existing `AuthException`
import — the class `requireAuth()` actually throws, not the one every other method in this
controller already catches.

#### 6.2.3 Why a revocation watermark, not a token blacklist

Two shapes were considered:

- **Blacklist every individual token** issued to a user, checked by token id (`jti`) on
  every verify/refresh call. Correct, but requires adding a `jti` claim, storing one row per
  token issued (not per user), and pruning expired entries — meaningfully more moving parts.
- **A single watermark timestamp per user** (`invalidated_at`): reject any token whose `iat`
  predates it. One row per user, upserted (never inserted-per-event), and self-cleaning.

The watermark is chosen: no cleanup job, no new per-token claim beyond the `token_kind`
addition (§6.2.5/D9) already needed, and "reject everything issued before this moment" is
exactly the semantics G8 asks for.

#### 6.2.4 Schema, and closing the cookie re-mint gap

A new table, owned by `auth` on the **`checkpoint` connection** (per `AGENTS.md` §5 — this
is Checkpoint identity state, shared alongside `checkpoint_*`/`admin_*`/`ext_*`). **As
shipped** (`database/migrations/2026_09_18_000001_create_checkpoint_primary_logout_watermarks_table.php`,
migrated and verified present on the vagrant lab) — the `hasTable()` guard wraps the whole
`create()` call, not a `return` inside its column closure, which would have been a no-op
check that never actually prevented anything:

```php
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::connection('checkpoint')->hasTable('checkpoint_primary_logout_watermarks')) {
            Schema::connection('checkpoint')->create('checkpoint_primary_logout_watermarks', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->primary();
                $table->timestamp('invalidated_at');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('checkpoint')->dropIfExists('checkpoint_primary_logout_watermarks');
    }
};
```

One row per **primary** user only — never written to, or read for, a secondary user id
(§6.4). `user_id` as the primary key makes the write an upsert, not a growing log. Unlike
the identity-table migration this one sits alongside (`2026_07_13_000001_create_checkpoint_tables.php`,
whose `down()` is deliberately inert), this table holds no data worth protecting from a
rollback, so `down()` actually drops it.

**A gap that had to be closed in the same change that shipped silent sign-on — done, not
deferred.** Whatever `decodeValidSsoCookie()` mints from (a token pair via
`getTokensFromSsoCookie()`, or a ticket via `getSsoTicketFromCookie()`, §2.2) carries `iat` =
the current time — not the SSO cookie's own original mint time. If Browser B still holds a
valid, unexpired `checkpoint_sso` cookie when the user triggers an everywhere-logout from
Browser A, Browser B's *next* silent-reauth attempt would otherwise mint a fresh ticket
whose `iat` is *after* the watermark, and the §6.2.6 check would accept it — silently
re-establishing exactly the session the user just asked to end everywhere. The fix lives in
`decodeValidSsoCookie()` itself, shared by both mint paths: it checks the **cookie's own**
`iat` against the watermark before either caller ever mints anything from it, and clears the
cookie on rejection — forcing Browser B through a real credential-form login instead.
Verified live: a cookie confirmed working, then an everywhere-logout triggered from a
separate token, then the *same* cookie retried and correctly rejected with
`AUTH_NO_SSO_SESSION` rather than silently minting a new ticket.

#### 6.2.5 `token_kind` claim (D9)

`JwtService::createTokenPair()` gains a third parameter, threaded through from both
callers:

```php
public function createTokenPair(int $userId, array $userData, ?string $audience = null, string $tokenKind = 'normal'): array
{
    $now = time();
    $accessExpiry = $now + $this->config->get('jwt.expiry', 3600);
    $refreshExpiry = $now + $this->config->get('jwt.refresh_expiry', 2592000);

    $accessToken = $this->createToken([
        'sub' => $userId, 'user' => $userData, 'type' => 'access',
        'aud' => $audience, 'token_kind' => $tokenKind,
    ], $accessExpiry);

    $refreshToken = $this->createToken([
        'sub' => $userId, 'type' => 'refresh', 'token_kind' => $tokenKind,
    ], $refreshExpiry);
    // ...unchanged return shape
}
```

`SecondaryUserService`'s two call sites (`AuthenticationService.php` line 797 and 978 per
[01-current-state-audit.md](01-current-state-audit.md) §10) pass `tokenKind: 'secondary_user'`
explicitly; every primary-path caller (`login()`, `loginWithUsername()`, `ssoExchangeToken()`,
`getTokensFromSsoCookie()`, `refreshToken()`) uses the default `'normal'`. This makes kind
determinable from **either** token in a pair, closing F8 without touching the two token
types' existing payload shapes otherwise.

**Transitional note**: tokens minted before this change carry no `token_kind` claim. Treat a
missing claim as `'normal'` when checking — safe for already-issued *primary* refresh
tokens, and bounded to a 30-day window (the refresh-token lifetime) for the theoretical
secondary/primary id-collision case, self-resolving as old tokens expire.

#### 6.2.6 Enforcement points

**As shipped.** `verifyToken()` does not check the new `token_kind` claim at all — it
reuses `$isSecondary`, a boolean it already computes a few lines earlier from the access
token's existing nested `user.type` claim (§10 in
[01-current-state-audit.md](01-current-state-audit.md)), which is strictly more reliable
for an *access* token than re-deriving the same fact from `token_kind`. `token_kind` earns
its keep specifically on the refresh path, where no such nested claim exists at all:

```php
// AuthenticationService::verifyToken(), after decoding, before returning success —
// $isSecondary is the method's own existing variable, not a new token_kind check
if (!$isSecondary) {
    $watermark = $this->getPrimaryLogoutWatermark((int) $decoded->sub);
    if ($watermark !== null && $decoded->iat < $watermark) {
        throw new AuthException('Token has been revoked', 'AUTH_TOKEN_REVOKED');
    }
}
```

```php
// AuthenticationService::refreshToken() — no nested `user` claim exists on a
// refresh token, so token_kind is what actually earns its keep here
if (($decoded->token_kind ?? 'normal') === 'normal') {
    $watermark = $this->getPrimaryLogoutWatermark((int) $decoded->sub);
    if ($watermark !== null && $decoded->iat < $watermark) {
        throw new AuthException('Token has been revoked', 'AUTH_TOKEN_REVOKED');
    }
}
```

**`decodeValidSsoCookie()` carries the equivalent check for the cookie path** (§6.2.4) —
built alongside silent sign-on (§§1–5) once that shipped, closing the gap this section
originally left open. **Secondary tokens never reach any of these checks** — the access
path's `$isSecondary`, the refresh path's `token_kind === 'secondary_user'`, and the cookie
path (secondary users never get an SSO session cookie at all, since `setSsoCookie()` is only
called from the primary-path methods in §2.1) all skip it entirely, satisfying G6/A8/A11 by
construction, not by convention.

```php
// AuthenticationService::logoutEverywhere() — the ONLY place this watermark is ever written
public function logoutEverywhere(int $userId): array
{
    try {
        $this->auth->logOutEverywhere();
    } catch (\Delight\Auth\NotLoggedInException $e) {
        // Expected, not fatal: $this->auth (Delight) is backed by a PHP
        // session, which a stateless bearer-token request generally does
        // not carry. The watermark below, not Delight's own session
        // bookkeeping, is the control this API actually relies on — see
        // §6.2's introduction to this whole shift in reasoning, made only
        // after a real HTTP call showed $this->auth->getUserId() (an
        // earlier draft's plan for getting $userId) is null in exactly this
        // situation. $userId is therefore a parameter, supplied by
        // AuthController::logoutEverywhere() from its own already-verified
        // bearer token (§6.2.2), not derived in here at all.
    }

    $this->setPrimaryLogoutWatermark($userId);
    $this->clearSsoCookie(); // ends silent reauth for THIS browser too, same as ordinary logout

    return ['message' => 'Successfully logged out from all devices'];
}
```

`setPrimaryLogoutWatermark()`'s own implementation matters as much as this method — it
generates its timestamp in PHP, deliberately never via SQL's `NOW()`:

```php
private function setPrimaryLogoutWatermark(int $userId): void
{
    // Not NOW() — verified live in the vagrant lab that the DB server's
    // clock and this app's PHP clock can genuinely disagree (four hours,
    // here). Comparing a MySQL-generated timestamp against PHP's time()/JWT
    // `iat` silently made the revocation check above pass no matter what,
    // caught only by running the whole flow end to end, not by review.
    $nowUtc = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    $db = $this->config->getDatabase();
    $stmt = $db->prepare(
        'INSERT INTO checkpoint_primary_logout_watermarks (user_id, invalidated_at) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE invalidated_at = VALUES(invalidated_at)'
    );
    $stmt->execute([$userId, $nowUtc]);
}
```

`getPrimaryLogoutWatermark()` reads that same column back with an explicit
`new \DateTime($row['invalidated_at'], new \DateTimeZone('UTC'))` rather than `strtotime()`
— `strtotime()` on a bare `"Y-m-d H:i:s"` string applies PHP's *default* timezone, which is
exactly the assumption that has to hold for the write and the read to agree, and the one
that turned out not to hold against this lab's actual MySQL server.

Compare this against §2.3's `logout()` — the watermark write appears in exactly one of the
two methods, and that method is reachable over HTTP only through §6.2.2's route, which is
gated by the new `SSO_CROSS_APP_LOGOUT_ENABLED` flag
([03-env-variables.md](03-env-variables.md)) the same way §2.2's endpoint is gated by
`SSO_SILENT_REAUTH_ENABLED` — independently, since a platform operator may want one
capability without the other during rollout. The enforcement checks above (in
`verifyToken()`/`refreshToken()`/`decodeValidSsoCookie()`) carry no flag check of their
own — they don't need one, since the single route-level gate is what determines whether the
watermark table ever has a row to find. This is why Phase 1's isolated `tinker` verification
([04-rollout-and-testing.md](04-rollout-and-testing.md)) calls
`AuthenticationService::logoutEverywhere()` directly, bypassing the gated controller
entirely, while both flags stay off — it is testing the plumbing, not the rollout switch.

#### 6.2.7 What "everywhere" actually means in practice

Every one of the seven web apps' `checkpoint.member`/`checkpoint.verify` middleware already
calls Checkpoint to verify a bearer token on (some cadence of) every request — never purely
locally, per `checkpoint-server-auth` D2, specifically because local JWT verification
"cannot honour revocation." That existing architecture is *why* this works with zero
changes to any of them (or to `afsdesktop5`/`afsmobile_flutter`,
[05](05-native-clients-and-sdk-copies.md)): the moment `verifyToken()` starts rejecting a
revoked token, every caller starts rejecting that token too, on its own next call.

Two honest bounds on "everywhere":

1. **Cache window.** `checkpoint-server-auth` F7/A9 already establishes that a *positive*
   verification result may be cached for up to 60 seconds per app. A token verified and
   cached just before an everywhere-logout can remain accepted, in that one app, for up to
   that window — this plan does not shrink that cache, and shrinking it is out of scope.
2. **No push to an idle tab.** An app or device with no open request in that window won't
   notice until its next request or reload (D8's rationale for not choosing a front-channel
   push). This is the standard bound for bearer-token SSO without a dedicated push channel.

Both are stated as the actual behavior in [00-overview.md](00-overview.md) A17, not
smoothed over as "instant."

### 6.3 Where the "log out everywhere" action should live

A product decision this design flags rather than mandates: the capability (§6.2) must exist
and be reachable, but nothing here requires building a button in all seven apps. `members`
is the natural home — it is the account/security hub in this platform's own structure — and
a single entry point there, calling the already-existing
`checkpoint.auth.logoutEverywhere()` SDK method directly, is sufficient to satisfy G8
without any SDK propagation. Surfacing it additionally in other apps, or in
`afsdesktop5`/`afsmobile_flutter`'s own settings screens, is a reasonable future
enhancement using the exact same already-existing client call — not required for this plan
to be complete.

### 6.4 Secondary users: untouched by either action

Neither `logout()` nor `logoutEverywhere()` gains any new secondary-specific branch.
Secondary sign-out already works exactly as it does today — clearing that one app's
secondary token — and continues to, because:

- The revocation watermark is only ever consulted for a primary token — `verifyToken()`
  via its own existing `$isSecondary` check, `refreshToken()` via the new `token_kind`
  claim, since it has no equivalent of `$isSecondary` to reuse (§6.2.5/§6.2.6). A secondary
  token never reaches either check.
- §6.2.2's `logoutEverywhere()` controller method explicitly refuses a secondary-user's own
  token with `AUTH_SECONDARY_NOT_PERMITTED` — a secondary user has no cross-app session
  concept to end, so the action is refused outright rather than silently doing nothing.

This is G6, enforced at both the data layer and the entry point, not merely asserted.

## 7. Logout propagation within one browser (G10)

**Found by testing, not anticipated by the original design**: §6.1 correctly scopes ordinary
logout to the session it was called from — but "scoped to one session" was read as "affects
only a future visit," when the real complaint was about an *already-open, already-authenticated
tab of a different app* not noticing. App A clearing the shared `checkpoint_sso` cookie on
logout (§2.3) does nothing for app B if B never makes another request — B's own access/refresh
tokens are independent, per-origin, and stay valid by construction (§4). Confirmed live: logging
out of `members` left an already-open `coordinator` tab fully authenticated, with its own valid
tokens still in its own `localStorage`, until that tab was reloaded.

### 7.1 The mechanism

A new, minimal endpoint answers one question and mints nothing:

```
GET /checkpoint/auth/sso/status?domain=<requesting-app-domain>
```

Gated by `SSO_LOGOUT_PROPAGATION_ENABLED` (config `checkpoint.sso.logout_propagation_enabled`,
off by default — same rollout shape as the other two SSO flags). Reuses `decodeValidSsoCookie()`
(§2.2, already shared by `getTokensFromSsoCookie()` and `getSsoTicketFromCookie()`) a third
time — same domain validation, same watermark check against the cookie's own `iat` — but
returns `{"active": true}` on success instead of minting a ticket or token pair, since this is
polled far more often than either of those should ever be issued:

```php
public function ssoStatus(Request $request): JsonResponse
{
    if (!config('checkpoint.sso.logout_propagation_enabled', false)) {
        return $this->errorResponse('Logout propagation is disabled', 'AUTH_SSO_LOGOUT_PROPAGATION_DISABLED', 404);
    }

    $domain = $request->input('domain') ?? $request->getHost();
    if (!$this->checkpoint->auth()->hasValidSsoSession($domain)) {
        return $this->errorResponse('No active SSO session', 'AUTH_NO_SSO_SESSION', 401);
    }

    return response()->json(['success' => true, 'data' => ['active' => true]]);
}
```

`hasValidSsoSession()` on `AuthenticationService` is a one-line wrapper:
`decodeValidSsoCookie($domain) !== null`.

### 7.2 The client side — `ClientHandler`

Every app's shared SDK copy already runs `handlePageLoad()` once at bootstrap. It now starts a
watch when that resolves to an authenticated **primary** user (never secondary — G6):

```typescript
async handlePageLoad(): Promise<PageLoadResult> {
    const result = await this.handlePageLoadInner();
    if (result.authState.isAuthenticated && result.authState.userType === 'normal') {
        this.startLogoutWatch();
    }
    return result;
}

startLogoutWatch(): void {
    if (this.logoutWatchStarted) return;
    this.logoutWatchStarted = true;
    void this.checkSsoLiveness();
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') void this.checkSsoLiveness();
    });
}

private async checkSsoLiveness(): Promise<void> {
    try {
        const response = await fetch(
            `${this.checkpoint.baseUrl}/checkpoint/auth/sso/status?domain=${encodeURIComponent(window.location.hostname)}`,
            { credentials: 'include' },
        );
        if (response.status !== 401) return;
        const body = await response.json().catch(() => null) as { error?: { code?: string } } | null;
        if (body?.error?.code === 'AUTH_NO_SSO_SESSION') {
            await this.logout();
            window.location.href = this.buildLoginUrl('normal');
        }
    } catch {
        // Network error — never log the user out on a flaky request.
    }
}
```

Checked once on load (catches a reload or a fresh navigation to an already-authenticated app)
and again on `visibilitychange` (catches switching back to a tab left open) — the two realistic
ways a user actually notices the app is still showing them signed in. **Fails closed**: a
disabled flag (404), a network error, or any response other than an explicit
401/`AUTH_NO_SSO_SESSION` is treated as "still logged in," mirroring the fail-open-to-the-form
posture §5 established for silent sign-on, inverted for this direction. On a confirmed 401, it
reuses the existing `logout()` (§6.1) rather than duplicating its local-storage-clearing logic —
calling it again after the server-side cookie is already gone is a harmless no-op network call,
and it's the one place that already knows how to clear every storage key correctly.

This needed one small addition to `CheckpointClient` itself: a public `baseUrl` getter
alongside the existing `ssoDomain` one (`index.ts`), so `ClientHandler` can build this absolute
cross-origin URL without every app inventing its own copy of "what is Checkpoint's own origin"
— closing the exact class of bug found in `members` while diagnosing this (three separate
hand-rolled `hostname.includes('members.') ? ... : ...` guesses for the post-logout redirect
domain, all wrong for the vagrant lab's `.test1` domains; fixed separately by using the
already-existing `VITE_CHECKPOINT_BASE_URL`-backed value instead).

### 7.3 Why this doesn't reach into `afsdesktop5` / `afsmobile_flutter`

Both native clients authenticate via direct credential POST and never touch Checkpoint's
`checkpoint_sso` cookie at all ([05](05-native-clients-and-sdk-copies.md)) — there is nothing
for this mechanism to check there, and nothing to change. This is consistent with G1 and G2
both already being structurally inapplicable to native clients for the same reason.

### 7.4 Scope discipline: this is not everywhere-logout

`checkSsoLiveness()` never touches the revocation watermark (§6.2.4) and has no server-side
component beyond reading the same cookie silent sign-on already reads. It cannot, by
construction, propagate a logout to a different browser or device — only to another app's tab
in the *same* browser that happens to share the same `checkpoint_sso` cookie. Everywhere-logout
(§6.2) remains the only mechanism that reaches other browsers and devices, and remains the only
thing that writes the watermark.
