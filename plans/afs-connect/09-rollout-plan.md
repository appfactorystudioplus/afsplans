# 09 — Rollout Plan

Phased so each phase ships something independently verifiable, and so the
feature can go live for a subset of apps before all four source apps are
done.

## Phase 0 — Prerequisites (blocking, no user-visible feature yet)

1. **dream_graph member scoping.** Add `member_reference_num` to
   `graph_projects`, backfill via brain lookup, populate on write going
   forward. ([03-database-schema.md](03-database-schema.md) §3.6). Without
   this, dream_graph cannot be a source app at all — do this first since
   it's the longest-lead item.
2. **Checkpoint feature flag registration.** Insert the
   `afs_connect` row into `checkpoint_feature_flags` (`enabled: false`
   initially) via a Checkpoint admin-panel entry or a one-off seeder in
   `apps/auth`. ([05-feature-toggle-and-billing.md](05-feature-toggle-and-billing.md) Layer 1).
3. **Plan entitlement field.** Add/extend `ProcessorPlan.features` with
   `afs_connect` in `apps/members`; mark it `true` on internal/test plans
   only for now. ([03-database-schema.md](03-database-schema.md) §3.7).
4. **Service-auth key issuance.** Generate the five
   `AFS_CONNECT_SERVICE_KEY_*` secrets and add them to each app's own
   `.env` (dev first) — see the corrected
   [06-security-and-service-auth.md](06-security-and-service-auth.md)
   §"Secrets & config location": this is a per-app `.env` entry, not
   something `apps/afsserver` provisions.
5. **Coordinator schema.** Ship the `afsconnect_*` migrations
   ([03-database-schema.md](03-database-schema.md) §3.1–3.5).
6. **Sub-user authorization SDK fix — done.** `resources/js/checkpoint/authz.ts`
   (all 8 apps) and `client-handler.ts` (the 6 sharing a baseline) were
   fixed so secondary-user roles/permissions resolve correctly instead of
   silently querying the primary-user table; Flagsmith was removed
   end-to-end (client, server config/routes, `package.json`). Detail in
   [01-current-state-audit.md](01-current-state-audit.md) §8.4. Staged via
   `git mv`/edits in each app's own repo, **uncommitted, pending review** —
   not yet deployed to any environment.
7. **Migrate off header-trust authorization — still open, blocking.** Every
   app's PHP controllers currently read `X-Coordinator-Role`/`X-App-Role`
   (a client-supplied header) instead of calling the now-fixed
   `authz.ts`-backed permission check server-side. Until this is done,
   item 6 only fixed the SDK's internal correctness — it hasn't changed
   what any controller actually enforces yet. This item is the real
   "uniform authorization" end-state and must land before afs-connect
   builds cross-app team/permission scoping on top of it, or afs-connect
   would just inherit and amplify the current gaps (§8.3):
   - `dream_graph`'s `ProjectController` has authorization fully disabled
     (`// Authorization disabled for development`) and doesn't even scope
     by member — fix as part of this migration, not separately.
   - `notes` has no team/per-note ACL at all — needs a product decision
     (should notes gain team scoping, or is member-wide access
     intentional?) before this migration can cover it fully.
   - `coordinator`/`flow` already have the closest-to-correct model
     (role header + `pm_team_members` scoping) — migrating them is lower
     risk and a good place to start and validate the pattern.
   - `pdf_editor`'s `DocumentShare` ACL is a different, valid primitive
     (resource sharing, not role-based) and should stay — this migration
     adds real role checks for coarser actions on top of it, it doesn't
     replace it.

**Exit criteria:** all five apps deployable with the new config/schema
present but entirely inert (global flag off).

## Phase 1 — Single source app, internal beta

1. Implement the internal API + webhook dispatcher in **`notes`** only
   (smallest, cleanest data model — no team/permission edge cases like
   dream_graph's owner_id gap).
2. Implement coordinator's `AfsConnectEntitlementService`,
   `NotesClient`, `AfsConnectAggregatorService`, ingest endpoint, and the
   reconciliation command — but wired to only one source.
3. Enable the global flag; leave `ProcessorPlan.features.afs_connect` false
   on all real plans; use `checkpoint_user_features` to allow-list a small
   set of internal/beta member accounts (per-user override, per
   [05-feature-toggle-and-billing.md](05-feature-toggle-and-billing.md) Layer 1) — no billing exposure yet, no public plan
   changes.
4. Ship the Members settings panel (master toggle + Notes-only source
   toggle) and coordinator's overview dashboard + search, scoped to notes
   data.

**Exit criteria:** a handful of internal accounts can turn afs-connect on,
see their notes summarized/searchable in coordinator, turn it off, and the
webhook + reconciliation paths are both observed working in staging.

## Phase 2 — Remaining source apps

1. Add `flow` (small surface area — one model, `flow_projects`).
2. Add `pdf_editor` (`pefeditor_documents`/`pefeditor_document_versions`,
   including deciding what "content_preview" means for a PDF — likely
   extracted text/outline, not a rendered page, in v1).
3. Add `dream_graph` last (largest surface area, most entity types —
   projects/graphs/nodes/assets/templates/etc.; decide up front which
   dream_graph entity *types* are worth surfacing at all in v1 versus just
   `graph_projects` as the unit — recommend starting with projects only and
   expanding based on what the Insights screens actually need).
4. Each addition follows the same pattern: internal API + webhook
   dispatcher in that app, a `{App}Client` in coordinator, added to the
   aggregator's fan-out.

**Exit criteria:** all four source apps ingesting; reconciliation sweep
covers all of them; dashboard/search functionally complete per
[08-frontend-ux.md](08-frontend-ux.md).

## Phase 3 — Linking

1. `afsconnect_links` CRUD + coordinator UI ("Linked items" panel on
   projects/tasks, "Add link" search-and-attach flow).
2. `afsconnect_activity_log` entries for link/unlink events feed the
   activity timeline.

**Exit criteria:** a project or task in coordinator can be linked to items
in all four other apps and the links survive source-item edits/renames
(index upsert keeps title/url current; link itself is keyed on stable
`source_id`).

## Phase 4 — Insights / analytics

1. Ship the read-only computed views described in
   [08-frontend-ux.md](08-frontend-ux.md) §"Insights" (undocumented work,
   orphaned recent items, per-team activity breakdown).
2. Add caching (short TTL, per member) if these queries prove expensive at
   real data volumes — measure before optimizing.

**Exit criteria:** insights views load in acceptable time (define an SLO,
e.g. p95 < 1.5s) for the largest internal test account.

## Phase 5 — Billing enablement

1. Flip `ProcessorPlan.features.afs_connect = true` on the actual paid
   plan(s) that should include it.
2. Implement `SyncAfsConnectEntitlement` webhook listener in
   `ProcessorWebhookController` (auto-disable on downgrade —
   [05-feature-toggle-and-billing.md](05-feature-toggle-and-billing.md) Layer 2).
3. Remove/relax the `checkpoint_user_features` beta allow-list once general
   availability is confirmed working.
4. Update marketing/plan-comparison surfaces in `apps/www` (out of scope
   for this plan's code changes, but flagged as a coordination dependency).

**Exit criteria:** a real (test-mode) subscription upgrade/downgrade
correctly flips the feature on/off end-to-end, verified against the test
matrix in [05-feature-toggle-and-billing.md](05-feature-toggle-and-billing.md).

## Phase 6 — Hardening & observability

1. Alerting on: webhook failure-rate per source app, reconciliation sweep
   duration/failures, entitlement-check latency, service-auth rejection
   spikes (possible key issue or attack).
2. Load-test the reconciliation sweep against a synthetic large account
   (worst-case dream_graph project count) to validate the 15-minute cadence
   holds.
3. Document the `AFS_CONNECT_SERVICE_KEY_*` rotation runbook (each app's
   own `.env`, per §"Secrets & config location" in
   [06-security-and-service-auth.md](06-security-and-service-auth.md)) —
   this is app-level ops documentation, not an `apps/afsserver` concern.
4. Confirm `afsconnect_activity_log` pruning job is scheduled
   ([03-database-schema.md](03-database-schema.md) §3.4).

## Rollback plan (any phase)

Because every layer in
[05-feature-toggle-and-billing.md](05-feature-toggle-and-billing.md) is
additive (new tables, new endpoints, new columns with safe defaults;
nothing in this plan modifies existing coordinator/notes/dream_graph/flow/
pdf_editor behavior for members who never enable it), rollback at any phase
is:

1. Flip the global `checkpoint_feature_flags.afs_connect` flag to `false`
   — immediately stops all reads/writes across every app (Layer 1 is
   checked first, everywhere).
2. Pause the queue workers processing `SendAfsConnectWebhookJob` /
   `ProcessAfsConnectWebhookJob` / the reconciliation schedule if a full
   code rollback is also needed.
3. No destructive migration is required to roll back — the `afsconnect_*`
   tables and the `dream_graph.graph_projects.member_reference_num` column
   are additive and inert when the feature is off; they can be left in
   place between a rollback and a fixed re-deploy.
