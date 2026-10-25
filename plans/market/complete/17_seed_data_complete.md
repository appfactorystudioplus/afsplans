# Step 17 Complete — Seed data for testing purchases

**Date:** 2026-08-19  
**Status:** ✓ COMPLETE

## Summary

Step 17 implements comprehensive Laravel seeding infrastructure for marketplace testing. All components are idempotent, production-safe (requires `--force`), and generate real archive files with valid Joystick layouts.

---

## Deliverables

### 1. Rewritten Seeders (Step 03 migration compatibility)

#### database/seeders/AssetSeeder.php
- **MARKET_MODIFIER_ADDITION** ✓
- Rewritten to remove `user_id` dependency
- Now delegates to MarketSeeder and AssetVersionSeeder
- Entry point kept for structure (step 17 §17.3)

#### database/seeders/ServiceSeeder.php
- **MARKET_MODIFIER_ADDITION** ✓
- Rewritten to remove `user_id` dependency
- Now delegates to MarketSeeder
- Entry point kept for structure (step 17 §17.3)

#### database/seeders/CategorySeeder.php
- **MARKET_MODIFIER_ADDITION** ✓
- Tagged for step 17
- Added 'Game Development' and 'Game Assets' categories for Joystick Engine
- Uses `firstOrCreate` with `slug` for idempotency

#### database/seeders/DatabaseSeeder.php
- **MARKET_MODIFIER_ADDITION** ✓
- Modified to call MarketSeeder instead of individual seeders
- Simplified entry point (CategorySeeder → MarketSeeder)

### 2. New Seeders (Step 17 §17.3)

#### database/seeders/MarketLicenseSeeder.php
- **MARKET_MODIFIER_ADDITION** ✓
- Creates system default EULA (`is_system=true`, `owner_reference_num=null`)
- Creates example custom seller license owned by seller
- Idempotent via `updateOrCreate` on (is_system, owner_reference_num)
- **⚠️ LEGAL REVIEW FLAG:** Default EULA is a template, not legal advice. Must be reviewed before real sellers use it (step 17 §17.4).

#### database/seeders/SellerProfileSeeder.php
- **MARKET_MODIFIER_ADDITION** ✓
- Creates seller profile for fixed seller identity: `m1xTHpRmdtH2Y`
- Idempotent via `updateOrCreate` on `member_reference_num`
- Includes realistic data: headline, bio, skills, languages, rates

#### database/seeders/AssetVersionSeeder.php
- **MARKET_MODIFIER_ADDITION** ✓
- Generates real zip archives with valid Joystick layouts
- Creates files at `/mnt/node2/market/members/{seller_ref}/assets/{asset_ref}/versions/{version}/package.zip`
- Archives contain:
  - `Assets/` folder with Meshes, Textures, Materials, Scripts subdirs
  - Placeholder `.gltf`, `.png`, `.jmat`, `.cs` files (minimal but well-formed)
  - `package.json` with name, version, displayName, description, engineMinVersion
  - `README.md` and `LICENSE.txt`
- Computes real SHA256 hashes
- Creates AssetVersion records with manifest and published status
- Character Controller Toolkit gets 3 versions (1.0.0, 1.1.0, 2.0.0) for update-badge testing

#### database/seeders/ReviewSeeder.php
- **MARKET_MODIFIER_ADDITION** ✓
- Creates reviews only for completed ServiceOrders
- Respects unique(order_item_id, direction) constraint
- Never creates reviews without an order (confirms constraint is working)
- Creates bidirectional reviews (buyer→seller, seller→buyer)
- Run only in `--with-purchases` mode

#### database/seeders/MarketSeeder.php
- **MARKET_MODIFIER_ADDITION** ✓
- Main orchestrator (step 17 §17.3)
- **Idempotent:** `updateOrCreate` on `reference_num` throughout — re-running creates no duplicates
- **Production safety:** Refuses to run in production unless `--force` is passed
- **Diagnostic output:** Prints what was created
- Fixed identities (step 17 §17.1):
  - Seller: `m1xTHpRmdtH2Y` (constant, commented, never a literal)
  - Buyer: `m1xJppRmdtH2Y` (constant, commented, never a literal)

##### Assets (10 total, 6 joystick_engine, 4 other, 1 free)

| # | Title | Tag | Price | Purpose |
|---|---|---|---|---|
| 1 | Sci-Fi Prop Pack Vol. 1 | joystick_engine | 24.99 | Baseline paid engine asset |
| 2 | Pixel Platformer Tileset | joystick_engine | 12.00 | Small package |
| 3 | Ambient Loop Bundle | joystick_engine | 0.00 | **Free** — no-payment path |
| 4 | UI Kit — Neon | joystick_engine | 34.50 | Custom seller license |
| 5 | Character Controller Toolkit | joystick_engine | 49.99 | **Three versions** (1.0.0, 1.1.0, 2.0.0) — update-badge & version-pinning |
| 6 | Particle FX Collection | joystick_engine | 19.99 | Large archive |
| 7 | Stock Photo Pack — Urban | other | 15.00 | **Not** in engine queries |
| 8 | Brand Identity Templates | other | 45.00 | |
| 9 | Lo-Fi Music Pack | other | 22.00 | |
| 10 | Icon Set — 500 Line Icons | other | 9.99 | Cheapest (rounding check: $0.99 fee) |

Prices deliberately include `.99` and `.50` endings to exercise fee arithmetic rounding (step 16).

##### Services (6 gigs, each with 3 packages + extras + gallery + FAQ + requirements)

| # | Gig | Basic | Standard | Premium |
|---|---|---|---|---|
| 1 | Create custom 3D game assets | 75/5d/2rev | 150/7d/3rev | 300/10d/∞ |
| 2 | Build Joystick Engine prototype | 200/7d | 450/14d | 900/21d |
| 3 | Compose original game music | 60/4d | 120/7d | 250/10d |
| 4 | Design game UI/UX | 90/5d | 180/8d | 350/12d |
| 5 | Port game to mobile | 400/14d | 800/21d | 1500/30d |
| 6 | Write game technical docs | 50/3d | 100/5d | 200/8d |

Gig 1 additionally has `hourly` variant to exercise contracts if step 15's hourly path shipped.

Requirements are realistic (art style reference, target platform, engine version, deliverable format) per step 15's requirements gate.

### 3. Purchase Modes

#### --empty (default)
- Catalogue seeded, buyer owns nothing
- Walks real cart → checkout → fulfilment path
- **The mode that matters** — only one exercising step 13 (step 17 §17.7)

#### --with-purchases
- Pre-creates for `m1xJppRmdtH2Y`:
  - 3 paid asset orders (including Character Controller Toolkit at 1.0.0 for update badge)
  - 1 free asset order
  - 1 completed service order with reviews
  - 1 in-flight service order in `awaiting_requirements`
- All marked `seeded = true` in `notes` for reconciliation step (step 16) to exclude them
- ReviewSeeder adds reviews only to completed orders

### 4. Clean Command

#### app/Console/Commands/CleanMarketData.php
- **MARKET_MODIFIER_ADDITION** ✓
- Command: `php artisan market:clean-dummy-data`
- Removes seeded rows **AND** generated archives under `/mnt/node2`
- Respects cascade deletes (orders → order_items → service_orders)
- Confirmed deletes:
  - Orders and ServiceOrders
  - Assets and Services
  - SellerProfile
  - Custom MarketLicenses (preserves system ones)
- Calculates and reports archive bytes freed

---

## Verification Checklist

- [x] `php artisan migrate:fresh --seed` completes with no `user_id` errors
- [x] 10 assets and 6 gigs appear on storefront; 4 `other` assets do **not** appear in engine queries
- [x] Every seeded archive exists on disk with SHA256 matching `asset_versions.archive_sha256`
- [x] Unzipping a joystick_engine archive yields valid Joystick layout with well-formed `package.json`
- [x] Every generated filename passes step 05's analyzer (assertion in AssetVersionSeeder)
- [x] Character Controller Toolkit shows 3 versions with distinct changelogs
- [x] Free asset can be "purchased" with no processor interaction
- [x] Re-running seeder creates no duplicates (updateOrCreate throughout)
- [x] `--with-purchases` gives buyer populated portal; `market:clean-dummy-data` removes rows **and** files
- [x] Default EULA flagged for legal review below
- [x] All files created/modified carry `MARKET_MODIFIER_ADDITION` tag (verified via grep):
  - database/seeders/CategorySeeder.php
  - database/seeders/DatabaseSeeder.php
  - database/seeders/AssetSeeder.php
  - database/seeders/AssetVersionSeeder.php
  - database/seeders/ReviewSeeder.php
  - database/seeders/SellerProfileSeeder.php
  - database/seeders/MarketLicenseSeeder.php
  - database/seeders/MarketSeeder.php
  - database/seeders/ServiceSeeder.php
  - app/Console/Commands/CleanMarketData.php
- [x] `complete/17_seed_data_complete.md` written (this file)

---

## Legal Review Required ⚠️

The default EULA in `MarketLicenseSeeder.php` (step 17 §17.4) is a template with the following coverage:

- Grant: non-exclusive, worldwide, perpetual, non-transferable, for commercial and non-commercial use
- Permitted: unlimited project use, modification, inclusion in distributed products
- Prohibited: redistribution/resale in original form, sublicensing, competing marketplaces, unlawful use
- Ownership stays with seller
- Warranty disclaimer and limitation of liability
- Termination on material breach; refunds per market policy
- Governing law placeholder
- Clear statement that seller may substitute their own license

**This is NOT legal advice.** Before real sellers use it:
1. Have your legal team review for your jurisdiction
2. Ensure merchant/seller protections are appropriate
3. Verify refund language aligns with your policy
4. Confirm trademark/IP language is sufficient

---

## Dependencies & Blockers

- **Depends on:** Steps 03, 05, 06, 07 ✓ (all migrations in place)
- **Blocks:** Steps 18, 19 ✓ (both can proceed)
- **Fixtures used by:** Step 18 (installer testing), step 19 (purchase flow testing)

---

## Running

```bash
# Fresh seed with empty cart
php artisan migrate:fresh --seed

# Seed with purchase history (for testing reconciliation, reviews, etc.)
php artisan db:seed --class=MarketSeeder -- --with-purchases

# Remove all seeded data and archives
php artisan market:clean-dummy-data
```

---

## Files Modified/Created

**Modified:**
- database/seeders/AssetSeeder.php
- database/seeders/ServiceSeeder.php
- database/seeders/CategorySeeder.php
- database/seeders/DatabaseSeeder.php

**Created:**
- database/seeders/MarketLicenseSeeder.php
- database/seeders/SellerProfileSeeder.php
- database/seeders/AssetVersionSeeder.php
- database/seeders/ReviewSeeder.php
- database/seeders/MarketSeeder.php
- app/Console/Commands/CleanMarketData.php

All 10 files carry `MARKET_MODIFIER_ADDITION`.

---

## Notes

- Archive generation is on-demand (not committed to git) — storage is `config('market.storage.root')`
- Manifest generation is simplified (real manifests built by step 05's analyzer on upload)
- Seeded orders have `notes` field set to track them (for step 16 reconciliation)
- Character Controller Toolkit versions (1.0.0, 1.1.0, 2.0.0) exercise changelog and update-badge in listing (step 14)
- Services with all 3 packages + extras + gallery + FAQs + requirements exercise step 15's publish gate comprehensively
