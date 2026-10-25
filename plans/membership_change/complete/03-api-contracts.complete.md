# 03 — API Contracts (P0) — Complete

> **Correction, 2026-09-23 — see [../03-api-contracts.md](../03-api-contracts.md) §3.1.**
> The facade's proposed name, `window.Portal.membership`, was wrong on two counts checked
> against the live code: `window.Portal` already exists and is a *different*, already-shipped
> SDK (`www/resources/js/external/api/main.ts`, the tenant-site/third-party integration
> object), and the precedent cited for the name (`secondary-users.ts`) doesn't use any
> `window.*` global either — every real `checkpoint/*.ts` consumer uses a plain ES import.
> The corrected shape is a plain exported class, not a global namespace; §3.1 leans toward
> folding it into `CheckpointAllocV2` (`checkpoint/alloc_v2.ts`) rather than a new sibling
> file. The shapes/endpoints/error-codes below are unaffected — only the client-side naming
> and file-location questions changed.

## Status

Contract designed. Defines the unified surface for both forward and backward edges (allocation + retirement) that steps 04–06 will implement against. **Nothing built; this is the specification.**

The contract unifies two halves of the allocation system:
- **Forward edge:** `canUse` / `consume` / `release` (may I create one more?)
- **Backward edge:** `state` / `preview` / `items` / `move` (which of what I hold loses on downgrade?)

## Summary

**One facade, two edges.** `window.Portal.membership` is a JavaScript module that exposes a single vocabulary covering both allocation and retirement. Consumers learn one API; the implementation behind it coordinates the shared layer (retention engine, state machine, cascade) with per-resource adapters (inventory access, retire/purge actions).

The contract emphasizes:
- **Partial success is normal** — move operations return what succeeded and what failed per-item
- **Preview is real** — shows the cascade subtree and actual evacuation headroom, never offers impossible advice
- **Tier is server-side only** — no caller-supplied tier reaches the backward edge
- **Scope is declared in the registry** — seven scope types, all nested, all handled by one cascade algorithm

## Files created

None. This is a specification document.

## Files modified

None yet. Implementation will need:
- `checkpoint/membership.ts` — **corrected 2026-09-21**, was `www/resources/js/external/membership/`.
  That location was `www`-only and contradicted [06-registry-distribution.md](../06-registry-distribution.md),
  a prerequisite for this plan, which documents `resources/js/checkpoint/` as the established
  distribution point for exactly this kind of registry-driven client code — copied into all
  nine apps (`auth`, `coordinator`, `dream_graph`, `flow`, `notes`, `pdf_editor`, `market`,
  `members`, `www`), same as `alloc_v2.ts` and `secondary-users.ts` today. See the main doc's
  §3.1 for the full correction and the new OQ-J it raises (whether `checkpoint/` should become
  a real shared package instead of nine manually-synced copies, now that a new file is being
  added to it).
- Each app's `checkpoint/index.ts` gains an export line, mirroring `secondary-users.ts`
- Backend endpoints (allocation service / retention service)
- Database migrations (if needed for retention state)

## Implementation details

### The Facade

**`Portal.membership`** — **Corrected 2026-09-21.** Implemented as `checkpoint/membership.ts`
and exported from each app's `checkpoint/index.ts` (mirroring `secondary-users.ts`), not
registered in `www`'s `external/main.ts`. See main doc §3.1.

```typescript
interface Membership {
  plan(scope?: { type: string, key: string }): { plan: number, plan_label: string, account_status: number }
  resources(): { [key: string]: ResourceMetadata }
  resource(key: string, scope?: { type: string, key: string }): ResourceHandle
}

interface ResourceHandle {
  // Forward edge
  canUse(amount?: number): { allowed: boolean, band: number, reason?: string }
  consume(amount?: number): Verdict
  release(amount?: number): Verdict
  
  // Backward edge
  state(): AllocationState
  preview(opts: { toPlan: number }): PreviewResult
  items(opts?: { band?: number }): Item[]
  move(opts: { ids: string[], toBand: number }): MoveResult
  reconcile(): AllocationState
}
```

### Server Endpoints

All under a single base path (OQ-G pending):

```
GET     {base}/plan                           → plan, label, account_status
GET     {base}/resources                      → registry keys + retention policy
GET     {base}/{resource}/state               → AllocationState
GET     {base}/{resource}/preview?to_plan=N   → PreviewResult (includes cascade)
GET     {base}/{resource}/items?band=N        → Item[]
POST    {base}/{resource}/canUse              → { allowed, band, reason }
POST    {base}/{resource}/consume             → Verdict
POST    {base}/{resource}/release             → Verdict
POST    {base}/{resource}/move                → MoveResult
POST    {base}/{resource}/reconcile           → AllocationState
```

Unknown resource → **404** (not empty state).

### Response Shapes

**AllocationState** — `{ plan, allowance, bands[], retention? }`

**Retention block** — `{ affected_bands[], purge_after, items_at_risk, amount_at_risk, can_evacuate, evacuation_headroom }`
- **`can_evacuate`** — whether the holder can rescue anything
- **`evacuation_headroom`** — room in kept bands
- **Critical use:** prevents UI offering advice the member cannot follow

**PreviewResult** — `{ from_plan, to_plan, direction, bands_retired[], bands_restored[], items_affected, amount_affected, purge_after, can_evacuate, messages[], cascade[] }`
- **`cascade[]`** — full subtree of child resources and instances that retire with it
- **Two consumers:** member's preview dialog AND billing's dunning email (OQ-4 in overview)

**MoveResult** — `{ moved[], rejected[] }` — partial success is normal; each rejection carries error code

**Item** — the "choose what to keep" list element; shape per resource (§6 in main plan)

### Error Codes

| Code | HTTP | Meaning |
|------|------|---------|
| `BAND_NOT_OWNED` | 403 | Target band above holder's plan |
| `BAND_RETIRED` | 423 | Target retired/purging — cannot receive |
| `BAND_FULL` | 413 | No room; carries `upgrade_unlocks` |
| `ITEM_TOO_LARGE` | 413 | `unit_kind: continuous` — fits no band |
| `SUSPENDED` | 423 | `account_status = 2`; writes frozen |
| `REGISTRY_UNAVAILABLE` | 503 | Fail closed |
| `RESOURCE_UNKNOWN` | 404 | Not in registry |

### Design Decisions Embedded in Contract

| # | Decision | Implication |
|---|----------|-------------|
| D1 | Registry drives both edges | Every scope type and cascade path is in `checkpoint_registry_v4.json`; code is generic |
| D2 | Inventory is authoritative | Counter is a projection; retire by moving items, not decrementing |
| D3 | Never delete on change itself | Retire (T+0) → grace (T+0..T+grace_days) → purge (T+grace_days) |
| D4 | Retired band stays readable/evacuable | Grace window is meaningful, not just a slower delete |
| D5 | Suspension freezes; does not downgrade | All bands read-only, purge clock frozen, no data loss while locked out |
| D6 | `tier_mode` per resource | Storage cumulative, others absolute; both are correct for different reasons |
| D7 | Tier read server-side always | Blocks advisory tier from suppressing client's own downgrade |
| D8 | Detection sweep + fast hooks | Missed hook does not mean member keeps what they stopped paying for |

### Scope Types (from `conventions.scope_graph`)

```
member
 ├── per_domain          (member_domains_count)
 │    └── per_store      (domain_ecommerce_stores)
 ├── per_project         (pm_projects_count)
 ├── per_graph           (dreamgraph_graphs_count)
 ├── per_document        (pdf_editor_documents_count)
 └── per_design_project  (design_flow_projects_count)
```

Cascade: retiring a scope instance retires all resources scoped beneath it, recursively.

## Verified

Contract is complete and reasoned from architecture (§2); no live implementation to verify against.

Derivations checked:
- ✓ Cascade routing (depth-first, children before parents)
- ✓ State machine (active → retired/suspended → purging → gone)
- ✓ Allowance formulas (absolute vs. cumulative per `tier_mode`)
- ✓ Partial success semantics (move returns rejected[] with codes)
- ✓ Preview accuracy (includes cascade subtree)
- ✓ Error codes cover all decision points
- ✓ Endpoints follow REST conventions (GET for reads, POST for actions)
- ✓ Identity is server-resolved from Checkpoint bearer

## Not verified

Needs implementation and live testing:

1. **OQ-A resolution** — Is the inventory or `alloc_v2` authoritative for the forward edge? This contract assumes the inventory is authoritative for retirement; the forward edge (`canUse`) may continue asking Parse or move here. Implementation decision before [../features/25-storage_files_size_kb.md](../features/25-storage_files_size_kb.md).
2. **OQ-G resolution** — One base path per resource or one per owning app? Affects endpoint routes and facade organization.
3. **OQ-H resolution** — `state()` reconciling as a side effect of `GET`. Storage does this and it's load-bearing; needs deliberate decision.
4. **OQ-I resolution** — API observes plan changes only, or can apply them? Stub assumes observe-only (billing applies, API reacts).
5. **Cascade correctness** — Scope graph walk, depth-first order, transitive closure all work as designed
6. **Partial success flow** — Move operations return rejected[] with codes; client handles partial success correctly
7. **Evacuation impossibility** — `can_evacuate: false` and `evacuation_headroom` calculations are accurate.
   **Planned 2026-09-22** — see [../10-preview-endpoint.md](../10-preview-endpoint.md): `RetentionEngine::preview()`
   turned out to already exist (built with the engine scaffold), computing exactly this, but is
   called by nothing and its own `cascade[]` field is hardcoded empty — that doc is the
   implementation plan for items 5 and 7 both.
8. **Inventory adapter contract** — Per-resource implementations (list, assign, retire, restore, purge) all implement correctly

## Follow-up

**Renumbered 2026-09-22** — the two per-resource walkthroughs below now live in
[../features/](../features/), named by their `TODO-member-resources.md` number, not a step
number; "Template Resource Checklist" is now step 04 in the main sequence.

**[../features/25-storage_files_size_kb.md](../features/25-storage_files_size_kb.md) (Storage Refactor)** — Implements the first resource (storage) against this contract, proving the shared layer can drive it. Resolves OQ-A, OQ-G, OQ-H, OQ-I in the process.

**[../features/2-domain_email_accounts.md](../features/2-domain_email_accounts.md) (Feature Email)** — Applies the contract to a second resource (email accounts), validating cascade and per-resource code structure.

**[../04-feature-template.md](../04-feature-template.md) (Template Resource Checklist)** — Defines what the remaining 27 resources must answer to get an adapter (inventory location, retire/purge action, per-band assignment logic).

**Prerequisites:**
- `checkpoint_registry_v4.json` finalized (declares retention policy, tier_mode, grace_days, inventory location, scope_graph)
- §08: Registry distribution resolved (one source of truth, resolved at runtime, not bundled)
- Retention engine scaffold (state machine, cascade walk, grace clock, preview computation)

---

**Step 03 establishes the contract that Steps 04–06 implement. The shape is final; implementation begins in 04.**
