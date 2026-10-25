# 03-mobile-screen-share — Completion (Android only)

**Status:** Android screen share is built and wired end to end into the call UI. iOS is
deliberately not attempted this pass — it needs a ReplayKit broadcast extension (a second Xcode
target), which cannot be built or even hand-edited safely on this machine (no Xcode at all,
Windows). Tracked as its own item, not buried in this doc's own follow-up list:
[`plans/TODOS.md`](../../../TODOS.md), "Video communications" §6. **No device/emulator run was
done this session** — same constraint as `01`/`02`, plus `02`'s stricter "can't even
compile-check the native side" limitation, which applies here too.

## What was built (Android)

Two OS-level requirements sit in front of `livekit_client.LocalParticipant.setScreenShareEnabled`,
both confirmed by reading the installed `livekit_client` package's own README rather than
assumed: the user must grant Android's `MediaProjection` capture permission
(`flutter_webrtc`'s `Helper.requestCapturePermission()`), and Android 14+ requires an active
`mediaProjection`-typed foreground service for the capture to keep running, provided here by the
`flutter_background` package (a different plugin from `02`'s `flutter_foreground_task`, per
LiveKit's own setup docs — two separate foreground services for two separate purposes, not a
consolidation opportunity).

New [`lib/services/screen_share_service.dart`](../../../../../afsmobile_flutter/lib/services/screen_share_service.dart):
`isSupported` (Android only, false everywhere else), `start()` (requests capture permission, then
initializes and enables `flutter_background`'s service; returns false without publishing anything
if the user declines at any point), `stop()`. Wired into `CallBloc` via a new `ScreenShareToggled`
event — toggling on calls `ScreenShareService.start()` then `setScreenShareEnabled(true)` only if
that succeeded; toggling off does the reverse. `CallTile` gained a `screenSharing` field (mirrors
`micOn`/`camOn`, read from `Participant.isScreenShareEnabled()`) so both the local tile and the
call UI can reflect share state. `call_page.dart` shows a screen-share toggle button in the call
controls, gated on `ScreenShareService.isSupported` so it simply doesn't appear on iOS/desktop/web
rather than existing as a dead button there.

## Files

| Where | What |
|---|---|
| `afsmobile_flutter/lib/services/screen_share_service.dart` (new) | Android-only wrapper: `isSupported`/`start()`/`stop()`. |
| `afsmobile_flutter/lib/bloc/call/call_event.dart` | New `ScreenShareToggled` event. |
| `afsmobile_flutter/lib/bloc/call/call_state.dart` | `CallTile` gained `screenSharing`. |
| `afsmobile_flutter/lib/bloc/call/call_bloc.dart` | `_onScreenShareToggled` handler; `ScreenShareService.stop()` added alongside the existing `CallForegroundService.stop()` calls at disconnect/leave/close. |
| `afsmobile_flutter/lib/pages/call/call_page.dart` | Screen-share toggle button, shown only when `ScreenShareService.isSupported`. |
| `afsmobile_flutter/android/app/src/main/AndroidManifest.xml` | `FOREGROUND_SERVICE_MEDIA_PROJECTION` permission + `flutter_background`'s own `<service>` declaration (`foregroundServiceType="mediaProjection"`). |
| `afsmobile_flutter/pubspec.yaml`/`pubspec.lock` | New dependency `flutter_background ^1.3.1`; `flutter_webrtc` promoted from transitive to a direct dependency (pinned to the version `livekit_client` 2.6.4 already resolves) since `screen_share_service.dart` imports it directly for `Helper.requestCapturePermission()`. |

## Verification

- `flutter pub add flutter_background --dry-run` — resolved with no conflicts before adding it
  for real.
- Every plugin API used (`FlutterBackground.initialize`/`enableBackgroundExecution`/
  `disableBackgroundExecution`, `FlutterBackgroundAndroidConfig`, `Helper.requestCapturePermission`,
  `LocalParticipant.setScreenShareEnabled`/`isScreenShareEnabled`) was checked against the exact
  installed package sources (`flutter_background-1.3.1`, `flutter_webrtc-1.3.0`,
  `livekit_client-2.6.4`) before being used.
- `flutter analyze` — **27 issues, all pre-existing**, identical set to `01`/`02`'s reports; zero
  new lints or errors. (One real new lint surfaced mid-way — `flutter_webrtc` imported directly
  without being a direct pubspec dependency — fixed by adding it explicitly rather than ignoring
  the lint.)
- `flutter test` — **39/39 passing**, unchanged count (no new Dart-testable logic; this step is a
  thin platform wrapper plus event plumbing, same shape as `02`).
- **Not verified — same "no Android SDK, no Xcode" constraint as `02`**: that
  `Helper.requestCapturePermission()` actually shows the system picker, that the
  `mediaProjection` foreground service starts and survives Android 14+'s runtime checks, that a
  captured screen actually publishes and is visible to other participants, and the toggle button's
  on/off round-trip in the real UI. `flutter analyze`/`flutter test` verify the Dart side compiles
  and behaves correctly in isolation; none of the native or device-dependent behavior above.

## Scope note

Per `03-mobile-screen-share.md` §3, this is screen share only — no mobile screen recording or
draw-over-screen (neither was in scope for mobile per `00`'s non-goals, unrelated to today's work).

## Follow-up work

1. **iOS broadcast extension** — tracked in `plans/TODOS.md`, "Video communications" §6, not
   here, since it needs a different environment (a Mac with Xcode) than anything else in this
   plan tree.
2. **Verify Android screen share on a real device** — the single most important gap for the
   Android side specifically, same category as `02`'s open item but for this feature.
3. `04` (mobile-scoped advanced features), `05` (mobile delivery plan itself) — not started.
