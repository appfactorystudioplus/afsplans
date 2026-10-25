# 05 — Authentication

The core the module does not currently have: a session that means something, and the
lifecycle around it.

## 5.1 What is being replaced

[01](01-current-state-audit.md) §1.2: `login()` writes a 64-character string to
`users.auth_token` and returns it. **No code anywhere reads it** (**M2**). There is no
middleware, no `/me`, no logout, and the 30-day expiry it sets is never checked (**M13**).

So this document is not hardening an auth system. It is building the first one.

## 5.2 Sessions

Login issues an opaque token; the server stores only its SHA-256 hash
([04](04-database-schema.md) §4.5).

```
POST /api/siteusers/public/login
  { module: 'su-a1b2', email, password }
→ { token: '<64 hex>', expires_at, user: {…} }
```

The plaintext token is returned **once**, at issue. It exists in the response body and
nowhere else on the server, ever.

One row per login, so a user can be signed in on a phone and a laptop simultaneously — the
current single-column design silently revokes the older session (**M8**), which for a
shopping site means being logged out by your own second tab.

### The guard

`SiteUsersSessionGuard` — middleware on every authenticated public route, and the
implementation behind `SiteUsersGateway::currentUser()`
([03](03-architecture.md) §3.5):

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/site_users/05-authentication.md §5.2.
 *
 * Resolves the site user for this request, or null.
 *
 *   Authorization: Bearer <token>   (or the su_session cookie — §7.4)
 *     → sha256
 *     → laravel_site_user_sessions.token_hash
 *     → not revoked, not expired, module active, user active
 *     → the user
 *
 * Returns NULL for every failure. It never throws and never redirects: a
 * consuming module decides what "not signed in" means for it, and half of them
 * legitimately treat it as "guest" (09-app-integrations.md §9.3).
 *
 * Timing: the lookup is a single indexed hash comparison, so a valid and an
 * invalid token cost the same. Do not add a "does this user exist" branch
 * before it.
 */
```

`last_seen_at` is updated at most once per 5 minutes, not per request — a write on every
authenticated page load is a lot of contention for a field nobody reads in real time.

### Logout and revocation (**M12**)

| Action | Effect |
|---|---|
| `POST /public/logout` | Sets `revoked_at` on the calling session only |
| `POST /public/logout-all` | Revokes every session for the user |
| Password change | Revokes **every other** session, keeps the current one |
| Password reset | Revokes **every** session, including the current one |
| Admin deactivates the user | Revokes every session |

The password-reset row is the important one: a reset is the flow someone uses when they
believe their account is compromised, so it must log the attacker out even though the
attacker may be holding a valid session.

## 5.3 Why opaque tokens and not JWTs (**N10**)

A JWT would let the guard skip a database read. It would also make revocation impossible
without a denylist — which is a database read, reintroducing the cost while keeping the
complexity.

This module's entire purpose is letting a tenant control who is signed in to their site:
deactivate a user, force a logout, revoke a device. Every one of those is a revocation.
Choosing a token format whose defining property is that it cannot be revoked, and then
building a denylist to undo that, is a worse version of a sessions table.

## 5.4 Passwords

| Rule | Value | Where |
|---|---|---|
| Minimum length | `modules.password_min_length`, default **12** | [04](04-database-schema.md) §4.2 |
| Hashing | `Hash::make` (bcrypt/argon per app config) | §4.4 |
| Composition rules | **None** | Below |
| Breach check | Optional, off by default | Below |

**No composition rules** — no "must contain a symbol". Length is the property that matters;
composition rules push people toward `Password1!` and a password manager's output already
satisfies any rule worth having. NIST dropped them and so does this.

The policy lives **server-side** and is served to the SDK as data, fixing **M17**: today
`RegistrationFlow` embeds its own validation, so a tenant cannot change it and it can
disagree with the server. The client validates for *feedback*; the server validates for
*truth*, and the server's answer is the one that decides.

A breach-corpus check (k-anonymity range query) is worth having and is **off by default**:
it is an outbound call on a registration path, and a tenant should opt into that dependency.

## 5.5 Email verification and password reset (**M9**, **M10**)

> **Extended by [13](13-identity-and-email-change.md) §13.4 (2026-09-12).** A third purpose,
> `email_change`, reuses this exact mechanism: the pending address is the verification row's
> `sent_to`, and the account's email is not touched until the token is consumed. No schema
> change — that is the point of having built verification as a purpose-tagged token.

Both ride [04](04-database-schema.md) §4.6's one table.

**Verification.** On registration, if `modules.require_email_verification`, issue a 24-hour
token and send it. Until consumed, `email_verified_at` is null.

What "unverified" *means* is the module's choice, and the default matters:

- The user **can** sign in.
- `currentUser()` returns them, with `email_verified_at === null` visible.
- A consuming module decides whether that is enough — ecommerce may allow browsing and block
  checkout; a discussion board may block posting.

Blocking login outright is the obvious design and the wrong one: it turns a mistyped address
into a dead account with no recovery path, and it is the module's job to expose the fact, not
to make the decision for nine different consumers.

**Reset.** `POST /public/password/forgot` **always** returns 202, whether or not the address
exists — a distinguishing response is a user-enumeration oracle. One-hour token, single use,
tied to `sent_to` (§4.6), and consuming it revokes every session (§5.2).

## 5.6 Rate limiting and origin control (**M11**)

Nothing today limits `login`, `register` or `ssoExchange`. With **M3** unfixed, `ssoExchange`
is an unlimited account-creation endpoint.

| Endpoint | Limit |
|---|---|
| `login` | 5 / 15 min per (module, email), 20 / 15 min per (module, IP) |
| `register` | 3 / hour per IP |
| `password/forgot` | 3 / hour per (module, email), 10 / hour per IP |
| `sso/exchange` | 10 / 15 min per IP |
| `verify` / `password/reset` | 10 / hour per IP |

Both keys on login, deliberately: per-email alone lets one IP spray a user list; per-IP alone
lets a botnet grind one account.

**Failures return the same shape and the same timing regardless of cause** — "no such user"
and "wrong password" are indistinguishable ([03](03-architecture.md) §3.6).

**Origin enforcement.** Every public endpoint checks `Origin` against
`modules.allowed_origins` ([04](04-database-schema.md) §4.7) *before* reading any credential.
Null means same-origin only. This is server-side, not CORS — CORS is a browser courtesy that
a `curl` ignores, and the module id is public by design.

## 5.7 The endpoint surface

Public — `/api/siteusers/public/*`, rate-limited and origin-checked:

| Method | Path | Notes |
|---|---|---|
| `GET` | `/config` | Module's public config: which identities are allowed, password policy, SSO providers. **No secrets** |
| `POST` | `/register` | |
| `POST` | `/login` | |
| `POST` | `/logout` | Session guard |
| `POST` | `/logout-all` | Session guard |
| `GET` | `/me` | Session guard. **Does not exist today** — the gap behind **M2** |
| `PATCH` | `/me` | Session guard. `display_name`, `username` |
| `POST` | `/me/password` | Session guard, requires current password |
| `GET` | `/me/sessions` | Session guard. Lists devices |
| `DELETE` | `/me/sessions/{id}` | Session guard |
| `POST` | `/email/verify` | Token in body |
| `POST` | `/email/resend` | Rate-limited hard |
| `POST` | `/password/forgot` | Always 202 |
| `POST` | `/password/reset` | Token + new password |
| `GET` | `/sso/{provider}/start` | [06](06-sso.md) |
| `POST` | `/sso/{provider}/callback` | [06](06-sso.md) — **the code exchange**, not **M3**'s claim |

Admin — `/portal/api/siteusers/*`, behind `ApiAuthMiddleware`, unchanged in shape from
today plus module CRUD ([10](10-admin-dashboard.md)).

## 5.8 Acceptance

- A token issued by `login` authenticates `GET /me`; a tampered one returns 401. **This is
  the test that does not pass today** (**M2**).
- `laravel_site_user_sessions.token_hash` never equals a token that was returned to a
  client (**M4**).
- Logging in on two devices leaves both working (**M8**).
- Changing a password keeps the current session and kills the others; resetting kills all.
- An expired session returns 401 rather than working (**M13**).
- 6 failed logins in 15 minutes for one email return 429 (**M11**).
- `password/forgot` returns 202 in the same time for a real and a fake address.
- A reset link is dead after use, after 1 hour, and after a newer one is issued.
- Requesting a reset, changing the account email, then redeeming the old link **fails**
  (§4.6's `sent_to`).
- A public call with a disallowed `Origin` returns 403 before any credential is read, and
  the same call with `curl` and no `Origin` is also refused when origins are configured.
- Registration with a 10-character password fails against a module whose minimum is 12, from
  the **server**, even when the SDK is patched to skip its own check.
