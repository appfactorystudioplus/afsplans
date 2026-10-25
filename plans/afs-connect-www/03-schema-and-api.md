# 03 — Schema & API

## 3.1 Coordinator schema extensions

Migration on coordinator (extend existing `afsconnect_*` from base plan):

### `afsconnect_index` — add website dimensions

```php
Schema::table('afsconnect_index', function (Blueprint $table) {
    $table->string('website_domain', 255)->nullable()->after('member_reference_num');
    $table->string('website_reference_num', 64)->nullable()->after('website_domain');

    $table->dropUnique('afsconnect_index_source_unique');
    $table->unique(
        ['source_app', 'source_id', 'website_reference_num'],
        'afsconnect_index_source_website_unique'
    );
    $table->index(['member_reference_num', 'website_domain']);
});
```

Nullable for backward compatibility with notes/graph/flow/pdf rows (NULL = N/A).

### `afsconnect_settings` — www module + per-website toggles

```php
Schema::table('afsconnect_settings', function (Blueprint $table) {
    $table->boolean('source_www_forms')->default(true)->after('source_pdf_editor');
    $table->boolean('source_www_direct_payments')->default(true)->after('source_www_forms');
    $table->boolean('source_www_sponsorships')->default(true)->after('source_www_direct_payments');
    $table->boolean('source_www_appointments')->default(true)->after('source_www_sponsorships');
    $table->boolean('source_www_subscribers')->default(true)->after('source_www_appointments');
    $table->boolean('source_www_analytics')->default(true)->after('source_www_subscribers');
    $table->boolean('source_www_ecommerce')->default(false)->after('source_www_analytics');   // stub — default off
    $table->boolean('source_www_events')->default(false)->after('source_www_ecommerce');       // stub — default off
    $table->json('disabled_website_domains')->nullable()->after('source_www_events');
});
```

Stub modules default **off** at settings layer so members opt in when preview is acceptable.

`disabled_website_domains`: `["old-project.com", "personal-blog.net"]`

### `afsconnect_links` — website on link rows

```php
Schema::table('afsconnect_links', function (Blueprint $table) {
    $table->string('website_reference_num', 64)->nullable()->after('source_id');
    $table->string('website_domain', 255)->nullable()->after('website_reference_num');

    $table->dropUnique('afsconnect_links_unique');
    $table->unique(
        ['linkable_type', 'linkable_client_id', 'source_app', 'source_id', 'website_reference_num'],
        'afsconnect_links_website_unique'
    );
});
```

### `afsconnect_website_sync_status` (new, optional but recommended)

Tracks backfill/reconcile health per member per website.

```php
Schema::create('afsconnect_website_sync_status', function (Blueprint $table) {
    $table->id();
    $table->string('member_reference_num')->index();
    $table->string('website_reference_num', 64);
    $table->string('website_domain', 255);
    $table->string('status', 16)->default('pending'); // pending | syncing | ok | error
    $table->timestamp('last_synced_at')->nullable();
    $table->text('last_error')->nullable();
    $table->timestamps();

    $table->unique(['member_reference_num', 'website_reference_num'], 'afsconnect_web_sync_unique');
});
```

Powers Members UI: *"example.com — synced 2 min ago"* vs *"shop.io — sync failed"*.

## 3.2 Tenant DB schema (www — edit create migrations, not alters)

`apps/www` has not been migrated against a live database yet. **Do not add alter migration files.** Instead:

1. Edit the original `Schema::create` migration for each table.
2. Log the equivalent raw SQL in **[db_table_alter.md](db_table_alter.md)**.

See that file for the full convention, checklist, and the planned afs-connect-www column additions.

### Columns to add (planned)

| Table | Migration file to edit |
|-------|------------------------|
| `laravel_forms` | `www/database/migrations/2026_04_11_000001_create_laravel_forms_tables.php` |
| `laravel_direct_payments` | `www/database/migrations/2026_04_03_160000_create_laravel_direct_payments_table.php` |

Both tables get website scoping columns (see [db_table_alter.md](db_table_alter.md)).

**Tier A parent tables** — same columns via edit-create migration:

| Table | Migration file |
|-------|----------------|
| `laravel_sponsorships` | `2026_05_02_000001_create_laravel_sponsorships_tables.php` |
| `laravel_appointments` | `2026_04_13_000001_create_laravel_appointments_table.php` |
| `laravel_ecommerce_stores` | `2026_04_03_133338_create_ecommerce_tables.php` |

**Tier B/C new create migrations** (allowed — brand-new tables, not alters):

| Table | New migration file |
|-------|-------------------|
| `laravel_analytics_daily_rollups` | `2026_XX_XX_create_laravel_analytics_daily_rollups_table.php` |
| `laravel_events` | `2026_XX_XX_create_laravel_events_tables.php` (replaces legacy `events`) |
| `laravel_ecommerce_orders` | `2026_XX_XX_create_laravel_ecommerce_orders_table.php` (checkout stub) |

Populate website columns at write time from tenant boot config (`subdomain_properties`).

Raw SQL for all changes: [db_table_alter.md](db_table_alter.md).

## 3.3 Brain internal API (meta-provider)

Base path: `/api/internal/afs-connect/v1/` on **Brain** instance.

Auth: `AfsConnectServiceAuthMiddleware` — caller is coordinator (or members for settings passthrough).

### `GET .../websites`

List member's websites eligible for afs-connect.

Query: `member_reference_num` (required)

```json
{
  "websites": [
    {
      "website_reference_num": "WS-abc",
      "domain_name": "example.com",
      "tenant_subdomain": "x1sabc123def",
      "contact_base_url": "https://x1sabc123def.sites.appfactory.studio",
      "tenant_reference_num": "TN-xyz",
      "sync_status": "ok",
      "forms_count": 5,
      "direct_payments_count": 2
    }
  ]
}
```

### `GET .../summary`

Query: `member_reference_num`, optional `website_domain`

```json
{
  "source_apps": [
    "www_forms", "www_direct_payments", "www_sponsorships", "www_appointments",
    "www_subscribers", "www_analytics", "www_ecommerce", "www_events"
  ],
  "counts": {
    "www_forms": { "total": 8, "updated_last_7d": 2 },
    "www_sponsorships": { "total": 2, "pending_applications": 3 },
    "www_ecommerce": { "total": 1, "stub": true }
  },
  "by_website": [
    { "domain_name": "example.com", "forms": 5, "sponsorships": 1, "modules_stub": ["www_events"] }
  ]
}
```

### `GET .../changes`

Query: `member_reference_num`, `since`, `source_app` (optional filter), `website_domain` (optional), `cursor`, `limit`

Same item envelope as base afs-connect §4.1 plus:

```json
{
  "items": [
    {
      "source_type": "www_form",
      "source_id": "vabc123",
      "website_domain": "example.com",
      "website_reference_num": "WS-abc",
      "op": "upsert",
      "title": "Contact us",
      "metadata": { "submission_count": 12 }
    }
  ]
}
```

### `GET .../items/{source_app}/{source_id}`

Query: `member_reference_num`, `website_domain` (required), `requested_by_checkpoint_user_id`

### `GET .../health`

Returns `{ "status": "ok", "provider_version": "1.0.0", "tenant_db_reachable": true }`

## 3.4 Coordinator public API extensions

| Method & path | Change |
|---------------|--------|
| `GET /api/afs-connect/status` | Add `www_forms`, `www_direct_payments` to `sources`; list `websites[]` with sync status |
| `GET /api/afs-connect/search?q=` | Support `website_domain`, `source_app=www_forms` filters |
| `GET /api/afs-connect/websites` | **New** — list member websites with counts (proxies Brain) |
| `POST /api/afs-connect/links` | Require `website_reference_num` + `website_domain` for www source apps |

## 3.5 Members settings API extensions

Extend `POST /api/internal/afs-connect/v1/settings` body:

```json
{
  "sources": {
    "www_forms": true,
    "www_direct_payments": true,
    "www_sponsorships": true,
    "www_appointments": true,
    "www_subscribers": true,
    "www_analytics": true,
    "www_ecommerce": false,
    "www_events": false
  },
  "disabled_website_domains": ["legacy-site.com"]
}
```

Members dashboard UI: master toggles for Forms / Direct Payments + checklist of member's websites from `members_websites`.

## 3.6 Service auth keys & environment URLs

Add to **each app's own `.env`** (and `.env.example`):

```env
# Required in EVERY app (coordinator, notes, dream_graph, flow, pdf_editor, members, www)
BRAIN_API_URL=https://www15.appfactory.studio/portal/api/v1/brain

# afs-connect service auth (per app — see base plan §06)
AFS_CONNECT_SERVICE_KEY=...
AFS_CONNECT_SERVICE_KEY_COORDINATOR=...
AFS_CONNECT_SERVICE_KEY_NOTES=...
AFS_CONNECT_SERVICE_KEY_DREAM_GRAPH=...
AFS_CONNECT_SERVICE_KEY_FLOW=...
AFS_CONNECT_SERVICE_KEY_PDF_EDITOR=...
AFS_CONNECT_SERVICE_KEY_MEMBERS=...
AFS_CONNECT_SERVICE_KEY_WWW=...

# Tenant → coordinator webhooks (source apps + www tenant portals)
AFS_CONNECT_COORDINATOR_URL=https://coordinator.dev.appfactory.studio

# Coordinator / Brain → members entitlements (separate from Brain)
MEMBERS_API_URL=https://members.dev.appfactory.studio
```

**Brain URL rule:** all Brain control-plane calls use `BRAIN_API_URL` only — never hardcode www hostnames in code. Coordinator's `WwwBrainClient` derives the www afs-connect internal API base from the same variable (see [06-environment-config.md](06-environment-config.md)).

Update `config/afs_connect.php` `peer_keys` in coordinator, members, and www.

## 3.7 Index `source_app` naming

| `source_app` | Module | Tier |
|--------------|--------|------|
| `www_forms` | Forms | A |
| `www_direct_payments` | Direct payments | A |
| `www_sponsorships` | Sponsorships | A |
| `www_appointments` | Appointments | A |
| `www_subscribers` | Subscribers (summary row) | B |
| `www_analytics` | Analytics (summary row) | B |
| `www_ecommerce` | E-commerce stores | C stub |
| `www_events` | Events | C stub |

Coordinator `AfsConnectEntitlementService` maps each to `source_www_*` settings columns.

Detail: [05-modules-reference.md](05-modules-reference.md).

## 3.8 Contract versioning

Brain meta-provider ships as **`v1`** alongside other source apps. Breaking changes bump to `v2`. Coordinator `WwwBrainClient` declares version independently of `NotesClient`.

## 3.9 Test matrix (QA)

| Global | Plan | Member | www_forms | www_DP | Website disabled | Expected |
|--------|------|--------|-----------|--------|------------------|----------|
| off | — | — | — | — | — | No www data anywhere |
| on | yes | yes | on | on | none | All sites, both modules |
| on | yes | yes | on | off | none | Forms only |
| on | yes | yes | on | on | `example.com` | All sites except example.com |
| on | yes | yes | on | on | tenant DB down | Other sites OK; example.com shows sync error |
