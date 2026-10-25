# Complete — Step 3: `ParseAllocationGateway` onto the functions

**Date:** 2026-08-31
**Plan:** `03-parse-cloud-functions.md` §3.6, §3.8 step 3
**File:** `coordinator/app/Services/AfsConnect/Allocation/ParseAllocationGateway.php`

Server-side, one app, no bundle to rebuild — which is why the plan puts it before the browser
copies.

## The decision this step and step 4 share

The functions live in the Parse app, which deploys separately from this one. Between "the code
is written" and "the functions are live on the registry host" there is a window in which
calling them fails every allocation.

Three options; a **feature flag defaulting to today's behaviour** was chosen over:

- a **hard cutover**, which breaks allocation until the Parse app ships;
- a **silent fallback** to direct writes, which would make a failed deployment look like a
  working one — precisely the thing you are checking when you first turn it on.

**There is deliberately no automatic fallback.** When the flag is on and the function is
missing, the call fails loudly and denies.

```
ALLOC_USE_CLOUD_FUNCTIONS  →  config('afs_connect.use_cloud_functions')   default false
ALLOC_SERVICE_KEY          →  config('afs_connect.alloc_service_key')     default ''
```

## What changed

`consume()` and `release()` branch to `consumeViaCloudFunction()` / `releaseViaCloudFunction()`
when the flag is on. The direct-write path is untouched beneath them.

`callCloudFunction()` POSTs to `{parse_server_url}/functions/{name}` with the application id
and `X-Alloc-Service-Key`. It returns `null` on any transport or function failure, and every
caller maps that to `degradedOrDeny($resolved)` — so a `block` resource **denies** and a
`notify` resource degrades, which is the fail-safe posture §60.4.4 already required.

### One subtlety worth recording

An `overage: notify` resource is sent with `limit: -1` and its warning computed locally from
the returned value. Sending its real limit would have the function **refuse** a resource whose
entire configuration says "permit and warn". The same reasoning appears in the TypeScript
client, for the same reason.

### Why the service key is separate

This gateway holds no Checkpoint user token, so it authenticates as itself and **is** permitted
to name the member — the one exception to §3.4.2's "the caller may not assert identity" rule.
That is exactly why it is a distinct credential rather than the Checkpoint system key or the
brain ops key: it grants something those do not, so a leak in one surface must not open the
others.

`config/afs_connect.php` documents both new keys inline, including the default-false rationale.

## Tests

Five new cases in `ParseAllocationGatewayTest`, **18 passing** overall:

| Test | Asserts |
|---|---|
| off unless configured | no `/functions/` request when the flag is false |
| calls `allocConsume` when enabled | URL, service-key header, member reference, resource, scope, `limit: 3`, `period` |
| a refusal is a denial | `ok: false` → `limit_exceeded`, `used` carried through |
| **a missing function denies rather than falling back** | 404 → `transport_error`, **and no PUT** |
| enabled without a service key denies | `transport_error`, nothing sent at all |

The fourth is the regression guard for the fallback that was deliberately not built — it
asserts the gateway does not quietly write the row instead.

## Verification

`php -l` clean. `ParseAllocationGatewayTest` 18/18. Full coordinator suite **116 passed**,
confirmed over three consecutive runs.

> An earlier run reported 3–4 failures. That was contention from running three app suites
> back-to-back against the same MySQL, not a regression — three clean consecutive runs
> followed. Recorded because the same flake will reappear for the next person who runs the
> suites in parallel.

## Residue

The flag must stay **off** until the Parse app is deployed. Once it is on everywhere and
verified, the flag and the direct-write path come out together — see
`step-5-close-permissions.complete.md`, which makes the old path fail anyway.
