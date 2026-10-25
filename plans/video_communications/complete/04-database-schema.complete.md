# 04 — Database schema — completion

**Status:** Done and applied to the vagrant lab database (2026-09-19). `www` owns the migration, as `w/AGENTS.md` §5 requires.

## Summary

One migration creates the five `members_communications_*` tables the plan describes — `rooms`, `sessions`, `participants`,
`recordings`, `turn_servers` — each guarded with `Schema::hasTable` and with explicitly named indexes and cascade foreign
keys. It passes `www`'s `MigrationOwnershipTest`. `w/communications` only reads and writes the tables; its own database user
has `SELECT/INSERT/UPDATE/DELETE` on these five and nothing else.

## Files changed

| Where | What |
|---|---|
| `w/www/database/migrations/2026_09_18_000001_create_members_communications_tables.php` (new) | The five tables |
| `w/communications/src/adapters/mysqlRepositories.ts` (new) | Repositories over the tables |
| Lab database `testdb_appfactorystudio` | Migration applied (migrations batch 2); `turn_servers` rows for `s1` and `s2` are written by `provision/deploy-coturn.sh` |

## Differences from the plan (the migration file is authoritative)

| Plan | Built | Why |
|---|---|---|
| bigint ids exposed | a public `uuid` on every table; bigint ids never leave the service | Unguessable references; a waiting guest's request id is the participant uuid |
| participants had no status | `status` enum (`pending`/`admitted`/`denied`/`left`/`removed`) and a per-session-unique `identity` | The waiting room and reconnection need them |
| sessions snapshot two limits | plus `cloud_recording_enabled`, `live_transcription_enabled`, and `cap_deadline_at` | A restart cannot let a capped meeting run on; a mid-session downgrade does not shrink a running meeting |
| rooms not unique per member | `UNIQUE(member_reference_num)` | Makes concurrent "get or create" race-safe (v1: one personal room per member) |

## Verification

- `www`'s `MigrationOwnershipTest` passes.
- Ran on strict MySQL 8.4 and on the lab's MariaDB 10.5.29; rollback and re-run are clean.
- 26 integration tests: unique-key error mapping, UTC round-trips, and the seat-cap transaction (row lock on the session)
  under concurrent admits.
- Least privilege checked on the lab: DROP, ALTER, CREATE, TRUNCATE and reads of other tables are denied for the service's
  user, and it cannot connect from the lab network.

## Not verified / follow-up

- ~~`members_communications_recordings` exists but nothing writes it yet.~~ Website step 02 writes it (start, egress webhooks) and added `egress_id` and `egress_location` in a second migration, `2026_09_19_000001_add_egress_columns_to_members_communications_recordings.php` (guarded, applied to the lab). A real recording has since been captured in the lab (Egress deployed; `npm run e2e:record`: the row reaches `ready` with location, duration and size): [`website/02`](../website/complete/02-host-controls-and-ui.complete.md).
- No query-plan or load testing of the indexes.
- The migration has only run in the lab; it has not run against production.
- Heartbeat timestamps are `TIMESTAMP` columns: compare with `NOW()` when checking by hand (session zone is EDT here).

See `01-architecture-and-media-topology.complete.md`.
