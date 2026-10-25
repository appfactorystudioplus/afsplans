# 12 — Joystick Engine client (checkpoint_cpp)

## Completion document

When this plan step is completed, write `complete/12-game-engine-client.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using Doxygen-style `/** */` blocks, matching
the standard already set in `libs/checkpoint_cpp` — which is high. Every public type,
method, and non-obvious branch explains purpose, parameters, return values, side effects,
and plan references.

**Two things must carry a comment wherever they appear:**

1. The capability seam (§5) **allows everything today**. Without a comment saying so, the
   next reader assumes it already restricts something and stops checking the server.
2. Client-side capability checks are **UX, not security** (§5.3). The engine binary runs on
   the user's machine.

---

## 1. Requirement

> The Joystick Engine is a desktop game engine using `libs/checkpoint_cpp`.
>
> - **Secondary users are allowed to sign in** — unlike the desktop and mobile apps.
> - Add a **guard seam** for secondary users: nothing to restrict yet, but a place to put
>   restrictions later.
> - **2FA must work** for both primary and secondary users, with the UI to run it.
> - Update anything the rest of this plan changed underneath it.

## 2. Current state, verified

Read against `JoystickEngine_v2` on 2026-08-22.

### 2.1 Architecture

```
JoystickLauncher/src/Auth/SignInView.cpp     ImGui-style sign-in UI
        │  StartSignIn(SignInKind::Normal | ::Secondary)
        ▼
libs/checkpoint_cpp  DesktopHandler::SignIn()
        │  build login URL → open system browser → block on loopback listener
        │  branch: multi-account / secondary / normal
        ▼
auth15.appfactory.studio hosted login page  ← the user actually types here
        │  redirect to http://127.0.0.1:<port>/…
        ▼
LoopbackListener → ticket exchange → Session → ITokenStore (FileTokenStore)
```

Config from `Resources/EngineConfig.json`:

```jsonc
"checkpoint": {
  "ssoDomain": "https://auth15.appfactory.studio",
  "baseUrl":   "https://auth15.appfactory.studio",
  "appName":   "GAME_EDITOR",
  "refreshThresholdSeconds": 300,
  "loopbackTimeoutSeconds":  300
}
```

### 2.2 What is already right — and better than the other clients

This library is the **best-implemented Checkpoint client in the platform**. Stated plainly
because most of this step is small additions to good code, not repair.

| Item | Detail |
|---|---|
| **2FA is already handled correctly** | `Auth::Login()` (`src/Auth.cpp:66–74`) checks `requires_2fa` and returns a `TWO_FACTOR_REQUIRED` error whose message *names the correct flow*: "use the browser-based SSO flow (DesktopHandler), where 2FA is handled on Checkpoint's own pages" |
| Browser-based login | 2FA, password reset, and any future auth step happen on auth's own pages — the engine never sees a credential |
| Secondary sign-in | `SignInKind::Secondary`, with a mandatory multi-account picker (`AccountPickerCallback`) |
| Multi-account branch | Implemented, and its docblock explains why skipping it "breaks any user who belongs to two studios" |
| App identity | `appName: GAME_EDITOR`, guarded server-side by coordinator's `EngineCheckpointAuthMiddleware` (`REQUIRED_APP_NAME = 'GAME_EDITOR'`) |
| Token file permissions | `FileTokenStore` sets POSIX `0600`, with a Windows ACL equivalent |
| Auto-refresh | `RefreshThresholdSeconds`, plus one retry on 401 — "one retry, not a loop", per its own comment |

**The `Auth::Login()` 2FA branch is the model the other two clients should have copied.**
afsdesktop5 throws `'Two-factor authentication required'` with no route forward
([07](07-desktop-client-auth.md) §2.5); this returns a typed error naming the flow that
works.

### 2.3 Finding E1 — `AppNames.h` is out of sync

`include/Checkpoint/AppNames.h` defines **seven** constants:

```
Members · Coordinator · NotesEditor · PdfEditor · FlowEditor · GraphEditor · GameEditor
```

The TypeScript and PHP registries define **nine** — the seven above plus `MARKET` and
`APPFACTORY_STUDIO`. [10](10-primary-only-enforcement.md) §5 adds a tenth,
`APPFACTORY_MOBILE`.

So the C++ copy is missing two entries today and will be missing three.

### 2.4 Finding E2 — the header's own sync claim is false

`AppNames.h`'s docblock says:

> *"Mirrors apps/auth/…/CheckpointAppName.php and every app's
> resources/js/checkpoint/app-names.ts (all 8 copies are kept byte-identical by
> apps/afsserver/bin/sync-checkpoint-client.sh)."*

`sync-checkpoint-client.sh` exists and is good — it syncs four files, including
`app-names.ts`, and has a `--check` mode for CI. But:

```bash
TARGETS=(members coordinator dream_graph notes pdf_editor flow auth)
```

**No C++ target. No `market` target either.** The script cannot sync a `.h` file from a
`.ts` source, and it does not try.

So the C++ header is maintained **by hand**, while its own documentation says it is
maintained mechanically. That is precisely how E1 happened and went unnoticed — and it is
worse than an ordinary stale comment, because it tells a reader not to bother checking.

### 2.5 Finding E3 — `SecondaryUsers::Login()` does not check `requires_2fa`

`Auth::Login()` handles it (§2.2). `src/SecondaryUsers.cpp` does not — a grep for
`requires_2fa` there returns nothing.

Harmless today, because [09](09-two-factor-authentication.md) §2.2 established that auth has
**no 2FA for secondary users at all**, so the field never appears.

**It stops being harmless the moment step 09 ships.** Auth will then return
`requires_2fa` (or `requires_2fa_enrolment`) with no token pair, and this code path will
fall through to whatever its "no access_token" branch does — surfacing a
`MALFORMED_RESPONSE`-class error instead of the actionable message its primary-user twin
produces.

**This is an ordering dependency, not an independent bug**: step 09 breaks the engine's
native secondary login unless this lands with it.

### 2.6 Finding E4 — no capability seam

`Session` carries `UserType::Secondary`, and `SignInView.cpp:139` branches on it for
display. There is no place to ask *"may this identity do X?"* — so the first restriction
that is ever wanted will be written inline at whatever call site needed it, and the second
somewhere else.

That is what §5 creates.

### 2.7 Finding E5 — session file is not encrypted at rest

`FileTokenStore` protects the session with **file permissions only** — `0600` on POSIX, an
ACL on Windows. The Windows path's own comment concedes it is *"NOT independently
runtime-verified"*.

Compare: afsdesktop5 encrypts through Electron `safeStorage` (DPAPI/Keychain-backed);
mobile uses `flutter_secure_storage`.

Permissions are a real control and this is defensible for a desktop tool. But it is the
weakest at-rest protection of the three clients, and the platform-native primitives
(`CryptProtectData` on Windows, Keychain on macOS, libsecret on Linux) are available.
Assessed in §7 — this is a **recommendation, not a defect**.

### 2.8 Finding E6 — `loopbackTimeoutSeconds: 300` versus 2FA enrolment

The loopback listener blocks for up to **5 minutes** while the user completes login in the
browser. That is generous for typing a password and a 6-digit code.

It is **not** generous for [09](09-two-factor-authentication.md) §3.3's forced enrolment:
install an authenticator app, scan a QR, confirm a code, then read and save recovery codes.
A first-time user doing that on a phone they have to go and find will exceed 300 seconds.

The failure is silent and confusing: the browser succeeds, the engine has already given up,
and the user is signed in *on the website* but not *in the engine*.

## 3. Registry sync (E1, E2)

### 3.1 Add the missing constants

```cpp
inline constexpr const char* Market            = "MARKET";
inline constexpr const char* AppfactoryStudio  = "APPFACTORY_STUDIO";
inline constexpr const char* AppfactoryMobile  = "APPFACTORY_MOBILE";  // plan 10 §5
```

The header already explains that most constants exist "for completeness with the PHP/TS
lists" rather than for use here. Keep that framing and extend it: `APPFACTORY_STUDIO` and
`APPFACTORY_MOBILE` are **primary-only** app names ([10](10-primary-only-enforcement.md)),
which the engine will never present — noting that is cheaper than a future reader wondering
whether the engine should be using one.

### 3.2 Fix the false claim

Replace the "kept byte-identical by sync-checkpoint-client.sh" sentence with what is true:

```cpp
/**
 * Mirrors apps/auth's CheckpointAppName.php and every app's
 * resources/js/checkpoint/app-names.ts.
 *
 * MAINTAINED BY HAND. apps/afsserver/bin/sync-checkpoint-client.sh syncs the
 * TypeScript copies to each other; it has no C++ target and cannot generate
 * a .h from a .ts. An earlier version of this comment claimed the script kept
 * this file in sync, which was never true — and that claim is why MARKET and
 * APPFACTORY_STUDIO were missing here for months while every other copy had
 * them (plan 12 §2.3–2.4).
 *
 * Adding an app name means touching FOUR places: the PHP enum, app-names.ts
 * (synced onward by the script), this header, and the Dart client.
 */
```

### 3.3 Make the drift detectable

A comment does not catch drift; a check does. Extend `sync-checkpoint-client.sh --check`
with a **parity assertion**, not a sync: parse the app-name string literals out of
`AppNames.h` and compare the *set* against `app-names.ts`'s `CHECKPOINT_APPS`.

It cannot rewrite the C++ file — the shapes differ — but it can fail CI saying exactly
which names are missing from which copy. That converts E1 from "noticed in a year" to
"noticed on the next commit".

Include the Dart client in the same check ([08](08-mobile-client-auth.md)) so all four
copies are covered by one assertion.

> **Also fix the script's own gap while here:** its `TARGETS` list omits `market`, which
> has a `resources/js/checkpoint/` directory of its own. Same class of miss.

## 4. Secondary users in the engine

**Confirmed: secondary users may sign in to the Joystick Engine.** `GAME_EDITOR` stays in
`UI_APP_ID_TO_CHECKPOINT`, and stays out of `PRIMARY_ONLY_APPS`
([10](10-primary-only-enforcement.md) §3.2).

The engine is therefore the **only native client** where secondary sign-in is permitted —
desktop and mobile are barred. Worth a comment at `SignInKind::Secondary`, because the
platform rule elsewhere is the opposite and someone will otherwise "align" this with the
others.

### 4.1 What already works

`SignInView.cpp` already offers both paths (`:171` Normal, `:174` Secondary), implements the
multi-account picker (`:43`, `:53`), and branches on `UserType::Secondary` (`:139`). No
change needed for sign-in itself.

### 4.2 Fix `SecondaryUsers::Login()` (E3)

Mirror `Auth::Login()`'s branch exactly, including the pointer to the working flow:

```cpp
// Mirrors Auth::Login()'s 2FA branch. Secondary users gain 2FA in plan step
// 09; before that step this field never appears, and after it this path would
// otherwise fall through to a "no access_token" MALFORMED_RESPONSE — a
// confusing error for an account that is correctly configured.
//
// The engine deliberately does NOT implement a native code-entry UI: 2FA lives
// on Checkpoint's own pages, reached through DesktopHandler's browser flow
// (§61.2.2, plan 12 §5).
if (data.value("requires_2fa", false) || data.value("requires_2fa_enrolment", false))
{
    Error error;
    error.Code = "TWO_FACTOR_REQUIRED";
    error.Message = "This account requires two-factor authentication — use "
                    "Sign in as team member, which completes 2FA in your browser.";
    return Result<SecondaryLoginResult>::Failure(std::move(error));
}
```

## 5. The capability seam (E4)

What was asked for: a guard for secondary users with nothing to guard yet — a place for
future restrictions.

### 5.1 Shape

```cpp
/**
 * @file Capabilities.h
 * @brief The one place the engine asks "may this identity do X?".
 *
 * ── READ THIS BEFORE USING ─────────────────────────────────────────────
 * TODAY THIS ALLOWS EVERYTHING, FOR EVERY IDENTITY TYPE. It is a seam, not
 * a restriction (plan 12 §5). Secondary users have exactly the same engine
 * capabilities as primary users right now, and adding this must change no
 * behaviour whatsoever.
 *
 * It exists so that the FIRST restriction anyone wants is one line in
 * Policy() rather than an `if (session.Type == Secondary)` written inline
 * at whatever call site happened to need it — which is how the second and
 * third end up somewhere else entirely.
 *
 * CLIENT-SIDE CHECKS ARE UX, NOT SECURITY (§5.3). The engine binary runs on
 * the user's machine and can be modified. A capability that actually matters
 * MUST also be enforced by whichever server owns the operation.
 */
namespace Checkpoint {

enum class Capability {
    PublishToMarket,        // market: publish/update a package
    ManageSyncTokens,       // dream_graph: mint/revoke tilemap sync tokens
    RegisterProject,        // afs-connect: register a project with coordinator
    ModifyProjectSettings,
    ExportBuild,
};

/// Verdict plus a reason, so a UI can explain rather than merely disable.
struct CapabilityVerdict {
    bool Allowed = true;
    std::string DeniedReason;   // empty when Allowed
};

CapabilityVerdict Can(const Session& session, Capability capability);

}
```

### 5.2 Implementation today

```cpp
CapabilityVerdict Can(const Session& session, Capability capability)
{
    // Deliberately unconditional. See the header: this is a seam awaiting a
    // policy, and shipping it as a no-op is what makes it safe to add now —
    // no behaviour changes, and the call sites exist when a rule is wanted.
    //
    // A future restriction looks like:
    //
    //   if (session.Type == UserType::Secondary &&
    //       capability == Capability::PublishToMarket)
    //       return { false, "Publishing is limited to the account owner." };
    //
    // …and the server that owns publishing must reject it too (§5.3).
    (void) session;
    (void) capability;
    return { true, {} };
}
```

### 5.3 Why the server still has to check

A capability check compiled into a binary on the user's disk is an affordance: it greys out
a button and explains why. It is not a boundary — the binary can be patched, and the HTTP
call it guards can be made by hand.

So every capability that matters is enforced **twice**:

| Capability | Server that must also enforce |
|---|---|
| `PublishToMarket` | market — `market.engine` bearer surface |
| `ManageSyncTokens` | dream_graph — `/api/projects/{id}/tilemap-sync-tokens` (already gated `checkpoint.member`) |
| `RegisterProject`, project settings | coordinator — `/api/engine/v1/*`, `EngineCheckpointAuthMiddleware` |

Coordinator's engine middleware already sets `engine_user_type` (`'normal'|'secondary'`) on
the request, so the server side of a future restriction has the identity it needs **today**.
That is the half that is a real control; §5.1 is the half that makes the UI honest.

### 5.4 Where to call it

At the point of *offering* an action, not deep inside performing it — a disabled button with
a tooltip beats an error after a click:

`ConnectPanel.h` (project registration, task updates) · the Market panel (publish) · sync
token management · build/export.

Each call site gets a one-line comment naming the capability, so `grep Capability::` lists
every guarded action.

## 6. Two-factor authentication

### 6.1 The engine needs no native 2FA UI, and should not have one

This is the substantive design point, and it is already how the library works.

Because sign-in happens in the **system browser** on auth's own pages, 2FA is handled
there — the code-entry modal `login.blade.php:123–133` already ships. The loopback callback
fires only after auth is satisfied, so the engine sees an authenticated ticket and nothing
else.

Consequences:

- **The engine never handles a TOTP code, a pending token, or a recovery code.** It cannot
  leak or mis-store what it never receives.
- **2FA improvements land in one place.** When [09](09-two-factor-authentication.md) adds
  secondary 2FA to the hosted page, the engine gets it with no engine release.
- **A native code-entry UI would be a downgrade.** It would mean the engine handling
  credentials and pending tokens directly — exactly what the browser flow avoids.

So "implement 2FA and a 2FA UI" resolves, for this client, to: **make sure the browser flow
is always the one used, and make the alternative fail with a message that says so.**
`Auth::Login()` already does this; §4.2 fixes the secondary twin.

### 6.2 Raise `loopbackTimeoutSeconds` (E6)

`300` is too short once a first-time secondary user must enrol an authenticator mid-login.

```jsonc
"loopbackTimeoutSeconds": 900,   // 15 min — 2FA enrolment needs more than a password
```

And the timeout must **fail visibly**: "Sign-in timed out waiting for your browser. If you
finished signing in, click Sign in again — your session on the website is unaffected." A
silent timeout after a successful browser login is the confusing case (§2.8).

Better still, if the listener supports it: keep listening and let the *user* cancel, rather
than racing a wall clock against a person hunting for their phone.

### 6.3 Surface `TWO_FACTOR_REQUIRED` properly

If any UI path still calls `Auth::Login()` / `SecondaryUsers::Login()` directly, its error
must render as an actionable message with a button that starts the browser flow — not as a
raw error code. The error text already says what to do; the UI has to show it.

### 6.4 What the engine must *not* do

- Do **not** add `/checkpoint/auth/2fa/*` calls to `checkpoint_cpp`.
- Do **not** persist a `two_factor_token` or pending token — the engine never receives one.
- Do **not** add a "remember this device" toggle. That is a Checkpoint-side concept and
  duplicating it client-side creates a second, weaker source of truth.

## 7. Session storage (E5)

**Recommendation, not a defect.**

`FileTokenStore`'s `0600` + Windows ACL is a real control. Hardening options, cheapest
first:

| Option | Cost | Gain |
|---|---|---|
| **A.** Verify the Windows ACL path at runtime — its own comment admits it is unverified | Small | Confirms the control that is claimed |
| **B.** `CryptProtectData` (Windows), Keychain (macOS), libsecret (Linux) behind `ITokenStore` | Moderate; three platform impls | Parity with afsdesktop5 and mobile |
| **C.** Leave as is, documented | None | Status quo |

`ITokenStore` is already an interface, so **B is additive** — a new implementation beside
`FileTokenStore`, selected per platform, with no call-site changes. That design choice was
made well in advance.

Recommend **A now, B when convenient**. The session is a bearer credential with a 30-day
refresh token; on a shared or backed-up machine, file permissions alone are thin.

## 8. What this step does *not* change

- The loopback/ticket SSO architecture. It is correct and is why 2FA is nearly free here.
- Secondary sign-in support. Confirmed as wanted.
- `GAME_EDITOR` as the app name, or its coordinator-side guard.
- Any engine feature behaviour — §5 ships as a no-op by construction.

## 9. Acceptance

| # | Criterion |
|---|---|
| GE1 | `AppNames.h` contains every name in the TypeScript/PHP registries, including `MARKET`, `APPFACTORY_STUDIO`, `APPFACTORY_MOBILE` |
| GE2 | Its docblock states the header is hand-maintained and names all four places an app name must be added |
| GE3 | `sync-checkpoint-client.sh --check` fails when the C++, Dart, TS, or PHP name sets diverge, naming the missing entries |
| GE4 | `sync-checkpoint-client.sh` includes `market` in `TARGETS` |
| GE5 | `SecondaryUsers::Login()` returns `TWO_FACTOR_REQUIRED` for `requires_2fa` **and** `requires_2fa_enrolment`, with a message naming the browser flow |
| GE6 | `Checkpoint::Can()` exists, is called at every action in §5.4, and **allows everything** |
| GE7 | Adding §5 changes no engine behaviour for any identity type — verified by running the existing suite before and after |
| GE8 | A secondary user signs in to the engine and reaches full functionality, exactly as before |
| GE9 | A primary user with 2FA signs in via the browser flow with no engine-side prompt |
| GE10 | Once [09](09-two-factor-authentication.md) ships, a secondary user with `require_2fa` completes enrolment in the browser and the engine receives the session |
| GE11 | `loopbackTimeoutSeconds` ≥ 900, and a timeout produces the §6.2 message |
| GE12 | `grep -rn "2fa\|two_factor" libs/checkpoint_cpp/src/` returns only the two `TWO_FACTOR_REQUIRED` guard branches — no code path handles a code or a pending token |
| GE13 | The Windows ACL in `FileTokenStore` is runtime-verified (§7 option A) |

**GE7 is the one that matters most.** The capability seam is a refactor with no intended
behavioural effect; if it changes anything, it is wrong.

## 10. Testing

| Test | Asserts |
|---|---|
| Primary sign-in via browser flow | Session persisted; engine authenticated |
| Primary with 2FA enabled | Code entered in browser; engine receives session, prompts for nothing (GE9) |
| Secondary sign-in, single account | Works as today (GE8) |
| Secondary sign-in, multiple accounts | Picker appears; chosen account's session persisted |
| Secondary with `require_2fa`, already enrolled | Code in browser; engine receives session |
| Secondary with `require_2fa`, **not** enrolled | Full enrolment in browser; engine receives session (GE10) — the test that justifies GE11 |
| `Auth::Login()` with a 2FA account | `TWO_FACTOR_REQUIRED`; UI offers the browser flow |
| `SecondaryUsers::Login()` with a 2FA account | Same, not `MALFORMED_RESPONSE` (GE5) |
| Browser login that takes 10 minutes | Succeeds at 900 s; a real timeout shows the §6.2 message |
| Remove `MARKET` from `AppNames.h` | `--check` fails naming it (GE3) |
| `Can()` for every capability, both identity types | All allowed (GE6) |
| Full engine regression, before/after §5 | Identical (GE7) |
| Session file permissions, Windows and POSIX | Owner-only, verified at runtime (GE13) |

## 11. Files

| Path | Action |
|---|---|
| `libs/checkpoint_cpp/include/Checkpoint/AppNames.h` | Add three constants; correct the sync claim |
| `libs/checkpoint_cpp/include/Checkpoint/Capabilities.h` | New — the seam |
| `libs/checkpoint_cpp/src/Capabilities.cpp` | New — allow-all implementation |
| `libs/checkpoint_cpp/src/SecondaryUsers.cpp` | 2FA guard branch (E3) |
| `libs/checkpoint_cpp/src/FileTokenStore.cpp` | Runtime-verify the Windows ACL |
| `libs/checkpoint_cpp/CMakeLists.txt` | Add `Capabilities.cpp` |
| `Resources/EngineConfig.json` | `loopbackTimeoutSeconds: 900` |
| `JoystickLauncher/src/Auth/SignInView.cpp` | Surface `TWO_FACTOR_REQUIRED` actionably; timeout copy |
| `JoystickWindow/src/Panels/ConnectPanel.h` | `Can()` call sites (§5.4) |
| Market / sync-token / export UI | `Can()` call sites |
| `apps/afsserver/bin/sync-checkpoint-client.sh` | Name-parity check across four languages; add `market` |
