# afs-connect — completed build steps

Per-step build records for the [afs-connect](../README.md) plan. Each file describes
**what was built** and **how the feature works end-to-end**.

## Convention note

These are **chronological build steps (01–14)**, not per-plan-document completion
notes. They deliberately do **not** map one-to-one onto the plan's numbered
documents (`00-overview.md` … `13-step-60-...`) — step 01 is "coordinator index
upsert", not "completion of doc 01". Do not rename them onto plan-document
basenames; the numbering is a build order, and several docs cross-reference each
other by it.

Every step here is `apps/coordinator` plus, in some steps, the four creative
source apps, `apps/members`, or `apps/www`/`apps/auth` for the Brain admin
surface. Two steps also carry slices of the
[afs-connect-www](../../afs-connect-www/README.md) extension — step 07 (the
website-module toggles and disabled-domain list in the Brain admin tab) and
step 13 (§"www — phase 5 — forms", the `www_forms` ingest webhook). The www
**provider layer itself** is not documented by any step here; see
[../../afs-connect-www/complete/README.md](../../afs-connect-www/complete/README.md).

| Step | Document | Summary |
|------|----------|---------|
| 01 | [01-coordinator-index-upsert.md](./01-coordinator-index-upsert.md) | Brain/webhook items → `afsconnect_index` upsert + activity log |
| 02 | [02-coordinator-public-api.md](./02-coordinator-public-api.md) | Public PM API: summary, search, activity, links, items |
| 03 | [03-coordinator-pm-ui.md](./03-coordinator-pm-ui.md) | Minimal `#/afs-connect` PM dashboard section |
| 04 | [04-webhook-queue-job.md](./04-webhook-queue-job.md) | Async `ProcessAfsConnectWebhookJob` for ingest |
| 05 | [05-backfill-and-reconcile.md](./05-backfill-and-reconcile.md) | Backfill job + enhanced `afs-connect:reconcile` command |
| 06 | [06-task-linking-ui.md](./06-task-linking-ui.md) | Attach/search afs-connect items from task edit panel |
| 07 | [07-brain-admin-afs-connect.md](./07-brain-admin-afs-connect.md) | Brain Memberships tab to enable afs-connect per member |
| 08 | [08-creative-source-clients.md](./08-creative-source-clients.md) | Coordinator clients + internal API on notes/flow/dream_graph/pdf |
| 09 | [09-kanban-badges-item-drawer.md](./09-kanban-badges-item-drawer.md) | Kanban link badges + item detail offcanvas |
| 10 | [10-checkpoint-global-flag-brain.md](./10-checkpoint-global-flag-brain.md) | Brain admin toggles Checkpoint `afs_connect` global flag |
| 11 | [11-members-self-service-afs-connect.md](./11-members-self-service-afs-connect.md) | Members settings panel → coordinator settings API |
| 12 | [12-live-items-and-backfill-status.md](./12-live-items-and-backfill-status.md) | Creative `/items/{id}` + backfill_status + PM banners |
| 13 | [13-webhooks-ingest.md](./13-webhooks-ingest.md) | Queued ingest webhooks from notes/flow/dream_graph/pdf + www forms |
| 14 | [14-insights-ui.md](./14-insights-ui.md) | PM insights cards (unlinked tasks & orphaned items) |

## Related plan docs

- [afs-connect overview](../README.md)
- [afs-connect-www overview](../../afs-connect-www/README.md)
- [API contracts](../04-api-contracts.md)
- [Environment — coordinator](../../_env/10-coordinator-env-variables.md)
- [Environment — Brain www](../../07-www-env-variables.md)

## Follow-ups raised during the build

1. ~~Brain `/changes` pagination cursor loop for large tenants~~ — **done** (www `has_more` / `next_cursor`; coordinator paginates in `AfsConnectAggregatorService`)
2. ~~Notes / flow / dream_graph / pdf internal provider clients on coordinator~~ — **done** (`SourceAppAfsConnectClient`, reconcile fan-out; internal `/changes` on each source app)
3. ~~Kanban card link badges + item detail drawer~~ — **done** (see [09-kanban-badges-item-drawer.md](./09-kanban-badges-item-drawer.md))
4. ~~Checkpoint global flag admin UI (or Brain link to auth feature flags)~~ — **done** ([10-checkpoint-global-flag-brain.md](./10-checkpoint-global-flag-brain.md))
5. ~~Members self-service settings panel for source toggles~~ — **done** ([11-members-self-service-afs-connect.md](./11-members-self-service-afs-connect.md))
6. ~~Live `/items/{id}` on creative apps + real `backfill_status`~~ — **done** ([12-live-items-and-backfill-status.md](./12-live-items-and-backfill-status.md))
7. ~~Webhook dispatch from notes/flow/dream_graph/pdf on create/update/delete~~ — **done** ([13-webhooks-ingest.md](./13-webhooks-ingest.md))
8. ~~Coordinator insights views (undocumented work, orphaned items)~~ — **done** ([14-insights-ui.md](./14-insights-ui.md))
9. afs-connect-www module rollout beyond current Brain indexing ([afs-connect-www/04-rollout-plan.md](../../afs-connect-www/04-rollout-plan.md)) — **partial** (forms ingest webhooks; other www modules still reconcile-only; pass tenant fields from site admin UI where needed)


## Current open items

The follow-up list above is closed out except for item 9. For the plan's live
open items — the two unbuilt step-60 phases (staleness, link integrity), the
aggregator's www-module gap, and the unwired capacity meters — see
**[../README.md § Open items](../README.md)**, which was re-verified against the
codebase on 2026-08-23.
