# 07-health-polling-and-reporting — Completion

**Status:** P0, P1 and P2 are built and tested. **Not run against a real mount, the lab, or a real outage** — "unmount a lab
device → offline; remount → healthy after two reports" is played out from a *real report payload* produced by the storage
app, fed through the Brain's signed surface, but not on the lab VM (step 11). Nothing was committed. Development mode: `vagrant`.

Changed (all uncommitted): **www** (derivation, stale job, Fleet data and screen), **storage_device** (retry queue). Plan
docs 07 got an "As built" section.

## Summary

- **P0 — queue.** The polling and the signed report existed from step 05. Added the bounded retry queue: every poll enqueues its
  report first, then sends oldest-first, so normal operation is one request per poll and an outage loses nothing until the
  bounds (120 reports / 24 h, oldest dropped first). Each retried report keeps its original `measured_at`; a fresh signature is
  made per attempt. Retry vs drop is by status: network error / 5xx / 408 / 429 / 401 / 403 are retried, any other 4xx is
  dropped (it can never succeed). Depth is shown in `storage:devices` and the Brain-facing `state`.
- **P1 — derivation.** `StorageHealthDeriver` implements the §3 table with a fixed priority; thresholds are config; only an
  explicit `false` counts as a fault; fill uses the same "smaller of declared and reported" capacity as placement. A problem
  applies immediately; any device that is not healthy (offline, degraded, or unknown) needs **two consecutive clean reports**
  to become healthy, keeping its reason meanwhile, so a flapping mount never oscillates (and writes no repeated events). A
  stale/duplicate report changes nothing. `admin_state` is never touched.
- **P2 — staleness and the Fleet screen.** `brain:storage-mark-stale` (every minute, Brain only, no overlap) marks silent devices
  `unknown` / `stale_report`, one event per change, idempotent, leaving never-reported and retired devices alone; a silent
  server ages all its devices out together. The Fleet endpoint now returns each server's `liveness` (`up`/`down`/`never`)
  and the 25 most recent health transitions; the screen shows a reporting badge per server and a "Recent health changes"
  table.

## Verification

| Check | Result |
|---|---|
| www `BrainStorageHealthTest` | **49 pass** — the whole §3 table incl. boundaries and priorities, config thresholds, two-report recovery, flapping, one-event-per-change, admin_state untouched, out-of-order reports ignored, atomicity of a refused report, multi-device reports, a signed report over HTTP, the stale job (TTL, idempotence, retired/never-reported/off-Brain, silent server vs healthy server), the schedule entry, and the Fleet payload |
| www `BrainStorageReportContractTest` | **5 pass** — a **real** report captured from the storage app (`tests/fixtures/storage_device_report.json`): accepted, every reading recorded, an unmounted device offline on report 1, a healthy one after 2, remount → healthy after 2, unknown extra fields tolerated |
| storage_device `ReportQueueTest` | **33 pass** — report body from real measurements, no secrets, outage → queue → ordered drain, original `measured_at` kept, one attempt per poll while down, partial recovery, each status accepted/retry/dropped, refusal not blocking later reports, unconfigured server queues, bounds, operator visibility |
| www `Feature/Brain` + `Unit/Database` | 258 tests, 0 failures |
| storage_device full suite | 431 tests, 0 failures, 4 skipped (symlink tests, as before) |
| `npx tsc --noEmit` (www) | exit 0; `brain_storage.ts` confirmed in the program |

## Not verified

- **The Fleet screen was not built with Vite and never opened in a browser** (same as step 03). `tsc` is clean.
- **No real mount, no lab, no real outage.** `inodes_free` is `null` on this Windows machine (Linux `stat -f` only) and the
  write probe/`fsync` ran against a temp directory. The contract fixture's device 1 numbers are this machine's disk.
- **The queue retry was not exercised across process restarts of a real scheduler** — only across separate poll invocations.
  (The queue is in SQLite, so restarts should be safe; not tested with `schedule:run`.)
- **Members' placement was not re-run against the new derivation.** Step 04's eligibility already requires `health = healthy`
  and a fresh report; nothing in members changed, but no test drives a device from unknown to placeable end to end.
- The migration `2026_09_27_000002_create_report_queue_table.php` (storage_device) has only run in memory.

## Not done / carried forward

- **The device page's report-history chart / capacity trend** (03 §3) is still not built.
- **Alerting.** The plan says staff "can be alerted" on a transition; events are recorded and shown, but nothing emails or
  pages anyone.
- A storage server that is `maintenance` is still watched for staleness like any other; nothing suppresses the noise.

## Decisions to confirm

1. An `unknown` device (new, or returning from silence) needs **two** clean reports, not one.
2. A partial repair moves `offline → degraded` **immediately**.
3. `401`/`403` on a report are treated as *retry* (a credential mid-rotation), so a permanently wrong credential fills the queue to
   its cap rather than dropping reports — the alternative loses reports during a normal rotation.

## Environment notes

- The www staff-route tests were making a real network call (creating a test `Membership` fires a tier-changed request to
  members); they now `Http::fake()`, which also made them fast. The new health test does the same.
- A `sed` with backslashes corrupted a class name once (caught by running the tests). I switched to the editor tool.
