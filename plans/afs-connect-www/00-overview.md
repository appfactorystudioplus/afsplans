# 00 — Overview

## Problem statement

Members can create one or more **websites** from the members dashboard (`apps/members`). Each website is a full **www tenant deployment** — own nginx vhost, own MySQL database, own copy of the portal (builder, forms, direct payments, etc.), identified by **root domain** (`example.com`, served as `www.example.com`).

The existing [afs-connect](../afs-connect/README.md) plan aggregates data from four **shared-DB** creative apps into coordinator. **www is explicitly out of scope** in that plan (`00-overview.md`: *"apps/www — Not directly involved beyond brain identity resolver"*).

Product need: coordinator should show **website portal data** across all of a member's domains — forms, payments, sponsorships, appointments, subscriber reach, analytics trends, and (as stubs until complete) e-commerce and events — searchable, linkable to PM projects/tasks, and gated by the same billing/toggle model.

## Why www is fundamentally different

| Dimension | Four creative apps | www (portal modules) |
|-----------|-------------------|------------------|
| Database | One shared MySQL; `{app}_*` prefixed tables | **Central Brain DB** + **one DB per tenant website** |
| Member scoping | `member_reference_num` on domain rows | Tenant isolation = separate DB; portal tables often **lack** `member_reference_num` |
| Multiplicity | One logical app per member account | **N websites per member**, each with own portal modules |
| Identity key | `member_reference_num` | `member_reference_num` + **`domain_name`** / `website_reference_num` |
| Runtime | Single Laravel deploy per app | **Brain** (central) + **N tenant portal** instances |
| Deep links | Fixed `*.appfactory.studio` subdomains | Member **custom domains** (`https://www.{domain}/...`) |
| Existing cross-app pattern | Shared DB reads (teams), internal API (planned) | **Brain HTTP API** via `BRAIN_API_URL` in each app's `.env` |

Trying to bolt www onto the four-app model without addressing multi-tenant DB isolation will fail. This extension introduces a **Brain meta-provider** layer.

> **As implemented (2026-08-23).** The meta-provider layer shipped, but **not**
> cross-tenant database fan-out. afs-connect reads module data through Laravel's
> **default connection on whichever deployment is running** — the Brain central DB
> on Brain, a site's own tenant DB on a tenant portal. This is the model
> [07-www-env-variables.md](../07-www-env-variables.md) documents ("No
> `AFS_CONNECT_TENANT_DB_*` variables") and the one `TenantDatabaseResolver`
> implements. Per-tenant connection opening from Brain was never built — not for
> want of credentials (they are in `brain_http_server_tenants.json`) but because
> the connection plumbing was never added. See
> [complete/04-rollout-plan.complete.md](complete/04-rollout-plan.complete.md).

## Goals

1. Coordinator can **view metadata** for portal modules across **all** of a member's websites (or filter to one domain).
2. Coordinator can **link** PM projects/tasks to a specific website entity (form, direct payment, sponsorship, appointment, etc.).
3. Coordinator can **analyze** cross-website activity: submissions, invoices, sponsorship applications, bookings, subscriber counts, analytics rollups, per-domain breakdowns.
4. Reuse afs-connect **entitlement layers** (global flag, plan, member toggle) with additional granularity:
   - Per-module toggles (forms, direct payments, sponsorships, appointments, subscribers, analytics, e-commerce, events — each independently opt-out).
   - Per-website: member can exclude specific domains from aggregation (privacy / client separation).
5. **Defense in depth**: Brain re-checks entitlement; tenant portal never trusts coordinator alone.
6. Index stores **metadata only** — not submission bodies, subscriber emails, booking PII, analytics visitor IDs, or processor secrets.
7. **Incomplete modules** (e-commerce checkout, events legacy CRUD) ship as **stubs** — DB placeholders + stub providers returning summary counts until promoted to full index (see [05-modules-reference.md](05-modules-reference.md)).

## Non-goals (v1)

- **Not** indexing the full GrapesJS builder, documents module, listings, feedback, chat, or every www feature.
- **Not** indexing every individual form submission, booking, or analytics event as a searchable index row.
- **Not** full e-commerce order history or events ticket sales until checkout / Laravel events CRUD are complete (stubs only).
- **Not** replacing Brain provisioning or the members Domains UI.
- **Not** merging tenant DBs into the shared apps DB.
- **Not** real-time sync of portal content into coordinator for editing.
- **Not** exposing database credentials to coordinator. (As implemented, no component opens another deployment's tenant DB at request time — each reads its own default connection.)

## Glossary

| Term | Meaning |
|------|---------|
| **Brain** | Central `apps/www` control-plane instance (`brain_*` tables, `/portal/api/v1/brain/*`). |
| **Tenant portal** | Per-website deployed copy of www portal code + `{systemuser}_db`. |
| **Website** | One deployed site; row in `members_websites` + `brain_http_server_tenants`. |
| **domain_name** | Root domain string, e.g. `example.com` (canonical website key for UI). |
| **website_reference_num** | Stable ID from `members_websites.reference_num`. |
| **tenant_reference_num** | Brain tenant ID (`brain_http_server_tenants.reference_num`). |
| **webbuilder_reference_num** | Link to `laravel_builder_projects.reference_num` inside tenant DB. |
| **Form** | Row in tenant `laravel_forms` (definition). |
| **Direct payment (DP)** | Row in tenant `laravel_direct_payments` (product/checkout config). |
| **Meta-provider** | Brain service that aggregates tenant DB reads behind one internal API. |
| **Tier A / B / C** | Module maturity for indexing: full entity / aggregate only / stub ([05-modules-reference.md](05-modules-reference.md)). |

## Relationship to base afs-connect

This is an **extension**, not a fork:

- Same coordinator `afsconnect_*` tables (extended columns).
- Same HMAC service auth ([06-security](../afs-connect/06-security-and-service-auth.md)).
- Same ingest webhook + reconciliation pattern ([07-sync](../afs-connect/07-sync-and-indexing-strategy.md)).
- New `source_app` values: `www_forms`, `www_direct_payments`, `www_sponsorships`, `www_appointments`, `www_subscribers`, `www_analytics`, `www_ecommerce`, `www_events` — see [05-modules-reference.md](05-modules-reference.md).
- New peer key: `AFS_CONNECT_SERVICE_KEY_WWW` on Brain (+ tenant portal uses Brain relay or direct coordinator key — see architecture doc).

## Apps involved

| App | Role |
|-----|------|
| `apps/coordinator` | Hub — extended index, search filters, linking, analytics |
| `apps/www` (Brain) | **Meta–source app** — tenant registry, cross-tenant DB reads, internal API |
| `apps/www` (tenant portal) | Webhook origin on module lifecycle events; stub listeners for Tier C modules |
| `apps/members` | Website registry (`members_websites`); settings UI for per-website toggles; entitlements |
| `apps/auth` | Unchanged — `afs_connect` feature flag |
| Other four creative apps | Unchanged |

## Database changes in `apps/www`

www has **not** been migrated to production yet. All www schema work for this extension follows a dedicated convention — **edit the original create migration files; do not add alter migrations.** Every change is also recorded as raw SQL in **[db_table_alter.md](db_table_alter.md)**. Coordinator and members continue to use normal Laravel alter migrations (see [03-schema-and-api.md](03-schema-and-api.md) §3.1).

## Environment configuration

The Brain control-plane URL is **`BRAIN_API_URL` in each app's own `.env`** — required for coordinator, all four creative apps, members, and www. No hardcoded Brain hosts. See **[06-environment-config.md](06-environment-config.md)**.
