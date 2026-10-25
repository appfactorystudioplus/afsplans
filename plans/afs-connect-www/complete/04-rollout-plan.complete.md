# 04-rollout-plan — Completion

**Verified:** 2026-08-23 (by code inspection against `apps/www`, `apps/coordinator`, `members`)

**Status:** **Partial.** Phases 0–4 are substantially built — all eight module
providers exist, with e-commerce and events correctly shipped as stubs. Phase 5
is roughly half done: module toggles reached members, but webhooks cover only
one of four Tier A modules, and the hardening items have no evidence at all.

The tenant-database-isolation question that this record originally raised as a
divergence was **settled on 2026-08-23**: the plan contradicted itself, the
deployment-scoped model the code implements was kept, and
[00-overview.md](../00-overview.md) and [02-architecture.md](../02-architecture.md)
were corrected to match. No code changed. See **Divergences** below — including
the consequence it exposes, which is now open item 9.

## Summary

This document records the state of the www extension as found in the codebase.
It was **not** written at build time — the www provider layer was built without
per-step completion notes, and this record was reconstructed by reading the code.
Everything below is a verified observation, not a report of intent.

### Phase 0 — Prerequisites

| Item | State | Evidence |
|------|-------|----------|
| 1. Tenant DB resolver | ✅ exists (deployment-scoped) | `www/app/AfsConnect/Services/TenantDatabaseResolver.php` — resolves the member's websites from the Brain registry, but does **not** open per-tenant connections; see Divergences |
| 2. Coordinator schema extensions | ✅ built | `2026_07_22_000001_create_afsconnect_tables.php` — `website_domain`/`website_reference_num` on `afsconnect_index` and `afsconnect_links`, all eight `source_www_*` booleans, `disabled_website_domains` json, and the `afsconnect_website_sync_status` table |
| 3. Service keys | ⚠️ partial | `AFS_CONNECT_SERVICE_KEY_WWW` present in both `apps/coordinator/config/afs_connect.php` and `www/config/afs_connect.php`. **`AFS_CONNECT_SERVICE_KEY_WWW_TENANT` does not appear in either app's config** |
| 4. Brain `config/afs_connect.php` peer keys | ✅ built | `www/config/afs_connect.php` peer key map |
| 5. `BRAIN_API_URL` in every app | ✅ present | Found in coordinator, notes, flow, dream_graph, pdf_editor, members, www |
| 6. www schema — new create migrations | ✅ built | `2026_07_23_000001_create_laravel_events_tables.php`, `..._000002_create_laravel_ecommerce_orders_table.php`, `..._000003_create_laravel_analytics_daily_rollups_table.php` |
| 7. Stub scaffold + contract | ✅ built | `Providers/WwwEcommerceProvider.php`, `Providers/WwwEventsProvider.php`, `Contracts/ProvidesWwwModuleData.php` |
| 8. Website registry join | ⚠️ narrower than planned | `TenantDatabaseResolver::websitesForMember()` reads **`brain_http_server_tenants` only** (`BrainHttpServerTenant` where `active = 1`). It does not join `members_websites`. `subdomain_properties` is used separately, in `Support/WwwAfsConnectTenantFromRequest.php` |

### Phase 1 — Tier A core: Forms + Direct payments

| Item | State | Evidence |
|------|-------|----------|
| 1. `WwwFormsProvider`, `WwwDirectPaymentsProvider` | ✅ built, non-stub | Both return `false` from `isStub()` |
| 2. Meta provider + internal API + `WwwBrainClient` | ✅ built | `WwwAfsConnectMetaProvider.php`; `www/routes/api.php` exposes `/websites`, `/summary`, `/changes`, `/items/{source_app}/{source_id}`, `/health` behind `afs_connect.service`; `coordinator/app/Services/AfsConnect/WwwBrainClient.php` |
| 3. Reconcile-only | ✅ matches | `AfsConnectAggregatorService` pulls Brain `/changes` with cursor pagination |
| 4. **Coordinator UI tiles: websites, forms, DP per domain** | ❌ **not built** | `resources/js/src/afsConnect.ts` renders `website_domain` only as an inline suffix on result rows. There is no websites list and no per-domain tile |

### Phase 2 — Tier A extended: Sponsorships + Appointments

| Item | State | Evidence |
|------|-------|----------|
| 1. Providers | ✅ built, non-stub | `WwwSponsorshipsProvider`, `WwwAppointmentsProvider` — `isStub()` returns `false` |
| 2. Reconcile fan-out | ✅ covered | Same `/changes` path as Phase 1 |
| 3. Coordinator UI tiles per website | ❌ not built | Same gap as Phase 1 item 4 |

### Phase 3 — Tier B: Subscribers + Analytics aggregates

| Item | State | Evidence |
|------|-------|----------|
| 1. `WwwSubscribersProvider` | ✅ built, non-stub | `isStub()` returns `false` |
| 2. `WwwAnalyticsProvider` | ✅ built, non-stub | `isStub()` returns `false`; rollups table migration exists |
| 3. `analytics:rollup-daily` command | ❌ not built | No rollup or analytics command in `www/app/Console/Commands/`. The plan marks this **optional** |
| 4. No raw analytics events indexed | ✅ consistent | Provider is aggregate-only |

### Phase 4 — Tier C stubs: E-commerce + Events

| Item | State | Evidence |
|------|-------|----------|
| 1. `WwwEcommerceProvider` stub | ✅ as designed | `isStub()` returns `true` |
| 2. `WwwEventsProvider` stub | ✅ as designed | `isStub()` returns `true` |
| 3. `laravel_events` migration | ✅ built | `2026_07_23_000001_create_laravel_events_tables.php` |
| 4. `laravel_ecommerce_orders` migration | ✅ built | `2026_07_23_000002_create_laravel_ecommerce_orders_table.php` |
| 5. Stub toggles default **false** | ✅ correct | `source_www_ecommerce` and `source_www_events` both `->default(false)`; every other source defaults `true` |

Phase 4 is the cleanest match to plan in the whole extension.

### Phase 5 — Webhooks, linking, insights, hardening

| Item | State | Evidence |
|------|-------|----------|
| 1. **Webhooks for Tier A modules** | ⚠️ **forms only** | `WwwAfsConnectIngestDispatcher` + `WwwAfsConnectTenantFromRequest` exist, but the only controller wired is `Portal/Admin/FormsController.php`. Direct payments, sponsorships, and appointments dispatch nothing — they remain reconcile-only. Recorded in [../../afs-connect/complete/13-webhooks-ingest.md](../../afs-connect/complete/13-webhooks-ingest.md) as "www (phase 5 — forms)" |
| 2a. Members: all module toggles | ✅ built | `members/.../AfsConnectSettingsController::MEMBER_SOURCE_KEYS` now carries all eight `www_*` keys, and `afsConnectSettings.ts` renders every source returned by the registry projection with no group filter. **This supersedes step 11's note** that "member UI does not expose www_* source toggles" — that was true when written, and the step-60 source-registry work changed it |
| 2b. Members: per-website checkboxes | ❌ not built | `disabled_website_domains` appears nowhere in `afsConnectSettings.ts`; per-website exclusion is still Brain-admin only, via the afs-connect tab |
| 3. Linking / search / insights across modules | ✅ source-agnostic | `AfsConnectQueryService`, `AfsConnectLinkService`, and `AfsConnectInsightsService` operate on `afsconnect_index` rows without special-casing source, so www rows participate |
| 4. Alerting, load test (10+ websites), key-rotation runbook | ❌ no evidence | Nothing found in code or docs |

## Divergences

### Tenant database isolation — resolved 2026-08-23 in favour of the code

[00-overview.md](../00-overview.md) **originally** stated that Brain "opens each
tenant's isolated DB" and was "the only component that opens tenant DBs" (both
passages have since been corrected — see the resolution below).
`TenantDatabaseResolver` does not do this. `withTenantConnection()` ignores the
`TenantContext` it is handed for connection selection and uses
`config('database.default')`:

```php
$connection = (string) config('database.default');
DB::connection($connection)->getPdo();
return $callback($connection);
```

The class docblock states this is deliberate:

> Tenant data queries use Laravel's default database connection (DB_* in .env).
> Brain deployment: default connection is the Brain central DB (tenant registry).
> Tenant portal deployment: default connection is that site's own tenant DB.

So the code assumes whichever deployment runs it already points at the right
data, rather than fanning out across per-tenant databases from one Brain process.

**The plan contradicted itself here.** [00-overview.md](../00-overview.md) and
[02-architecture.md](../02-architecture.md) described Brain opening each tenant's
isolated DB, while [07-www-env-variables.md](../../07-www-env-variables.md) stated
the opposite in as many words — "afs-connect reads tenant module data through
Laravel's **default database connection**", "No `AFS_CONNECT_TENANT_DB_*`
variables". The code follows the second.

**Correction — cross-tenant fan-out *is* feasible.** An earlier draft of this
record stated that no tenant DB credentials exist anywhere in the platform. **That
was wrong.** They are stored inside `brain_http_server_tenants.json` — an opaque
`TEXT` column, which is why a scan of the model's `$fillable` and of the Brain
controllers missed them. `FunctionsController` (≈L735–760, and again ≈L5710)
handles the keys `db_host`, `db_name`, `db_username`, `db_password` explicitly:
it copies the tenant's `json` entries into the tenant-side `subdomain_properties`
key/value table, then `unset()`s those four before returning the payload — i.e.
it strips them deliberately rather than not having them.

This matches [01-current-state-audit.md §7](../01-current-state-audit.md), which
said "Brain already stores enough to open a tenant DB" and named
`subdomain_properties`' `db_name` / `db_host`. That section was accurate.

What remains genuinely absent is only the *plumbing*: `www/config/database.php`
has no tenant connection template, nothing registers a runtime connection, and
`TenantContext::withConnection()` has no caller. Building per-tenant fan-out would
be a contained change — read the four keys from the tenant row, register a
connection, populate `TenantContext` — not a blocked one. It was **not** attempted
here; the decision below was to keep the deployment-scoped model, and that
decision now stands on preference rather than on impossibility.

**Decision (2026-08-23): keep the deployment-scoped model and correct the docs.**
`00-overview.md` and `02-architecture.md` now carry "As implemented" notes
describing what shipped. No code was changed. Note this decision was taken while
the record still wrongly claimed fan-out was impossible — it is worth re-testing
against the correction above before treating it as final.

**Known consequence, stated plainly.** `apps/www` is one codebase, so its
migrations create the module tables on every deployment. On Brain they exist but
hold no member-site data, so provider queries there return empty and the
coordinator reconcile path contributes no www module rows — leaving tenant-side
webhooks (forms only) as the sole populator of www rows in the index. This was
determined by code inspection; it should be confirmed against a live Brain
database and a populated `afsconnect_index` before being relied on.

### Dead per-website data path

Two pieces of the website dimension are built but unused:

- `WwwBrainClient::websites()` exists on the coordinator client and **has no
  caller** anywhere in `apps/coordinator/app`.
- `AfsConnectWebsiteSyncStatus` is **written** by `AfsConnectAggregatorService`
  and read by nothing — no query service, controller, or view surfaces it.

Both are consistent with Phase 1 item 4 (per-website UI tiles) never being built.

### Aggregator does not gate on per-`www_*` source status

`AfsConnectAggregatorService` calls `wwwBrain->changes()` unconditionally rather
than looping per enabled `www_*` source the way it does for the four creative
sources. Also recorded as open item 3 in
[../../afs-connect/README.md](../../afs-connect/README.md); noted here because it
affects every module in this plan.

## Correctness findings (2026-08-23)

Three defects found while checking whether the shipped code can deliver this plan's
stated purpose — a visitor fills in a form on a member's website, and the member
reviews that activity in coordinator. They compound: each one alone would prevent
the feature working.

### F1 — Submission counts are structurally always zero

`WwwFormsProvider::changes()` builds each index row's snippet by counting
`laravel_form_submissions`:

```php
$submissionCount = DB::connection($connection)
    ->table('laravel_form_submissions')
    ->where('form_reference_num', $form->reference_num)
    ->count();
```

**Nothing writes to that table.** Across both `www` (Brain) and
`packages/wwwafsportal_v1` (the tenant portal), every reference to
`laravel_form_submissions` is a *reader*: `WwwFormsProvider`,
`WwwAfsConnectIngestDispatcher`, and the `FormSubmission` model's `$table`
declaration. There is no insert anywhere.

Real visitor submissions are written by legacy raw-SQL paths in
`FunctionsController` into **differently named tables** — `form_submissions`
(`www` L8764, `wwwafsportal_v1` L8758) and `afsform_submissions` (L10632 / L10626).

So every indexed form reports `0 submissions`, forever, on both deployments. This
defeats the plan's headline use case on its own, and it is invisible — an empty
count looks like a quiet form, not a broken join.

### F2 — Providers never scope by website

`laravel_forms` **does** carry `website_domain` and `website_reference_num` (audit
gap #1 was fixed). None of the four Tier A providers filters on them. They read the
website fields only to *stamp* output rows from the `TenantContext`:

```php
$query = Form::on($connection)->orderByDesc('updated_at')->limit($limit);   // no website filter
...
'website_domain'        => $tenant->websiteDomain,      // stamped, not filtered
'website_reference_num' => $tenant->websiteReferenceNum,
```

Same pattern in `WwwDirectPaymentsProvider`, `WwwSponsorshipsProvider`, and
`WwwAppointmentsProvider`.

Alone this would be harmless — on a correctly-scoped connection every row already
belongs to that site. It is dangerous only in combination with F3.

### F3 — On Brain, unscoped queries read Brain's own data

`WwwAfsConnectMetaProvider` iterates the member's websites and calls each provider
through `withTenantConnection()`, which returns `config('database.default')`.
Coordinator only ever calls **Brain**, whose default connection is
`testdb_appfactorystudio` — the database Brain shares with `members`, holding
**appfactory.studio's own** forms, payments, sponsorships, and appointments.

Combined with F2, for every one of a member's N websites the providers return *the
same* Brain-owned rows, each stamped with that website's identity. The intended
result — "this member's website has 12 forms" — becomes "appfactory.studio's own
forms, attributed to the member's site, repeated N times."

Severity depends on whether Brain's module tables hold rows. Per the ecosystem
owner, Brain does keep its own forms that child sites cannot see, so the realistic
outcome is **platform-owned data surfacing in members' coordinator dashboards**.
This should be confirmed against the live Brain database before being treated as
either proven or dismissed — it is a code-path inference, not an observed leak.

### What actually works today

The **tenant → coordinator webhook** path is architecturally sound: it runs *on the
tenant*, against the tenant's own database, and sends genuine tenant context. It
is the only path that can populate correct per-website data, and it is wired for
forms only. Note it fires on form **create/update by an admin**
(`FormsController::formUpserted`) — **not** on a visitor submission, so even this
path does not carry the event the use case is about.

### Scope question this raises

The plan caps www indexing at counts: open decision #1 is "Index
submissions/bookings/analytics events individually? **No** — counts/aggregates
only", and [00-overview.md](../00-overview.md) lists "**Not** indexing every
individual form submission" as a non-goal, with the index holding "metadata only —
not submission bodies".

The stated purpose — a member reviewing *what someone submitted* — needs
submission-level data. Either the goal or the plan's non-goal has to move. That is
a product decision and is not resolved here.

## Verification method

Static inspection only — no application was run, no database was available, and
no request was issued against Brain or a tenant portal. Specifically:

- Provider maturity read from each class's `isStub()` return value.
- Schema claims read from the migration files, not from a live database.
- Route claims read from `www/routes/api.php` and `apps/coordinator/routes/api.php`.
- "Not built" claims are absence-of-evidence over the relevant directories
  (`www/app/Console/Commands/`, `afsConnectSettings.ts`, `afsConnect.ts`,
  `apps/coordinator/app/`) and should be read as such.

Nothing here has been exercised end to end. The plan's own exit criteria — "two
websites show form/DP counts after reconcile", "form create on tenant appears in
coordinator within seconds" — are **unverified**, and cannot be verified without
a running Brain plus at least one tenant deployment.

## Open items

1. **Phase 1/2 coordinator UI tiles** — per-website and per-module tiles were never
   built; `websites()` and `AfsConnectWebsiteSyncStatus` are waiting for them.
2. **Phase 5 webhooks** — extend beyond forms to direct payments, sponsorships,
   and appointments.
3. **Per-website checkboxes in members** (`disabled_website_domains`).
4. **`AFS_CONNECT_SERVICE_KEY_WWW_TENANT`** — named in Phase 0, absent from config.
5. **`analytics:rollup-daily`** — optional in the plan, not built.
6. **Hardening** — alerting, a 10+ website load test, and the key-rotation runbook.
7. ~~**Resolve the tenant-isolation divergence**~~ — **done 2026-08-23.** Docs 00
   and 02 corrected to the deployment-scoped model; no code changed. The
   downstream question it exposes is now item 9.
8. **Exit-criteria verification** — none of the six phases' exit criteria have been
   observed running.
9. **Confirm whether reconcile yields any www rows at all.** If the deployment-scoped
   model means Brain's providers always query empty tables, then reconcile is
   decorative for www and the index depends entirely on webhooks — which reach forms
   only. That would make Phase 5 item 1 (webhooks for the other three Tier A modules)
   a correctness gap rather than a nice-to-have. Needs one query against a live Brain
   database to settle.
10. **F1 — wire submission counts to the real table.** `laravel_form_submissions` has
    no writer; visitor submissions land in legacy `form_submissions` /
    `afsform_submissions`. Either point the providers at the legacy tables or
    dual-write. Highest value, smallest change.
11. **F2/F3 — scope provider queries by website.** Add
    `->where('website_reference_num', $tenant->websiteReferenceNum)` to the four
    Tier A providers. Cheap, and it makes the deployment-scoped model safe by
    construction rather than by luck.
12. **Confirm F3 against the live Brain database** before treating it as a real
    exposure or dismissing it.
