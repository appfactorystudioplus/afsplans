# Complete — Step 6: retire `/consume` and `/release`

**Date:** 2026-08-31
**Plan:** `03-parse-cloud-functions.md` §3.8 step 6
**Files:** `auth/routes/checkpoint.php`, `www/routes/portal.php`

## What was removed

```
POST /checkpoint/alloc/v2/consume          (auth)
POST /checkpoint/alloc/v2/release          (auth)
POST /portal/api/v1/alloc/v2/consume       (www proxy)
POST /portal/api/v1/alloc/v2/release       (www proxy)
```

## Two reasons, and the second is the one that mattered

**They were orphans.** Every client bypassed this surface when `managerUrl` was dropped from
`alloc_v2.ts` in favour of talking to Parse directly. A sweep of the estate finds no caller for
*any* route in the group — the www proxy had nothing calling it, and nothing calling the proxy.

**They were the wrong shape to keep.** Both took `tier` as a caller-supplied request field and
performed no lookup, so anyone who could reach them could claim tier 4 — and they wrote usage
counters on that basis. The safe write is now `allocConsume` / `allocRelease`, which resolve
the member from a validated Checkpoint token rather than believing the payload (§3.4.2).

Removing a write path that trusted its caller is worth doing on its own, independently of
whether anything called it.

## What was deliberately left

The **read** routes stay:

```
GET  /checkpoint/alloc/v2
GET  /checkpoint/alloc/v2/{resourceId}
POST /checkpoint/alloc/v2/check          (read-only preflight)
```

Removing a published read surface is a different decision from removing a write path that
trusted its caller, and nothing forces it today. Both route files carry a comment saying so, so
the asymmetry does not read as an oversight.

## Verification

`php artisan route:list` after the change:

```
GET|HEAD  checkpoint/alloc/v2                 → AllocationV2Controller@index
POST      checkpoint/alloc/v2/check           → AllocationV2Controller@check
GET|HEAD  checkpoint/alloc/v2/{resourceId}    → AllocationV2Controller@show
```

The two write routes are gone; the three read routes remain. `php -l` clean on both files.
auth's 21 tests still pass.

## Residue

`AllocationV2Controller` still **has** `consume()` and `release()` methods — only the routes
were removed. They are unreachable over HTTP but remain in the class, which is the reversible
form of this change: restoring the routes is one line each if something turns out to have
needed them.

Deleting the methods, and deciding the fate of the remaining read surface, belongs to whoever
decides whether `/checkpoint/alloc/v2` survives at all. Nothing calls any of it today.

`POST /checkpoint/alloc/v2/check` still authorises via
`AllocationV2Controller::authorizeRequest()`, which compares against a **hardcoded** string
(`'members_master_key_exchange_secret'`) rather than config — noted in
`step-0-service-auth.complete.md` as a separate finding. A credential in source is not
rotatable.
