# 02 — Authentication: the device token

## Completion document

Built 2026-10-04; server tests and the lab exit check pending: [complete/02-authentication-device-token.complete.md](complete/02-authentication-device-token.complete.md).

## 2.1 The requirement

> "A mechanism that does not require any log in but that's already logged into the brain member."

The app acts as the Brain member **`appfactorystudio`** from the first frame: no login screen, no password
field, no session that expires overnight. The credential must work against the lab today and against
production when the estate is published, switched by `.env` like everything else.

## 2.2 Options considered

| Option | How it would work | Verdict |
|--------|-------------------|---------|
| **A. Device token** (chosen) | A row in `laravel_user_tokens` for user 1, no expiry (or a long one), minted by a command you run, sent as `Authorization: Bearer` | ✅ Accepted unchanged by `ApiAuthMiddleware` and `PortalAdminAuthMiddleware`, hence by every route the app needs, including `portal.level99` ([BA2](01-current-state-audit.md), [BA3](01-current-state-audit.md)) |
| B. `login-superadmin` | App calls `POST /portal/api/auth/login-superadmin`; server trusts the caller's IP | ❌ `SUPER_ADMIN_APPROVED_IPS` holds one public address; a phone on mobile data or another Wi-Fi never matches. Tokens it mints last 1–30 days ([BA2](01-current-state-audit.md)) |
| C. Ship the password in `.env`, log in at startup | App posts `identifier`+`password` to `/auth/login` | ❌ Types credentials into a login form (a standing rule forbids *me* doing it; the app doing it automatically is the same pattern with the password stored in the APK), and re-logging every launch fills `laravel_user_tokens` |
| D. HMAC service key (`afs_connect.service`) | App signs requests like `coordinator` does | ❌ That middleware guards only `/brain/entitlement/*`. The admin routes would each need a second authentication path |
| E. Direct database | Phone connects to MySQL | ❌ [BF2](00-overview.md): bypasses Checkpoint registration, audit and dunning |
| F. Checkpoint SSO | Redirect to `auth` and back | ❌ It *is* a login |

## 2.3 The design

```
   you (a terminal on the Brain)                       the phone
   ────────────────────────────                       ─────────
   php artisan brain:device-token \
        --label="pixel" --days=180
        │
        ▼
   creates laravel_user_tokens row  ──────────►  64-char token, user_id = 1,
   (user 1, expires_at NULL | now+N)              prints ONE line, once:
   + brain_device_tokens registry row               BRAIN_DEVICE_TOKEN=…
        │
        ▼
   you paste that line into
   afsbrain_flutter/_env/<mode>/.env      ──► use-env copies it to ./.env ──► flutter build
                                                                                  │
                                                                                  ▼
                                                              Authorization: Bearer <token>
                                                              on every request, from the first
```

Properties:

- **One token per environment.** lab2 and production have different databases, so a lab token is meaningless
  on production and vice versa; each `_env/<mode>/.env` carries its own.
- **It is a normal portal token.** Nothing in `www`'s authentication changes; the same `logout` call that kills a
  browser session would kill it, so the app has no logout button.
- **It resolves to the Brain member.** `PortalAdminAuthMiddleware` loads `Membership::where('user_id', 1)` →
  `appfactorystudio`, level 99 — which `portal.level99` then verifies **from the database row**, not from anything
  the phone says.
- **You mint it; the agent never does.** Per the standing rule, the implementing agent writes the command and its
  tests, and tells you to run it. It never reads, types or generates the token value, and never edits a live `.env`.

## 2.4 The command — server change **BS1**

`php artisan brain:device-token` in `w/www/app/Console/Commands/`, plus one migration.

```
brain:device-token
    {--member=appfactorystudio   : brain_memberships.reference_num to act as (must be level 99)}
    {--label=                    : free text, e.g. "pixel-8" (required when creating)}
    {--days=                     : lifetime in days; omit for a token that does not expire}
    {--list                      : show registered device tokens (id, label, member, created, expires, revoked)}
    {--revoke=                   : registry id to revoke (deletes the underlying laravel_user_tokens row)}
    {--revoke-all                : revoke every device token for --member}
```

Behaviour, in order:

1. **Refuses unless this is the Brain** (`config('brain.is_brain')` true) — a tenant has no `brain_memberships`.
2. **Resolves `--member`** → `brain_memberships` row; refuses unless `level === 99` (exactly, as
   `PortalLevelNinetyNineMiddleware` does) and `user_id` is set and the user is `active`.
3. **Creates** `Str::random(64)` (identical in shape to `AuthController::login`'s tokens, `varchar(80)` column),
   `UserToken::create(['user_id'=>…, 'token'=>…, 'expires_at'=> --days ? now()->addDays(N) : null])`.
4. **Registers** a `brain_device_tokens` row (below) and prints, **once**, exactly:
   `BRAIN_DEVICE_TOKEN=<token>` followed by the expiry and the id. The token is never logged and never written to
   `storage/logs`.
5. `--list` never prints a token. `--revoke` deletes the `laravel_user_tokens` row and stamps `revoked_at`.

New table (`brain_*` prefix = control-plane, owned by `www`; migration dated when written):

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned PK | the id `--revoke` takes |
| `user_token_id` | bigint unsigned null | → `laravel_user_tokens.id`; null after revoke |
| `member_reference_num` | varchar(64) | `appfactorystudio` |
| `label` | varchar(120) | |
| `expires_at` | timestamp null | copy of the token's, so `--list` works after the token row is gone |
| `revoked_at` | timestamp null | |
| `created_at`, `updated_at` | timestamp | |

A registry row is **only a label**: authentication never reads this table, so a bug here cannot lock the app out.

## 2.5 What the app does with it

**Startup handshake** ([05](05-app-architecture.md) §5.4), before the first screen renders:

| Step | Request | Outcome |
|------|---------|---------|
| 0 | `.env` has a non-empty `BRAIN_DEVICE_TOKEN` | else → **Not paired** screen |
| 1 | `GET {PORTAL_API_URL}/auth/me` | `200` → token valid. `401` (`AUTH_INVALID_TOKEN` / `AUTH_TOKEN_EXPIRED` / `AUTH_MISSING`) → **Token rejected** screen |
| 2 | `GET {PORTAL_API_URL}/v1/brain/staff/whoami` ([04](04-server-additions.md) BS2) | `200` → store `capabilities`, show the app. `404` → **Not the Brain member** screen (token valid but not level 99). `Brain-only` features simply absent |
| 3 | `member.reference_num == BRAIN_MEMBER_REF` | mismatch → **Wrong identity** screen (guards against pasting a different member's token) |

The three failure screens contain **no form**. They show the environment label, the failure, and the exact command
to run on the Brain (`php artisan brain:device-token --label=…`) with a copy button, and a *Retry* button.

During normal use any `401` re-runs the handshake and lands on **Token rejected** — the app never keeps retrying
with a dead token, and never prompts for a password.

## 2.6 Lifetime, rotation, loss

- **Lab modes** — no expiry; nothing to renew.
- **`production`** — recommended `--days=180` ([OQ1](00-overview.md)); the app shows "token expires in N days" on
  Settings from `whoami.token.expires_at`, and the **Token rejected** screen says "expired" vs "revoked" using the
  `401` code.
- **Rotation** — mint a new token, replace the line in `_env/production/.env`, rebuild, install; then
  `--revoke=<old id>`. There is a window where both work, by design.
- **Lost or stolen phone** — `php artisan brain:device-token --revoke-all`. The next request from the phone is a
  401; no password changes anywhere.

## 2.7 Where the token is exposed, and what limits it

The token is in the APK's bundled `.env` asset ([03](03-environments-and-config.md) §3.3) — that is the price of
"no login, no first-run step". It is the full power of the Brain member, so on **production** (where the data is
real) the plan applies these, all cheap and none a login:

| Measure | Where |
|---------|-------|
| `_env/**/.env` is **gitignored**; only `.env.example` is committed | [03](03-environments-and-config.md) §3.2 |
| `android:allowBackup="false"` so `adb backup` cannot lift the asset | [11](11-android-build-and-deploy.md) §11.3 |
| Production release is signed with your own keystore (`key.properties`, gitignored) and installed only by you | [11](11-android-build-and-deploy.md) §11.4 |
| **App lock** — device biometric/PIN on cold start, `APP_LOCK=true` in the production `.env` ([OQ6](00-overview.md)) | [10](10-ui-and-navigation.md) §10.6 |
| A finite `--days` and `--revoke-all` | §2.6 |
| The token is only ever sent to `PORTAL_API_URL`'s host; the HTTP client refuses a redirect to another host | [05](05-app-architecture.md) §5.4 |

The lab is private and disposable; no lab hardening is proposed.

## 2.8 Tests

- **Server (PHPUnit, real MySQL, not SQLite):** command refuses on a non-Brain; refuses a non-level-99 member;
  refuses an inactive user; creates a token that `ApiAuthMiddleware` and `PortalAdminAuthMiddleware` both accept and
  that `portal.level99` passes; `--days` sets `expires_at`; `--list` prints no token; `--revoke` makes the token a
  401; no token string appears in captured log output.
- **App:** handshake state machine against a fake client for each row of §2.5; assert the failure screens contain no
  text field.
- **Lab check (P0 exit):** with a freshly minted lab token in `.env`, the app reaches the Members screen and the header shows
  `appfactorystudio` and the `LAB2` badge.
