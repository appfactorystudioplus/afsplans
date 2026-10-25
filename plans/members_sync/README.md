# plans/members_sync

Plan home for **member data sync** — keeping a logged-in member's data the same across their devices through the `members`
server, with the shared-database tables created by `www`. Two features so far, each implemented twice — once in the
mobile app, once in the desktop app — against **one shared server** per feature: **the calendar** and **notes**.

> ### This folder is a reference. The plan documents live in each app's own repository.
>
> **Calendar:** `C:\projects\vagrant\afs-eco\afsmobile_flutter\plan\calendar\` (mobile client + the shared server) and
> `C:\projects\vagrant\afs-eco\afsdesktop5\plans\sync\` (desktop client, decided 2026-09-27). Start at
> [`afsmobile_flutter/plan/calendar/00-overview.md`](../../../../afsmobile_flutter/plan/calendar/00-overview.md) (source of
> truth for the shared record model and server), then
> [`afsdesktop5/plans/sync/00-overview.md`](../../../../afsdesktop5/plans/sync/00-overview.md) for the desktop client.
>
> **Notes:** `C:\projects\vagrant\afs-eco\afsmobile_flutter\plan\notes\` (mobile client + the shared server — mobile is the
> source of truth here, since it adopts the desktop app's already-shipped Notes Pro schema as canonical) and
> `C:\projects\vagrant\afs-eco\afsdesktop5\plans\notes_sync\` (desktop client, decided 2026-09-27, mostly hardening an
> existing feature rather than building one). Start at
> [`afsmobile_flutter/plan/notes/00-overview.md`](../../../../afsmobile_flutter/plan/notes/00-overview.md), then
> [`afsdesktop5/plans/notes_sync/00-overview.md`](../../../../afsdesktop5/plans/notes_sync/00-overview.md).
>
> Each pair references the other directly. Do not copy any of them into this folder; a copy would drift.
>
> Completion reports for the **calendar's server-side** work (the `www` migrations and the `members` routes) are in the calendar plan's own
> `complete/`, next to the client reports: `afsmobile_flutter/plan/calendar/complete/04-server-schema-and-api.md` (record log, devices) and
> `10-sharing-and-invites.md` (sharing and invitations, server and mobile together). That is where they were actually written; this folder's own
> `complete/` has not been created. (The notes feature's server reports follow the same rule in `afsmobile_flutter/plan/notes/`.) Each client's
> own completion reports live with its own plan.

## What each feature covers on the server

### Calendar

| Repo | What | Plan document |
|---|---|---|
| `w/www` | The `members_calendar_*` migration(s): the record log, the per-member sequence counter, devices, and (for sharing) shares and invites. `www` owns every migration for the shared database; `members` ships none. | [calendar/04-server-schema-and-api.md](../../../../afsmobile_flutter/plan/calendar/04-server-schema-and-api.md), [calendar/10-sharing-and-invites.md](../../../../afsmobile_flutter/plan/calendar/10-sharing-and-invites.md) |
| `w/members` | Routes under `/api/v1/calendar` inside `checkpoint.verify:primary_only`, controllers, models, services, config, rate limiters, tests, account-closure eraser. | the same two documents |

### Notes

| Repo | What | Plan document |
|---|---|---|
| `w/www` | The `members_notes_*` migration(s): the record log, sequence counter, devices — same shape as calendar's, a separate table set. | [notes/04-server-schema-and-api.md](../../../../afsmobile_flutter/plan/notes/04-server-schema-and-api.md) |
| `w/members` | Routes under `/api/v1/notes`, same group, structurally parallel service to the calendar's; account-closure eraser. | the same document |

Each server design is consumed by **two** client plans; neither client plan repeats its server's design, both point at
the one specification for that feature:

| Client | Calendar plan / steps | Notes plan / steps |
|---|---|---|
| Mobile (Flutter) | `afsmobile_flutter/plan/calendar/`, Steps 1–3, 5–9, 11 | `afsmobile_flutter/plan/notes/`, Steps 1–7, 9 (8 is an unapproved sketch) |
| Desktop (Electron, Tools section) | `afsdesktop5/plans/sync/`, Steps 1–9 | `afsdesktop5/plans/notes_sync/`, Steps 1–5 (lighter — most of the feature already exists on desktop) |

The calendar desktop plan's Step 4 (auth bridging, ledger, IPC conventions) is the pattern the notes desktop plan's Step 3
reuses directly rather than re-deriving.

## Decisions made for these features (calendar: 2026-09-26, desktop duplication 2026-09-27; notes: 2026-09-27)

- **Sync is for every member.** No plan tier, no payment. (Not gated through the Checkpoint allocation system.)
- **Data lives until the member deletes it.** No server-side expiry: deleted items stay as tombstones indefinitely, so a device
  that has been offline for any length of time still syncs correctly without a full resync. The only removals are the member's
  own actions ("Delete forever", "Remove from server") and account closure.
- **Sharing calendars between members is in scope** (calendar mobile Step 10 / calendar desktop Step 9) — one server design,
  one client implementation each. **Sharing notes is *not* approved** — the notes plans keep only a sketch (mobile Step 8).
- **Both features are duplicated to the desktop app.** Calendar's desktop plan built the feature from nothing, in the Tools
  section. Notes' desktop plan mostly hardens the already-shipped Notes Pro feature for sync — the asymmetry is the
  opposite of calendar's, and each plan's overview explains why.
- **Notes adopts the desktop app's existing `NoteRecord`/`TagRecord`/`FolderRecord` schema as canonical**, the one case so
  far where desktop's design led and mobile's plan builds to match it, rather than the other way round.
- Primary members only for now (`checkpoint.verify:primary_only`), matching both clients' login, for both features.

## Cross-plan links

- [`../account_close/`](../account_close/00-overview.md) — the member-closure/purge engine. Each feature (both clients'
  data lives in the same server tables per feature, so this is one eraser per feature, not one per client) registers its own
  eraser: the calendar's **`calendar` eraser** (the `MemberEraser` contract in
  [`05-purge-engine.md` §5.3](../account_close/05-purge-engine.md)) that deletes the member's `members_calendar_*` rows,
  and notes' **`notes` eraser** (mobile notes Step 4) that deletes `members_notes_*` rows; both must be added to that
  plan's step order (neither has external resources, so both can sit next to `communications`).
- [`../cloud-storage/`](../cloud-storage/README.md) — the closest precedent for a synced, per-member, tombstoned data set
  (delta feed, `members_storage_*` migration in `www`, storage rate limiters, `CreatesStorageTestSchema`). Both features
  copy its conventions and depart from it on purpose in two places: a per-member **sequence number** instead of
  `(updated_at, id)` cursors, and tombstones kept **forever** instead of 90 days. It is also the desktop app's own
  precedent for a Checkpoint-authenticated client (`afsdesktop5/src/main/cloud/`), which both desktop plans' sync engines
  copy for their main/renderer auth-token bridging. Notes attachments and, on mobile, calendar attachments also reuse this
  system directly rather than building a second storage/quota mechanism.
- [`../membership_change/`](../membership_change/) — allocation/tier rules; not used here (sync is not tier-gated).

## Status

Planning only — nothing has been implemented for either feature. The first server steps are
[`calendar/04-server-schema-and-api.md`](../../../../afsmobile_flutter/plan/calendar/04-server-schema-and-api.md) and
[`notes/04-server-schema-and-api.md`](../../../../afsmobile_flutter/plan/notes/04-server-schema-and-api.md).
