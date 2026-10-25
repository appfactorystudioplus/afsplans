# 05 — Feature tiers and entitlements — completion

**Status:** Done for the parts this plan assigns to code (2026-09-19): the registry entry (written during the planning
pass) and the service-side enforcement in `w/communications`. Two of the entitlement fields were resolved and stored but not
yet acted on, because the feature they gate (cloud recording) did not exist yet. **Update (website step 02, 2026-09-19):** `cloud_recording` is now enforced (403 `entitlement_denied`, `reason: recording_not_in_plan`) and shown to the host; only `live_transcription` is still unenforced. See [`website/02`](../website/complete/02-host-controls-and-ui.complete.md).

## Summary

The `communications` resource lives in `w/auth/public/checkpoint/checkpoint_registry_v4.json` (with checklist entry #35 in
`TODO-member-resources.md`), edited directly during planning. `w/communications` reads that file at startup and resolves a
host's limits from it **in the registry's own order** — level 99 → account status → `feature_flag` → `pass` →
`tiers[plan].enabled` — failing **closed** if the resource or a tier field is missing. The limits are then **snapshotted onto
the session** at start, so a mid-session downgrade does not shrink a running meeting.

## Enforcement points (plan §6)

| Point | Built | State |
|---|---|---|
| Session start / token issuance | `entitlements.ts` → `MeetingService.startSession`; `participant_cap` becomes the LiveKit room's `maxParticipants`; denial is `403 entitlement_denied` with `reason` and `upgrade_worthy` (`tier_disabled` is upgrade-worthy, `feature_disabled` / `level_denied` are not) | Done, verified |
| Duration cap | The **service** enforces it, not the client: the clock starts when the **third** participant connects (from LiveKit's `participant_joined` webhook), the deadline is persisted (`cap_deadline_at`) and set once, and a sweep (`enforceDeadlines`) closes expired sessions and deletes the LiveKit room. 1:1 calls are never capped | Clock start verified end to end; the sweep closing a real room is unit-tested with fakes only (the Free cap is 40 minutes and was not waited out) |
| `cloud_recording` | Resolved and snapshotted on the session (`cloud_recording_enabled`) | **Enforced** on `POST …/recording/start` (website step 02); the host UI hides Start recording and explains why when the plan lacks it |
| `live_transcription` | Resolved and snapshotted | Not enforced yet — no transcription feature |
| `draw_annotations` | Not a server-side concern (desktop feature) | n/a |

The Free tier's numbers (40 minutes, 40 participants) are the plan's placeholders, not pricing decisions; they are read from
the registry, so changing the file changes the behaviour.

## Files changed

| Where | What |
|---|---|
| `w/auth/public/checkpoint/checkpoint_registry_v4.json`, `TODO-member-resources.md` | Written during the planning pass (not touched since) |
| `w/communications/src/domain/entitlements.ts`, `src/services/meetingService.ts`, `src/composition.ts` | Resolution, snapshot, cap clock, `enforceDeadlines`, and the sweep timer in `src/index.ts` |
| `w/communications/src/adapters/checkpointIdentity.ts` | Reads the member's plan (`membership` 0–4), level and account status from Checkpoint's token-validate response |

## Verification

- Unit tests run the resolver **against the real registry file**: tier gating, the resolution order, fail-closed on missing
  fields, `upgrade_worthy` vs not.
- Five deliberate breakages of the service (cap clock, seat cap, removed-can-rejoin, suspended-can-host, TURN picks the
  most loaded) were each caught by the suite.
- Lab: a real LiveKit room is created with `maxParticipants` 40 (Free), and `npm run e2e:media` shows the deployed service
  starting the cap clock when the third real participant connects (deadline ≈ 39.6 min out) and **not** moving it when people
  leave.

## Not verified / follow-up

- A host with a real member login hitting the entitlement path: the Meetings screen and `npm run e2e:owner` exercise it, but the latter must be run by you with a member's credentials ([`website/02`](../website/complete/02-host-controls-and-ui.complete.md)).
- The deadline sweep ending a real 3+ participant meeting at the cap.
- ~~Enforcement of `cloud_recording`~~ — done in website step 02. `live_transcription`: build it with transcription.
- Confirm the placeholder tier numbers against real pricing before treating them as final.

See `01-architecture-and-media-topology.complete.md`.
