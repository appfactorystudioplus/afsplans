# 01-webrtc-client — Completion (partial: guest join-by-code)

**Status:** Guest join-by-code is built, `flutter analyze`/`flutter test` clean. Hosting (`01`
§4), native call UX (`02`), screen share (`03`), and the rest of `04`/`05` are **not built** —
this is the first slice of the mobile plan, not the whole thing. **No device/emulator run was
done this session** — see Verification and Follow-up work.

## Summary

`afsmobile_flutter` can now join a meeting by code as a guest (or member — the join endpoint
itself doesn't require auth either way) and see a live LiveKit participant grid with mic/camera
controls, matching `01` §3's call-lifecycle contract. Scoped exactly as `05-delivery-plan.md`
sequences it: "guest join-by-code first," host flow, native call UX, and screen share are
explicitly deferred, not attempted partially.

Two things happened before any call code was written, both corrections to the plan docs
themselves rather than new work:

1. **The plan's own "auth is a hard prerequisite, build it first" framing was stale.** Real
   Checkpoint auth for this app already existed and was code-complete
   (`checkpoint-server-auth/08-mobile-client-auth.md`, done 2026-09-19) — the mobile
   video-comms plan just hadn't been updated to know about it. Fixed in `00-overview.md`,
   `01-webrtc-client.md` §4, and `05-delivery-plan.md` (Phase 0 removed) before writing any
   call-client code, so a future session doesn't re-discover the same stale premise.
2. **The app had no `.env`-file config scheme at all** (a single hardcoded `const bool
   isProduction` in `lib/config.dart`, two hardcoded URLs, `apiBaseUrl`/`checkpointBaseUrl`) —
   adding one new URL (`w/communications`) would have meant either hardcoding a third URL next
   to the other two, or finally converting to a proper environment scheme. The user chose the
   full conversion, matching `afsdesktop5`'s own `_env/{production,development,vagrant}/.env`
   scheme exactly, via the `flutter_dotenv` package (their choice over a hand-rolled loader).
   This touches config for the WHOLE app, not just the call feature — see Files below.

## The two real dependency-version findings

- **`livekit_client` had to be pinned to `2.6.4`, not the newest Dart-3.9-compatible version
  (`2.11.0`).** `2.6.5`+ raised its own `meta` dependency to `^1.17.0`, but the installed
  Flutter SDK (3.35.5) pins `meta` to exactly `1.16.0` via `flutter_test` — a real conflict
  `flutter pub get` failed on for real (not a hypothetical one caught by inspection), suggesting
  `2.6.4` itself as the fix. Separately, `2.12.0`+ needs Dart SDK ≥3.10.0, also unavailable
  (installed: 3.9.2). Both bounds need a `flutter upgrade` first if a newer `livekit_client` is
  ever wanted — noted directly in `pubspec.yaml`'s own comment, not just here.
- **`w/communications` isn't deployed anywhere except the vagrant lab** — confirmed by reading
  `afsdesktop5`'s own `.env` files, which is why the new `_env/vagrant/.env` is the only one
  that actually points at a live backend; `_env/development/.env` deliberately points at the
  same not-yet-live placeholder host desktop's own development `.env` does (fails loudly
  instead of silently talking to the lab), matching that precedent exactly.

## Files

| Where | What |
|---|---|
| `_env/{production,development,vagrant}/.env`, `.env` (root) (new) | The new environment scheme. Root `.env` currently mirrors `_env/vagrant/.env` (the only one with a genuinely reachable `w/communications`). |
| `lib/config.dart` | Rewritten onto `flutter_dotenv`: `bootstrapConfig()` (loads + eagerly validates every key), `isProduction`/`apiBaseUrl`/`checkpointBaseUrl` now read from `.env` instead of a hardcoded ternary; new `communicationsBaseUrl` getter. `checkpointAppName` unchanged (not environment-dependent). |
| `lib/main.dart` | `await bootstrapConfig()` before `runApp()`. |
| `lib/models/call/call_types.dart` (new) | Wire types for `w/communications`' guest-join contract — `JoinOutcome` (sealed: `AdmittedPayload` \| `WaitingResult` \| `DeniedResult`), `SessionView`, `LivekitInfo`, `CallParticipant`, `IceServer`. Mirrors `afsdesktop5/src/renderer/call/types.ts`. |
| `lib/repositories/communications_repository.dart` (new) | `joinByCode()`, `rejoin()`, `pollJoinRequest()` — `POST /v1/join` / `GET /v1/join-requests/:id`, no auth. Mirrors `communicationsApi.ts`. |
| `lib/bloc/call/{call_bloc,call_event,call_state}.dart` (new) | The call state machine: `CallIdle → CallJoining → (CallWaitingForHost →)* → CallConnected → CallEnded`, wrapping one LiveKit `Room` at a time. Mirrors `callController.ts`'s "one Room, turned into snapshots" design in this app's own Bloc idiom (`AuthBloc`/`StorageBloc`'s `part`/`part of` pattern). |
| `lib/pages/call/call_page.dart` (new) | The join form and in-call grid view — one page owns the bloc, matching `storage_page.dart`'s scoping pattern. |
| `lib/app.dart`, `lib/pages/home_page.dart` | `CallPage` route registered; a "Meetings" tile added to the home grid — **not** gated behind login like `/storage`, since guest join needs no account and the plan wants join-by-code to be the most prominent mobile entry point (`01` §3). |
| `android/app/src/main/AndroidManifest.xml`, `ios/Runner/Info.plist` | Camera/microphone permission declarations `flutter_webrtc` needs the OS to even show a prompt for — without these the app would crash or silently deny on a real device, not just skip a feature. |
| `pubspec.yaml` | New dependencies: `livekit_client` (pinned `^2.6.4`, see above), `flutter_dotenv`. `.env` declared as a Flutter asset. |
| `test/call/call_types_test.dart` (new) | `JoinOutcome.fromJson`'s parsing, including the unrecognised-role/status fallback behavior. |
| `test/widget_test.dart` | Fixed to call `bootstrapConfig()` before pumping `App()` and assert `tester.takeException()` is null — the pre-existing test only checked `find.byType(App)`, which stays true even if `App.build()` throws (a caught build-time error still leaves the `App` element in the tree), so it would not have caught this ordering requirement on its own. |

## What was deliberately NOT built (matches `01`/`05-delivery-plan.md`'s own scope, not a gap)

- Hosting a meeting (`01` §4) — needs `AuthRepository`'s bearer token wired into a
  `POST /rooms`/`POST /rooms/:id/sessions` call; the existing `AuthBloc` this would read from is
  untouched.
- Reconnection on `AppLifecycleState` resume / connectivity change (`01` §5).
- Everything in `02` (native call UX/push), `03` (screen share), `04` (mobile-scoped advanced
  features).
- Rejoin-token persistence (`session.ts`'s desktop equivalent) — `rejoin()` exists on the
  repository but nothing calls it yet; a dropped connection currently just ends the call.

## Verification

- `flutter pub get` — resolves cleanly (after the `livekit_client` version fix above).
- `flutter analyze` — **27 issues, all pre-existing**, in files this change never touched
  (calculator/domain pages, `login_page.dart`, `notes_page.dart`, `pdf_error_dialog.dart`, and
  the vendored `packages/checkpoint`). Zero errors; zero new lints from any file this step added
  or edited.
- `flutter test` — **39/39 passing** (32 pre-existing + 6 new for `call_types_test.dart` + the
  fixed `widget_test.dart`), including a full `pumpWidget(const App())` with the new `.env`
  bootstrap in place and an explicit check that nothing threw during that build.

**Not done this session — genuinely needs a device or emulator, which this session did not
have:** actually joining a real meeting (against the vagrant lab, `_env/vagrant/.env`), camera/
mic permission prompts firing correctly on real iOS/Android, `VideoTrackRenderer` actually
showing a live video frame, the participant grid updating when a second real participant
joins/leaves, and the mic/camera toggle buttons working against real device hardware. This
matches `05-delivery-plan.md` §3's own testing section, which already says simulator camera/mic
behavior is unreliable enough not to trust and calls for testing on physical devices — none of
which was available this session. `flutter analyze`/`flutter test` verify the code is
structurally sound (types match the real LiveKit Flutter SDK API surface, read directly from
the installed package source rather than assumed from memory/docs, and the wire-format parsing
is unit-tested against the real JSON shapes `w/communications` returns) but do not prove the
feature works end to end on a device.

## Follow-up work

1. **Run it on a real device or emulator** against the vagrant lab (needs the lab's mkcert root
   CA trusted and DNS reachable from the test device — the LAN-bridged setup already used for
   other lab testing, or an Android emulator with a host-machine DNS override). This is the
   single most important unverified item.
2. Host flow (`01` §4) — straightforward given `AuthBloc`/`AuthRepository` already exist; wire a
   bearer token into a new `startSession`-equivalent repository method.
3. Localize the new UI strings (`call_page.dart` currently uses plain English literals, not
   `AppLocalizations` — this app hand-writes `lib/l10n/messages_{en,es}.dart` rather than using
   Flutter's ARB pipeline, and adding the ~10 new strings properly was out of scope for this
   first slice).
4. Reconnection (`01` §5) and rejoin-token persistence — `CommunicationsRepository.rejoin()`
   exists but nothing calls it; a dropped connection currently just ends the call with no retry.
5. `02`-`04` (native call UX, screen share, mobile-scoped advanced features) — not started.
