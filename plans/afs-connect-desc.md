I read the plan docs plus the actual implementation in `coordinator`, `members`, `www`, and `auth`'s registry. Here's how the paywall actually works.

## The short version

afs-connect is **not billed as its own product**. There is no afs-connect SKU, no charge, no separate subscription. It's an *entitlement* gated on the member's existing membership tier — a single integer, `brain_memberships.membership` (0–4) — checked against a JSON registry entry that declares the feature `enabled: false` at index 0 (free).

That one line is the entire paywall:

```jsonc
// checkpoint_registry_v3.json → resources.afs_connect.tiers
[ { "value": 0, "enabled": false },   // 0 = Free  ← the paywall
  { "value": 1, "enabled": true  },   // 1 = Starter
  … ]
```

## How it evolved (why two designs exist in the plans)

[05-feature-toggle-and-billing.md](afs-connect/05-feature-toggle-and-billing.md) is the **original** design: a boolean `ProcessorPlan.features.afs_connect` in `apps/members`, plus a `members` → coordinator entitlements endpoint and a subscription-webhook listener that hard-disabled on downgrade. **That is superseded and was never what shipped** — there's no `ProcessorPlan` model or `afs_connect` feature blob in `members` today.

[11-billing-and-allocation.md](afs-connect/11-billing-and-allocation.md) replaced it, and its argument is worth knowing: a boolean prices the feature wrongly because afs-connect's cost is index rows / ingest events / live fetches, not on-off. Since Checkpoint already ships **alloc_v2** (a registry-driven metering engine), the tier entry's `enabled` flag *is* the entitlement — so quota and entitlement can't drift apart as two separate systems.

[13-step-60-findings-and-execution-plan.md](afs-connect/13-step-60-findings-and-execution-plan.md) is the audit + build log for that rewrite, and it matches the code on disk.

## The five layers, as implemented

All of it lives in [AfsConnectEntitlementService.php](coordinator/app/Services/AfsConnect/AfsConnectEntitlementService.php). Every layer fails **closed**.

| # | Layer | Source | Denies with |
|---|---|---|---|
| 1 | Global kill switch | Checkpoint `afs_connect` feature flag over HTTP, 60s cache | `global_disabled` |
| 2 | **Tier — the paywall** | `AllocationGateway::isEnabled($member, 'afs_connect')` | `plan_not_entitled` |
| 3 | Member master toggle | `afsconnect_settings.enabled` | `member_disabled` |
| 4 | Source *allowed* (packaging) | `sources_allowed` ← registry `min_tier_index` vs tier | `source_not_allowed` |
| 5 | Source *enabled* (privacy) | `sources_enabled` — the member's own choice | `source_disabled` |

Layer 4/5 being split is the non-destructive-downgrade guarantee: a downgrade flips only `sources_allowed`, so re-upgrading restores the member's exact prior configuration, and the UI can say "your plan doesn't include this" instead of showing an unchecked box that looks like the member's own choice.

## Where the tier number comes from

This is the part that surprised me, and it matters for how you reason about billing:

- The payment side ([ProcessorBillingController.php](members/app/Http/Controllers/Externals/Processor/ProcessorBillingController.php)) is explicitly *"provider-agnostic… completely decoupled from any membership or entity model."* Stripe/Square/PayPal subscriptions do not feed the gate.
- The tier lives on `brain_memberships.membership`, written by www's Brain admin ([MembershipController.php:94](www/app/Http/Controllers/Portal/Admin/MembershipController.php:94) on create, `:141` on update).
- Coordinator resolves it via [BrainMembershipLookup.php](coordinator/app/Services/AfsConnect/BrainMembershipLookup.php) — local `brain_memberships` table if shared, else www's Brain API, 5-minute cache. Unresolvable member ⇒ tier 0 ⇒ denied.

So **the paywall's actual input is the Brain membership integer**, and whatever keeps that in sync with the payment processor is the real billing coupling. The old design's downgrade-webhook listener does not exist in this one.

Doc 11 §11.3's rule is enforced everywhere: the tier is a bare **index**, never a name. `Free/Pro/Business/…` and `free/starter/professional/…` are two different label sets over the same 0–4 ladder, and a name lookup would break silently the first time marketing edits a label.

## Enforcement points

| Path | Where | Free member gets |
|---|---|---|
| Read APIs (summary/search/items/insights) | [RequiresAfsConnectEntitlement.php](coordinator/app/Http/Controllers/Concerns/RequiresAfsConnectEntitlement.php) | `503` + `reason: plan_not_entitled` |
| Turning it on | [Api/Internal/AfsConnectSettingsController.php:87](coordinator/app/Http/Controllers/Api/Internal/AfsConnectSettingsController.php:87) | `402` + `upgrade_required: true` |
| Ingest from source apps | [AfsConnectIngestController.php:41](coordinator/app/Http/Controllers/Api/Internal/AfsConnectIngestController.php:41) | `503`, event dropped |
| Reconcile / backfill pull | [AfsConnectAggregatorService.php:84](coordinator/app/Services/AfsConnect/AfsConnectAggregatorService.php:84) — gates on the *live* (allowed && enabled) map | source never pulled |
| Members UI | [afsConnectSettings.ts](members/resources/js/settings/afsConnectSettings.ts) | upsell notice naming their tier; per-source rows render three-state with "Included from *Starter* and above" |
| Status | [AfsConnectStatusController.php](coordinator/app/Http/Controllers/Api/AfsConnect/AfsConnectStatusController.php) | `tier.index`/`tier.label`, per-source `allowed`/`enabled`/`live`/`upgrade_label`, `upgrade_url` |

One good property worth knowing: `isEnabled()` in [ParseAllocationGateway.php:45](coordinator/app/Services/AfsConnect/Allocation/ParseAllocationGateway.php:45) reads only the bundled registry JSON + the tier index — **no Parse call**. The paywall keeps working when Parse is down. Parse is only touched for *counting* (`check`/`consume`/`release`), and there `block` resources deny on transport failure so an outage never becomes free quota.

## What is metered vs. only designed

Only two resources exist in the registry today: `afs_connect` (an access flag — `value: 1` means presence, nothing consumes it) and `secondary_users` (`0/3/10/50/unlimited`, so free accounts can't create team members either — enforced in `auth`'s `SecondaryUserService::register`, returning **402** not 400).

The capacity meters — `afs_connect_index_rows`, `afs_connect_ingest_events_monthly`, `afs_connect_live_fetches_monthly`, `afs_connect_activity_retention_days` — are **proposals only**, deliberately deferred (they're a pricing decision). The seam is built: every source in [afs_connect_sources.php](coordinator/config/afs_connect_sources.php) already carries a `meter` field, so adopting one is a registry edit plus a consume call.

## Gaps that affect the paywall

Since you're asking whether free members really can't get in:

1. **`AFS_CONNECT_ENTITLEMENT_OVERRIDE=dev_allow_all` bypasses layer 2 entirely.** It replaced four separate escape hatches, which is an improvement, but production must have it `off` (the default, and any unrecognized value reads as `off`).
2. **www's guard is not a billing check.** [AfsConnectEntitlementGuard.php](www/app/AfsConnect/AfsConnectEntitlementGuard.php) checks only coordinator's `enabled` + the per-source *privacy* map — no tier, no `sources_allowed`. And when coordinator is unreachable it falls back to `env('AFS_CONNECT_DEV_ENABLED')`. [06-security-and-service-auth.md](afs-connect/06-security-and-service-auth.md) claims source apps "independently re-derive" entitlement; on the www path they don't. The paywall there rests entirely on coordinator refusing to pull and refusing ingest.
3. **Brain admin's override is write-only.** `brain_admin=1` from www skips the layer-2 check when persisting `enabled: true`, but every subsequent `check()` still runs layer 2 — so staff enabling a free member writes the row without granting access. The how-it-works doc reads as though Brain grants access; it doesn't.
4. **Layers 4/5 are inert today.** Every source has `min_tier_index => 1`, identical to afs-connect's own gate, so the packaging axis exists but isn't priced.
5. **The registry JSON is a manually-synced copy.** Coordinator bundles `resources/afs-connect/checkpoint_registry_v3.json`; it must match `auth`'s copy, and unlike the JS client there's no sync script. The class flags this itself.
6. **Migration of existing free members hasn't happened** (doc 11 §11.7: count, notify, grace period, keep data). Flipping this on today revokes access from anyone currently using it on free.
7. **Two open doc items**: `afs_connect_sources_count` appears in 11 §11.5 without an "(if adopted)" qualifier but isn't in the registry (finding B, unresolved), and members' proxy source-key list is still hand-kept — corrected to 12, but a 13th source added to coordinator's registry silently repeats the original bug.