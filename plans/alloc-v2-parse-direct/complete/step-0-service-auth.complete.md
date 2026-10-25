# Complete — Step 0: service auth on `POST /checkpoint/member/init`

**Date:** 2026-08-31
**Plan:** `03-parse-cloud-functions.md` §3.8 step 0

## The problem

`auth/routes/checkpoint.php` put the route in a bare `Route::prefix('member')` group with
**no middleware at all**, and `MemberInitController::init()` provisions Parse rows with the
**master key**. Its own docblock said *"called server-to-server — never from the browser"*.
Nothing enforced that: anyone who knew the URL could provision ledger rows in a loop, against
any member reference they cared to name.

## What was built

`auth/app/Http/Middleware/VerifyCheckpointSystemKey.php`, aliased `checkpoint.system`, applied
to the `member` route group.

It checks `config('checkpoint.system_key')` against **either** `X-Checkpoint-System-Key` or
`X-Appfactory-System-Secret` — because the existing caller sends both. www's
`MemberInitController::curlRequest()` has always set the two to the same value, and auth's own
`AllocationV2Controller::authorizeRequest()` already accepted either. Accepting one and not
the other would have rejected a caller doing nothing wrong.

### Two deliberate choices

**A separate middleware, not `VerifyCheckpointOpsKey`.** That one reads
`checkpoint.brain_ops_key` from `X-Checkpoint-Ops-Key` and guards the Brain admin proxies.
This reads a different key for a different trust relationship. Sharing one key across both
would mean a leak in either surface opens the other.

**Fails closed on an unconfigured key.** An empty `checkpoint.system_key` denies every request
rather than admitting every request. The opposite choice turns a missing `.env` line into an
open door, silently.

## Checked before applying

The middleware would have broken signup if the two apps disagreed about the key, so that was
verified first rather than assumed:

```
auth/.env  CHECKPOINT_SYSTEM_KEY  ==  www/.env  CHECKPOINT_SYSTEM_KEY   → MATCH
```

## Verification

Against the live local host, all four cases:

| Request | Result |
|---|---|
| No key | **401** |
| Wrong key | **401** |
| `X-Checkpoint-System-Key: <correct>` | **201**, 14 rows created |
| `X-Appfactory-System-Secret: <correct>` | **200**, `rows_existing: 14` — idempotent |

The test member's 14 rows were deleted afterwards.

## Residue

**S18 is now closed for `member/init`.** The endpoint is no longer an unauthenticated
master-key write path.

`POST /checkpoint/alloc/v2/check` in the same file still uses
`AllocationV2Controller::authorizeRequest()`, which compares against a **hardcoded** string
(`'members_master_key_exchange_secret'`) rather than config. That is a separate finding, out
of scope here, and worth closing: a credential in source is not rotatable.
