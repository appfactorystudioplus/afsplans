# 09 — Admin dashboard UX

## Completion document

When this plan step is completed, write `complete/09-admin-dashboard-ux.complete.md`.

---

This is the one part of the plan a tenant actually sees: **Plugins**, a new top-level sidebar
item registered exactly once, statically, in `views.ts` ([01 §2](01-current-state-audit.md)) —
everything inside it is data-driven, not a per-plugin code change. **Browse reads from the
central marketplace** (a remote, signed pull, [02 §8](02-architecture-overview.md)); **Installed,
Activity, and Settings read from this deployment's own local tables** ([03](03-database-schema.md))
— two different data sources behind the same nav item, invisible to the tenant admin using it.

## 1. Information architecture

```
Plugins (sidebar)
├── Browse            — the marketplace catalog
│   ├── category filters, search
│   └── plugin detail page → Install
├── Installed         — this deployment's own local installs
│   ├── active / disabled grouping
│   ├── per-plugin → Settings, Permissions, Update, Disable, Uninstall
│   └── update-available banner
├── Activity           — the audit log viewer (§7)
└── Developer Portal    — submit and manage your own plugins (§6; may be a separate area, 00 §6 Q5)
```

## 2. Browse

A standard catalog UI: search, category filter, sort (newest / most installed — a count derived
from `plugin_installs`, not developer-reported). Each card shows name, developer, summary, icon,
and a rating if [10](10-review-and-publishing-pipeline.md)'s review system includes tenant
reviews. Only `plugins.status = published` rows with an `approved` current release ever appear —
nothing in `draft`, `in_review`, or `rejected` state is visible to a browsing tenant, ever.

## 3. The plugin detail page and the consent screen

The detail page shows: full description, screenshots, changelog, developer identity, and —
rendered prominently, not buried — the **itemized capability list** from the current release's
manifest ([04 §3](04-manifest-and-package-format.md)), each row showing:

- The plain-language label from `plugin_capabilities_catalog.label`
  ([03 §1](03-database-schema.md)) — never the developer's own free-text description of what a
  capability does.
- A risk-tier badge (low/medium/high) driving visual weight — a `high` capability (e.g.
  `http.egress`) is visually distinct from a `low` one (e.g. `plugin_data.read`), so a tenant
  scanning quickly still notices the capability that matters most.
- For a parameterized capability — today, only `http.egress` — **the actual declared parameter
  values, not just the generic label**: the exact hostnames the manifest names
  ([04 §3.1](04-manifest-and-package-format.md)), listed out (`api.some-saas.example`,
  `hooks.some-saas.example`) rather than a vague "this plugin can access the internet." There is
  nothing to type here — the tenant is approving or rejecting the developer's declared list
  verbatim, never entering a host themselves ([04 §3.1](04-manifest-and-package-format.md)).

Clicking **Install** does not install immediately — it opens the consent screen (07 §3), a
required, explicit re-statement of the same capability list with a confirmation action. There is
no "Accept All" that bundles installation and consent into one click without the list actually
being shown; the list is the screen, not a link off of it.

## 4. Installed

Grouped by active/disabled. Each row shows: name, version, an **update available** indicator when
`plugins.latest_published_release_id` differs from the install's `release_id`
([03 §2](03-database-schema.md)), and a menu: **Settings**, **Permissions**, **Update**,
**Disable**/**Enable**, **Uninstall**.

- **Permissions** re-shows the current grants (03 §2), including the exact approved parameter
  values for a parameterized capability (e.g. the specific `http.egress` host list,
  `plugin_grants.parameters_json`), with per-capability revoke — revoking one capability the
  plugin actually needs at runtime results in that specific SDK call failing for the plugin going
  forward (05 §4's dispatcher checks grants per invocation, so this takes effect immediately),
  which the UI states plainly rather than implying the plugin will "still mostly work."
- **Update** shows the diff between the currently-installed manifest and the new release's
  manifest — new capabilities highlighted, **and a changed parameter on an already-granted one
  (e.g. a host added to `http.egress`) highlighted the same way**, per
  [04 §6](04-manifest-and-package-format.md) — and only proceeds through the consent screen if
  there is anything new to show (07 §3).
- **Disable** is reversible and does not touch data (07 §5). **Uninstall** shows the grace-period
  data-retention notice (07 §5) before confirming.
- A `disabled_reason` of `kill_switch` or `circuit_breaker` (05 §7, 13 §2) is shown with a distinct,
  non-dismissible banner explaining what happened and linking to more detail — not the same visual
  treatment as a tenant's own voluntary disable.

## 5. A plugin's own settings screen

Rendered via the `settings.panel` slot ([06 §2](06-extension-points-and-hooks.md)) — the plugin's
own frontend bundle, inside the same iframe/RPC mechanism as everywhere else
([08 §3](08-frontend-runtime-and-dynamic-loading.md)). The admin dashboard supplies the page
chrome (title, breadcrumb, the surrounding "Installed" context); everything inside the panel is
the plugin's own UI.

## 6. Developer portal

Where a developer registers (`plugin_developers`, [03 §1](03-database-schema.md)), submits a zip,
tracks review status ([10](10-review-and-publishing-pipeline.md)), manages versions, and — once
[12](12-billing-and-monetization.md) is built — sets pricing and views payout history. This is also
where the scaffold/CLI described in
[15](15-developer-scaffolding-and-local-development.md) is documented and downloaded from — the
tool a developer actually uses before there is anything here to submit. Whether
this lives inside the same admin dashboard shell (as a section only a `plugin_developers`-linked
account can see) or as a wholly separate small application is an open question (00 §6 Q5) with no
bearing on the rest of this plan either way — the submission API it talks to
([10](10-review-and-publishing-pipeline.md)) is the same regardless of which frontend calls it.

## 7. Activity / audit viewer

A tenant-facing view over `plugin_audit_log` ([03 §4](03-database-schema.md)), filterable by
plugin, filtered by default to a human-readable subset (capability invocations grouped, not one
row per call) — this is what makes G4's "and can review... later" concrete: a tenant is not
asked to trust a plugin's behavior blindly after granting it, they have a place to go check what
it has actually done.

## 8. What is deliberately plain, not flashy

This is an admin tool, not a storefront. No autoplay video, no dark-pattern "recommended" upsell
styling on install prompts, no countdown timers on trial offers once [12](12-billing-and-monetization.md)
exists. The consent screen in particular (§3) is deliberately unstyled-for-persuasion — its whole
job is to be read, not clicked through.

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | Sidebar item, Browse, plugin detail page, consent screen | A tenant can browse the catalog and see a real capability list before installing anything |
| **P1** | Installed, Settings panel rendering, Permissions revoke | A tenant can fully manage an install's lifecycle without leaving the admin dashboard |
| **P2** | Update diffing and banner | An update with new capabilities cannot be applied without the consent screen reappearing |
| **P3** | Activity viewer | Every capability invocation a plugin makes is visible to the tenant who granted it |
| **P4** | Developer portal | A developer can complete a full submit → track-review → publish cycle without contacting the platform team out of band |
