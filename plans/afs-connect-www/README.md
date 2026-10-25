# afs-connect — www (Portal Modules) — Planning Index

Extension of [afs-connect](../afs-connect/README.md) to surface **member website** data from `apps/www` into coordinator.

Unlike notes/flow/pdf_editor/dream_graph, www is **not** in the shared apps DB. Each member website is a **separate tenant deployment** with its own database, keyed by **domain name** (`example.com`). A member may own **many** websites.

## Modules in scope

| Module | Tier | afs-connect `source_app` | Notes |
|--------|------|--------------------------|-------|
| **Forms** | A — full | `www_forms` | Original scope |
| **Direct payments** | A — full | `www_direct_payments` | Original scope |
| **Sponsorships** | A — full | `www_sponsorships` | Laravel-complete |
| **Appointments** | A — full | `www_appointments` | Index objects, not booking PII |
| **Subscribers** | B — aggregate | `www_subscribers` | Per-website summary row |
| **Analytics** | B — aggregate | `www_analytics` | Daily rollups only |
| **E-commerce** | C — stub | `www_ecommerce` | Checkout incomplete; DB + provider stub |
| **Events** | C — stub | `www_events` | Legacy PHP; new `laravel_events` migration stub |

Tier definitions: [05-modules-reference.md](05-modules-reference.md).

Read in order:

| # | Document | Contents |
|---|----------|----------|
| 0 | [00-overview.md](00-overview.md) | Problem, goals, non-goals, glossary, why www is different |
| 1 | [01-current-state-audit.md](01-current-state-audit.md) | Existing www/members architecture, tables, gaps |
| 2 | [02-architecture.md](02-architecture.md) | Brain meta-provider pattern, data flows, website scoping |
| 3 | [03-schema-and-api.md](03-schema-and-api.md) | Coordinator index extensions, internal API contract, toggles |
| 4 | [04-rollout-plan.md](04-rollout-plan.md) | Phased delivery, prerequisites, test matrix |
| 5 | [05-modules-reference.md](05-modules-reference.md) | **Per-module audit: tables, routes, maturity, indexing rules** |
| 6 | [06-environment-config.md](06-environment-config.md) | **`BRAIN_API_URL` in every app's `.env`; other env vars** |
| 7 | [07-www-env-variables.md](../07-www-env-variables.md) | **www `config/afs_connect.php` — every `.env` variable explained** |
| — | [10-coordinator-env-variables.md](../_env/10-coordinator-env-variables.md) | **coordinator `config/afs_connect.php` — every `.env` variable explained** |
| — | **[db_table_alter.md](db_table_alter.md)** | **www-only DB convention: edit create migrations + log raw SQL** |

## Completion documents

Build records live in [complete/](complete/README.md). **Status: partial** — Phases
0–4 of [04-rollout-plan.md](04-rollout-plan.md) are substantially built (all eight
module providers exist; e-commerce and events are stubs by design), Phase 5 is
about half done, and tenant DB isolation diverges from docs 00/02. Details and the
verification caveat are in
[complete/04-rollout-plan.complete.md](complete/04-rollout-plan.complete.md).

## One-paragraph summary

**www Brain** (central control plane) becomes a **meta–source app** for afs-connect: it knows every member website via `brain_http_server_tenants` + `members_websites`, opens each tenant's isolated DB, and exposes a signed `/api/internal/afs-connect/v1/*` API returning normalized items for **forms, direct payments, sponsorships, appointments**, plus **aggregate summaries** for subscribers and analytics, and **stubs** for e-commerce and events until those modules are complete. Tenant portals fire signed webhooks on lifecycle events. The coordinator index uses **website dimensions** (`domain_name`, `website_reference_num`) and per-module toggles. Schema changes in www follow **[db_table_alter.md](db_table_alter.md)** — edit create migrations, no alter files.
