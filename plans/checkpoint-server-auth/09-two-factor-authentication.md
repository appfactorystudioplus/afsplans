# 09 — Two-factor authentication for every user type

## Completion document

When this plan step is completed, write `complete/09-two-factor-authentication.complete.md`
with status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly — PHPDoc in `apps/auth` and `apps/members`,
JSDoc in the TypeScript clients, `///` in Dart. Every 2FA function documents what it
trusts, what it rejects, and which way it fails.

**Two things specifically must be documented at their call sites**, because both are
counter-intuitive and both will otherwise be "simplified" later:

1. Why the primary user can *require* a secondary user's 2FA but can never *enrol* it for
   them (§3.2).
2. Why `checkpoint_2fa` cannot be keyed on `user_id` alone once secondary users are in it
   (§4.1).

---

## 1. Requirement

> **2FA works for everyone, including secondary users.** Build the UIs necessary to run it.
>
> **The primary user controls whether 2FA is required for a secondary user.**

> **Amended by [11](11-secondary-user-dashboard.md) §6 — settled 2026-08-22.** This step
> was written when secondary users had no dashboard at all. They now have a minimal one,
> so the split is: the **primary owns the policy** (is 2FA required) and **reset** (lost
> device); the **secondary owns their own enrolment** and can regenerate recovery codes
> from `/security`. Nobody but the enrolling user ever sees the TOTP secret.
>
> Everything below still holds — §3.1's table and §3.2's premise are the parts amended,
> and both already reflect the decision.

## 2. Current state, verified

### 2.1 Primary users — complete, on the web only

| Piece | State |
|---|---|
| `TwoFactorService` | Full TOTP implementation — `setupTotp`, `enableTotp`, `disable`, `getStatus`, `generateRecoveryCodes`, `createPendingToken`, `verifyAndComplete` |
| Storage | `checkpoint_2fa` (secret, enabled, recovery_codes) and `checkpoint_2fa_pending` (pending token, expiry) |
| Endpoints | `/checkpoint/auth/2fa/{status,setup,enable,verify,disable,recovery-codes}` |
| Hosted login UI | `apps/auth/resources/views/login.blade.php:123–133` — a modal with 6-digit entry that already accepts a backup code |
| Recovery codes | Generated, stored, and consumed on use |

So a primary user signing in through a browser has a working 2FA experience today.

### 2.2 Secondary users — nothing

`grep -n "2fa\|twoFactor\|TwoFactor"` returns **zero matches** in both
`SecondaryUserController.php` and `SecondaryUserService.php`.

`buildLoginResponse()` (`SecondaryUserService.php:650`) issues tokens directly. There is no
`requires_2fa` branch, no pending token, and no enrolment surface.

### 2.3 Desktop and mobile — hard-fail

Both throw on `requires_2fa` because neither has a code-entry screen
([07](07-desktop-client-auth.md) §2.5, [08](08-mobile-client-auth.md) §2.6). A member with
2FA enabled cannot use either app.

### 2.4 What this means for the work

| Surface | Needed |
|---|---|
| Primary, web | ✅ exists |
| Primary, desktop | Code-entry UI ([07](07-desktop-client-auth.md) §6) |
| Primary, mobile | Code-entry UI ([08](08-mobile-client-auth.md) §6) |
| **Secondary, web** | **Everything** — schema, service, endpoints, enrolment UI, login UI, admin controls |
| Secondary, desktop/mobile | **Not applicable** — secondary users cannot sign in there ([10](10-primary-only-enforcement.md)) |
| Secondary, members dashboard | Self-service enrolment + recovery-code regeneration ([11](11-secondary-user-dashboard.md) §6) |

The desktop/mobile row is what keeps this tractable. Because secondary users cannot sign in
there, the *login-time* secondary 2FA UI is built **once**, in auth's hosted login page,
and serves coordinator, notes, dream_graph, pdf_editor, flow and market together. The
members dashboard adds a *management* surface on top of it, not a second login flow.

## 3. The model for secondary users

### 3.1 Who decides, who enrols

| Action | Who | Where |
|---|---|---|
| Require 2FA for a secondary user | **Primary user** | members → app settings → users |
| Require 2FA for all secondary users on the account | **Primary user** | same, account-level default |
| Enrol a device (scan QR, confirm code) | **Secondary user** | auth hosted login, forced first-run step |
| See own recovery codes | Secondary user | shown once at enrolment |
| **Regenerate** recovery codes | **Secondary user** | members `/security` — added by [11](11-secondary-user-dashboard.md) §6 |
| Re-enrol a new device | **Secondary user** | members `/security` — ibid. |
| Reset / revoke enrolment (lost device) | **Primary user** | members → app settings → users |
| Disable 2FA for a secondary user | **Primary user** | same |

### 3.2 The primary can require, but can never enrol

This is the rule that shapes everything else, and it needs to survive future refactors.

A TOTP secret is a shared secret between the account and **the device in the user's hand**.
If the primary user enrolled on a secondary's behalf, the primary would hold that secret —
and could then generate that user's codes and authenticate as them. The second factor would
be worthless precisely against the party it most needs to be independent of.

So:

- The primary sets a **policy** (`require_2fa`).
- The secondary user's next login is **interrupted by a forced enrolment step** before any
  session is issued.
- The primary can **reset** enrolment, which clears the secret and forces re-enrolment. It
  does **not** reveal or set a secret.

Reset is the recovery path for a **lost device**. A locked-out user cannot authenticate to
fix their own lockout, so someone else must be able to — and that is the account owner.

Since [11](11-secondary-user-dashboard.md), a secondary user who is *not* locked out can
re-enrol and regenerate recovery codes themselves from members `/security`. The primary's
reset remains for the case where the user cannot get in at all.

### 3.3 Forced enrolment, step by step

```
secondary user signs in (identifier + password)
        │
        ▼
credentials valid?  ── no ──▶ reject
        │ yes
        ▼
require_2fa policy on?  ── no ──▶ issue tokens (unchanged behaviour)
        │ yes
        ▼
enrolled already?
        │
        ├── yes ──▶ 401-equivalent + pending token
        │            └─▶ code entry ──▶ verify ──▶ issue tokens
        │
        └── no  ──▶ enrolment-required + enrolment token
                     └─▶ show QR + secret
                         └─▶ confirm code
                             └─▶ store secret (enabled)
                                 └─▶ show recovery codes ONCE
                                     └─▶ issue tokens
```

**No session is issued before enrolment completes.** A half-enrolled user is signed out, not
signed in with a warning — otherwise "require 2FA" is advisory.

### 3.4 Recovery codes for secondary users

Generated and shown **once**, at enrolment, exactly as for primary users.

A secondary user can regenerate them from members `/security`
([11](11-secondary-user-dashboard.md) §6) — but only while they can still sign in. If they
lose both device and codes they are locked out, and the account owner's reset is the only
way back. State that in the UI copy at enrolment, not only in this document.

## 4. Schema

### 4.1 `checkpoint_2fa` cannot stay keyed on `user_id` alone

Verified: `TwoFactorService` reads and writes `checkpoint_2fa WHERE user_id = ?`. Primary
user ids come from the Delight-Auth users table. Secondary user ids come from
`checkpoint_secondary_users`, **an independent table with an independent id space**.

Primary user `5` and secondary user `5` both exist, and both would map to the same
`checkpoint_2fa` row. That is a cross-account credential collision — one user's enrolment
silently becomes another's.

Two options:

| Option | Shape |
|---|---|
| **A. Discriminator column** (recommended) | Add `user_type ENUM('normal','secondary')`; primary key becomes `(user_type, user_id)` |
| B. Separate table | `checkpoint_secondary_2fa`, mirroring the schema |

**A**, because every method in `TwoFactorService` then takes `(userType, userId)` and the
logic is shared. B duplicates a working TOTP implementation, which is how the two copies
drift.

The same applies to `checkpoint_2fa_pending`.

> **Migration note.** Existing rows are all primary. Backfill `user_type = 'normal'` before
> adding the composite key, and do it in that order — adding the key first against
> ambiguous rows is the failure mode.

### 4.2 The policy

Where the primary's requirement is stored:

```php
Schema::create('checkpoint_secondary_2fa_policy', function (Blueprint $table) {
    $table->id();
    $table->string('member_reference_num', 64);           // the account
    $table->unsignedBigInteger('secondary_user_id')->nullable();  // null = account default
    $table->boolean('require_2fa')->default(false);
    $table->string('set_by_checkpoint_user_id', 64);      // audit: who turned it on
    $table->timestamps();

    $table->unique(['member_reference_num', 'secondary_user_id']);
});
```

`secondary_user_id IS NULL` is the account-wide default; a row with an id overrides it for
that user. Resolution is "most specific wins", and a user with no row inherits the default.

An account-wide toggle is what a primary actually wants ("all my staff use 2FA"); the
per-user override exists for the one contractor who cannot.

## 5. Auth-side work

### 5.1 `TwoFactorService`

Every method takes `(string $userType, int $userId)` instead of `(int $userId)`. No logic
change beyond the key — TOTP generation, verification, recovery codes and pending tokens
are all user-type agnostic.

> **Ordering dependency — the Joystick Engine.** Adding `requires_2fa` to the secondary
> login response breaks `checkpoint_cpp`’s `SecondaryUsers::Login()`, which does not
> check that field and will surface a `MALFORMED_RESPONSE` instead of an actionable
> message ([12](12-game-engine-client.md) §2.5). [12](12-game-engine-client.md) §4.2 must
> ship with or before this section.

### 5.2 `SecondaryUserService`

`buildLoginResponse()` (`:650`) gains the branch that does not exist today:

```php
/**
 * Secondary login now has three outcomes, not one:
 *
 *   1. no 2FA policy            → tokens, as before
 *   2. policy on, enrolled      → requires_2fa + pending token (no session)
 *   3. policy on, NOT enrolled  → requires_2fa_enrolment + enrolment token (no session)
 *
 * Outcome 3 has no primary-user equivalent: a primary user enrols voluntarily
 * from their own dashboard, so their login never has to force it. A secondary
 * user has no dashboard (plan 09 §3.2), so enrolment can only happen here.
 *
 * No token pair is issued in outcomes 2 or 3. A partially authenticated user
 * is signed OUT, not signed in with a warning — otherwise "require 2FA" is
 * advisory and the policy means nothing.
 */
```

### 5.3 New endpoints

Under `/checkpoint/secondary/2fa/`, mirroring the primary surface:

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/enrol/begin` | enrolment token | Returns TOTP secret + `otpauth://` URI for the QR |
| `POST` | `/enrol/confirm` | enrolment token | Verifies the first code, enables, returns recovery codes **once** |
| `POST` | `/verify` | pending token | Code or recovery code → token pair |
| `GET` | `/status` | service/HMAC | Enrolment status for the admin UI |
| `POST` | `/reset` | service/HMAC | Primary-initiated reset — clears secret, forces re-enrolment |
| `POST` | `/policy` | service/HMAC | Set `require_2fa` for a user or account |

**`/status`, `/reset` and `/policy` are service-authenticated, not user-authenticated.**
They are called by members' server on the primary's behalf, over the existing HMAC channel
— never from a browser with a user token, because that would let any secondary user call
`/reset` on themselves and remove their own second factor.

That distinction is the single most important line in this section.

### 5.4 Rate limiting

Code verification is a 6-digit guess. Existing primary-side limits apply; secondary
verification and enrolment confirmation need the same:

- 5 attempts per pending/enrolment token, then the token is burned and login restarts.
- 10 verification attempts per secondary user per 15 minutes.
- Enrolment and pending tokens expire in 10 minutes.
- Recovery codes are single-use and removed from the stored set on consumption (already the
  primary behaviour).

## 6. The UIs

### 6.1 auth hosted login — secondary code entry and enrolment

`resources/views/login.blade.php` already has the primary 2FA modal
(`:123–133`), including backup-code entry. Extend the same page:

- **Code entry** for secondary users — reuse the existing modal; only the endpoint differs.
- **Enrolment step** (new): QR code, the secret in copyable text for manual entry, a
  confirm-code field, then a one-time recovery-code panel with copy and download.

Requirements that are easy to get wrong and matter:

- The secret must be **selectable text** as well as a QR — desktop browsers on a machine
  without a camera need manual entry.
- The recovery-code panel needs an explicit "I have saved these" confirmation before it can
  be dismissed. Shown once means shown once.
- The QR is rendered **client-side** from the `otpauth://` URI. Do not call a third-party QR
  image service — that ships the TOTP secret to a stranger in a URL.

Built here, this serves every web app at once.

### 6.2 members — the primary's controls

In the existing secondary-user surface (`resources/js/applications/applications.ts`,
`templates/views/app_settings_user_create.html`,
`templates/views/app_settings_user_profile.html`):

| Control | Placement |
|---|---|
| **Require 2FA for all users** | Account-level toggle at the top of the users list |
| **Require 2FA** per user | On the user row and in the create form |
| **2FA status** per user | Badge: `Not required` / `Required — not enrolled` / `Enrolled` |
| **Reset 2FA** | Per-user action, confirmation dialog |

`Required — not enrolled` is the state the primary most needs to see: it means that user
cannot currently sign in, and it distinguishes "policy set, waiting on them" from "broken".

Reset copy must say what it does: *"This signs them out and requires them to set up a new
authenticator the next time they sign in. It does not reveal or change their password."*

Members' server proxies these over the existing HMAC channel to §5.3 — the browser never
calls the secondary 2FA admin endpoints directly.

### 6.3 members — the primary's own 2FA

Primary 2FA already works through the hosted login page, but members has no *management*
UI for it. A member cannot enrol from their own dashboard.

Add it in account settings, using the existing `/checkpoint/auth/2fa/*` endpoints:
enrol (QR + confirm), status, regenerate recovery codes, disable (requires a current code).

Without this, the only route to enrolment is whatever the hosted page offers at login time,
and "turn on 2FA" is not a login-time action.

### 6.4 Desktop and mobile

Primary users only. Specified in [07](07-desktop-client-auth.md) §6 and
[08](08-mobile-client-auth.md) §6 — a code-entry step holding the pending token **in memory
only**, recovery-code support, and retry without restarting the login.

Both call `/checkpoint/auth/2fa/verify` directly; neither uses the hosted page.

## 7. Interaction with the primary-only rule

[10](10-primary-only-enforcement.md) makes members, desktop and mobile primary-only. That
simplifies this step considerably, and the two must land in a consistent order:

- Secondary 2FA UI is needed **only** on the hosted login page, because that is the only
  place a secondary user can now authenticate.
- Desktop and mobile need **primary** 2FA only.
- members needs primary 2FA management (§6.3) plus the **admin** controls for its secondary
  users (§6.2) — even though those users cannot log into members themselves.

That last point is worth stating: the primary manages, in members, the 2FA of users who can
never sign in to members. That is correct and is the whole reason §5.3's admin endpoints are
service-authenticated.

## 8. Acceptance

| # | Criterion |
|---|---|
| T1 | `checkpoint_2fa` and `checkpoint_2fa_pending` are keyed on `(user_type, user_id)`; existing rows backfilled to `normal` |
| T2 | A primary user can enrol, verify, regenerate recovery codes, and disable 2FA from members account settings |
| T3 | A primary user with 2FA can sign in on web, desktop, and mobile — including with a recovery code |
| T4 | A primary user can set `require_2fa` account-wide and per secondary user |
| T5 | A secondary user under an account with `require_2fa` is forced through enrolment at next login and receives **no session** until it completes |
| T6 | A secondary user who is enrolled must supply a code at every login |
| T7 | Recovery codes are shown exactly once, require explicit acknowledgement, and are single-use |
| T8 | The primary can reset a secondary user's 2FA; the next login forces re-enrolment |
| T9 | **The primary can never see or set a secondary user's TOTP secret** — no endpoint returns it to them |
| T10 | A secondary user cannot call `/reset` or `/policy` for themselves — those endpoints are service-authenticated only |
| T11 | The QR is generated client-side; the TOTP secret never reaches a third-party host |
| T12 | Pending and enrolment tokens expire in 10 minutes, are burned after 5 failed attempts, and never reach persistent storage on any client |
| T13 | The members admin UI distinguishes `Not required`, `Required — not enrolled`, and `Enrolled` |
| T14 | Primary user id `N` and secondary user id `N` have independent 2FA state (the §4.1 collision) |

## 9. Testing

| Test | Asserts |
|---|---|
| Primary enrol → sign out → sign in with code | T2, T3 |
| Primary sign-in with recovery code | Code consumed, cannot be reused |
| Primary disable with a wrong code | Refused |
| Set account-wide `require_2fa`, then log in an unenrolled secondary | Forced enrolment; no session issued before completion (T5) |
| Abandon enrolment midway, retry login | Still forced; no partial session |
| Enrolled secondary, correct code | Signs in |
| Enrolled secondary, wrong code ×5 | Token burned; login restarts |
| Primary resets a secondary's 2FA | Next login forces re-enrolment (T8) |
| Secondary calls `/reset` with their own user token | Rejected (T10) |
| Per-user override off, account default on | Override wins |
| Primary id 7 and secondary id 7 both enrol | Independent secrets; neither code validates for the other (T14) |
| Desktop and mobile, 2FA-enabled primary | Sign in, including recovery code |
| Inspect client storage after 2FA login | No pending token persisted (T12) |

## 10. Files

| Repo | Path | Action |
|---|---|---|
| auth | `app/Services/Checkpoint/TwoFactorService.php` | Key on `(userType, userId)` |
| auth | `app/Services/Checkpoint/SecondaryUserService.php` | 2FA branch in `buildLoginResponse()`; enrolment/verify/reset/policy |
| auth | `app/Http/Controllers/Checkpoint/SecondaryUserController.php` | New endpoints (§5.3) |
| auth | `routes/checkpoint.php` | Register `/checkpoint/secondary/2fa/*` |
| auth | migrations | `user_type` on both 2FA tables; `checkpoint_secondary_2fa_policy` |
| auth | `resources/views/login.blade.php` + its JS | Secondary code entry; enrolment step; client-side QR |
| members | `resources/js/applications/applications.ts` | Policy toggles, status badges, reset action |
| members | `templates/views/app_settings_user_create.html`, `app_settings_user_profile.html` | 2FA controls |
| members | account settings view + controller | Primary's own 2FA management (§6.3) |
| members | `app/Http/Controllers/…` | HMAC proxy to the §5.3 admin endpoints |
| afsdesktop5 | `components/settings.ts` | Code-entry step ([07](07-desktop-client-auth.md) §6) |
| afsmobile_flutter | `lib/pages/`, `lib/bloc/auth/` | Code-entry step ([08](08-mobile-client-auth.md) §6) |
