# afs-connect — Planning Index

**afs-connect** is a paid, toggleable feature that lets the **coordinator** app view,
search, link to, and analyze data from the other four AFS applications —
**notes**, **dream_graph**, **flow**, and **pdf_editor** — for a given member
account. It is billed per member, gated by a global kill switch, and opt-in
per member and per source app.

This directory contains the full design. Read in this order:

| # | Document | Contents |
|---|----------|----------|
| 0 | [00-overview.md](00-overview.md) | Problem statement, goals, non-goals, glossary |
| 1 | [01-current-state-audit.md](01-current-state-audit.md) | What already exists in the repo that this design builds on (or must fix first) — including §8, the sub-user authorization audit and fix |
| 2 | [02-architecture.md](02-architecture.md) | System architecture, components, identity chain, data flow diagrams |
| 3 | [03-database-schema.md](03-database-schema.md) | New tables in coordinator's shared DB, changes to members' billing tables |
| 4 | [04-api-contracts.md](04-api-contracts.md) | Internal service-to-service API contracts between all five apps + members |
| 5 | [05-feature-toggle-and-billing.md](05-feature-toggle-and-billing.md) | ⚠️ **Superseded (layers 2 + 4).** How the feature is turned on/off at every layer. The shipped gate is **five** layers, not four — see doc 11 and `AfsConnectEntitlementService`'s own docblock. Still accurate as background for layers 1 and 3 |
| 6 | [06-security-and-service-auth.md](06-security-and-service-auth.md) | Service-to-service auth, entitlement re-checks, data minimization, team scoping |
| 7 | [07-sync-and-indexing-strategy.md](07-sync-and-indexing-strategy.md) | Webhook + polling + backfill design for the cross-app index |
| 8 | [08-frontend-ux.md](08-frontend-ux.md) | Coordinator dashboard UX, members settings UX, key screens |
| 9 | [09-rollout-plan.md](09-rollout-plan.md) | Phased milestones, migration steps, testing & rollback plan |
| 10 | [10-coordinator-env-variables.md](../_env/10-coordinator-env-variables.md) | **coordinator `config/afs_connect.php` — every `.env` variable explained** |
| 11 | [11-billing-and-allocation.md](11-billing-and-allocation.md) | ✅ **alloc_v2 metered billing** — registry-driven tier entitlement (afs-connect is starter+, not free), `secondary_users_count`, the brain↔registry tier-ladder mismatch. **Supersedes doc 5's Layer 2.** Tier entitlement is built; the five capacity meters remain proposals (see status below) |
| 12 | [12-source-registry.md](12-source-registry.md) | ✅ **The source registry** — one declaration replacing eleven registration sites, the `provider`/`meta`/`hosted` kinds, the privacy-vs-packaging settings split, and the CI consistency test |
| 13 | [13-step-60-findings-and-execution-plan.md](13-step-60-findings-and-execution-plan.md) | **The step-60 audit + build log** — what the audit confirmed, what it found, the eight-phase build order, and a per-phase record of what was actually built |

Docs 11–12 are **built**. Their build order came from the Joystick engine plan
repo as `plan/steps/60_afs_connect_platform.md` (platform fixes) and
`plan/steps/61_afs_connect_engine.md` (the game engine as the first `hosted`
source); doc 13 is the local record of that execution.

## One-paragraph summary

Coordinator becomes the aggregation point ("hub"). Each of the four source
apps exposes a small, versioned **internal HTTP API** (`/api/internal/afs-connect/*`)
that returns normalized summaries of its own data, authenticated with a
shared service key (mirroring the `system_key` pattern already used by
`apps/auth`'s Checkpoint config). Coordinator stores a denormalized,
metadata-only **index** of cross-app items in its own database (which is
physically the same shared MySQL instance the other four apps already use,
following the existing `{app}_` table-prefix convention — new tables use the
`afsconnect_` prefix). The index is kept fresh via lightweight webhooks fired
by each source app on create/update/delete, with a scheduled reconciliation
job as a safety net. Access is gated by independent layers — originally four in
[05-feature-toggle-and-billing.md](05-feature-toggle-and-billing.md), five as
shipped — so the feature can be killed globally, disabled per pricing tier,
disabled per member, withheld from a tier that does not include a given source,
or disabled per source app by the member — all without a deploy.

---

## Implementation status

**This plan is built and running.** Verified against the codebase on 2026-08-23.
Per-step build records live in [complete/](complete/README.md).

| Area | Evidence |
|------|----------|
| Schema | 6 core tables in `2026_07_22_000001_create_afsconnect_tables.php`, plus 5 later migrations |
| Coordinator | 14 models, 19 services, 3 jobs; `afs-connect:reconcile` scheduled every 15 min in `routes/console.php` |
| Public PM API | 10 routes under `/api/afs-connect/*` behind `checkpoint.member` |
| Internal API | `/api/internal/afs-connect/v1/*` — ingest, settings, status, tokens |
| Source apps | notes, flow, dream_graph, pdf_editor each expose `/internal/afs-connect/v1/{summary,changes,search,items,health}` |
| Entitlement | `AfsConnectEntitlementService` — **five** layers (global, tier, member, allowed, enabled), all fail-closed |
| Source registry | `SourceRegistry` + `config/afs_connect_sources.php`, **15 sources**, with `SourceRegistryConsistencyTest` |
| Allocation | `AllocationGateway` / `AllocationVerdict` / `ParseAllocationGateway` |
| UI | Coordinator PM `#/afs-connect` (5 TS modules), members self-service panel, Brain admin tab |
| Tests | 17 afs-connect test files under `apps/coordinator/tests/` |

Two corrections to earlier drafts of this index, both confirmed in code:

- **No class named `AllocationV2Manager` exists.** Doc 11's capability shipped as
  `ParseAllocationGateway` implementing the `AllocationGateway` interface.
  `AfsConnectEntitlementService`'s docblock names doc 11 §11.6 as "the target
  this class implements". The name survives only in docblocks describing the
  road not taken: `AllocationGateway`'s own docblock calls it "the three-tier
  `AllocationV2Manager` proxy described in `alloc_v2.ts`'s old docblock (**never
  implemented in any app**)", and `auth`'s `AllocationV2Controller` diagrams it
  as the client-side caller it expects. Both describe a design, not code that
  exists.
- **Doc 05's four-layer gate is superseded.** The shipped gate has five layers,
  splitting the old per-source toggle into *allowed* (tier packaging) and
  *enabled* (member privacy). The four config escape hatches doc 05 describes
  collapsed to one, `AFS_CONNECT_ENTITLEMENT_OVERRIDE`.

## Open items

From [13-step-60-findings-and-execution-plan.md](13-step-60-findings-and-execution-plan.md)'s
own "Not done in this pass" section. Each was re-verified against the code on
2026-08-23 and still stands.

| # | Item | Status in code |
|---|------|----------------|
| 1 | **Phase 6 — staleness (§60.6)** | Not built. No `afsconnect_source_status` table exists in any migration |
| 2 | **Phase 7 — link integrity (§60.7)** | Not built. `AfsConnectIndexService::deleteItem()` removes the index row and writes an activity entry but never touches `afsconnect_links` — there is no `AfsConnectLink` reference anywhere in that service, so deletions orphan links silently |
| 3 | **Aggregator www-module gap** | `AfsConnectAggregatorService` calls `wwwBrain->changes()` unconditionally, with no per-`www_*` live-status filtering — unlike the four creative sources, which loop per enabled source. Predates step 60 |
| 4 | **Capacity meters not wired** | Only `AllocationGateway::isEnabled()` (tier gating) is called in coordinator; there is no `consume()` / `release()` call anywhere in `apps/coordinator/app`. The five meters in doc 11 remain proposals — deliberately, as a pricing decision. (auth's separate copy *does* wire check/consume/release for `secondary_users`) |
| 5 | **Doc 11 `afs_connect_sources_count` inconsistency** | Unresolved either way |
| 6 | **`.env.prod` Parse app-id mismatch** | Unresolved; explicitly out of scope under the "Parse server is off-limits" decision |

Items 1 and 2 are the two unbuilt phases of the step-60 execution plan; 3 and 4
are known gaps carried deliberately.

## Related

- [afs-connect-www](../afs-connect-www/README.md) — the www portal-module
  extension of this plan (Brain meta-provider, eight website modules)
- [afs-connect-external](../afs-connect-external/README.md) — token-based
  external API surface, a later addition
