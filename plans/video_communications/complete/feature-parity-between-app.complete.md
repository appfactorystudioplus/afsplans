# Feature parity between apps — Meetings — Completion

**Status (2026-09-20): all execution steps from the plan are done and verified at the
type-check/build/automated-test level, except the two items that were explicitly out of this
plan's scope from the start (§9 of the plan doc): device/manual testing, and the production
`COMMUNICATIONS_URL` decision.** Nothing was committed, branched, or pushed in any repo — every
change is sitting uncommitted in its working tree, per this project's standing rule.

## What was done, by plan section

**§1 Naming pass** — done. Fixed: the Checkpoint entitlement registry label
(`w/auth/public/checkpoint/checkpoint_registry_v4.json`, "Video Communications" → "Meetings"),
`TODO-member-resources.md`'s heading, the website's in-call participant-count string ("in the
call" → "in the meeting"), mobile's Android foreground-service/screen-share notification text
("Call in progress" → "Meeting in progress", etc.), and desktop's Spanish tile description. One
planned item — the website dashboard's "Video conferencing" pretitle — was investigated further
during execution and found to already match this app's own established pretitle/title pattern
(every other dashboard section has a pretitle that is a category, not a repeat of its title, e.g.
"Cloud Sync" / "File Manager") — left unchanged rather than "fixed" into a real inconsistency.

**§7 Documentation correctness** — done. `04-database-schema.md` was re-synced against the actual
migration files (added the missing `uuid`/`identity`/`status`/`requested_at`/entitlement-snapshot/
egress columns, corrected the wrong `guest_display_name` column name). `00-overview.md`'s status
paragraph was updated to reflect that every client is now built. Wrote the two missing completion
docs (`desktop/complete/06-delivery-plan.complete.md`, `website/complete/04-delivery-plan.complete.md`)
confirming both were already satisfied in substance. `TODO-member-resources.md`'s stale checkboxes
for the `communications` resource were corrected after confirming directly in `w/communications`'s
source that `canUse`, duration-cap enforcement, `max_participants` enforcement, and the
`cloud_recording` gate are all genuinely implemented, not just declared.

**§4 Desktop** — done. Wired the already-existing-but-unused `hostApi.ts` methods into the host
panel: a recording start/stop button (`call.record.*` i18n, all four languages) and a "People"
list with Make/Remove-co-host buttons (`call.people.*` i18n). Added the call feature to the
command-palette search index. Verified: `tsc` 0 errors, `npm run test-call` 111/111 passing,
`npm run build` succeeds.

**§5 Website** — done, with one honestly-reported blocker. Breakout rooms and virtual
backgrounds/blur were ported from desktop in full (breakout: wire format, host panel, the
second-connection "visit" mechanism; backgrounds: confirmed during execution that the
Electron-only `callasset://` workaround desktop needed genuinely isn't required in a real
browser — ordinary same-origin static assets via Vite work fine). Recording retrieval was
investigated and found to be a real backend gap, not a UI task (see below) — correctly not built
against a field that's always empty. Verified: `tsc` 0 new errors (97 pre-existing, unchanged),
281/281 tests passing (45 new), `npm run build` succeeds (confirmed twice, independently).

**§6 Mobile** — done, with one deliberate, flagged deferral. Built: the dead `rejoinToken`
reconnect capability (now wired to a real disconnect-reason classifier), cloud recording controls,
co-host promotion with a new participant list, breakout rooms (5 of 6 routes — the host's "visit a
room" feature was deliberately deferred, since it needs a second concurrent LiveKit connection, a
materially larger structural change to `CallBloc`'s single-room architecture than the rest of this
pass), and in-call chat (built after you confirmed you wanted it, reversing an earlier deliberate
scope cut). Virtual backgrounds/blur was investigated and confirmed infeasible as a simple port —
`livekit_client`'s Flutter package exposes only a bare `TrackProcessor` hook, no segmentation
implementation the way the web `@livekit/track-processors` package provides; building this for
real needs a native ML pipeline per platform, correctly not attempted. Verified: `flutter analyze`
29 issues before and after (identical set, zero new), `flutter test` full suite 172/172 passing.

## Real gaps confirmed, not yet closed

1. **Recording retrieval has no backend support at all.** `members_communications_recordings.
   storage_ref` is never populated — nothing moves a finished LiveKit Egress output file into a
   member's storage allocation. This needs new backend work (a process to move the Egress file
   into `members_storage_nodes` and populate `storage_ref`, plus a room-level "all past
   recordings" list route, since the only existing route is session-scoped) before any client can
   build a "past recordings" UI. Not attempted here — it's materially bigger than a UI task and
   wasn't assumed away.
2. **Mobile's breakout "visit a room" feature** — deliberately deferred, needs a second concurrent
   LiveKit connection in `CallBloc`.
3. **Mobile virtual backgrounds/blur** — blocked on there being no Flutter-side segmentation
   library; would need native platform ML work.
4. **iOS screen share** — blocked on needing a Mac + Xcode session (unchanged from before this
   plan; not something this plan could resolve).
5. **Production `COMMUNICATIONS_URL`** — still unset in `w/members`'s prod env, per §8 of the plan
   doc. A decision for you, not code.

## What still needs you, specifically

- Whether/when to build the recording-retrieval backend work (item 1 above).
- The production communications URL decision (§8).
- Real device/manual verification of everything built this pass — explicitly out of scope for
  this session per the plan's own §9, same as every other client-side feature in this whole plan
  tree. Nothing here has been run against a real phone, a real second computer, or a real member
  login.

## Verification summary

| Repo | Type-check | Tests | Build |
|---|---|---|---|
| `afsdesktop5` | 0 errors | 111/111 | succeeds |
| `w/members` | 0 new errors (97 pre-existing, unchanged) | 281/281 | succeeds (confirmed twice) |
| `afsmobile_flutter` | `flutter analyze`: 29/29 identical, 0 new | 172/172 (full suite) | n/a (no device run) |

No commits were made anywhere. All changes are uncommitted in each repo's working tree, ready for
your review.
