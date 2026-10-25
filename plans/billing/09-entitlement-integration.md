# 09 — Entitlement Integration

How a downgrade reaches afs-connect, cloud-storage and alloc_v2 — and the honest account of
what it does not reach.

## 9.1 The observation this whole document rests on

Every entitlement consumer in the ecosystem already resolves from **one integer**:

| Consumer | What it reads | Where |
|---|---|---|
| cloud-storage quota | `brain_memberships.membership`, clamped 0–4 | `members/app/Services/Storage/QuotaService.php:82` |
| cloud-storage retirement | `planFor()` vs each `BucketUsage.bucket` | `members/app/Services/Storage/RetentionService.php:52` |
| cloud-storage sweep | hourly `storage:retention-sweep` | `members/routes/console.php` |
| afs-connect settings | `(int) $member->membership` | `members/app/Http/Controllers/AfsConnectSettingsController.php:98` |
| afs-connect entitlement layer 2 | tier index into `checkpoint_registry_v3.json` `resources.afs_connect.tiers[]` | [../afs-connect/11-billing-and-allocation.md](../afs-connect/11-billing-and-allocation.md) §11.2 |
| alloc_v2 (all 29 resources) | caller-supplied `tier` index into the same registry | `www/resources/js/checkpoint/alloc_v2.ts` |

So the integration is not integration work. It is **making that integer correct**, which
is what [08](08-brain-enforcement.md) does.

## 9.2 What changes in afs-connect and cloud-storage: nothing (decision **D11**)

### cloud-storage

`billing:enforce-membership` (triggered by `members`, run on `www`) sets `brain_memberships.membership` down to the policy
target — normally 0 ([08](08-brain-enforcement.md) §8.4.1). Within the hour,
`storage:retention-sweep` runs `RetentionService::reconcile()` for every member with a
`BucketUsage` row, finds `bucket > plan`, and moves those buckets to `retired` with
`purge_after = now + 30 days` — its own state machine, its own notifications, its own
grace.

This closes the gap that subsystem documented about itself:

> *"A billing hook on membership change would be the primary, immediate trigger (§6.5's
> table), but no such hook exists in this app to attach to (billing/membership changes
> happen entirely outside members)."* — `StorageRetentionSweep`

The hook now exists. It is not an event listener, and that is on purpose: cloud-storage's
§6.5 already calls its sweep **authoritative** precisely because a missed hook must not
mean a member silently keeps storage they stopped paying for. Two authoritative sweeps,
each reading the same column, is a design that degrades safely. An event that must be
delivered exactly once is not.

The composed timeline is worth stating plainly, because it is the member-visible one:

```
card fails ──14d dunning (08 §8.4)──▶ membership = 0
                                          │
                                          ├─ ≤1h ─▶ buckets retired, read-only, 30d grace
                                          ├─ afs-connect sources stop syncing
                                          └─ alloc_v2 limits drop to tier 0
                                                     │
                                          ──30d──▶ files to .trash/
                                          ──37d──▶ hard delete
```

51 days from a declined card to any irreversible data loss, with notifications from both
subsystems. That is the number to quote when someone proposes shortening either grace.

### afs-connect

Layer 2 of its five-layer entitlement resolves `resources.afs_connect.tiers[tierIndex].enabled`
from the registry. At index 0 that entry is `{ value: 0, enabled: false }` — so a
downgraded member's afs-connect access closes with no afs-connect code change at all.
Layers 3–5 (member enabled, source allowed, source enabled) are untouched; a member who
re-upgrades gets their own settings back exactly as they left them, because the downgrade
never wrote to them.

**This is verified, not inferred.** The path in `apps/coordinator`:

```
AfsConnectEntitlementService::tierIndexFor($memberRef, $hint)
  └─ resolveBrainMember()
       ├─ BrainMembershipLookup::findByReferenceNum()   ← primary
       │    ├─ local DB when Schema::hasTable('brain_memberships')
       │    └─ else the www Brain API
       └─ $brainMemberHint                              ← fallback only
  └─ max(0, min(4, (int) $row->membership))
```

Three properties of that code matter to this plan, and all three already hold:

- It reads `brain_memberships.membership` **server-side**, so the downgrade propagates
  without anything being told about it.
- `$row === null` yields tier **0**, and the clamp is 0–4 — fail closed, matching
  [08](08-brain-enforcement.md) §8.3's positional-index contract.
- The `brain_membership_tier` query param that `members`' `AfsConnectSettingsController`
  sends (`CoordinatorSettingsClient::fetchSettings()`) is a **hint used only when the row
  cannot be resolved**, not the primary source. So members' settings page cannot
  accidentally hold a downgraded member's access open with a stale value.

**The one timing caveat: `BrainMembershipLookup` caches for 300 seconds**
(`Cache::remember("afs_connect.brain_member.{$ref}", 300, …)`). So "closes on the next
entitlement check" means *within five minutes of the write*, not instantly. That is
acceptable for a downgrade and slightly annoying for a restore — a member who pays should
not wait five minutes to get afs-connect back.

If that becomes a complaint, the fix is one line in `DunningService` (the code that writes the tier): forget
`afs_connect.brain_member.{$memberRef}` after writing the tier. It is **not** built in this
pass, because the cache lives in coordinator's cache store and the Brain may not share it —
which is exactly the kind of cross-app coupling D11 exists to avoid. Wait for the complaint
before reaching for it, and if it comes, prefer a short TTL over a cross-app bust.

### `account_status` is deliberately not touched

`brain_memberships.account_status` (1=active, 2=suspended, 3=marked for deletion) is read
by afs-connect alongside the tier. Enforcement writes **only** `membership`. Suspension is
an account-lifecycle decision — abuse, fraud, a support action — and a failed card is not
one. Conflating them would make an expired card indistinguishable from a ban in every log
and every downstream check.

`secondary_users` behaves the same way: `enabled: false` at index 0 means a downgraded
member's team members lose access. That is a **product decision with teeth** and it must be
in the `dunning_final` email's list of what is lost (§8.8).

## 9.3 What must change: nothing has ever asked the server

The list in §9.1 has a fault line running through it. `QuotaService` and
`AfsConnectSettingsController` read `brain_memberships` **from the server, in a shared
database**. `alloc_v2.ts` reads the tier **from whoever called it**.

`alloc_v2.ts`'s own header is unambiguous:

> *"`tier` is likewise supplied by the caller (from `member.membership`, the 0-4 index).
> With no server-side code in the path there is nowhere to derive it from a token, so
> limits enforced here are ADVISORY — a determined client can claim any tier. … Treat
> allocation as a quota-management and UX mechanism, not a security boundary."*

So after part 2 ships, a downgraded member using the normal `members` UI correctly loses
their allocations, and a downgraded member who edits one number in a JavaScript console
does not.

**This plan does not fix that, and must not claim to.** What it adds is the server-derived
answer that apps can start asking for.

### `GET /portal/api/v1/brain/entitlement/{member_reference_num}`

On the Brain, behind the existing `afs_connect.service` HMAC middleware (the same
service-auth surface Brain already exposes to coordinator and members):

```json
{
  "member_reference_num": "m_abc123",
  "tier": 0,
  "tier_source": "billing",
  "billing_state": "downgraded",
  "downgraded_at": "2026-09-08T00:00:00Z",
  "tier_paid": 0,
  "parse_alloc": "aB3xY9…",
  "evaluated_at": "2026-09-08T00:15:00Z"
}
```

`tier` is read from `brain_memberships.membership` **server-side**, joined to the billing
state from [08](08-brain-enforcement.md) §8.5. `tier_source` is `billing` when a billing
state row governs it and `manual` when it does not — so a caller can tell an enforced tier
from an admin grant.

Consumers, in the order they should adopt it:

1. `members` — resolves the tier for `BillingApp` and for its own `QuotaService` (which
   today reads the column directly; switching it to the endpoint is optional, since it is in
   the same database, but it removes the assumption that it always will be).
2. coordinator / afs-connect — the `AllocationGateway` seam
   ([../afs-connect/11-billing-and-allocation.md](../afs-connect/11-billing-and-allocation.md) §11.4)
   already exists as the one place afs-connect talks to allocation. It resolves the tier
   there; this endpoint is what it resolves it *from*.
3. The desktop and mobile clients, which have no other server-side answer available.

**Fail closed.** Unresolvable member → `404` and the caller denies. Brain unreachable →
the caller denies for `block` resources and allows-with-degraded-flag for `notify`
resources, exactly as afs-connect §11.4 already specifies. Brain being briefly down must
not become free quota.

## 9.4 Saying the remaining gap out loud (decision **D12**)

| Question | Answer after this plan |
|---|---|
| Does a member who stops paying lose their tier? | **Yes**, after 14 days, with notice. |
| Do storage buckets retire? | **Yes**, within an hour, then a 30-day grace. |
| Does afs-connect close? | **Yes**, on the next entitlement check. |
| Can a member forge a higher tier in the browser and get more allocation? | **Yes, still.** `alloc_v2` takes the tier from the caller. |
| Is there now a server-derived tier any app can ask for? | **Yes** — §9.3. |
| Is it enforced at the store? | **No.** Parse's class-level permissions and the atomic-increment Cloud Functions that would enforce it are [../alloc-v2-parse-direct/](../alloc-v2-parse-direct/)'s work, not this plan's. |

That last row is the honest boundary. This plan makes the **billing signal** authoritative
and reachable. Making the **allocation store** refuse a forged tier requires server-derived
identity inside Parse, which is a different effort with a plan folder of its own. Anything
that must not be forgeable needs a server-side check of its own — the endpoint in §9.3 is
what such a check should call.

Two things that follow from that and should not be forgotten:

- **Do not build product features that depend on `alloc_v2` alone for a security-relevant
  limit.** Use §9.3 for those.
- **Do not "fix" this by writing the tier into Parse.** Parse's `MemberRegistry` holds
  *usage*, not entitlement, and adding a tier field there creates a second source of truth
  that can disagree with `brain_memberships` — the exact failure D11 exists to prevent.

## 9.5 The registry side

No change to `checkpoint_registry_v3.json` is required by this plan. Two notes for whoever
touches it next:

- The `afs_connect` and `secondary_users` resources already encode "not available on free"
  as `tiers[0].enabled = false`. A downgrade therefore needs no registry edit to take
  effect — which is the property that makes D11 work.
- afs-connect §11.2.2 proposes four capacity meters (`afs_connect_index_rows`,
  `afs_connect_ingest_events_monthly`, …) that do not exist yet. If they are added, a
  downgrade will start blocking on them too, and the `dunning_final` email's "what you
  lose" list ([08](08-brain-enforcement.md) §8.8) needs updating in the same pass.

## 9.6 Acceptance

- Downgrading a test member to 0 causes `storage:retention-sweep` to retire their
  above-plan buckets on its next hourly run, **with no change to cloud-storage code**.
- The same member's afs-connect access closes on the next entitlement check, **with no
  change to afs-connect code**.
- `GET /portal/api/v1/brain/entitlement/{ref}` returns the tier that is actually in
  `brain_memberships`, and `404`s for an unknown member.
- The endpoint requires service auth; an unauthenticated call gets `401`.
- Restoring the member reverses all of the above within one sweep interval each.
