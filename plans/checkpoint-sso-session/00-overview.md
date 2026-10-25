# 00 — Overview

## Completion document

When this plan step is completed, write `complete/00-overview.complete.md` with status,
summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments (PHPDoc for PHP, JSDoc
for TypeScript). The login page's silent-check code, and the logout-scope split in §5's
decisions (D3/D5/D6 below), specifically must document what they trust, what they reject,
and which way they fail — see the standard set by
[checkpoint-server-auth](../checkpoint-server-auth/00-overview.md).

---

## 1. The problem

All seven consuming web apps (`members`, `coordinator`, `dream_graph`, `flow`, `notes`,
`pdf_editor`, `market`) authenticate through Checkpoint (`apps/auth`) using an SSO
**ticket** flow: an app redirects the browser to Checkpoint's `/login`, the user submits
credentials, Checkpoint mints a short-lived ticket, redirects back, and the app exchanges
the ticket for its own token.

That flow runs in full — credential form included — every single time, for every app,
regardless of whether the same browser authenticated against the same Checkpoint instance
thirty seconds earlier for a different app. A real IdP-backed SSO platform does not do this:
the IdP should recognize its own existing session and skip straight to minting a fresh
ticket, with the user never seeing a form the second time.

**`www` is deliberately not in that list.** It has its own, separate login system and is
meant to stay that way — see §3's non-goal. `www` does have a copy of Checkpoint's client
SDK (`www/resources/js/checkpoint/`, consumed by `www/resources/js/afs/globalAuth.ts` and a
similar file under `resources/js/portal/`), but that is Checkpoint being used for *other*
functionality `www` consumes, not for `www`'s own end-user sign-in. Nothing in this plan
changes, touches, or routes through that usage.

### 1.1 How this was found

While fixing an unrelated 404 in coordinator's `/api/pm/workspace` route (a missing
`public/.htaccess`), the user asked how the Checkpoint IdP is supposed to behave across
apps, and specifically whether being signed in to one app should mean being signed in to
the rest. Tracing the login path from a downstream app's redirect through to Checkpoint's
`/login` page and back showed there is no session check anywhere in that path — only a
credential form, every time.

A second question followed: should logging out of one app log a user out everywhere? The
first draft of this plan answered that with a single global mechanism — logging out of
*any* app would revoke that primary user's tokens on *every* device. The user corrected
this (§1.3): ordinary logout must stay scoped to the browser or device it was performed in;
a true "log out of everywhere" must be its own separate, explicit action. §5 reflects the
corrected design.

### 1.2 What is actually wrong (silent sign-on)

| # | Finding | Severity |
|---|---|---|
| **F1** | `auth/resources/js/login.ts`'s `DOMContentLoaded` handler goes straight to wiring the submit button — there is no call to check for an existing session before the form renders | **P1** |
| **F2** | `AuthenticationService::setSsoCookie()` — the method that would create that session — is called from nowhere in live code. Its only caller is `auth/public/checkpoint/_old/index.php`, a dead raw-PHP router superseded by the current Laravel controllers | **P1** |
| **F3** | `AuthenticationService::getTokensFromSsoCookie(string $domain)` already does the one thing a silent-reauth endpoint needs — validates the cookie and mints a domain-scoped token pair — but no live route exposes it. The dead router had one (`GET /auth/sso/session`); `routes/checkpoint.php` does not | **P1** |
| **F4** | `SSO_COOKIE_DOMAIN` is not set in any of `auth/_env/{local,vagrant,dev,prod}/.env`, so `config('checkpoint.sso.cookie_domain')` always falls back to the hardcoded `.appfactory.studio` default — which does not match `auth.test` (local) or `auth.appfactory.test1` (vagrant). A browser silently refuses a `Set-Cookie` whose `Domain` attribute is not the current host or a parent of it, so even after F1–F3 are fixed, the cookie would never actually persist in those two environments | **P1** |
| **F5** | `AuthenticationService::logout()` does not call `clearSsoCookie()`. Fixing F1–F4 without this means logging out of one app leaves every other app *in the same browser* able to silently re-authenticate the same, supposedly-ended session | **P1** |
| **F6** | `sso.mode` (`SSO_MODE` env, `'ticket'`\|`'cookie'`) is read only by `getSsoMode()`, which is itself only called from the same dead `_old` router as F2. It is live-looking configuration that does nothing | **P2** |

F1–F3 together are why the "stay signed in" symptom exists. F4 is why simply calling
`setSsoCookie()` would not be enough on its own — it would appear to work in whichever
environment matches the hardcoded default and silently fail everywhere else. F5 is a bug
this plan must not introduce, and its scope is important: it is about *that one browser*,
not about every device (§1.3). F6 is adjacent dead configuration, noted for completeness
and left alone.

### 1.3 What is actually wrong (logout) — corrected scope

| # | Finding | Severity |
|---|---|---|
| **F7** | There is no token revocation of any kind today. `JwtService::validateToken()` is a pure signature+expiry check with no database lookup; `AuthenticationService::logout()`/`logoutEverywhere()` only affect Delight\Auth's own session state, never an already-issued access/refresh JWT | **P1** |
| **F8** | Refresh tokens (`JwtService::createTokenPair()`'s `'type' => 'refresh'` branch) carry no marker distinguishing a primary token from a secondary one — only the *access* token's nested `user` claim does. A revocation record checked by `sub` alone would, on the refresh path, risk matching a secondary user's refresh token whose numeric id collides with an unrelated primary user's id from a different table | **P1** |
| **F9** | The client SDK — copied into every one of the seven web apps, and independently ported into `afsdesktop5`/`afsmobile_flutter` — **already has a `logoutEverywhere()` method** (`auth.ts:280`, `await this.http.post('/auth/logout/all', ...)`). **No server route answers it.** `routes/checkpoint.php` has `/logout` but no `/logout/all`, and `AuthController` has no `logoutEverywhere` method. This is the exact same "client half exists, server half doesn't" shape as F2/F3, just for the *other* logout button entirely |

**F7 is why no logout today reaches a token already issued to a different session — not
even the one it should.** `AuthenticationService::logoutEverywhere()` exists and calls
Delight\Auth's own `logOutEverywhere()`, but that only affects Delight's own session
bookkeeping, never an already-issued Checkpoint JWT — so even the *intentional*
"everywhere" logout does not, in fact, reach anywhere today. F9 is the missing wiring for
that specific, already-half-built feature — see D7/§5 for what closes it.

**What this plan does *not* do, corrected from an earlier draft**: it does not make
*ordinary* logout (the "Sign out" button every app already has) reach any other browser,
tab, or device. An earlier draft of this plan's design made every logout reach every
session, and the user identified it as wrong before any code was written: logging out of
the mobile app must not log out the browser, and vice versa. §5 documents the corrected
split.

## 2. Goals

| # | Goal | Measured by |
|---|---|---|
| G1 | Visiting a second app shortly after signing in to a first, **in the same browser**, reaches it authenticated with zero credential re-entry | [04](04-rollout-and-testing.md) test matrix; acceptance A1 — **✅ built and verified live in a real browser, [complete/02-design.complete.md](complete/02-design.complete.md)** |
| G2 | **Ordinary logout (the existing "Sign out" action in every app) is scoped to the browser or device it was performed in, and never affects any other browser, tab, or device.** Logging out of the mobile app does not touch the desktop app or any browser session, and vice versa | [02](02-design.md) §6.1; acceptance A3, A10 |
| G3 | Every SSO-cookie setting that differs between `local`/`vagrant`/`dev`/`prod` is set explicitly in that environment's own `_env/<mode>/.env`, matching how `CHECKPOINT_BASE_URL` already works — nothing relies on one hardcoded default being correct everywhere | [03](03-env-variables.md); acceptance A4, A5 — **✅ both done for `vagrant`: `SSO_COOKIE_DOMAIN` set in all four `_env/*/.env`, and a real login's `Set-Cookie` confirmed carrying `domain=.appfactory.test1; secure; HttpOnly; SameSite=Lax` exactly. `local` itself remains unexercised.** |
| G4 | The existing ticket-exchange mechanism is unchanged for every app that already calls it | [02](02-design.md) §1; acceptance A2, A8 |
| G5 | The fix is verified working in `local` and `vagrant` — both genuinely reachable end to end per `DEVELOPMENT_MODE.md` — before it is enabled in `dev` or `prod` | [04](04-rollout-and-testing.md); acceptance A6 |
| G6 | **Secondary-user sign-in and sign-out are completely unaffected.** A secondary user still signs in to every app individually — no silent reauth, no shared session — and logging a secondary user out of one app never touches any other app's secondary session, nor any primary session, and vice versa | [02](02-design.md) §6.4; acceptance A8, A11 |
| G7 | **The two native clients, `afsdesktop5` and `afsmobile_flutter`, are covered without any change to either repository.** Their existing "Sign out" behavior already matches G2 (scoped to that device) once the mechanism is wired correctly; their existing SDK copy already exposes the everywhere-logout call, unused, for G8 | [05](05-native-clients-and-sdk-copies.md); acceptance A13, A14 |
| G8 | **A genuinely new capability: an explicit "log out of everywhere" action** that ends a primary user's session on every browser and every device at once — the feature the user asked be added, distinct from and never triggered by ordinary logout | [02](02-design.md) §6.2; acceptance A16, A17 — **✅ built and verified, [complete/02-design.complete.md](complete/02-design.complete.md)** |
| G9 | **`www`'s own, separate login system is untouched and stays that way.** Nothing in this plan routes `www`'s sign-in through Checkpoint's SSO ticket/cookie/revocation flow | [00](00-overview.md) §1, non-goals; acceptance A18 |
| G10 | **Ordinary logout propagates to every other app already open in the same browser** — not just to a future visit (G1/G2's original scope). If app A and app B are both open and authenticated, signing out of A also signs B out, without B needing a reload the user thinks to trigger, and without touching any other browser or device | [02](02-design.md) §7; [05](05-native-clients-and-sdk-copies.md) §9; acceptance A19, A20, A21 — **✅ built and verified live, [complete/02-design.complete.md](complete/02-design.complete.md)** |

## 3. Non-goals

- **Changing ticket-based token delivery.** `ssoLogin()` → redirect → `ssoExchangeToken()`
  keeps working exactly as today for every app. This plan adds a check *in front of* the
  credential form, not a replacement for the ticket mechanism.
- **Changing per-app token storage.** Each app's `localStorage` token is per-origin by
  browser design and stays that way — nothing here tries to share a token across origins.
- **Changing how secondary users sign in.** Secondary tokens are minted per app context and
  delivered directly via URL params, by design (see `checkpoint-server-auth/00-overview.md`
  §4 glossary). No code path this plan introduces reads or writes the SSO session cookie on
  the secondary path, and the everywhere-logout mechanism added for G8 is explicitly scoped
  away from secondary tokens (D9). G6 makes this isolation a *requirement*, not merely an
  area left alone.
- **Pulling `www` into the Checkpoint SSO system.** `www` has its own login and that is
  correct and intentional — not a gap, not a P2 to fix later. This plan's login-page,
  cookie, and revocation changes apply only to the seven apps in §1; `www`'s use of
  Checkpoint for other functionality is unaffected and out of scope for analysis here (G9).
- **Making ordinary logout reach other browsers or devices.** This was this plan's own
  earlier draft and is now explicitly rejected — see §1.3. Ordinary logout stays
  session-scoped, full stop; only the distinct action in G8 crosses that boundary.
- **Server-side bearer verification** (`checkpoint.member` middleware, app scoping,
  `CheckpointTokenValidator`). Entirely upstream of that work — this plan only affects what
  happens before any token exists, plus the one narrow addition of a revocation check inside
  `verifyToken()`/`refreshToken()` (D6), which every app already calls into unchanged.
- **OIDC/OAuth.** `routes/checkpoint.php`'s `authorization_endpoint`/`token_endpoint`
  metadata scaffolding is unrelated and untouched.
- **Deciding the SSO session cookie's idle lifetime beyond today's 30-day default**, or
  revoking it on a password change with no explicit logout. Flagged as an open question in
  [04](04-rollout-and-testing.md) §4, not resolved here.
- **Removing `SSO_MODE`/`getSsoMode()` (F6).** Dead but harmless; a cleanup candidate for a
  separate pass, not folded into this one.
- **Instant, server-push-based logout of an idle, already-open tab, for everywhere-logout
  (G8)**. It invalidates a token so the *next* request or reload elsewhere is rejected; it
  does not reach into an open tab that makes no request. See D8's rationale for why a
  front-channel/iframe-push mechanism was considered and not chosen **for that action.**
  G10 is a different mechanism solving a different problem: it is client-initiated polling
  (each app asks Checkpoint "is my cookie still good?" on load and on regaining focus), not
  a server push, and it only ever reacts to the SSO cookie's own presence — it has no
  awareness of the revocation watermark and cannot be used to detect an everywhere-logout
  faster than G8's own propagation bound (A17). D8's rejection was about adding a push
  channel to *every app* to serve one rare action (G8); G10 reuses a check every app
  already needed for G1 (§7), just polled instead of only checked once at login time.
- **Building a "log out everywhere" UI in every app.** G8 requires the *capability* to
  exist and be reachable; where it is surfaced (one app's account settings vs. several) is
  a product decision the design flags but does not mandate — see [02](02-design.md) §6.3.

## 4. Glossary

| Term | Meaning |
|---|---|
| **Checkpoint** | The IdP at `apps/auth`. Owns identity; mints and validates JWTs and SSO tickets. |
| **SSO ticket** | The existing, unchanged mechanism: a short-lived JWT handed to the browser on successful login, exchanged by the target app for its own access/refresh token pair. |
| **SSO session cookie** | `checkpoint_sso`, `HttpOnly`, set on Checkpoint's own domain after a successful login. Its scope is **one browser** — it is what makes G1 (silent sign-on) and the browser-scoped half of G2 (ordinary logout ends *this browser's* convenience) work. It is never shared across devices and never read by any app's JavaScript. |
| **Silent re-authentication ("silent reauth")** | The login page's new first step: ask Checkpoint whether this browser already has a valid SSO session cookie before ever rendering the credential form. |
| **Fail open (to the form)** | If the silent check errors, times out, or is ambiguous, the correct behavior is to show the credential form — exactly today's behavior — never to guess authentication. |
| **`target_domain`** | The app-supplied hostname a ticket (or, after this plan, a cookie-derived token pair) is scoped to. Already validated against `sso.allowed_domains` for the ticket path; the new cookie path reuses the same check. |
| **Ordinary logout** | The existing "Sign out" action in every app and native client. Scoped to **one session** — clears that app's own token, and (for a web app) clears that browser's SSO session cookie. Never touches a revocation watermark. This is G2. |
| **Everywhere logout** | The new, explicit, separate action (G8). Ends the primary user's session on every browser and device by writing the revocation watermark. Never triggered implicitly by ordinary logout. |
| **Revocation watermark** | A per-primary-user `invalidated_at` timestamp, written only by **everywhere logout**. A primary access/refresh token minted *before* that timestamp is rejected on its next verification, regardless of its own `exp`. |
| **`token_kind`** | A top-level JWT claim (`'normal'` or `'secondary_user'`), added to *both* access and refresh tokens so the revocation watermark can be consulted only for primary tokens without any ambiguity on the refresh path (closes F8). |
| **Logout propagation (within one browser)** | G10. Ordinary logout (above) already clears the SSO session cookie; this is every other app noticing that and reacting — clearing its own local session — without the user needing to trigger a fresh login-page visit there themselves. Distinct from everywhere logout: it never touches the revocation watermark and has no effect beyond the one browser it runs in. |

## 5. The nine decisions

| # | Decision | Choice | Rationale |
|---|---|---|---|
| **D1** | Keep ticket delivery, add cookie only as a pre-check | The SSO session cookie decides *whether to show the form*; it never becomes the thing an app receives. Ticket exchange is unchanged | Minimizes blast radius — the audited, working path (ticket exchange) is untouched; only the login page's very first branch changes. |
| **D2** | `SSO_COOKIE_DOMAIN` gets an explicit, correct value in every environment's own `_env/<mode>/.env` | Not left blank for a host-only cookie, even though the cookie is only ever read by `auth` itself today | Matches the existing convention (`CHECKPOINT_BASE_URL` etc. are already literal per-environment values). Detail in [03](03-env-variables.md). |
| **D3** | Ordinary `logout()` clears the SSO cookie and nothing more | Add `clearSsoCookie()` to `AuthenticationService::logout()`. **No** revocation-watermark write here | Directly closes F5, scoped correctly per G2: ending *this browser's* silent-reauth convenience is right; reaching any other browser or device from the ordinary "Sign out" button is not (§1.3). |
| **D4** | Roll out silent sign-on behind a new env flag, one environment at a time | `SSO_SILENT_REAUTH_ENABLED`, default `false`; `local` → `vagrant` → `dev` → `prod` | `local` and `vagrant` are the only environments where "verify end to end" is actually possible (`DEVELOPMENT_MODE.md`). |
| **D5** | Everywhere-logout is a **separate action**, not a side effect of ordinary logout | New `AuthController::logoutEverywhere()`, routed at `POST /checkpoint/auth/logout/all` — the exact path the client SDK already calls | This is the corrected design after the user identified the original draft's mistake: conflating "sign out of this app" with "sign out of everything" is the specific bug to avoid. Keeping them as two unmistakably different actions, with different server methods, is what makes that bug structurally hard to reintroduce. |
| **D6** | The revocation watermark is written **only** by `logoutEverywhere()`, and only checked in `verifyToken()`/`refreshToken()` when present | Ordinary `logout()` never writes it (D3); `verifyToken()`/`refreshToken()` check it for every primary token regardless of which action last touched it | This is what makes G2 true by construction rather than by convention: there is exactly one code path that can ever cause another session's token to be rejected, and it is not the one every "Sign out" button calls. |
| **D7** | Everywhere-logout's server route is the missing half of an already-existing client method | Wire `POST /checkpoint/auth/logout/all` to the client SDK's existing `logoutEverywhere()` (F9) rather than inventing a new endpoint name or a new SDK method | The client already calls this exact path in every copy, including `afsdesktop5`/`afsmobile_flutter`'s independent ports (verified, [05](05-native-clients-and-sdk-copies.md)). Completing the pairing needs zero SDK changes anywhere — matching this plan's recurring pattern of half-built plumbing. |
| **D8** | Everywhere-logout via a server-side revocation watermark, checked inside `verifyToken()`/`refreshToken()` — not a front-channel logout ping to each app | On everywhere-logout, upsert `invalidated_at = now()` for that primary user; every app's *existing* call to Checkpoint's token-verify/refresh endpoints starts rejecting that user's older tokens | Fits the architecture that already exists — every app already calls `auth` to verify, specifically because local JWT verification "cannot honour revocation" (`checkpoint-server-auth` D2). A front-channel approach would only reach apps with a tab open right now and needs a new route added everywhere — rejected as more moving parts for a benefit outside what was requested. |
| **D9** | Add a `token_kind` claim to every access **and refresh** token at mint time | `'normal'` for primary tokens, `'secondary_user'` for secondary ones, checked before ever consulting the revocation watermark | Closes F8. Without this, a primary user's numeric id and a secondary user's numeric id (drawn from different tables) could collide, and an everywhere-logout would risk also rejecting an unrelated secondary user's refresh token — a direct violation of G6. |
| **D10** | Propagate ordinary logout within one browser via client-initiated polling against a new pure-liveness endpoint, not a new server-push channel or a change to what ordinary `logout()` clears | New `GET /checkpoint/auth/sso/status`, checked by every app's `ClientHandler` once on page load and again whenever the tab becomes visible; a 401 clears that app's own local session and redirects to login | The shared `checkpoint_sso` cookie already carries exactly this information the moment `logout()` clears it (D3) — no new state to invent. Polling on load/focus catches the two realistic ways a user notices ("revisits a tab" or "switches back to one"), matches the fail-closed posture already established for G1 (§00 Glossary, "Fail open (to the form)" — inverted here to "fail closed to still-logged-in"), and needs no change to `logout()`, the watermark, or any other app's backend. |

## 6. Acceptance criteria

| # | Criterion |
|---|---|
| A1 | With a valid, unexpired SSO session cookie present, redirecting from any of the seven web apps to Checkpoint's login flow completes with the credential form never rendered, landing back at the originating app authenticated. |
| A2 | With no SSO session cookie (fresh browser profile, or after ordinary logout), the same redirect shows the credential form exactly as it does today — no regression for the common case. |
| A3 | Ordinary logout of any one app clears the `checkpoint_sso` cookie **for that browser only**; a subsequent redirect from a second app *in the same browser* shows the credential form again. |
| A4 | `SSO_COOKIE_DOMAIN` is set in all four of `auth/_env/{local,vagrant,dev,prod}/.env`, each a valid parent (or exact) domain of that environment's own `auth` `APP_URL` host. |
| A5 | In `vagrant` specifically (`auth.appfactory.test1`), the cookie set during login is confirmed present in the browser's own cookie store for that domain — not just assumed from server-side logs. |
| A6 | Setting `SSO_SILENT_REAUTH_ENABLED=false` in one environment's `.env` restores exactly today's behavior in that environment; no other environment is affected. |
| A7 | A silent-reauth request for a `domain` not present in `sso.allowed_domains` is rejected the same way the existing ticket-mode check rejects it. |
| A8 | No code path introduced by this plan reads or writes the SSO session cookie, or consults the revocation watermark, during any secondary-user login, logout, or token refresh. |
| A9 | Any error, timeout, or malformed response from the silent-reauth check results in the credential form being shown — never in a false "authenticated" state. |
| A10 | **Ordinary logout isolation, tested explicitly**: a primary user signed in on a browser AND on `afsdesktop5` (or `afsmobile_flutter`) logs out of the browser via the normal "Sign out" action; the native client's session is completely unaffected, and vice versa. This is the specific scenario the user named and this plan must not regress. |
| A11 | A primary user and a secondary user who independently happen to share the same numeric id in their respective tables can each log out (either kind) without affecting the other's tokens. |
| A12 | Setting `SSO_CROSS_APP_LOGOUT_ENABLED=false` in one environment restores today's behavior for the everywhere-logout endpoint (404, per D7's gating) in that environment, independent of `SSO_SILENT_REAUTH_ENABLED`. |
| A13–A15 | Native-client and SDK-copy specifics — defined in [05-native-clients-and-sdk-copies.md](05-native-clients-and-sdk-copies.md) §7. |
| A16 | Triggering the new everywhere-logout action from any one app or client ends the primary user's session on every other app, browser, and device — verified across at least one web app, one other web app in a *different* browser, and one native client, in the same test pass. |
| A17 | The propagation bound for everywhere-logout is the existing bearer-verification cache window (`checkpoint-server-auth` F7/A9: ≤60s for a positive cached result) for an actively-used session, or "next request/reload" for an idle one — stated as the actual behavior, not glossed over as "instant." |
| A18 | `www`'s own login flow is unmodified by this plan; `www/resources/js/checkpoint/` and its consumers (`globalAuth.ts` and its `portal/` counterpart) are untouched. |
| A19 | With app A and app B both open and authenticated in one browser, signing out of A causes B to clear its own local session and land on the login page the next time B's tab is reloaded or revisited — without B's user clicking anything in B. |
| A20 | Setting `SSO_LOGOUT_PROPAGATION_ENABLED=false` restores today's behavior (B stays authenticated until its own token naturally expires) independent of the other two SSO flags; a network error or non-401 response from `/sso/status` never logs a still-valid session out. |
| A21 | Native-client specifics for G10 — defined in [05-native-clients-and-sdk-copies.md](05-native-clients-and-sdk-copies.md) §9. |

## 7. Repository map

See [README.md](README.md). Silent sign-on and both kinds of logout (§§1–6) land only in
`apps/auth`. Logout propagation (G10, §7) additionally touches the shared `checkpoint/`
client SDK, which is why it — and only it — is also copied into all seven web apps sharing
that SDK; the two native clients were checked, not assumed, to need nothing for it either;
[05](05-native-clients-and-sdk-copies.md)
is that verification. `www` is excluded entirely per G9 — it is not one of the seven, and
none of its own login code is in scope.

## 8. Relationship to other plans

- **[checkpoint-server-auth/00-overview.md](../checkpoint-server-auth/00-overview.md)** §3
  lists "changing how login works" as an explicit non-goal and states the browser-side
  Checkpoint SSO flow "is fine and is not touched." This plan is the first one to actually
  change it — narrowly, and without touching anything that plan's audit covered (bearer
  verification, app scoping, the five/two-pattern split). The two plans do not conflict:
  that one is about what the *server* does with a token once minted; this one is about
  whether the browser has to ask for a new one at all, and about what "logged out" actually
  invalidates.
