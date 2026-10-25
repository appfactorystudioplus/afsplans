# 07 — Desktop client authentication (afsdesktop5)

## Completion document

When this plan step is completed, write `complete/07-desktop-client-auth.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using `/** */` JSDoc blocks — every file,
exported symbol, public function, non-obvious private helper, and IPC handler, with plan
references. Auth code additionally documents **what it trusts, what it rejects, and which
way it fails**.

**Deletions get documented too.** Where a capability is removed on purpose — the secondary
login paths in §3 — leave a comment at the site saying it was removed, why, and where the
rule lives. Otherwise the next person re-adds it from the library's own docstring, which
still advertises it.

---

## 1. Requirement

> **Only primary Checkpoint users may sign in to the desktop app.** Secondary users are
> web-only.

This is not a new invention. Coordinator's `MemberCheckpointAuthMiddleware` already states
it as settled:

> *"Studio desktop must never obtain a secondary session" remains true, but it is enforced
> by the desktop client never completing a secondary login, not by this API refusing a
> token it has no way to attribute to a particular client platform.*

**That enforcement does not exist.** This step creates it.

## 2. Current state, verified

Read against `afsdesktop5` on 2026-08-22.

### 2.1 What is correct

| Item | Detail |
|---|---|
| Checkpoint host switching | `src/renderer/config.ts` — `isProduction` compared against the exact string `'true'`, with a documented fallback to development so a misconfigured build never silently ships against production |
| App identity | `src/renderer/checkpoint/app-names.ts:31` — `THIS_APP = CHECKPOINT_APPS.APPFACTORY_STUDIO` |
| Production login path | `components/settings.ts` calls `checkpointClient.auth.login()` / `loginWithUsername()` — the **primary** endpoints |
| Token at rest | Encrypted through Electron `safeStorage` via the `safe-storage-encrypt` IPC handler, then stored as ciphertext in `localStorage` (`settings.ts:174–177`) |
| Sign-out | Calls `auth.logout()` server-side, then clears local state — best-effort, with local clearing regardless |

### 2.2 Finding D1 — the secondary paths exist and are unguarded

`src/renderer/checkpoint/client-handler.ts` implements a complete secondary-user login:

| Line | Capability |
|---|---|
| `:179–205` | Handles a multi-account SSO callback, returns `needsAccountSelection` |
| `:208–213` | **Accepts `params.userType === 'secondary' && params.accessToken` from the URL and authenticates** |
| `:234–266` | `completeAccountSelection()` — calls `secondaryUsers.selectAccount()`, stores the resulting tokens |
| `:271–289` | `buildLoginUrl('secondary', appName, superUserId)` |

There is **no check anywhere** that rejects a secondary identity.

**Reachability today is low.** The only caller of `buildLoginUrl('secondary')` and
`completeAccountSelection()` is `src/renderer/_testing/client.ts`, a harness excluded from
`tsconfig.json`. The production UI (`components/settings.ts`) never touches `ClientHandler`
— it drives `CheckpointClient` directly and calls only primary endpoints. And a secondary
user's credentials would not authenticate against `/checkpoint/auth/login` anyway, because
secondary accounts sign in through `/checkpoint/secondary/login`.

**So this is a structural finding, not a live exploit.** It is worth fixing because:

- the invariant is asserted in a *different repository's* comment and enforced in none;
- `handlePageLoad()` will authenticate a secondary session if the parameters ever appear,
  and it is the function the app calls on every load;
- the capability is one UI wire-up away from being real, and nothing would flag that
  change as a security decision.

### 2.3 Finding D2 — no identity assertion after login

`settings.ts` receives an `AuthResponse` and persists it without ever checking what kind of
identity it is. It happens to call primary endpoints, so it happens to get primary
identities. Nothing states or verifies that.

### 2.4 Finding D3 — plaintext token storage on the secondary path

`client-handler.ts:110–114` defines localStorage keys `checkpoint_secondary_access_token`,
`checkpoint_secondary_refresh_token`, `checkpoint_secondary_token_expires`, and
`storeAuthState()` (`:432–436`) writes **raw, unencrypted tokens** to `localStorage`.

That is a second storage mechanism sitting beside the `safeStorage`-encrypted one, and it
is strictly weaker. Note what it is *for*: every one of those keys is named
`checkpoint_secondary_*`. **The plaintext path exists to serve the secondary flow.**
Removing secondary support (§3) removes the plaintext storage with it — the two fixes are
one change.

### 2.5 Finding D4 — 2FA blocks desktop sign-in entirely

`settings.ts:159–163`:

```ts
if (authResponse.requires_2fa) {
    // the testing client provides a 2FA flow; our modal doesn't,
    // so surface a generic error message instead
    throw new Error('Two-factor authentication required');
}
```

A member who has enabled two-factor authentication **cannot use the desktop app at all**.
Checkpoint fully supports the flow (`/checkpoint/auth/2fa/verify`), and the `_testing`
client implements it — the production modal does not.

This is a security *feature* penalising the users who adopted it: the practical advice
becomes "turn off 2FA to use the desktop app". Not an auth hole, but squarely in scope for
"make authentication correct".

### 2.6 Finding D5 — `THIS_APP` is declared and never used

`app-names.ts` exports `THIS_APP = APPFACTORY_STUDIO`, but no login or API call sends it.
Primary tokens carry no `app_name` claim, so there is nothing for it to do on the primary
path — it exists for the secondary path being removed in §3.

Keep the constant (§5 uses it as the server-side app identity) but document what it is and
is not for, so it is not mistaken for an active control.

## 3. The change: remove the capability

The strongest guarantee is that the code cannot do the thing.

### 3.1 Delete from `client-handler.ts`

| Remove | Replace with |
|---|---|
| Secondary branch of `handlePageLoad()` (`:179–213`) | An explicit rejection that logs and returns an unauthenticated state with a clear reason |
| `completeAccountSelection()` | Deleted |
| `'secondary'` branch of `buildLoginUrl()` | `buildLoginUrl()` takes no `userType`; it always builds a primary URL |
| `STORAGE_KEYS` and the localStorage read/write of secondary tokens | Deleted — D3 goes with it |
| `ClientUserType` union | Narrowed to `'normal'`, or removed |
| `SecondaryUserAccountOption` import and `accounts`/`selectionToken` on `PageLoadResult` | Deleted |

The rejection branch is deliberately **not** silent:

```ts
/**
 * Secondary (sub-account) identities are rejected here, by design.
 *
 * Desktop is primary-only — see afsserver/plans/checkpoint-server-auth/
 * 07-desktop-client-auth.md §1. The rule pre-dates this file: coordinator's
 * MemberCheckpointAuthMiddleware states "Studio desktop must never obtain a
 * secondary session", and until this change nothing enforced it.
 *
 * This branch exists rather than simply deleting the parameter handling so
 * that a secondary callback arriving here is VISIBLE (logged, surfaced to the
 * user) instead of falling through to "not signed in", which would look like
 * a broken login rather than a refused one.
 */
if (params.userType === 'secondary') {
    console.warn('[ClientHandler] secondary sign-in refused — desktop is primary-only');
    this.cleanupUrl();
    return {
        authState: {
            isAuthenticated: false,
            userType: 'normal',
            user: null,
            error: 'SECONDARY_NOT_PERMITTED',
        },
        needsAccountSelection: false,
    };
}
```

### 3.2 Remove `secondary-users.ts` from the desktop bundle

`src/renderer/checkpoint/secondary-users.ts` is the SDK surface for creating, listing, and
logging in secondary users. The desktop app has no legitimate use for any of it.

Delete it, and delete its re-export from `checkpoint/index.ts`. If something still imports
it after that, the build fails — which is the point.

### 3.3 Update `_testing/client.ts`

It is the only consumer of the deleted paths. It is a harness, not shipped UI, but it must
still compile and it must not demonstrate a flow the product forbids. Remove its secondary
login and account-selection sections.

## 4. Defence in depth

Deleting the capability is layer one. It is not sufficient on its own, because the desktop
app is code on a user's machine and can be modified.

| Layer | Where | What it stops |
|---|---|---|
| **1. Capability removed** | desktop client (§3) | The app cannot initiate or complete a secondary login |
| **2. Identity asserted** | desktop client (§4.1) | A secondary identity obtained by any other route is discarded at the door |
| **3. Server refuses** | shared middleware (§5) | A tampered client presenting a secondary token to a Studio-scoped API |
| **4. Token never minted** | `apps/auth` (§5.2) | A Studio-scoped secondary token cannot exist in the first place |

Layers 1–2 are client-side and defeat mistakes, not attackers. Layers 3–4 are the real
controls.

### 4.1 Layer 2 — assert after every login

Every path that produces an authenticated state ends with the same check:

```ts
/**
 * Reject a secondary identity regardless of how it was obtained.
 *
 * Layer 2 of the primary-only rule (07 §4). Layer 1 removes the code that
 * could ask for a secondary session; this catches a secondary identity that
 * arrives anyway — a restored session from an older build, a hand-edited
 * localStorage entry, or a future SDK change that reintroduces the path.
 *
 * Tokens are discarded, not merely ignored: leaving a rejected token in
 * storage means the next launch retries and fails the same way with no
 * explanation.
 */
private assertPrimary(response: AuthResponse): void {
    const type = response.user?.type;
    if (type === 'secondary_user' || type === 'secondary') {
        void this.clearStoredSession();
        throw new SecondaryNotPermittedError(
            'Sub-accounts cannot sign in to the desktop app. ' +
            'Please use the web portal at members15.appfactory.studio.'
        );
    }
}
```

Called from the `settings.ts` login handler and from session restore
(`loadExistingAuthState`), so an existing secondary session created by an older build is
cleared on next launch rather than persisting indefinitely.

The error message names the alternative. "Access denied" for a user whose account genuinely
exists produces a support ticket; "use the web portal" does not.

## 5. Server-side enforcement (layer 3)

The shared package ([04](04-shared-validator.md)) gains a `primary_only` option:

```php
/**
 * When true, a valid SECONDARY identity is rejected with reason
 * 'secondary_not_permitted'.
 *
 * For surfaces that only ever serve first-party desktop/mobile clients,
 * where the platform rule is primary-only (07 §1, 08 §1). Off by default:
 * every web surface legitimately accepts secondary users, and defaulting
 * this on would lock sub-accounts out of the apps they are sold with.
 */
Route::middleware('checkpoint.member:primary_only')->group(…);
```

### 5.1 The honest limitation

**Most endpoints cannot use this.** The desktop app calls the same members, notes,
dream_graph and coordinator APIs the web client calls, and those must keep accepting
secondary users. The server cannot tell a desktop caller from a browser caller — a header
or user-agent claiming to be Studio is client-supplied and worthless as a control.

So layer 3 applies only to genuinely Studio-exclusive surfaces. Where a route is shared,
layer 4 is the control that matters.

Stating this plainly matters more than the feature: a `primary_only` flag on a handful of
routes must not be mistaken for a platform-wide guarantee.

### 5.2 Layer 4 — never mint the token

> **Decided: yes.** Specified in full in
> [10-primary-only-enforcement.md](10-primary-only-enforcement.md) §3.2, which also extends
> the rule to members and registers `APPFACTORY_MOBILE`. Summarised here because it is the
> control the rest of this step depends on.

The airtight control, and the only one that does not depend on client behaviour.

`members/resources/js/checkpoint/app-names.ts` defines `UI_APP_ID_TO_CHECKPOINT` — the apps
assignable to a secondary user in the members create-user UI. It contains coordinator,
notes, dream, design, pdf, engine, and market. **It does not contain `APPFACTORY_STUDIO`
or `MEMBERS`.**

So today a secondary user cannot be granted Studio access, and a Studio-scoped secondary
token cannot be minted. **The control already exists — as the absence of an entry in a UI
map.**

Make it explicit and server-enforced:

1. In `apps/auth`, reject a secondary login or registration request naming
   `APPFACTORY_STUDIO` (and any future mobile app name, [08](08-mobile-client-auth.md) §5),
   with a distinct error code.
2. Add a comment at `UI_APP_ID_TO_CHECKPOINT` recording that the omission is deliberate and
   load-bearing, not an oversight — otherwise someone will "complete" the list.
3. Add a test asserting Studio app names are not assignable.

This converts an implicit property of a UI constant into an enforced server rule. Full
specification, including the members and mobile cases, is
[10-primary-only-enforcement.md](10-primary-only-enforcement.md).

## 6. Fixing 2FA (D4)

> **Platform context:** 2FA across every client and user type is specified in
> [09-two-factor-authentication.md](09-two-factor-authentication.md). Desktop needs the
> **primary-user** flow only — secondary users cannot sign in here
> ([10](10-primary-only-enforcement.md)). This section is the desktop-specific part.

Out of the auth-hole category, but in scope for "authentication is correct".

Checkpoint's flow: `login` returns `requires_2fa` plus a `two_factor_token`; the client
collects a 6-digit code and posts it to `/checkpoint/auth/2fa/verify` with that token,
receiving the real token pair.

`_testing/client.ts` already implements this end to end and is the reference. The work is a
modal step in `settings.ts`, not new protocol:

1. `requires_2fa` ⇒ swap the login modal to a code-entry step, holding `two_factor_token`
   **in memory only** — never `localStorage`, encrypted or not; it is a bearer credential
   with a short life.
2. Submit the code; on success run the same `assertPrimary()` + persist path as a normal
   login.
3. Support recovery codes — the same endpoint accepts them, and a member who lost their
   authenticator needs the app to keep working.
4. On failure, allow retry without restarting the whole login.

## 7. Acceptance

**Status as of 2026-08-23** — code-level and build-level verification (grep, `tsc --noEmit`,
full `webpack` build) done for every row; runtime rows that need a launched app against a
real Checkpoint backend (DA3, DA7, DA11) are implemented but not manually exercised this
session — see `complete/07-desktop-client-auth.complete.md`.

| # | Criterion | Status |
|---|---|---|
| DA1 | `grep -rn "secondary" src/renderer/checkpoint/` returns only the §3.1 rejection branch and its documentation | Done, with one caveat — see completion doc (a docs file, `CHECKPOINT.md`, uses "secondary domain" in an unrelated cross-domain-SSO sense) |
| DA2 | `src/renderer/checkpoint/secondary-users.ts` is deleted and nothing imports it | Done — verified by successful `tsc`/`webpack` build |
| DA3 | A crafted SSO callback with `user_type=secondary` leaves the app signed out, logs a refusal, and shows the §4.1 message | Implemented; not manually exercised |
| DA4 | `assertPrimary()` runs on the login path **and** on session restore | Done — `finishLogin()`, `restoreSessionIfPresent()`, `restoreSessionGlobal()` |
| DA5 | A stored session whose user type is secondary is cleared on next launch, not merely ignored | Done |
| DA6 | No token or refresh token is written to `localStorage` unencrypted; the `checkpoint_secondary_*` keys no longer exist | Done — mechanism deleted; legacy keys purged on sign-out (DA9) |
| DA7 | A 2FA-enabled member can sign in, including with a recovery code | Implemented; not manually exercised against a real 2FA-enabled account |
| DA8 | `two_factor_token` never reaches persistent storage | Done — held only in an in-memory closure variable |
| DA9 | Sign-out clears both the encrypted session and any legacy plaintext keys from older builds | Done |
| DA10 | `THIS_APP` is documented as to what it is and is not used for | Done |
| DA11 | The desktop app still signs in normally against both production and development Checkpoint hosts | Not regressed (no code touched `config.ts`'s host-selection logic); not manually exercised |

## 8. Testing

| Test | Asserts |
|---|---|
| Primary login, email and username | Succeeds; session persists across restart |
| Primary login, wrong password | Clear error; nothing persisted |
| 2FA-enabled account | Code step appears; correct code signs in; wrong code retries |
| Recovery code | Signs in |
| SSO callback with `user_type=secondary` | Refused, logged, signed out (DA3) |
| Pre-seeded secondary session in `localStorage` (older build) | Cleared on launch (DA5) |
| Token expiry mid-session | Refresh succeeds without re-login |
| Auth host unreachable | Clear error; no partial signed-in state |
| Sign-out | Server logout called; all local keys cleared |
| Build with `IS_PRODUCTION` unset / `"false"` / typo | Development host — never production (`config.ts` rule) |

## 9. Files

**Status as of 2026-08-23** — all rows done. `ClientUserType` was narrowed in
`client-handler.ts` directly (this codebase has no separate `types.ts` for the checkpoint
module); two files not listed in this table were also deleted this step —
`_testing/register-secondary.ts` and its stylesheet — found via the same reachability
analysis as this doc's own §2 findings; see completion doc.

| Path | Action | Status |
|---|---|---|
| `src/renderer/checkpoint/client-handler.ts` | Remove secondary paths, add rejection branch, delete plaintext storage | Done |
| `src/renderer/checkpoint/secondary-users.ts` | Delete | Done |
| `src/renderer/checkpoint/index.ts` | Drop the re-export | Done — also dropped the separate `sso-handler.ts` re-export (undiscovered second secondary-capable module, see completion doc) |
| `src/renderer/checkpoint/types.ts` | Narrow `ClientUserType` | Done in `client-handler.ts` (no standalone `types.ts` in this module) |
| `src/renderer/checkpoint/app-names.ts` | Document `THIS_APP` | Done |
| `src/renderer/components/settings.ts` | `assertPrimary()`, 2FA step, clear legacy keys on sign-out | Done |
| `src/renderer/_testing/client.ts` | Remove secondary sections | Done |
| `src/main/index.ts` | No change — `safe-storage-encrypt` already correct | Confirmed, no change made |
