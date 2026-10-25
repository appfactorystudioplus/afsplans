# membership_change — Planning Index

> **STATUS (2026-10-20): steps 06-14 are done (13 and 14 are unverified against real Stripe, a device and a browser; the whole PHP suite was not completed).** The architecture steps (00-05) describe the retention design
> and are not built as a whole. Steps 06-10 are built and recorded in `complete/`. Steps 11-14 are a **second theme** added the same
> day: creating a member properly (welcome, password, billing through Direct Payments, cancel and change) on the Brain, the phone app
> and the Brain dashboard. They are about *members*, not about what a member's resources do when their membership changes; they live
> here because the owner asked for them in this folder.

What happens to a resource a member **already holds** when their membership changes —
**upgrade**, **downgrade**, or **suspension**.

## This is not a new system. It is the missing half of `alloc_v2`.

`alloc_v2` answers exactly one question:

> **"May I create one more?"** — `canUse` / `consume` / `release`

It cannot answer:

> **"I hold more than I'm allowed. Which ones lose?"**

Those are the same question at two different times, both derived from the same fact
(`tiers[plan]` versus what is held). One is the **forward edge**, one is the **backward
edge**. Only the forward edge was ever built.

| Edge | Question | Built? |
|---|---|---|
| **Forward** | may I create one more | 3 of 29 resources, and `canUse` fails **open** |
| **Backward** | which of what I hold loses | **1 of 29** (`storage_files_size_kb`) |

So a downgrade today changes nothing for 28 of 29 resources. The member keeps everything
they had, forever, while paying less for it — and the pricing page says otherwise.

## What the plan actually does

1. **Extends the registry** so a resource declares its own retention policy —
   `retention`, `tier_mode`, `grace_days`, `unit_kind`, `inventory`, and a `scope_graph`
   for cascade. That is `checkpoint_registry_v4.json`, already written.
2. **Builds the backward edge once**, registry-driven, and applies it per resource.
3. **Refactors storage onto it** without changing its observable behaviour ([storage](features/25-storage_files_size_kb.md)).
4. **Applies it to email accounts** as the first new feature ([email](features/2-domain_email_accounts.md)).

> ### ⚠ Why this cannot be per-feature code
>
> `member_domains_count` is `[1, 3, 10, 25, -1]`. A member on plan 3 holds 10 domains and
> drops to plan 1 — **7 domains retire**, and each contains ten `per_domain` resources,
> one of which (`domain_ecommerce_stores`) contains a `per_store` resource beneath it.
>
> Retiring a domain has to cascade transitively into everything scoped under it. Email's
> retirement code has no way to know a domain above it is being retired. **Only something
> holding the whole resource graph can compute that**, and the thing that holds the
> resource graph is the registry.

Read in this order — this list is the **architecture only**: how the system works in
general, independent of any one resource. **Renumbered 2026-09-22** — steps 04 and 05 used
to be here as the storage and email walkthroughs; they moved to
[features/](features/) (see below) so this index stays the system-wide view, not a mix of
architecture and one-feature-at-a-time detail.

| Step | Document | Contents |
|---|----------|----------|
| — | [README.md](README.md) | This index |
| 00 | [00-overview.md](00-overview.md) | Problem, goals, non-goals, decisions, and what remains open |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | What storage built, what alloc_v2 wired, and the defects both sit on |
| 02 | [02-architecture.md](02-architecture.md) | Registry-driven retention, the scope graph, cascade, suspension |
| 03 | [03-api-contracts.md](03-api-contracts.md) | The merged surface — one API with both edges. **`state()`/`preview()` built 2026-09-23 — see [complete/03-api-contracts-alloc_v2-fold.complete.md](complete/03-api-contracts-alloc_v2-fold.complete.md)**: folded into `CheckpointAllocV2` (not the originally-specified `window.Portal.membership`, which collided with a real, already-shipped object — see §3.1's own correction), working for the two resources with a retention engine. `canUse`/`consume`/`release`/`items`/`move` remain contract-only |
| 04 | [04-feature-template.md](04-feature-template.md) | What each remaining resource has to answer |
| 05 | [05-rollout-and-testing.md](05-rollout-and-testing.md) | Phases, and the tests that prove nothing deletes early |
| 06 | [06-registry-distribution.md](06-registry-distribution.md) | **Prerequisite. Done — see [complete/06-registry-distribution.complete.md](complete/06-registry-distribution.complete.md).** The registry is one file, resolved at runtime, nothing bundled (steps 1–5, 7 of §6.5 found already implemented elsewhere on 2026-09-23; step 6 — deriving/de-duplicating the hand-maintained resource-id maps, and a second copy-per-app drift found in the process — completed that day). OQ-J (CORS) and OQ-K (9-copy SDK collapse) remain open. See [../alloc-v2-parse-direct/03](../alloc-v2-parse-direct/03-parse-cloud-functions.md) §3.8.1 for the allocation-side implementation this document's decision fed into. |
| 07 | [07-signup-flow.md](07-signup-flow.md) | **Done — see [complete/07-signup-flow.complete.md](complete/07-signup-flow.complete.md).** Every finding closed, moot, or explicitly deferred: the security list (S1 RCE, S19 tier-escalation replay, S2/S3/S17/S25/S29/S34/S35), server-owned identity, one ordered signup flow with resumable failure, a scoped storage-provisioning fix, and the tidy list. §7.7.1's whole provisioning-reconciliation narrative (S21/S22/S23) turned out to be moot — the architecture it describes was superseded by the `MemberResource` row model before this pass started. The plan's literal two-step `/register/begin`+`/register/complete` API (§7.5) was deliberately not built — see the completion doc's own deviation note. OQ-E/OQ-G/OQ-J remain open, genuine product/infrastructure decisions |
| 08 | [08-member-management.md](08-member-management.md) | **Done — see [complete/08-member-management.complete.md](complete/08-member-management.complete.md).** `level` closed off everywhere on the controller (including a live bug in `adminStore()` this document didn't name), tier and account-status split into their own audited/reasoned operations sharing DunningService's own `brain_membership_events` table, a stale-write guard, and a paged/searchable list. The retention-engine-dependent half (preview, holdings/retirement panels, MM4) stays open — the engine has no caller anywhere yet. **The Allocations-tab leftover closed 2026-09-23 — see [complete/08-member-management-allocations.complete.md](complete/08-member-management-allocations.complete.md)**: `members_brain`'s claimed-done override system had no real authorization, a 500 on the plain case, and was never actually visible — all three found and fixed |
| 09 | [09-billing-tier-change-hook.md](09-billing-tier-change-hook.md) | **Done — see [complete/09-billing-tier-change-hook.complete.md](complete/09-billing-tier-change-hook.complete.md).** D8's "tier-change hook from billing" — the last of the three detection mechanisms named in the design. `www` → `members`, fired by `created()`/`updated()` observer methods (not a single `saved()` + `wasChanged()`, which this pass found silently misses signup) on every real write to `brain_memberships.membership`, reusing the signed channel and `reconcile()` isolation already built for email. All three open questions (OQ-K/L/M) resolved as built |
| 10 | [10-preview-endpoint.md](10-preview-endpoint.md) | **Done — see [complete/10-preview-endpoint.complete.md](complete/10-preview-endpoint.complete.md).** Implements `03`'s already-designed `preview()` contract — D10, "see the consequence before it happens." `RetentionEngine::preview()` now has three callers (member-facing storage, member-facing email, signed `www.internal` email) instead of zero, and `BillingApp.ts`'s plan-change dialog shows the consequence before the member confirms. OQ-P/OQ-Q remain open, as scoped |
| 11 | [11-new-member-welcome-and-alerts.md](11-new-member-welcome-and-alerts.md) | **Done 2026-10-20 — see [complete/11-new-member-welcome-and-alerts.complete.md](complete/11-new-member-welcome-and-alerts.complete.md). Brain only.** One shared routine finishes creating a member for both the public signup and the admin/phone create (the latter skips the payer, e-mail propagation and storage today); the Brain-only Memberships module announces `member_created` through the general notification system ([`www/notifications_and_analytics`](../www/notifications_and_analytics/README.md)) so the new member gets a welcome e-mail and the Brain gets a bell and a count. Replaces the dead welcome block. |
| 12 | [12-phone-add-member-and-alerts.md](12-phone-add-member-and-alerts.md) | **Built, uncommitted - see [complete/12-phone-add-member-and-alerts.complete.md](complete/12-phone-add-member-and-alerts.complete.md).** The phone shows the Brain's own inbox: an Alerts page under More, an unread count on the bar, a Members chip, tapping a new-member row opens that member. Level A only (while the app is open); no background, push or Firebase. Not seen on a device. |
| 13 | [13-staff-member-lifecycle-server.md](13-staff-member-lifecycle-server.md) | **Built, uncommitted - see [complete/13-staff-member-lifecycle-server.complete.md](complete/13-staff-member-lifecycle-server.complete.md).** Nothing ran against real Stripe or in a browser. Create a member and set up billing **through Direct Payments' normal flow** (the operator acts through a payer token and an administrator-set start option; nothing calls Stripe directly): free tier, card now, a free period (7 days to 12 months) with or without a card (no card at the end: the subscription **pauses**), or complimentary; `paused` support, tax as a processor setting, and the rule that **no member is ever level 99** (only `appfactorystudio`). Cancel and change reuse the existing audited routes. |
| 14 | [14-phone-and-dashboard-member-lifecycle.md](14-phone-and-dashboard-member-lifecycle.md) | **Built, uncommitted - see [complete/14-phone-and-dashboard-member-lifecycle.complete.md](complete/14-phone-and-dashboard-member-lifecycle.complete.md).** The phone and the Brain dashboard add a member in five steps, start or finish billing through a card page the server names, cancel (now or at period end), change plan, and show status chips; the dashboard's dead Subscription and Payments tabs (and the card-number form) are replaced. Card page opens in the phone's in-app browser, not a web view. Not seen on a device or in a browser. |

> Note: every document's internal section numbers were renumbered to match its new step
> number (§7.x → §5.x, §8.x → §6.x, §9.x → §7.x, §10.x → §8.x), along with every
> cross-reference to them elsewhere in `plans/`.

## Per-feature documents — `features/`

Steps 04 and 05 used to live here as the first two feature walkthroughs (storage, email).
They now live in **[features/](features/)**, one file per resource, named
`[feature number]-[feature name].md` where the number matches
**[../TODO-member-resources.md](../TODO-member-resources.md)**'s numbering (1-35) — not the
architecture step sequence above:

| File | Resource | Contents |
|---|---|---|
| [features/2-domain_email_accounts.md](features/2-domain_email_accounts.md) | `domain_email_accounts` (#2) | The first new feature — and what "disabled" means for live mail |
| [features/3-domain_email_sends_monthly.md](features/3-domain_email_sends_monthly.md) | `domain_email_sends_monthly` (#3) | Planning only, 2026-09-22 — forward-edge-only (retention is `n/a` by design), no implementation yet |
| [features/25-storage_files_size_kb.md](features/25-storage_files_size_kb.md) | `storage_files_size_kb` (#25) | Folding cloud-storage in without regressing it |
| [features/23-voip_numbers_count.md](features/23-voip_numbers_count.md) | `voip_numbers_count` (#23) | Planning only, 2026-09-23 — the provisioning feature itself doesn't exist yet (found only a dead stub), so this records the open retention questions for whoever builds it, unresolved on purpose |

Each has a matching completion record in
**[features/complete/](features/complete/)** — the execution status is written and verified
there, not in the design doc itself.

### Internal section numbers in `features/` use an `F` prefix

A feature doc's own internal sections are numbered `§F<feature number>.x` — e.g. email
(`features/2-domain_email_accounts.md`) uses `§F2.1`, `§F2.2`, …; storage
(`features/25-storage_files_size_kb.md`) uses `§F25.1`, `§F25.2`, … — **not** bare
`§<feature number>.x`.

**Why:** the architecture docs (00-08) already own the bare numbers 0-8 for their own
sections (`02-architecture.md` uses `§2.1`–`§2.9` extensively, cross-referenced throughout
this whole plan). A feature number can collide with one of those — `domain_email_accounts`
is feature #2, and `02-architecture.md` is step 02 — so a feature doc's sections would
otherwise share a number with an unrelated architecture doc's sections, sometimes inside the
very same file (`features/2-domain_email_accounts.md` cites `02-architecture.md`'s own
`§2.6`). The `F` prefix makes a feature doc's own sections textually distinct from any
architecture doc's sections, unconditionally — no need to check whether a given feature
number happens to collide before deciding.

**Apply this to every new `features/` doc**, regardless of whether its particular feature
number would actually collide with anything today — the point is not needing to check.

**Per-resource progress for all 35 is tracked in [../TODO-member-resources.md](../TODO-member-resources.md)**
— one section each, with the cross-cutting blockers at the top.
