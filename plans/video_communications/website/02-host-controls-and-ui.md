# 02 — Host controls and UI

## 1. Start-a-meeting screen

New member-facing screen in `w/members`, reachable from the member dashboard alongside existing
member features. Behind the app's normal auth wall (unlike the guest join page, `01` §2). On
load, checks the `communications` entitlement
(`../05-feature-tiers-and-entitlements.md`) and either shows the
host UI or a clear "not available on your plan" state — using the registry's own
`resolution_order` distinction (`checkpoint_registry_v4.json` →
`conventions.feature_flags.resolution_order`) so a `tier_disabled` denial reads as an upgrade
prompt and any other denial reason does not.

## 2. Room persistence

Per the room/session split (`../03-signaling-and-session-model.md` §1),
a member's room is created once and reused — the UI should reflect this as "your meeting room"
with a stable, always-available join code/link, rather than generating a new code every time
the member starts a session, matching the personal-Zoom-room mental model the master plan is
built around.

## 3. Host controls during a live session

Mirrors the role table in `../03-signaling-and-session-model.md` §4:
waiting-room admit/deny, mute-all, remove participant, promote co-host, start/stop recording
(gated by `cloud_recording`, `../05-feature-tiers-and-entitlements.md` §5) — all issued over the
signaling WebSocket (`../03-signaling-and-session-model.md` §5) exactly as the desktop and mobile
clients issue them, no website-specific host-control protocol.

## 4. Waiting room UI

When a room has `waiting_room_enabled` (`../04-database-schema.md` §2),
the host sees incoming `waiting_room.request` events (`../03-signaling-and-session-model.md` §5) as a
visible queue with per-guest admit/deny — this is the one piece of host UI most guests will
actually see the effect of, so it should be prominent, not buried in a secondary panel.
