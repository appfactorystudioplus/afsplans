# 06 — Known Issues (read before trusting this in production)

> ### ⚠ Status — 2026-08-31
>
> | Issue | State |
> |---|---|
> | §1 the race condition | ⚠️ **half closed.** The *lost update* is fixed by the row model; the *ceiling* race is not, and needs the cloud function ([03](03-parse-cloud-functions.md) §3.1.1) |
> | §2 silent fallback to non-atomic writes | ✅ **resolved.** The `ac_*` fallback and its `isUnknownFieldError` guard are deleted; there is one number and it is incrementable |
> | §3 `.env.prod` Application ID mismatch | ⚠️ unverified in this pass |
> | §4 advisory-only enforcement | ✅ still true, still deliberate — [03](03-parse-cloud-functions.md) §3.4.3 |
> | §7 directory sync is a partial file list | ✅ still true |
>
> **One issue to add:** reads on `MemberResource` are open to any holder of the application
> id, and closing them needs a read-side function plus a nine-copy client change.
> [03](03-parse-cloud-functions.md) §3.3.2.

Everything the client-side implementation (§5.11 of
[05-findings-and-execution-plan.md](05-findings-and-execution-plan.md)) does
NOT resolve, plus the one deliberate design compromise. Nothing below is
theoretical — each item was found by reading the actual code or the actual
Parse/parse-server behaviour, not inferred.

**Nothing in this refactor has run against a live Parse server.** Every item
below is a risk to verify, not a confirmed bug — but none has been ruled out
either.

> ## ✅ Updated 2026-08-27 — §1 and §2 now have an answer
>
> This document was written under the instruction that server-side code was out
> of scope. **That instruction has been reversed.** §1's own conclusion —
> *"it needs a server-side atomic check-and-increment, which requires a Cloud
> Function"* — is now the plan, and [03](03-parse-cloud-functions.md) is that
> plan, scoped to the conditional write and nothing else.
>
> §1 and §2 are **the reason** for it, and they read as the strongest argument in
> this folder. They are left in place, with the resolution marked inline. Every
> other item stands unchanged.

---

## 1. The race condition — what is and isn't actually fixed

**The bug this exists to prevent:** two browser tabs both call `consume()` at
the same moment. Naive code reads `used: 5` in both, computes `6` in both,
saves `6` in both — two consumptions recorded as one, silently.

**What was built:** each counter got a top-level mirror field
(`ac_m__<resourceId>`) written with Parse's `increment()`, which parse-server
translates to a MongoDB `$inc` — a genuine atomic operation, not a
read-modify-write. This closes the exact bug above, *for the counter itself*.

**What is NOT fixed — two real gaps:**

1. **The limit check is not atomic with the increment.** `increment()` has no
   conditional form ("increment only if the result would stay ≤ N" does not
   exist in Parse). So `consume()` does: read usage → check if it would exceed
   the limit → increment → re-read → if it turns out the increment pushed past
   the limit, issue a compensating decrement and throw. Two clients racing
   *exactly* at the boundary can both pass the pre-check and both increment —
   the counter briefly sits above the cap until the loser's rollback lands.
   The counter converges to a correct value, but there is a real (if narrow)
   window where the stored usage exceeds the limit. **A cap that must never be
   exceeded even momentarily — e.g. a hard billing ceiling with real financial
   consequences — is not safe on this design.** It needs a server-side atomic
   check-and-increment, which requires a Cloud Function (explicitly out of
   scope per the user's instruction) or a different Parse primitive.

   > **Resolved in design; not yet built.** That instruction is reversed and
   > [03](03-parse-cloud-functions.md) §3.4.1 is the fix. Note what does and does
   > not change: the analysis above describes **increment-then-verify-and-roll-back**,
   > which is exactly option (b) that 03 recommends — so the transient overshoot
   > described here is **not** eliminated, it is moved somewhere a client cannot
   > skip it. 03 §3.4.1's option (a) (a conditional `findOneAndUpdate` through the
   > Mongo driver) is the only variant with no window at all, and it was **not**
   > chosen: member allocation is human-paced, and a raw-driver dependency inside
   > cloud code costs more than the window is worth.
   >
   > So this paragraph's warning survives the fix and should be read as a live
   > constraint: **a cap that must never be exceeded even momentarily still needs
   > option (a).** If a hard financial ceiling ever lands on this path, revisit
   > 03 §3.4.1 rather than assuming a cloud function settled it.

2. **This has never been tested under actual concurrency.** No test has fired
   N simultaneous `consume()` calls against a real Parse server and checked
   the resulting counter. The `increment()` atomicity claim is based on
   reading parse-server's source (`MongoTransform.js` maps `Increment` → `$inc`)
   and the Mongo `$inc` operator's documented guarantee — not on an observed
   result. **This is the single highest-priority thing to verify before
   relying on any of this.**

**Also unverified: whether atomicity is even active.** See item 2 below — if
the server rejects the new field, the code falls back to the exact
read-modify-write pattern this section describes fixing, silently.

---

## 2. Silent fallback to non-atomic writes

`applyDelta()` catches a specific failure shape (`isUnknownFieldError`) and,
on catching it, does this:

```
object.unset(field);              // drop the atomic counter field
this.writeNestedProjection(...);  // fall back to plain read-modify-write
await object.save();
```

This exists so a locked/strict Parse schema doesn't hard-fail every consume
call. But it means: **if `MemberRegistry`'s schema rejects unrecognized
top-level fields, every write silently reverts to the exact race condition
§1 describes, with no error, no log, no signal anywhere.** The application
would appear to work correctly in every manual test (races are rare and hard
to notice without deliberately inducing them) while quietly having zero
atomicity guarantee.

**Not verified:** whether `MemberRegistry`'s current schema accepts arbitrary
new top-level fields or rejects them. This determines whether the atomicity
work in §1 does anything at all in production.

> **Strong evidence it rejects them, and the fix removes the question.**
> `parse/config.ts` sets `lockSchemas: true` and `strict: true`. Adding an
> unrecognised top-level column is a schema modification, and that is what
> `lockSchemas` refuses — so the fallback above is very probably not an edge case
> but **the only path currently taken**. It is still one save against staging to
> confirm, and worth doing, because it tells you whether today's counters have had
> any atomicity at all.
>
> Either way it stops mattering: [03](03-parse-cloud-functions.md) §3.2.2 moves the
> counter to `MemberRegistryCounter`, whose four columns are declared up front.
> Adding a domain adds **rows**, never columns, so there is no new field for a
> locked schema to reject and no fallback to engage. The silent-degradation
> mechanism is removed rather than worked around — which is the point of choosing
> the counter class over unlocking the schema.

---

## 3. `.env.prod` Application ID mismatch (unresolved by design — out of scope)

`apps/auth/public/checkpoint/parse/.env.prod` has:

```
APP_ID="1PM4yMxbQ0ihyNObm4uQAsG4Zkpl7ZLH5lyquDMT22"
```

Every other file in every app — 30+ locations across `.env`, `_env/*/`,
`registry.ts` factories, sample tooling — has:

```
1PM4yMxbQ0ihyNObm4uQAsG4Zkpl7ZLH5lyquDMT
```

(no trailing `22`). `parse/build.sh --production` copies `.env.prod` over
`.env`, so a production deploy would start parse-server with the `…DMT22` id
while every client in the ecosystem sends `…DMT`. **First production request
from any app would fail with an invalid-Application-ID error.**

This is explicitly not something I'm fixing — the user's instruction was that
the Parse server directory is out of scope entirely. Flagging it here because
it will block production regardless of how correct the client code is.

---

## 4. Advisory-only enforcement — tier and limits are self-reported

Because there is no server-side code in this design (by the user's explicit
scope decision), `tier` is supplied by the calling client, exactly as it was
under the old PHP proxy (`AllocationV2Controller` took `tier` as a raw request
field with no lookup). A client that lies about its own tier gets whatever
limits it claims.

**This is not a regression** — the system this replaces had the identical
property — but it means: **allocation as built is a UX/quota-management
mechanism, not a security boundary.** Anything where "member X actually paid
for tier 3" must be true for correctness or billing (not just displayed)
needs an independent, server-side check. Nothing here provides one.

---

## 5. Auto-provisioning dropped, unverified whether anything depended on it

The PHP controller's `getUsageFromMember()` has a side effect nowhere
documented in the plan: if it's asked about a domain/project resource that
doesn't exist in the stored object yet, it creates one from a template before
answering. The TypeScript client does not reproduce this — a scope that
doesn't exist gets no nested projection (though the atomic counter still
records usage under its own field).

**Not verified:** whether any current caller relies on this auto-creation
behavior. If one does, that caller will see different results (empty/absent
projections instead of an auto-created one) after this ships.

---

## 6. New refusal mode: `ALLOC_V2_TIER_DISABLED`

The old PHP path only ever checked `.value` (the numeric limit) and never
`.enabled`. The new client checks both, and throws a new error code when a
tier's `enabled` flag is `false` for a resource. This is more correct, but
it's also **new behavior**: any tier-0 resource marked `enabled: false` in the
registry that a caller was previously able to use (because nothing checked
the flag) will now be refused.

**Not verified:** which tier-0 resources are currently `enabled: false`, and
whether any live caller exercises one. This needs an audit of the 29
resources against real tier-0 usage before shipping, or it will look like a
new, unexplained outage for some free-tier feature.

---

## 7. Directory sync is a partial, explicit file list — not a general solution

> **Updated:** the file list is now **3** files — `checkpoint_registry_v3.json` was
> removed from it, and the script's parity check inverted: every consumer resolves
> `checkpoint_registry_v4.json` at runtime from auth, so **finding a bundled registry
> anywhere is now the failure** ([03](03-parse-cloud-functions.md) §3.8.1).
>
> Two further corrections to this section, both live: the script's `TARGETS` list was
> stale and its `APPS_ROOT` resolved one directory short, so **it aborted before doing
> any work and reported nothing** — very likely why anything drifted at all. Both are
> fixed.
>
> `--check` then surfaced an *inverted* drift in `app-names.ts` — **five distinct
> versions**, with `www` (the source) holding the oldest. **Fixed 2026-08-27**: www now
> carries the canonical 83-line version, so a write pass raises the targets instead of
> stripping them. Two findings it exposed are **not** fixed and are recorded in
> [04](04-distribution-and-parity.md) §4.1: `MARKET` is absent from
> `CheckpointAppName.php` while market signs in with it, and auth's copy grants
> `appfactory: APPFACTORY_STUDIO` in the map a security docblock says must stay empty.
>
> **`--check` is still not CI-ready**: it flags CRLF (coordinator's `alloc_v2.ts`) and
> trailing whitespace (`resources.ts`, all eight) as drift. Normalise both in the
> comparison first — see [04](04-distribution-and-parity.md) §4.1.2.

`afsserver/bin/sync-checkpoint-client.sh` intentionally syncs only 4 files
(`alloc_v2.ts`, `resources.ts`, `app-names.ts`, `checkpoint_registry_v3.json`),
not the whole `checkpoint/` directory, because a whole-directory sync from
www would have deleted newer authentication code
(`setAuthSession()`, `getCurrentMember()`) that six other apps have and www
does not. The remaining drifted files (`alloc.ts`, `auth.ts`,
`client-handler.ts`, `index.ts`, `secondary-users.ts`) are **still drifted
across apps** and this work does not reconcile them. Anyone who later widens
the sync-script file list to "just sync everything" will reproduce the same
data-loss risk this script was written to avoid.

---

## Priority order to close these before any production use

0. **Put service auth on `POST /checkpoint/member/init`.** Not in this list
   originally because it is not a client-side issue — but it is an
   unauthenticated route that creates Parse objects with the **master key**, and
   it is one line to close ([03](03-parse-cloud-functions.md) §3.8 step 0).
1. **§1/§2 together** — **now a build, not an investigation.**
   [03](03-parse-cloud-functions.md) §3.8 steps 1–5: declare the counter class,
   build the two functions, move both callers onto them, then close the class
   permissions. The acceptance test is unchanged and is still the one that
   matters: 50 parallel `consume(…, 1)` against a limit of 40 must land on
   **exactly 40**, with 10 refused.
   *Optional but cheap first:* confirm the `ac_*` field really is rejected today
   (§2), because that tells you whether the counters have ever been atomic.
2. **§3** — resolve or route around the `.env.prod` app-id mismatch; it blocks
   production regardless of anything else.
3. **§6** — audit tier-0 `enabled: false` resources against real usage before
   `ALLOC_V2_TIER_DISABLED` starts refusing calls that work today.
4. **§5** — confirm nothing depends on auto-provisioning.
5. **§4** — decide whether any consumer of this needs a real security
   boundary, and if so, design one (this will require server-side code,
   which was explicitly deferred here).
   **Partly addressed, deliberately incompletely:** closing the class permissions
   stops anyone writing counters directly, but `limit` still arrives from the
   client, so allocation stays advisory. [03](03-parse-cloud-functions.md) §3.4.3
   states the boundary; the server-derived answer for anything that must not be
   forgeable is [../billing/09](../billing/09-entitlement-integration.md) §9.3.
6. **§7** — reconcile the remaining drifted files, or explicitly accept the
   drift and document why. **`app-names.ts` is the live one** and it blocks
   running the sync script in write mode at all ([04](04-distribution-and-parity.md) §4.1).
7. **Wire `--check` into CI.** Nothing runs it in any pipeline, which is why
   both §7's drift and the sync script's own breakage went unreported.
