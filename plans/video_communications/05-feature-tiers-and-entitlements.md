# 05 — Feature tiers and entitlements

## 1. The registry entry

This feature is gated through the existing Checkpoint allocation registry, not a bespoke
system — `checkpoint_registry_v4.json` (`w/auth/public/checkpoint/`) gains one new resource,
`communications`, and `TODO-member-resources.md` gains the matching checklist entry (#35).
**Those two files are edited directly as part of this plan** (not summarized here and left
undone) — see them for the literal schema. This document is the *rationale* for the shape
chosen, because that resource deliberately extends the registry's tier-entry shape beyond what
the other 34 resources use, and that deviation needs to be understood before it's approved.

## 2. Why one resource, and why it doesn't fit the mold cleanly

Every existing resource in the registry meters **one** number: a count of things owned
(`member_domains_count`), a monthly quota (`domain_email_sends_monthly`), or a binary switch
(`afs_connect`). Zoom and Teams' own free-tier restrictions are not one axis — they're a
bundle: a meeting-length cap, a participant-count cap, and cloud-recording availability, all
changing together at the same plan boundary. The person asking for this explicitly wants one
resource named `communications`, not a `communications_*` family — so this plan encodes the
bundle as **one resource whose tier entries carry extra fields beyond the standard
`{value, enabled}`**, the same way `storage_files_size_kb`'s tiers already carry a precedented
extra `_note` field. This is flagged in the registry entry itself (a `tier_note`) exactly as
every other deliberately-nonstandard entry in that file already is (`domain_sponsorships`,
`domain_chat`, etc. all carry similar self-flagging notes) — it is a documented, intentional
extension, not a silent schema drift.

## 3. What the primary metered value is

The registry's `tiers[n].value` field is the one number every consumer of the file (alloc_v2,
the reconciler, the retirement machinery) actually reads generically. For `communications` that
value is **max meeting duration in minutes for a session with 3 or more participants** — the
single number most associated with "the free tier" in both Zoom's and Teams' own marketing, and
the one that most naturally maps to `overage: block` (the operation being blocked is "keep this
meeting running past N minutes," enforced by `w/communications` ending the session, not by
degrading media quality).

**1:1 calls are not subject to this cap at any tier** — matching both Zoom's and Teams' own
free-tier behavior, where the 40/60-minute limit only applies once a third participant joins.
`w/communications` checks participant count, not just elapsed time, before enforcing.

## 4. `period` and `retention` are placeholders, deliberately

The registry's `period_meanings` only defines `lifetime`/`monthly`/`daily` — none of which
describe "a ceiling re-evaluated fresh every single meeting." This entry uses `period: lifetime`
and `retention: none` as the closest existing fit (structurally identical to how
`domain_email_sends_monthly` uses `retention: none` because a sent email can't be un-sent — here,
an elapsed meeting-minute can't be un-elapsed either), with an explicit note in the registry
entry calling out that it is evaluated per-session, not accumulated. This is called out as an
open question for whoever next revises the registry's `period_meanings` — a `per_instance`
period may eventually be worth adding formally rather than borrowing `lifetime`.

## 5. The additional per-tier fields

| Field | Meaning | Free | Starter | Professional | Business | Enterprise |
|---|---|---|---|---|---|---|
| `value` (minutes, 3+ participants) | Session length cap | 40 | -1 | -1 | -1 | -1 |
| `max_participants` | Concurrent participant cap per session | 40 | 100 | 300 | 500 | 1000 |
| `cloud_recording` | May start server-side recording (`04` §2) | false | false | true | true | true |
| `draw_annotations` | Desktop draw-over-screen during a call is available to this host | true | true | true | true | true |
| `live_transcription` | Whisper-backed live captions (`desktop/05-advanced-features.md`) | false | true | true | true | true |

**These are placeholder numbers modeled on public Zoom/Teams free-tier figures, not pricing
decisions** — every other entry in the registry that carries plausible-but-unconfirmed tier
values says so explicitly (`domain_sponsorships`' `tier_note`, verbatim: *"Tier VALUES are
copy-pasted ... and have not been set by pricing"*). The same caveat applies here. Confirm
against actual pricing before treating any of these five columns as final.

## 6. Enforcement points

Per the registry's own `feature_flags.enforcement` convention (*"Client-side... advisory... NOT
a server-side guarantee... has to re-check server-side"*), `communications` is checked
server-side in exactly two places, both in `w/communications`, matching the "block at the edge"
principle `03` already established:

1. **Token issuance** (`03` §2) — `max_participants` and `cloud_recording` are baked into the
   session's config at creation (`members_communications_sessions.participant_cap`,
   `.duration_cap_minutes` — `04` §2's snapshot columns exist for exactly this).
2. **Duration enforcement** — `w/communications` runs the session-length check itself
   (LiveKit's own room API is told to close the room at the cap), not the client, so a modified
   or malicious client cannot simply ignore a client-side timer.

`canUse`/`consume`/`release` (the registry's own vocabulary, `TODO-member-resources.md` §"How
to use this") map loosely here: `canUse` is "may this member start a session with 3+
participants at all, and for how long" (checked at session start), there is nothing to
`consume` in the allocation sense (a meeting-minute isn't inventory), and `release`/retirement
are `n/a` for the same reason `domain_email_sends_monthly` and `voip_number_minutes_monthly`
mark them `n/a` — nothing is held that a downgrade needs to retire.
