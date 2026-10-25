# 13 — Rollout and testing

## Completion document

When this plan step is completed, write `complete/13-rollout-and-testing.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments, including test files —
each test names the acceptance criterion or defect id it covers.

---

## 1. Phase 0 — decisions and prerequisites (no code)

These must be answered before Phase 1 starts. Each blocks something concrete.

| # | Question | Blocks | Default if unanswered |
|---|---|---|---|
| **P0-1** | Is `member_reference_num` the same value as the platform's `systemuser`, or a second naming scheme under `/mnt/node1/`? (doc 03 §2.2) | The storage root layout | Assume distinct; key on `member_reference_num` as specified |
| **P0-2** | Are all five buckets on **one filesystem**? (doc 08 §5.3) | Whether a bucket move is an atomic `rename` or a copy job with progress UI | Assume one filesystem |
| **P0-3** | Cumulative or absolute quota? (doc 08 §2.2) | `QuotaService::capacityFor()` and the UI copy | Cumulative, per the stated requirement |
| **P0-4** | Is the 30-day downgrade grace window acceptable to the business? (doc 08 §6, decision D6) | `RetentionService` timeline | 30 days |
| **P0-5** | `nginx`: `proxy_request_buffering off` + `client_max_body_size 64m` on the session-upload location (doc 09 §1.3) | Streaming uploads at all | Must be done — not optional |
| **P0-6** | Backup policy for `/mnt/storage1/*/storage/` — and does a purged bucket need to leave backups too? | Retention correctness and legal posture | Flag for the operator |
| **P0-7** | Is the members app's DB the right home for the ledger, or is a shared DB required for other apps to read usage? | doc 04 | members DB |
| **P0-8** | **Is production's `JWT_SECRET` different from the dev value in auth's `.env`?** That value is `members_master_key_exchange_secret`, sitting under a comment reading *"generate a secure 256-bit secret key for production"* | If production shares it, anyone who knows the string can mint a valid token for any member. P0 regardless of this plan (doc 01 §11 Q2) | **Must be answered before Phase 1 is scheduled** |
| **P0-9** | Which existing callers pass `?user_id=` or `?member_reference_num=` to the members API? | Phase 1b is breaking for them (doc 01 §10.1) | Log-and-ignore for one release, then remove |
| **P0-10** | Is `CHECKPOINT_JWT_SECRET` in auth's `.env` live or dead config? `config/checkpoint.php` reads only `JWT_SECRET` | Dead secrets get "reused" later by someone assuming they are live (doc 01 §11 Q3) | Assume dead; delete it |

## 2. Phases

Each phase is independently shippable and leaves the product working.

| Phase | Content | Exit criteria |
|---|---|---|
| **1 — Server authentication** | [01-server-authentication.md](01-server-authentication.md) in full: every route inside `checkpoint.verify`, `member_plan` on the request, the trait, deletion of every client-supplied-identity path, `ServiceClient`, the anti-pattern tests. **Ships alone, before any storage work.** | AU1–AU12 pass; A0 passes |
| **2 — Storage foundation** | `PathResolver`, `NameValidator` (PHP), the five tables, `QuotaService` + `UsageLedger`, the session protocol, `/scan`, `/sessions`, `/commit`, `/tree`, download. **No client changes yet** — verified with `curl`/PHPUnit. | S1–S6 closed; A2, A3 (PHP), A5, A14 pass; traversal corpus rejected in full |
| **3 — Web client** | `resources/js/storage/`, mounted in Cloud Sync's AFS pane. Scan report, conflict dialog, chunked parallel upload, virtualised tree, IndexedDB resume. | A1, A4, A6, A7, A8 pass from a browser; audit C7–C12 fixed |
| **4 — Storage Retention** | Bucket routing/cascade, `/profile`, bucket meters + formula UI, `move-bucket`, `RetentionService`, the sweep, notification ladder, retirement UI. | A9, A10, A11, A12 pass |
| **5 — Desktop** | `src/main/cloud/`, `storage-common/` extraction, sync engine, watcher, renderer Cloud view, token refresh in main. | D1–D10 (doc 10 §8) pass |
| **6 — Mobile** | `storage_repository.dart`, upload engine, bucket UI, scan, download. | M1–M8 (doc 11 §9) pass |
| **7 — Consolidation** | alloc_v2 reporting (doc 08 §7), `user_files` migration + drop, retire `storage.html`, reconcile job in production schedule, docs. | A13 passes; §3 sign-off complete |

**Ordering rationale.** Authentication is Phase 1 and ships alone, because it closes a live
unauthenticated cross-tenant exposure across most of the members API — a defect that exists
today, is not caused by storage, and must not wait for storage's schedule. Every later
phase is a sentence beginning "for this member", which has no trustworthy answer until
Phase 1 lands.

After that: the storage backend precedes any client so the protocol can be exercised with
`curl` before a UI depends on it. The web client precedes retention so uploads can be
exercised before the quota layer sits on top of them. Desktop precedes mobile because it
reuses an engine that already exists in `afsdesktop5/src/main/sync/`. Retention's UI is
Phase 4 rather than Phase 3 so the bucket concept lands together with the feature that
gives it meaning, instead of as an unexplained "bucket 0" label.

## 3. Migrating existing data

**There is no safe automatic migration, and the plan says so rather than pretending
otherwise.**

Because of audit S1, every existing `user_files` row has the **same** `user_id` — the
shared admin account — and every file sits under `/mnt/node2/users/{thatId}/storage` with a
slugged, `uniqid`-suffixed filename. There is no field, and no filesystem attribute, that
attributes a given file to the member who uploaded it. `original_filename` survives in the
DB, but ownership does not.

Procedure:

1. **Freeze.** The old upload route is deleted in Phase 1c (doc 01 §10); freeze happens there.
2. **Inventory.** Produce a report: file count, total bytes, `original_filename`,
   `created_at`, and any correlating signal (upload timestamps against session/login logs).
3. **Operator attribution.** Where correlation gives a confident answer, map to a member.
   Where it does not, the files go to a quarantine area — **never** guessed into a
   member's bucket. Putting member A's file in member B's storage is a worse outcome than
   leaving it unattributed.
4. **Rehydrate.** For attributed files: copy into `/mnt/storage1/{ref}/storage/0/` under
   `original_filename` (falling back to the slugged name only where the original is null),
   validated by `NameValidator`, then create `storage_nodes` rows and update the ledger.
5. **Verify.** `storage:reconcile` reports zero drift.
6. **Retain, then drop.** Keep `user_files` and the node2 tree read-only for **90 days**
   after Phase 7, then drop the table and archive the tree.

If the inventory shows the data is negligible — a handful of test uploads — the correct
answer is to **discard it and tell members to re-upload**, which is faster, safer, and
honest. Decide this at Phase 0 with the actual row count in hand.

## 4. Test matrix

### 4.1 Server (PHPUnit)

| Test | Asserts |
|---|---|
| Traversal corpus (doc 12 §3.2) | Every entry rejected; nothing written outside the root (A2) |
| Name fixture corpus (doc 06 §7) | PHP validator matches the shared corpus exactly (A3) |
| Unauthenticated request to every route | 401; no disk access |
| Member A requests member B's node / session / file id | **404**, not 403 (doc 12 §4) |
| Upload → commit | File at the exact path, real name, byte-identical (A1) |
| Same manifest twice, `policy: fail` | 409 with the full conflict list; nothing overwritten (A4) |
| Concurrent identical uploads | Unique index makes the duplicate impossible; loser gets a conflict (A5) |
| Declared size < actual bytes | Rejected at commit, nothing published |
| Declared sha256 mismatch | `DIGEST_MISMATCH`, `.part` discarded, nothing published |
| Interrupt at 60 % of 4 GB, resume | Resumes within one chunk of 60 % (A6) |
| Stale `Content-Range` start | 409 carrying the true offset |
| 10 000 × 20 KB via batch frames | ≤ 200 requests (A7) |
| Fill bucket 0 on plan 0 | 413 `QUOTA_EXCEEDED`, explicit body |
| Fill bucket 0 on plan 1 | Cascades to bucket 1 automatically (A9) |
| Session larger than any single bucket | Refused with the split-it message (doc 08 §3.1) |
| Two concurrent sessions each fitting, together not | Second refused — reservations counted |
| Move node 2 → 0 and back on plan 3 | Ledger and disk agree afterward (A11) |
| Move to a bucket above plan | 403 |
| Downgrade 3 → 0 | Buckets 1–3 retired, read-only, nothing deleted, member notified (A12) |
| Upgrade during grace | `bucket_restored`, data intact |
| Purge after grace | Files to `.trash`, then deleted; event logged with counts |
| Delete file | Ledger decrements; reconcile finds zero drift (A13) |
| Registry unreachable, no cache | `QuotaService` denies — fails closed (doc 08 §2.3) |
| `membership` null / 9 / "pro" | Plan 0 / clamped 4 / plan 0 |
| Symlink planted between session open and commit | Rejected at commit-time containment check |

### 4.2 Client parity

The name-validation fixture corpus (doc 06 §7.1) runs in **all three** languages in CI.
A rule added to the corpus fails the TypeScript, Dart, and PHP builds until each is
updated. **This is acceptance A3** and it is the mechanism that keeps three
implementations of a security-relevant rule set from drifting.

### 4.3 Web

| Test | Asserts |
|---|---|
| Drop a 500-file folder | All 500 uploaded — guards audit C9's 100-entry `readEntries` limit |
| Drop a 64-deep tree | Rejected with a clear depth error, no stack overflow |
| Reload mid-upload | Resume offered; already-sent bytes not re-sent (doc 09 §2.4) |
| 10 000-entry folder listing | ≤ ~100 DOM nodes |
| Scan report | Each rejected file names its specific rule, never "invalid filename" |
| File with `:` in the name | Rejected in portable mode with the rename suggestion |

### 4.4 Desktop

Per doc 10 §8 (D1–D10). Note especially **D7** — the LAN sync engine's existing tests must
pass unchanged after the `paths.ts` → `storage-common/pathRules.ts` extraction. That
extraction is the only change this plan makes to working code, and it must be provably
inert.

### 4.5 Mobile

Per doc 11 §9 (M1–M8). Note especially **M6**, the three-chunk byte-exactness test that
guards the `File.openRead` exclusive-vs-`Content-Range` inclusive off-by-one (doc 11 §3.1)
— a defect that produces subtly wrong files rather than obvious failures.

### 4.6 Load and soak

| Scenario | Target |
|---|---|
| 50 GB single file, one session | Completes; server memory flat (A8) |
| 100 000 files in one member's tree | Listing stays paged and responsive |
| 20 members uploading concurrently | No cross-tenant leakage; quota accurate per member |
| Kill PHP-FPM mid-upload | Session survives; resume works |
| Kill the DB mid-commit | Transaction rolls back; no orphan file at a final path; reconcile finds the `.part` and cleans it |
| 7-day soak with random churn | `storage:reconcile` reports zero drift |

## 5. Rollout mechanics

- **Feature flag** `storage.v2_enabled`, per member, default off. Enables the new Cloud
  Sync AFS pane; the old view stays reachable until Phase 7.
- **Cohorts:** internal accounts → opt-in beta → 10 % → all. Retention (Phase 4) follows the
  same ladder one step behind, because a quota bug that blocks uploads is more visible than
  a UI bug.
- **Retention is enforced last.** Buckets, cascade, and the meters ship in "observe" mode
  first: usage is tracked and displayed, but `413` is not returned. Turn on blocking only
  after the ledger has been shown accurate against disk for a full week. Getting quota
  wrong in the blocking direction stops paying members from working.
- **No member is downgraded into retirement during the observe window.** `RetentionService`
  runs in report-only mode until Phase 4 exits.

## 6. Rollback

| Phase | Rollback |
|---|---|
| 1 | **Hardest to roll back, and the one you least want to.** Reverting re-opens the exposure. Stage 1b (identity from token, not query param) is the breaking part — the transitional log-and-ignore window in doc 01 §10.1 exists so it does not need rolling back. Stages 1a/1d are additive |
| 2 | New tables and routes are additive; nothing existing depends on them yet |
| 3 | Flag off → old view. Files uploaded via v2 remain and stay reachable through Phase 2's API |
| 4 | Disable enforcement (observe mode). Bucket assignments persist and are harmless |
| 5 / 6 | Client-side; ship a build with the feature hidden. The server is unaffected |
| 7 | **`user_files` drop is irreversible** — hence the 90-day retention in §3 before it happens |

## 7. Operational runbook

| Situation | Action |
|---|---|
| Member reports missing files | `storage:reconcile --member=REF` — reports drift without changing anything |
| Ledger drift found | `storage:reconcile --member=REF --fix` |
| Disk near full | Physical check already returns `507`; alert fires before members see it |
| Member disputes a purge | `storage_retention_events` has the full timeline, byte and file counts, and every notification sent |
| Purge ran wrongly | Files are in `.trash/` for 7 days past the member-facing deadline (doc 08 §6.3) — restore from there |
| Stuck sessions accumulating | `storage:session-sweep`; investigate whether a client is failing to commit |
