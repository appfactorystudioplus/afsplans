# 01 — Current state audit

## Completion document

When this plan step is completed, write `complete/01-current-state-audit.complete.md`.

---

Every claim below was verified against the actual files in `w/www` (paths relative to that
repository root) during scoping, not inferred from a plan or a README elsewhere in this tree.
Nothing here describes intended behavior — [00-overview.md](00-overview.md) and everything after
it does that. This document is "what is actually true right now."

## 1. There is no plugin system, and no partial one

A search for anything resembling third-party extensibility — `plugin`, `module install`, a
package-discovery hook, a dynamic-loading registry — turns up nothing. The word "module" appears
in exactly one place that matters, and it means something different from a plugin (§3).

## 2. The admin frontend's architecture

`resources/js/portal/admin` is a hand-rolled single-page application, not React, Vue, or any
component framework:

- **`main.ts`** (28 KB) bootstraps a single `ApplicationContextManager` (`app`) and a
  `ViewComponent` (`view`) from a shared library at `../_sources/src/afslibs/afs/appfactory3`,
  loads settings, member data, feature flags, permissions metadata, and localization, then calls
  `InitializeApplication()` before inserting the shell into the DOM.
- **`views.ts`** registers every screen as a `view.newSubView({ id, route, body, listener })` —
  a hash-based route (`useHash: true` router), a body-render function, and a listener that runs
  once the view is navigated to. Every feature — `viewForms`, `viewEcommerce`, `viewEvents`,
  `viewBuilder`, `viewModules`, and so on — is a function imported **statically** at the top of
  `views.ts` and invoked in `registerViews()`. There is no dynamic registration API; a new screen
  requires editing this file and rebuilding.
- Some subviews lazy-load their controller via `import('./controllers/...')` inside the
  `listener`, but the *view registration itself* — the route, the nav entry — is still compiled
  into the one bundle. Lazy-loading here is a code-splitting optimization within one build, not a
  mechanism for adding a screen without rebuilding.
- Global services (`NotificationService`, `FeatureFlags`, `ThemeManager`, `Localizer`,
  `dataStore`) are attached to `app.window.*` in `main.ts` and read from anywhere in the bundle
  by direct reference — there is no capability boundary between one feature's code and another's,
  or between the shell and any feature. Everything runs with the same privileges as everything
  else, in one execution context.
- Build tooling is Vite (`vite.config.js`, `vite.config.afs.js` for this admin bundle
  specifically). Per [AGENTS.md](../../../w/AGENTS.md) §1, the Vite **dev server** is never
  started by an agent; `npm run build` is the only asset command run here, and it compiles the
  entire admin bundle in one pass.

**Implication for this plan:** any plugin frontend mechanism must not require editing
`views.ts` or rebuilding this bundle per plugin (D5) — the entire existing pattern does the
opposite of that on purpose, for first-party code, where a rebuild for every change is
acceptable. [08](08-frontend-runtime-and-dynamic-loading.md) designs the alternative.

## 3. "Modules" today means built-in features, not installable plugins

`resources/js/portal/admin/controllers/modules/modulesIndex.ts` and
`resources/js/portal/admin/views/modulesView.ts` implement a **Modules** admin screen — but its
own docblock is explicit about what it is: an index over the fixed, first-party feature set
(`BUILDER_MODULES` — a statically declared array of four page-builder blocks — plus a
hardcoded list of four more, `UNREGISTERED`, that have admin screens but no builder block yet).
Its own comments state directly:

> "This deployment records no per-tenant module installation anywhere — there is no table, no
> subdomain property and no flag for it... `availability.requires` was written against a store
> that does not exist yet."

So the closest-looking existing thing to "a tenant's installed modules" is a UI over a hardcoded
list with no backing installation state at all. This plan's `plugin_installs` table
([03](03-database-schema.md)) is the first time that store actually gets built — for third-party
plugins, not for the built-in modules, which this plan does not touch (00 §3 non-goal).

## 4. Permissions are a static PHP array

`config/portal_permissions.php` hardcodes fifteen module keys (`users`, `ecommerce`, `events`, …)
with CRUD flags, labels, descriptions, and a `view_permission_map` from view id to permission key.
A portal user's permissions live in a JSON column (`users.permissions`) checked against this
fixed key set. There is no API to register a new permission key at runtime — adding one today
means editing this file and shipping a new build.

**Implication:** a plugin that needs its own permission key (e.g. "can manage Widget Pro's
settings") cannot fit into this model unmodified. [11](11-permissions-and-tenant-model.md)
designs how plugin-contributed permissions extend this without breaking the fifteen existing
callers of `portal_permissions.php` and `utils/permissions.ts`'s `setViewPermissionMap` /
`applyPermissionsToNav`.

## 5. The page builder's module registry — the closest existing analog

The builder has its own `BUILDER_MODULES` registry (`resources/js/builder/core/modules`,
referenced from `modulesIndex.ts`) with `availability` rules (`requires`, tier-gating via
`alloc_v2`), an `instanceProviderFor()` lookup, and `paletteStates()` to compute whether a block
is available, disabled, or hidden. This is the single most plugin-shaped piece of existing code
in the app — a declarative registry with availability logic — but it is:

- **Statically declared in TypeScript source**, compiled into the bundle. Adding an entry means
  editing this file, not installing anything.
- **First-party only.** Every entry names a feature this repository owns and ships.
- **Not connected to any authorization or sandboxing layer** — it decides whether to *show* a
  block, not whether code is *trusted* to run.

It is worth studying as prior art for availability/tier-gating UX (`paletteState()`'s
available/disabled/hidden three-state model reappears conceptually in this plan's install states,
[07](07-installation-and-activation-lifecycle.md)), but it is not extensible today and this plan
does not attempt to make third-party plugins register into it — plugin extension points are a
separate, new registry ([06](06-extension-points-and-hooks.md)).

## 6. `AfsConnect` is data sync, not plugin infrastructure

`app/AfsConnect/` (entitlement guard, tenant context DTOs, `Www*Provider` classes for
Analytics/Appointments/DirectPayments/Ecommerce/Events/Forms/Money/Sponsorships/Subscribers, an
ingest dispatcher) pushes this app's own first-party module data to a separate aggregation-hub
application (`coordinator`) elsewhere in the estate. It is real, working infrastructure for
**cross-application data sync within appfactory studio**, unrelated to third-party extensibility.
Nothing here is reused by this plan; it is documented only so the two are not confused later —
"AfsConnect" and "a plugin connecting to the platform" are unrelated ideas that happen to share
the word "connect."

## 7. `external/api` and `portal.d.ts` are a different kind of contract

`resources/js/external/api` is a **separately built** (own `webpack.config.cjs`, own
`tsconfig.external.json`) TypeScript SDK, consumed at runtime only via `window.Portal.*` by
*other appfactory studio applications* (`coordinator`, `notes`, `flow`, `dream_graph`,
`pdf_editor`, `market`, `members`) reading this app's data. Its `portal.d.ts` is hand-maintained
and copied into each consumer, per [AGENTS.md §4](../../../w/AGENTS.md).

Two things from this are directly relevant, and this plan follows both precedents rather than
inventing new ones:

- **The "separately built, dynamically consumed via a global, typed by a hand-copied `.d.ts`"
  pattern already exists in this codebase**, for a different purpose. [08](08-frontend-runtime-and-dynamic-loading.md)'s
  plugin bundle mechanism is the same shape, applied to plugin frontends instead of
  cross-app data access — not a novel idea for this codebase, an established one reused.
- **Never import a consuming file directly into the other build graph.** The `plugin-host`
  contract this plan introduces ([08](08-frontend-runtime-and-dynamic-loading.md) §5) follows
  `portal.d.ts`'s exact discipline: a hand-maintained type declaration copied to wherever a
  plugin bundle is authored, never a live import across the plugin/host build boundary.

## 8. Backend structure has no package/provider discovery to build on

`app/Providers` has exactly two providers: `AppServiceProvider` and
`DirectPaymentsServiceProvider`. `composer.json`'s `extra.laravel.dont-discover` is empty —
package auto-discovery is otherwise untouched Laravel default behavior, and nothing in this app
already does anything resembling "discover and boot a unit of code found in a directory or a
database row." `app/Http/Controllers` is organized by first-party domain
(`Admin`, `Brain`, `DirectPayments`, `Integrations`, `Portal`, `Processor`, `SiteUsers`, …) with
no plugin-related namespace. The Plugin Runtime ([05](05-security-and-sandboxing.md)) is
therefore new infrastructure, not an extension of an existing discovery mechanism — there isn't
one to extend.

## 9. Database schema ownership rules that constrain this plan

Per [AGENTS.md §5](../../../w/AGENTS.md): this app owns every migration for the shared database;
a new migration must guard every `Schema::create` with `hasTable`, name every index explicitly
once a table name gets long, group related tables into one migration file per domain rather than
one file per table, and never let a second application ALTER a table this app created. New tables
this plan introduces are `plugin_*`-prefixed (D9) and land in one migration file per
[03](03-database-schema.md), the same convention the audit notes elsewhere in this tree
(`brain_storage_*`, `laravel_direct_payment_*`) already follow.

## 10. Summary: what this plan can build on, and what it must build from nothing

| Exists and reused | Exists but not reused (different purpose) | Does not exist — this plan builds it |
|---|---|---|
| The separately-built, dynamically-loaded, hand-typed contract pattern (`external/api`) | `AfsConnect` (cross-app data sync) | Any notion of installable third-party code, backend or frontend |
| Vite `npm run build` as the asset pipeline for this app's own bundle | The builder's `BUILDER_MODULES` registry (first-party, static) | A per-tenant install/activation store |
| `ViewComponent`'s hash router, for the *shell* the Plugins nav item lives in | The Modules index UI (a view over a hardcoded list) | Dynamic permission registration |
| The shared-database schema-ownership rules (AGENTS §5) | `config/portal_permissions.php` (static, unextended) | Any sandboxing, review, signing, or capability-scoping mechanism |
