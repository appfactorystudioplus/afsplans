# Feature parity between apps — Meetings

## Completion document

When this plan step is completed, write a completion document in this directory's `complete/`
folder, named `feature-parity-between-app.complete.md`, per this tree's usual convention
(`00-overview.md`'s own instruction).

## Why this plan exists

A full audit (2026-09-20) of the meetings/video-conferencing feature across all three clients
(`afsmobile_flutter`, `w/members`, `afsdesktop5`), the backend (`w/communications`), and the
database (`w/www` migrations) was requested to answer three questions: is the feature named the
same thing everywhere, is the database and URL configuration correct, and — for everything
already built — is there anything left besides device/manual testing. Four parallel read-only
audits were run (one per client, one for backend+DB). Findings below; this document is **the
plan for fixing what they found**. No code has been changed yet — this is planning only.

**Bottom line up front:** the database itself is correct (no bugs, no migration needed — the
*plan doc describing it* is what's stale). URLs are correct everywhere they're set, with one real
gap: production `w/members` has no `COMMUNICATIONS_URL` at all, which needs a decision from you,
not code. Naming is *mostly* uniform — the three clients all call this "Meetings" in their primary
UI — but a handful of stray strings (an entitlement registry label, a couple of leftover
dashboard/notification strings) still say something else. The real substance is feature parity:
**breakout rooms, virtual backgrounds, and wiring already-built backend APIs (recording, co-host
promotion) into client UIs** are each present on some clients and missing on others.

---

## 1. Naming uniformity — make everything say "Meetings"

The desktop app is the reference point: `call.title` = "Meetings" in all four languages, and every
other desktop string consistently says "meeting(s)". The website's primary UI (`conference.*`,
`meetings.*` i18n) is also uniformly "Meeting(s)" — better than initially expected. Mobile's
primary UI (drawer, app bar, buttons, dialogs) was already renamed to "Meetings" in a recent
session. The gaps are all small, specific strings that were missed by those passes or that live
outside client i18n entirely:

| # | Where | Current text | Fix | File |
|---|---|---|---|---|
| 1 | `w/auth` Checkpoint entitlement registry | `"label": "Video Communications"` | `"Meetings"` | `w/auth/public/checkpoint/checkpoint_registry_v4.json:1603` (resource key `communications`) |
| 2 | `packages/afsserver/plans/TODO-member-resources.md` | Heading/entry calls it "Video Communications" | "Meetings" | entry #35 (lines ~979-1019) |
| 3 | Website dashboard, just above the "Meetings" heading | `meetings.pretitle` = "Video conferencing" | "Meetings" (or drop the pretitle — it's redundant with the `<h2>` right below it) | `w/members/resources/js/i18n/en.json:1038`, duplicated in `w/members/resources/js/utils/utils.ts:184` (fix both, they can drift) |
| 4 | Website in-call participant count | `conference.call.in_call` = "{n} in the call" | "{n} in the meeting" | `w/members/resources/js/i18n/en.json:966` |
| 5 | Website host panel section heading | `conference.host.actions_title` = "Meeting" (singular, next to an otherwise-plural brand) | Leave as-is — it's a section heading for *this one* meeting's actions, singular is grammatically correct here, not a stray product name. **No change**, listed only so it isn't re-flagged later. | `w/members/resources/js/i18n/en.json:1022` |
| 6 | Mobile Android persistent notification (shown for the whole call, including guests) | "Call in progress" / "Tap to return to the call." | "Meeting in progress" / "Tap to return to the meeting." | `afsmobile_flutter/lib/services/call_foreground_service.dart:36,65,66` |
| 7 | Mobile Android screen-share notification | "Tap to return to the call." | "Tap to return to the meeting." | `afsmobile_flutter/lib/services/screen_share_service.dart:43` |
| 8 | Desktop Spanish dashboard tile description | `tools.callDesc` (es) = "Organiza o únete a una **videollamada**." while the section title is "Reuniones" | "Organiza o únete a una **reunión** de video." | `afsdesktop5/src/renderer/locales.json` (`tools.callDesc`, `es`) |

**Not changed, and why:** internal-only identifiers — `CallPage`, `CallBloc`, `CallController`,
route names like `/call`, the `conference` namespace in i18n keys and in `w/members`' own file
paths (`resources/js/conference/*.ts`), the desktop's unrelated `videoComms` module name (that's
the *local screen recorder*, a different feature entirely — see §7 for why that overlap is
confusing but not a naming bug), and the `video_comminucation` docker-build directory (a real
typo, but renaming it touches production deployment scripts/compose paths for no user-visible
benefit — listed under Housekeeping, §6, as optional and explicitly *not* recommended to do
casually).

**Execution note:** item 1 (the registry label) is the one to double check before changing —
grep `w/members` and any pricing/plan page for the literal string `"Video Communications"` in
case it's rendered directly from the registry somewhere outside the `communications.*` i18n keys
already audited. Everything else in this section is a pure string edit, no logic changes,
bundleable as one small step.

---

## 2. Feature parity matrix

**Updated 2026-09-20, after executing this plan.** Built ✅ / Partial 🟡 / Not built ❌ / Blocked
🚧 (not code — needs something outside this session, e.g. hardware or a business decision).

| Feature | Backend (`w/communications`) | Desktop | Website | Mobile |
|---|---|---|---|---|
| Host a meeting / join by code | ✅ | ✅ | ✅ | ✅ |
| Waiting room admit/deny | ✅ | ✅ | ✅ | ✅ |
| Mute-all | ✅ | ✅ | ✅ | ✅ |
| End meeting | ✅ | ✅ | ✅ | ✅ |
| In-call chat | — (LiveKit data msg, no server code) | ✅ | ✅ | ✅ (built this pass, per your go-ahead) |
| Reactions / raised hand | — | ✅ | ✅ | ✅ |
| Whiteboard | — | ✅ full editor | ✅ full editor | 🟡 read-and-annotate only (by design) |
| Screen share | — | ✅ | ✅ | 🟡 Android only, iOS not implemented (needs a Mac — 🚧) |
| Virtual backgrounds / blur | — (client-side only, `@livekit/track-processors`) | ✅ | ✅ (built this pass) | 🚧 no segmentation library exists for Flutter (`livekit_client`'s `TrackProcessor` is a bare hook, not an implementation) — needs a native ML pipeline, out of scope for a session like this |
| Breakout rooms | ✅ 6 routes | ✅ | ✅ (built this pass) | 🟡 built this pass — 5 of 6 routes wired; "host visits a room" deliberately deferred (needs a second concurrent LiveKit connection, a large structural change to `CallBloc`) |
| Co-host / role promotion | ✅ `setRole` route | ✅ (wired this pass) | ✅ | ✅ (built this pass) |
| Cloud recording (start/stop) | ✅ entitlement-gated, LiveKit Egress wired | ✅ (wired this pass) | ✅ | ✅ (built this pass) |
| Recording retrieval (view/download after) | 🚧 real gap, confirmed this pass: `storage_ref` is never populated — no process moves a finished Egress file into member storage at all yet | — | 🚧 blocked on the backend gap, not a UI task | — |
| Live captions / transcription | ❌ (consistently deferred everywhere, explicit user choice) | ❌ | ❌ | ❌ |
| Network drop → reconnect without new code | ✅ `rejoin_token` API | ✅ | ✅ | ✅ (built this pass) |
| Native call UX (CallKit / ConnectionService ringing) | — | n/a | n/a | ❌ (in-app join only, tracked as future work, not in this pass's scope) |

Everything that was a ❌ against desktop/website's ✅ in the original audit is now built and
verified (type-check/build/unit tests — see the completion doc). What's left is either a genuine
backend gap (recording retrieval), a hardware block (iOS screen share), or a scoped-out platform
limitation (mobile virtual backgrounds, mobile's breakout-room "visit" feature) — see
`complete/feature-parity-between-app.complete.md` for the full report.

---

## 3. Backend — nothing new to build, but two things need confirming

Recording and co-host promotion are **fully implemented** in `w/communications`
(`recordingService.ts`, `meetingService.setRole`, the routes in `http/app.ts`) — the gaps are
entirely client-side (§4, §5). No backend code changes are needed for either.

One thing worth a deliberate decision rather than silent action: `TODO-member-resources.md`'s
checkbox for the `communications` resource's `canUse` wiring is still shown as **unchecked**,
even though `w/communications/src/domain/entitlements.ts` genuinely implements it
(`resolveHostEntitlement()`, fail-closed, tier-aware). This is a documentation-only fix — check
the box and note where the enforcement actually lives.

---

## 4. Desktop — wire two already-built backend APIs into the UI

Both are additive UI work only; no backend or protocol changes.

1. **Cloud recording controls.** `hostApi.ts` already has `startRecording()`, `stopRecording()`,
   `recordings()` and `call.html` already has a passive recording badge
   (`call-rec-badge`, driven by `RoomEvent.RecordingStatusChanged`) — but nothing in the host
   panel actually calls `startRecording`/`stopRecording`. Add "Start recording" / "Stop recording"
   buttons to the host panel (`#call-host-panel`), gated the same way the equivalent website
   buttons are (`conference.host.record_start/stop/not_in_plan` strings already exist as a
   pattern to mirror — desktop's own `locales.json` needs its own `call.record*` keys added in
   all four languages).
2. **Co-host promotion UI.** `hostApi.setRole()` and `hostApi.participants()` exist; `call.html`'s
   host panel has no participant list. Add one (mirroring the website's "In the meeting" people
   list with Make-co-host/Remove-co-host actions) — the `co_host` role label already exists in
   desktop's locale strings, it's only the list + actions that are missing.
3. **Minor:** add the call feature to the command-palette search index
   (`src/renderer/components/search.ts` currently lists every other feature — `videoComms`,
   `flow`, `whisper`, etc. — but not `call`). Small, unrelated to naming, just a real
   discoverability gap noticed in passing.

---

## 5. Website — two missing advanced features, one retrieval gap

1. **Breakout rooms UI.** Backend already exposes all six `/v1/sessions/:sessionId/breakouts*`
   routes (start, list, close, broadcast, reassign one participant, host visit). Port the desktop
   panel's design (`afsdesktop5`'s `05-advanced-features.md` §5 design doc + its
   `components/call.ts` host panel) to `w/members/resources/js/conference/` — a new
   `breakoutPanel.ts` + the `afs.breakout` LiveKit data-topic listener in `callController.ts`
   (the wire format, `BreakoutDestination`/`BreakoutSignal`, is already fully specified and
   client-agnostic — see `afsdesktop5/src/renderer/call/breakoutMessages.ts` as the reference
   implementation to port, same pattern already used for whiteboard/reactions).
2. **Virtual backgrounds / blur UI.** `@livekit/track-processors` is a plain browser library —
   desktop already proves it works in a Chromium-based renderer, and `w/members` runs in an
   actual browser, so this should be a more direct port than desktop's Electron-specific asset
   plumbing (desktop needed a custom `callasset://` protocol only because of Electron's
   `file://` CSP restrictions — a website build can likely just serve the model/wasm files as
   ordinary static assets via Vite, no privileged-protocol workaround needed). Confirm this
   simplification is real (check whether `fetch()` of same-origin static assets is unrestricted
   before assuming) before treating it as a given.
3. **Recording retrieval.** Recording start/stop is built; there's no page for a member to see or
   download a finished recording. `members_communications_recordings.storage_ref` already points
   into the existing `members/storage_nodes` inventory per `04-database-schema.md` — this should
   be a fairly small addition: a "Past recordings" list on the Meetings dashboard page, reusing
   whatever the existing storage/file-download UI pattern is elsewhere in `w/members` (check the
   Storage plan's own client code for the pattern to mirror, rather than inventing a new download
   flow).

Co-host promotion is already built on the website — no action needed there.

---

## 6. Mobile — the largest set of gaps, and one that's blocked on hardware

1. **Wire the dead reconnect capability.** `communications_repository.dart` already has
   `rejoin(rejoinToken)` and `call_types.dart` already parses `rejoinToken` from the join payload
   — neither is ever called. `call_bloc.dart`'s `_onRoomDisconnected` currently treats every
   disconnect (manual leave, host-ended, or a genuine network drop) identically, ending the call
   and returning to the join screen. Distinguish a network-drop disconnect reason from an
   intentional one (desktop already has this classification — `mapDisconnectReason` per the
   desktop `06-delivery-plan` audit — port the same reasoning) and attempt `rejoin()` before
   giving up. This is the mobile equivalent of a feature already verified working on both other
   clients.
2. **Cloud recording UI for mobile hosts.** `host_types.dart`/`call_types.dart` already parse a
   `cloudRecording` entitlement flag that nothing reads. Add `HostRepository` methods mirroring
   `hostApi.ts`'s `startRecording`/`stopRecording`/`recordings` (same backend routes desktop and
   website already call), and start/stop controls on `host_controls_page.dart`.
3. **Co-host promotion.** Needs a new `HostRepository.setRole()` method (backend route already
   exists) plus a participant list + promote/demote UI on `host_controls_page.dart` — mobile
   currently has no participant-list UI at all for the host, so this is slightly more than a
   thin wrapper; the desktop/website "In the meeting" list is the pattern to follow.
4. **Breakout rooms.** The largest single mobile item — needs `HostRepository` methods for all
   six breakout routes, the `afs.breakout` LiveKit data-topic listener (port from
   `breakoutMessages.ts`, same as the website item in §5), a host-side breakout panel, and a
   participant-facing "you're in Room N" banner. Given the size, treat as its own bounded
   sub-step within this plan's execution, not folded into the smaller items above.
5. **Virtual backgrounds / blur — needs a feasibility check before committing to build it.**
   Unlike the website (§5.2), mobile doesn't get this "for free" from a browser library — it
   would depend on whatever video-processor hook (if any) `livekit_client`'s Flutter package
   exposes on `LocalVideoTrack`, which has not been checked. **First step here is investigation,
   not implementation**: read the installed `livekit_client` Flutter package source (same
   discipline this project always uses — every prior mobile step checked exact installed SDK
   surfaces before writing code) to confirm whether a segmentation/processor hook exists at all.
   If it doesn't, this item should be reported back as blocked/deferred, not forced.
6. **In-call chat — a prior deliberate scope cut, not an oversight.** `call_messaging.dart`'s own
   header comment says chat was excluded on purpose when reactions/hands were ported. Full parity
   would mean building it (port `messages.ts`'s chat slice, same pattern already used for
   reactions/hands). **Flagging this explicitly rather than silently including or excluding it**
   — since it was a conscious earlier decision, not a bug, confirm with the user before building
   it as part of this plan's execution, rather than assuming the earlier scope decision should be
   reversed.
7. **iOS screen share — blocked on hardware, not a code gap to "complete."** The existing plan
   already states this needs a Mac + Xcode session (`plans/TODOS.md` item 6). Not actionable in
   this environment; excluded from this plan's execution scope for that reason, same as every
   other testing/device-dependent item.
8. **Live transcription / captions** — consistently deferred estate-wide by explicit prior user
   choice (desktop, website, mobile all agree). Not re-litigated here; no action.

---

## 7. Documentation correctness (the "database is correct" finding, precisely stated)

No migration is needed anywhere — the actual `w/www` migrations are internally consistent, every
FK/index/enum checked out, and the one previously-undocumented practical fix already in place
(an explicitly-named FK constraint on `current_breakout_room_id` to stay under MySQL's 64-char
identifier limit) is correct as built. The gap is entirely that **the plan docs describing the
schema are stale**, specifically:

1. `04-database-schema.md`'s table listings are missing: `uuid` (on every table except
   `members_communications_turn_servers`, which genuinely has none — the doc's "every table has a
   public uuid" framing should say "every member-facing table"), `identity`/`status`/
   `requested_at` on `participants` (the doc calls the display-name column
   `guest_display_name`; it's actually just `display_name`, used for members and guests alike),
   `cloud_recording_enabled`/`live_transcription_enabled`/`cap_deadline_at` on `sessions`, and
   `egress_id`/`egress_location` on `recordings` (added the day after the original migration).
   Update the doc to match the real, already-correct schema — no schema change, a documentation
   edit only.
2. `00-overview.md`'s status paragraph (currently dated 2026-09-19) says desktop's call client,
   every `mobile/` plan, and website's advanced features are "Not started" — all of these are now
   done, per this same audit. Update the status summary to reflect reality as of 2026-09-20.
3. `desktop/06-delivery-plan.md` and `website/04-delivery-plan.md` each have no `.complete.md` in
   their respective `complete/` folders, but both audits found their content already satisfied in
   substance by the `01`–`05`/`01`–`03` work that *does* have completion docs. Write the two
   missing completion documents (a short "satisfied by X, Y, Z — nothing new built" report each,
   not a new feature write-up) so the plan tree accurately reflects what's done.
4. `TODO-member-resources.md` entry #35's `canUse` checkbox (see §3).

---

## 8. Production URL gap — needs you, not code

Production `w/members` (`_env/prod/.env`) has no `COMMUNICATIONS_URL` at all — by the lab env
file's own documented convention ("empty = meetings off"), **meetings are effectively disabled in
production today**. This is already tracked as a "needs you" item in the website's own
`plans/TODOS.md` (item 4) and is not something this plan can resolve on its own: it needs a real
decision from you about when/whether `w/communications` gets deployed to production and what its
public URL will be (`communications.appfactory.studio`, matching the `.test1` lab convention, is
the expected value once it's ready — see the Coturn fleet's own production `.env`, which already
references that exact hostname for its heartbeat reporting, so the naming convention is already
decided even though the URL isn't wired up yet). Not an execution item; a decision to make
whenever you're ready.

---

## 9. What "done" means for this plan's execution, and what's deliberately excluded

Per this project's standing convention, "done" for each item above means: implemented, unit
tested, and — where an existing lab e2e harness already covers the pattern (Playwright +
`media-probe`, no real device needed) — exercised there too, the same bar every other completed
sub-feature in this tree has already met. **Excluded from this plan's execution, by design:**

- Anything needing a real physical device (mobile's iOS screen share, real cellular-network
  behavior, a real second phone) — same standing limitation every mobile completion doc already
  states.
- Anything needing a real member account logged in through a real browser/app (host-only UI
  paths have consistently been excluded from automated e2e for this whole feature, per this
  project's own "never use the user's own login in an automated test" rule).
- The production `COMMUNICATIONS_URL` decision (§8) — yours to make.
- Whether to build mobile in-call chat (§6.6) — flagged for your confirmation, not assumed.

Everything else in §1–§7 is intended to be fully implemented (not left as another round of
planning) when this plan is executed, with only the device/manual-testing tail left open,
exactly as you asked.

---

## 10. Suggested execution order

Grouped into bounded, independently-completable steps (this project's "finish a whole step, not
a phase" convention):

1. **Naming pass** (§1) — small, mechanical, every repo, low risk. Do first.
2. **Documentation correctness pass** (§7) — no code, just catching the plan tree up to reality.
3. **Desktop UI wiring** (§4) — recording + co-host UI, both backed by already-existing APIs.
4. **Website: breakout rooms + virtual backgrounds + recording retrieval** (§5).
5. **Mobile, in order of size**: reconnect wiring → recording UI → co-host promotion →
   virtual-backgrounds feasibility check → breakout rooms (§6.1–6.5), with chat (§6.6) gated on
   your confirmation first.
6. Re-verify the full feature parity matrix (§2) once 1–5 land, and write this plan's own
   completion document.
