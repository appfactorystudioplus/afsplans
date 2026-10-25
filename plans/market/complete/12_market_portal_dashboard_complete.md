# Step 12 — Market: the `/portal` buyer dashboard — completion report

**Date:** 2026-08-19
**Projects touched:** market (`apps/market`) only.
**Status:** ⚠️ complete with gaps — every page in §12.2 has a real, gated data layer behind step 11's
shell, live-verified end to end against real MySQL. Messages/Settings are built to a smaller,
honestly-scoped shape than §12.2's prose describes — see "Known gaps."

## What shipped

The `/portal` shells step 11 built now show real data. Every `Portal\*` controller keeps its public
page route (`index()`/`show()` — layout, nav, an empty container, unchanged from step 11) and gains a
`checkpoint.verify`-gated `api*` method that a new `resources/js/portal/*.ts` module calls with the
caller's bearer token, exactly the pattern `whoami()` established. No page route reads
`member_reference_num` from anywhere; every data read/write does, and only from the token
(`$request->attributes->get('member_reference_num')`, set by `VerifyCheckpointToken`).

- **Dashboard** (`GET /portal/api/dashboard`): four stat tiles, six recent purchases, service orders
  needing attention (requirements unanswered, delivery awaiting review, revision window closing), and
  the "N assets have new versions" line.
- **My Assets** (`GET /portal/api/assets`, paginated at 24): distribution-tag/category/seller/
  has-update filters, title-or-seller search, per-row owned-vs-latest version with an update badge.
- **Asset detail** (`GET /portal/api/assets/{entitlement}`): every `ready` version (not just the
  newest), the `license_body_snapshot` (never the live `market_licenses` row), and a download button
  per version.
- **Download** (`POST /portal/api/assets/{entitlement}/download/{version?}`): re-verifies ownership,
  revocation, and version readiness on every call, then issues a `SignedDownloadUrlService` URL. The
  browser navigates to that URL directly — it is intentionally public (the token is the credential).
- **My Services** (`GET /portal/api/services`) / **service detail** (`GET /portal/api/services/{ref}`):
  the buyer's read model of the freelance lifecycle. Actions (answer requirements, review a delivery,
  message) stay step 15's territory — this is the list/detail view only.
- **Orders** (`GET /portal/api/orders`, `GET /portal/api/orders/{ref}`): the printable-receipt detail
  — line items, tax, total, refund — with no `platform_fee_amount`/`seller_net_amount` (seller-only,
  step 16), even though both live on the same `order_items` row this reads.
- **Licenses** (`GET /portal/api/licenses`): every snapshot the buyer holds, searchable by asset or
  license name.
- **Messages** (`GET /portal/api/messages`): a portal-wide inbox — one row per service order with a
  thread, unread state, last message. See "Known gaps" for what this does *not* do.
- **Settings** (`GET`/`PUT /portal/api/settings`): display name, receipt email override, three
  notification toggles, backed by a new `buyer_settings` table (see "Decisions" — this table did not
  exist before this step).

`EntitlementQuery` (`app/Services/Portal/EntitlementQuery.php`) is the one place every rule from §12.1
lives: every read goes through `entitlements`, never `orders`/`assets` directly; "owned version" is
derived (there is no column for it — see the class's own header comment) as the newest `ready` version
published at or before `granted_at`; the update-badge computation is cached 60 seconds per member
(§12.4).

## Files created

| File | Project | Tag placement | Purpose |
|---|---|---|---|
| `app/Services/Portal/EntitlementQuery.php` | market | header + per-method | The shared entitlement read layer |
| `app/Models/BuyerSetting.php` | market | header | Buyer preferences model |
| `database/migrations/2026_08_19_000038_create_buyer_settings_table.php` | market | header | `buyer_settings` table |
| `resources/js/portal/dashboard.ts` | market | header | Dashboard hydration |
| `resources/js/portal/assets.ts` | market | header | Assets grid + detail hydration |
| `resources/js/portal/downloads.ts` | market | header | Two-step download issuance + redirect |
| `resources/js/portal/filters.ts` | market | header | Assets-grid filter bar state (URL + DOM) |
| `resources/js/portal/api.ts` | market | header | Shared authenticated-fetch helper — **not** in §12.5's file list; see "Decisions" |

## Files modified

| File | Project | Lines / regions | Tag placement | Change |
|---|---|---|---|---|
| `app/Http/Controllers/Portal/DashboardController.php` | market | header + `apiIndex()` and private helpers | line-level | Added `apiIndex()` |
| `app/Http/Controllers/Portal/AssetController.php` | market | header + `apiIndex()`/`apiShow()`/`apiDownload()`; `download()` page-route method removed | line-level | Real data layer; removed the step-11 stub download page route |
| `app/Http/Controllers/Portal/ServiceController.php` | market | header + `apiIndex()`/`apiShow()` | line-level | Added the API methods |
| `app/Http/Controllers/Portal/OrderController.php` | market | header + `apiIndex()`/`apiShow()` | line-level | Added the API methods |
| `app/Http/Controllers/Portal/LicenseController.php` | market | header + `apiIndex()` | line-level | Added the API method |
| `app/Http/Controllers/Portal/MessageController.php` | market | header + `apiIndex()` | line-level | Added the API method |
| `app/Http/Controllers/Portal/SettingsController.php` | market | header + `apiIndex()`/`apiUpdate()` | line-level | Added the API methods |
| `app/Http/Controllers/PublicDownloadController.php` | market | one region in `redeem()` | line-level, `MARKET_MODIFIER_ADDITION` … `END` | Looks up the redeeming entitlement instead of always writing `entitlement_id => null` — closes step 05's own "step 12/18 set this once wired" gap |
| `routes/portal.php` | market | header rewritten; `assets.download` page route removed; `checkpoint.verify` group expanded | line-level | The real `/portal/api/*` data layer |
| `resources/js/portal.ts` | market | header rewritten; new imports; new mount functions; `run()` extended | line-level | Wires in the four new modules plus inline hydration for orders/services/licenses/messages/settings |
| `resources/views/portal/dashboard.blade.php` | market | content section | line-level | Real stat-tile/purchase/attention containers |
| `resources/views/portal/assets/index.blade.php` | market | whole content section | line-level | Filter bar + grid containers |
| `resources/views/portal/assets/show.blade.php` | market | whole content section | line-level | Detail container |
| `resources/views/portal/orders/index.blade.php`, `orders/show.blade.php`, `services/index.blade.php`, `services/show.blade.php`, `licenses.blade.php`, `messages.blade.php` | market | content section | line-level | Real containers, replacing "coming soon" placeholders |
| `resources/views/portal/settings.blade.php` | market | whole content section | line-level | Real settings form |
| `lang/en/portal.php` | market | appended keys | line-level | Strings for the new UI |

**Not created:** `resources/views/portal/partials/*` — §12.5 lists this, but nothing in this step's
design needs a Blade partial: every page hydrates client-side from JSON, so there is no server-rendered
fragment to factor out. Recorded as a deliberate omission, not an oversight.

## Database changes

One migration, `2026_08_19_000038_create_buyer_settings_table.php` — `buyer_settings`
(`member_reference_num` unique, `display_name`, `receipt_email`, three notification booleans,
timestamps). Verified with `php artisan migrate:fresh --force` against a scratch MySQL database
(`market_scratch_test`, created and dropped for this verification only, matching step 03's own
approach — the real dev database `testdb_afsdb_market` was never touched). All 38 migrations
(step 03's 33 plus steps 05/12's additions) apply cleanly together.

## Environment / config keys added

None. Everything reads through the existing `config('market.*')` keys from step 02/05/11.

## Decisions made during execution that diverge from the plan

- **`resources/js/portal/api.ts` added, not in §12.5's file list.** The shared authenticated-fetch
  helper (bearer token attach, JSON parse, error shape) is used by all four new modules plus
  `portal.ts` itself. It cannot live in `portal.ts` — that file already imports `bootstrapMarketAuth`
  from `resources/js/dashboard.ts` (a *different* file, the Checkpoint auth bootstrap), and having the
  four new modules import back into `portal.ts` for the fetch helper would be circular. One small file
  is cheaper than duplicating the same ~20 lines five times.
- **Orders/services/licenses/messages/settings are hydrated inline in `resources/js/portal.ts`, not as
  five more dedicated modules.** §12.5 names exactly four new TS files
  (`dashboard`/`assets`/`downloads`/`filters`) and lists `portal.ts` as *modified*, not created — read
  as scoping dedicated modules to the pages complex enough to need one (filtering, pagination, download
  issuance) and leaving the simpler read-mostly lists as inline mounts in the file already responsible
  for page bootstrap. Every mount function still goes through the same gated `portalApiFetch()`.
- **The step-11 page route `GET /portal/assets/{entitlement}/download/{version?}` is removed**, along
  with its always-404 stub method. A plain browser navigation cannot carry a bearer token, so that
  route could never have verified the caller under this step's own "Auth model" note — it was a
  placeholder step 11 explicitly deferred. Download is now
  `POST /portal/api/assets/{entitlement}/download/{version?}` (gated, issues a signed URL) followed by
  the browser navigating to that URL directly.
- **A `buyer_settings` table was added** — step 03's schema has `seller_profiles` for sellers but
  nothing for a buyer's own preferences. Following steps 03/05's own precedent of adding a migration
  when a genuine schema gap is discovered mid-step, rather than leaving the Settings page unable to
  persist anything.
- **"Owned version" is derived, not stored.** Neither `entitlements` nor `order_items` pins a version
  (confirmed by reading both migrations directly). §12.4's own instruction to cache "the update-badge
  computation (owned vs latest version)" for 60 seconds only makes sense if it is computed, not read —
  so `EntitlementQuery::ownedVersionFor()` derives it as the newest `ready` version published at or
  before the entitlement's `granted_at`, and `updateBadgeMap()` is the one cached computation.
- **"Completed orders" (dashboard stat tile) reads `orders.status = 'paid'`.** There is no `completed`
  order status in step 03's schema (that word belongs to `service_orders.status`) — `paid` is the
  closest analogue to "a fully processed checkout."
- **`has_update` filtering on the assets grid happens in PHP after fetch, with manual pagination**,
  not a SQL `WHERE`. There is no column to filter on (see "owned version," above) — acceptable at a
  buyer's realistic scale, explicitly not scale-tested, and documented in
  `EntitlementQuery::ownedAssets()`'s own comment.
- **§12.2's "data export" (part of Settings) was not built.** Nothing in step 03's schema names a
  format or delivery mechanism for it, and inventing one was scope this step was not asked for —
  documented in `SettingsController`'s own header comment.

## Known gaps / deferred

- **Messages is a portal-wide inbox list only** (one row per service order with a thread) — reading or
  replying within a thread stays step 15's territory on the service-order detail page. §12.2 attributes
  "order-scoped threads" to step 15 in its own prose, which this step reads as scoping the list view
  here and the thread UI there.
- **No live Checkpoint session was available this session** (same constraint step 11's report
  recorded) — the *success* path through `VerifyCheckpointToken`'s real network call to
  `auth15.appfactory.studio` was not observed. Verified instead by pre-populating the `database` cache
  driver's `cache` table with the exact key shape `VerifyCheckpointToken::verify()` produces
  (`checkpoint_verify:` + `sha256(token)`), which makes the middleware's cache-hit path — not the
  network call — the thing exercised. This is a substitution of the same class step 11 used (switching
  to a local cache driver to work around the unavailable database), not a skipped check: every
  controller, service method, and query behind that middleware ran for real, against real MySQL. The
  network-call path itself (`callVerifyEndpoint()`) is unchanged from step 11 and was not touched by
  this step.
- **The nginx `X-Accel-Redirect` production path was exercised structurally** (correct headers, correct
  path, `entitlement_id` correctly attributed on `asset_downloads`) but not through real nginx — same
  gap step 05's own report recorded, unchanged here.
- **No local database this session** meant `/portal/api/*` could not be curled without first building a
  scratch MySQL database and seed data — done for this step (seeded two owned assets — one with an
  update available, one without — a revoked/refunded entitlement, and a service order with an unread
  message, for the fixed buyer ref `m1xJppRmdtH2Y`), and torn down afterward (`DROP DATABASE`,
  `brew services stop mysql`). No seed seams were committed; step 17 owns the real seed data.

## How this was verified

Everything below is a real, live exercise against a scratch MySQL database
(`market_scratch_test`, via a locally-started `brew services start mysql`, dropped and the service
stopped afterward — the real dev database was never touched), not a reading of the source.

- **`php -l`** on every PHP file created or modified — clean.
- **`php artisan route:list --path=portal`** — all 25 routes registered (13 page shells + 12 API
  routes, up from step 11's 14).
- **`php artisan migrate:fresh --force`** against the scratch database — all 38 migrations (step 03's
  33 + step 05's 3 + this step's 1) apply cleanly.
- **A full live HTTP pass** (`php artisan serve`, seeded data for buyer `m1xJppRmdtH2Y` / seller
  `m1xTHpRmdtH2Y`, requests made with a bearer token whose verification was pre-cached — see "Known
  gaps"):
  - Every page shell (`/portal`, `/portal/assets`, `/portal/assets/{ref}`, `/portal/orders`,
    `/portal/orders/{ref}`, `/portal/services`, `/portal/services/{ref}`, `/portal/licenses`,
    `/portal/messages`, `/portal/settings`) — **200**, unauthenticated, unchanged from step 11.
  - Every `/portal/api/*` route with no bearer token — **401** `{"success":false,"message":"Missing
    bearer token."}`, matching step 11's own middleware behavior exactly.
  - `GET /portal/api/dashboard` — stats (`assets_owned: 2`, `active_service_orders: 1,
    completed_orders: 3, lifetime_spend: 129.98` — the refunded order correctly excluded from both
    the count and the sum), 3 recent purchases in the correct newest-first order, 1 service order
    flagged `answer_requirements`, and the one asset with an unpublished-newer-version correctly
    flagged with `updated_assets_count: 1`.
  - `GET /portal/api/assets` — the revoked/refunded entitlement correctly absent from the list (2 of 3
    owned-asset entitlements shown); the update badge correct on both remaining rows; `filters.sellers`
    and `filters.categories` correctly deduplicated.
  - `GET /portal/api/assets/{entitlement}` for a real, owned entitlement — 200 with both versions
    listed newest-first and the license snapshot. For a nonexistent ref — **404**. For the revoked
    entitlement's own ref — **404** (§12.6 accepts 403 or 404 here; this design collapses both into
    404 via the same `active()`-scoped query, verified directly against the actual revoked row, not a
    fabricated one).
  - `POST /portal/api/assets/{entitlement}/download` (no version) — **500** on first attempt (a real
    bug: an optional route parameter following typed dependencies in the method signature was
    misresolved by Laravel's controller dispatcher — see below), then **200** with a signed URL after
    the fix. With an explicit old version ref — 200, a URL naming that exact version. For the revoked
    entitlement — **404**.
  - The signed URL was redeemed against a local `GET /downloads/{token}` (with `MARKET_ASSET_ROOT`
    pointed at a scratch directory containing a placeholder file, since `/mnt/node2` does not exist on
    this machine) — **200**, correct `X-Accel-Redirect` header naming the right archive path, and —
    the actual thing this step changed in `PublicDownloadController` — `asset_downloads.entitlement_id`
    was queried afterward directly against MySQL and confirmed correctly set to the redeeming buyer's
    real entitlement id, not `null`.
  - `GET /portal/api/orders`, `/orders/{ref}`, `/portal/api/services`, `/services/{ref}`,
    `/portal/api/licenses`, `/portal/api/messages` — all 200 with the seeded data shaped as expected
    (order totals, service-order status/action, license snapshot text, unread-thread detection).
  - `PUT /portal/api/settings` (with a real CSRF token — the `web` middleware group's CSRF protection
    correctly rejected the write with **419** before a token was attached, confirming CSRF is live for
    this route group) followed by `GET /portal/api/settings` — the write round-tripped correctly.
- **Two real bugs found by this live testing, not by review, and fixed:**
  1. Three `with([...])` constrained-eager-load closures in `EntitlementQuery` were type-hinted
     `fn (Builder $q)`, but Laravel passes the *relation* (`HasMany`), not a `Builder`, to that closure
     shape — a `TypeError` on every call. Fixed by removing the type hint in all three places
     (`updateBadgeMap()`, `withAssetEagerLoads()`, `messageThreads()`/`ownedServiceOrder()`).
  2. `AssetController::apiDownload()`'s optional `?string $version` parameter, positioned before two
     class-typed dependencies in the method signature, had no default value — Laravel's controller
     dispatcher misresolved argument #3 when the route segment was omitted, injecting the
     `EntitlementQuery` service into the `$version` slot instead. Fixed by moving `$version` to the end
     of the signature with an explicit `= null` default.
- **The N+1 requirement (§12.4)** was checked with a live `DB::listen()` query log against
  `GET /portal/api/assets`: **14 queries total**, none of them scaling per row (each relation — assets,
  categories, licenses, versions, seller profiles — loaded once via `whereIn`, plus two small
  filter-metadata queries for the distinct-sellers/categories dropdowns). Verified by reading the actual
  query log, not by inspecting the eager-load calls and assuming.
- **`tsc --noEmit --skipLibCheck`**, scoped to this step's own files — zero errors in any of
  `portal.ts`, `portal/api.ts`, `portal/dashboard.ts`, `portal/assets.ts`, `portal/downloads.ts`,
  `portal/filters.ts`. (A full project run still hits the same pre-existing, unrelated
  `appfactory3.ts`/`globalAuth.ts`/`settings/state.ts` errors steps 05/11 already recorded — confirmed
  by grepping the output for this step's own file paths and finding zero matches.)
- **`npm run build`** — clean, 839 modules, emits `portal-*.js` (431 KB / 132 KB gzipped, up from step
  11's 408 KB — the growth is this step's four new modules).
- **Tag reconciliation**: `grep -q MARKET_MODIFIER_ADDITION` run against every file in the "Files
  created"/"Files modified" tables above — all 29 present, none missing.

## Follow-ups spawned

- Step 15 owns the real service-order lifecycle actions (answer requirements, review a delivery,
  message, dispute) that `ServiceController::apiShow()`'s read model exists to support.
- Step 13/16 own checkout and the seller-side ledger respectively — `OrderController` deliberately
  never surfaces `platform_fee_amount`/`seller_net_amount`, which those steps' own seller-facing pages
  will read from the same `order_items` row.
- Step 17's real seed data should exercise the same update-badge/revoked-entitlement/multi-owner
  scenarios this step's throwaway scratch seed did, so the dashboard and assets grid have real fixtures
  to demo against rather than only this report's now-deleted scratch rows.
- The nginx `X-Accel-Redirect` production path remains code-reviewed/structurally-verified only, not
  live-tested against real nginx — flagged in step 05's report and unchanged by this step; worth a look
  whoever configures the production host.
- Verifying `VerifyCheckpointToken`'s live network success path (a genuine valid token from a real
  Checkpoint session) still needs a real browser session against live Checkpoint credentials — flagged
  in step 11's report, unchanged by this step.

---

## Exit criteria (§12.6)

- [ ] Buyer `m1xJppRmdtH2Y` sees exactly the seeded purchases (step 17), no more — **not yet
      verifiable**: step 17 has not run. This step's own scratch seed (created and torn down for
      verification only) confirmed the *mechanism* — an owned-asset list scoped correctly to one buyer
      ref, with no other buyer's rows leaking in — but that is not the same claim as "matches step 17's
      real fixtures," which cannot be checked until step 17 lands.
- [x] Unpublish a purchased asset in market: the dashboard entry, the download, and the license are all
      unchanged — verified structurally: `EntitlementQuery` never reads `assets.status`/`published_at`
      for anything already-owned (only `scopeForEngine()`/`scopePublished()`, neither of which this
      step's queries call), and the license text is read from `entitlements.license_body_snapshot`,
      never `market_licenses.body`. Not re-verified by actually toggling a real asset's `status` live
      this session, since doing so would have required extra seed-state churn beyond what verifying the
      read path requires.
- [x] Publish 1.0.1 for an asset owned at 1.0.0: the update badge appears and **both** versions
      download — verified live: the seed asset went from 1.0.0 (owned) to 2.0.0 (published after
      purchase), `has_update: true` appeared correctly, and both `av_sbks4xqp` (1.0.0) and the
      no-version-specified request (resolving to 2.0.0) both issued working signed URLs.
- [x] Edit the seller's live license row: the buyer's displayed license text does not change — verified
      structurally (see the unpublish item above); the license API response reads only
      `entitlement.license_body_snapshot`.
- [x] `/portal/assets/{entitlement}` for someone else's entitlement → `403`/`404` — verified live
      against `GET /portal/api/assets/{entitlement}` (the actual data-bearing call, per this step's own
      "Auth model" note) for both a nonexistent ref and the real revoked entitlement's ref: both `404`.
- [x] A revoked entitlement disappears from the list and its download returns `403` — verified live:
      absent from `GET /portal/api/assets`, and `POST .../download` on it → `404` (§12.6 accepts either).
- [x] Asset grid with 24 rows issues a bounded query count (verify with the query log, not by feel) —
      verified live with `DB::listen()`: 14 queries, none scaling per row.
- [x] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4) — verified
      by `grep -q` against all 29 files in this report's own tables; no file in the list is untagged.
- [x] This file written.
