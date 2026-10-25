# 02 — Architecture

> **STUB, FOR AUDIT.** Rewritten around `checkpoint_registry_v4.json`.

## 2.1 The shape

```
        brain_memberships.membership (0-4)  +  account_status (1|2|3)
                                 │
                                 ▼
                  ┌──────────────────────────────┐
                  │  checkpoint_registry_v4.json │  ← declares the POLICY
                  │  ──────────────────────────  │
                  │  tiers[] · tier_mode          │
                  │  retention · grace_days       │
                  │  unit_kind · inventory        │
                  │  conventions.scope_graph      │
                  └──────────────┬───────────────┘
                                 ▼
                  ┌──────────────────────────────┐
                  │   Retention engine (shared)   │  ← one implementation
                  │  ──────────────────────────  │
                  │  plan → owned bands         │
                  │  state machine + grace clock  │
                  │  CASCADE across scope_graph   │
                  │  preview() · move()           │
                  │  event log                    │
                  └──────────────┬───────────────┘
                                 │ "enumerate items" / "retire this one"
                                 ▼
                  ┌──────────────────────────────┐
                  │  Inventory adapter per        │  ← the ONLY per-resource code
                  │  resource (registry.inventory)│
                  │  list() · assign() · retire() │
                  │  restore() · purge()          │
                  └──────────────────────────────┘
```

The engine never contains a resource name. It reads the registry, walks the scope graph,
and calls adapters. Adding a resource is a registry entry plus an adapter — never an edit
to the engine.

> **The registry box above is one file — but it is not one file today.** An audit found
> twelve copies, nine of them compiled into shipped JS bundles, so "the policy" is currently
> a snapshot frozen at each application's last build. Resolving that is a **prerequisite**
> for this architecture and for §2.9 in particular:
> [06-registry-distribution.md](06-registry-distribution.md).

## 2.2 What is shared, precisely

Written once; must never differ between resources:

1. **Ownership** — plan `P` owns bands `0..P`. Clamp 0–4.
2. **Allowance** — computed from `tier_mode` (§2.5).
3. **The state machine** and its transitions (§2.3).
4. **The grace clock** — when `purge_after` is set, cleared, and **frozen** (§2.4).
5. **The cascade** (§2.6).
6. **`preview(toPlan)`** — so every surface shows the same number.
7. **The move contract** — partial success reported per item, never a silent subset.
8. **The event log** (D9).
9. **Detection** — sweep authoritative, hooks fast (§2.7).

## 2.3 The state machine

```
                    plan drops below band
   ┌────────┐ ──────────────────────────────▶ ┌─────────┐
   │ active │                                  │ retired │
   └────────┘ ◀────────────────────────────── └─────────┘
       ▲  │         plan restored                   │
       │  │                                         │ grace_days elapse
       │  │ account_status = 2                      ▼
       │  ▼                                   ┌─────────┐
   ┌───────────┐                              │ purging │
   │ suspended │                              └─────────┘
   └───────────┘                                    │ hold expires
       │  account_status = 1                        ▼
       └──────────────────────────────────────▶  (gone)

   Restore edges: retired → active AND purging → active are both valid at any
   point before hard deletion.
```

| State | Read | Write | Evacuate | Counts to allowance | Purge clock |
|---|---|---|---|---|---|
| `active` | ✅ | ✅ | ✅ | ✅ | — |
| `retired` | ✅ | ❌ | ✅ | ❌ | running |
| `purging` | ❌ | ❌ | ❌ | ❌ | expired |
| `suspended` | ✅ | ❌ | ❌ | ❌ | **frozen** |

## 2.4 Suspension is a second axis

```
  effective_state(band n) =
      account_status == 2   -> suspended        (every band, plan irrelevant)
      n <= plan             -> active
      otherwise             -> retired | purging  (by the clock)
```

The plan says **which bands you own**. The status says **whether you may use the ones you
own**.

**Frozen means the remaining duration is preserved.** A holder 20 days into a 30-day grace
who is then suspended still has 10 days when it lifts. Recomputing from the unsuspend date
would extend it; letting it run would delete data while they were locked out and unable to
act. Neither is acceptable.

## 2.5 `tier_mode` — the allowance formula

```
  absolute    allowance(P) = tiers[P].value
  cumulative  allowance(P) = Σ tiers[0..P].value        (-1 anywhere ⇒ unlimited)
```

| Mode | Resources | Why |
|---|---|---|
| `cumulative` | `storage_files_size_kb` **only** | Its bands are **five real directories** that coexist and fill in sequence. Band 0's 512 MB and band 1's 2 GB are both physically present on disk, so the allowance is genuinely their sum |
| `absolute` | the other **33** | `[2, 10, 50, 250]` is a table of totals. Nobody reading a pricing page thinks tier 2 means 62 |

This is not an inconsistency — it is the reason `tier_mode` is per resource. **Storage's bands
are places; every other resource's band is only a label** recording which tier's allowance
covers an item. Storage is the one case where the attribution and a physical location coincide,
which is also why its shipped code keeps the word `bucket` for the directory a member browses
([../handoff.md](../handoff.md) §8.3).

> **Naming note.** This document said *bucket* throughout until 2026-08-27. Renamed to **band**
> per [../handoff.md](../handoff.md) §8 — the concept is attribution, not containment, and
> `bucket` collided with object-storage buckets in a codebase that ships a cloud storage
> product. Shipped identifiers (`BucketUsage`, `routeBucket()`, `bucketRoot()`,
> `bucketCapacityBytes()`, `storage_bucket_usage`, `BucketPanel`) are **not** renamed and
> appear as-is. The registry's own value **has now been applied**: `checkpoint_registry_v4.json`
> reads `retention: "banded"` on all 29 banded resources, and its `conventions` prose no
> longer contains the word `bucket` at all.

## 2.6 Cascade — the part no per-feature design can do

Retiring an **instance** of a scope retires everything scoped beneath it.

```
retire(domain "acme.com", band 3)
  ├─ every per_domain resource on acme.com  → retired
  │    email_accounts, forms_count, subscribers_count, ecommerce_stores,
  │    processors_count, direct_payments_count, events_count, …
  └─ for each store in acme.com
       └─ every per_store resource          → retired  (recursive)
```

Rules:

1. **Depth-first, children before parents.** A parent whose children are not yet retired is
   in an inconsistent state; the reverse is merely incomplete.
2. **One clock.** Cascaded children inherit the **parent's** `purge_after`, not their own
   `grace_days`. A domain retired for 30 days must not contain mailboxes scheduled to
   vanish at 60 — the member sees one deadline, so there is one deadline.
3. **Restore is the mirror**, parents before children.
4. **A cascaded child cannot be evacuated to a surviving parent it has no room in.** The
   preview must compute this across the whole subtree, not per resource.
5. **`retention: none` children are skipped.** A retired domain's monthly send counter is
   not retired; it simply stops being usable because its parent is.

> Rule 2 is the one most likely to be got wrong by an implementation that treats each
> resource independently.

## 2.7 The ledger

One row per (resource, scope instance, band):

```
  resource_key     'domain_email_accounts'
  scope_type       'per_domain'
  scope_key        the domain / member ref / store id / project id …
  band           0-4
  ──────────────────────────────────────────────
  state            active | retired | purging
  used             in the resource's unit
  item_count
  retired_at
  purge_after      inherited from the parent when cascaded (§2.6 rule 2)
  suspended_at     set when suspended (§2.4); null otherwise
```

`scope_key` is deliberately opaque — the engine never parses it. Its meaning is defined by
`scope_type` and is the adapter's business.

> **Corrected 2026-09-21, from actually building it:** the original version of this table
> listed `state` as `active | retired | purging | suspended` (four values) *and* a separate
> `frozen_remaining` field — two different mechanisms for the same thing, and the first one
> doesn't survive contact with a real database. Storage's `state` column is a 3-value
> `enum('active','retired','purging')`; there was never room for a fourth. Adding one either
> means an `ALTER TABLE` on every resource's ledger, or — what was actually built —
> suspension as a marker **orthogonal** to `state`: `suspended_at` is set, `state` is left
> exactly as it was, and the engine/sweep both check `suspended_at IS NULL` rather than
> `state != 'suspended'`. On unsuspend, `purge_after` is shifted forward by however long the
> row was frozen (`now() - suspended_at`), rather than a precomputed `frozen_remaining` being
> read back — same effect, computed once, at the moment it's needed. See
> [features/complete/25-storage_files_size_kb.complete.md](features/complete/25-storage_files_size_kb.complete.md)
> §F25.10 F6 for the full account, including a Carbon 3 sign-convention bug this caught along
> the way. **Any future adapter's ledger should follow the orthogonal-marker shape, not the
> four-value enum this section originally specified.**

## 2.8 Detection

| Mechanism | Role |
|---|---|
| Tier-change hook from billing | **Fast.** Reconcile immediately |
| Scheduled sweep | **Authoritative.** Compares every holder against their bands |
| Opportunistic on read | A member looking at the page gets a current answer |

Two authoritative sweeps reading the same column degrade safely. An event that must be
delivered exactly once does not.

[../billing/08-brain-enforcement.md](../billing/08-brain-enforcement.md) is what writes the
tier down for non-payment; this engine is what makes that write mean something.

## 2.9 Fail-closed rules

| Situation | Behaviour | Why |
|---|---|---|
| Registry unreachable / uncached | Deny new allocation. **Retire nothing** | 28 of 29 resources are `overage: block`, so denial is right — but an unreachable registry must never trigger mass retirement |
| Registry **absent** vs. tier limit of **zero** | Must be **distinguishable**. Absent ⇒ skip; zero ⇒ act | Today's loader returns `[]` for a missing file, which `resolveLimit()` turns into `{ limit: 0, enabled: false }` — identical to a legitimate plan-0 member. The row above cannot be implemented until this is fixed. Same defect as B3, second location — [06](06-registry-distribution.md) §6.6 |
| **Membership row missing** | **Skip the holder entirely.** Do not treat as plan 0 | **Applied to storage 2026-09-21** — `QuotaService::planFor()` now throws rather than defaulting to 0, and `RetentionService::reconcile()` logs and skips the holder ([features/complete/25-storage_files_size_kb.complete.md](features/complete/25-storage_files_size_kb.complete.md) §F25.10 F1). Billing already got this right (*"unknown is not zero"*); storage previously didn't, and a resolve-but-empty membership table would otherwise retire every band for every member in one sweep. The other 33 resources don't have this machinery yet, so the rule still needs applying resource-by-resource |
| `membership` null / non-numeric / > 4 | Clamp 0–4 | |
| Adapter unreachable during a sweep | Skip that resource, log, continue | **Now true of `StorageRetentionSweep::handle()`, fixed 2026-09-22** — its Phase 1 loop used to call `reconcile()` for every member inside **one** `try`/`catch` around the whole loop, so an exception on member 1 of 500 aborted reconciliation for the other 499. Each member now gets its own `try`/`catch`; see [features/complete/25-storage_files_size_kb.complete.md](features/complete/25-storage_files_size_kb.complete.md) §F25.10 F8 |
| Holder suspended | Freeze; do not start or advance any clock | D5 |
| Cascade parent resolvable, child adapter is not | **Abort the whole cascade** for that instance | A half-retired subtree is worse than an un-retired one |

The asymmetry is the point: **denying an allocation on uncertainty is recoverable;
deleting on uncertainty is not.**
