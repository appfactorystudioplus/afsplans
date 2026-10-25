# 12 — Billing and monetization

## Completion document

When this plan step is completed, write
`complete/12-billing-and-monetization.complete.md`.

---

## 0. Scope note — designed now, built later

Per [00-overview.md](00-overview.md) §3 and §7, this document designs the model so D10 (reuse
`market`'s Stripe Connect rather than a second integration) can be implemented cleanly once the
free-plugin path (steps 00–11, 13–14) is live and trusted. **None of this is built in the first
delivery.** It is written now because a schema or pricing decision made without this in mind is
exactly the kind of thing that would force a rework later — the goal is that when this document's
phase starts, [03](03-database-schema.md)'s tables need no shape change to support it.

## 1. Why this reuses `market`, not a new integration

[market](../market/README.md) already solved the general problem this needs: a marketplace where
a third party sells something, the platform takes a cut, and money moves via Stripe Connect with
a split charge. Its step 08 (`08_stripe_connect_brain_api.md`) built Connect on Accounts v2,
surfaced through Direct Payments as `dp.connect`, provider-agnostic, gated by feature flag and
member level. A second, independent Stripe Connect integration for plugin purchases would
duplicate onboarding, split-calculation, webhook handling, and payout logic that already exists
and is already proven end to end for a materially identical problem (a seller, a buyer, a
platform fee).

## 2. What a paid plugin adds to the schema (future — not created now)

Sketched here for continuity with [03](03-database-schema.md), to be created as part of this
step's own implementation, not retrofitted onto tables built earlier:

| Table (future) | Purpose |
|---|---|
| `plugin_pricing` | Per-plugin pricing model: free, one-time, or subscription; amount; billing period |
| `plugin_purchases` | One row per (tenant, plugin) purchase — links to the `market`/Direct Payments order this produced |
| `plugin_developers.payout_account_ref` | Already reserved in [03 §1](03-database-schema.md) precisely for this — a Stripe Connect account reference created via `market`'s existing onboarding flow, not a new one |

`plugin_installs` ([03 §2](03-database-schema.md)) does not change shape — an install still means
"this tenant has this release," regardless of whether it was free or purchased; purchase state is
a separate, joined concern, the same separation `market` itself draws between an entitlement and
an order.

## 3. Pricing models to support, in order of implementation priority

1. **Free** — the entire first delivery; every plugin in the marketplace at launch.
2. **One-time purchase** — simplest paid model, a single Direct Payment charge with a Connect
   split to the developer, mirroring `market`'s existing asset-purchase flow closely enough to
   reuse most of its checkout logic directly.
3. **Subscription** — recurring billing per active install; more complex (what happens to an
   active install when a subscription lapses — most likely a forced transition to `disabled`,
   consistent with the existing state machine in [07 §2](07-installation-and-activation-lifecycle.md),
   with data retained under the same grace-period logic as a voluntary uninstall,
   [07 §5](07-installation-and-activation-lifecycle.md)).

## 4. Revenue share

Follows `market`'s existing split convention (its README documents a 90% seller / 10% platform
split for its own asset sales) unless a different figure is deliberately chosen for plugins —
recorded here as a decision for whoever implements this step, not fixed now, since it has no
bearing on anything in steps 00–11.

## 5. What does not change when billing is added

- The review pipeline ([10](10-review-and-publishing-pipeline.md)) is unchanged — a paid plugin
  goes through exactly the same static analysis and manual review as a free one; price is not a
  substitute for scrutiny, and there is no "paid plugins get faster review" fast path.
- The capability model ([05 §4](05-security-and-sandboxing.md)) is unchanged — payment does not
  grant a plugin any capability a free plugin couldn't also request and have approved.
- The consent screen ([09 §3](09-admin-dashboard-ux.md)) gains a price/terms line ahead of the
  capability list, but the capability list itself is shown exactly as it is for a free plugin.

## Phases

Not scheduled — this entire document is future work per §0. When scheduled, it should proceed in
the order §3 lists (free confirmed working → one-time purchase → subscription), each phase gated
on the previous one's real usage data rather than built speculatively ahead of it.
