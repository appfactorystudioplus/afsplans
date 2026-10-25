# Complete — Step 4: `alloc_v2.ts` `consume` / `release` onto the functions

**Date:** 2026-08-31
**Plan:** `03-parse-cloud-functions.md` §3.5, §3.8 step 4
**Files:** `alloc_v2.ts` × 9 apps, plus 8 factories

## What changed

`consume()` and `release()` branch to `consumeViaCloudFunction()` / `releaseViaCloudFunction()`
on `config.useCloudFunctions`. The direct-write path — pre-check, `applyDelta`, post-check,
compensating rollback — is untouched beneath them.

**Every public method keeps its exact name, parameters, return type and error type**, so call
sites compile untouched. That is the same contract doc 02 held to, and it is why nine apps
needed no changes outside the synced file and their factory.

A refusal from the function raises the same `AllocV2Error` with `ALLOC_V2_LIMIT_EXCEEDED` the
direct path raises, so no UI anywhere needs to learn a new failure mode.

## What is deliberately not sent: the member reference

The function takes it from the validated Checkpoint token. A client that could name the member
would re-open the hole that closing the write permissions exists to shut (§3.4.2).

The client sends `resource_id`, the three scope fields, `amount`, `limit`, `period` and the
token — resolved policy and nothing else.

## The flag

```
VITE_ALLOC_USE_CLOUD_FUNCTIONS  →  AllocV2Config.useCloudFunctions   default false
```

Default false, no automatic fallback, for the reasons set out in
`step-3-coordinator-gateway.complete.md`. The config docblock states plainly what turning it on
buys, so the next reader does not have to reconstruct it:

> `increment()` cannot be conditional, so the pre-check and the write after it are two round
> trips with a gap. Two tabs can both pass the check and both write. The function compares and
> increments inside one call. Until this is on, the compensating rollback is the mitigation —
> it converges the counter but does not prevent the transient overshoot.

All eight factories pass the flag through (`www` keeps its factory at
`resources/js/registry.ts`; the other seven use `checkpointHelper/static/registry.ts`).

## The `notify` case

An `overage: notify` resource is sent as `limit: -1` and its warning computed locally from the
returned value — sending its real limit would have the function refuse a resource configured to
permit and warn. Identical reasoning to the PHP gateway, so the two cannot drift on it.

## Verification

- All nine `alloc_v2.ts` copies remain **byte-identical** (single md5).
- `tsc --noEmit` clean on `www`.
- The other eight apps' error counts unchanged from baseline — auth 1, coordinator 1,
  dream_graph 49, flow 13, market 3, members 1, notes 0, pdf_editor 0. All pre-existing and
  unrelated (a tsconfig option, missing `@types`, and a React node-types mismatch in a
  different `registry.ts`).

**Not verified here:** the functions actually being called. That needs the Parse app deployed
and the flag flipped — the deployment test.

## Residue

1. **Rebuild required.** These apps serve built assets; the source change does nothing until
   each is rebuilt. Only `www` was rebuilt during this session's earlier work.
2. **The clients still write `CheckpointAlloc_Log` themselves** via `writeAuditRow()`. The
   function writes its own entry, so once the flag is on everywhere that client-side write is a
   duplicate and should be removed in the same change that removes the flag.
3. **`canUse()` is unchanged and stays advisory.** It gates the UI; `allocConsume` is the
   authority. A `canUse` that passes and a `consume` that then refuses is **correct** —
   someone else consumed in between — and the UI must be able to show that.
