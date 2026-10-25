# Step 17 — Seed data for testing purchases

**On completion:** write `complete/17_seed_data_complete.md`.
**Depends on:** [03](03_market_database_schema.md), [05](05_asset_storage_and_upload_pipeline.md), [06](06_members_asset_publishing.md), [07](07_members_freelance_publishing.md).
**Blocks:** 18, 19.

> *"Create laravel seeded data for both assets and services so create some fake assets and fake
> services for me to test purchase."*

---

## 17.1 Fixed identities

| Role | `reference_num` | Notes |
|---|---|---|
| Seller | `m1xTHpRmdtH2Y` | Exists in `brain_memberships`. **Do not create.** |
| Buyer | `m1xJppRmdtH2Y` | Exists in `brain_memberships`. **Do not create.** |

Both are constants in `MarketSeeder`, named and commented, not repeated as literals across files.

The seeders write only market's own tables. Nothing in this step touches brain, members, or
Checkpoint.

---

## 17.2 The existing seeders break

`database/seeders/AssetSeeder.php` and `ServiceSeeder.php` reference `user_id`, which step 03
removes. They must be rewritten in the same pass, not left to fail. `CategorySeeder` survives with a
small update for `slug`, `kind` and `parent_id`.

---

## 17.3 Seeder structure

> **Tagging (step 01 §01.4).** Every seeder, factory, fixture generator and migration this step
> creates carries `MARKET_MODIFIER_ADDITION` in its top doc block — including the generated archive
> builder, which is easy to overlook because it produces data rather than code.


```
database/seeders/
├── DatabaseSeeder.php          modified — calls MarketSeeder
├── CategorySeeder.php          modified
├── MarketLicenseSeeder.php     NEW — the default EULA + one custom seller license
├── SellerProfileSeeder.php     NEW
├── AssetSeeder.php             rewritten
├── AssetVersionSeeder.php      NEW — real archives on disk
├── ServiceSeeder.php           rewritten — gigs + packages + extras + FAQ + requirements
├── ReviewSeeder.php            NEW
└── MarketSeeder.php            NEW — orchestrator
```

`MarketSeeder` is **idempotent**: `updateOrCreate` on `reference_num` throughout, so re-running does
not duplicate. It refuses to run when `APP_ENV=production` unless `--force` is passed, and it prints
what it created.

---

## 17.4 The default EULA

`MarketLicenseSeeder` creates exactly one `is_system = true`, `owner_reference_num = null` row:
**"Default End User License Agreement"**.

Content requirements — plain language, no legalese theatre, and it must actually cover the cases a
digital marketplace produces:

- Grant: non-exclusive, worldwide, perpetual, non-transferable, for commercial and non-commercial use.
- Permitted: use in an unlimited number of the licensee's own projects; modify; include in a
  compiled or otherwise non-extractable form in a distributed product.
- Prohibited: redistribution or resale of the asset in its original or a substantially similar form;
  sublicensing; inclusion in a competing asset library or template marketplace; use in unlawful
  material.
- Ownership stays with the seller.
- Warranty disclaimer and limitation of liability.
- Termination on material breach; refunds per market policy.
- Governing law placeholder.
- A clear statement that the seller may substitute their own license, which then supersedes this one.

> **This is a template, not legal advice.** The completion report must flag it for review by whoever
> is accountable for the platform's terms before real sellers use it. Writing it now is right;
> shipping it unreviewed is not.

A second, non-system license — *"Extended Commercial License (Seller Example)"* owned by
`m1xTHpRmdtH2Y` — exercises the custom-license path end to end.

---

## 17.5 Assets

Ten, all owned by `m1xTHpRmdtH2Y`, all `published`. Six `joystick_engine`, four `other`, one free.

| # | Title | Tag | Price | Purpose |
|---|---|---|---|---|
| 1 | Sci-Fi Prop Pack Vol. 1 | joystick_engine | 24.99 | Baseline paid engine asset |
| 2 | Pixel Platformer Tileset | joystick_engine | 12.00 | Small package |
| 3 | Ambient Loop Bundle | joystick_engine | 0.00 | **Free** — exercises the no-payment path |
| 4 | UI Kit — Neon | joystick_engine | 34.50 | Custom seller license |
| 5 | Character Controller Toolkit | joystick_engine | 49.99 | **Three versions** (1.0.0, 1.1.0, 2.0.0) — the update-badge and version-pinning case |
| 6 | Particle FX Collection | joystick_engine | 19.99 | Large-ish archive |
| 7 | Stock Photo Pack — Urban | other | 15.00 | Must **not** appear in the engine |
| 8 | Brand Identity Templates | other | 45.00 | |
| 9 | Lo-Fi Music Pack | other | 22.00 | |
| 10 | Icon Set — 500 Line Icons | other | 9.99 | Cheapest; rounding check ($0.99 fee) |

Prices deliberately include `.99` and `.50` endings — the fee arithmetic's rounding behaviour
(step 16) needs data that exercises it. A catalogue of round numbers proves nothing.

Each gets: description, long markdown description, category, tags, cover image, 2–4 gallery images,
tax mode/rate on some and `none` on others, and `platform_fee_percent = 10.00`.

### Real archives

`AssetVersionSeeder` writes **genuine zip files** to
`/mnt/node2/market/members/m1xTHpRmdtH2Y/assets/{ref}/versions/{v}/package.zip`, computes real
sha256s, and populates `asset_version_files`.

Generated, not committed — a repository is not a binary store.

For `joystick_engine` assets the archive contains a plausible Joystick layout, because step 18's
installer needs something real to install:

```
Assets/
  SciFiProps/
    Meshes/crate.gltf
    Textures/crate_albedo.png
    Materials/crate.jmat
    Scripts/CrateBehaviour.cs
package.json          name, version, displayName, description, engineMinVersion
README.md
LICENSE.txt
```

Files are small (a few KB of placeholder content) but **real and well-formed** — a stub that is not
a valid PNG will fail the engine's importer and produce a confusing bug in step 18 that looks like
an installer defect.

Every generated filename must pass step 05's analyzer. The seeder asserting this is a free
regression test for the analyzer itself.

---

## 17.6 Services

Six gigs owned by `m1xTHpRmdtH2Y`, all published, each with three packages, extras, gallery, FAQ,
and requirements.

| # | Gig | Basic | Standard | Premium |
|---|---|---|---|---|
| 1 | I will create custom 3D game assets | 75 / 5d / 2rev | 150 / 7d / 3rev | 300 / 10d / unlimited |
| 2 | I will build a Joystick Engine gameplay prototype | 200 / 7d | 450 / 14d | 900 / 21d |
| 3 | I will compose original game music | 60 / 4d | 120 / 7d | 250 / 10d |
| 4 | I will design your game's UI/UX | 90 / 5d | 180 / 8d | 350 / 12d |
| 5 | I will port your game to mobile | 400 / 14d | 800 / 21d | 1500 / 30d |
| 6 | I will write your game's technical documentation | 50 / 3d | 100 / 5d | 200 / 8d |

Gig 1 additionally gets a `hourly` variant to exercise contracts if step 15's hourly path shipped.

Requirements per gig are realistic (art style reference, target platform, engine version,
deliverable format) — they are what step 15's requirements gate is tested against.

---

## 17.7 Purchase state

Two modes, selectable by flag, because they test different things.

**`--empty` (default).** Catalogue seeded, buyer owns nothing. The buyer walks the real
cart → checkout → fulfilment path. **This is the mode that matters** — it is the only one that
exercises step 13.

**`--with-purchases`.** Pre-creates for `m1xJppRmdtH2Y`: three paid asset orders (including
Character Controller Toolkit at 1.0.0, so the update badge has something to show), one free asset
order, one completed service order with a review, and one in-flight service order in
`awaiting_requirements`.

Pre-created orders are marked `seeded = true` in `notes` so reconciliation (step 16) can exclude
them — a seeded order with no Stripe charge would otherwise show as an unreconciled discrepancy
forever.

`ReviewSeeder` adds reviews only against seeded completed orders, honouring the
`unique(order_item_id, direction)` constraint. There must be no path by which the seeder creates a
review without an order — that would prove the constraint is not doing its job.

---

## 17.8 Running

```bash
php artisan migrate:fresh --seed
```

```bash
php artisan db:seed --class=MarketSeeder -- --with-purchases
```

```bash
php artisan market:clean-dummy-data
```

The last extends the existing `app/Console/Commands/CleanDummyData.php` to remove seeded rows **and**
their generated archives under `/mnt/node2`. A cleanup that leaves gigabytes of orphaned zips on a
shared mount is not a cleanup.

---

## 17.9 Verification

- [ ] `migrate:fresh --seed` completes with no `user_id` errors from the old seeders.
- [ ] Ten assets and six gigs appear on the storefront; the four `other` assets do **not** appear in any engine-facing query.
- [ ] Every seeded archive exists on disk with a sha256 matching `asset_versions.archive_sha256`.
- [ ] Unzipping a `joystick_engine` archive yields a valid Joystick layout with a well-formed `package.json`.
- [ ] Every generated filename passes step 05's analyzer with zero blocking issues.
- [ ] Character Controller Toolkit shows three versions with distinct changelogs.
- [ ] The free asset can be "purchased" with no processor interaction.
- [ ] Re-running the seeder creates no duplicates.
- [ ] `--with-purchases` gives the buyer a populated portal; `market:clean-dummy-data` removes rows **and** files.
- [ ] The default EULA is flagged for legal review in the completion report.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/17_seed_data_complete.md` written.
