# 06 — SSO (complete)

**Plan:** `plans/www/site_users/06-sso.md`
**Rollout phase:** P4 — SSO
**Repo:** `www`
**Date:** 2026-08-31 (Google, unverified), rebuilt 2026-09-05

## §6.1 — M3 is closed

`ssoExchange()` and its `provider_id` input are gone — the route is unregistered in both
route files and the controller method returns `503 SU_SSO_DISABLED` if it is ever re-wired
(`11-rollout-and-testing.complete.md`). **No endpoint, public or admin, accepts a
client-asserted identity.**

## §6.2 — the replacement, as built

`app/SiteUsers/SiteUsersSsoService.php` runs the authorization-code flow with the exchange
**server-side**. `app/SiteUsers/Support/JwtTools.php` does RS256 verification and ES256
signing by hand — the estate ships no JWT library and `AGENTS.md` §1 forbids adding one, so
`JwtTools` implements exactly the two operations the flow needs, against `openssl`.

| §6.2 check | Where |
|---|---|
| `state` matches the stored value | `completeCallback()` — `hash_equals` against the server-side record |
| PKCE `code_verifier` ↔ `code_challenge` (S256) | generated at `/start`, sent in the token exchange |
| ID-token **signature** vs the provider JWKS | `JwtTools::verifyRs256()` — rejects `alg` ≠ RS256 (kills `none` and algorithm confusion) |
| `iss` is the expected issuer | checked against a per-provider allow-list |
| `aud` equals our `client_id` | checked — the one most often skipped |
| `exp` future, `iat` recent | checked with 60s leeway |
| `nonce` matches | generated at `/start`, stored server-side, checked |

JWKS is cached 24h and **force-refreshed once** on a verification failure (providers rotate
keys without notice).

### §6.3 — state storage

`state`, `nonce` and the PKCE verifier are generated at `/start` and stored **server-side**,
keyed by an opaque handle carried in a short-lived (`10 min`) httpOnly `su_sso` cookie —
`SameSite=None; Secure`, so it survives Apple's cross-site `form_post`. A cache pointer
`state → handle` is the fallback when the cookie is dropped; the `state` value is itself
unguessable, so CSRF protection does not depend on the cookie.

### Callback is POST-only

`Route::post('/sso/{provider}/callback')` — the `GET` form is gone (it put `code` in the URL,
i.e. in logs and `Referer`). The SDK's `sso(provider).complete()` reads `code`/`state` from
the return URL and POSTs; Apple posts its `form_post` here directly.

## §6.4 — per provider

| Provider | State |
|---|---|
| **Google** | ✅ Full OIDC. `sub` is the id, not `email`. Verified end to end in `SiteUsersSsoFeatureTest` (token endpoint + JWKS faked). |
| **Apple** | ✅ Built. ID token RS256 against Apple's JWKS (same verifier). `client_secret` is an **ES256 JWT minted at call time** by `JwtTools::signEs256()` from the `.p8` key + `team_id`/`key_id` in `sso_integrations.provider_meta` — never a stored literal (§6.4). Name captured from the first-authorization `user` body. **Not verifiable on this machine** — no Apple developer app. |
| **Facebook** | ✅ Built. Token exchange, then Graph `/me?fields=id,name,email` with `appsecret_proof`. Email may be absent → `email = null`, which is why the column is nullable. **Not verifiable on this machine.** |

New column: `laravel_site_user_sso_integrations.provider_meta` (json) for Apple's team/key id.

## §6.5 — account linking

In `SiteUsersSsoService::resolveUser()`:

- Existing identity for `(module, provider, sub)` → that user, `last_used_at` bumped.
- No identity, `provider_email` matches a **verified** local account → **link** (add identity),
  user count unchanged. Verified in `SiteUsersSsoFeatureTest`.
- Matches an **unverified** local account:
  - provider asserts the email verified → provider wins: link **and** mark the local
    account verified.
  - provider does not assert → **separate account**, logged as a possible duplicate. Not a
    silent merge. Verified in `SiteUsersSsoFeatureTest`.
- `unique(site_users_id, type, provider_id)` + `firstOrCreate` guard the race.

## Acceptance — §6.7

| Criterion | State |
|---|---|
| Posting a `provider_id` authenticates nobody | ✅ parameter does not exist |
| Callback with mismatched `state` fails | ✅ `SiteUsersSsoFeatureTest` |
| ID token with wrong `aud` fails | ✅ `SiteUsersSsoFeatureTest`, `JwtToolsTest` |
| ID token with an unknown signing key fails; rotated key succeeds after refresh | ✅ verifier logic + `JwtToolsTest`; the refresh path is `jwks(..., forceRefresh: true)` |
| Replayed `code` fails on second use | ✅ the state handle is `Cache::pull` (single use); Google also rejects a reused code |
| Google login, verified matching email → identity added, count unchanged | ✅ `SiteUsersSsoFeatureTest` |
| Same, unverified account → no link | ✅ `SiteUsersSsoFeatureTest` |
| Facebook with no email → session, `email = null` | ✅ code path; not run against live Facebook |
| Apple `client_secret` generated at call time | ✅ `appleClientSecret()` → `JwtTools::signEs256`; `JwtToolsTest` checks the ES256 shape |
| No endpoint returns `client_secret_encrypted` or its plaintext | ✅ `toAdminArray()` → `has_secret` bool |

## Not verifiable locally

Apple and Facebook are written to spec but need real provider apps to exercise. Google is
fully covered by faked HTTP. When Apple/Facebook credentials exist, run one live login each
and confirm: Apple name-on-first-auth capture, Facebook no-email path.

## Verification

```bash
cd www
php artisan test --filter="SiteUsers|JwtTools"
```

**Files:** `app/SiteUsers/SiteUsersSsoService.php`, `app/SiteUsers/SsoException.php`,
`app/SiteUsers/Support/JwtTools.php`, `app/Http/Controllers/Externals/SiteUsers/SiteUsersPublicController.php`
(`ssoStart`/`ssoCallback` now thin), `app/Models/Externals/SiteUsers/SiteUserSsoIntegration.php`,
the schema migration (`provider_meta`), `routes/portal.php`, `routes/auth1.php`.
