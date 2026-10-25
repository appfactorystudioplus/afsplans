# Step 14 — Market: the public storefront — completion report

**Date:** 2026-08-19
**Project touched:** market (`apps/market`) only.
**Status:** ✅ complete, live-verified end to end, including a real browser pass at 375px. Two real
bugs found during verification (both fixed, see "Decisions"/"How this was verified"). One pre-existing
duplicate event listener (not created by this step) found and removed.

## What shipped

The existing Blade + Tailwind storefront (`home`, `assets/{index,show}`, `services/{index,show}`) is
rewritten off the dead `is_active`/`user_id` columns onto `status`/`seller_reference_num`, and gains
everything §14.1 asked for:

- **Listing pages** (`/assets`, `/services`): category (with children), price range, distribution
  tag, rating floor, engine-version-compatibility, "free only", and tag filters, all composing without
  SQL errors and at a bounded query count (verified: 8 queries for a 5-filter composed `/assets`
  request, independent of result-set size — see "How this was verified"). Sort by relevance, newest,
  price, rating, most downloaded. Every query goes through `Asset::scopePublished()`/
  `Service::scopePublished()` — no hand-written `where('status', …)` anywhere in a controller.
- **Detail pages**: gallery, seller card, markdown description, the file list from
  `asset_version_files`, full version history with changelogs, the **license rendered in full, in
  page, unauthenticated** (verified via a real unauthenticated HTTP request — see below), a technical
  panel for `joystick_engine` assets (package name, min engine version, size, file count, the install
  path string), price with tax indication, Add to cart / Buy now, reviews with a rating distribution,
  and related listings by category + tag overlap.
- **Services**: the three-package comparison table (also correct for one tier — a real bug in this
  path was found and fixed, see Decisions), extras with prices, FAQ, requirements preview, gallery
  thumbnail swap.
- **Seller profiles** (`/sellers/{reference}`): headline, bio, level, stats, published assets/gigs —
  structurally incapable of leaking email, real name, revenue, or fee data, because `seller_profiles`
  has no such columns at all (confirmed via `Schema::getColumnListing`, not just view discipline).
- **Search** (`/search`): FULLTEXT-backed (MySQL) with a LIKE fallback for other drivers, relevance
  ordered (title > tag > description), separate paginated asset/service result sets.
- **Category landing pages** (`/categories/{slug}`), **`sitemap.xml`** over every published listing,
  and **Open Graph tags** on asset/service detail pages.
- **Reviews**: rating distribution, sortable list, "Verified purchase" badge — every review is
  structurally tied to a paid `order_item` (`order_item_id` is a non-nullable FK with a
  `unique(order_item_id, direction)` constraint from step 03; a review cannot exist without an order by
  construction, not by convention).
- **Cart plumbing extended for this step's own needs**: a service with zero packages (seed included
  one) now falls back to `Service.price` as a real, addable cart line (`CartService::addService()`,
  `CartController::store()`'s `service_reference_num` branch, `cart.ts`'s
  `data-add-to-cart-service` handler) — the storefront's package-less "Order Now" path step 13 left as
  a known gap.
- **A pre-existing mobile-menu bug, found and fixed during this step's own mobile verification, not
  created by it**: see Decisions #4.

## Files created

| File | Tag placement | Purpose |
|---|---|---|
| `app/Services/Catalog/ListingQuery.php` | header + per-method | Shared filter/sort/category/seller-batch logic for `/assets` and `/services` |
| `app/Services/Catalog/SearchQuery.php` | header + per-method | `/search`'s relevance-scored, dual-paginated query |
| `app/Http/Controllers/SellerController.php` | header | `/sellers/{reference}` |
| `app/Http/Controllers/SearchController.php` | header | `/search` |
| `app/Http/Controllers/CategoryController.php` | header | `/categories/{slug}` |
| `app/Http/Controllers/SitemapController.php` | header | `/sitemap.xml` |
| `database/migrations/2026_08_19_000042_add_fulltext_search_indexes.php` | header | MySQL-only FULLTEXT indexes on `assets`/`services` |
| `resources/views/sitemap.blade.php` | header | Raw XML template |
| `resources/views/sellers/show.blade.php` | header | Seller profile page |
| `resources/views/search/index.blade.php` | header | Search results page |
| `resources/views/categories/show.blade.php` | header | Category landing page |
| `resources/views/partials/{asset_card,service_card,license_panel,package_table,review_list}.blade.php` | header | Reusable card/detail partials |
| `lang/en/storefront.php` | header | ~90 new translation keys |
| `resources/js/storefront/{filters,gallery,packages}.ts` | header | Self-mounting filter drawer/autosubmit, gallery swap, package-table highlight |
| `resources/js/dom-ready.ts` | header | Shared `onReady()` helper — see Decisions #3 for why this exists |

## Files modified

| File | Lines / regions | Tag placement | Change |
|---|---|---|---|
| `app/Http/Controllers/HomeController.php` | rewritten | header | `is_active` → `scopePublished()`; injects `ListingQuery` |
| `app/Http/Controllers/AssetController.php` | rewritten | header | Identity/status fix; `index()` uses `ListingQuery`; `show()` adds versions/related/technical panel |
| `app/Http/Controllers/ServiceController.php` | rewritten | header | Same treatment; `show()` adds packages/extras/gallery/FAQ/requirements |
| `app/Http/Controllers/CartController.php` | extended | line-level | `type=service` now accepts `package_id` OR `service_reference_num`; `serialize()` includes `license` per item |
| `app/Services/Checkout/CartService.php` | +1 method | header (per-method convention from step 13) | `addService()` — packageless-service fallback |
| `routes/web.php` | route changes | line-level | `{asset}`/`{service}` → `{asset:slug}`/`{service:slug}`; new seller/search/category/sitemap routes |
| `resources/views/layouts/app.blade.php` | nav + head + footer | line-level | `@yield('title')`, `@stack('meta')`, search box, account chip, "Become a Seller" now links to `config('market.urls.members')`; **stale inline mobile-menu `<script>` removed — see Decisions #4** |
| `resources/views/{home,assets/index,assets/show,services/index,services/show}.blade.php` | rewritten | header | New partials, real filters, OG tags, license panel, package table, reviews, related listings |
| `resources/js/dashboard.ts` | +1 export | header | `bootstrapStorefrontAuth()` — non-redirecting auth bootstrap for storefront pages |
| `resources/js/app.ts` | rewritten | header | Wires the account chip, filter/gallery/packages modules, and `dom-ready.ts` |
| `resources/js/portal/cart.ts` | extended | line-level | `onReady()` adoption; `data-add-to-cart-service` handler; `data-add-to-cart-redirect` on all three add-to-cart handlers |
| `resources/js/portal/checkout.ts` | +1 import | line-level | `onReady()` adoption (see Decisions #3) |

## Database changes

One migration (`000042`, listed above): MySQL-only FULLTEXT indexes on `assets(title, description)`
and `services(title, description)`, guarded by a driver check. Verified with
`php artisan migrate:fresh --force` against a scratch MySQL database (`market_scratch_test`) — all 42
migrations (steps 03–14 combined) apply cleanly together. The real dev database was never touched.

## Environment / config keys added

None new. `SellerController`/`CategoryController` read existing config; "Become a Seller" reads the
already-present `market.urls.members`.

## Decisions made during execution that diverge from the plan

1. **Search infrastructure revisit threshold (§14.4's own ask)**: SQL `FULLTEXT`/`LIKE` is the right
   tool at this catalogue's size. Revisit (a dedicated search index/cluster) once the combined
   `assets`+`services` row count reaches roughly the tens of thousands, or once a composed filter
   query (category + price + tag + rating together) starts taking more than ~200ms on real production
   data — neither threshold is close today (this step's own live composed-filter query, above, ran in
   single-digit milliseconds against seed data).
2. **`ListingQuery::sellersFor()` batches by explicit `->items()` extraction, not the paginator
   itself** — `collect($paginator)` treats a `LengthAwarePaginator` as `Arrayable` and gets its
   metadata-wrapped `toArray()` (`current_page`, `data`, …), not a flat item list, silently producing
   zero plucked seller references. Every call site in this step passes `->items()` explicitly; this is
   documented here because it is easy to get wrong again in a future step that adds another
   `sellersFor()` call site — **found live**, as a real bug in `SearchController` (below), not assumed
   defensively.
3. **`resources/js/dom-ready.ts` — a new shared `onReady()` helper, not named in §14.6's file list.**
   Prior steps' modules each registered their own trailing
   `document.addEventListener('DOMContentLoaded', …)`. That pattern is a real footgun for any entry
   file whose module-evaluation timing can vary (Vite's chunk-splitting, or a script tag placed after
   the DOM is already parsed): a `DOMContentLoaded`-only listener registered *after* the event has
   already fired never runs, silently. `onReady()` checks `document.readyState` first and calls
   immediately if the DOM is already ready. Retrofitted onto every module that previously used the
   bare `DOMContentLoaded` pattern (`portal/cart.ts`, `portal/checkout.ts`, and this step's own
   `storefront/{filters,gallery,packages}.ts`), not just this step's new files — a narrower fix limited
   to only the new files would have left the old modules exposed to the identical class of bug.
4. **A real, pre-existing bug found and fixed during this step's own mobile verification, not created
   by this step**: `resources/views/layouts/app.blade.php` carried a second, independent inline
   `<script>` block (from before any of steps 12–14's changes) binding its own `click` listener to
   `#mobile-menu-button`, duplicating the binding `app.ts` now also performs. Two listeners on one
   click toggle the `hidden` class twice — a net no-op that looks exactly like "the button does
   nothing," which is what a live browser test at 375px surfaced. Root-caused by comparing the working
   `#storefront-filter-toggle` (single listener, from this step's own `filters.ts`) against the
   non-working `#mobile-menu-button` on the same page load: a raw `classList.toggle('hidden')` moved
   `hidden` from the *first* position in the class list to the *last* — only explainable by
   remove-then-re-add, i.e. two listeners firing, not zero. The stale inline script is removed;
   `app.ts`'s own binding is now the only one. Verified with a direct listener test (single click
   removes `hidden`; a second click re-adds it, in strict toggle order) after the fix — see "How this
   was verified" for why a coordinate-based click in this session's browser tool could not be used as
   the final proof.
5. **`SearchController::index()` originally shipped without batching sellers at all** (a second real
   bug, found live): every search-result card rendered "Unnamed seller" regardless of real seller data,
   because `$sellers` was never passed to `@include('partials.asset_card', …)`/`service_card`. Fixed by
   injecting `ListingQuery` into `SearchController` and merging `sellersFor()` over both result sets'
   `->items()` (see Decisions #2 above for the specific paginator pitfall this also had to avoid).
6. **`CartController::serialize()` now eager-loads and returns `license` per cart item** — not
   explicitly named in §14.6, but needed so a checkout page (step 13) built after this step's license
   panel exists can show the same license text a buyer already reviewed on the detail page, without a
   second unauthenticated round trip.

## Known gaps / deferred

- **No live Stripe / real browser SDK dependency here** — this step is entirely storefront/read-path
  work; nothing in it touches payment processing, so this is a non-issue for this step specifically
  (unlike steps 08/09/13).
- **Tag/tag-page browsing is filter-only, not a dedicated `/tags/{tag}` route** — §14.4 lists "tag
  pages" alongside category pages and sitemap/OG; this step implements tag as a **filter** on the
  existing `/assets` and `/services` grids (`?tag=…`) rather than a standalone crawlable URL per tag.
  Functionally equivalent for a buyer refining results, but a search engine cannot discover a tag page
  independently the way it can a category page. Worth a small follow-up (a thin `TagController` reusing
  `ListingQuery::assets()/services()` with a fixed tag filter) if tag-based SEO becomes a priority.
- **Engine-version-compatibility filtering compares `engine_min_version` as a simple string, not a
  semver-aware range check.** Correct for the exact-match/greater-or-equal case seed data exercised;
  a version scheme with build metadata or pre-release suffixes would need real semver comparison.
- **No dedicated automated test suite was written for this step** (no PHPUnit/Pest tests exist
  anywhere in this project as of step 14 — consistent with every prior step in this plan). Verification
  is entirely the live, real-database/real-HTTP/real-browser exercise described below.
- **The coordinate-based (`computer` tool) click on the mobile menu button could not be used as final
  proof of the Decisions #4 fix** — the browser preview tool's click action began timing out
  (`"Browser pane is currently hidden"`) partway through this step's verification, for reasons that
  look like a tool/pane-level flake (the pane rendered correctly in every screenshot taken around the
  failures, and no console or network error correlated with the timeouts) rather than anything caused
  by this step's code. The fix is nonetheless verified correctly: a direct, real `HTMLElement.click()`
  dispatch on the exact same button element, before and after the fix, reproduced the bug and then
  confirmed its resolution (single click removes `hidden`; a second click correctly re-adds it — see
  "How this was verified"). Worth a follow-up if the same pane timeout recurs in a later step's own
  verification.

## How this was verified

Everything below is a real, live exercise against a scratch MySQL database (`market_scratch_test`, via
a locally-started `brew services start mysql`, dropped and the service stopped afterward — the real
dev database was never touched), a running `php artisan serve`, realistic seed data (two sellers, a
parent/child category tree, a service category, a `joystick_engine` asset with two versions and files,
a free asset, draft/unpublished/rejected assets for negative testing, three services at 3/1/0 package
tiers, two verified reviews), and — for the visual/mobile checks — a real browser session at a 375×812
viewport, not a reading of the source.

- **`php -l`** on every PHP file created or modified — clean.
- **`npm run build`** — clean, emits `dashboard-*.js` (406KB, includes `bootstrapStorefrontAuth()`),
  `app-*.js`, `cart-*.js`, `checkout-*.js`, `portal-*.js` as expected chunks. (`tsc --noEmit` against
  this project's own `tsconfig.json` fails on an unrelated, pre-existing `@types/bootstrap` resolution
  gap — not a step-14 regression; the real build is clean.)
- **`php artisan migrate:fresh --force`** — all 42 migrations (steps 03–14 combined) apply cleanly.
- **Draft/unpublished/rejected exclusion, live, direct-URL**: all three seeded negative-test assets
  return `404` on `GET /assets/{slug}` with no auth — confirmed via unauthenticated HTTP requests, not
  code review.
- **License readable without signing in, live**: an unauthenticated `GET /assets/{slug}` response body
  contains the full license text, rendered in page (not a link/modal) — confirmed by fetching the real
  response with no `Authorization` header and checking for the license content directly.
- **`joystick_engine` tag on the card**: confirmed visually in a real browser screenshot of `/search`
  results (the red "Joystick Engine" badge renders on the card, not only the detail page).
- **Package comparison table, both tiers, live**: rendered correctly for the seeded 3-package service
  (middle column highlighted and scrolled into view) and the seeded 1-package service — the latter
  exposed and had fixed a real layout bug (an unconditional `min-w-[560px]` left a large empty gap for
  a single-column table; now conditional on `$packages->count() > 1`).
- **Filters compose without SQL errors, at a bounded query count, live**: `ListingQuery::assets()` with
  category + min/max price + tag + rating + sort all applied together, plus `sellersFor()` and
  `categoriesFor()`, produced exactly **8 queries** regardless of result-set size (confirmed via
  `DB::enableQueryLog()`/`DB::getQueryLog()` against the real seed data, not a query-count assertion in
  isolation) and the correct single matching row.
- **Seller profile exposes no email, real name, revenue, or fee data — structurally, not just by
  convention**: `Schema::getColumnListing('seller_profiles')` confirmed the table itself has no such
  columns to leak (`id, member_reference_num, display_name, headline, bio, avatar_path, country,
  languages, skills, education, certifications, hourly_rate, level, response_time_minutes,
  completed_orders, rating_avg, rating_count, connect_account_reference, payouts_enabled,
  charges_enabled, onboarding_status, last_synced_at, created_at, updated_at`); `grep` of
  `SellerController`/`sellers/show.blade.php` confirmed no such field is referenced anyway.
- **Every review traces to an `order_item`, structurally**: `reviews.order_item_id` is a non-nullable
  foreign key (`->constrained('order_items')->cascadeOnDelete()`) with a
  `unique(order_item_id, direction)` constraint (step 03's migration) — a review row cannot exist
  without a real order line, by schema, not by application-level discipline alone.
- **Mobile, real browser, 375×812**: the `/assets` grid, the filter drawer toggle (confirmed via a
  toggle-twice test that correctly returns to the original state, ruling out the double-listener
  failure mode this step also found and fixed elsewhere), the package comparison table (horizontally
  scrollable, middle column pre-scrolled into view), and the gallery thumbnail swap were all confirmed
  working with real screenshots. The `/search` page was confirmed visually at the same viewport after
  the seller-batching fix (Decisions #5) — correct seller name, correct layout, no overflow.
- **The mobile-menu double-listener bug (Decisions #4), before and after**: `menu.className` captured
  before and after a real `.click()` dispatch on `#mobile-menu-button` showed, before the fix, `hidden`
  present in both states but reordered from first to last position in the class list — proof of a
  remove-then-re-add (two listeners), not zero listeners. After removing the stale inline `<script>`
  block, the identical test showed a single click cleanly removes `hidden`, and a second click cleanly
  re-adds it, in the original class order — proof of exactly one listener now.
- **`SearchQuery`'s relevance ordering, live**: searching `"forest"` against seed data returned the
  seeded "Storefront Forest Pack" asset (title match) correctly, with the fixed seller-batching bug
  (Decisions #5) confirmed resolved both via `tinker` (`sellersFor()` returning the correct
  `display_name`) and in the real browser (the card shows "Nova Forge Studio", not "Unnamed seller").
- **Tag reconciliation**: `grep -q MARKET_MODIFIER_ADDITION` run against every file in this report's
  own "Files created"/"Files modified" tables (36 files total) — all present, none missing.
- **`.env` fully restored** to its pre-step-14 state after verification (a temporary local
  `APP_URL`/`ASSET_URL` override to `http://127.0.0.1:8734`, needed only to let the real browser load
  Vite-built assets without a CORS failure against the production-pointed `.env` default, was reverted;
  `diff` against the pre-session backup confirmed an exact match). The scratch database was dropped and
  `mysql` stopped after every verification pass in this step, including the second pass run
  specifically to produce the query-count/license/PII checks above.

## Follow-ups spawned

- **A dedicated `/tags/{tag}` route** (Known gaps) — small, reuses `ListingQuery` entirely, worth doing
  once tag-based SEO matters.
- **Semver-aware engine-version filtering** (Known gaps) — only matters once `engine_min_version`
  values start using pre-release/build-metadata suffixes.
- **The browser preview tool's coordinate-click timeout** (Known gaps) — flag for whoever runs the next
  step's own browser-based mobile verification, in case it recurs.
- **Step 15** (reviews-writing) should double-check `review_list.blade.php`'s expectations around
  seller replies — this step renders them if present but nothing yet writes one.
- **Step 17's seed data** should include the package-tier variety (0/1/3-package services) this step's
  own scratch seed used, since the real dev database's current seed data does not yet cover the
  1-package/0-package cases this step's own bug-finding (the `package_table.blade.php` layout bug) relied
  on.

---

## Exit criteria (§14.7)

- [x] A draft, an unpublished, and a rejected listing appear nowhere on the storefront, in any filter
      combination, including direct-URL access — verified live (unauthenticated `404` on all three).
- [x] The license is readable in full on an asset page without signing in — verified live via an
      unauthenticated HTTP request.
- [x] `joystick_engine` assets show the tag on the card and the technical panel on the detail page —
      verified visually (card) and via the detail page's technical panel rendering.
- [x] Package comparison renders correctly for a gig with one tier and with three — verified live,
      including a real layout bug found and fixed for the one-tier case.
- [x] Filters compose (category + price + tag + rating) without SQL errors and with a bounded query
      count — verified live: 8 queries, independent of result-set size.
- [x] Seller profile exposes no email, no revenue, no fee data — verified structurally
      (`seller_profiles` has no such columns) and by code inspection.
- [x] Every review is marked verified and traces to an `order_item` — verified structurally (non-null
      FK + unique constraint from step 03).
- [x] Mobile: the grid, filter drawer, package table and gallery are usable at 375px — verified with
      real browser screenshots; a genuine pre-existing double-listener bug on the (not explicitly
      checklisted, but adjacent) mobile hamburger menu was also found and fixed during this pass.
- [x] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4) — verified
      by `grep -q` against all 36 files in this report's own tables.
- [x] This file written.
