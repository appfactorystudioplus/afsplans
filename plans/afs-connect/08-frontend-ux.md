# 08 — Frontend UX

Two frontends are touched: the **Members dashboard** (entitlement + on/off
control — the member's "account settings" view of the feature) and
**coordinator's** own UI (the actual analysis/linking experience).
`notes`, `dream_graph`, `flow`, `pdf_editor` get no new UI in v1 — they are
pure data sources.

## Members dashboard: "AFS Connect" settings panel

New panel, likely under an existing "Integrations" or "Apps" section of the
member dashboard (`apps/members`). Reachable from account settings, not
gated behind coordinator.

**States:**

1. **Not entitled (plan doesn't include it).** Shows a short description of
   the feature, a comparison to what data it can surface, and an "Upgrade
   plan" CTA linking to the existing plan-change flow
   (`ProcessorSubscriptionController`). Toggle is visibly present but
   disabled/greyed, not hidden — the member should always know the feature
   exists and how to get it.
2. **Entitled, disabled.** Master toggle "Off". Turning it on triggers a
   short explainer modal ("Coordinator will be able to show summaries and
   links to your Notes, Graphs, Flow drawings, and PDFs. You can turn off
   individual apps below, or disable this at any time.") before the first
   `POST /api/internal/afs-connect/v1/settings {enabled: true}` call, since
   this is a one-click action with real data-visibility consequences.
3. **Entitled, enabled.** Master toggle "On", plus four per-app toggles
   (Notes / Dream Graph / Flow / PDF Editor), each independently
   switchable, matching `afsconnect_settings.source_*` (§03). A small
   status line shows last sync state per app (from `afsconnect_index`'s
   most recent `indexed_at` for that member+app, exposed via
   `GET /api/afs-connect/status` proxied through members' backend or called
   directly by the members frontend against coordinator, same-origin CORS
   allowed per the domain list in
   [01-current-state-audit.md](01-current-state-audit.md) §7).
4. **Auto-disabled (plan downgrade).** Master toggle shown "Off" with an
   inline note: "Disabled because your current plan doesn't include AFS
   Connect (was on until <date>)." — sourced from
   `afsconnect_settings.disabled_at` + a `reason` the settings endpoint
   should also persist (small addition to the schema in
   [03-database-schema.md](03-database-schema.md): a nullable
   `disabled_reason` string column, `'member' | 'plan_downgrade' | 'admin'`).

## Coordinator: `/afs-connect` section

Only rendered/routed when `GET /api/afs-connect/status` returns
`enabled: true` for the current member; otherwise coordinator shows the
same "not enabled" / "upgrade" messaging as the Members panel (states 1–2
above), with a deep link to the Members settings page rather than
duplicating the toggle UI inside coordinator — **one place owns the
on/off control** (Members), coordinator only ever displays status +
deep-links there, avoiding two UIs racing to change the same setting.

**Screens:**

1. **Overview dashboard.** Per-source-app tiles (count, last-updated, a
   sparkline of `afsconnect_activity_log` volume over the last 30 days),
   and a unified activity timeline merging events from all four apps
   (icon + app name + title + relative time + actor).
2. **Cross-app search.** A single search box; results grouped by source
   app, each result showing title/snippet/updated-at and a "Link to..."
   action plus an "Open" action (the latter navigates to the source app's
   own URL for that item — coordinator does not attempt to render notes/
   graphs/PDFs inline in v1, it deep-links out, except where the live
   `items/{id}` preview is used for a lightweight inline preview panel).
3. **Project/task linking.** From a `pm_projects` or `pm_tasks` detail view
   (existing coordinator screens), a new "Linked items" panel: shows
   existing `afsconnect_links` for that entity, an "Add link" button that
   opens the same cross-app search UI scoped to "attach to this item"
   mode, and per-link remove action.
4. **Insights (analytics).** A small set of computed views, all derived
   from `afsconnect_index` + `pm_*` joins already available to coordinator:
   - Tasks/projects with zero linked items ("undocumented work").
   - Items in other apps updated recently that aren't linked to any active
     project (surfaces orphaned work worth triaging).
   - Per-team activity breakdown across all five apps for a selected date
     range.
   These are read-only, computed on request (with short caching), not a
   new stored-analytics subsystem in v1.

## Empty/edge states worth designing explicitly

- **Just enabled, backfill in progress.** `GET /api/afs-connect/status`
  includes a `backfill_status: 'pending' | 'in_progress' | 'complete'` flag
  (derived from whether `AfsConnectBackfillMemberJob` has finished for this
  member) so the dashboard can show "Still importing your data from Notes,
  Dream Graph, Flow, and PDF Editor — this can take a few minutes for large
  accounts" instead of an empty state that looks broken.
- **A source app entirely disabled by the member.** That app's tile shows
  as "Off" with a one-click re-enable, not hidden — consistent with the
  member panel's philosophy of always showing what exists and its state.
- **A linked item's source was deleted.** `afsconnect_links` rows outlive a
  deleted `afsconnect_index` row (loose reference, §03.3); the UI shows the
  link with a "no longer available" state and a remove action, rather than
  silently vanishing or erroring.
