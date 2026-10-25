# Step 12 — Market: the `/portal` buyer dashboard

**On completion:** write `complete/12_market_portal_dashboard_complete.md`.
**Depends on:** [11](11_market_portal_auth.md), [03](03_market_database_schema.md), [05](05_asset_storage_and_upload_pipeline.md).

> **Auth model:** the browser owns the Checkpoint session and talks to auth15 directly (step 11
> §11.2). Every controller here receives its member reference from token verification — never from a
> Laravel session, and never from the request body or query string.
**Blocks:** 18, 19.

> *"The dashboard should have all the services and assets the user purchased."*

---

## 12.1 The one rule that shapes everything here

**Every query reads `entitlements`, never `orders` and never `assets` directly.**

An asset can be unpublished, reprice, change its license, gain versions, or have its seller leave.
An order can be partially refunded. None of those change what a buyer owns. Only `entitlements`
holds that, and it holds it immutably with the license text snapshotted at purchase (step 03).

The practical test: unpublish a purchased asset and the buyer's dashboard must be completely
unchanged — same entry, same download, same license. If anything moves, something read the wrong
table.

---

## 12.2 Pages

### Dashboard — `GET /portal`

Four stat tiles (assets owned, active service orders, completed orders, lifetime spend), then:
recent purchases (6), service orders needing the buyer's attention (requirements unanswered,
delivery awaiting review, revision window closing), and recently updated assets — *"3 of your assets
have new versions"*, which is the dashboard's highest-value line and the reason `asset_versions`
exists.

### My Assets — `GET /portal/assets`

Card or table grid of every active entitlement where `purchasable_type = Asset`.

Per row: thumbnail, title, seller, purchase date, **owned version**, **latest version** with an
update badge when they differ, distribution-tag chip, and Download / Details.

Filters: distribution tag, category, seller, "has update". Search across title and seller.

The distribution-tag chip is worth stating: an asset tagged `joystick_engine` shows
*"Available in the Joystick Engine editor"*, which is where a buyer learns the asset they bought
appears somewhere other than this page.

### Asset detail — `GET /portal/assets/{entitlement}`

Description, gallery, seller link, then the three things this page exists for:

1. **Version list.** Every `ready` version with changelog, size, date, and a per-version Download.
   Buyers get every version, not only the newest — a project pinned to 1.0.0 must be able to
   re-fetch 1.0.0 after 2.0.0 ships.
2. **License.** The `license_body_snapshot` from the entitlement, rendered in full, with its
   version and purchase date. **Not** the live `market_licenses` row — the snapshot is the contract
   the buyer actually agreed to. Downloadable as text.
3. **Downloads.** Redeems a signed URL (step 05). Each redemption writes an `asset_downloads` row.

### My Services — `GET /portal/services`

Purchased service orders, grouped by state, with due dates, the ordered package and extras, and
whatever action is next for the buyer: answer requirements, review a delivery, accept, request a
revision, message the seller, open a dispute. Full lifecycle in step 15; this is the buyer's list
view of it.

### Orders — `GET /portal/orders`, `GET /portal/orders/{order}`

History with reference, date, items, totals, status. Detail is a printable receipt: line items,
per-item tax, total, payment reference, and refund records. **Not** the fee split — the buyer paid
the list price and the platform's cut is not their business. Sellers see the split (step 16); buyers
do not.

### Licenses — `GET /portal/licenses`

Every snapshot the buyer holds, in one searchable place, with the asset and purchase date. Exists
because "which license covers the pack I used in the client project?" is a question people actually
need to answer, sometimes urgently, and hunting through purchase history to answer it is bad.

### Messages, Settings

Order-scoped threads (step 15). Settings: display name, receipt email (defaults to Checkpoint's,
overridable), notification preferences, and a data export.

---

## 12.3 Empty states

A new buyer sees six empty pages. Each gets a purposeful empty state with one action into the
storefront — never a bare "No data". The dashboard's empty state is the highest-traffic page in the
whole portal at launch and deserves the most attention.

---

## 12.4 Performance

- Eager-load `entitlement.purchasable.latestVersion` and `.seller` — the asset grid is otherwise a
  textbook N+1 across four relations.
- Paginate at 24.
- Cache the update-badge computation (owned vs latest version) per member for 60s. It touches every
  row on the busiest page and does not need to be real-time.
- Thumbnails served from the public disk with far-future cache headers; **never** from
  `/mnt/node2` — that path is `internal;`-only by design.

---

## 12.5 Files

> **Tagging (step 01 §01.4) — every file below.** Created files carry
> `MARKET_MODIFIER_ADDITION` in their top doc block; modified files carry it at each changed line or
> region. `grep -rn MARKET_MODIFIER_ADDITION` must return this list in full, or the change index is
> already wrong.


**Created:** `app/Http/Controllers/Portal/{Dashboard,Assets,Services,Orders,Licenses,Messages,Settings}Controller.php`,
`app/Services/Portal/EntitlementQuery.php` (the shared read layer — one place that knows the
entitlement rules, so no controller re-derives them),
`resources/views/portal/{dashboard,assets/index,assets/show,services/index,services/show,orders/index,orders/show,licenses/index,messages/index,settings}.blade.php`,
`resources/views/portal/partials/*`,
`resources/js/portal/{dashboard,assets,downloads,filters}.ts`.

**Modified:** `routes/portal.php`, `resources/js/portal.ts`, `resources/css/app.css`.

---

## 12.6 Verification

- [ ] Buyer `m1xJppRmdtH2Y` sees exactly the seeded purchases (step 17), no more.
- [ ] Unpublish a purchased asset in market: the dashboard entry, the download, and the license are all unchanged.
- [ ] Publish 1.0.1 for an asset owned at 1.0.0: the update badge appears and **both** versions download.
- [ ] Edit the seller's live license row: the buyer's displayed license text does not change.
- [ ] `/portal/assets/{entitlement}` for someone else's entitlement → `403`/`404`.
- [ ] A revoked entitlement disappears from the list and its download returns `403`.
- [ ] Asset grid with 24 rows issues a bounded query count (verify with the query log, not by feel).
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/12_market_portal_dashboard_complete.md` written.
