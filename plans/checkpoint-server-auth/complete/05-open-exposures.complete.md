# 05-open-exposures — Completion

**Status:** Substantially complete. All five items in §1's summary table have a recorded,
dated outcome — three fixed with real code and tests (F11, F7, F8's gating half), one
fixed in this pass (F6), and one (F3) correctly still open pending members/market's
migration onto the shared package. §6 (revocation) is a stated, accepted design decision
requiring no code. §7 (the public-route register) is updated to current, accurate state
across every app touched by this plan so far.

## Summary

### F11 — `JWT_SECRET` (§5) — done in an earlier pass

Confirmed the production-committed value was a guessable phrase, not generated entropy,
and rotated it using the existing `JWT_PREVIOUS_SECRETS` mechanism. Full detail
unchanged from the prior version of this document — see Files Changed and Follow-up
below, both carried forward.

### F7 — negative caching (§4) — done via step 04

The split-TTL fix (60 s success / 5 s failure, `Cache::put` instead of `Cache::remember`)
shipped as part of the merged `CheckpointTokenValidator` in the `afs/checkpoint-auth`
package (step 04), live in coordinator, flow, notes, pdf_editor, and dream_graph.
Verified in that step's own behaviour suite (`test_failed_validation_then_retry_after_
six_seconds_reaches_auth_again` / `test_successful_validation_retry_after_six_seconds_
is_served_from_cache`, both actually run and passing). Not yet true for members/market,
which haven't adopted the package — tracked as their own follow-up, not re-tracked here.

### F8 — members VoIP (§3) — gating done via step 03; enforcement question stays open

`/api/voip/*` is inside the authenticated group as of step 03. The plan-limit
enforcement question (record provisioned numbers against `member_reference_num` so
`voip_numbers_count` can be enforced?) is unanswered by design — this document doesn't
decide it, same as the plan originally specified.

### F6 — dream_graph asset serving (§2) — decided and implemented this pass

Before implementing, the user pushed back on assuming this is browser-only and asked for
the Joystick Engine's own plans to be checked. That investigation mattered: it confirmed
the engine does **not** consume `/api/assets/{uuid}` — it gets textures/tilesets bundled
inside the already-authenticated `tilemap.sync` export ZIP instead
(`TilemapSyncController::export()` returns the zip bytes directly). Full reasoning
recorded in `05-open-exposures.md` §2.5, with the exact plan-step and code references.

Implemented the plan's own recommendation — **option B (signed cookie) + option C's
mitigating headers**:

- `dream_graph/app/Http/Middleware/IssueAssetAccessCookie.php` — new. Issues a
  short-lived (1 h), HMAC-signed, httpOnly cookie scoped to `/api/assets` on every
  `checkpoint.member`-gated request. Written directly onto the response rather than via
  `Cookie::queue()`, since this app's API routes don't run `AddQueuedCookiesToResponse`.
- `dream_graph/app/Http/Middleware/VerifyAssetAccessCookie.php` — new. Guards the two
  asset routes; accepts a valid Checkpoint bearer (for any future non-browser caller) or
  the signed cookie (what the 8 real browser call sites get automatically, zero
  frontend changes).
- `AssetController::streamAsset()` — added `X-Robots-Tag: noindex` and
  `Referrer-Policy: no-referrer`. `Cache-Control` deliberately left at its existing
  long-lived `immutable` value — reasoned in the code comment, not shortened, since
  assets are content-addressed and the route is authenticated now.
- Docblocks in `AssetController` and `routes/api.php` updated to record the 2026-08-23
  decision in place of the old "known, not-yet-fixed exposure" language, per this plan
  document's own instruction that an "accept the risk" (or in this case, "here's the
  fix") conclusion belongs in the code, not only in the plan.

**What this does not close**: per-asset project ownership. Any member with a currently
valid Checkpoint session can still read any asset UUID belonging to any project — the
fix narrows the exposure from "anyone on the internet" to "someone with a valid
session," which is exactly option B's documented scope (versus option A's, the more
expensive signed-URL approach, which remains the correct fix for real ownership
enforcement and is still out of scope).

### §6 — revocation

No code change; restating the plan's own accepted position (bounded by the 60 s cache
plus 1 h token lifetime, not something this plan closes) is the whole of what this
section asked for. Nothing to add.

### §7 — public-route register

Updated to current state:

- Removed `dream_graph GET /api/assets/{asset}`, `…/thumbnail` — no longer fully public
  as of the F6 fix above.
- Added `members GET/POST /api/v1/progress/*` — the deliberately-ungated pair from step
  03 §4.4 (one has a real non-browser caller with no bearer to present; the other is a
  bounded capability-URL read), which step 03 documented in code but this register
  hadn't caught up to.
- Added `members GET /api/storage/*` — explicitly flagged as **deferred, not decided**
  (out of this plan's scope, belongs to the separate cloud-storage plan), so it isn't
  silently absent from the register nor miscategorized as a settled decision.

## Files changed

**This pass (F6):**
- `dream_graph/app/Http/Middleware/IssueAssetAccessCookie.php` — new
- `dream_graph/app/Http/Middleware/VerifyAssetAccessCookie.php` — new
- `dream_graph/app/Http/Controllers/Api/AssetController.php` — mitigating headers,
  updated docblocks
- `dream_graph/routes/api.php` — asset routes gated by `verify-asset-cookie`; main group
  gained `issue-asset-cookie`
- `dream_graph/bootstrap/app.php` — two new middleware aliases
- `dream_graph/tests/Feature/AssetAccessCookieTest.php` — new, 6 tests
- `dream_graph/tests/Unit/IssueAssetAccessCookieTest.php` — new, 3 tests
- `afsserver/plans/checkpoint-server-auth/05-open-exposures.md` — §1 status column, §2.5
  (new), §7 register updated

**Prior pass (F11), carried forward unchanged:**
- `auth/.env` — `JWT_SECRET` rotated, `JWT_PREVIOUS_SECRETS`/`_EXPIRES_AT` added,
  `CHECKPOINT_JWT_SECRET` deleted.

## Verification steps

- **F6 — actually executed, not reasoned.** `vendor/bin/phpunit tests/Feature/
  AssetAccessCookieTest.php tests/Unit/IssueAssetAccessCookieTest.php` → **9/9 pass**,
  covering: no-credential rejection on both routes, valid-cookie acceptance with both
  mitigating headers present, expired-cookie rejection, tampered-signature rejection,
  garbage-value rejection (401, not a 500), the thumbnail route honouring the same
  cookie, cookie non-issuance when no member attribute is present, and — the test that
  actually proves the two middlewares agree — the exact value `IssueAssetAccessCookie`
  produces verifying successfully through `VerifyAssetAccessCookie::hasValidCookie()`.
- Ran dream_graph's full suite before and after this change: same pre-existing,
  unrelated failure count (33 errors / 31 failures, all traced to missing model
  factories and an absent Checkpoint-HTTP test-fake — see step 04's completion notes)
  both times. Zero new failures.
- One real test-harness pitfall hit and fixed during this pass, worth recording:
  Laravel's `TestCase::withCookie()` auto-encrypts the value it sends, assuming
  `EncryptCookies` will decrypt it on the way in. This app's API routes don't run that
  middleware (the cookie here is deliberately hand-signed, not framework-encrypted), so
  the first test run failed — not because the implementation was wrong, but because the
  test wasn't simulating a real browser correctly. Fixed by using
  `withUnencryptedCookie()` instead, which matches what an actual browser sends.
- `php artisan route:list --path=assets` confirms both routes boot under the new
  middleware with no fatal errors.
- F7/F8(gating)/F11 verification is unchanged from their respective completion docs
  (04-shared-validator, 03-members-remediation) — not re-verified here, only
  cross-referenced.

## Follow-up work

- **F3 (app_name enforcement for members/market)** remains open — blocked on those two
  apps adopting the shared package (step 04's own follow-up, not new here).
- **F8's plan-limit enforcement question** (VoIP numbers per member) remains
  deliberately unanswered, as the plan specifies.
- **F6's ownership gap** (any valid session can read any project's asset UUIDs) remains
  open — closing it for real means signed URLs threaded through all 8 frontend call
  sites, a materially larger change than this pass's fix.
- **User must still apply the F11 rotation to whatever is actually live in
  production** — unchanged from the prior version of this document; this session's
  edit only touched the local `auth/.env` checkout.
- **Delete `JWT_PREVIOUS_SECRETS` / `_EXPIRES_AT` after 2026-09-21** — unchanged
  reminder, carried forward.
- **members' `GET/POST /api/v1/progress/*` and `GET /api/storage/*`** remain
  deliberately ungated / deferred respectively — both newly reflected in the §7
  register this pass, neither newly resolved.
