# Complete — Step 5: close the write permissions — PREPARED, NOT APPLIED

**Date:** 2026-08-31
**Plan:** `03-parse-cloud-functions.md` §3.3, §3.8 step 5
**File:** `w/auth/public/checkpoint/parse/config.ts`

> ⛔ **This step is deliberately not done.** It is the gate. Applying it before the functions
> are deployed and every caller is on them breaks allocation estate-wide.

## The problem it closes

Read from the live server, 2026-08-31:

```
MemberResource        public: ACL, addField, count, create, delete, find, get, update
CheckpointAlloc_Log   public: ACL, count, create, delete, find, get, update
```

`MemberResource` is world-writable **and world-deletable**, and since the `MemberRegistry`
anchor was removed it is the *only* class holding allocation data. Anyone with the application
id — which ships in every browser bundle — can zero a member's usage or delete the rows.

That is P2 from §3.1, and removing the anchor made it sharper rather than softer: there is no
longer a second class to spread the exposure across.

## What is prepared

Three named constants in `config.ts`. The classes currently reference `CLP_OPEN_TODAY`, which
reproduces the inherited defaults verbatim — so declaring the classes (step 1) changes nothing
by itself.

**Step 5 is a one-word change at two call sites**, and the file says so at both:

```ts
classLevelPermissions: CLP_OPEN_TODAY,   // → CLP_WRITES_CLOSED   (MemberResource)
classLevelPermissions: CLP_OPEN_TODAY,   // → CLP_LOG_CLOSED      (CheckpointAlloc_Log)
```

## What gets closed, and what does not

```
create, update, delete, addField   →  closed
find, count, get                   →  LEFT OPEN
```

Cloud Functions use the master key and are unaffected by CLPs, so closing writes costs the
legitimate path nothing once every caller is on them.

**Reads stay open because there is nowhere else to read from.** The plan's original version
could close the counter class completely, because `alloc_v2` read usage from a per-member
object instead — *that object no longer exists*. `getAll()`, `get()` and `canUse()` query these
rows directly, so closing `find` would break every read in the estate.

### The exposure that leaves — recorded, not glossed

With `find` open, anyone holding the application id can enumerate `MemberResource` and read
**any** member's usage. That is true today; this step neither worsens nor fixes it.

Closing it properly needs:

1. an `allocStatus` function returning the caller's own rows, resolved from the token;
2. `getAll()` / `get()` / `canUse()` rewritten onto it in nine copies;
3. the Brain editor unaffected — it already goes through www's master-key API.

That is a bigger change than this document and should not be smuggled into it. **It is open.**

## Preconditions, in order

1. Parse app deployed with `allocConsume` / `allocRelease`.
2. `ALLOC_USE_CLOUD_FUNCTIONS` and `VITE_ALLOC_USE_CLOUD_FUNCTIONS` on in **every**
   environment, and every app rebuilt — the clients serve built assets, so an un-rebuilt app is
   still writing rows directly.
3. Verified against §3.10 — especially the 50-concurrent-consume test.
4. *Then* swap the two constants and redeploy.

## The trap to avoid when verifying

> **parse-server merges schema changes rather than replacing them.** A permissive CLP already
> in Mongo can survive a redeploy and leave you believing a lock applied when it did not.

**Verify by trying, not by reading the config.** A client-side `create` on `MemberResource`
must fail. If it succeeds, the merge kept the old permissions and the gate is not actually
shut — while every file in the repo says it is.

## Residue

The master-key callers — `AllocationV2Controller`, `AllocationGateway`, `MemberInitController`,
`BrainAllocationController`, `AllocReconcilePayments` — bypass CLPs entirely and keep working
after this lands. Whether they *should* also route through the functions is a consistency
question, not a correctness one, and is out of scope here.
