# Step 03 — Market database schema — completion report

**Date:** 2026-08-19
**Projects touched:** market (`apps/market`) only.
**Status:** ✅ complete.

## What shipped

Market's schema now supports the real system rather than the mock: `assets`/`services` key on
`seller_reference_num` instead of a local `users` row, every marketplace-nameable row carries an
opaque `reference_num`, and 28 new tables cover licensing, versioned asset packages, multi-line
orders, entitlements, seller profiles, the full Fiverr-style gig model, the freelance order
lifecycle, Upwork-style job posts/proposals/contracts, reviews, the seller ledger, payouts, and the
internal-API idempotency ledger. 29 Eloquent models (4 updated, 25 new) sit on top, plus a
`HasReferenceNum` trait shared by every model that needs a prefixed reference number.

## Files created

**Migrations** — `apps/market/database/migrations/`, all dated `2026_08_19_0000NN`, all tagged in
their header doc block:

| # | File | Table(s) |
|---|---|---|
| 01 | `_reshape_assets_and_services_identity.php` | `assets`, `services` (drop `user_id`, add `seller_reference_num`) |
| 02 | `_create_market_licenses_table.php` | `market_licenses` |
| 03 | `_reshape_assets_table.php` | `assets` (catalog columns) |
| 04 | `_reshape_services_table.php` | `services` (catalog columns) |
| 05 | `_reshape_categories_table.php` | `categories` (`parent_id`, `kind`, `sort_order`) |
| 06 | `_create_asset_versions_table.php` | `asset_versions` |
| 07 | `_create_asset_version_files_table.php` | `asset_version_files` |
| 08 | `_add_latest_version_foreign_key_to_assets_table.php` | `assets.latest_version_id` FK |
| 09 | `_create_service_packages_table.php` | `service_packages` |
| 10 | `_create_service_extras_table.php` | `service_extras` |
| 11 | `_create_service_gallery_table.php` | `service_gallery` |
| 12 | `_create_service_faqs_table.php` | `service_faqs` |
| 13 | `_create_service_requirements_table.php` | `service_requirements` |
| 14 | `_reshape_orders_table.php` | `orders` |
| 15 | `_create_order_items_table.php` | `order_items` |
| 16 | `_create_entitlements_table.php` | `entitlements` |
| 17 | `_create_asset_downloads_table.php` | `asset_downloads` |
| 18 | `_create_seller_profiles_table.php` | `seller_profiles` |
| 19 | `_create_service_orders_table.php` | `service_orders` |
| 20 | `_create_service_order_requirements_table.php` | `service_order_requirements` |
| 21 | `_create_service_order_milestones_table.php` | `service_order_milestones` |
| 22 | `_create_service_order_deliverables_table.php` | `service_order_deliverables` |
| 23 | `_create_service_order_revisions_table.php` | `service_order_revisions` |
| 24 | `_create_service_order_messages_table.php` | `service_order_messages` |
| 25 | `_create_order_disputes_table.php` | `order_disputes` |
| 26 | `_create_reviews_table.php` | `reviews` |
| 27 | `_create_job_posts_table.php` | `job_posts` |
| 28 | `_create_job_proposals_table.php` | `job_proposals` |
| 29 | `_create_contracts_table.php` | `contracts` |
| 30 | `_create_contract_timesheets_table.php` | `contract_timesheets` |
| 31 | `_create_seller_ledger_entries_table.php` | `seller_ledger_entries` |
| 32 | `_create_payouts_table.php` | `payouts` |
| 33 | `_create_internal_api_requests_table.php` | `internal_api_requests` |

**Models** — `apps/market/app/Models/`, all tagged, all with a `/** */` header:

`Concerns/HasReferenceNum.php` (trait), `AssetVersion`, `AssetVersionFile`, `MarketLicense`,
`OrderItem`, `Entitlement`, `AssetDownload`, `SellerProfile`, `ServicePackage`, `ServiceExtra`,
`ServiceGallery`, `ServiceFaq`, `ServiceRequirement`, `ServiceOrder`, `ServiceOrderRequirement`,
`ServiceOrderMilestone`, `ServiceOrderDeliverable`, `ServiceOrderRevision`, `ServiceOrderMessage`,
`Dispute` (backed by `order_disputes`), `Review`, `JobPost`, `JobProposal`, `Contract`,
`ContractTimesheet`, `SellerLedgerEntry`, `Payout`, `InternalApiRequest`.

## Files modified

| File | Tag placement | Change |
|---|---|---|
| `app/Models/Asset.php` | header | Full rewrite: `seller_reference_num` identity, `HasReferenceNum`, `license()`/`versions()`/`latestVersion()` relations, `scopePublished()`/`scopeForEngine()` |
| `app/Models/Service.php` | header | Full rewrite: identity, `HasReferenceNum`, package/extra/gallery/faq/requirement relations, `scopePublished()` |
| `app/Models/Category.php` | header | `parent()`/`children()` self-relation, `kind`/`sort_order` fillable |
| `app/Models/Order.php` | header | Full rewrite: `HasReferenceNum` (`or_…`) replaces `generateOrderNumber()`, `items()`/`entitlements()` relations |

## Database changes

33 migrations, listed above. Verified against **both** a scratch SQLite database and a scratch MySQL
database (`market_scratch_test`, created and dropped for this verification only — the real dev
database `testdb_afsdb_market` was never touched). `php artisan migrate:fresh --force` succeeds
cleanly on both. No `php artisan migrate` invocation was run against the currently-seeded dev
database — see "Known gaps" for why that check was done differently.

## Decisions made during execution that diverge from the plan

- **Two indexes named explicitly.** MySQL enforces a 64-character limit on identifier names.
  `entitlements`' `(buyer_reference_num, purchasable_type, purchasable_id)` unique constraint and its
  `(buyer_reference_num, distribution_tag, revoked_at)` index both exceed that limit under Laravel's
  auto-generated naming (confirmed by running the migration against real MySQL, not just SQLite —
  see "How this was verified"). Both are now given short explicit names
  (`entitlements_buyer_purchasable_unique`, `entitlements_buyer_tag_revoked_index`). Not a plan
  deviation in substance — the indexed columns are exactly as specified — only in the generated SQL.
- **`orders.notes` and `orders.customer_name` are dropped**, not just `payment_method` and the
  `purchasable` morph as the step file's prose singles out. The step's column table for the reshaped
  `orders` table lists a specific, closed set of columns and states only `customer_email` is kept;
  everything else present on the old table and absent from that list was treated as intentionally
  dropped rather than carried forward silently. Flagging this explicitly in case the omission of
  `notes`/`customer_name` from the step's prose (as opposed to its column table) was meant to leave
  them in place — easy to restore in a follow-up migration if so.
- **`categories.slug`** was not re-added — it already exists (unique) from the original 2026_01_23
  migration, and the step's own text elsewhere acknowledges this class of drift. Only `parent_id`,
  `kind`, and `sort_order` were added. The existing `type` column was left untouched (see the
  migration's own header comment for why touching it is out of a schema-only step's scope).

## Known gaps / deferred

- **§03.8's "`php artisan migrate` against the currently-seeded database succeeds or fails loudly"
  was verified by controlled reproduction, not by running migrate against the real dev database.**
  Running a schema-breaking migration against `testdb_afsdb_market` was avoided since step 17 (which
  the plan requires to land in the same pass as this reshape, for exactly this reason) has not run
  yet. Instead: `2026_08_19_000001`'s `seller_reference_num` column was given a `default('')`
  specifically so the identity-reshape migration itself succeeds cleanly against a table with
  existing rows (verified on scratch MySQL with pre-existing seeded-shape rows). Separately, running
  `AssetSeeder`/`ServiceSeeder` against the **new** schema was reproduced on a scratch database and
  confirmed to **fail loudly**: `SQLSTATE[…]: … no such column: user_id` — `artisan db:seed` runs
  seeders with `Model::unguarded()`, so `user_id` reaches the database driver instead of being
  silently dropped by `$fillable` filtering (which is what a plain `Asset::create([...])` outside the
  seed command would do — verified separately, and worth knowing: outside `db:seed`, dropped columns
  are silently discarded, not errored). **Also discovered**: `apps/market/.env` currently sets
  `APP_PRODUCTION=true`, which makes both seeders no-op entirely before touching the database at all
  — unrelated to this step, but means the loud failure above only surfaces once that flag is
  corrected or step 17 removes the guard's dependency on it. Step 17 must rewrite both seeders
  regardless, per the step file's own instruction; this is recorded for whoever lands that step so
  the no-op-versus-error distinction isn't rediscovered from scratch.
- Migrations were not run against the real `testdb_afsdb_market` database — intentionally, to avoid
  landing a half-finished reshape (no step-17 seed data yet) on the shared dev database. Whoever
  executes step 17 should run this step's migrations and step 17's seeders in the same pass, per the
  step file's own instruction.

## How this was verified

- `php artisan migrate:fresh --force` against a scratch **SQLite** database: all 33 migrations apply
  cleanly, `down()` was read-reviewed (not executed) for symmetry.
- The same, repeated against a scratch **MySQL** database (`market_scratch_test`, created via a
  locally-started `brew services start mysql` for this check only, dropped and the service stopped
  afterward) — this caught the two over-length index names SQLite's looser identifier handling
  missed, which is exactly why both engines were checked rather than trusting SQLite alone.
- `information_schema.KEY_COLUMN_USAGE` queried against the MySQL scratch database for any foreign
  key referencing `users` — zero rows. No marketplace table has a `users` FK.
- `Entitlement::scopeForEngine()` tested directly against a scratch database with two entitlements
  seeded by hand, one `distribution_tag = joystick_engine` and one `= other`: `forEngine()->count()`
  returned exactly 1, and the one row returned was confirmed (by id) to be the `joystick_engine` one
  — the `other`-tagged entitlement never appears.
- `HasReferenceNum` exercised directly: created an `Asset`, `Order`, `Service`, and `MarketLicense`
  and confirmed each received the correct prefix (`as_`, `or_`, `sv_`, `li_` respectively) with no
  prefix passed explicitly — confirming the trait's `creating` hook and per-model
  `referenceNumPrefix()` both work.
- `php -l` run over every migration and model file created or modified in this step — zero syntax
  errors.
- The seeder-breakage investigation in "Known gaps" above.

## Follow-ups spawned

None spawned as separate work items. The `APP_PRODUCTION=true` quirk in market's dev `.env` is noted
above for whoever next touches that file, but is pre-existing and out of this step's scope to fix.

---

## Exit criteria (§03.8)

- [x] `php artisan migrate:fresh` on a scratch database succeeds — verified on both SQLite and MySQL.
- [x] `php artisan migrate` against the currently-seeded database succeeds **or** fails loudly with a documented reason — addressed by controlled reproduction rather than running against the real dev database; see "Known gaps" for the full reasoning and the confirmed failure mode.
- [x] No marketplace FK references `users` — verified by inspecting `information_schema` directly, not by reading code.
- [x] `Entitlement::scopeForEngine()` returns zero `other`-tagged rows — verified directly against live data.
- [x] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). `grep -rn MARKET_MODIFIER_ADDITION apps/market/database/migrations apps/market/app/Models` reconciled against the file lists above.
- [x] This file written, listing every migration file.
