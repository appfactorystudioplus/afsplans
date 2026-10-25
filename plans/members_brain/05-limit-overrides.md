# 05 — Limit Overrides

*"Give this customer 5 extra Direct Payments without moving them a tier."*

That request has no answer in the current data model, and the screen that looks like it should
answer it can only change usage — which is the opposite operation
([00](00-overview.md) **D2**).

## 5.1 The gap

| Thing | Lives in | Editable per member |
|---|---|---|
| **Usage** | Parse `MemberRegistry` | yes — that is what this screen does |
| **Ceiling** | `checkpoint_registry_v4.json`, by tier | **no** — it is per tier, per deployment |

An operator who wants to give one customer more room has three options today, and all three are
bad:

1. **Move them a tier.** Changes eleven other ceilings and their billing.
2. **Edit the registry file.** Changes that ceiling for *every member on that tier*.
3. **Zero their usage.** Grants a one-time credit that the next consume starts eating, and
   which nothing records as a decision.

Option 3 is what actually happens, because it is the only one the screen offers.

## 5.2 The decision — overrides live on the Brain, not in Parse

**A per-member override table in MySQL on the Brain**, delivered to clients alongside the tier,
applied at the same point in `alloc_v2` where the tier is applied.

The obvious alternative — an `overrides` map inside the `MemberRegistry` object, edited by this
form like everything else on the page — was rejected for one reason:

> Parse's `MemberRegistry` class-level permissions are **public for create, update and delete**
> ([../allocation.md](../allocation.md) §11.2, **G2**). Anyone holding the application id, which
> ships in every client bundle, can write any member's registry.

Putting ceilings there would let any member grant themselves an unlimited ceiling with four
lines of console JavaScript. They can already edit their own *usage* that way, which is bad —
but a usage edit is transient and shows up against `count(*)` in any reconciler
([../billing/16](../billing/16-allocation.md) §16.8). A forged **ceiling** is durable, reconciles
against nothing, and would be indistinguishable from a legitimate support grant.

**Do not add a new writable-by-anyone surface to a store that is writable by anyone.** When
[../alloc-v2-parse-direct/](../alloc-v2-parse-direct/) closes the CLPs, this decision is still
correct — entitlement belongs with the billing record, not with the usage counters
([../billing/09](../billing/09-entitlement-integration.md) §9.4's second warning: *"Do not 'fix'
this by writing the tier into Parse"*). An override **is** entitlement.

## 5.3 The shape

```php
// database/migrations/…_create_brain_alloc_overrides_table.php
// AFS_BRAIN_ALLOC_MODIFIER_ADDITION · AFS_ALLOC_V2_TAG
laravel_brain_alloc_overrides
    id
    member_reference_num   string(64)   index
    resource_id            string(64)               // e.g. domain_direct_payments_count
    scope_type             enum member|domain|pm_project   default member
    scope_value            string(255)  nullable    // the domain or project name
    limit_value            integer                  // -1 unlimited; 0 is a valid override
    reason                 string(500)              // REQUIRED — 02 §2.6
    expires_at             timestamp    nullable    // null = permanent, §5.5
    created_by_user_id     unsignedBigInteger
    created_by_username    string(128)              // denormalised, survives staff changes
    timestamps
    deleted_at             timestamp    nullable

    unique (member_reference_num, resource_id, scope_type, scope_value)
```

Four notes:

- **The scope is part of the identity.** `domain_processors_count` is `per_domain`, so *"5 more
  processors"* has to say **on which site**. An override with `scope_type: domain` and no
  `scope_value` is rejected at validation, not silently applied everywhere.
- **`limit_value: 0` is meaningful** and distinct from no override — it is how a specific member
  is denied a feature their tier includes.
- **`reason` is `NOT NULL`.** An override without a recorded why becomes unexplainable the day
  the person who granted it leaves.
- **Soft delete**, so a revoked grant remains visible in history rather than vanishing.

**Overrides do not touch `enabled`.** A tier where a feature does not exist stays that way; the
override raises or lowers a **number**. Granting a Free-tier member payment processors is a tier
change, not a quota adjustment, and conflating the two is how "Free" stops meaning anything.

## 5.4 Resolution order

One place, in the client, where the tier is already read:

```ts
/**
 * AFS_ALLOC_V2_TAG — plans/members_brain/05-limit-overrides.md §5.4
 *
 * Resolution order for a ceiling:
 *
 *   1. an unexpired override matching (resource, scope)   ← new
 *   2. registry tiers[tier].value                         ← today
 *
 * `enabled` is NEVER overridden (§5.3): a resource the tier does not include
 * stays excluded, and the override only moves the number.
 *
 * Overrides arrive with the tier, from the same server-derived payload, and
 * carry exactly the same trust: both are client-supplied here, and both are
 * ADVISORY until enforcement moves server-side (../../allocation.md §7).
 * Nothing about this makes allocation more forgeable than it already is —
 * a client that would forge an override can already forge the tier.
 */
```

Delivered two ways, both already existing channels:

| Consumer | Channel |
|---|---|
| Browser clients | `app.window.member.alloc_overrides[]`, in the member payload beside `membership` and `parse_alloc`; `createAllocV2()` passes it through like `tier` |
| Server-side enforcement | `GET /portal/api/v1/entitlement/{member}` ([../billing/09](../billing/09-entitlement-integration.md) §9.3) gains an `overrides` array |

The second is what makes an override apply to the limits that are *actually enforced* — the
forms submission meter ([../www/forms/15](../www/forms/15-allocation.md) §15.7) resolves its
ceiling from that endpoint, so an override reaches it with no code change there.

**Add `overrides` to the entitlement response in the same pass as the table.** An override that
works in the browser and not on the server is worse than none: it tells the operator the grant
landed and tells the member it did not.

## 5.5 Expiry

`expires_at` nullable. Null is permanent, and permanent is the common case for a negotiated
plan.

- The **resolver ignores expired rows** — it does not delete them. History is the point.
- The form shows *"expires 1 Oct (37 days)"*, and an expired override renders struck through in
  the overrides list, not hidden.
- **No scheduled job.** A nightly sweep that deletes expired grants is a job that can delete the
  wrong ones; a `where('expires_at', '>', now())` in the resolver cannot.

## 5.6 The form control

The ceiling in a row is read-only text ([04](04-form-generation.md) §4.3). Clicking it opens:

```
┌─ Override: Direct Payments · example.com ─────────────┐
│  Tier ceiling (Professional)            50            │
│  Override to                          [  75 ]         │
│  Expires                 ( • ) never  ( ) [ date  ]   │
│  Reason (required)  [ ticket 4412 — migrating from  ] │
│                     [ a competitor, agreed 6 months ] │
│                                                       │
│  ⓘ This changes what they are ALLOWED, not what they  │
│    have USED. Their usage stays at 12.                │
│                          [ Cancel ]  [ Grant ]        │
└───────────────────────────────────────────────────────┘
```

That info line is the whole reason this document exists. The operator arrived wanting to help a
customer, and the two available actions look interchangeable and are not.

**Overrides save through their own endpoint**, not the allocation save
([07](07-save-semantics.md)) — different table, different validation, different audit shape.
They appear in the same *review* step so one confirmation covers everything pending.

The row then renders `50 → **75** (override)` with the reason on hover, and the **show overrides
only** filter ([04](04-form-generation.md) §4.9) lists every grant on the account.

## 5.7 What an override is not

**It is not enforcement.** `alloc_v2` runs in the browser and takes the tier from the caller
([../allocation.md](../allocation.md) §7). An override raises a ceiling the client already
declines to enforce against a determined client.

What it *does* buy, honestly stated:

- The member stops seeing a limit dialog, which is the actual support ticket.
- The grant is **recorded, attributed, reasoned and expiring**, rather than being a zeroed
  counter nobody can explain later.
- Server-side enforcement points get it for free through the entitlement endpoint (§5.4), so
  where a limit *is* real — the forms submission meter today, more later — the override is real
  too.

That last line is the difference between this and option 3.

## 5.8 Acceptance

- Granting an override does **not** change usage, and the dialog says so before it is confirmed.
- An override on a `per_domain` resource **requires** a domain, and one saved without a
  `scope_value` is rejected.
- An override cannot make a resource available at a tier where `enabled: false` (§5.3).
- `limit_value: 0` is stored and applied — distinct from no override.
- An override with no `reason` is rejected at the database and at the endpoint.
- An **expired** override is ignored by the resolver, still visible in the list, and not deleted.
- The same override changes the ceiling in the **browser** and in
  `GET /portal/api/v1/entitlement/{member}` (§5.4).
- A forms-submission ceiling resolved server-side reflects the override with **no change to
  [../www/forms/15](../www/forms/15-allocation.md)**.
- Revoking an override soft-deletes it; the history remains queryable.
- `laravel_brain_alloc_overrides` is on the **Brain in MySQL**, and no ceiling is ever written
  into a Parse `MemberRegistry` (§5.2).
