# Complete — Step 2: `allocConsume` and `allocRelease`

**Date:** 2026-08-31
**Plan:** `03-parse-cloud-functions.md` §3.4, §3.8 step 2
**File:** `w/auth/public/checkpoint/parse/cloud/functions.ts`

> ✅ **Executed. 46 of 46 checks passed** against a running Parse instance
> (`build.sh --local`, local MongoDB) — 32 on the service-key path, 14 on the token path.

## What was there before

The stock parse-server scaffold: `hello`, `helloAsyncFunction`, `beforeSave('TestObject')`.
Confirmed by reading the file, exactly as the plan's status table claimed. The scaffold is
left in place beneath the new functions.

## The one thing these functions do

Compare and increment inside a single call. That is all, and it is deliberate:

```
alloc_v2 keeps       the registry, the tier, the limit resolution, canUse(),
                     the limit dialog, the upgrade prompt, the level-99 bypass
these functions do   a conditional increment
```

The caller passes the ceiling it resolved as `limit`. A forged `limit` therefore still works,
and that is a recorded, deliberate limitation (§3.4.3) rather than an oversight — allocation is
advisory quota management, not a security boundary.

## The detail that makes the write correct rather than subtly broken

Implementation **(b)** from §3.4.1: increment, then verify against the value **Parse returned
to this caller**, and compensate if it overshot.

The trap is re-querying instead. With `used = 9, limit = 10` and two concurrent callers, A's
save returns 10 and B's returns 11. A accepts; B decrements and refuses; final state 10 —
correct. Re-read the row and **both** see 11, both roll back, and an allocation that should
have succeeded is refused.

**The per-caller return value is the serialisation.** The code says so at the exact point
where someone would add a re-read.

The compensating decrement subtracts **the amount we added**, never "back to what I read" —
the latter discards whatever a racer legitimately consumed in between.

## Period rollover

Implemented against the **built** model rather than the plan's earlier one, per §3.2.3:
`period_start` is a field on one permanent row, a rolled-over counter is `set` rather than
incremented, and the reset rides along in the same save so it cannot be lost between two
operations.

`periodHasRolledOver()` mirrors `AllocationGateway::periodHasRolledOver()` in `auth`, in UTC,
**including its treatment of a never-stamped row as current rather than rolled over**. The
opposite reading would wipe usage on ambiguous input, and wiping usage is not a safe default.

That PHP method was read line by line before the TypeScript was written, so the branch matches
rather than approximates. Getting it wrong leaves a monthly counter that either never resets or
resets on every call — and neither is visible until a month boundary.

## Identity — the one thing the caller may not assert

`resolveCaller()` validates the Checkpoint token against `POST /checkpoint/auth/token/verify`
and takes `member_reference_num` from **the result**, ignoring any member reference in the
payload. A function that accepted it as a parameter would re-open, through its own front door,
the hole that closing write permissions exists to shut.

Cached 60s against a **SHA-256 of the token**, never the token itself — an in-memory map
holding live bearer tokens is a credential store nobody decided to build.

Two behaviours worth stating:

- **Checkpoint unreachable ⇒ refuse.** Denying a create is recoverable; a forged consume is
  not. The docblock records that this is the *opposite* of the correct posture on the
  retirement edge (`../handoff.md` §9), where a missing membership row must cause a holder to
  be skipped rather than treated as plan 0 — **and that the two must never share a helper.**
- **A valid token resolving to no member is refused.** When this was written, this check was
  load-bearing: `attachMember()` hard-failed only when `isProduction()`, so a memberless
  identity really could validate outside production. **That changed on 2026-08-31** — Checkpoint
  now refuses a memberless identity on every path in every environment, so this branch is
  unreachable in practice.

  It stays, because it is not the same assertion. Checkpoint's refusal is caught one line
  earlier by the `success` / `valid` check; this one confirms the member actually *arrived* in
  the response. A payload that is shaped right but carries no member — a changed response, a
  partial serialisation, a proxy that strips it — would otherwise flow into `ledgerKey()` as an
  empty reference and pile every member's usage onto one shared row keyed `|resource|member||`.

  Verified live afterwards by minting a token, deleting the member row underneath the still-valid
  JWT, and re-validating: `valid: false`. See `../../00_ISSUES.md` **I8**.

### The service-key exception

Coordinator runs server-side and holds no user token, so it authenticates as itself via
`X-Alloc-Service-Key` and **is** permitted to name the member. That is the single exception to
the rule above, and the reason `ALLOC_SERVICE_KEY` is its own credential rather than one shared
with the Checkpoint system key or the brain ops key — a leak in one surface must not open the
others.

Unset means the service path is closed: the safe default for a deployment that has not been
given a key.

## The audit log

`writeAuditRow()` is best-effort and **never fails the call** — an audit failure must not deny
a member an action they are entitled to.

Moving it server-side is also the only way it means anything. `alloc_v2` currently writes
`CheckpointAlloc_Log` from the browser, which is worth very little: the same caller that can
forge a consume can forge its record.

## Verification

**Checked here:**

- Syntax and local type-check with `tsc --noResolve --skipLibCheck`. The only errors are
  missing ambient types (`process`, `node:crypto`) — expected with no `node_modules`. **No
  syntax errors, and nothing wrong in the new logic.**
- The existing `cloud/functions.ts` contents, the live schema and the live CLPs were read from
  the server rather than assumed.
- `AllocationGateway::increment()` and `periodHasRolledOver()` read before writing.

**Checked against the DEPLOYED functions, 2026-08-31** (they were deployed between writing
and testing):

| Check | Result |
|---|---|
| Both functions exist and route | ✅ reached — the refusal is this file's own message |
| No token | ✅ refused, code 209 |
| Invalid service key, in the header | ✅ refused, code 119 |
| Invalid service key, in the body | ✅ refused, code 119 — both forms are read |
| Service caller naming no member | ✅ refused |
| Token path with `CHECKPOINT_BASE_URL` unset | ✅ refuses with the configuration error, does not fall open |
| **Any write from the above** | ✅ none — 62 rows before, 62 after |

**`ledger_key` parity — the highest-risk item — PASSES against live data.** The function's
algorithm was run over **all 62 live rows** and reproduced every stored `ledger_key` exactly,
including field order (`scope_parent` before `scope_key`) and the `||` that member scope ends
in. A drift guard also asserts the tested copy still matches `functions.ts`, so this stops
being a real test the moment someone edits one without the other.

**Period rollover parity passes across languages.** The seven boundaries that decide a reset —
never-stamped, same month, same day, previous month, previous day, yesterday-within-a-month,
and 00:30 UTC on the 1st — were run through both the TypeScript and
`AllocationGateway::periodHasRolledOver()`. **7 of 7 identical.**

**The write path — 32 of 32 passed** against a local Parse, service-key path:

| Group | Result |
|---|---|
| First consume creates one row, correct `ledger_key`, `state: active` | ✅ |
| Consume to the ceiling; consume past it refuses | ✅ |
| **Counter UNCHANGED after a refusal** — the compensating decrement works | ✅ |
| `limit: -1` counts and never gates | ✅ |
| Release subtracts; clamps at 0; creates no row when there is none | ✅ |
| **P1: 50 concurrent consumes of 1, `limit: 40`** | ✅ **exactly 40** — 1 row, 40 accepted, 10 refused |
| 200 concurrent first-consumes | ✅ 1 row, `used` 200 — *with the unique index; see below* |
| Monthly rollover **SETS** rather than increments, same row, re-stamped | ✅ |
| Function writes its own audit rows (248) | ✅ |
| Wrong service key → 119; no credential → 209 | ✅ |
| Validation: bad `resource_id`, negative amount, unknown period, missing `limit` | ✅ |

**The finding worth carrying forward: the unique index on `ledger_key` is required, not
advisory.** Without it, 200 concurrent first-consumes produced **42 rows and `used` 159** — the
counter split and lost 41 increments. With it: one row, 200. The 50-concurrent test passes
either way, so a smaller run gives false confidence. Written up in plan §3.2.4 and
`step-1-declare-schema.complete.md`.

**Two failures in the first run were mine, not the code's**, and are recorded because both
would mislead someone re-running this:

- `{"error":"unauthorized"}` on every call — the test harness's `.env` parser did not strip
  quotes, so it sent the App ID *with* quote characters. Parse's error says nothing about why.
- "bad service key not refused" — the harness's `consume()` helper did not forward its options,
  so the test passed the *correct* key. Verified directly afterwards: a wrong key gets
  `119 Invalid service key`.

**The token path — 14 of 14 passed.** A real access token was minted with auth's own
`JwtService::createTokenPair()` for a user with a `brain_memberships` row, so Checkpoint
validated it exactly as it would a browser's.

| Check | Result |
|---|---|
| Consume succeeds with a Checkpoint token | ✅ |
| Row is keyed to the **token's** member | ✅ |
| **§3.4.2 — a payload naming a DIFFERENT member is ignored** | ✅ **nothing written for the named member; the token's own counter moved** |
| Token accepted from `Authorization: Bearer` | ✅ |
| Malformed / tampered-signature / empty token | ✅ all refused, 209 |
| No refused call wrote anything | ✅ counter unmoved |
| `allocRelease` on the token path | ✅ |

**§3.4.2 is the one that matters.** A caller sent a valid token for `m1xJppRmdtH2Y` together
with `member_reference_num: m1xTHpRmdtH2Y` in the payload. Nothing was written for the named
member; the token's own counter moved instead. That is the property closing the write
permissions depends on — a function that honoured the payload would re-open the hole through
its own front door.

**The 60s identity cache is real, and keyed per token.** Timed against a running instance:

```
token A, already cached :  93ms      <- its 60s TTL had expired; re-validated
token B, FIRST time     : 233ms      <- pays the Checkpoint round trip
token B, now cached     :  13ms
token A again           :  10ms
```

First use of a token costs a round trip; subsequent uses are ~15× faster. The expiry is
visible too — token A had to re-validate after sitting idle past its TTL.

## Residue

Two environment variables the Parse app needs, or the functions cannot work:

| Variable | Without it |
|---|---|
| `CHECKPOINT_BASE_URL` | `resolveCaller` cannot validate a token; **every call refuses** |
| `ALLOC_SERVICE_KEY` | the service path is closed; coordinator cannot call the functions |
