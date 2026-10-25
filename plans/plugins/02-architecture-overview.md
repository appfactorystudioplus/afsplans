# 02 — Architecture overview

## Completion document

When this plan step is completed, write `complete/02-architecture-overview.complete.md`.

---

> **Corrected 2026-09-27.** This document originally modeled one shared application instance
> serving many logical tenants — wrong (00 §0). It is rewritten here around the actual model: a
> centrally hosted marketplace, and the Plugin Runtime/Plugin Host/local install state running
> **inside each independently deployed tenant**, with no shared runtime between them at all.

## 1. Two things, not one

```
┌─────────────────────────────────────────┐         ┌─────────────────────────────────────────┐
│         The Marketplace (10, 03)          │         │      Tenant A's own deployment            │
│         hosted on the Brain               │         │      (its own `w/www` instance,           │
│                                            │◀───────▶│       its own database)                   │
│  developer ──upload zip──▶ Submission API │  signed │                                            │
│                   │                       │  pull   │  Plugins nav (09) ──▶ Plugin Runtime (05)  │
│                   ▼                       │  (10)   │                  ──▶ Plugin Host (08)      │
│            Review Pipeline (10)           │  +      │                  ──▶ local install/grant   │
│                   │                       │  push   │                      state (03, local)     │
│                   ▼                       │  (13)   │                                            │
│         brain_plugin_catalog (03)         │         └─────────────────────────────────────────┘
└─────────────────────────────────────────┘
                     ▲                                ┌─────────────────────────────────────────┐
                     │            same signed channel  │      Tenant B's own deployment            │
                     └─────────────────────────────────│      — identical in shape to A,           │
                                                        │        no awareness A exists              │
                                                        └─────────────────────────────────────────┘
```

**Nothing runs across this diagram's middle boundary except the signed channel itself.** A
tenant's Plugin Runtime, its installed plugins' code, and its local database are entirely inside
its own box. The marketplace never executes a plugin, never holds a tenant's data, and never
reaches into a tenant's deployment except through the same push/pull channel every tenant already
uses for its Brain-owned configuration ([www_brain 10](../www_brain/10-brain-owned-config-and-push.md),
reused per [00 §0](00-overview.md)/D11 — not reinvented here).

## 2. Components

| Component | What it is | Lives in | Owned by (step) |
|---|---|---|---|
| **Submission API** | Where a registered developer uploads a plugin zip and manages releases | The marketplace (Brain) | [10](10-review-and-publishing-pipeline.md) |
| **Review Pipeline** | Automated static analysis + dependency scan + human review, gating every release before it can be signed | The marketplace (Brain) | [10](10-review-and-publishing-pipeline.md) |
| **Marketplace Catalog** | The published, signed releases any tenant deployment can pull | The marketplace (Brain), `brain_plugin_*` tables | [03](03-database-schema.md), [09](09-admin-dashboard-ux.md) |
| **Local install/grant state** | Which plugins *this* deployment has, which version, which capabilities it approved, active or disabled — no tenant-identifying column, because there is exactly one tenant here | Each tenant's own deployment, `laravel_plugin_*` tables | [03](03-database-schema.md), [07](07-installation-and-activation-lifecycle.md) |
| **Plugin Runtime** | Executes this deployment's own installed plugins' backend PHP outside the main application's trust boundary | Each tenant's own deployment — one per deployment, never shared | [05](05-security-and-sandboxing.md) |
| **Plugin Host** | Loads and sandboxes a plugin's frontend bundle inside this deployment's admin dashboard | Each tenant's own deployment | [08](08-frontend-runtime-and-dynamic-loading.md) |
| **Capability-scoped API (the plugin SDK)** | The only way a plugin's code, running inside its own deployment, reaches that deployment's own platform data — never raw framework access | Each tenant's own deployment | [05](05-security-and-sandboxing.md) §4, [06](06-extension-points-and-hooks.md) |
| **Core Platform** | The existing application code in every deployment — controllers, models, events — modified only to (a) emit events plugins can subscribe to and (b) render the Plugins nav item | Every deployment (it's the same codebase) | Existing code; touched narrowly per [06](06-extension-points-and-hooks.md), [09](09-admin-dashboard-ux.md) |

## 3. Why the Plugin Runtime is a boundary, not a library

The single most important structural decision in this plan, unchanged by the correction above: a
plugin's PHP code does **not** execute inside the same PHP-FPM worker, the same Laravel service
container, or the same request lifecycle as the core application **it is installed into**. It
executes in a separate, isolated environment ([05](05-security-and-sandboxing.md) §3), local to
that same tenant's deployment, that:

- Has no database credential for that deployment's own database beyond the mediated capability
  API — and, since there is no shared database at all in this model, no path to any *other*
  deployment's data exists to begin with (00 §0).
- Has no filesystem access outside a directory scoped to that one plugin.
- Reaches platform data only by calling a narrow, versioned, capability-checked API — the same
  posture [storage-sharding](../storage-sharding/08-security.md) already uses for a different
  problem ("the storage app never touches the shared database... it talks to the Brain over the
  existing signed channel"). This plan applies that same idea — mediation instead of trust —
  to first-party code running third-party plugins, inside one deployment, rather than to a
  separate server talking to another.

This is what makes "the plugin runs arbitrary PHP" not mean "the plugin can do arbitrary things."
The isolation is enforced by the boundary between processes/containers, **and again** by the fact
that even inside its own sandbox, a plugin is never handed an Eloquent model, a `DB::` facade, a
`Storage::` facade, or an outbound HTTP client with no allowlist — see [05 §4](05-security-and-sandboxing.md).
Two independent layers, so a bug in one is not the whole defense.

## 4. Request flow: installing a plugin

1. This deployment's admin opens **Plugins → Browse**. The admin dashboard makes a signed pull
   against the marketplace's catalog API ([10](10-review-and-publishing-pipeline.md), §5 below) —
   published releases only.
2. Admin picks a plugin, sees its detail page: description, screenshots, developer, reviews,
   and — critically — **the itemized list of capabilities it requests**, read from the release's
   signed manifest ([04](04-manifest-and-package-format.md) §3), rendered in plain language
   ([09](09-admin-dashboard-ux.md) §3, D8).
3. Admin clicks Install. The consent screen shows the same capability list with a required
   confirmation. On confirm:
   - The admin dashboard pulls the signed release artifact from the marketplace and verifies its
     signature **locally**, against the marketplace's public key baked into this codebase — not by
     asking the marketplace "is this valid," by checking it itself.
   - A `laravel_plugin_installs` row is created **in this deployment's own database** — no
     tenant-identifying column, since this database has exactly one tenant.
   - A `laravel_plugin_grants` row is created for each approved capability.
   - If the release has a frontend bundle, its bundle URL + integrity hash is recorded; nothing is
     fetched yet.
4. The **Plugins** nav gains an entry for the newly active plugin ([08](08-frontend-runtime-and-dynamic-loading.md) §2)
   the next time this deployment's admin shell re-reads its own active-plugin list (on install,
   and on each full page load) — a purely local read, no network call involved.

## 5. Request flow: a plugin's UI screen rendering

Unchanged by the correction — this was always local to one deployment, since the admin dashboard
itself only ever exists per-deployment:

1. Admin navigates to the plugin's nav entry.
2. The admin shell resolves the plugin's frontend bundle URL + integrity hash from this
   deployment's own install record, and dynamically `import()`s it inside a sandboxed iframe
   ([08](08-frontend-runtime-and-dynamic-loading.md) §3) — the bundle itself is never trusted to
   run in the admin dashboard's own document context.
3. The iframe's script talks to the parent only through `postMessage`, against the `plugin-host`
   contract ([08](08-frontend-runtime-and-dynamic-loading.md) §5): request data, render a toast,
   navigate, read this deployment's granted capabilities for this plugin.
4. Any data request the plugin's frontend makes is itself capability-checked server-side, exactly
   like a backend hook invocation (§6) — the iframe boundary defends the *admin dashboard's own
   page*, not the *data*; data is defended by the API layer regardless of which side is asking.

## 6. Request flow: a backend hook firing

1. Core platform code, running in this deployment, emits an event at an extension point
   ([06](06-extension-points-and-hooks.md) §1) — e.g. `order.completed`.
2. The Plugin Runtime dispatcher, local to this deployment, looks up every **active** install (in
   its own local table) whose manifest subscribes to `order.completed`, and whose grants include
   the capability that hook requires.
3. For each match, the dispatcher invokes that plugin's handler **in this deployment's own Plugin
   Runtime**, passing only the data the hook's contract defines (never a full model, never a raw
   request) and a capability-scoped SDK handle limited to that plugin's grants.
4. The handler runs under the resource limits and timeout in [05 §6](05-security-and-sandboxing.md);
   a timeout, crash, or repeated failure is recorded locally and can trip this deployment's own
   circuit breaker ([13](13-observability-and-incident-response.md) §2) without affecting the
   request that fired the event — hook dispatch is asynchronous by default (§7 below) precisely so
   a slow or broken plugin cannot make this tenant's own core action (completing an order) slow or
   fail. See [17](17-example-plugin-order-guard.md) for this worked through end to end.

## 7. Synchronous vs. asynchronous extension points

Not every hook can be asynchronous — a plugin that wants to *validate or block* an action (e.g.
"reject this order below a minimum") needs a synchronous answer. This plan supports both, declared
per extension point in the catalog ([06 §1](06-extension-points-and-hooks.md)):

| Kind | When | Failure mode |
|---|---|---|
| **Notify (async)** | The default. "Something happened," fire-and-forget, queued | A failing or slow handler is isolated to its own queue worker; the triggering request is unaffected |
| **Filter (sync, bounded)** | The platform needs a value back before continuing (e.g. "should this be allowed") | A hard timeout (short — [05 §6](05-security-and-sandboxing.md)) with a defined default-on-timeout per extension point, stated in the catalog entry ([06 §1](06-extension-points-and-hooks.md)), so a stalled plugin fails toward the platform's own default behavior, never toward hanging the request indefinitely |

## 8. The marketplace channel: reusing `www_brain`, not inventing one

D11 ([00 §5](00-overview.md)). The one genuinely cross-deployment relationship in this plan — a
tenant's own deployment talking to the centrally hosted marketplace — is the same shape
[www_brain 10](../www_brain/10-brain-owned-config-and-push.md) already built for Brain-owned
settings and mail events, reused rather than re-invented:

- **Pull is the source of truth (B13).** A tenant's deployment fetches the catalog, a specific
  release, and each installed release's current `revoked_at` status by pulling from the
  marketplace, on its own schedule and on demand (Browse, Install, Update-check). If the
  marketplace is briefly unreachable, already-installed plugins keep running exactly as they are —
  unreachable is not the same as revoked.
- **Push is an accelerator, never the only path (B13).** The marketplace can notify a deployment
  "a release you have installed was just revoked" over the same `brain-app` channel
  ([www_brain 10 §10.5](../www_brain/10-brain-owned-config-and-push.md)), for fast reaction — but
  a deployment that misses the push still catches the revocation on its next ordinary pull, per
  [05 §7](05-security-and-sandboxing.md)'s kill switch and [13](13-observability-and-incident-response.md)'s
  runbook. G6 does not depend on every push arriving.
- **Each direction has its own verifier (B14).** Deployment → marketplace calls (browse, install,
  check-in) reuse the app→Brain signed path; marketplace → deployment calls (a revocation push)
  reuse the Brain→app signed path. Same HMAC scheme, same non-replayability between directions,
  already built for a different feature.
- **A deployment decides which pushed actions it accepts (B15).** A revocation notice is the only
  push action this plan defines in the first delivery — there is no "the marketplace can run
  arbitrary code on a tenant's deployment" path, matching B15's rule exactly.

This is why [00 §0](00-overview.md) can state flatly that no new trust mechanism was invented for
cross-deployment communication: the one place this plan needs one, an equivalent already exists
and is already partially built.

## 9. What changes in the existing codebase, and what does not

| Existing thing | Change |
|---|---|
| `config/portal_permissions.php` | Unchanged structurally; a new, separate dynamic layer sits alongside it, local to each deployment ([11](11-permissions-and-tenant-model.md)) |
| `views.ts` / `main.ts` | One new static registration, present in every deployment since it's the same codebase: the **Plugins** nav item and its own view, which then reads this deployment's own install list at runtime. No plugin adds an entry to this file, ever |
| `BUILDER_MODULES`, the Modules index | Untouched — first-party modules stay exactly as they are (00 §3) |
| Database migrations | Additive only, shipped to every deployment: new `laravel_plugin_*` tables (populated locally by each deployment) and new `brain_plugin_*` tables (populated only on the Brain instance, sitting empty everywhere else, exactly like every other `brain_*` table) — guarded creates, per AGENTS §5 |
| `app/Providers` | One new provider registering the Plugin Runtime's own service bindings, present in every deployment — not a discovery mechanism that boots plugin code in-process (§3 explicitly rules that out) |
| `afs/brain-client` | Reused, not modified, for the marketplace channel (§8) |
| `AfsConnect`, `external/api` | Untouched; unrelated systems (01 §6, §7) |
