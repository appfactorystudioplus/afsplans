# 08-security — Completion

**Status:** Done, in the sense that every phase's "done when" is now met by tests — but per the user's instruction
this round, **nothing was verified against the lab** (it was stopped before this step started). Everything below is
unit/feature-test verification only. Nothing was committed. Development mode: `vagrant`.

Most of what this step asks for already existed from steps 03–07 (the credential model, token claim checks, the
containment port, the traversal corpus, the §6 compromised-server table). This step's actual new work is narrower:
resolving the single-use-download-token question left open in step 06, and building the two abuse controls (§7) that
had not been enforced yet. Changed: **storage_device** (all the new code), **members** (one end-to-end test corrected
so it no longer reuses a download token — see below).

## Summary

- **P0 (already done, re-confirmed).** Per-server secrets, the per-server-derived members→storage key, direction
  isolation and rotation grace are unchanged from steps 03/06/07; no new code.
- **P1 — single-use download tokens (new).** The plan says download tokens are single-use; step 06 flagged that this
  conflicts with resumable ranged downloads (resuming means presenting the same token again) and left it open. **Decision:**
  a token is spent on its first use of any kind. A resumed download gets a fresh token from members'
  `GET .../download-url`, which is stateless and never calls the device — the same shape as the existing "refresh an
  expired upload token" flow. Built as `App\Services\DownloadTokenLedger` (storage_device): an atomic
  `INSERT OR IGNORE` keyed on the token's `jti`, so a race on a captured token has exactly one winner. A new migration
  adds `spent_download_tokens`; `storage:prune-ops` drops rows whose `exp` has passed.
- **P2 — abuse controls (new).**
  - **Concurrent-stream cap** (`storage_device.upload.max_concurrent_streams_per_session`, default 4): no permanent
    counter — a file only counts as "in flight" while it is `uploading` and was touched within
    `concurrency_window_seconds` (default 30s), so a crashed upload's shadow expires on its own rather than locking
    the session out. `StreamConcurrencyGuard` implements it; a rejection is `429 CONCURRENCY_LIMIT` and never touches
    the file.
  - **Per-member rate limit** (`rate_limit_per_minute`, default 600/min), keyed by the token's `sub`, shared by the
    upload and download routes, registered as Laravel's `device-client` limiter and applied via `throttle:device-client`
    (which runs after the token middleware, so it reads the verified claim, not client input). A limited request is
    `429 RATE_LIMITED` and audited.
  - `tests/Feature/OwnershipTest.php` consolidates the "every id-taking handler is 404, never 403" property into one
    file, per the plan's own wording ("ownership...test suites").
- **P3 (already done, re-confirmed).** The §6 table's three attempts were already exercised in earlier steps' tests.

## Verification

| Check | Result |
|---|---|
| storage_device full suite | **457 tests, 0 failures, 4 skipped** (the pre-existing symlink tests — unrelated to this step) |
| New this step | `AbuseControlsTest` (16), `OwnershipTest` (10), single-use additions to `DownloadAndCorsTest` (4), `PruneOps`/`DownloadTokenLedger` covered incidentally |
| members full suite | 219 tests, 0 failures, 6 skipped (the opt-in end-to-end tests, unaffected except the one corrected below) |

Not run this round, per your instruction: the lab, and the local PHP-built-in-server end-to-end harness used in steps
05–06. `StorageDeviceEndToEndTest` (members, opt-in via env vars) was corrected so it no longer reuses a single
download token for two requests — it now mints a second token for the second range and asserts the first is refused
on replay — but that correction itself has **not been run**, since it needs the harness this round skipped.

## Not verified

- **The single-use ledger's atomicity was exercised by only one PHP process** (`insertOrIgnore` on SQLite): the
  "two requests racing" test is sequential, not truly concurrent, so it proves the logic but not real-world contention.
- **The concurrency cap's self-healing window** was tested with `travel()`, not a real crashed process.
- **The rate limiter's cache store** is `array`/`file` in tests; production would need to confirm the store survives
  across PHP-FPM workers (a per-process `array` cache would make the limiter useless) — this needs a real deployment
  to confirm, not a unit test.
- Nothing here touched the lab, so §8 TLS and the nginx/Apache prerequisites (`proxy_request_buffering off`, body-size
  limit, per-request timeouts) remain undeployed and unverified — they are step 11's job.

## Decisions to confirm

1. **Download tokens are single-use in the strictest sense** (spent on first use, not "spent once the file has been
   fully read"). This trades a slightly heavier resume path (one extra members call) for tokens that actually cannot
   be replayed at all, rather than only after completion. If you'd prefer the weaker "spent at EOF" semantics to avoid
   that extra call, say so — it's a bigger change (the device would need to know when a range reaches the file's end).
2. **The rate limit is keyed by the token's `sub`** (the member), not `jti` or IP. A member's tokens across every
   session and device share one budget; this seemed like the more meaningful limit than per-token-instance, since a
   single upload session's token is reused for every chunk of that session.
3. **The concurrency cap is approximate and self-healing**, not a hard permanent counter, specifically so a crashed
   upload cannot lock a session out of the cap forever. The cost is a window (default 30s) during which a just-finished
   stream could in theory still count against a new one; this seemed like the safer failure direction than the
   alternative (a leaked slot that never clears without an operator intervening).
