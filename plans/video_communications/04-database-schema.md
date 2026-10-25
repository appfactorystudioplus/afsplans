# 04 — Database schema

## 1. Why these tables live in `w/www`, not `w/communications`

Per the user's direction and matching this project's existing convention: every table another
app "owns" but that lives in the shared MySQL database is migrated from `w/www` with a prefix
identifying the owner (`2026_08_23_000001_create_members_storage_tables.php`,
`2026_05_15_000003_create_members_website_tables.php`,
`2026_08_19_000001_create_members_market_notifications_table.php` — all in
`w/www/database/migrations/`, all prefixed `members_` for tables the `members` app reads and
writes). This plan's tables follow the same rule, with the same prefix pattern extended:
**`members_communications_*`** — owned conceptually by the video communications feature, which
is reached through the member's account exactly like storage and market notifications are.

`w/communications` (the new Node.js service, `01` §1) connects to this same MySQL database
and reads/writes these tables directly — it does not go through `w/www`'s HTTP layer for this,
the same way other non-PHP or cross-app consumers of shared tables don't in this codebase.
Migration ownership (where the `CREATE TABLE` lives) and runtime ownership (who reads/writes
the rows) are different things here, exactly as they already are for `members_storage_*`.

## 2. Tables

> **As built — kept current.** The four tables below (and `turn_servers`) come from
> `2026_09_18_000001_create_members_communications_tables.php`; egress linkage was added the
> next day by `2026_09_19_000001_add_egress_columns_to_members_communications_recordings.php`;
> breakout rooms landed 2026-09-20 in its own migration (below). **Every table below except
> `turn_servers` (infrastructure state, never member-facing) carries a public `char(36) unique`
> `uuid`** — the only identifier ever exposed to a client; the bigint `id` never leaves the
> service. This section was re-synced against the actual migration files on 2026-09-20 (a prior
> version of this doc had drifted — see `feature-parity-between-app.md` §7); the migration files
> remain the authoritative source if this ever drifts again.

**`members_communications_rooms`**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uuid` | char(36), unique | |
| `member_reference_num` | string(64), **unique** | The host. One room per member (v1) — the unique index is what makes a concurrent "get or create" call race-safe. |
| `join_code` | string(16), unique | Human-friendly (`abc-defg-hij` style), stable across sessions |
| `title` | string(120), nullable | |
| `waiting_room_enabled` | boolean, default false | |
| `status` | enum(`active`,`archived`), default `active` | Archived rooms keep history but can't be joined |
| `created_at` / `updated_at` | timestamps | |

**`members_communications_sessions`**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uuid` | char(36), unique | |
| `room_id` | FK → `members_communications_rooms.id`, cascade delete | |
| `livekit_room_name` | string(128), unique | The name passed to LiveKit — not necessarily `join_code` (rotates per session so a stale token can't rejoin a room that has since ended) |
| `started_at` | timestamp, `useCurrent()` | |
| `ended_at` | timestamp, nullable | Null = session currently live |
| `duration_cap_minutes` | unsigned int, nullable | Snapshot of the host's tier limit at session start (`05`) — a mid-session downgrade doesn't retroactively shrink a running meeting. Null = unlimited; the cap only applies once 3+ participants are connected. |
| `participant_cap` | unsigned int, nullable | Same snapshot principle |
| `cloud_recording_enabled` | boolean, default false | Snapshot of the host's tier at session start; enforced by `recordingService.ts` before allowing a recording to start |
| `live_transcription_enabled` | boolean, default false | Snapshotted the same way; not enforced anywhere yet (no live-transcription feature exists — see the feature-parity plan) |
| `cap_deadline_at` | timestamp, nullable | When the duration cap will end the session. Persisted (not an in-memory timer) so a service restart can't let a capped meeting run on — read by the `enforceDeadlines()` sweep in `index.ts` |
| `created_at` / `updated_at` | timestamps | |

Indexes: `(room_id, ended_at)` (find the live session for a room), `(ended_at, cap_deadline_at)`
(the deadline sweep's own query).

**`members_communications_breakout_rooms`** (`desktop/05-advanced-features.md` §5, added
2026-09-20 in its own migration, `2026_09_20_000001_create_members_communications_breakout_rooms_table.php`
— per the user's explicit instruction, schema changes here are ordinary additive migrations,
applied with a normal `php artisan migrate`, never `migrate:fresh` without the user's explicit
go-ahead each time, never raw SQL)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uuid` | char(36), unique | |
| `session_id` | FK → `members_communications_sessions.id`, cascade delete | Always the MAIN session being split, never another breakout room — deliberately not modeled as another session row (would risk `findActiveByRoom`'s "most recent non-ended session for this room" query preferring a live breakout over the real meeting) |
| `label` | string(60) | `'Room 1'`, `'Room 2'`, ... (v1 has no custom-naming UI) |
| `livekit_room_name` | string(128), unique | A fresh LiveKit room per breakout, same rotation principle as sessions' own |
| `status` | enum(`open`,`closed`), default `open` | |
| `close_deadline_at` | timestamp, nullable | Host-set auto-close timer, set once at batch-start; a separate concept from a session's own `cap_deadline_at` (the meeting-wide duration cap) |
| `closed_at` | timestamp, nullable | |
| `created_at` / `updated_at` | timestamps | |

Indexes: `(session_id, status)`, `(status, close_deadline_at)`.

**`members_communications_participants`**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uuid` | char(36), unique | Also doubles as a waiting guest's "request id" — random, so unguessable |
| `session_id` | FK → `members_communications_sessions.id`, cascade delete | |
| `identity` | string(80) | The LiveKit participant identity: `m:<member_reference_num>` or `g:<uuid>`. Unique per session (`(session_id, identity)`) so a reconnect maps to the same row instead of creating a duplicate. |
| `member_reference_num` | string(64), nullable | Null = guest |
| `display_name` | string(64) | Used for members and guests alike — **not** `guest_display_name`; a prior version of this doc named it wrong |
| `role` | enum(`host`,`co_host`,`participant`), default `participant` | |
| `status` | enum(`pending`,`admitted`,`denied`,`left`,`removed`), default `pending` | `pending` = a waiting-room request; `admitted` = holds a seat; `left` = seat released (may rejoin); `denied` = host refused; `removed` = kicked, cannot rejoin. Drives the waiting-room logic directly — not in a prior version of this doc at all. |
| `current_breakout_room_id` | FK → `members_communications_breakout_rooms.id`, nullable, cascade delete | Null = the main room. A participant is in at most one room at a time, so one nullable FK (not a join table) tracks it, and moving someone is a single race-free `UPDATE` (`05-advanced-features.md` §5). Added in the 2026-09-20 breakout migration with an **explicitly named** FK constraint (`members_comm_participants_breakout_fk`) — Laravel's auto-generated name is over MySQL's 64-character identifier limit here. |
| `requested_at` | timestamp, `useCurrent()` | |
| `joined_at` / `left_at` | timestamps, nullable | |
| `created_at` / `updated_at` | timestamps | |

Indexes: `(session_id, identity)` unique, `(session_id, status)`, `member_reference_num`.

**`members_communications_recordings`**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uuid` | char(36), unique | |
| `session_id` | FK → `members_communications_sessions.id`, cascade delete | |
| `egress_id` | string(64), nullable, indexed | Added 2026-09-19. What LiveKit's `egress_started`/`egress_ended` webhooks and the stop call refer to — how a webhook finds its row. |
| `storage_ref` | string(255), nullable | Points into the **existing** `members/storage_nodes` inventory (`checkpoint_registry_v4.json` → `storage_files_size_kb.inventory`) — recordings are billed against the member's existing storage allocation, not a new metered resource. Stays null until the finished file is moved into the member's storage allocation (a later step, not yet built — see the feature-parity plan's "recording retrieval" gap). This is the one resource in `TODO-member-resources.md` marked fully `DONE` end-to-end; reusing it here means recording storage inherits working `canUse`/`consume`/`release`/retention for free instead of needing its own. |
| `egress_location` | string(512), nullable | Added 2026-09-19. Where LiveKit actually wrote the file — distinct from `storage_ref`, which is the pointer into `members_storage_nodes` once it's been moved there. |
| `duration_seconds` | unsigned int, nullable | |
| `size_kb` | unsigned bigint, default 0 | Consumed against `storage_files_size_kb` on write, released on delete — identical to every other file the member stores |
| `status` | enum(`recording`,`processing`,`ready`,`failed`), default `recording` | |
| `started_at` | timestamp, `useCurrent()` | |
| `ended_at` | timestamp, nullable | |
| `created_at` / `updated_at` | timestamps | |

Index: `session_id`, `egress_id`.

**`members_communications_turn_servers`**

Fleet registry — see `02-turn-infrastructure.md` §5 for the full column list and selection
algorithm that reads it. Not member-scoped; this is infrastructure state, not member data. Unlike
every other table above, it has **no `uuid` column** — it's never exposed to a member-facing
client, so there's nothing that needs the bigint `id` hidden.

## 3. What is deliberately not a table here

- **Chat messages** — ephemeral per session in v1 (carried over the signaling WebSocket, `03`
  §5), not persisted. A durable meeting-chat history is a plausible advanced feature
  (`07-rollout-plan.md` marks it a later phase) but is not in this schema until decided.
- **TURN credentials** — never stored; minted on demand from the shared secret
  (`02-turn-infrastructure.md` §4) and expire on their own.
- **LiveKit's own room/participant state** — LiveKit is the live source of truth while a
  session is active; these tables are the durable record before/after, populated from LiveKit
  webhooks (`01` §3), not polled from it continuously.
