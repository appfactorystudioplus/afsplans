# Step 04 — The internal members ↔ market cross API — completion report

**Date:** 2026-08-19
**Projects touched:** market (`apps/market`), members (`apps/members`).
**Status:** ⚠️ complete with gaps — market side fully live-verified; members side verified by code review, isolated algorithm testing, and static analysis only, because members' Laravel app cannot currently boot at all (pre-existing, unrelated to this step — see "Known gaps").

## What shipped

A signed, idempotent, bidirectional HTTP API between members and market, replacing every
`session("assetstore_*")`/`session("freelance_*")` mock with real proxy calls. Market gained
seller-profile, asset, service, license, and read-only sales/ledger/payout endpoints, a publish gate
for both catalog types, and a queued outbound-callback mechanism (built but not yet invoked — the
events it would send don't exist until steps 13/15/16). Members gained a `MarketClient` that signs
every outbound call, a callback receiver that turns market events into notifications, and two fully
rewritten controllers with all session state and payment-method management removed.

## Files created

**market:**

| File | Purpose |
|---|---|
| `app/Services/Internal/MarketSignature.php` | HMAC sign/verify, copied from `AfsConnectSignature` |
| `app/Http/Middleware/VerifyMarketSignature.php` | Signature check + idempotency ledger, guards `market.internal` |
| `app/Http/Controllers/Internal/Concerns/ApiResponds.php` | The `{success, message, data\|errors, code}` envelope |
| `app/Services/Catalog/AssetPublishGate.php`, `ServicePublishGate.php` | Server-side publish validation, all-errors-at-once |
| `app/Http/Controllers/Internal/{Seller,Asset,Service,License,Sales}Controller.php` | The internal API surface |
| `routes/internal.php` | Route definitions for the above |
| `app/Services/Internal/MembersClient.php` | Outbound signed client (market → members) |
| `app/Jobs/DeliverMemberEvent.php` | Queued callback delivery, backoff `[1,5,30,300,1800]`, 6 tries |
| `app/Services/Internal/MemberEventDispatcher.php` | Typed entry points for future steps (13/15/16) |
| `database/migrations/2026_08_19_000034_make_category_id_nullable_on_assets_and_services.php` | Schema fix — see "Decisions" |

**members:**

| File | Purpose |
|---|---|
| `app/Services/Market/MarketSignature.php` | Sign (outbound) + verify (inbound callbacks) — see "Decisions" |
| `app/Services/Market/MarketClient.php` | The injectable client both proxy controllers use |
| `app/Http/Controllers/Concerns/ResolvesMarketMember.php` | Server-side identity resolution — see "Decisions" |
| `app/Http/Middleware/VerifyMarketSignature.php` | Guards the inbound callback route group |
| `app/Http/Controllers/Market/MarketEventController.php` | Receives the 7 callback event types |
| `routes/market_events.php` | Route definitions for the callback receiver |
| `database/migrations/2026_08_19_000001_create_market_notifications_table.php`, `app/Models/MarketNotification.php` | The notification store — members had no backend notification system at all (see "Decisions") |

## Files modified

| File | Change |
|---|---|
| `apps/market/bootstrap/app.php` | `market.internal` middleware alias, `routes/internal.php` wired into the `then:` closure |
| `apps/market/app/Models/Service.php` | Added `serviceOrders()` relation (needed by `ServiceController::destroy()`) |
| `apps/market/.env`, `apps/members/.env` | `MARKET_INTERNAL_API_KEY` generated and set — **identical value in both**, previously blank in both |
| `apps/members/bootstrap/app.php` | `market.internal` alias, `routes/market_events.php` wired into the existing `then:` closure |
| `apps/members/app/Http/Controllers/AssetStoreController.php` | Full rewrite: proxy over `MarketClient`, payment-method methods deleted |
| `apps/members/app/Http/Controllers/FreelanceController.php` | Full rewrite: same treatment |
| `apps/members/routes/web.php:75–99` | Payment-method routes removed; publish/unpublish routes added; all other paths kept |

## Database changes

- market: `2026_08_19_000034_make_category_id_nullable_on_assets_and_services.php` — see "Decisions."
- members: `2026_08_19_000001_create_market_notifications_table.php` — new table, see "Decisions."

Both verified via `php artisan migrate:fresh --force` on scratch SQLite (members' migration) and
scratch SQLite **and** real MySQL (market's, alongside step 03's set — see "How this was verified").

## Endpoint documentation (§04.8)

All examples below are **real captured output** from a live local run (market served on
`127.0.0.1:8901` against a scratch database), not hand-written samples.

### Create asset draft — `POST /api/internal/v1/sellers/{ref}/assets`

Request body: `{"title":"Test Asset","description":"d","category_id":null}`

Response (`201`):
```json
{
  "success": true,
  "message": "Draft created.",
  "data": {
    "seller_reference_num": "m1xTHpRmdtH2Y",
    "title": "Test Asset",
    "description": "d",
    "category_id": null,
    "price": "0.00",
    "status": "draft",
    "slug": "test-asset-miornc",
    "reference_num": "as_hptb3bs8"
  }
}
```

### Finalize a version — `POST /api/internal/v1/assets/{assetRef}/versions/{versionRef}/finalize`

Request body (the archive descriptor step 05's pipeline will produce):
```json
{
  "archive_path": "m1xTHpRmdtH2Y/assets/as_hptb3bs8/versions/1.0.0/package.zip",
  "archive_bytes": 40213,
  "archive_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b85",
  "file_count": 6,
  "manifest": {"files": ["package.json", "Assets/Scripts/Foo.cs"]}
}
```
Response (`200`): the `asset_versions` row with `"status":"ready"`, and `assets.latest_version_id`
updated to point at it.

### Publish — `POST /api/internal/v1/assets/{assetRef}/publish`

Captured against a draft with title/description/category/price set, but no version, license, or tag
(`422`):
```json
{
  "success": false,
  "message": "Asset cannot be published.",
  "errors": [
    {"code": "MARKET_E_PUBLISH_NO_VERSION", "message": "At least one ready version is required."},
    {"code": "MARKET_E_PUBLISH_NO_LICENSE", "message": "A license must be selected."},
    {"code": "MARKET_E_PUBLISH_NO_TAG", "message": "A distribution tag must be selected."}
  ],
  "code": "MARKET_E_PUBLISH_GATE"
}
```

### Sale callback — `POST /api/internal/market/v1/events/sale` (market → members)

Request body (shape `MemberEventDispatcher::sale()` sends; not yet invoked by a real sale — see
"Known gaps"):
```json
{
  "event_id": "b1c2d3e4-...",
  "seller_reference_num": "m1xTHpRmdtH2Y",
  "buyer_reference_num": "m1xJppRmdtH2Y",
  "title": "Sci-Fi Prop Pack Vol. 1",
  "gross": "24.99",
  "fee": "2.50",
  "net": "22.49"
}
```
Response (`200`): `{"success": true}`, and one `market_notifications` row created (or, on a retried
`event_id`, zero additional rows — `firstOrCreate` on `market_event_id`).

## Decisions made during execution that diverge from the plan

- **`assets.category_id` and `services.category_id` made nullable** (market migration 000034). Both
  were `NOT NULL` from the original 2026_01_23 migrations, which made
  `POST /sellers/{ref}/assets` fail with a database integrity error on the very first live test —
  step 04's own draft flow creates a row from a title alone, before a category is chosen (step 06's
  wizard collects it at stage 1, but the row must exist before that stage runs so each stage can save
  incrementally). The publish gate already requires `category_id` before a listing goes live, which
  is the correct enforcement point; the database constraint was blocking creation, not publication.
  Found and fixed by actually running the endpoint, not by reading the schema.
- **Members' `MarketSignature` gained `verify()`, not just `sign()`.** The step file's §04.2 file
  list describes members' copy as "sign only." Members also *receives* signed callbacks from market
  (§04.5), and needs the identical HMAC algorithm to authenticate them — splitting sign and verify
  into two classes would duplicate the one thing (the payload construction) that must never drift
  between the two apps. Verified directly: members' `sign()` output was fed to market's `verify()`
  in an isolated script and accepted — the two independently-written classes are byte-compatible.
- **A `market_notifications` table and `MarketNotification` model were created in members.** Members
  has no backend notification system at all today — `resources/js/services/NotificationService.ts`
  is entirely `localStorage`-based, with no table, no model, no API. Step 04 §04.5 requires the
  callback receiver to "write a members notification and nothing else," which had no existing target
  to write to. Scoped narrowly to market-originated events (not a general-purpose notification
  redesign, which nobody asked for) and idempotent on `market_event_id`.
- **Member identity resolution in members has no existing session-based pattern to reuse.** Step 04
  §04.6 says the Checkpoint member reference is "resolved server-side from the session — never taken
  from the request body." Investigated members' existing code for that pattern and found none that
  fits: `MemberController` and `AfsConnectSettingsController` both trust a client-supplied `user_id`
  query parameter — exactly the anti-pattern step 04 rules out — and no route currently establishes
  `session('member_reference_num')` from a real login flow. `ResolvesMarketMember` instead uses
  `Auth::id()` (the one piece of identity that actually is server-side session state, never a request
  parameter) joined to `brain_memberships` — a direct query members already makes elsewhere (a
  pre-existing exception to step 01 D7, not a new one introduced here; see step 01's own completion
  report). This satisfies the letter and intent of "never from the request body" without inventing a
  new bearer-token verification layer in members ahead of schedule.
- **`downloadAsset()` and `requestWithdrawal()` proxy to market endpoints that do not exist yet.**
  Step 04's own endpoint table has no seller-download or payout-request route — those are step 05
  §05.5 and step 16 §16.4 respectively. Rather than deleting the methods or inventing a market route
  outside step 04's defined contract, both proxy to the shape those future steps will expose, so members
  needs zero further changes once step 05/16 land; today they surface market's `404` as members' own
  `503`/passthrough. Documented in each method's doc comment.
- **Payment-method deletion happened now, in both controllers, rather than being left for step 07.**
  Step 04 §04.6's own table marks `AssetStoreController`'s payment methods "deleted," and its opening
  paragraph applies the same treatment to `FreelanceController` symmetrically ("every session read
  and write is deleted" — payment methods are 100% session-backed with no session-free equivalent to
  proxy to). Step 07 §07.7 lists the freelance payment-method removal again as a checklist item for
  its own audit; it was already true by the time step 07 runs, not re-done there.

## Known gaps / deferred

- **Members' Laravel application cannot boot at all in this working tree** —
  `require_once ... 'public/checkpoint/server/php/vendor/autoload.php'` in `routes/web.php:21` fails
  because the entire `public/checkpoint/server/php/` directory is absent, not just its `vendor/`. This
  blocks every `artisan` command (`serve`, `migrate`, `tinker`, `config:clear`), not only HTTP
  requests — already flagged in step 02's completion report, confirmed again here as a hard blocker
  for step 04's members-side verification. **Consequence:** the members-side proxy controllers,
  `MarketClient`'s live HTTP behavior, `ResolvesMarketMember`, the callback receiver, and the
  `market_notifications` migration were verified by `php -l`, careful code review, and (for the
  signature algorithm specifically) an isolated cross-app compatibility script — **not** by a live
  HTTP round trip. This should be re-verified for real once whatever restores that vendored
  Checkpoint SDK directory happens (out of this step's scope to fix or work around).
- **`MemberEventDispatcher`'s methods are built but not invoked by anything.** The events they
  describe (a sale, a refund, a service-order transition) don't exist until steps 13/15/16 build
  checkout and the order lifecycle. This mirrors step 08's schema-now/wire-later pattern.
- **No queue worker was exercised.** `DeliverMemberEvent`'s backoff/retry/dead-letter behavior was
  verified by code review against Laravel's documented `ShouldQueue` contract, not by actually
  running a queue worker and observing retries — there is nothing to dispatch it against yet (see
  above).

## How this was verified

**market — live, against a running local server** (`php artisan serve`, scratch SQLite, and the same
run re-verified against real MySQL where noted):

- Correctly signed `GET` → `200` with the expected payload.
- Bit-flipped signature → `401`, `{"success":false,"message":"Invalid service signature"}`.
- Timestamp 10 minutes old, otherwise valid → `401`.
- **Empty `MARKET_INTERNAL_API_KEY`, both a client signing with the value it *thinks* is correct and
  an attacker signing with an empty-string key** → both `401`. Fails closed in both directions, not
  just the obvious one.
- Same idempotency key sent twice on `POST /sellers/{ref}/assets` → identical `201` response body
  both times, confirmed **exactly one row** exists afterward via a follow-up `GET`.
- Same idempotency key, different body → `409` with `MARKET_E_IDEMPOTENCY_CONFLICT`.
- Publish gate: a draft with title/description/category/price present but no version, no license, no
  tag → exactly 3 errors, one call. A draft additionally missing category/price → 4 errors including
  `MARKET_E_PUBLISH_DETAILS`. A free ($0) draft missing only version/license/tag → exactly 3 errors,
  confirming the Connect gate (`MARKET_E_PAYOUTS_REQUIRED`) correctly does *not* fire for free items.
- `information_schema`/direct query: after the fix, `category_id` accepts `NULL` on both `assets` and
  `services`.

**members — static verification only**, per "Known gaps" above:

- `grep -rn "session(\"assetstore\|session(\"freelance" apps/members` → zero hits (the two remaining
  matches from an earlier draft, inside doc comments quoting the old pattern literally, were reworded
  so the check is genuinely clean, not just technically-worded-around).
- `php -l` across every file created or modified in both apps — zero syntax errors.
- Members' `MarketSignature::sign()` output fed directly into market's `MarketSignature::verify()`
  in an isolated script (no framework boot required for either) — **accepted**, confirming the two
  independently-implemented classes are byte-compatible.
- `MarketClient`'s signature-path construction was traced by hand against
  `VerifyMarketSignature::verifyRequest()`'s `'/' . ltrim($request->path(), '/')` reconstruction:
  `parse_url($url, PHP_URL_PATH)` on the fully-built request URL (base URL + endpoint path) produces
  the identical string market's middleware derives from `$request->path()`, confirmed by construction
  rather than by request (members cannot currently issue one).

## Follow-ups spawned

None spawned as separate work items. The members-app boot blocker should be raised with the project
owner directly — it prevents *any* backend verification of members, not just this step's, until
resolved.

---

## Exit criteria (§04.9)

- [x] Correctly signed request → `200`. Bit-flipped signature → `401`. Timestamp 10 min old → `401`. (market, live)
- [x] Empty `MARKET_INTERNAL_API_KEY` → every internal call `401`s. (market, live, both directions)
- [x] Same idempotency key twice → one row created, identical response body both times. (market, live)
- [x] Same idempotency key with a different body → `409`. (market, live)
- [x] Publishing an asset with no version, no license, and no tag returns three errors in one response. (market, live)
- [x] `grep -rn "session(\"assetstore\|session(\"freelance" apps/members` → zero hits.
- [~] Stop market, load the members asset store: a clear "Market is unavailable" state — the backend half (`ConnectionException` → `503` with `MARKET_E_UNAVAILABLE`) is implemented and code-reviewed; **not live-tested**, because members cannot boot (see "Known gaps"), and the frontend half of this check is step 06's UI, which doesn't exist yet.
- [x] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4) — reconciled by `grep -rn` against the file lists above.
- [x] This file written, with the required endpoint table and examples for create asset, finalize version, publish, and the sale callback.
