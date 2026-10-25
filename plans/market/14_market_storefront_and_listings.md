# Step 14 — Market: the public storefront

**On completion:** write `complete/14_market_storefront_and_listings_complete.md`.
**Depends on:** [03](03_market_database_schema.md), [11](11_market_portal_auth.md).
**Blocks:** 19. Feeds [13](13_market_cart_and_checkout.md) (add-to-cart lives here).

---

## 14.1 What exists

A working Blade + Tailwind storefront: `home`, `services/{index,show}`, `assets/{index,show}`, a
shared layout with nav and mobile menu, `HomeController` composing categories + latest 8 of each.

It is a good base. It needs: the identity change (`user_id` → `seller_reference_num`), status
filtering (`is_active` → `status`), real search, the license surface, seller profiles, reviews, and
the package/tier presentation for gigs.

---

## 14.2 Listing pages

### `/assets`

Grid with thumbnail, title, seller, rating, price, and a distribution-tag chip.

Filters: category (with children), price range, distribution tag, rating, engine version
compatibility, "free only", tags. Sort: relevance, newest, price, rating, most downloaded.

**Every query goes through `Asset::scopePublished()`** (step 03). A `where('status', …)` written by
hand in a controller is the way a draft eventually appears on the storefront.

The distribution-tag chip is a customer-facing promise: *"Joystick Engine"* means it installs from
the editor. It must be visible on the card, not only on the detail page — a buyer filtering for
engine assets should be able to trust the grid.

### `/services`

Gig cards Fiverr-style: gallery thumbnail, seller avatar + level, title, rating + review count, and
**"From $X"** taken from the cheapest active package. Filters: category, budget, delivery time,
seller level, language, rating.

---

## 14.3 Detail pages

### `/assets/{slug}`

- Gallery, title, seller card (avatar, level, rating, other assets, member since).
- Description (markdown), and the **file list** from `asset_version_files` — buyers want to know
  what is inside before paying, and step 05 already stores it.
- **Version history** with changelogs. Visible pre-purchase: an asset last updated two years ago is
  information a buyer is entitled to.
- **License**, expandable in full, before purchase. The brief requires it: *"viewable on the Market
  for the purchaser to review."* Not a link, not a modal that loads on click — rendered in the page.
- Technical panel for `joystick_engine` assets: package name, minimum engine version, package size,
  file count, and *"Installs from Windows ▸ Service Assets Market in the Joystick Engine editor."*
- Price with tax indication, Add to cart, Buy now.
- Reviews with rating distribution.
- Related assets by category and tag.

### `/services/{slug}`

- Gallery, title, seller card with response time and completion rate.
- **The three-package comparison table** — the page's centrepiece. Aligned feature rows, delivery
  days, revisions, price, and a Select per column.
- Extras with prices.
- Description, FAQ, requirements preview (*"After ordering you'll be asked for…"* — sets
  expectations and reduces the awaiting-requirements stall step 15 has to handle).
- Reviews.

### `/sellers/{ref}`

Public profile: avatar, headline, bio, level, member since, languages, skills, stats (completed
orders, on-time rate, response time, rating), and their published assets and gigs.

Never exposes: email, real name unless volunteered, revenue, or the fee split.

---

## 14.4 Search

Start with SQL: `LIKE`/`FULLTEXT` across title, description and tags, with the filters above, and a
sane relevance ordering (title match > tag match > description match).

Do not reach for a search cluster at this catalogue size — it is infrastructure to operate for a
result set a `FULLTEXT` index answers in milliseconds. Note in the completion report the row count
at which to revisit (roughly tens of thousands of listings, or when filter combinations start
timing out).

Also: category landing pages, tag pages, `sitemap.xml` over published listings, and per-listing
Open Graph tags — a marketplace listing that pastes into a chat as a bare URL loses sales.

---

## 14.5 Reviews

Display only here; writing them is step 15.

- Rating distribution bar, sortable list, seller replies.
- **"Verified purchase" on every review**, guaranteed by the `unique(order_item_id, direction)`
  constraint from step 03 — reviews cannot exist without an order. Say so on the page; it is the
  reason the ratings mean anything.

---

## 14.6 Files

> **Tagging (step 01 §01.4) — every file below.** Created files carry
> `MARKET_MODIFIER_ADDITION` in their top doc block; modified files carry it at each changed line or
> region. `grep -rn MARKET_MODIFIER_ADDITION` must return this list in full, or the change index is
> already wrong.


**Modified:** `HomeController`, `ServiceController`, `AssetController` (identity, status scopes,
`$afssdk` now from the composer), `resources/views/{home,assets/*,services/*}`,
`resources/views/layouts/app.blade.php` (cart badge, account chip, search box).

**Created:** `app/Http/Controllers/{SellerController,SearchController,CategoryController}.php`,
`app/Services/Catalog/{ListingQuery,SearchQuery}.php`,
`resources/views/{sellers/show,search/index,categories/show}.blade.php`,
`resources/views/partials/{asset_card,service_card,license_panel,package_table,review_list}.blade.php`,
`resources/js/storefront/{filters,gallery,packages}.ts`.

---

## 14.7 Verification

- [ ] A draft, an unpublished, and a rejected listing appear nowhere on the storefront, in any filter combination, including direct-URL access.
- [ ] The license is readable in full on an asset page **without signing in**.
- [ ] `joystick_engine` assets show the tag on the card and the technical panel on the detail page.
- [ ] Package comparison renders correctly for a gig with one tier and with three.
- [ ] Filters compose (category + price + tag + rating) without SQL errors and with a bounded query count.
- [ ] Seller profile exposes no email, no revenue, no fee data.
- [ ] Every review is marked verified and traces to an `order_item`.
- [ ] Mobile: the grid, filter drawer, package table and gallery are usable at 375px.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/14_market_storefront_and_listings_complete.md` written.
