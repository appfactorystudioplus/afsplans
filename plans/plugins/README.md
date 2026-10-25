# Plugins — Planning Index

**A WordPress-shaped third-party plugin system for the appfactory studio admin dashboard**
(`w/www`, the Laravel app also referred to elsewhere in this estate by its control-plane role —
this plan does not use that internal codename; see [00-overview.md](00-overview.md) §0 for why).
External developers write plugins, submit them to a marketplace, and any tenant can browse,
install, configure and remove them from a new **Plugins** item in the admin sidebar — without a
deploy, a rebuild of the core admin bundle, or the developer ever touching this repository.

```
 developer's plugin.zip
        │  upload
        ▼
 ┌─────────────────────┐   scan + review   ┌────────────────────┐   signed release   ┌───────────────┐
 │  Submission intake   │ ────────────────▶ │  Review pipeline   │ ──────────────────▶│  Marketplace   │
 │  (10)                │                   │  (10)              │                    │  catalog (03)  │
 └─────────────────────┘                    └────────────────────┘                    └───────┬────────┘
                                                                                                │ browse / install
                                                                                                ▼
                                                                            ┌──────────────────────────────────┐
                                                                            │      Tenant admin dashboard       │
                                                                            │  Plugins nav (09) → install (07)  │
                                                                            └───────────┬───────────┬──────────┘
                                                                                        │           │
                                                              backend hook/API (05, 06) │           │ frontend bundle,
                                                              inside the Plugin Runtime │           │ dynamically imported,
                                                              sandbox — never in-process│           │ iframe-sandboxed (08)
                                                                                        ▼           ▼
                                                                            ┌────────────────┐ ┌────────────────┐
                                                                            │ Plugin Runtime  │ │  Plugin UI      │
                                                                            │ (isolated)      │ │  (isolated)     │
                                                                            └────────────────┘ └────────────────┘
```

> ### Read this before anything else
>
> - **This is a planning document set. Nothing in it has been built.** No code, migration, or
>   config change described here exists yet — every file describes an intended design, not
>   current behavior. See [01-current-state-audit.md](01-current-state-audit.md) for what
>   actually exists today.
> - **The user chose the highest-risk option on purpose, with controls attached.** Plugins get
>   real backend PHP execution via a runtime zip upload — not a curated Composer-package model,
>   not a frontend-only widget system. Every other document in this set exists to make that
>   safe: sandboxed execution, capability-scoped APIs, mandatory review, and a kill switch are
>   not optional hardening to add later — they are the reason this design looks the way it does.
>   Read [05-security-and-sandboxing.md](05-security-and-sandboxing.md) first if you read only
>   one file besides this index.
> - **Terminology:** do not use "Brain" in this plan or in any code, UI copy, or developer-facing
>   documentation it produces. That word names this app's *internal* role among appfactory
>   studio's own ten repositories and means nothing to a plugin developer or a tenant. This plan
>   calls the app **the admin dashboard** or, where the distinction matters, **the platform**;
>   see [00-overview.md](00-overview.md) §0.
> - **Scope is one deployment for now, designed to generalize.** Every tenant that this admin
>   dashboard instance serves can install from the marketplace from day one — that is the point
>   of the feature. What is *not* in scope for the first delivery is running this same system
>   unmodified across appfactory studio's other nine repositories; see
>   [00-overview.md](00-overview.md) §3 non-goals.

## Steps

| Step | Document | Contents |
|---|---|---|
| — | [README.md](README.md) | This index |
| 00 | [00-overview.md](00-overview.md) | The problem, terminology, goals, non-goals, glossary, the decisions, open questions |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | What exists today: the admin frontend's architecture, the static `portal_permissions.php`, the builder's module registry, `AfsConnect`, `external/api`'s `portal.d.ts` contract, the Vite build — and why none of it is a plugin system |
| 02 | [02-architecture-overview.md](02-architecture-overview.md) | The system's components and how they fit together: marketplace catalog, review pipeline, plugin runtime, frontend host, admin UI — with the request flows for install, activate, and invoke |
| 03 | [03-database-schema.md](03-database-schema.md) | Every new table: the marketplace catalog, per-tenant installs and grants, plugin-owned data tables, audit log — all `plugin_*`-prefixed, all owned by this app's migrations per AGENTS §5 |
| 04 | [04-manifest-and-package-format.md](04-manifest-and-package-format.md) | The plugin package a developer builds: `plugin.json` manifest, zip layout, versioning, the signature that makes a marketplace release the only thing ever executed |
| 05 | [05-security-and-sandboxing.md](05-security-and-sandboxing.md) | **The load-bearing document.** Trust boundaries, execution isolation for backend PHP, the capability-scoped API a plugin actually gets, frontend iframe sandboxing, resource limits, the kill switch |
| 06 | [06-extension-points-and-hooks.md](06-extension-points-and-hooks.md) | The catalog of hooks and UI slots a plugin can subscribe to or render into, on both backend and frontend, and how that catalog is versioned so it can grow without breaking installed plugins |
| 07 | [07-installation-and-activation-lifecycle.md](07-installation-and-activation-lifecycle.md) | The state machine from "submitted" to "installed and active" on a tenant, permission consent, plugin-scoped migrations, updates, disable, uninstall and data retention |
| 08 | [08-frontend-runtime-and-dynamic-loading.md](08-frontend-runtime-and-dynamic-loading.md) | How a plugin's TypeScript bundle reaches the browser and runs: independent builds, integrity-checked dynamic `import()`, the iframe host bridge, the `plugin-host` contract (this plan's `portal.d.ts` equivalent) |
| 09 | [09-admin-dashboard-ux.md](09-admin-dashboard-ux.md) | The **Plugins** sidebar item: browse/marketplace, installed, permission-consent screen, per-plugin settings, updates, the developer portal for submitting a plugin, the activity/audit viewer |
| 10 | [10-review-and-publishing-pipeline.md](10-review-and-publishing-pipeline.md) | Everything between "developer clicks submit" and "tenants can install it": automated static analysis, dependency scanning, manual review, staged rollout, revocation, abuse reporting |
| 11 | [11-permissions-and-tenant-model.md](11-permissions-and-tenant-model.md) | How a plugin's requested permissions extend the existing static `config/portal_permissions.php` model without breaking it, and how per-tenant portal users get scoped access to a plugin's screens |
| 12 | [12-billing-and-monetization.md](12-billing-and-monetization.md) | Pricing models for a plugin (free now; paid, subscriptions and revenue share later) — deliberately built on the `market` plan's existing Stripe Connect work rather than a second payments stack |
| 13 | [13-observability-and-incident-response.md](13-observability-and-incident-response.md) | Per-plugin logging and metrics, automatic circuit-breaking on a misbehaving plugin, and the runbook for a plugin reported malicious or found abusing its grants |
| 14 | [14-rollout-and-testing.md](14-rollout-and-testing.md) | Delivery order and dependency graph across steps 00–13, the internal dogfood plugins built to prove the system before any external developer sees it, acceptance criteria, and the test matrix (including deliberate sandbox-escape attempts) |
| 15 | [15-developer-scaffolding-and-local-development.md](15-developer-scaffolding-and-local-development.md) | The `laravel new`-equivalent for a plugin: a scaffold/CLI that generates a local project, a mock capability SDK and mock Plugin Host so a developer can build and test entirely on their own machine, and a packaging command that produces exactly what 04's submission format requires |
| 16 | [16-example-plugin-cookie-consent.md](16-example-plugin-cookie-consent.md) | A worked example, built through the real scaffold and pipeline: a cookie-consent toast on the published website (a new **public site slot**, [06 §2.1](06-extension-points-and-hooks.md)) with settings and acceptance stats in the admin dashboard — also where the "should a plugin get its own database" question is answered (no; an atomic counter capability instead, [05 §12](05-security-and-sandboxing.md)) |
| 17 | [17-example-plugin-order-guard.md](17-example-plugin-order-guard.md) | The other worked example: a plugin that actually ships backend PHP — a `filter` hook (`checkout.validate`) blocking a sale below a configured minimum, and a `notify` hook (`order.completed`) counting completed orders. Shows exactly what a developer writes versus what the platform's dispatcher does |

## Completion documents

Each numbered document gets a completion document in `complete/` when its work actually lands,
named after it — `05-security-and-sandboxing.complete.md`. Include status, summary, files
changed, verification steps, and follow-up work. Phases (P0, P1, …) live inside their own
step's file; there is no per-phase completion file. Work that comes up during implementation but
was never a numbered step of its own — a seeder, an off-plan fix, a small regression — is
recorded as a dated section in `complete/00_extras.md`, not a new numbered file.

## Code documentation standard

Same as every other plan in this tree: PHPDoc on every new file, class, public method and route
handler, naming the plan step it implements; JSDoc on TypeScript. Every security check names the
threat it defends against — this plan has more of those than most.

## The marker: `PLUGIN_SYSTEM_ADDITION`

This effort touches existing files that already work — `config/portal_permissions.php`,
`resources/js/portal/admin/views.ts`, `resources/js/portal/admin/main.ts`, the permissions
utilities — as well as adding many new ones. Every file this plan creates or modifies carries
`PLUGIN_SYSTEM_ADDITION`, the same way `MARKET_MODIFIER_ADDITION` and
`AFS_BUILDER_MODIFIER_ADDITION` mark their own plans' footprints elsewhere in this codebase:

- **Created** → the marker in the file's top doc block, on its own line.
- **Modified** → the marker on, or immediately above, each changed line or region. Regions
  longer than ~20 lines close with `PLUGIN_SYSTEM_ADDITION END`.

`/** PLUGIN_SYSTEM_ADDITION */` in PHP and TypeScript; `<!-- PLUGIN_SYSTEM_ADDITION -->` in
Blade/HTML. `grep -rn PLUGIN_SYSTEM_ADDITION` is the complete answer to "what did this plan
touch" for debugging, review, or a future revert.

## One-paragraph summary

Today, adding a feature to this admin dashboard means writing TypeScript that gets statically
imported into `views.ts` and compiled into one Vite bundle, and writing PHP that lives directly
in `app/`, trusted at the same level as every other line of the framework — there is no notion of
installable third-party code at all. This plan adds one: a **marketplace** where outside
developers submit plugins as a signed zip (PHP + a TypeScript frontend bundle + a manifest
declaring exactly what the plugin touches); a **review pipeline** that statically scans, and a
human reviews, every submission and every update before it is ever installable; a **Plugin
Runtime** that executes a plugin's backend code outside the main application's trust boundary,
through a narrow, capability-scoped API rather than raw framework access, so an approved plugin
still cannot reach another tenant's data, the shared database's core tables, or the filesystem
outside its own sandbox; a **frontend host** that loads a plugin's UI as an independently-built
bundle inside a sandboxed iframe, so a compromised or buggy plugin script cannot read the admin
session or the rest of the page; and a **Plugins** item in the admin sidebar where any tenant
browses the catalog, reviews exactly what permissions an install is requesting before granting
them, configures, updates, disables or removes a plugin, and can see an audit trail of what it
has actually done. Paid plugins, revenue share and cross-app distribution are explicitly later
work, built on infrastructure ([market](../market/README.md)'s Stripe Connect) this plan reuses
rather than duplicates.
