# 02-call-ux-and-notifications — Completion (partial)

**Status:** §1 (push-to-ring) explicitly deferred, not built. §2's background-audio continuation
and persistent notification are built for Android and iOS. §2's picture-in-picture is not built
(the plan document itself already scoped PiP as v2, not baseline). §3 (contextual permissions)
needed no new code — the existing join flow already satisfies it, explained below. **No
device/emulator run was done this session** — this machine has no Android SDK and no Xcode (see
Verification), a harder constraint than the previous slice's "no device available."

## Decision recorded before writing code

§1's own text flagged an open decision: whether an invited member should be "rung" with a native
incoming-call screen (push-to-ring, CallKit/ConnectionService, cold-start-to-join), which the
signaling model has no event for today. Checked first: `afsmobile_flutter` has **no push
infrastructure of any kind** — no `firebase_messaging`, no APNs setup, confirmed by search, not
assumed. Building push-to-ring would mean a new cross-repo signaling event plus push delivery
plus a native call-screen bridge, all from nothing. **Decided: out of scope for v1** — mobile
stays join-by-code/link only, the same scope `01` already built. `02-call-ux-and-notifications.md`
§1 updated with this decision.

## §2 — Background behavior

**A second, harder constraint surfaced here and was raised before writing any native code:** this
machine has no Android SDK and no Xcode (`flutter doctor` confirms the Android toolchain is
missing; Xcode isn't available on Windows at all). Background-audio continuation genuinely needs
native platform code — an Android foreground service, an iOS `UIBackgroundModes` declaration —
which cannot even be *compiled* here, a stricter limit than "no device to run it on." Asked how to
handle that risk; the choice was to use an established, already-tested plugin for the native side
rather than hand-write unverified native code.

- **Android**: [`flutter_foreground_task`](https://pub.dev/packages/flutter_foreground_task)
  `^9.2.2` (resolves cleanly against this project's existing constraints — checked via
  `flutter pub add --dry-run` before adding it for real). New
  [`lib/services/call_foreground_service.dart`](../../../../../afsmobile_flutter/lib/services/call_foreground_service.dart):
  `init()` (called once from `main.dart`), `start()`/`stop()` (called from `CallBloc` at connect,
  disconnect, leave, and `close()`). Starts a foreground service (types `microphone` + `camera`,
  matching what the call actually uses, per Android 14+'s foreground-service-type requirement)
  showing a low-importance, silent "Call in progress — tap to return" notification whose
  `notificationInitialRoute` points at `CallPage.routeName`. Runs for the whole call, not only
  while actually backgrounded — toggling it on/off with `AppLifecycleState` would need extra
  state and risks flicker (e.g. a permission dialog briefly backgrounding the app); the
  notification is silent, so showing it for the call's duration is a deliberate simplification.
  `stopWithTask: true` — killing the app from Recents ends the call too, since there is no
  CallKit-style continuation in this scope. `AndroidManifest.xml` gained the required permissions
  (`FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_MICROPHONE`, `FOREGROUND_SERVICE_CAMERA`,
  `POST_NOTIFICATIONS`) and the plugin's own `<service>` declaration with an explicit
  `foregroundServiceType`.
- **iOS**: `Info.plist` gained `UIBackgroundModes: [audio]` — the standard mechanism for letting
  an active `AVAudioSession` (which `flutter_webrtc` configures as `playAndRecord` when a call is
  live) keep running when the app is backgrounded, without needing CallKit/PushKit. iOS shows its
  own system indicator for background microphone use automatically, so no custom persistent
  notification was built for iOS — there is no equivalent to build; `flutter_foreground_task`'s
  own iOS story is a periodic-fetch mechanism unrelated to this, and its iOS-specific
  `AppDelegate` wiring was deliberately skipped since nothing here needs it.
- **Not verified — genuinely can't be, on this machine**: that the Android foreground service
  actually starts, that its notification appears and its "tap to return" route works, that
  `flutter_webrtc`'s `AVAudioSession` category assumption on iOS holds in practice, and that audio
  actually keeps flowing on either OS once backgrounded. All of this needs a real build on a
  machine with the Android SDK (for Android) or a Mac with Xcode (for iOS), neither of which this
  session had. `flutter analyze`/`flutter test` only prove the Dart side is structurally sound;
  they cannot compile-check the native manifest/plist changes at all.
- **Picture-in-picture**: not built. The plan document itself scopes this as "a v2 nicety, not
  baseline" — left alone, not a gap in this slice.

## §3 — Permissions

**No new code needed.** Checked (not assumed) how `flutter_webrtc` (which `livekit_client` uses
transitively) requests camera/microphone permission, by reading the installed package's own
Android source
(`GetUserMediaImpl.java`'s `checkSelfPermissions`/`requestPermissions`): it checks and requests
the OS runtime permission itself, at the exact moment `getUserMedia` (i.e.
`setMicrophoneEnabled`/`setCameraEnabled`) is called — which in this app only happens inside
`CallBloc._connect()`, i.e. when a person actually joins a call, not at app launch. iOS behaves
the same way natively (the system prompts automatically on first camera/mic access). §3's
requirement — "requested contextually, at first use of each capability, not all up front" — was
already satisfied by the existing join-by-code design from `01`; no `permission_handler` package
or explicit request code was added, since there was nothing missing to add.

## Files

| Where | What |
|---|---|
| `afsmobile_flutter/lib/services/call_foreground_service.dart` (new) | Android foreground-service wrapper: `init()`/`start()`/`stop()`, no-op on non-Android platforms. |
| `afsmobile_flutter/lib/main.dart` | `CallForegroundService.init()` before `runApp()`. |
| `afsmobile_flutter/lib/bloc/call/call_bloc.dart` | `CallForegroundService.start()` after a successful connect; `.stop()` on room-disconnected, leave-requested, and bloc `close()`. |
| `afsmobile_flutter/android/app/src/main/AndroidManifest.xml` | Foreground-service permissions + the plugin's `<service>` declaration. |
| `afsmobile_flutter/ios/Runner/Info.plist` | `UIBackgroundModes: [audio]`. |
| `afsmobile_flutter/pubspec.yaml`/`pubspec.lock` | New dependency `flutter_foreground_task ^9.2.2`. |

## Verification

- `flutter pub add flutter_foreground_task --dry-run` — resolved with no version conflicts before
  adding it for real (avoiding a repeat of `01`'s `livekit_client` conflict).
- Every plugin API call (`FlutterForegroundTask.init`/`startService`/`stopService`, notification
  option classes, `ForegroundServiceTypes`, `TaskHandler`) was checked against the exact installed
  package source (`flutter_foreground_task-9.2.2`'s own `lib/` files) before being used, not
  written from memory/docs of a possibly different version.
- `flutter analyze` — **27 issues, all pre-existing**, identical set to `01`'s completion report;
  zero new lints or errors from anything this step touched.
- `flutter test` — **39/39 passing** (unchanged count — this step added no new Dart-testable
  surface; the foreground-service logic is a thin platform wrapper with no pure logic worth unit
  testing in isolation).
- `flutter doctor -v` — confirms no Android SDK, no Xcode; native manifest/plist changes are
  **unverified even at compile time**, see the "Not verified" note under §2 above.

## Follow-up work

1. **Verify the native pieces on a machine with the Android SDK and a Mac with Xcode** — the
   single most important gap, larger than usual since this session couldn't even compile-check
   the native side (previous slices at least had `flutter analyze`/`flutter test` covering
   everything they touched).
2. `03` (mobile screen share), `04` (mobile-scoped advanced features) — not started.
3. If push-to-ring is wanted later: raise a signaling-contract addition on the master plan first
   (`../03-signaling-and-session-model.md`), then build FCM/APNs delivery and
   `flutter_callkit_incoming` — this is a materially larger effort than anything built in `01`/`02`
   so far, not a small addition.
