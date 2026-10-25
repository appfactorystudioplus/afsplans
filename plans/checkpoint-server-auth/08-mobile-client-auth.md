# 08 — Mobile client authentication (afsmobile_flutter)

## Completion document

When this plan step is completed, write `complete/08-mobile-client-auth.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using `///` Dart doc comments — every
library, public class, method, and non-trivial type, with plan references. Auth code
additionally documents **what it trusts, what it rejects, and which way it fails**.

**Document the deletions.** The `checkpoint_client` package's own library docstring
currently advertises `loginSecondary()` in its usage example. Removing the method without
removing that example guarantees someone re-adds it.

---

## 1. Requirement

> **Only primary Checkpoint users may sign in to the mobile app.** Secondary users are
> web-only.

Identical to the desktop rule ([07](07-desktop-client-auth.md) §1), and for the same
reason. Mobile has an additional wrinkle: unlike Studio, it has **no registered Checkpoint
app name at all** (§5).

## 2. Current state, verified

Read against `afsmobile_flutter` on 2026-08-22.

### 2.1 What is correct

| Item | Detail |
|---|---|
| Checkpoint client | `packages/checkpoint` (`checkpoint_client`), a local copy of apps/auth's Flutter SDK |
| Login path | `AuthRepository.login()` → `ClientHandler.loginNormal()` → `checkpoint.auth.login()` / `loginWithUsername()` — **primary** endpoints only |
| Endpoint config | `lib/config.dart` — `auth.appfactory.studio` / `auth15.appfactory.studio`, members API at `members15.appfactory.studio/api` |
| **Primary token storage** | `CheckpointConfig.storage` defaults to `TokenStorage.secure` → `SecureTokenStorage` → `flutter_secure_storage`, with `encryptedSharedPreferences: true` on Android. `AuthRepository` does not override it, so it gets the secure default |
| 2FA handling | Not implemented, but **deliberately and visibly** — `AuthRepository`'s docblock says the app has no OTP screen so a 2FA account *"fails with a clear message rather than silently mismanaging the login"* |
| Honest scoping note | The same docblock records that there is no registered app scope for this client, so it authenticates as a primary user |

The primary path is in good shape. The findings below are all on the secondary path and on
what is missing.

### 2.2 Finding M1 — `loginSecondary()` exists and is advertised

`packages/checkpoint/lib/src/client_handler.dart` exposes a full secondary flow, and the
library's own docstring (`:15–20`) shows callers how to use it:

```dart
/// // Secondary user login
/// final result = await handler.loginSecondary(
///   appName: 'my-app',
///   identifier: 'user@example.com',
///   password: 'pass',
/// );
```

`lib/` never calls it. But the method is present, documented, and reachable — and the
documentation is an instruction to use it.

### 2.3 Finding M2 — plaintext token storage on the secondary path

`client_handler.dart:104–108` defines SharedPreferences keys:

```dart
const _kAccessToken  = 'checkpoint_secondary_access_token';
const _kRefreshToken = 'checkpoint_secondary_refresh_token';
const _kTokenExpires = 'checkpoint_secondary_token_expires';
```

and `_storeAuthState()` (`:373–383`) writes them with `prefs.setString(...)`.

**SharedPreferences is plaintext on disk.** On a rooted Android device or an unlocked
backup it is readable. The package already contains `SecureTokenStorage` using
`flutter_secure_storage` — `ClientHandler` simply does not use it.

Two mitigating facts, both verified:

- `loginNormal()` builds an `AuthState` with **no** `accessToken`/`refreshToken`
  (`:214–218`), so the primary path writes only `_kUserType` and `_kUserData` — profile
  data, not credentials.
- Primary tokens are held by `CheckpointClient` itself through the secure `ITokenStorage`.

So **the plaintext credential storage is exclusively the secondary path** — exactly as on
desktop ([07](07-desktop-client-auth.md) §2.4). Removing secondary support removes it.

`_kUserData` still writes the user profile JSON to plaintext SharedPreferences. Not
credentials, but it is PII (email, name, ids) and should move to secure storage in the same
pass.

### 2.4 Finding M3 — no identity assertion

`AuthRepository.login()` returns a display name on `state.isAuthenticated` and never checks
the identity type. As on desktop, it calls primary endpoints so it gets primary identities;
nothing states or verifies that.

### 2.5 Finding M4 — no app name for mobile

There is no `CHECKPOINT_APPS` entry for the mobile client. `AuthRepository`'s docblock
states this plainly.

Consequences:

- Mobile cannot be app-scoped server-side. The `wrong_app` check
  ([01](01-platform-audit.md) §5) has nothing to compare against for this client.
- The layer-4 control that protects desktop — *"a Studio-scoped secondary token can never
  be minted because `APPFACTORY_STUDIO` is not assignable"*
  ([07](07-desktop-client-auth.md) §5.2) — has no mobile equivalent.

Decision needed, §5.

### 2.6 Finding M5 — 2FA unsupported

Same as desktop D4: a member with 2FA enabled cannot use the mobile app. Handled honestly
(clear error, no broken half-state), but the practical advice is still "disable 2FA to use
the app".

## 3. The change: remove the capability

### 3.1 Delete from `client_handler.dart`

| Remove | Notes |
|---|---|
| `loginSecondary()` | |
| `registerSecondary()` and any secondary account-selection helper | |
| The `loginSecondary` example in the **library docstring** (`:15–20`) | The example is the instruction — deleting the method and leaving the docs is how it comes back |
| `_kAccessToken`, `_kRefreshToken`, `_kTokenExpires` and their reads/writes | M2 goes with this |
| `ClientUserType.secondary` | Narrow the enum to `normal`, or delete the enum |
| The `secondary_users.dart` import | |

### 3.2 `secondary_users.dart`

The package's secondary-user SDK surface. The mobile app has no legitimate use for any of
it.

Delete it and its export from `checkpoint_client.dart`. Anything still importing it then
fails to compile — which is the point.

> **This is a local copy of apps/auth's SDK**, not a shared dependency (`pubspec.yaml`:
> `checkpoint_client: path: packages/checkpoint`). So deleting from it affects only this
> app. Record the divergence in the package README, because the next person syncing from
> upstream will otherwise restore the removed code without knowing it was removed on
> purpose.

### 3.3 Move remaining persistence to secure storage

`_storeAuthState()` / `_loadAuthState()` switch from `SharedPreferences` to the package's
existing `SecureTokenStorage`. After §3.1 the only thing left is `_kUserType` and
`_kUserData` — profile data — but it is PII and the secure implementation is already there.

Include a one-time migration that deletes the old SharedPreferences keys on first run of
the new build. A rewrite that leaves old plaintext credentials on disk has not removed
them.

## 4. Defence in depth

Same four layers as desktop ([07](07-desktop-client-auth.md) §4).

### 4.1 Layer 2 — assert after login

```dart
/// Rejects a secondary identity regardless of how it was obtained.
///
/// Layer 2 of the primary-only rule (plan 08 §4). Layer 1 removes the code
/// that could request a secondary session; this catches one that arrives
/// anyway — a session restored from an older build, or a future re-sync of
/// the vendored SDK that reintroduces the path.
///
/// Credentials are CLEARED, not just ignored: a rejected session left on
/// disk means every subsequent launch retries and fails identically with no
/// explanation to the user.
Future<void> _assertPrimary(AuthState state) async {
  final type = state.user?['type'] ?? state.user?['user_type'];
  if (type == 'secondary_user' || type == 'secondary') {
    await _handler.clearAuth();
    throw const SecondaryNotPermittedException(
      'Sub-accounts cannot sign in to the mobile app. '
      'Please use the web portal at members15.appfactory.studio.',
    );
  }
}
```

Called after login **and** after `loadExistingAuth()`, so a secondary session persisted by
an older build is cleared on next launch.

`AuthBloc` surfaces the message; it must not be flattened into the generic "invalid
credentials" path, or a sub-account user will believe their password is wrong and reset it
repeatedly.

## 5. The app-name decision (M4)

> **Decided: option B — register `APPFACTORY_MOBILE`.** Implementation is
> [10-primary-only-enforcement.md](10-primary-only-enforcement.md) §5, which also covers
> what registering a name does *not* buy (§5.2 there). The analysis below is retained
> because it records why.

Mobile has no Checkpoint app identity. Three options:

| Option | Effect | Cost |
|---|---|---|
| **A. Reuse `APPFACTORY_STUDIO`** | Inherits the layer-4 control immediately — the name is already unassignable to secondary users | None. But it conflates two clients in logs and any future per-app policy |
| **B. New `APPFACTORY_MOBILE`** | Clean separation; per-client policy and audit become possible | `apps/auth`'s `CheckpointAppName`, the TS registry, and the Dart client. Must be added as **not** assignable to secondary users |
| **C. Leave unnamed** | Status quo | Mobile can never be app-scoped, and layer 4 cannot protect it |

**Recommendation: B.** The cost is three constant additions, and it is the only option that
lets mobile-specific policy exist later — including "revoke mobile sessions" as distinct
from desktop.

Chosen. Registered in [10](10-primary-only-enforcement.md) §5.

If B is chosen, the same rule as desktop applies: the new name must be **absent** from
`UI_APP_ID_TO_CHECKPOINT` and explicitly rejected by auth's secondary login, with a comment
at both sites saying the omission is deliberate and load-bearing.

## 6. Fixing 2FA (M5)

> **Platform context:** [09-two-factor-authentication.md](09-two-factor-authentication.md).
> Mobile needs the **primary-user** flow only — secondary users cannot sign in here
> ([10](10-primary-only-enforcement.md)).

Same shape as desktop ([07](07-desktop-client-auth.md) §6). Mobile specifics:

1. A `TwoFactorPage` (or a state on `LoginPage`) collecting a 6-digit code, following the
   app's existing `flutter_bloc` pattern.
2. `AuthBloc` gains a `TwoFactorRequired` state carrying the `two_factor_token`
   **in memory only** — never `SharedPreferences`, never secure storage. It is a
   short-lived bearer credential and the app may be killed mid-flow; losing it and
   restarting the login is correct.
3. Recovery-code entry on the same screen.
4. Autofill hints (`TextInputType.number`, `autofillHints: [AutofillHints.oneTimeCode]`) so
   iOS and Android can offer the code from SMS/authenticator.

## 7. Acceptance

| # | Criterion |
|---|---|
| MA1 | `grep -rn "secondary" lib/ packages/checkpoint/lib/` returns only the §4.1 rejection and its documentation |
| MA2 | `packages/checkpoint/lib/src/secondary_users.dart` is deleted and nothing imports it |
| MA3 | The library docstring no longer shows a `loginSecondary` example |
| MA4 | `_assertPrimary()` runs after login **and** after session restore |
| MA5 | A stored secondary session from an older build is cleared on launch |
| MA6 | No token or refresh token is written to `SharedPreferences`; the `checkpoint_secondary_*` keys are gone and migrated away |
| MA7 | User profile data is in secure storage, not plaintext SharedPreferences |
| MA8 | A 2FA-enabled member can sign in, including with a recovery code |
| MA9 | `two_factor_token` never reaches any persistent store |
| MA10 | The secondary-refusal message reaches the UI distinctly from "invalid credentials" |
| MA11 | `APPFACTORY_MOBILE` is set in `lib/config.dart` and matches the auth-side registry |
| MA12 | The package README records the divergence from upstream apps/auth |

## 8. Testing

| Test | Asserts |
|---|---|
| Primary login, email and username | Succeeds; session survives app restart |
| Wrong password | Clear error; nothing persisted |
| 2FA-enabled account | Code step; correct code signs in; wrong code retries |
| Recovery code | Signs in |
| Pre-seeded secondary session in SharedPreferences | Cleared on launch (MA5) |
| Fresh install, inspect on-device storage | No token in SharedPreferences (MA6) |
| Upgrade from a build that wrote plaintext keys | Old keys removed by migration |
| Token expiry mid-session | Refresh without re-login |
| Airplane mode at login | Clear network error; no partial signed-in state |
| Sign-out | Server logout called; secure storage and SharedPreferences both cleared |
| Android backup / iOS device backup inspection | No credentials in the backup set |

## 9. Files

| Path | Action |
|---|---|
| `packages/checkpoint/lib/src/client_handler.dart` | Remove secondary paths, docstring example, plaintext keys; move persistence to secure storage |
| `packages/checkpoint/lib/src/secondary_users.dart` | Delete |
| `packages/checkpoint/lib/checkpoint_client.dart` | Drop the export |
| `packages/checkpoint/README.md` | Record the deliberate divergence from upstream |
| `lib/repositories/auth_repository.dart` | `_assertPrimary()`, secondary-refusal error, 2FA wiring |
| `lib/bloc/auth/*` | `TwoFactorRequired` state, distinct secondary-refusal state |
| `lib/pages/login_page.dart` | 2FA step; surface the refusal message |
| `lib/config.dart` | App name, if §5 option B is chosen |
