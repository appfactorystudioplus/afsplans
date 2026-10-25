# 00 — Overview

## Completion document

When this plan step is completed, write `complete/00-overview.complete.md` with status,
summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code with block comments naming the plan step. Every security
check names the threat it defends against.

---

## 0. Terminology and deployment model — read this first, corrected 2026-09-27

**This is not a multi-tenant SaaS application.** Earlier drafts of this plan modeled "a tenant" as
a row in a shared database, distinguished by a `tenant_reference_num` column, all served by one
running instance — the ordinary shape of a multi-tenant web app. That was wrong, and it was wrong
in a way that shaped the database schema, the security model, and the runtime design throughout
this plan. The actual model, corrected here and propagated through every document that assumed
otherwise:

- **A "tenant" is one independently deployed website** — its own running copy of this
  application (`w/www`), its own database, its own filesystem. Per
  [AGENTS.md §5](../../../w/AGENTS.md): "the Brain provisions a member website by deploying a
  **duplicate of itself**." A member's site is not a logical partition of one shared app; it is a
  separate instance of the same codebase, with a database that starts as a full copy of the
  schema and is genuinely used only for that one site's own content (`laravel_*`-prefixed tables).
- **Tenants share no runtime, no database, and no mechanism to know about each other.** There is
  no query, no table, no API call within a tenant's own deployment that could see another
  tenant's data — not because it's forbidden, but because it isn't reachable. A tenant's own
  `plugin_installs`, `plugin_data`, and every other install-time table this plan defines therefore
  need **no tenant-identifying column at all** — within one deployment's own database, there is
  exactly one tenant, implicitly, always.
- **One thing genuinely is shared: the marketplace.** A catalog any tenant can browse, developer
  accounts, review, and signing cannot live inside each tenant's own isolated database — nobody
  would be browsing the same catalog. This is centrally hosted, and this ecosystem already has a
  precedent for exactly that shape: the Brain (this same `www` codebase, running as the one
  instance that is the control plane) already hosts centralized state for every independently
  deployed app and pushes/accepts pulls over a signed channel
  ([www_brain 10](../www_brain/10-brain-owned-config-and-push.md)). This plan's marketplace reuses
  that same pattern rather than inventing a second one — see §8 below.
- **This does not mean every tenant deployment must poll a shared central table** the way a
  multi-tenant app's request handler would. Marketplace *browsing* and *installing* are a tenant's
  own deployment reaching out, on its own initiative, to the central catalog — the same shape as
  an app pulling its Brain-owned settings today.

Terms this plan uses, corrected:

| Term | Means |
|---|---|
| **the platform** | This application's codebase, `w/www` — what a plugin developer builds against, and what a plugin's backend/frontend code actually runs inside, in whichever tenant's deployment installed it |
| **a tenant** | One independently deployed, independently databased instance of the platform — one member's website. Never a row; always a separate running application |
| **the marketplace** | The centrally hosted catalog, review pipeline, and signing service, reachable by every tenant deployment over a signed channel — hosted on the Brain per this ecosystem's existing precedent, exposed to a developer or tenant only as "the marketplace," never by that internal name |

This admin dashboard's own internal name for itself — used throughout
[AGENTS.md](../../../w/AGENTS.md) and the rest of this plans tree for the app's role among
appfactory studio's ten repositories — is meaningless, and actively confusing, to a third-party
plugin developer or a tenant's own admin. Nowhere in this plan, in plugin-facing documentation, in
the marketplace UI, in error messages, or in developer-facing API docs does that word appear.
Internally, in code comments and in cross-references to other plan folders in this tree (which
already use it, including the one this plan now builds directly on, §8), it is unavoidable — but
it never crosses into anything a developer or tenant reads.

## 1. The problem

Every feature this admin dashboard has today — ecommerce, forms, events, appointments, the page
builder — is first-party: written in this repository, reviewed by whoever reviews this
repository, compiled into the one admin Vite bundle, and deployed the same way as everything
else. [01-current-state-audit.md](01-current-state-audit.md) confirms there is no mechanism for
anyone outside this repository to add a feature, no registry of what is installed where, and no
notion of a tenant enabling or disabling something that isn't already one of the fifteen modules
`config/portal_permissions.php` hardcodes.

That is the WordPress plugin problem, faced by every extensible platform: **let people who do
not work on the core product add real functionality to it, without giving every one of them the
keys to the whole application.** WordPress's own answer — any plugin runs as PHP with the same
privileges as WordPress core itself — is also its most-cited weakness; the majority of WordPress
security incidents are compromised or malicious third-party plugins, not WordPress core. This
plan's brief, stated directly during scoping, is to build the WordPress *experience* — install a
package, get real backend functionality, no deploy — while treating that specific failure mode
as the design's central problem to solve, not an accepted cost of the model.

## 2. Goals

| # | Goal | Measured by |
|---|---|---|
| G0 | **A tenant's admin dashboard gets a Plugins sidebar item** where they browse a marketplace, install, configure, update and remove plugins without any deploy or rebuild | [09](09-admin-dashboard-ux.md) |
| G1 | **A plugin can ship real backend PHP** — its own database tables, its own scheduled jobs, its own reaction to platform events — not just a UI widget | [02](02-architecture-overview.md), [06](06-extension-points-and-hooks.md) |
| G2 | **No plugin, compromised or malicious, can read or modify data outside its own tenant's deployment, the platform's core tables, or the filesystem outside its own sandbox** | [05](05-security-and-sandboxing.md); acceptance X1–X8 |
| G3 | **Every plugin a tenant can install has already been through the same review and signing pipeline.** A raw, unreviewed upload is never directly executable by any tenant | [10](10-review-and-publishing-pipeline.md) |
| G4 | **A tenant sees exactly what a plugin is asking for before granting it**, in the same spirit as a mobile app permission prompt, and can revoke it later | [07](07-installation-and-activation-lifecycle.md) §3, [11](11-permissions-and-tenant-model.md) |
| G5 | **A plugin's frontend code loads without rebuilding the core admin bundle** — installing a plugin is a data change (a new manifest row), not a `npm run build` of this repository | [08](08-frontend-runtime-and-dynamic-loading.md) |
| G6 | **A misbehaving plugin can be stopped across every independently deployed tenant that installed it, in minutes**, via the same push-first/pull-guarantees channel [www_brain](../www_brain/10-brain-owned-config-and-push.md) already uses — not by asking each tenant to uninstall it individually | [05](05-security-and-sandboxing.md) §7, [13](13-observability-and-incident-response.md) |
| G7 | **The developer side is a real submission workflow** — an account, a package format, automated and human review, versioning — not an informal "email us a zip" process | [04](04-manifest-and-package-format.md), [10](10-review-and-publishing-pipeline.md) |
| G8 | **Paid plugins are possible later without a second payments integration**, by reusing [market](../market/README.md)'s Stripe Connect work | [12](12-billing-and-monetization.md) |
| G9 | **A developer can build and test a plugin entirely on their own machine** — a scaffold command, a mock of the capability SDK and Plugin Host, a local lint mirroring the review pipeline — before ever submitting anything | [15](15-developer-scaffolding-and-local-development.md) |

## 3. Non-goals

- **Running this system, unmodified, on the other nine appfactory studio repositories.** This
  plan is scoped to the one admin dashboard app. A well-designed system here could extend later,
  but per-repository activation, cross-app distribution, and a single shared marketplace across
  the whole estate are explicitly out of scope for the first delivery — see the scope decision
  recorded during initial scoping, restated as **D2** below.
- **A general-purpose sandboxed-code product.** This plan builds exactly the isolation this one
  feature needs (§5) — not a reusable "run untrusted PHP" service for other parts of the
  business.
- **Paid plugins, revenue share, or a plugin storefront checkout flow in the first delivery.**
  [12](12-billing-and-monetization.md) designs the model; it is explicitly not built until the
  free-plugin path (G0–G7) is proven. See that document's own scope note.
- **Automatic, unattended acceptance of any submission.** Every plugin and every version update
  goes through the review pipeline in [10](10-review-and-publishing-pipeline.md); there is no
  "trusted developer" fast path in the first delivery, even though the pipeline is designed to
  support one later (§5 open question in that document).
- **Replacing the existing static module system** (ecommerce, forms, events, …). Those stay
  exactly as they are — first-party code, compiled into the core bundle, governed by
  `config/portal_permissions.php`. This plan adds a second, parallel system for third-party code;
  it does not migrate the first one onto it.
- **A fully general plugin-to-plugin dependency system.** A plugin can depend on the platform's
  own extension-point contract version ([06](06-extension-points-and-hooks.md) §4); one plugin
  depending on another plugin being installed is not supported in the first delivery.

## 4. Glossary

| Term | Meaning |
|---|---|
| **Plugin** | A developer's submission: a manifest, PHP backend code, and an optional TypeScript frontend bundle, versioned and reviewed as a unit |
| **Release** | One reviewed, signed, immutable version of a plugin. What a tenant actually installs |
| **Manifest** | `plugin.json` — the declared identity, requested permissions, and extension points a release uses ([04](04-manifest-and-package-format.md)) |
| **Install** | The local record, inside one tenant's own deployment and database, that this deployment has a specific plugin release present, whether active or not — never a row identifying *which* tenant, since a deployment's own database only ever holds its own installs |
| **Grant** | A specific permission a tenant's own deployment has approved for one of its installs — the answer to "yes, this plugin may do X," recorded locally |
| **Plugin Runtime** | The isolated execution environment, running **inside each tenant's own deployment**, that runs that tenant's installed plugins' PHP outside the main application's trust boundary ([05](05-security-and-sandboxing.md)) — there is one per deployment, not one shared instance |
| **Plugin Host** | The frontend-side counterpart, also local to each tenant's deployment: the iframe and RPC bridge a plugin's UI bundle runs inside ([08](08-frontend-runtime-and-dynamic-loading.md)) |
| **Extension point** | A named hook (backend) or slot (frontend) a plugin can attach to — the platform's entire exposed surface area ([06](06-extension-points-and-hooks.md)) |
| **Capability** | One discrete thing a plugin can be granted the ability to do (e.g. "read this deployment's own order list", "register a nav item") — the unit permissions are expressed in |
| **Marketplace** | The **centrally hosted** catalog of published releases — developer accounts, review, signing — that every tenant's own deployment reaches out to browse and install from ([02](02-architecture-overview.md), [10](10-review-and-publishing-pipeline.md)) |
| **Kill switch** | The mechanism to disable a published release across every independently deployed tenant that installed it — a central revocation flag, delivered by push and guaranteed by each deployment's own pull, never a direct write to a tenant's local database ([05](05-security-and-sandboxing.md) §7) |

## 5. The decisions

These were made directly with the user during scoping and are binding on every later document in
this plan; a later document that needs to revisit one says so explicitly rather than silently
drifting from it.

| # | Decision | Why |
|---|---|---|
| **D1** | **Plugins get real backend PHP execution, delivered as a zip upload — not a Composer-package model, not frontend-only.** Chosen deliberately as the highest-capability, highest-risk option | User's explicit choice: "I like Runtime zip upload but with a lot of controls in place for security and safety." Every later document's job is to be that control |
| **D2** | **Scope is this one application's codebase, `w/www`.** Not the other nine repositories. Every tenant automatically gets the plugin system, because a tenant *is* a deployed copy of this codebase — that was always what "for the tenants it already serves" meant, corrected here only to be explicit that it is *not* one shared instance serving many logical tenants (§0) | User: this app is "not external to anyone but appfactory studio" — the plugin system is a feature of this codebase, not an estate-wide platform spanning `auth`, `members`, `coordinator`, and the rest |
| **D3** | **No internal-only terminology in anything plugin-facing.** See §0 | User: "do not use that terminology" for this app's internal control-plane name |
| **D4** | **There is a real marketplace: browse, install, and manage from a Plugins sidebar item**, not a developer-only allowlist or a config-file toggle | User: "The ideal would be for some marketplace of plugins that can be installed on a tenant like WordPress" |
| **D5** | **A plugin's frontend TypeScript ships as an independently-built bundle, loaded by dynamic `import()` at runtime** — not statically imported into the core admin bundle | User's explicit choice, and it is also the only option compatible with D4: a marketplace install cannot require rebuilding and redeploying the whole admin app |
| **D6** | **The raw uploaded zip is never what executes.** Every release goes through review and is re-packaged and signed; only a signed release is ever installable, and the Plugin Runtime verifies the signature before running anything | The single largest lever against "zip upload + execute" being as dangerous as it sounds. Detailed in [10](10-review-and-publishing-pipeline.md) and [05](05-security-and-sandboxing.md) §2 |
| **D7** | **Plugin backend code never gets direct framework access** — no raw Eloquent, no raw `DB::`, no raw `Storage::`, no raw outbound HTTP. It gets a capability-scoped SDK object whose every method corresponds to one grantable capability | This is what makes "arbitrary PHP" not mean "arbitrary access" — the sandbox boundary is the API surface, not (only) the process boundary. [05](05-security-and-sandboxing.md) §4 |
| **D8** | **A tenant approves specific, itemized capabilities at install time**, shown before the install completes, and can review or revoke them afterward | User's controls-first framing, applied to the tenant-facing side, not just the review pipeline. [07](07-installation-and-activation-lifecycle.md) §3 |
| **D9** | **Tenant-local install state is `laravel_plugin_*`-prefixed; the central marketplace catalog is `brain_plugin_*`-prefixed.** Both live in this app's own migrations, shipped to every clone — but only the Brain instance ever populates the `brain_plugin_*` rows, exactly like every other `brain_*` table on a tenant clone ([AGENTS.md §5](../../../w/AGENTS.md)) | Follows the estate's own existing prefix convention precisely, corrected from an earlier draft that put everything in one `plugin_*`-prefixed, tenant-column-scoped set of tables as if this were a shared multi-tenant database (§0) |
| **D10** | **Paid plugins reuse [market](../market/README.md)'s Stripe Connect integration rather than a second one.** Not built in the first delivery | Avoids a second, parallel payments stack for what is fundamentally the same problem market already solved: split payments to a third-party seller |
| **D11** | **The marketplace is hosted on the Brain, and a tenant's own deployment reaches it exactly the way an app already reaches its Brain-owned settings** — a signed pull as the source of truth, an optional signed push as an accelerator ([www_brain 10](../www_brain/10-brain-owned-config-and-push.md), B13) | This ecosystem already solved "one central service, many independently deployed apps, a signed two-way channel" for a different feature. Reusing it means the plugin marketplace needs no new trust mechanism between deployments — see [02 §8](02-architecture-overview.md) |

## 6. Questions — open

Unlike a plan built entirely before implementation starts, several of these are deliberately left
for the document that owns them, because answering them here would be guessing ahead of the
detail:

| # | Question | Depends on | Where it's resolved |
|---|---|---|---|
| Q1 | Exact isolation mechanism for the Plugin Runtime — a separate OS process/container per invocation, a persistent isolated worker pool, or a WASM-compiled PHP runtime | Available infrastructure, latency budget | [05](05-security-and-sandboxing.md) §3 |
| Q2 | Whether a "trusted developer" fast-track (lighter review after a track record) is ever introduced, and its bar | Marketplace volume once launched | [10](10-review-and-publishing-pipeline.md) §5 (recorded as future work, not decided now) |
| Q3 | Whether plugin data survives an uninstall by default (soft, recoverable) or is purged immediately | Support burden vs. tenant data-cleanliness expectations | [07](07-installation-and-activation-lifecycle.md) §5 |
| Q4 | Rate/volume limits on how many plugins a single tenant may have active at once | Resource budget of the Plugin Runtime pool | [13](13-observability-and-incident-response.md) §3 |
| Q5 | Whether the developer portal is a section of this same admin dashboard or a separate small app | Team preference, not yet stated | [09](09-admin-dashboard-ux.md) §6 |

## 7. Decision on scope of first delivery

A working marketplace end to end, on this one app, for **free plugins only**: submission,
automated scanning, human review, signing, a Plugins sidebar with browse/install/configure/
update/remove, sandboxed PHP execution behind a capability-scoped SDK, and a kill switch. Proven
first with a small number of first-party "reference" plugins built by the team
([14](14-rollout-and-testing.md) §2) before any outside developer is invited in. Billing,
revenue share, and cross-app distribution are designed ([12](12-billing-and-monetization.md)) but
not built until that free-plugin path is live and trusted.

## 8. Known follow-up from the 2026-09-27 tenant-model correction

§0's correction fixed every substantive claim (the invocation token, the threat model, the
schema's central/local split, every code example) across 00, 02, 03, 05, 09, 11, 13, 16, and 17.
One thing it deliberately left for a dedicated pass rather than a blind find-and-replace: several
documents (04, 06, 07, 08, 10, 15) still refer to local tables by their pre-correction bare names
(`plugin_installs`, `plugin_grants`, `plugin_settings`, `plugin_audit_log`) instead of
[03](03-database-schema.md)'s corrected `laravel_plugin_*`/`brain_plugin_*` prefixes, and to
`plugin_capabilities_catalog` as if it were still a database table rather than the shipped
`config/plugin_capabilities.php` (03 §0). None of this is a correctness error the way the tenant
model was — a capability *key* like `plugin_data.increment` is unaffected either way, since that's
an SDK method name, not a table — it's a naming-consistency cleanup, flagged here rather than
risking a mechanical rename that could conflate a table name with a same-looking capability key.
