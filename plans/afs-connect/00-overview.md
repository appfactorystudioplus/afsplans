# 00 — Overview

## Problem statement

The five AFS product apps (`coordinator`, `notes`, `dream_graph`, `flow`,
`pdf_editor`) are independent Laravel applications that happen to share one
physical MySQL database (each app's migrations create tables prefixed with
the app name, e.g. `pm_*`, `notes_*`, `pefeditor_*`). Today a member's work
is siloed: a project in coordinator has no awareness of the notes, graphs,
drawings, or PDFs that relate to it, even though they all belong to the same
member account.

`afs-connect` closes that gap. It is a **coordinator-hosted, cross-app
analysis feature**: coordinator can view, search, and link to a member's
data in the other four apps, and surface aggregate analytics ("you have 12
overdue tasks with no linked notes", "this project references 4 PDFs and 2
graphs", "team activity across all tools this week"). It is a premium
capability that must be sellable, i.e. it must be possible to turn on or off
per pricing plan and per member without a code deploy.

## Goals

1. Coordinator can **view** metadata (and, on demand, fetch full content) for
   a member's notes, graphs, flow drawings, and PDF documents.
2. Coordinator can **link** its own entities (projects, tasks) to items in
   the other four apps, and show those links in both directions where
   feasible.
3. Coordinator can **analyze** cross-app data: activity timelines, per-app
   counts, staleness/coverage insights, and search across all five apps from
   one place.
4. The whole feature is **billable and toggleable**:
   - A global kill switch (ops/incident control).
   - A pricing-plan entitlement (only certain plans include it).
   - A per-member on/off switch, owned by the member in their Members
     dashboard.
   - A per-source-app data-sharing switch (a member can enable afs-connect
     but exclude, say, `notes` from being visible to coordinator).
5. No source app's internal schema becomes coordinator's problem. Each app
   owns and versions its own data contract.
6. Every cross-app read is re-authorized at the source app, not just trusted
   because it came from coordinator ("defense in depth" — see
   [06-security-and-service-auth.md](06-security-and-service-auth.md)).

## Non-goals (v1)

- **Not** building a generic event bus / message broker for the whole
  platform. afs-connect uses simple signed HTTP webhooks scoped to its own
  concern; a broader eventing platform is a separate initiative.
- **Not** giving the other four apps write access into coordinator's data
  (this is coordinator *reading about* the others, not a two-way sync
  engine). Linking creates a reference record, not a data copy loop.
- **Not** re-architecting authentication. afs-connect reuses the existing
  Checkpoint IdP (`apps/auth`) and the existing member-resolution pattern
  (`ResolvesMember` / `FunctionsController::resolveMemberReferenceNum`)
  unchanged.
- **Not** exposing raw document/note/graph content through the aggregate
  index. The index stores metadata + short snippets only; full content is
  fetched live, per-request, with normal per-app authorization.
- **Not** solving real-time collaborative sync between apps (e.g. editing a
  note from inside coordinator). v1 is read + link + analyze.

## Glossary

| Term | Meaning |
|---|---|
| **Member** | An account holder managed in `apps/members` (its own database), identified by `reference_num`. This is the billing/ownership unit. |
| **Member reference num** | `member_reference_num` — the string every app-shared-DB table uses to scope rows to a member account. Resolved from a Checkpoint user id via the "brain" API (`apps/www` / `apps/members` `/member` endpoint). See [01-current-state-audit.md](01-current-state-audit.md). |
| **Checkpoint** | The IdP in `apps/auth` (`CHECKPOINT_DOCUMENTATION.md`). Issues JWTs, hosts SSO, and already has a feature-flag + resource-allocation system (`checkpoint_feature_flags`, `checkpoint_user_features`, `checkpoint_allocations`). |
| **Secondary user** | A sub-user under a member account, managed via Checkpoint's multi-tenant secondary-user system, scoped by `app_name` + `super_user_id`. |
| **Source app** | One of `notes`, `dream_graph`, `flow`, `pdf_editor` — an app whose data afs-connect reads. |
| **Hub app** | `coordinator` — the app that aggregates and presents cross-app data. |
| **Index** | Coordinator's local, metadata-only cache of items that exist in the source apps (`afsconnect_index` table). |
| **Entitlement** | The combination of global flag + plan + member toggle + source-app toggle that determines whether a given request is allowed to proceed. |

## Apps involved and their roles

| App | Role in afs-connect |
|---|---|
| `apps/auth` | Identity, JWT/SSO, global feature-flag store (`checkpoint_feature_flags`), per-user override store (`checkpoint_user_features`). No new tables here beyond registering the `afs_connect` feature key. |
| `apps/members` | Owns the member account, sub-users, and billing/plan/subscription records (`ProcessorPlan`, `ProcessorSubscription`). Owns the **member-facing settings UI** for turning afs-connect on/off. Does not store afs-connect data itself (separate DB) — it calls coordinator's internal settings API. |
| `apps/coordinator` | The hub. Owns the `afsconnect_*` tables, the aggregation/index/search logic, the linking feature, the analytics dashboard, and the member-facing "AFS Connect" screen inside the PM app. |
| `apps/notes`, `apps/dream_graph`, `apps/flow`, `apps/pdf_editor` | Source apps. Each adds a small, self-contained `AfsConnect` module: an internal read API, an entitlement guard, and event listeners that fire ingest webhooks to coordinator. |
| `apps/afsserver` | Not a runtime participant and not involved in any app's configuration — these are ordinary Laravel apps, each configured entirely through its own `.env`. `afsserver` only hosts this plan. |
| `apps/www` | Not directly involved beyond being the existing "brain" identity resolver that all apps already call. |
