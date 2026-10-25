# 08-mobile-client-auth — Completion

**Status:** Code complete and analyze-verified (`flutter analyze` clean — zero errors,
only pre-existing lint infos/warnings unrelated to this change). All acceptance criteria
(MA1–MA12) are implemented; MA8/MA9 (real 2FA sign-in, including recovery code) and the
on-device storage inspection items in §8 need a running app against a real Checkpoint
backend and were not manually exercised this session — see Follow-up work.

## Summary

`afsmobile_flutter` (a separate Dart/Flutter repo from this plan's own `w` monorepo)
shipped a complete, documented, reachable secondary-user login path in its vendored
`packages/checkpoint` Checkpoint SDK, backed by plaintext `SharedPreferences`, even
though mobile is meant to be primary-only like desktop. This step removed that
capability outright, moved the remaining persisted data to secure storage with a
one-time migration off the old plaintext keys, added a defense-in-depth identity
assertion, built the missing 2FA sign-in flow, and registered `APPFACTORY_MOBILE`.

1. **Deleted the secondary-login capability (§3, MA1–MA3).**
   `packages/checkpoint/lib/src/client_handler.dart` lost `loginSecondary()`,
   `registerSecondary()`, `completeAccountSelection()`, the `LoginResult` type (only
   existed to serve those methods), and the library-doc `loginSecondary` usage example.
   `packages/checkpoint/lib/src/secondary_users.dart` deleted outright, along with its
   export from the public `checkpoint_client.dart` and its import/field wiring
   (`secondaryUsers`) in the internal `src/checkpoint_client.dart`. The public library
   doc's own "Secondary Users" usage example and feature-list bullet were removed too —
   same reasoning as the method-level docstring: an example is an instruction to use it.
2. **`ClientUserType` kept, not narrowed** — a deliberate deviation from the plan's §3.1
   suggestion. The enum's `secondary` value has no live producer anymore, but a session
   persisted by an older build still has the string `'secondary'` on disk; layer 2 (next
   item) needs that value to parse back correctly to detect and reject it. Narrowing the
   enum would have made that silently impossible instead of raising a compile error,
   which is a worse outcome for a primary-only guarantee.
3. **Secure storage + migration (§3.3, MA6–MA7).** `_kAccessToken`/`_kRefreshToken`/
   `_kTokenExpires` deleted entirely — no code path produces a secondary token again, so
   nothing migrates them forward. `_kUserType`/`_kUserData` (profile data, not
   credentials — `loginNormal()` never populated tokens on `AuthState` even before this
   change) moved from `SharedPreferences` to `FlutterSecureStorage`, reusing the same
   Android/iOS options `SecureTokenStorage` already used (pulled out to shared constants
   in `core/storage.dart` rather than duplicated). `_migrateLegacyPrefs()` runs once on
   first load: copies the legacy user-type/data into secure storage if present (so a
   stale secondary session can still be positively identified once), then unconditionally
   purges all five legacy `SharedPreferences` keys, including any leftover secondary
   token — those are abandoned, never carried forward.
4. **Layer 2 defense-in-depth, adapted from the plan's illustrative snippet (§4.1,
   MA4–MA5).** The plan's snippet checks `state.user?['type']`; that field doesn't exist
   anywhere in this codebase's `User`/`SecondaryUser` JSON shapes (verified before
   writing code), so copying it literally would have made `_assertPrimary` a no-op — a
   silent gap in exactly the control it's supposed to add. Implemented instead against
   `AuthState.userType == ClientUserType.secondary`, the discriminator `ClientHandler`
   already sets correctly (including for a migrated legacy session). Added
   `AuthRepository._assertPrimary()`, called after `login()`'s success path,
   `completeTwoFactor()`'s success path, and `getCurrentUser()` (which swallows the
   thrown exception and returns `null` — a cold-start rejection should look like "not
   logged in," not an error dialog). A new public `ClientHandler.clearAuth()` clears
   local storage only, without a server round trip, distinct from `logout()`.
5. **Built the missing 2FA flow (§6, MA8–MA9).** The SDK-level piece
   (`auth.completeTwoFactor()`, `packages/checkpoint/lib/src/auth/auth.dart:168-187`) was
   already correct and already persisted through the secure `ITokenStorage` — no SDK/
   server change needed. Added `ClientHandler.completeTwoFactor()` mirroring
   `loginNormal()`'s shape; `AuthRepository` gained `pendingTwoFactorToken` (in-memory
   field only, mirroring the desktop client's shipped `pendingTwoFactorToken`
   closure-variable pattern) and `completeTwoFactor(code)`; `AuthBloc` gained
   `TwoFactorCodeSubmitted` and states `TwoFactorRequired`/`SecondaryRejected` (the latter
   distinct from `AuthFailure` so the UI doesn't fold "sub-accounts can't sign in here"
   into a generic wrong-password message — MA10); `login_page.dart` gained a conditional
   2FA-code step (TOTP or recovery code, `autofillHints: [AutofillHints.oneTimeCode]`)
   since the page previously had no step/wizard pattern to extend.
6. **`APPFACTORY_MOBILE` registered (§5, MA11).** Added as a plain
   `const String checkpointAppName` in `lib/config.dart`, documented as *not* wired into
   `CheckpointConfig` (which has no app-name field distinct from the unrelated OAuth
   `clientId`) and *not* scoping this client's own sessions — per plan 10 §5.2, primary
   tokens carry no `app_name` claim, so this exists solely so a secondary token can never
   be minted for this client server-side.
7. **Package README created (§3.2, MA12)** — didn't exist before. Records that this is a
   local vendored copy of apps/auth's SDK, that secondary-user support was deliberately
   removed, and what to re-remove if a future sync from upstream restores it.

## Files changed

- `packages/checkpoint/lib/src/client_handler.dart` — secondary paths, `LoginResult`,
  and the docstring example removed; storage rewritten onto `FlutterSecureStorage` with
  legacy-prefs migration; `completeTwoFactor()` and `clearAuth()` added; `AuthState`
  simplified (dropped the token/expiry fields only the secondary flow ever populated).
- `packages/checkpoint/lib/src/secondary_users.dart` — deleted.
- `packages/checkpoint/lib/src/checkpoint_client.dart` — `secondaryUsers` field/import
  dropped.
- `packages/checkpoint/lib/checkpoint_client.dart` — export dropped; library doc's
  Secondary Users example/bullet removed.
- `packages/checkpoint/lib/src/core/storage.dart` — Android/iOS secure-storage options
  pulled out to shared constants for reuse by `client_handler.dart`.
- `packages/checkpoint/README.md` — new.
- `lib/repositories/auth_repository.dart` — `SecondaryNotPermittedException`,
  `_assertPrimary()`, `pendingTwoFactorToken`, `completeTwoFactor()`.
- `lib/bloc/auth/auth_event.dart`, `auth_state.dart`, `auth_bloc.dart` —
  `TwoFactorCodeSubmitted` event; `TwoFactorRequired`/`SecondaryRejected` states; handlers
  wired, including catching `SecondaryNotPermittedException` from the repository.
- `lib/pages/login_page.dart` — 2FA code-entry step (with recovery-code toggle) and a
  distinct `SecondaryRejected` message, replacing the single-form-only layout.
- `lib/config.dart` — `checkpointAppName` constant.

## Verification steps

- `flutter pub get` — resolved cleanly against the existing `flutter_secure_storage`
  constraint already in the package's `pubspec.yaml` (no version bump needed).
- `flutter analyze lib packages/checkpoint/lib` — 43 issues, all pre-existing
  info/warning-level lints in files this change didn't touch (unrelated calculator/
  domain pages, framework-level `dangling_library_doc_comments`/`unnecessary_library_name`
  style notes already present in the checkpoint package). Zero errors; zero new lints
  introduced by this change's own files.
- `grep -rn "secondary" lib/ packages/checkpoint/lib/ -i` (MA1) — every hit is either the
  rejection/migration code and its comments, or unrelated same-word matches: Material's
  own `ColorScheme.secondary`/`secondaryContainer` fields, an SSO "secondary domain"
  comment in `auth.dart` (a different, pre-existing cross-domain concept, same caveat the
  desktop completion doc noted), and CSS-like tokens inside an unrelated JSON fixture
  (`lib/models/_test3.json`).
- Read-verified `secondary_users.dart` is gone and nothing imports it (compile-clean via
  `flutter analyze`, which would have errored on a dangling import).
- Read-verified `pendingTwoFactorToken` has no write path to `SharedPreferences` or
  secure storage anywhere in `AuthRepository` — it's a plain in-memory field.
- Read-verified `_assertPrimary()` is called from all three producing paths: `login()`,
  `completeTwoFactor()`, `getCurrentUser()`.
- **Not done this session:** launching the app and manually exercising the plan's §8
  table — in particular, a real 2FA-enabled account (code + recovery code), a
  pre-seeded legacy secondary session on an actual device/emulator to confirm it's
  rejected and cleared on launch, and inspecting on-device storage to confirm no
  plaintext token/profile survives. These need a running app and a real backend/account,
  which this session did not have.

## Follow-up work

1. Manually run the plan's §8 test table against a running build — primary login, wrong
   password, 2FA (code + recovery code), a pre-seeded legacy secondary session, fresh-
   install storage inspection, token refresh, airplane mode, sign-out, and backup
   inspection. None of these were exercised this session.
2. Confirm with the auth-side team that `APPFACTORY_MOBILE` is registered server-side to
   match (this step only added the mobile-side constant — plan 10 §5 is the server-side
   registry entry, and per `TODOS.md`, step 10 has not been started).
3. `packages/afsserver/plans/checkpoint-server-auth/08-mobile-client-auth.md` itself
   could get the same status-column/per-row annotation treatment step 07 gave its own
   plan doc, if that convention should continue.
