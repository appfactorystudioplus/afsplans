# 06 — SSO

Google, Facebook and Apple. The current implementation is an authentication bypass; this
document is mostly about why, and what replaces it.

## 6.1 What is wrong today (**M3**)

`SystemsUsersPublicController::ssoExchange()`:

```php
$validated = $request->validate([
    'systemsusers_id' => 'required|string',
    'provider_id'     => 'required|string',   // ← the client says who they are
    'email'           => 'nullable|email',
    'username'        => 'nullable|string|max:80',
]);

$user = SystemsUsersUser::where('systemsusers_id', $systemsusersId)
    ->where('provider', $provider)
    ->where('provider_id', $validated['provider_id'])
    ->first();

if (!$user) { /* create it */ }

$token = Str::random(64);   // ← and receives a session
```

**There is no exchange.** Nothing contacts Google. No authorization code is redeemed, no ID
token is verified, no signature is checked, no audience or issuer is validated. The endpoint
takes the client's word for the single fact that matters — *which account is this* — and
issues a session for it.

Two attacks, neither requiring anything but a URL:

1. **Impersonation.** A provider subject id is not a secret; it appears in ID tokens, in
   analytics, in any app that logs it. Post someone's and receive their session.
2. **Enumeration and mass creation.** Post arbitrary ids and create accounts without limit —
   and with **M11** there is no rate limit either.

`ssoConfig()` hands out the `client_id`, so the attacker does not even need to guess which
provider is configured.

**This is the finding that gates the plan** ([00](00-overview.md) §5, **N1**). It cannot be
mitigated by rate limiting or origin checks; the flow is wrong.

## 6.2 The replacement (**N6**)

The authorization-code flow, with the exchange **server-side**:

```
browser                    our server                     provider
   │                            │                              │
   │  GET /sso/google/start ───▶│                              │
   │◀── 302 to provider ────────│  (state + PKCE stored)       │
   │─────────────────────────── consent ─────────────────────▶│
   │◀────────── redirect back with ?code&state ────────────────│
   │  POST /sso/google/callback │                              │
   │      { code, state } ─────▶│                              │
   │                            │── code + client_secret ─────▶│
   │                            │◀── id_token + access_token ──│
   │                            │  verify signature, iss,      │
   │                            │  aud, exp, nonce             │
   │◀── session token ──────────│                              │
```

**The client never supplies an identity.** It supplies a `code` — a single-use credential
that is worthless without our `client_secret`, which never leaves the server.

### What must be verified, every time

| Check | Why |
|---|---|
| `state` matches the stored value | CSRF on the callback |
| PKCE `code_verifier` matches the `code_challenge` | Protects the code in transit through the browser |
| ID token **signature** against the provider's JWKS | The whole basis of trust |
| `iss` is the expected issuer | |
| `aud` equals our `client_id` | **An ID token minted for a different app is otherwise accepted** — the classic mistake |
| `exp` in the future, `iat` recent | Replay |
| `nonce` matches the stored value | Replay, specifically token injection |

The `aud` check is the one most often skipped and the most damaging: without it, any ID token
from the same provider for *any* application authenticates here.

JWKS is fetched and cached (TTL ~24h) with a forced refresh on an unknown `kid`, because
providers rotate keys without notice.

## 6.3 State storage

`state`, `nonce` and the PKCE verifier are generated at `/start` and stored **server-side**,
keyed by an opaque handle in a short-lived httpOnly cookie. Not in `localStorage` — a value
whose job is to be unguessable by the page's own scripts should not be readable by them.

10-minute TTL, single use, deleted on redemption.

## 6.4 Per-provider notes

| Provider | Notes |
|---|---|
| **Google** | Standard OIDC. JWKS at the documented endpoint. `sub` is the stable id — **not** `email`, which the user can change |
| **Facebook** | Not true OIDC; token is exchanged then the profile read from the Graph API. Verify with `appsecret_proof`. Email may be **absent** — an account with no email is normal here, which is why `users.email` stays nullable ([04](04-database-schema.md) §4.3) |
| **Apple** | ID token is a real JWT — verify it. **The name is sent only on first authorization, never again**, so if it is not captured then it is gone. `email` may be a private relay address; treat it as a real address, do not try to resolve it. `client_secret` is a **signed JWT that expires** (≤6 months) and must be regenerated, not stored as a static string — this is where Apple integrations break months later |

Apple's two quirks are the ones that cause silent, delayed failures, so both belong in the
implementation's comments, not just here.

## 6.5 Account linking

An SSO login whose verified `provider_email` matches an existing user's `email` **links** —
adds an identity row ([04](04-database-schema.md) §4.4) rather than creating a second account.

**Only when the existing account's `email_verified_at` is not null.** Otherwise: register
`victim@example.com` with a password, never verify it, wait for the real owner to sign in
with Google, and inherit their account. The unverified-email account is the attacker's
claim, not the owner's.

If the existing account is unverified, the flows diverge:

- Provider says the address is verified → **the provider wins.** Link, and mark the address
  verified — a verified assertion from Google outranks an unverified local claim.
- Provider does not assert verification → create a separate account, and surface it in the
  admin as a possible duplicate rather than merging silently.

`identities` has `unique(site_users_id, type, provider_id)`, so even under a race a subject
id maps to at most one user per module.

## 6.6 Credentials

`laravel_site_user_sso_integrations`, per module per provider
([04](04-database-schema.md) §4.8). `client_secret_encrypted` via `Crypt::encryptString`.

**No endpoint returns the secret** — not the admin one. It returns `has_secret: true`. The
current admin API is reachable unauthenticated (**M1**), which makes this the difference
between a fixable exposure and a leaked OAuth client secret for every tenant.

`GET /public/config` returns `client_id`, `redirect_uri` and `scopes` only — the values that
are public by construction because they appear in the authorization URL.

## 6.7 Acceptance

- **Posting a `provider_id` to any endpoint authenticates nobody.** The parameter no longer
  exists (**M3**).
- A callback with a valid `code` but a mismatched `state` fails.
- An ID token whose `aud` is a different client id fails.
- An ID token signed with an unknown key fails, and a *rotated* key succeeds after JWKS
  refresh.
- A replayed `code` fails on the second use.
- Google login on an account that already has a password, with a **verified** matching email,
  adds an identity — user count unchanged.
- The same against an **unverified** account does **not** link (§6.5).
- Facebook login with no email returns a usable session and a user with `email = null`.
- Apple's `client_secret` JWT is generated at call time, not read from config as a literal.
- No endpoint, admin or public, returns `client_secret_encrypted` or its plaintext.
