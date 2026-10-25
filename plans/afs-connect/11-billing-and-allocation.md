# 11 — Billing & Allocation (alloc_v2)

> **Status:** specification for the work planned as **step 60** in the Joystick
> engine plan repo (`JoystickEngine_v2/plan/steps/60_afs_connect_platform.md`).
> That step file is the build order; this document is the contract.
>
> **Supersedes** [05-feature-toggle-and-billing.md](05-feature-toggle-and-billing.md)
> §"Layer 2 — Plan entitlement", which specified a boolean
> `ProcessorPlan.features.afs_connect`. Layers 1, 3 and 4 of that document are
> unchanged in intent; this document replaces the billing layer and adds
> metering.

---

## 11.1 Why the boolean had to go

afs-connect's original plan gate was one flag: `ProcessorPlan.features.afs_connect`, on or off.

That prices the feature wrongly, because afs-connect's cost is not binary. Its real drivers are **rows in `afsconnect_index`**, **ingest events processed**, and **live `items/{id}` fetches**. A member with fifty websites and 200 000 form submissions consumes orders of magnitude more than a member with one notes account, and the boolean charges them the same.

Meanwhile Checkpoint already ships a complete, deployed metering engine that afs-connect never called:

- **alloc_v2** — `/checkpoint/alloc/v2/{,{resourceId},check,consume,release}`
- **Parse-backed** — one `MemberRegistry` object per member holding `member_resources`, `domains[]`, `pm_projects[]`, plus an append-only `CheckpointAlloc_Log`
- **Registry-driven** — `public/checkpoint/checkpoint_registry_v3.json` defines every resource's scope, period, overage behavior, warn threshold, and **per-tier limits**

> **alloc v1 is deprecated.** `/checkpoint/alloc/*` (`AllocationController`) is
> superseded by `/checkpoint/alloc/v2/*` (`AllocationV2Controller`). New work
> targets v2 only. The TypeScript client's `alloc.ts` is likewise superseded by
> `alloc_v2.ts`.

## 11.2 The registry is the single source of truth for *whether* and *how much*

The insight that removes the boolean entirely: each resource's `tiers[]` entry carries **both** a limit and an `enabled` flag.

```jsonc
"tiers": [                                 // index IS the contract; labels are cosmetic (§11.3)
  { "value": 0,      "enabled": false },   // 0 — feature NOT available
  { "value": 5000,   "enabled": true  },   // 1
  { "value": 50000,  "enabled": true  },   // 2
  { "value": 250000, "enabled": true  },   // 3
  { "value": -1,     "enabled": true  }    // 4 — -1 = unlimited
]
```

`enabled: false` at index 0 **is** the statement "afs-connect is not available on the free tier". So entitlement and quota stop being two systems that can disagree; there is one registry entry per resource, and the plan gate is a property of it.

### 11.2.1 The two resources that already exist

`checkpoint_registry_v3.json` already carries both, and they are the contract — do not invent alternative ids:

| Resource id | Scope | Period | Overage | idx 0 | 1 | 2 | 3 | 4 |
|---|---|---|---|---|---|---|---|---|
| `afs_connect` | member | lifetime | block | **0, disabled** | 1 | 1 | 1 | 1 |
| `secondary_users` | member | lifetime | block | **0, disabled** | 3 | 10 | 50 | −1 |

`afs_connect` is an **access flag, not a meter**: `value: 1` means presence, `warn_at_pct: 0` disables warnings, nothing consumes it. `tiers[tierIndex].enabled` on it *is* the answer to "may this member use afs-connect at all", replacing both `ProcessorPlan.features.afs_connect` and coordinator's `min_membership_tier` config.

`secondary_users` implements the second rule: **free accounts cannot create secondary users**, `enabled: false` at index 0.

### 11.2.2 Capacity meters — proposed, not yet present

The access flag answers *whether*, not *how much*, and afs-connect's real costs are index rows, ingest events and live fetches. These are **proposals** and everything else works without them:

| Proposed id | Period | Overage | Purpose |
|---|---|---|---|
| `afs_connect_index_rows` | lifetime | block | Searchable indexed items — the hard capacity limit |
| `afs_connect_ingest_events_monthly` | monthly | notify | Write volume |
| `afs_connect_live_fetches_monthly` | monthly | notify | On-demand detail calls |
| `afs_connect_activity_retention_days` | lifetime | block | PM timeline depth |

Tier values are a pricing decision — propose an order of magnitude per tier with index 0 at `value: 0, enabled: false`, consistent with `afs_connect` itself. Build the metering seam regardless: a meter id is a field on each source-registry entry ([12-source-registry.md](12-source-registry.md)), so adopting one later is an entry plus a consume call.

## 11.3 The tier index is the contract

`AllocationV2Controller` takes `tier` as a **bare integer index** into each resource's `tiers[]` array and performs no auth-DB lookup of its own. The caller supplies `parseObjectId` and `tier`.

**Pass `brain_memberships.membership` straight through — it *is* the index.** Both ladders are the same 0–4 ranking (0 = free, 4 = top); only their *labels* differ (`Free/Pro/Business/Organization/Enterprise` versus `free/starter/professional/business/enterprise`), and labels carry no contract. Confirmed with pricing.

`apps/members`' `BillingApp.ts` already reads it this way (`membershipVal = parseInt(member.membership.toString())`, matched against plan ids), so this is the established interpretation.

**The one rule: never resolve or compare a tier by name.** A name lookup breaks silently the moment either label set is edited for marketing reasons — a change nobody thinks of as schema. Clamp the value to 0–4 and treat a missing membership record as 0 (fail closed: an unknown member must never inherit a paid tier's limits).

## 11.4 Transport: how a call reaches Parse

Usage lives in **Parse** — one `MemberRegistry` object per member holding `member_resources`, `domains[]` and `pm_projects[]` (see `parse_member_query.json`), plus an append-only `CheckpointAlloc_Log`. That is settled.

**How a caller reaches it is being fixed by a separate effort.** The transport shipped today is a three-tier proxy — browser → `AllocationV2Manager.php` on the calling app's server → `AllocationV2Controller` on auth → Parse — which has two defects: it is unusable from any client that is not a Laravel web app (no same-origin PHP endpoint, no way to discover which app server to address), and **`AllocationV2Manager.php` is implemented in no app**, despite being named in the docblocks of both `alloc_v2.ts` and `AllocationV2Controller.php`.

The fix is specified in **[../alloc-v2-parse-direct/](../alloc-v2-parse-direct/)**: the client initialises the Parse SDK directly against `https://dashboard15-registry.appfactory.studio/parse`, with enforcement moved into Parse Cloud Functions (atomic increments, server-derived tier, locked class-level permissions). Same method surface, new constructor (`applicationId`, `serverURL`).

**Resolution for afs-connect:** put every allocation call behind **one seam**, so this step is unaffected by which transport is in place and lands independently of that effort.

```php
/**
 * The one place afs-connect talks to allocation.
 *
 * WHY an interface for something with a single implementation: the repo
 * documents two transports for alloc_v2 (direct Parse, and the three-tier
 * AllocationV2Manager proxy described in alloc_v2.ts). Whichever wins, it
 * changes here and nowhere else.
 *
 * Responsibilities:
 *   1. Resolve the member's Parse objectId and TIER INDEX once per request
 *      cycle (§11.3). Calling code never sees a tier and cannot pass a wrong one.
 *   2. FAIL SAFE, NOT OPEN — on transport failure, `block` resources DENY and
 *      `notify` resources ALLOW with a degraded flag. Parse being briefly
 *      unreachable must not become free quota, and must not break read-only
 *      browsing of already-indexed data.
 *   3. Cache the full allocation read per member for 60s; a reconcile sweep
 *      would otherwise hit Parse once per source per member.
 *
 * Deliberately NOT here: knowledge of any specific resource id.
 */
interface AllocationGateway
{
    public function isEnabled(string $memberReferenceNum, string $resourceId): bool;
    public function check(string $memberReferenceNum, string $resourceId, int $amount = 1): AllocationVerdict;
    public function consume(string $memberReferenceNum, string $resourceId, int $amount = 1): AllocationVerdict;
    public function release(string $memberReferenceNum, string $resourceId, int $amount = 1): void;
}
```

Two things the direct-to-Parse effort surfaced that afs-connect must not assume away:

- **`MemberRegistry`'s class-level permissions are currently public** for find/get/create/update/delete. Until [../alloc-v2-parse-direct/03-parse-cloud-functions.md](../alloc-v2-parse-direct/03-parse-cloud-functions.md) §3.3 lands, treat stored usage as untrusted for anything security-bearing — entitlement (layer 2) reads the registry JSON's `enabled` flag, which is static and safe; *quota* numbers are not.
- **The Parse-side schema disagrees with the sample query**: `cloud/schema.ts` declares class `Registry` with a pre-v3 field set, while the controller and `parse_member_query.json` use `MemberRegistry` with `member_resources` / `domains[]` / `pm_projects[]`. Any afs-connect meter added before that is reconciled will be written against a shape that does not exist yet.

## 11.5 Where each meter is consumed

| Resource | `check` / `consume` site | `release` site |
|---|---|---|
| `afs_connect` (access) | entitlement layer 2 — **read only, never consumed** | — |
| `afs_connect_index_rows` *(if adopted)* | `AfsConnectIndexService` — when an upsert creates a **new** row | row deleted or pruned |
| `afs_connect_ingest_events_monthly` *(if adopted)* | `ProcessAfsConnectWebhookJob` — per accepted event | n/a (monthly reset) |
| `afs_connect_live_fetches_monthly` *(if adopted)* | `AfsConnectItemsController::show` | n/a (monthly reset) |
| `afs_connect_sources_count` | settings save — when a source flips **on** | when it flips off |
| `afs_connect_activity_retention_days` *(if adopted)* | read as a limit by the activity-log pruner | n/a |
| `secondary_users` | `SecondaryUserController::register` | secondary user deleted |

### 11.5.1 Metering must never break ingest

```php
/**
 * Quota enforcement in the ingest path is a DATA decision, never a TRANSPORT
 * failure.
 *
 * When afs_connect_index_rows is exhausted:
 *   - the new row is refused,
 *   - a `quota_exceeded` entry is written to afsconnect_activity_log so the PM
 *     UI can explain the gap ("index full — upgrade to keep indexing"),
 *   - the ingest endpoint still returns 202.
 *
 * The 202 is deliberate: a 4xx/5xx makes the source app's SendAfsConnectIngestJob
 * retry with backoff forever against a condition that retrying cannot fix, and
 * a queue of doomed jobs is a worse outage than the missing rows. The response
 * body carries `quota_exceeded` so the sender can stop and, if it wants, warn.
 *
 * Monthly counters use `overage: notify` for the same reason: exceeding them
 * degrades to a warning, and only the hard capacity limit blocks.
 */
```

## 11.6 The five entitlement layers, revised

| Layer | Question | Source | Fail-closed |
|---|---|---|---|
| 1 — Global | Platform kill switch off? | Checkpoint `afs_connect` feature flag | yes |
| 2 — **Tier** | Is `afs_connect.tiers[tierIndex].enabled` true? | **registry_v3 via alloc_v2** | yes |
| 3 — Member | Has the member turned afs-connect on? | `afsconnect_settings.enabled` | yes |
| 4 — **Allowed** | Does the tier include *this source*? | `sources_allowed` ← registry `min_tier_index` | yes |
| 5 — Enabled | Has the member turned *this source* on? | `sources_enabled` | yes |

Layer 2 replaces `plan_includes_afs_connect`, `min_membership_tier` and `dev_plan_entitled`. Layers 4 and 5 are the privacy/packaging split ([12-source-registry.md](12-source-registry.md) §12.4).

The four config escape hatches (`global_enabled_override`, `dev_plan_entitled`, `require_checkpoint_global_for_access`, `min_membership_tier`) collapse to one: `AFS_CONNECT_ENTITLEMENT_OVERRIDE=off|dev_allow_all`.

`GET /api/afs-connect/status` grows `tier`, `usage` (per-meter used/limit/pct/warn), per-source `allowed`/`enabled`/`min_tier`, and `upgrade_url` — so every UI can show numbers and a specific upgrade path instead of a bare refusal.

## 11.7 Migrating existing members

Free-tier members currently able to use afs-connect will lose access. That needs a rollout, not a flag flip:

1. Count affected members and their index sizes **before** anything changes.
2. Notify, with the tier that restores access.
3. Keep `afsconnect_settings` rows and index data through a defined grace period — upgrading must restore everything with no support ticket.
4. PM renders the upsell, never an error.
5. Pruning a downgraded member's data is a **separate, explicitly scheduled** retention job — never a side effect of a tier change.

## 11.8 Test matrix

| Case | Expect |
|---|---|
| Free member (index 0), any afs-connect call | `tier_not_entitled`; both coordinator PM and `apps/members`' afsConnectSettings.ts show the upgrade prompt |
| A member at each tier index 0-4 | `afs_connect.tiers[i].enabled` decides access; index 0 refused, 1-4 allowed; no name lookup anywhere |
| *(if adopted)* member at 85% of `afs_connect_index_rows` | `warn: true` in status; nothing blocked |
| *(if adopted)* member at 100% | new rows refused, `quota_exceeded` logged, ingest still `202`, sender stops |
| Monthly ingest counter exceeded (`notify`) | ingest continues, warning surfaces |
| Parse unreachable | `block` denies, `notify` allows-degraded, browsing unaffected |
| Free account creates a secondary user | refused as a **tier-upgrade** error, not validation; tier 1 succeeds (limit 3) and the counter increments |
| Secondary user deleted | counter releases |
| Downgrade then re-upgrade | `sources_enabled` untouched; only `sources_allowed` flips |
| Reconcile, 50 members × 6 sources | one allocation read per member (60 s cache) |
