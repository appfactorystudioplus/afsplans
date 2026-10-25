# 05 — Authentication (complete)

**Plan:** `plans/www/site_users/05-authentication.md`
**Rollout phase:** P2 (sessions) + P3 (lifecycle)
**Repo:** `www`
**Date:** 2026-08-31, extended 2026-09-05

## §5.2–§5.3 — sessions (P2)

- **Opaque tokens, SHA-256 hash stored** (`laravel_site_user_sessions.token_hash`,
  char(64) unique). Plaintext returned once, at issue, and nowhere on the server.
- **`SiteUsersSessionGuard`** middleware (`site_users.session`) + the resolution behind
  `SiteUsersGateway::currentUser()`. Returns null on every failure, never throws.
- One row per login — two devices, two live sessions.
- `last_seen_at` throttled to one write / 5 min.
- **Logout / revocation** (`SiteUsersSessionService::revokeAllForUser($id, $exceptId)`):
  logout revokes the calling session; logout-all and admin-deactivate revoke all; a password
  **change** keeps the current session and kills the rest; a password **reset** kills all.

**Files:** `app/SiteUsers/SiteUsersSessionService.php`, `app/Http/Middleware/SiteUsersSessionGuard.php`.

## §5.4–§5.6 — lifecycle (P3)

| Area | State |
|---|---|
| Password minimum length | Served from `modules.password_min_length` (default 12), enforced **server-side** in `SiteUsersAuthService`; the SDK reads it from `config()` for feedback only (M17) |
| No composition rules | ✅ length only, per NIST / §5.4 |
| Breach-corpus check | ⚠️ **not built** — §5.4 marks it optional and off by default; deferred (see below) |
| Email verification | ✅ 24h token on registration when `require_email_verification`; user can still sign in, `currentUser()` exposes `email_verified_at === null` for the consuming module to act on |
| Password reset | ✅ `POST /password/forgot` always 202; 1h token; single use; tied to `sent_to`; consuming it revokes every session |
| `POST /email/resend` | ✅ **new 2026-09-05** — always 202, re-issues only for an existing unverified user |
| Rate limiting | ⚠️ `site_users.origin` + `site_users.public.throttle` middleware present on every public route; per-endpoint limits from §5.6 are **coarser than the table** — see below |
| Origin enforcement | ✅ `SiteUsersOriginMiddleware` checks `Origin` against `modules.allowed_origins` before any credential is read; null = same-origin |

### Email delivery — now real (2026-09-05)

The stub is gone. `SiteUsersVerificationService::deliver()` sends `App\Mail\SiteUsers\SiteUsersTokenMail`
(view `resources/views/mail/site_users/token.blade.php`) through Laravel's mailer. A
transport failure is logged, not fatal — the token is already persisted and re-sendable.

Locally `MAIL_MAILER=log`, so the link lands in `storage/logs/laravel.log`. The link points
at `config('app.url') . '/verify-email?token=…&module=…'` (resp. `/reset-password`).

> **Refinement worth making:** the link base is `config('app.url')`. For the standalone SDK
> case (07 §7.1) the user may have registered from a different origin; capturing the
> registration `Origin` and using it, or a per-module `public_base_url`, would be more
> correct. Not blocking — noted in deferred.

## Acceptance — §5.8

| Criterion | State |
|---|---|
| A `login` token authenticates `GET /me`; a tampered one 401s | ✅ `SiteUsersAuthFeatureTest`, `SiteUsersSessionServiceTest` |
| `token_hash` never equals a returned token | ✅ `SiteUsersSessionServiceTest` |
| Two devices both work | ✅ session model is one-row-per-login |
| Password change keeps current, kills others; reset kills all | ✅ `SiteUsersAuthService` / `SiteUsersVerificationService` |
| Expired session → 401 | ✅ guard checks `expires_at` |
| 6 failed logins / 15 min for one email → 429 | ⚠️ throttle middleware fires, but at the middleware's default rate, not the §5.6 per-(module,email) + per-(module,IP) split |
| `password/forgot` same time/shape for real vs fake | ✅ always 202, no branch before the response |
| Reset link dead after use / 1h / supersede | ✅ `SiteUsersVerificationFeatureTest` |
| Reset, change email, redeem old link → **fails** | ✅ `SiteUsersVerificationFeatureTest` (`sent_to`) |
| Disallowed `Origin` → 403 before credential read | ✅ `SiteUsersOriginMiddleware` |
| 10-char password vs 12-char module, SDK bypassed → server rejects | ✅ policy is server-side in `SiteUsersAuthService` |

## Deferred

| # | Work | Why it's not blocking |
|---|---|---|
| 1 | Per-endpoint rate limits exactly as §5.6's table (per-(module,email), per-(module,IP), the different budgets per route) | A throttle is in place on every public route; the refinement is granularity |
| 2 | Breach-corpus (HIBP k-anonymity) check, module-toggled, off by default (§5.4) | §5.4 itself says optional and opt-in |
| 3 | Verification-link base URL: use the registration `Origin` or a per-module base rather than `config('app.url')` | Works for builder-published and same-origin standalone sites today |

## Verification

```bash
cd www
php artisan test --filter="SiteUsers"
```

33 tests passing as of 2026-09-05, including `SiteUsersVerificationFeatureTest`
(mail assertions via `Mail::fake()`).

**Files:** `app/SiteUsers/SiteUsersAuthService.php`, `SiteUsersSessionService.php`,
`SiteUsersVerificationService.php`, `app/Mail/SiteUsers/SiteUsersTokenMail.php`,
`app/Http/Controllers/Externals/SiteUsers/SiteUsersPublicController.php`,
`app/Http/Middleware/SiteUsers{SessionGuard,OriginMiddleware,PublicThrottle}.php`.
