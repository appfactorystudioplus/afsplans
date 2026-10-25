# alloc_v2 → direct-to-Parse

**Goal:** make `alloc_v2` genuinely **server-independent**. Any client — browser, desktop, mobile, CLI — initialises the Parse SDK against `https://dashboard15-registry.appfactory.studio/parse` and talks to the registry directly. No per-application PHP proxy, no relative URL that only resolves inside one Laravel app.

**Scope:** this is a **standalone effort**, deliberately separate from the afs-connect work in [`../afs-connect/`](../afs-connect/). afs-connect's step 60 consumes allocation behind an `AllocationGateway` seam ([11-billing-and-allocation.md](../afs-connect/11-billing-and-allocation.md) §11.4) precisely so that this refactor can land independently, before or after, without touching a single afs-connect call site.

| # | Document | Contents |
|---|---|---|
| 1 | [01-current-state-audit.md](01-current-state-audit.md) | What was deployed when the audit was taken. **Largely historical now** — see its banner |
| 2 | [02-client-refactor.md](02-client-refactor.md) | `CheckpointAllocV2` on the Parse SDK. Transport shipped; the mutation half has not |
| 3 | [03-parse-cloud-functions.md](03-parse-cloud-functions.md) | **Rewritten 2026-08-31.** The server half: closing write permissions on `MemberResource`, two functions performing a conditional increment, and the CORS allowlist (§3.7 — an Nginx change on the registry host, *not* a Parse one) |
| 4 | [04-distribution-and-parity.md](04-distribution-and-parity.md) | Keeping the client identical across all nine `resources/js/checkpoint` copies |

---

> ### ⚠ Status — verified against the codebase and the live Parse schema, 2026-08-31
>
> | | State |
> |---|---|
> | Doc 02 — transport | ✅ **shipped.** `managerUrl` gone from all nine copies; clients talk to Parse directly |
> | Doc 02 — mutations | ❌ `consume`/`release` still write `MemberResource` from the browser |
> | Doc 03 — counter class | ✅ **declared** as `MemberResource` (not `MemberRegistryCounter`), three indexes, verified created on boot. ⚠️ the **unique** index on `ledger_key` must be created separately — parse-server's schema cannot express it, and without it a counter splits under load (§3.2.4) |
> | Doc 03 — cloud functions | ✅ **written and tested** — `allocConsume` / `allocRelease`, **32/32** checks against a running Parse including the 50-concurrent P1 test. Deployed to the registry host but **not configured there** (`CHECKPOINT_BASE_URL`, `ALLOC_SERVICE_KEY`) |
> | Doc 03 — permissions | ❌ **wide open.** `MemberResource` is public for `find/get/count/create/update/delete/addField`. The closed CLP is written and gated — §3.8 step 5 |
> | Doc 03 — CORS | ❌ not applied (Nginx, registry host) |
> | Registry distribution | ✅ shipped — nothing bundles the JSON; all eight factories fetch it at runtime, all on v4 |
>
> **The data-integrity hole doc 03's preamble warned about is the current state, and it got
> sharper.** Every client writes usage counters directly against a public class — so anyone
> holding the application id, which ships in every bundle, can rewrite *or delete* any
> member's usage. There is no longer a second class to spread that across: `MemberResource`
> holds everything.
>
> **`MemberRegistry` was deleted on 2026-08-31** — class, objects, and the
> `brain_memberships.parse_alloc` column. It held one field every ledger row already carried.
> Doc 03 §3.9 records what that changed for this plan; the removal itself is written up in
> [`../00_ISSUES.md`](../00_ISSUES.md) **I7**.
>
> The corrected sequence is [03](03-parse-cloud-functions.md) §3.8, and
> [`complete/`](complete/README.md) records what happened to each step. Steps 0–4, 6 are done;
> 5 is gated on deployment and 7 stopped on evidence.

---

## The problem in one paragraph

`alloc_v2.ts` shipped a `managerUrl` config field pointing at an `AllocationV2Manager.php` proxy on the *calling application's own server*. Three things followed. **A client that is not a Laravel app could not use it at all**: a desktop editor or a mobile app has no same-origin PHP endpoint to call. **The proxy did not exist**: `AllocationV2Manager.php` is referenced by name in docblocks and implemented nowhere. And **the chain was three hops** (app server → auth → Parse) for what is a single object read.

That half is done. `managerUrl` is gone from all nine copies.

## The decision

The client initialises Parse directly:

```ts
const alloc = new CheckpointAllocV2({
    applicationId:      import.meta.env.VITE_CHECKPOINT_APP_ID,
    serverURL:          import.meta.env.VITE_CHECKPOINT_SERVER_URL,
    memberReferenceNum: app.window.member.reference_num,
    tier:               Number(app.window.member.membership ?? 0),
    registryUrl:        import.meta.env.VITE_CHECKPOINT_REGISTRY_URL,
    getToken:           () => localStorage.getItem('checkpoint_access_token'),
});
```

**Every public method keeps its exact name, parameters, return type and error type** — `getAll`, `get`, `canUse`, `consume`, `release`, `getRemaining`, `getLimit`, `getUsage`, `isUnlimited`, `invalidateCache`, and `AllocV2Error`. Only the transport underneath changes, so existing call sites compile untouched.

> `parseObjectId` was removed from this config on 2026-08-31 along with the `MemberRegistry`
> anchor it addressed. Identity is `memberReferenceNum`, which is what every ledger row is
> stored under.

## The one thing that must not be missed

Going direct means the network path no longer passes through a server that can be trusted to decide *how much* a member may consume. Two properties held only because a PHP proxy stood in the way:

1. **`MemberResource` is publicly writable.** With a direct client and the application id, any user can rewrite or delete any member's usage counters.
2. **`tier` is supplied by the caller.** A direct client can simply claim tier 4.

So this refactor is **not** "point the SDK at Parse and delete the proxy". Doc [03](03-parse-cloud-functions.md) is the other half, and it is not optional — shipping doc [02](02-client-refactor.md) alone converted a missing feature into a data-integrity hole, which is where the estate is now.

**What moves into Parse, and what does not.** An earlier draft of doc 03 moved the whole decision there — registry, tier lookup, limit resolution. That was rejected. `alloc_v2` keeps the policy: it reads the registry, resolves the tier, decides whether the member may create one more, and owns the limit dialog. **Only the write moves**, because comparing and incrementing in a single operation is the one step a client cannot perform — the SDK can read and it can write, but it has no *"write only if"*.

Point 2 above therefore stands unfixed and is an explicit, recorded limitation rather than something doc 03 closes: `tier` stays caller-supplied and allocation stays advisory ([../allocation.md](../allocation.md) §7). Anything that must not be forgeable calls [../billing/09-entitlement-integration.md](../billing/09-entitlement-integration.md) §9.3 instead. [03](03-parse-cloud-functions.md) §3.4.3 states the boundary and the one-change upgrade path if it is ever wanted.

**A third limitation is new**, and is a consequence of removing the anchor: **reads are open too**. Clients now read `MemberResource` directly, so §3.3 can close writes but not reads without a read-side function and a nine-copy client change. [03](03-parse-cloud-functions.md) §3.3.2 records it as its own pass.

## Order of work

The authoritative version, with the reasoning, is [03](03-parse-cloud-functions.md) §3.8.

1. **Put service auth on `POST /checkpoint/member/init`** — no middleware today, and it provisions ledger rows with the master key.
2. **[03] Declare `MemberResource`** in `config.ts`, with a unique index on `ledger_key`. Note `cloud/schema.ts` is imported by nothing — editing it deploys nothing.
3. **[03] Build** `allocConsume` / `allocRelease`. Risk-free: nothing calls them yet.
4. **Move coordinator's `ParseAllocationGateway.php`** onto the functions — server-side, one app, no bundle to rebuild.
5. **[02] Move `alloc_v2`'s `consume`/`release`** onto the functions, then **[04] propagate** byte-identically to the other eight copies.
6. **Close the write permissions.** The gate. Everything above must be deployed everywhere first.
7. Retire `AllocationV2Controller`'s `/consume` and `/release` routes.
8. Drop the dead `Registry` class — the pre-v2 scheme, whose last consumers were deleted on 2026-08-31.

Steps 1–3 are independent of everything else and can start immediately. Step 6 is the only one with estate-wide blast radius.

> **Deployment is the real constraint.** Steps 2, 3 and 6 change files under
> `w/auth/public/checkpoint/parse/`, which take effect only when that app is deployed to the
> registry host. This checkout cannot deploy it — see [03](03-parse-cloud-functions.md)
> §3.8.1, and note the registry file itself is already in that state (correct here, 404 on
> the deployed host).
