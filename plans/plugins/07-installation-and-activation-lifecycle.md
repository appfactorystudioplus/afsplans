# 07 — Installation and activation lifecycle

## Completion document

When this plan step is completed, write
`complete/07-installation-and-activation-lifecycle.complete.md`.

---

## 1. The plugin-identity state machine (marketplace side)

```
 draft ──submit──▶ in_review ──approved──▶ published ──delisted/rejected──▶ delisted
                       │
                       └──rejected──▶ draft (developer revises and resubmits)
```

Governed entirely by [10](10-review-and-publishing-pipeline.md); restated here only so the
per-tenant state machine below is read in context — a tenant can only ever install a `published`
plugin's `approved` release.

## 2. The per-tenant install state machine

```
 (not installed)
        │ tenant clicks Install, confirms capability consent (§3)
        ▼
   installed ──tenant/system activates──▶ active ──tenant disables──▶ disabled
        │                                    │                            │
        │                                    │◀───tenant re-enables───────┘
        │                                    │
        │                                    └──kill switch / circuit breaker──▶ disabled
        │                                         (05 §7, 13 §2 — disabled_reason recorded)
        │
        └──tenant uninstalls (from any state)──▶ uninstalling ──▶ (row retained, state uninstalled — §5)
```

`installed` and `active` are distinct states deliberately: installing (creating the
`plugin_installs` row, running the manifest's `data_schema` provisioning) is separated from
activation (the plugin actually begins receiving hook dispatches and appearing in navigation) so
that:

- A tenant can install a plugin, configure its settings, and choose *when* to go live, without an
  extra "half-installed" ambiguity.
- The platform can install a plugin in an inert state for its own verification purposes
  ([14 §2](14-rollout-and-testing.md)'s dogfood plugins) without it doing anything yet.

## 3. Capability consent — where D8 actually happens

Before an install request is written, the admin dashboard shows the consent screen
([09 §3](09-admin-dashboard-ux.md)) built directly from the release's manifest `capabilities`
list (04 §2), each rendered using its `plugin_capabilities_catalog.label` and `risk_tier`
([03 §1](03-database-schema.md)) — never the developer's own description of what a capability
does, which the review pipeline does not treat as authoritative. A parameterized capability (only
`http.egress` today) shows its actual declared parameter values — the specific hostnames
([04 §3.1](04-manifest-and-package-format.md)) — not just its label. The tenant must explicitly
confirm; there is no "select all" default and no capability is pre-checked. On confirm, one
`plugin_grants` row is written per capability (03 §2), each stamped with the confirming portal
user's id, and carrying that capability's approved parameters verbatim
(`plugin_grants.parameters_json`, [03 §2](03-database-schema.md)) where it has any.

**An update that adds a new capability, or changes the parameters of one already granted (e.g.
adds a host to `http.egress`'s list), re-triggers this exact screen**, scoped to only what's new
or changed (04 §6) — the tenant is never asked to re-confirm something already granted at the same
scope, but is always asked about anything new or expanded, with no way to silently inherit a
broader grant through a version bump.

## 4. What happens at install time, precisely

1. Verify the release's signature and hashes ([04 §5](04-manifest-and-package-format.md)) — an
   install request for an unverifiable release is refused before anything else happens.
2. Verify `platform_compat` against the live extension-point catalog version
   ([06 §4](06-extension-points-and-hooks.md)) — refused if incompatible.
3. Write `plugin_installs` (`state = installed`).
4. Write one `plugin_grants` row per confirmed capability (§3).
5. For each collection in the manifest's `data_schema` (04 §4), record it as an allowed
   collection for this `install_id` — no DDL runs, per
   [05 §5](05-security-and-sandboxing.md)'s reasoning; this is a permission-table write, not a
   schema change.
6. If the release has a frontend bundle, record its URL and integrity hash against this install —
   nothing is fetched by the browser until the tenant actually navigates to the plugin.
7. Move to `active` (either immediately, if the manifest declares no first-run configuration is
   required, or after the tenant completes the plugin's own first-run settings screen if it does
   — a manifest flag, not a platform-wide rule).

Nothing in this sequence executes a single line of the plugin's own backend code. The first time
plugin code runs is the first hook dispatch or the first time its frontend bundle is loaded, both
strictly after `state = active` and both going through the Plugin Runtime/Plugin Host boundaries
in [05](05-security-and-sandboxing.md) and [08](08-frontend-runtime-and-dynamic-loading.md) —
installation is pure bookkeeping, deliberately, so that the moment of highest risk (code actually
executing) is never conflated with the moment of lowest risk (a tenant deciding to add
something).

## 5. Disable vs. uninstall vs. data retention (00 §6 Q3)

| Action | What happens to `plugin_installs` | What happens to `plugin_data` / `plugin_settings` | What happens to grants |
|---|---|---|---|
| **Disable** (tenant-initiated) | `state = disabled`, `disabled_reason = 'tenant'` | Untouched — re-enabling restores exactly where it left off | Untouched |
| **Disable** (kill switch / circuit breaker) | `state = disabled`, `disabled_reason` names the cause | Untouched | Untouched — a later platform-approved fix can be re-enabled without re-granting |
| **Uninstall** | `state = uninstalling` immediately, then the row is retained with `state` reflecting completion (03 §2's unique-index rationale: reinstalling reuses the row so `plugin_audit_log` history stays attached) | **Retained for a stated grace period** (recommended default: 30 days, configurable), then purged by a scheduled job — resolving Q3 toward "soft, recoverable" as the default, since the support cost of an accidental uninstall losing configuration outweighs the storage cost of a short grace window | Marked inactive; reinstalling within the grace window restores data but still re-runs the full consent screen (§3) — grants are never silently restored, only data is |
| **Uninstall, tenant requests immediate purge** | Same as above | Purged immediately on explicit, separately-confirmed request | Same |

A plugin's own backend code is never invoked as part of uninstall — there is no "run the plugin's
cleanup hook" step, because that would mean executing a possibly-malicious plugin's code at the
exact moment a tenant is trying to get rid of it. Data cleanup is entirely a platform-side
operation against `plugin_data`/`plugin_settings`, scoped by `install_id`, per
[05 §5](05-security-and-sandboxing.md)'s document-store model.

## 6. Reactivation after a kill-switch disable

Set out in full in [13 §5](13-observability-and-incident-response.md)'s incident-response
runbook; the lifecycle-relevant point is that a kill-switch `disabled` install is **not**
automatically reactivated when a fixed version is published. The tenant sees the notice
([05 §7](05-security-and-sandboxing.md)), and re-enabling is a deliberate action — pointing the
install at the new release and, if the new release's manifest adds capabilities, going through §3
again.

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | Install/activate/disable state machine and its four tables (03 §2) | Every transition in §2's diagram is reachable and every disallowed transition (e.g. `uninstalling → active`) is rejected |
| **P1** | Capability consent flow (§3) wired to the manifest and the catalog | An install cannot reach `installed` without a `plugin_grants` row for every capability the manifest declares |
| **P2** | Update flow with capability diffing (04 §6, §3 above) | Installing a version with no new capabilities requires no re-consent; one with new capabilities always does |
| **P3** | Uninstall grace period and purge job (§5) | Data is recoverable within the grace window and reliably purged after it |
