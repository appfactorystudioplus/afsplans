# Step 03 — Market database schema

**On completion:** write `complete/03_market_database_schema_complete.md`.
**Depends on:** [02](02_environment_and_config.md).
**Blocks:** 04–17.

All migrations live in `apps/market/database/migrations/`, dated after the existing
`2026_01_23_*` set. Every migration file carries `MARKET_MODIFIER_ADDITION` in its header doc block
plus a `/** */` explaining what the table is *for* — not what the columns are named.

---

## 03.1 Non-goal, stated once so it is not revisited

**No data is migrated from members.** Step 01 established that members has no marketplace
migrations; its asset and service records live in browser `localStorage` and in PHP session files.
There is nothing to move, and a migration script for per-browser mock data would be a script that
can never be verified.

The instruction "move the migration tables over from members" resolves to: **create them fresh in
market, shaped for the real system rather than the mock.** That is what this step does.

---

## 03.2 The identity change

`assets.user_id` and `services.user_id` are `foreignId(...)->constrained()` FKs into market's local
`users` table. Both are dropped.

```php
// 2026_08_18_000001_reshape_assets_and_services_identity.php
Schema::table('assets', function (Blueprint $table) {
    $table->dropForeign(['user_id']);
    $table->dropColumn('user_id');
    $table->string('seller_reference_num', 64)->after('id')->index();
});
```

Same for `services`. `AssetSeeder` and `ServiceSeeder` reference `user_id` and break here — step 17
rewrites them, and this migration must not run against a seeded database without step 17 landing in
the same pass. Note that explicitly in the migration's doc block.

Market's `users` table stays (Laravel plumbing, `CleanDummyData` references it) and gains **no**
marketplace relationship. Nothing joins to it.

---

## 03.3 Reference numbers

Every marketplace row a member or an API can name gets a `reference_num`: a short, opaque, unique
string, following the pattern `brain_memberships.reference_num` and the DP refs already use
(`eq4W10iF8`). Auto-increment ids never appear in a URL, an API payload, or the engine.

*Why.* Ids leak volume ("they have sold 12 things") and are guessable. More practically, market's
ids and members' ids would be two different numbering spaces for the same object, and someone will
eventually pass the wrong one.

Prefixes: `as_` asset, `sv_` service, `or_` order, `en_` entitlement, `li_` license, `av_` asset
version, `so_` service order, `jp_` job post, `pr_` proposal.

---

## 03.4 Tables

### Catalog

**`assets`** — modify.

| Column | Type | Notes |
|---|---|---|
| `reference_num` | string(32) unique | `as_…` |
| `seller_reference_num` | string(64) index | replaces `user_id` |
| `slug` | string unique | storefront URL |
| `status` | enum | `draft`, `pending_review`, `published`, `unpublished`, `rejected` |
| `distribution_tag` | enum | `joystick_engine`, `other`. **Indexed** — the engine query filters on it. |
| `license_id` | FK → `market_licenses` nullable | the EULA in force for new purchases |
| `currency` | string(3) | default from config |
| `tax_mode` | enum | `inclusive`, `exclusive`, `none` |
| `tax_rate` | decimal(5,4) nullable | seller-declared rate |
| `platform_fee_percent` | decimal(5,2) default 10.00 | **snapshotted per asset**, not read from config at sale time |
| `package_name` | string nullable unique | reverse-DNS, required when `distribution_tag = joystick_engine` |
| `engine_min_version` | string nullable | semver |
| `latest_version_id` | FK → `asset_versions` nullable | denormalized pointer |
| `published_at`, `unpublished_at` | timestamp nullable | |
| `rejection_reason` | text nullable | |

Keep: `category_id`, `title`, `description`, `full_description`, `price`, `preview_image`, `tags`,
`downloads`, `views`, `rating`. Drop `is_active` — `status` supersedes it; leaving both invites the
two disagreeing. Drop `file_path` / `file_type` / `file_size` — they move to `asset_versions`, since
an asset with one immutable file path cannot be versioned, and versioning is the whole point of a
package manager.

> `platform_fee_percent` on the row rather than in config is deliberate. The seller was shown a
> number at publish time and agreed to it. Changing the config value must not retroactively change
> what they earn on an already-listed asset. Step 16 depends on this.

**`asset_versions`** — new. `asset_id`, `reference_num` (`av_…`), `version` (semver),
`changelog` text, `archive_path` (relative to the storage root, never absolute — the root is
config), `archive_bytes`, `archive_sha256`, `file_count`, `manifest` json, `engine_min_version`,
`status` (`uploading`, `scanning`, `ready`, `failed`, `withdrawn`), `published_at`.
Unique on `(asset_id, version)`.

**`asset_version_files`** — new. One row per file inside the archive: `asset_version_id`,
`relative_path`, `bytes`, `sha256`, `mime`. Populated by the server-side re-validation in step 05.

*Why store the file list.* Three consumers need it without unzipping: the storefront's "what's in
this package" listing, the engine's pre-install conflict check ("this package writes
`Assets/Shared/Util.cs`, which you already have"), and the abuse review queue.

**`services`** — modify. Same identity/status/reference/fee changes. `delivery_time` and the single
`price` become the *fallback* for a service with no packages; the real pricing is
`service_packages`. Add `seller_reference_num`, `gig_type` (`fixed`, `hourly`).

**`categories`** — modify. Add `slug`, `parent_id` (nullable self-FK), `kind` (`asset`, `service`,
`both`), `sort_order`.

### Licensing

**`market_licenses`** — new.

| Column | Notes |
|---|---|
| `reference_num` | `li_…` |
| `owner_reference_num` | nullable — **null means system-provided** |
| `name`, `slug` | |
| `body` | longtext, markdown |
| `version` | int, incremented on edit |
| `is_system` | bool — the default EULA; not editable, not deletable |
| `is_default` | bool — this member's default pick |

Seeded with exactly one `is_system = true` row: the **Default End User License Agreement**
(step 17 writes the text). A member either accepts it or authors their own.

> **Licenses are versioned and never edited in place for an existing sale.** The purchase snapshots
> the full body into the entitlement (below). If a member rewrites their EULA in March, someone who
> bought in January is still governed by the January text, and can still read it. Editing a license
> row bumps `version` and leaves prior snapshots untouched.

### Commerce

**`orders`** — reshape. The current table has `customer_email` / `customer_name` and a fake
`transaction_id`.

| Column | Notes |
|---|---|
| `reference_num` | `or_…`, replaces `order_number` |
| `buyer_reference_num` | index |
| `currency`, `subtotal`, `tax_total`, `total` | decimal(10,2) |
| `platform_fee_total`, `seller_net_total` | decimal(10,2) |
| `status` | `pending`, `awaiting_payment`, `paid`, `failed`, `refunded`, `partially_refunded`, `cancelled` |
| `dp_invoice_reference_num` | Direct Payments invoice ref |
| `dp_payment_reference` | processor charge/PI id |
| `paid_at`, `refunded_at` | |

Keep `customer_email` — the receipt address, which may differ from the account's.
Drop `payment_method` (the processor owns it) and the polymorphic `purchasable` morph: an order is
now multi-line.

**`order_items`** — new. `order_id`, `purchasable_type`/`purchasable_id` (Asset or Service),
`seller_reference_num`, `title_snapshot`, `unit_price`, `quantity`, `tax_amount`, `line_total`,
`platform_fee_amount`, `seller_net_amount`, `license_id` + `license_body_snapshot`,
`service_package_id` nullable, `extras` json nullable.

> `seller_reference_num` on the *item*, not just the order, is what makes a multi-seller cart
> possible — and multi-seller carts are what force the payment-split decision in step 08. The schema
> has to admit the case even if the first release restricts the cart to one seller.

**`entitlements`** — new. The durable ownership record.

`reference_num` (`en_…`), `buyer_reference_num` (index), `order_id`, `order_item_id`,
`purchasable_type`/`purchasable_id`, `seller_reference_num`, `distribution_tag`,
`license_id`, `license_body_snapshot` longtext, `license_version`, `granted_at`,
`revoked_at` nullable, `revoked_reason`.

Unique on `(buyer_reference_num, purchasable_type, purchasable_id)` — a second purchase of the same
asset is a no-op grant, not a duplicate row.

> Both `/portal` and the engine read **entitlements**, never orders. An unpublished asset, a
> departed seller, a refunded-then-reinstated purchase — every one of those changes a catalog row or
> an order row while ownership stays exactly as it was. Reading orders to answer "what do I own"
> re-derives that answer from mutable state every time, and eventually gets it wrong.

**`asset_downloads`** — new. `entitlement_id`, `asset_version_id`, `buyer_reference_num`, `ip`,
`user_agent`, `source` (`portal`, `engine`), `bytes_sent`, `created_at`. Audit + the `downloads`
counter's source of truth.

### Sellers

**`seller_profiles`** — new. `member_reference_num` unique, `display_name`, `headline`, `bio`,
`avatar_path`, `country`, `languages` json, `skills` json, `level` (`new`, `level_1`, `level_2`,
`top_rated`), `response_time_minutes`, `completed_orders`, `rating_avg`, `rating_count`,
`connect_account_reference` nullable, `payouts_enabled` bool, `charges_enabled` bool,
`onboarding_status`, `last_synced_at`.

The three Connect columns are written by the step-10 callback from brain. `charges_enabled = false`
is what gates publishing a paid listing (step 06).

### Freelance — Fiverr side

- **`service_packages`** — `service_id`, `tier` (`basic|standard|premium`), `title`, `description`, `price`, `delivery_days`, `revisions` (int, `-1` = unlimited), `features` json, `is_active`. Unique `(service_id, tier)`.
- **`service_extras`** — paid add-ons: `title`, `price`, `extra_delivery_days`, `max_quantity`.
- **`service_gallery`** — `media_path`, `media_type` (`image|video|pdf`), `sort_order`.
- **`service_faqs`** — `question`, `answer`, `sort_order`.
- **`service_requirements`** — the pre-work questionnaire: `prompt`, `type` (`text|choice|multi|file`), `options` json, `is_required`, `sort_order`.

### Freelance — order lifecycle (detailed in step 15)

- **`service_orders`** — `reference_num` (`so_…`), `order_item_id`, `buyer_reference_num`, `seller_reference_num`, `service_id`, `service_package_id`, `extras` json, `status` (`awaiting_requirements`, `in_progress`, `delivered`, `revision_requested`, `completed`, `cancelled`, `disputed`), `due_at`, `delivered_at`, `completed_at`, `auto_accept_at`, `revisions_used`, `revisions_allowed`.
- **`service_order_requirements`** — the buyer's answers.
- **`service_order_milestones`** — `title`, `amount`, `due_at`, `status`, `sequence`.
- **`service_order_deliverables`** — `file_path`, `note`, `delivered_at`, `is_final`.
- **`service_order_revisions`** — `requested_at`, `reason`, `resolved_at`.
- **`service_order_messages`** — `sender_reference_num`, `body`, `attachments` json, `read_at`.
- **`order_disputes`** — `disputable` morph, `opened_by_reference_num`, `reason`, `status`, `resolution`, `resolved_at`. **Buyer-vs-seller only.** Card chargebacks are a separate concern in the payments layer (step 08 §08.7.5) — do not merge the two.

### Freelance — Upwork side

- **`job_posts`** — buyer-posted: `reference_num` (`jp_…`), `buyer_reference_num`, `title`, `description`, `category_id`, `budget_type` (`fixed|hourly`), `budget_min`, `budget_max`, `skills` json, `experience_level`, `status`, `visibility`, `proposals_count`, `closes_at`.
- **`job_proposals`** — `reference_num` (`pr_…`), `job_post_id`, `seller_reference_num`, `cover_letter`, `bid_amount`, `bid_type`, `estimated_days`, `status` (`submitted`, `shortlisted`, `accepted`, `declined`, `withdrawn`), `milestones` json.
- **`contracts`** — `job_proposal_id`, `type`, `rate`, `weekly_limit_hours`, `status`, `started_at`, `ended_at`.
- **`contract_timesheets`** — `contract_id`, `week_starting`, `hours`, `memo`, `status`, `invoiced_order_id`.

### Reviews

**`reviews`** — `reviewable` morph (Asset | Service | ServiceOrder), `order_item_id`,
`author_reference_num`, `subject_reference_num`, `direction` (`buyer_to_seller`,
`seller_to_buyer`), `rating` 1–5, `body`, `is_public`, `published_at`.

Unique on `(order_item_id, direction)` — one review per side per order, and **only** from an order.
No order, no review. That single constraint is what makes the ratings non-gameable, so it belongs in
the database rather than in a controller check.

### Ledger (detailed in step 16)

- **`seller_ledger_entries`** — `seller_reference_num`, `type` (`sale`, `platform_fee`, `refund`, `chargeback`, `payout`, `adjustment`), `order_item_id` nullable, `amount` signed, `currency`, `balance_after`, `available_at`, `memo`, `external_reference`.
- **`payouts`** — `seller_reference_num`, `amount`, `currency`, `status`, `connect_payout_id`, `initiated_at`, `paid_at`, `failure_reason`.

### Internal API plumbing (step 04)

- **`internal_api_requests`** — `idempotency_key` unique, `app_name`, `method`, `path`, `body_sha256`, `response_status`, `response_body`, `created_at`. Replay/idempotency ledger.

---

## 03.5 Indexes that are not optional

| Table | Index | Why |
|---|---|---|
| `entitlements` | `(buyer_reference_num, distribution_tag, revoked_at)` | The engine's list query. Every panel refresh hits it. |
| `assets` | `(status, distribution_tag, published_at)` | Storefront listing + engine catalog. |
| `assets` | `(seller_reference_num, status)` | The members dashboard's list. |
| `order_items` | `(seller_reference_num, created_at)` | Seller earnings, the members callback. |
| `seller_ledger_entries` | `(seller_reference_num, created_at)` | Balance computation. |
| `service_orders` | `(seller_reference_num, status)` / `(buyer_reference_num, status)` | Both dashboards. |
| `asset_versions` | `(asset_id, status)` | "latest ready version" resolution. |

---

## 03.6 Money columns

`decimal(10,2)` throughout, never float. Currency stored per row, never assumed. All arithmetic in
PHP is done with the `decimal` string values through `bcmath` or integer cents, never native floats
— a 10% fee on `$19.99` computed in float arithmetic produces `1.9990000000000001`, and it will
eventually round the wrong way on a boundary and put the ledger a cent out. Step 16 defines the
rounding rule (banker's rounding, fee rounded *down*, seller net = total − fee so the two always sum
exactly).

---

## 03.7 Models

> **Tagging (step 01 §01.4).** Every file this step creates or modifies carries
> `MARKET_MODIFIER_ADDITION` — created files in the top doc block, modified files at each changed
> line or region. `grep -rn MARKET_MODIFIER_ADDITION` is the change index for this whole effort.


New/updated Eloquent models in `apps/market/app/Models/`, each with a full `/** */` header and the
tag: `AssetVersion`, `AssetVersionFile`, `MarketLicense`, `OrderItem`, `Entitlement`,
`AssetDownload`, `SellerProfile`, `ServicePackage`, `ServiceExtra`, `ServiceGallery`, `ServiceFaq`,
`ServiceRequirement`, `ServiceOrder` (+ its children), `Dispute`, `JobPost`, `JobProposal`,
`Contract`, `ContractTimesheet`, `Review`, `SellerLedgerEntry`, `Payout`, `InternalApiRequest`.

Conventions:
- `$fillable`, `$casts` (json → array, money → `decimal:2`, timestamps → `datetime`).
- A `HasReferenceNum` trait: generates the prefixed ref on `creating`, and makes `getRouteKeyName()`
  return `reference_num` so route-model binding never exposes an id.
- Scopes that encode the rules once: `Asset::scopePublished()`, `Asset::scopeForEngine()`
  (`published` + `distribution_tag = joystick_engine`), `Entitlement::scopeActive()`
  (`revoked_at is null`).

Use the scopes everywhere. A controller that hand-writes `where('distribution_tag', …)` is a place
the engine filter can be forgotten.

---

## 03.8 Verification

- [ ] `php artisan migrate:fresh` on a scratch database succeeds.
- [ ] `php artisan migrate` against the currently-seeded database succeeds **or** fails loudly with a
      documented reason (step 17 lands in the same pass).
- [ ] No marketplace FK references `users`. Verify by inspecting the schema, not by reading code.
- [ ] `Entitlement::scopeForEngine()` returns zero rows for an `other`-tagged purchase.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/03_market_database_schema_complete.md` written, listing every migration file.
