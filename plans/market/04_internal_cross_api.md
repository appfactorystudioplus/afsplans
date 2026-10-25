# Step 04 — The internal members ↔ market cross API

**On completion:** write `complete/04_internal_cross_api_complete.md`.
**Depends on:** [02](02_environment_and_config.md), [03](03_market_database_schema.md).
**Blocks:** 05, 06, 07, 10, 16.

---

## 04.1 The model to copy, and where to depart from it

`members/app/Http/Controllers/Brain/DomainController.php` is the precedent named in the brief. Its
shape:

```php
private function brainApiUrl(): string
{
    return rtrim(env('BRAIN_API_URL', 'https://www.appfactory.studio/portal/api/v1/brain'), '/');
}

$zonesResponse = Http::withHeaders(['Accept' => 'application/json'])
    ->get($this->brainApiUrl() . '/dns/zones', ['member_reference_num' => $memberRef]);
```

Copy: the directory convention (`app/Http/Controllers/<Peer>/`), the `Http::` facade, the
`{success, message, data}` envelope, `member_reference_num` in every payload, the heavy
`/** */` header that explains the whole flow at the top of the file.

Depart in three places:

1. **`env()` at call time → `config()`.** Step 02's rule. `DomainController` predates it.
2. **Unauthenticated → HMAC-signed.** `DomainController` calls brain over plain HTTPS with no
   proof of origin, relying on network position. That is survivable for a DNS zone list. It is not
   survivable for "create a listing that sells for money as member X" — an unsigned endpoint that
   trusts a `member_reference_num` in the body is an open impersonation API.
3. **Split client from controller.** `DomainController` builds URLs, signs nothing, parses
   responses, and renders — all in one class. Here, an injectable `MarketClient` service owns
   transport, and the controller owns HTTP shape. It makes the signing testable and stops the
   signing logic being copy-pasted into the eleventh method that needs it.

---

## 04.2 Service authentication

Reuse the algorithm already proven in this codebase:
`www/app/Services/AfsConnect/AfsConnectSignature.php`.

```
payload   = "{timestamp}.{METHOD}.{path}.{sha256(body)}"
signature = hmac_sha256(payload, shared_key)
```

Headers:

| Header | Value |
|---|---|
| `X-Market-App` | `MEMBERS` or `MARKET` (the *sender*) |
| `X-Market-Timestamp` | unix seconds |
| `X-Market-Signature` | hex hmac |
| `X-Market-Idempotency-Key` | uuid v4, required on every non-GET |
| `Accept` | `application/json` |

Rules, each with its reason:

- **Skew window ±300s.** Wider makes a captured request replayable for longer; narrower breaks on
  ordinary clock drift between two hosts.
- **`hash_equals` only.** Never `==` — timing-comparing an HMAC leaks it a byte at a time.
- **Empty key ⇒ reject.** `AfsConnectSignature::verify()` already returns `false` on an empty
  service key. Preserve that: an unset `MARKET_INTERNAL_API_KEY` must fail closed, not open.
- **Body hashed over raw content.** `$request->getContent()`, before any JSON decoding, so a
  re-encode cannot change the hash.
- **Two accepted keys.** `key` and `key_next` both verify, so a rotation is: set `_NEXT` on both
  sides → deploy → promote `_NEXT` to primary → clear. No downtime window.
- **Idempotency ledger.** `internal_api_requests` (step 03). A repeated key returns the stored
  response verbatim and does not re-execute. Without this, a members-side retry after a timeout
  double-publishes.

### Files

> **Tagging (step 01 §01.4).** Every file this step creates or modifies carries
> `MARKET_MODIFIER_ADDITION` — created files in the top doc block, modified files at each changed
> line or region. `grep -rn MARKET_MODIFIER_ADDITION` is the change index for this whole effort.


**market** — `app/Services/Internal/MarketSignature.php` (verify/sign),
`app/Http/Middleware/VerifyMarketSignature.php`, aliased `market.internal` in `bootstrap/app.php`.
Rejections return `401` with `{success: false, message: 'Invalid service signature'}` and log the
sender app, path, and the reason (skew / mismatch / missing key) — never the expected signature.

**members** — `app/Services/Market/MarketSignature.php` (sign only),
`app/Services/Market/MarketClient.php`.

---

## 04.3 Routes

New file `apps/market/routes/internal.php`, wired in `bootstrap/app.php`:

```php
->withRouting(
    web:      __DIR__.'/../routes/web.php',
    commands: __DIR__.'/../routes/console.php',
    health:   '/up',
    then: function () {
        /** MARKET_MODIFIER_ADDITION */
        Route::middleware(['api', 'market.internal'])
            ->prefix('api/internal/v1')
            ->group(base_path('routes/internal.php'));

        Route::middleware('web')
            ->prefix('portal')
            ->group(base_path('routes/portal.php'));   // step 11
    },
)
```

Both route files land in the same `then:` closure; step 11 adds the second line. Note it here so
the two steps do not each rewrite `bootstrap/app.php` from scratch.

`api/internal/v1` is `api`-middleware'd: stateless, no session, no CSRF. It is a service-to-service
channel and must never accept a browser cookie as authentication.

---

## 04.4 Endpoints — members → market

Controllers under `apps/market/app/Http/Controllers/Internal/`.

### Seller profile

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/sellers/{ref}` | Profile + aggregate stats. Auto-creates an empty profile on first read. |
| `PUT` | `/sellers/{ref}` | Update display name, bio, skills, languages. |
| `GET` | `/sellers/{ref}/dashboard` | The numbers the members asset-store/freelance headers show: balance, lifetime sales, listing counts, pending payout, open orders. Replaces the `session("assetstore_balance_…")` mock wholesale. |

### Assets

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/sellers/{ref}/assets` | Paginated, filterable by status. |
| `POST` | `/sellers/{ref}/assets` | Create a **draft**. Returns `reference_num`. |
| `GET` | `/assets/{assetRef}` | Full record incl. versions + license. |
| `PUT` | `/assets/{assetRef}` | Update metadata, price, tax, license, distribution tag. |
| `POST` | `/assets/{assetRef}/versions` | Begin a version upload — returns an upload target + version ref (step 05). |
| `POST` | `/assets/{assetRef}/versions/{v}/finalize` | Server validates + zips + marks `ready` (step 05). |
| `POST` | `/assets/{assetRef}/publish` | Draft/unpublished → published. Runs the publish gate (below). |
| `POST` | `/assets/{assetRef}/unpublish` | Delisted. **Existing entitlements are untouched.** |
| `DELETE` | `/assets/{assetRef}` | Soft-delete. Refuses with `409` if any entitlement exists — you cannot delete something people own. |

**The publish gate**, evaluated server-side on `POST /publish` and returning a *list* of failures,
not the first one:

1. Title, description, category, price present.
2. At least one `asset_versions` row with `status = ready`.
3. A `license_id` — the system default counts, but it must be an explicit choice.
4. `distribution_tag` set.
5. If `distribution_tag = joystick_engine`: `package_name` present, globally unique, matching
   `^[a-z0-9]+(\.[a-z0-9-]+)+$`; `engine_min_version` valid semver.
6. If `price > 0`: the seller's `seller_profiles.charges_enabled` is true (step 10).

Returning all failures matters. A six-field wizard that reveals one missing field per round trip is
six round trips.

### Services

The mirror set: `GET/POST /sellers/{ref}/services`, `GET/PUT/DELETE /services/{svcRef}`,
`publish`/`unpublish`, plus child collections `packages`, `extras`, `gallery`, `faqs`,
`requirements` (each `GET`/`PUT` with the full collection — replace, not patch; a gig's three
package tiers are edited as a unit and a partial update produces a half-priced tier).

Service publish gate: ≥1 active package with price and delivery days; ≥1 gallery item; category;
and `charges_enabled` for a paid gig.

### Orders and earnings (read-only from members)

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/sellers/{ref}/sales` | Sold `order_items`, filterable by date. |
| `GET` | `/sellers/{ref}/ledger` | Ledger entries + running balance (step 16). |
| `GET` | `/sellers/{ref}/payouts` | Payout history. |
| `GET` | `/sellers/{ref}/service-orders` | Open freelance work (step 15). |
| `POST` | `/sellers/{ref}/service-orders/{soRef}/deliver` | Submit a delivery from the members side. |

### Licenses

`GET /sellers/{ref}/licenses` (system default + the member's own), `POST` create, `PUT` update
(bumps `version`), `DELETE` (refused if any asset or entitlement references it).

---

## 04.5 Endpoints — market → members (callbacks)

The brief: *"the seller's info is updated in the members project."* Members stores nothing (D1), so
"updated" cannot mean "row written". It means **the seller is told**: a notification, a badge, an
unread count.

Market calls members at `MEMBERS_URL/api/internal/market/v1/*`, signed with the same key, sender
`MARKET`.

| Event | Path | Payload |
|---|---|---|
| Sale | `POST /events/sale` | seller ref, order item ref, title, gross, fee, net, buyer ref, timestamp |
| Refund | `POST /events/refund` | as above, negative |
| Payout state | `POST /events/payout` | payout ref, amount, status, failure reason |
| Service order state | `POST /events/service-order` | so ref, old → new status, due date |
| New message | `POST /events/message` | so ref, sender, preview |
| Dispute | `POST /events/dispute` | disputable ref, reason, status |
| Listing review | `POST /events/listing-status` | asset/service ref, new status, rejection reason |

Members side: `app/Http/Controllers/Market/MarketEventController.php` + a route group in
`members/routes/web.php` (or a new `routes/internal.php`) under the verify middleware. Each handler
writes a members notification and nothing else. Handlers are **idempotent by `event_id`** — a
retried webhook must not produce a second notification.

Delivery: queued job, exponential backoff (1s, 5s, 30s, 5m, 30m), 6 attempts, then dead-letter with
an admin-visible record. **A failed callback must never fail the transaction that caused it.** A
seller not seeing a toast is an annoyance; a sale rolled back because a notification timed out is a
lost sale.

---

## 04.6 Members side — replacing the mocks

`members/app/Http/Controllers/AssetStoreController.php` and `FreelanceController.php` are rewritten
as proxies. Every `session("assetstore_*")` and `session("freelance_*")` read and write is deleted.

Method-by-method:

| Old | New |
|---|---|
| `getDashboard()` → `session(...)` | `MarketClient::get("/sellers/{$ref}/dashboard")` |
| `getAssets()` → `session(...)` | `MarketClient::get("/sellers/{$ref}/assets")` |
| `createAsset()` → `storeAs()` + session push | `MarketClient::post("/sellers/{$ref}/assets")`, then the step-05 upload |
| `updateAsset()` / `deleteAsset()` | proxied `PUT` / `DELETE` |
| `getPaymentMethods()` / `addPaymentMethod()` / `deletePaymentMethod()` | **deleted** — payout details are Stripe Connect's (step 10). Storing a bank account number in a PHP session, as the current code does, is exactly the thing Connect exists to avoid. |
| `requestWithdrawal()` | proxied to market's payout request (step 16) |
| `downloadAsset()` | proxied to a signed market download URL |
| `getSalesHistory()` / `getWithdrawalHistory()` | proxied |

`$userId = Auth::id()` is replaced everywhere by the Checkpoint member reference resolved
server-side from the session — never taken from the request body, and (per **step 01 D7**) never by
querying `brain_memberships`, even though members' connection could reach it. The session already
carries the reference; where more membership data is needed, members calls `BRAIN_API_URL`. A members endpoint that accepts a
`member_reference_num` from the browser and forwards it is a horizontal-privilege bug regardless of
how well the market call is signed.

Routes at `members/routes/web.php:75–99` keep their paths (the TS controllers reference them) but
their handlers now proxy. Tag each changed line.

---

## 04.7 Error handling

Single envelope, both directions:

```json
{ "success": false, "message": "Human sentence.", "errors": { "field": ["…"] }, "code": "MARKET_E_PUBLISH_GATE" }
```

| Situation | Status |
|---|---|
| Signature invalid / stale / missing | `401` |
| Member exists but does not own the resource | `403` |
| Not found | `404` |
| Publish gate / validation | `422` with the full `errors` map |
| Delete blocked by entitlements | `409` |
| Idempotency key reused with a *different* body | `409` |
| Market unreachable from members | `503` + a members-side message naming market, not a generic "something went wrong" |

`MarketClient` timeouts: 30s normal, 900s uploads (both from config). On timeout, **do not
auto-retry a non-GET** — retry with the same idempotency key only, which the ledger makes safe.

---

## 04.8 Documentation deliverable

`apps/afsserver/plans/market/complete/04_internal_cross_api_complete.md` must include the final
endpoint table with example request and response bodies for at least: create asset, finalize
version, publish, sale callback. Those four are what steps 05–07 and 16 code against; if they are
only in code, the next step re-guesses them.

---

## 04.9 Verification

- [ ] Correctly signed request → `200`. Bit-flipped signature → `401`. Timestamp 10 min old → `401`.
- [ ] Empty `MARKET_INTERNAL_API_KEY` on the market side → every internal call `401` (fails closed).
- [ ] Same idempotency key twice → one row created, identical response body both times.
- [ ] Same idempotency key with a different body → `409`.
- [ ] Publishing an asset with no version, no license, and no tag returns **three** errors in one response.
- [ ] `grep -rn "session(\"assetstore\|session(\"freelance" apps/members` → zero hits.
- [ ] Stop market, load the members asset store: a clear "Market is unavailable" state, no white screen, no console exception.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/04_internal_cross_api_complete.md` written.
