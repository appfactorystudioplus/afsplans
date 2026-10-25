# 12-security — Completion

**Status:** Substantially complete. Every gap the first pass through this document
flagged as a real follow-up — physical free-space check, session/`.part` expiry sweep,
rate limiting, structured audit logging, the traversal-corpus test, and an ownership
test proving 404-not-403 — is now built and verified, not just planned. One pre-existing
bug outside this plan's own scope (a SQLite-incompatible duplicate index name in a
`checkpoint-server-auth` migration) was found and fixed because it silently blocked
*any* PHPUnit feature test using `RefreshDatabase` in this entire app, which made §10
item 6 impossible without touching it. Remaining gaps: the doc 06 §7 shared
TypeScript/Dart/PHP fixture corpus (§10 item 5) still doesn't exist anywhere in this
plan, nginx's `proxy_request_buffering off` (§10 item 9) is a deployment prerequisite
outside application code, and no live penetration test has been run (§10 item 11 — no
live environment in this session).

## Summary

### §2 / §2.1 — authentication, no implicit tenancy

Unchanged from the first pass — see the original notes below (§2/§2.1 were already
correctly implemented and didn't need revisiting this pass): storage routes sit inside
`checkpoint.verify:primary_only`; no Eloquent global scope anywhere; every service
method takes `member_reference_num` as an explicit first argument.

### §3 / §3.1 / §3.2 — path containment, symlinks, the traversal corpus

**§3/§3.1 unchanged** from the first pass (already correct). **§3.2 — now a real,
passing, automated test**, closing §10 item 4:
`tests/Unit/Storage/PathTraversalCorpusTest.php`, 16 assertions, pure unit (no DB, no
auth, no HTTP — `NameValidator`/`PathResolver` are pure classes) against the doc's exact
corpus. Every entry's expected outcome was **verified by actually running it** through
the real classes (a small throwaway probe script, not assumed from the doc's prose)
before the test was written — this caught something worth recording precisely rather
than glossing over:

**Three corpus entries are correctly *accepted*, not rejected**, and this is not a gap:
- `..\escape.txt` — a literal backslash is not a path separator on this POSIX host/mount;
  it resolves to an ordinary, fully-contained file literally named `..\escape.txt`.
  There is no traversal to defend against here on this architecture.
- `%2e%2e%2fescape` and `..%2fescape` — every `rel_path` in this API arrives as a plain
  string inside a JSON request body, never a URL path segment, and this app never
  percent-decodes a JSON body value. The literal `%`/`2`/`e`/`f` characters just become
  part of an ordinary filename.

Both properties were confirmed by actually calling `PathResolver::resolve()` on all
three and asserting the result still resolves fully inside the bucket root — the test
proves containment holds, not merely that the string "looks accepted."

### §4 — authorisation on every object

**Now backed by an automated test**, closing §10 item 6:
`tests/Feature/Storage/StorageOwnershipTest.php`, 9 tests, all passing. This required
building test infrastructure that didn't exist in this app at all before this pass — see
"The migration bug" below. Covers: `GET /files/{id}/content`, `PATCH /nodes/{id}`,
`DELETE /nodes/{id}`, `POST /nodes/move-bucket`, `GET/PUT/POST/DELETE /sessions/{id}...`
— every id-taking handler in the storage surface — each proven to return 404 (asserted
literally, `assertNotFound()`), never touch the resource, when the caller isn't its
owner. A control test (`test_content_returns_404_for_a_nonexistent_node_too`) proves the
404 is a real ownership check and not just "this endpoint always 404s."

Ownership-denial events are now also audited (see §9) at every one of these check
points, distinguishing "this id genuinely doesn't exist" (not logged — routine 404,
not an attack signal) from "this id exists and belongs to someone else" (logged).

### §5 — upload-specific risks

Two items moved from "not implemented" to implemented and tested:

- **Physical free-space check (`507`), independent of plan quota.**
  `QuotaService::hasPhysicalSpace()` (new) checks `disk_free_space()` against the real
  storage mount (`PathResolver::storageRoot()`, a new public accessor — the mount path
  itself was already private to `PathResolver`, doc 12 §10 item 1's "exactly one match"
  property is preserved) with a 5 GiB safety margin, wired into
  `StorageSessionController::open()` right after the plan-quota check. **Deliberately
  not fail-closed the way the registry-unreachable case is**: if the mount doesn't exist
  on this host at all (`is_dir()` false — true of every non-production environment,
  since `/mnt/storage1` is a production-only mount), the check is skipped rather than
  returning `507` for every single upload on every dev/test/CI machine. If the mount
  *does* exist but reading it fails, that's treated as insufficient space. This
  asymmetry — one check fails closed, the other doesn't — is deliberate and documented
  in the method's own comment, not an oversight: the registry case being unreachable is
  itself a security-relevant failure (quota bypass); the mount not existing on a
  non-prod machine isn't a security failure, it's just not being on the real host.
- **Session/`.part` expiry sweep.** `storage:session-sweep` (new command, hourly via
  `routes/console.php`, matching the existing `storage:retention-sweep`/
  `storage:tombstone-sweep` pattern) finds every `storage_sessions` row still
  `open`/`committing` past its 48h `expires_at`, releases whatever reservation its
  still-outstanding files hold (`UsageLedger::release()` — the exact method
  `UsageLedger::reserve()`'s own doc comment named as "not built in this pass"),
  deletes its `.staging/{sessionId}/` directory, and marks it `expired` (the schema's
  `status` enum already included this value; nothing had ever written it before now).
  **Tested**: `tests/Feature/Storage/StorageSessionSweepTest.php`, 3 tests — expires a
  past-TTL session and releases its reservation; leaves a not-yet-expired session
  untouched; and specifically proves a session with one `committed` file and one
  `pending` file only releases the pending file's bytes, never double-releasing bytes
  already converted to `used_bytes`.

Everything else in this section is unchanged from the first pass (already correct or
already flagged as not applicable/deployment-layer).

### §6 / §7 — download risks, secrets and tokens

Unchanged from the first pass — both already fully implemented/inherited correctly.

### §8 — rate limiting

**Now implemented**, closing the largest previously-"not implemented" section.
`AppServiceProvider::registerStorageRateLimiters()` (new) defines four named limiters —
`storage-scan` (30/min), `storage-sessions` (20/min), `storage-tree` (120/min),
`storage-mutations` (60/min) — each keyed **by `member_reference_num`**, read from the
same request attribute `VerifyCheckpointToken` sets (falling back to IP only as a
defensive, should-be-unreachable case), per doc 12 §8's explicit "per member, not per
IP... mobile members share carrier NAT" reasoning. Applied via `throttle:` middleware to
the corresponding routes in `routes/web.php`'s storage group — verified with
`php artisan route:list -v` that `checkpoint.verify:primary_only` runs before the
`throttle:` middleware on every route it's attached to (so the limiter key is always
resolvable), not just assumed from declaration order.

**Two of the doc's six table rows deliberately not implemented as named limiters**,
both documented in `AppServiceProvider`'s own comment: `PUT` chunks ("unlimited count,
≤ 8 concurrent, ≤ 64 MiB each" is a concurrency/size cap, not a request-rate limiter —
no code enforces the 64 MiB-per-chunk or 8-concurrent-streams caps either, a genuine
remaining gap); and failed-auth throttling (10/min/IP — that's `VerifyCheckpointToken`
itself, a platform-wide concern across every `checkpoint.verify` consumer app-wide, not
storage-specific, out of this document's boundary).

### §9 — audit logging

**Now implemented.** `App\Services\Storage\SecurityAudit` (new) writes structured JSON
entries — `member_reference_num`, source IP, client origin, plus event-specific context
— to a **new dedicated `storage_audit` log channel** (`config/logging.php`, `daily`
driver, 400-day retention by default, separate from the app's normal 14-day default log
specifically because doc 12 §9 says deletion of member data "must be explainable months
later"). Wired into every point doc 12 §9 names:

- **Every rejected path, with its rule id** — `StorageSessionController::
  validateManifest()` (scan/session-open time) and `commitOneFile()` (commit-time
  re-validation), `StorageTreeController::content()`/`deleteRecursive()`/
  `createFolder()` (download/delete/create-time).
- **Every ownership-check failure** — every 404-not-403 point across all three storage
  controllers (§4 above), each distinguishing "exists, wrong owner" (audited) from
  "doesn't exist" (not audited, not an attack signal).
- **Every session lifecycle event with byte totals** — open (declared bytes), commit
  (committed bytes + committed/failed counts), cancel (released bytes), and the sweep's
  own expiry (released bytes).
- **Every bucket move** — node ids, target bucket, bytes moved.

**Retention state changes are deliberately not duplicated here** — `RetentionService`
already writes every one to the queryable `storage_retention_events` table (doc 04 §5),
which independently satisfies that specific §9 bullet; adding a second audit-log copy of
the same event would be redundant, not additive.

**Verified working end to end, not just wired**: running the ownership test suite
produced real entries in `storage/logs/storage-audit-{date}.log` — e.g.
`storage.ownership_denied {"member_reference_num":"member-b",...,"resource_type":
"storage_node","resource_id":1,"action":"content"}` — confirming the channel, the
service, and every call site actually work together, not merely that each piece
compiles in isolation.

### §10 — pre-launch checklist, item by item (updated)

| # | Item | Result |
|---|---|---|
| 0 | Step 01's checklist passes | Yes — unchanged |
| 1 | `grep -rn "/mnt/storage1" app/` → one match | **Pass** — still true; the new `PathResolver::storageRoot()` accessor returns the same constant, doesn't duplicate it |
| 2 | `grep -rn "logInAsUserByUsername" app/` → zero | **Pass** |
| 3 | No storage route outside `checkpoint.verify` | **Pass** |
| 4 | Traversal corpus rejected in full, with a test | **Pass, now** — `PathTraversalCorpusTest.php`, 16 assertions, all against the real classes |
| 5 | Doc 06 fixture corpus passes in TS/Dart/PHP | **Not done** — the *shared* corpus still doesn't exist in any of the three ports; each language now has its own independent hand-written suite (PHP: this pass; TS/Dart: none — see follow-up) |
| 6 | Every id-taking handler has an ownership test | **Pass, now** — `StorageOwnershipTest.php`, 9 tests, every id-taking storage endpoint |
| 7 | Downloads set `nosniff` + RFC 5987 filename* | **Pass** — unchanged |
| 8 | No token written to localStorage in web client | Inherited, not re-verified this pass |
| 9 | nginx `proxy_request_buffering off` | **Not done** — deployment config, still a prerequisite |
| 10 | Physical free-space check | **Pass, now** — `QuotaService::hasPhysicalSpace()`, wired into session open, `507 INSUFFICIENT_STORAGE` |
| 11 | Penetration pass against a second member's ids | **Not done** — no live environment; the *automated* equivalent (item 6) is now done, which covers the same property mechanically rather than manually |

### The migration bug (found and fixed, outside this document's own scope)

`database/migrations/2026_07_13_000001_create_checkpoint_tables.php` (owned by the
already-completed `checkpoint-server-auth` plan, not this one) named several indexes
identically across *different* tables — `idx_user_id` five times, `idx_expires_at`
three times, `idx_email`/`idx_username` twice each. MySQL scopes index names per-table,
so this was harmless in production; **SQLite scopes them per-database**, so
`RefreshDatabase`'s migration run failed with `index idx_user_id already exists` before
a single assertion in *any* feature test could run — confirmed this blocked the one
pre-existing attempt at a `checkpoint.verify`-gated feature test
(`AfsConnectSettingsSourceKeysTest`) too, which never got past `setUp()`. Fixed by
making every duplicate name table-qualified (e.g. `idx_user_features_user_id`,
`idx_secondary_sessions_expires_at`) — 12 renames, confirmed via grep that no other code
anywhere references any of the old names, so this is a pure metadata rename with zero
behavioral change on the real (MySQL) database. Fixed because it made §10 item 6
impossible to build at all, not as unrelated cleanup — same category of "environment
fix needed to verify this plan's own work" as the `composer.lock`/`welcome.blade.php`
fixes recorded in `00-overview.complete.md`.

**Two pre-existing, unrelated test failures remain** (`AfsConnectSettingsSourceKeysTest`
×2, `LoginRedirectTest` ×2) — confirmed, by reading the diff, that this pass touched
none of the code either failure depends on (afs-connect settings validation, login-host
redirect config). Not fixed; out of scope for this document.

## Files changed

**New:**
- `app/Services/Storage/SecurityAudit.php`
- `app/Console/Commands/StorageSessionSweep.php`
- `tests/Unit/Storage/PathTraversalCorpusTest.php`
- `tests/Feature/Storage/StorageOwnershipTest.php`
- `tests/Feature/Storage/StorageSessionSweepTest.php`

**Changed:**
- `app/Services/Storage/PathResolver.php` — `storageRoot()` accessor
- `app/Services/Storage/QuotaService.php` — `hasPhysicalSpace()`, `PathResolver` dependency
- `app/Http/Controllers/Storage/StorageSessionController.php` — `SecurityAudit` dependency + calls, `507` free-space check
- `app/Http/Controllers/Storage/StorageTreeController.php` — `SecurityAudit` dependency + calls
- `app/Http/Controllers/Storage/StorageBucketController.php` — `SecurityAudit` dependency + calls
- `app/Providers/AppServiceProvider.php` — `registerStorageRateLimiters()`
- `config/logging.php` — `storage_audit` channel
- `routes/web.php` — `throttle:` middleware on the rate-limited routes, one stale comment corrected (it claimed `GET /delta`/`PATCH /nodes/{id}`/`POST /nodes/move-bucket` were unimplemented; all three shipped in later passes and sit right below the comment)
- `routes/console.php` — `storage:session-sweep` scheduled hourly
- `database/migrations/2026_07_13_000001_create_checkpoint_tables.php` — 12 duplicate index names made unique (environment fix, see above)

## Verification steps

- `php artisan test` — 31 passing (up from 22 before this pass; the 4 pre-existing
  unrelated failures are unchanged in count and confirmed unrelated by diff).
- `php artisan test --filter=PathTraversalCorpusTest` — 16/16.
- `php artisan test --filter=StorageOwnershipTest` — 9/9.
- `php artisan test --filter=StorageSessionSweepTest` — 3/3.
- `php artisan route:list --path=api/v1/storage -v` — confirmed every rate-limited route
  carries `checkpoint.verify:primary_only` before `throttle:storage-*` in its resolved
  middleware stack.
- `php artisan route:list --path=api/v1/storage` — all 15 routes still register after
  every edit in this pass (no route lost or mis-registered).
- Read `storage/logs/storage-audit-*.log` after a test run — real, correctly-shaped
  entries present, not just code that would theoretically produce them.
- A standalone probe script ran the full §3.2 traversal corpus against the real
  `NameValidator`/`PathResolver` before the corresponding test was written, so every
  expected outcome in `PathTraversalCorpusTest.php` reflects actual verified behavior,
  not an assumption from the doc's prose.
- `grep -rn "idx_user_id\|idx_email\|idx_username\|idx_expires_at" database/` (before
  the migration fix) → 5/3/2/2 duplicates; (after) → zero duplicates, one occurrence
  each, confirmed via `sort | uniq -c`.
- `grep -rn "/mnt/storage1" app/` → still exactly one match (item 1 still holds after
  adding `storageRoot()`).

## Follow-up work

Priority order:

1. **The doc 06 §7 shared TypeScript/Dart/PHP fixture corpus** (§10 item 5) — the single
   most-repeated follow-up item across this entire plan's completion docs. PHP now has
   its own real corpus test (this pass); TypeScript and Dart still have none. Extracting
   one shared JSON file all three consume would finally close this for good instead of
   three (soon possibly more) independently-hand-written, driftable suites.
2. **`PUT` chunk concurrency/size enforcement** (doc 12 §8's other table row) — no code
   currently caps a chunk at 64 MiB or a member's concurrent streams at 8; only the
   request-rate limiters built this pass exist.
3. **Failed-auth throttling** (10/min/IP) — belongs in `VerifyCheckpointToken` itself,
   platform-wide, not storage-specific; flagged here because doc 12 §8 names it but it's
   really a `checkpoint-server-auth`-plan follow-up.
4. **nginx `proxy_request_buffering off`** (§10 item 9) — deployment config, not code;
   same category as `09-web-client.complete.md`'s identical note.
5. **A live penetration pass** (§10 item 11) — no live environment in this session;
   the automated ownership tests (item 6, now done) cover the same property
   mechanically, but a real adversarial pass against a running server is still distinct
   value not yet obtained.
6. `LoginRedirectTest`'s two pre-existing failures and `AfsConnectSettingsSourceKeysTest`'s
   two pre-existing failures — both confirmed unrelated to this plan's work, not fixed,
   worth a separate look since they indicate the members app's `.env`/test config and an
   unrelated feature's request validation are each currently broken in ways nothing in
   this plan surfaced until this pass's `php artisan test` run touched them.
