# afs-connect-www — completion notes

Completion records for the [afs-connect-www](../README.md) plan — the www portal-module
extension of [afs-connect](../../afs-connect/README.md).

| Doc | Completion | Status |
|-----|------------|--------|
| [00-overview.md](../00-overview.md) | — | Framing; no code |
| [01-current-state-audit.md](../01-current-state-audit.md) | — | Audit; no code |
| [02-architecture.md](../02-architecture.md) | — | Design; realised by the phases in doc 04 |
| [03-schema-and-api.md](../03-schema-and-api.md) | — | Contract; realised by the phases in doc 04 |
| [04-rollout-plan.md](../04-rollout-plan.md) | [04-rollout-plan.complete.md](04-rollout-plan.complete.md) | 🟡 **Partial** — Phases 0–4 substantially built, Phase 5 about half |
| [05-modules-reference.md](../05-modules-reference.md) | — | Per-module reference; module state recorded in doc 04's completion |
| [06-environment-config.md](../06-environment-config.md) | — | Env reference |

## Why there is only one completion document

This plan's work was **built without completion notes**. The record in
[04-rollout-plan.complete.md](04-rollout-plan.complete.md) was reconstructed on
2026-08-23 by reading the code, not written at build time — so it is filed against
the document that defines the phases (`04-rollout-plan.md`) rather than split into
per-phase files that never existed.

## Where the www build steps are recorded

The plans tree previously had a single root-level `completed/` folder holding 14
chronological build steps. Every one of those steps declares `apps/coordinator`,
the four creative source apps, `apps/members`, or `apps/auth`/`apps/www` (for the
Brain admin surface) as its repo, and **none documents the www provider layer**.
They now live in [../../afs-connect/complete/](../../afs-connect/complete/README.md).

Two of them carry slices of *this* plan and are worth reading from here:

| Step | www slice |
|------|-----------|
| [07-brain-admin-afs-connect.md](../../afs-connect/complete/07-brain-admin-afs-connect.md) | The Brain admin afs-connect tab, including the eight website-module toggles and the disabled-website-domains list |
| [13-webhooks-ingest.md](../../afs-connect/complete/13-webhooks-ingest.md) | §"www (phase 5 — forms)" — `WwwAfsConnectIngestDispatcher`, `WwwAfsConnectTenantFromRequest`, and the `FormsController` hook |

They were not moved here because a document cannot live in two folders, and their
primary subject is the coordinator/creative-app build.

## Headline findings

- **All eight module providers exist.** Forms, direct payments, sponsorships,
  appointments, subscribers, and analytics are non-stub; e-commerce and events
  are stubs, which is exactly what the plan specifies for Tier C.
- **Per-website UI was never built.** `WwwBrainClient::websites()` has no caller
  and `AfsConnectWebsiteSyncStatus` is written but never read.
- **Webhooks reach forms only** — the other three Tier A modules stay
  reconcile-only.
- **Tenant DB isolation — settled 2026-08-23.** The plan contradicted itself:
  docs 00/02 described Brain opening per-tenant databases, while
  [07-www-env-variables.md](../../07-www-env-variables.md) stated afs-connect uses
  the default connection and that no `AFS_CONNECT_TENANT_DB_*` variables exist.
  `TenantDatabaseResolver` follows the latter. **Decision: keep the
  deployment-scoped model; docs 00 and 02 were corrected. No code changed.**
  (An earlier draft claimed per-tenant fan-out was impossible for want of
  credentials. That was wrong — they live in `brain_http_server_tenants.json` as
  `db_host` / `db_name` / `db_username` / `db_password`. Fan-out is feasible; it
  simply was not built.)
  The open consequence — whether reconcile yields any www rows at all — is item 9
  in the completion document.

## Correctness findings (2026-08-23)

Three compounding defects prevent the plan's headline use case from working today.
Full detail in the completion document's **Correctness findings** section.

| # | Finding |
|---|---------|
| **F1** | **Submission counts are always zero.** `laravel_form_submissions` is read by afs-connect and written by nothing; real submissions go to legacy `form_submissions` / `afsform_submissions` |
| **F2** | **Providers never filter by website**, despite `laravel_forms` carrying `website_domain` / `website_reference_num` — those fields are stamped onto output, not used to scope the query |
| **F3** | **On Brain the default connection is `testdb_appfactorystudio`**, so F2 means providers return appfactory.studio's own rows stamped with each member website's identity |

The **tenant → coordinator webhook** is the only architecturally sound path — it runs
on the tenant against its own database — but it covers forms only, and fires on
admin form create/update rather than on a visitor submission.

## Verification caveat

Everything recorded is **static code inspection**. No Brain instance, tenant
deployment, or database was available, so none of the plan's exit criteria have
been observed running.
