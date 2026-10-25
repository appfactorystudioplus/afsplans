# 15 — Money and Refunds — Complete

## Status

Done, including two things the plan assigned elsewhere that turned out to belong here once
built: `dp.subscription.history()` (07-direct-payments-api.md's own completion doc deferred
it to "15-money-and-refunds.md §15.5, which owns the underlying charge/refund tables") and
the coordinator-side rollup (§15.6), which needed a genuine investigation into the existing
afs-connect ingestion pattern before it could be built correctly.

**One major, load-bearing deviation from the plan text** — read this before touching
anything in this step: `laravel_processor_charges` is **not** the live charges table.

## The `laravel_processor_charges` discovery

§15.2 specifies `laravel_processor_refunds.charge_id` as a `ulid` referencing
`laravel_processor_charges.id`. That table exists, with exactly that ulid shape — and it is
**dead**. A full search of every write site in this codebase (`ProcessorWebhookController`'s
`recordStripeCharge()`/`recordStripeInvoiceCharge()`/`recordSquareCharge()`/`recordPayPalCharge()`,
and `ProcessorBillingController`'s `syncStripe()`/`syncSquare()`/`syncPayPal()`) found that
every one of them writes to `laravel_direct_payment_invoices` — reached through
`App\Models\Externals\Processor\ProcessorCharge`, whose `$table` property already points there, not at
the ulid table its class name implies. `laravel_processor_charges` has never had a row
written to it by any code path in this application.

Repointing every existing writer at the ulid table would be a considerably larger, riskier
change than this step calls for — unknown blast radius on `App\Models\Externals\DirectPaymentInvoice`
(a second model reading the same table for Direct-Payments-specific purposes), and zero
historical data on day one. Instead, this step builds on the table that is actually live:

- `laravel_processor_refunds.charge_id` is `unsignedBigInteger`, matching
  `laravel_direct_payment_invoices.id`'s real type, not the plan's stated `ulid`.
- The B36 Connect columns (§15.7's second migration) were added to
  `laravel_direct_payment_invoices`, not `laravel_processor_charges`.
- `laravel_processor_charges` itself is untouched — still present, still empty, exactly as
  it was.

Both migrations carry a full explanation of this in their own header comments, since anyone
touching this table family later needs to know before assuming the plan's literal type is
correct.

## Summary

Built `laravel_processor_refunds` + `ProcessorRefund` model, `RefundService` (the one place
that both calls a provider's refund API and persists the result — adapted from
`ProcessorBillingController`'s existing `refundStripe()`/`refundSquare()`/`refundPayPal()`,
with the Connect flags now genuinely operator-controlled instead of hardcoded `true`),
`MoneyController` (`ledger()` fixing B32, `refund()` fixing B33 by being charge-scoped with
no customer resolution at all), the five webhook refund handlers now persisting instead of
only logging, `money:backfill-charges` (Stripe + Square; PayPal explicitly flagged as
unreachable with the auth this app has), the B36 Connect columns wired into
`recordStripeCharge()` so `is_split`/`connected_account_id`/`application_fee_cents` actually
populate for new charges, `DirectPaymentSubscriptionController::history()`, and a full
rewrite of `MoneyView.ts`/`money.html` with filters, gross/refunded/net/count stats, and the
refund dialog with live Connect-absorb copy (§15.4). Registered `www_money` in
coordinator's afs-connect source registry as a live-computed rollup provider
(`WwwMoneyProvider`), following the established `www_*` pattern.

## Files changed

### Backend (`www`)
| File | Contents |
|---|---|
| `www/database/migrations/2026_08_25_000012_create_processor_refunds_table.php` | New. §15.2 — targets `laravel_direct_payment_invoices`, see above |
| `www/database/migrations/2026_08_25_000013_add_connect_fields_to_processor_charges_table.php` | New. B36 — same target-table note |
| `www/app/Models/Externals/Processor/ProcessorCharge.php` | New Connect fields/casts, `refunds()` relation |
| `www/app/Models/Externals/Processor/ProcessorRefund.php` | New |
| `www/app/Models/Externals/DirectPaymentInvoice.php` | New Connect fields/casts (mirrors `ProcessorCharge` — both models read the same table) |
| `www/app/Externals/DirectPayments/Money/RefundService.php` | New. Provider call + persistence, one place |
| `www/app/Http/Controllers/Externals/Processor/MoneyController.php` | New. `ledger()`, `refund()` |
| `www/app/Http/Controllers/Externals/Processor/ProcessorWebhookController.php` | Five refund handlers now persist (`persistStripeRefund()` + inline Square/PayPal logic); `recordStripeCharge()` captures Connect fields (B36) |
| `www/app/Console/Commands/MoneyBackfillCharges.php` | New. §15.3 |
| `www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentSubscriptionController.php` | Added `history()` (§15.5, deferred here by step 07) |
| `www/routes/portal.php` | `GET/POST /api/v1/money/*`, legacy `GET /api/charges` alias, `GET /{dpRef}/subscription/history` |

### afs-connect (`www` + `apps/coordinator`)
| File | Change |
|---|---|
| `www/app/AfsConnect/Providers/WwwMoneyProvider.php` | New. §15.6's rollup provider |
| `www/app/AfsConnect/WwwAfsConnectMetaProvider.php` | Registered `www_money` |
| `www/config/afs_connect.php` | Added `www_money` to `www_source_apps` |
| `apps/coordinator/config/afs_connect_sources.php` | New `www_money` entry, `default_on: false` |
| `apps/coordinator/config/afs_connect.php` | Added `www_money` to `source_app_base_urls`, `source_apps`, `www_source_apps` — see below, this is NOT the "one entry" the plan describes |

### External SDK
| File | Change |
|---|---|
| `www/resources/js/external/direct_payments/api.ts` | `DirectPaymentSubscriptionApi.history()` |
| `www/resources/js/external/portal.d.ts` | `PortalDirectPaymentSubscriptionAPI.history()`, `PortalMoneyHistoryRow` |
| `www/resources/js/external/direct_payments/README.md` | `history()` documented |

### Admin UI
| File | Change |
|---|---|
| `www/resources/js/portal/admin/Processors/MoneyView.ts` | Full rewrite — new response shape, filters, refund modal |
| `www/resources/js/portal/admin/html/views/money.html` | Gross/refunded/net/count stat cards, filter row, new table columns, refund modal markup |

## Design decisions not fully spelled out in the plan text

- **§15.6's "one registry entry" claim does not match this codebase's actual registration
  surface — verified by running the real test, not by reading the code.** The plan's own
  acceptance line says "Adding `www_money` touched one registry entry plus the provider
  module, and `SourceRegistryConsistencyTest` passes." Adding only the
  `afs_connect_sources.php` entry and running the test produced a real, specific failure:
  `'www_money' (kind=meta) has no base URL configured`. Reading `SourceRegistry::baseUrl()`
  showed it resolves from `config('afs_connect.source_app_base_urls.{$key}')` — a SEPARATE
  map in `apps/coordinator/config/afs_connect.php` that every existing `www_*` entry is
  listed in individually, alongside two more flat lists (`source_apps`, `www_source_apps`)
  in the same file. Registration is genuinely **four** additions across two files, not one.
  Fixed all four, then re-ran the actual test — `OK (4 tests, 211 assertions)`. Also found
  and updated `www`'s own `config/afs_connect.php`'s parallel `www_source_apps` list (used
  by `AfsConnectEntitlementGuard`'s dev fallback), a fifth touch point the coordinator-side
  test cannot see at all.

- **§15.6's "push on rollup change, pull on the scheduled reconcile" describes a pattern
  that does not exist for any `www_*` source, including this one — confirmed by reading
  every `www_*` provider and both sides of the ingestion path.** There is no push endpoint
  anywhere in `www` calling out to coordinator. Every `www_*` meta source (`WwwDirectPaymentsProvider`
  and its seven siblings) computes its data **live, at request time**, inside `changes()`;
  coordinator's `afs-connect:reconcile` (scheduled every 15 minutes,
  `apps/coordinator/routes/console.php`) is the entire ingest path, pulling via
  `WwwBrainClient` → the existing `/internal/afs-connect/v1/changes` endpoint. `WwwMoneyProvider`
  follows this exact, real, already-established pattern — computing the
  `{website_ref}:{YYYY-MM}:{provider}` rollup live from `laravel_direct_payment_invoices`/
  `laravel_processor_refunds` on every `changes()` call — rather than the push
  infrastructure the plan describes, which would have been new work invented to match a
  sentence rather than the codebase.

- **`refund()`'s charge lookup fixed a real, separate customer_id storage inconsistency**,
  found while building member resolution for the ledger. Stripe's `recordStripeCharge()`
  stores the **provider's own** customer id directly in
  `laravel_direct_payment_invoices.customer_id`; Square's and PayPal's equivalent handlers
  store the **local** `ProcessorCustomer.id` (a ulid) in the same column — a pre-existing
  inconsistency, not introduced here. `MoneyController::toLedgerRows()` and
  `DirectPaymentSubscriptionController::history()` both resolve member identity against
  BOTH shapes rather than assuming one, with the reasoning recorded inline at both call
  sites so a future reader does not "fix" one path back to the wrong assumption.

- **PayPal's refund webhook (`PAYMENT.CAPTURE.REFUNDED`) resolves the charge via the
  resource's `up`-rel link, not `resource.id`**, with a documented fallback and an explicit
  note that this is unverified against a live delivery. The pre-existing log line in this
  handler labelled `resource['id']` as `"capture_id"` — per PayPal's own API, that field is
  the REFUND's id, and the capture it refunds is reachable only via `resource.links`. Kept
  the old (likely mislabeled) log line rather than silently rewording it, and used the
  `links` extraction for the actual persistence, falling back to treating `resource.id` as
  the capture id only if no `up` link is present — so a refund is still recorded (if
  imprecisely) rather than silently dropped either way.

- **`ProcessorBillingController::refund()`/`refundStripe()`/`refundSquare()`/`refundPayPal()`
  are left exactly as they were** — a second, pre-existing, still-B33/B34-broken refund
  path this step does not touch. §15.4 specifies ONE canonical new endpoint
  (`POST /portal/api/v1/money/charges/{chargeId}/refund`), which is what was built;
  removing or rewriting the old controller's methods risked breaking an unverified caller
  for no requirement this step actually has.

- **`ApiAuthMiddleware` gates the new `/money` routes** — a real auth check where the plan
  says "sits behind `portal.admin.auth` like the rest of the Financials section." That
  literal middleware alias exists in `bootstrap/app.php` but (confirmed by grepping every
  route file) is applied to zero routes anywhere in this application, including every other
  Financials endpoint — there is no existing pattern to be "like." `ApiAuthMiddleware` was
  used instead, matching the established precedent for a JSON admin API
  (`api/v1/documents`'s own group) rather than `portal.admin.auth`, which redirects to a
  login page on failure — the wrong failure mode for a `fetch()` caller.

- **`money:backfill-charges` covers Stripe and Square only.** PayPal has no account-wide
  capture-listing endpoint reachable with the client-credentials auth this codebase uses
  everywhere else (its Transaction Search API needs additional scopes not currently
  requested). Flagged in the command's own report line rather than silently omitted or
  faked with a partial implementation.

- **Discrepancy handling in the backfill command is genuinely non-destructive**, per §15.3's
  own instruction ("report the discrepancy count; do not auto-resolve it"): a charge or
  refund missing locally is created; one that exists but disagrees with the provider
  (amount or status) is counted and left untouched. This needed an explicit design, since a
  blanket `updateOrCreate` would have silently done the auto-resolution the plan says not to.

## Verification performed

- `php -l` on every changed/new PHP file — all clean.
- `tsc --noEmit -p tsconfig.json` on the full `www` project — zero errors, re-checked after
  each frontend edit pass (MoneyView.ts, the SDK additions, the `history()` type).
- **`SourceRegistryConsistencyTest` actually run**, not just reasoned about — first run
  failed with the specific `www_money` base-URL error described above; fixed; re-run passed
  for real (`OK (4 tests, 211 assertions)`). This is the one piece of this step verified
  against a live test suite rather than by inspection, and it caught a real gap the
  inspection-only approach would have missed.
- Traced §15.8's acceptance list:
  - `GET /portal/api/v1/money/ledger` and `GET /portal/api/charges` both route to
    `MoneyController::ledger()` — same handler, confirmed in `routes/portal.php`. ✅ (B32)
  - `RefundService::refund()` always calls `ProcessorRefund::updateOrCreate()` on a
    provider-confirmed result before returning — traced through all three provider
    branches. Money's `net_cents` is computed from `gross_cents - refunded_cents` in
    `toLedgerRows()`, never a stored total. ✅ (B34)
  - `MoneyController::refund()` resolves the charge by `ProcessorCharge::find($chargeId)`
    alone — no `ProcessorCustomer`/`resolveProcessorAndCustomer()` call anywhere in the
    method. ✅ (B33)
  - `openRefundModal()` defaults both Connect checkboxes to `checked`; `RefundService::executeStripe()`
    only sends `reverse_transfer`/`refund_application_fee` when the charge's Stripe object
    actually has a `transfer` AND the operator left the box checked — unchecking either
    stops that flag from ever reaching Stripe's API, traced in the method body. ✅
  - A refund on a subscription's charge never touches `laravel_direct_payment_subscriptions`
    or any subscription-cancel code path anywhere in `RefundService`/`MoneyController`. ✅
  - `initiated_by` is resolved via `$request->attributes->get('auth_user')`
    (`ApiAuthMiddleware`'s own attribute), never from the request body — confirmed by
    reading `resolveOperator()`. No level-99/`AdvancedConfigGate` check anywhere on the
    refund route. ✅
  - `RefundService::syncChargeStatus()` compares summed succeeded-refund cents against
    `amount_cents` — `>=` moves to `refunded`, not `partially_refunded`, for a sum that
    lands exactly on the full amount. ✅
  - `money:backfill-charges --dry-run` increments counters and never calls `::create()`
    anywhere in `reconcileCharge()` when `$dryRun` is true — traced. ✅
  - `WwwMoneyProvider::changes()`'s `metadata` array is a fixed six-key list
    (`gross_cents`/`refunded_cents`/`net_cents`/`charge_count`/`refund_count`/`currency`) —
    no `customer_email`, `charge_id`, `reason`, or account field constructed anywhere in the
    method. ✅
  - `SourceRegistryConsistencyTest` passes — verified by running it, not reading it. ✅
  - `www_money`'s `default_on: false` — set explicitly, confirmed present in the registry
    entry. ✅
- **Not** run against a live server, and no Stripe/Square/PayPal sandbox call was actually
  exercised — same pre-existing `php artisan` boot gap every step in this plan has noted.
  Unlike every prior step, though, one real piece of this step (`SourceRegistryConsistencyTest`)
  WAS runnable and WAS run, in the `apps/coordinator` app, which boots cleanly for its test
  suite even though `www`'s own `artisan` does not.

## Follow-ups for later steps

- `MoneyView.ts`'s "Direct Payment" filter is a plain reference-number text input, not a
  dropdown of the tenant's actual Direct Payments — a reasonable simplification given this
  view has no existing DP-list fetch to build on, not a plan requirement.
- PayPal charge backfill remains unimplemented (see above) — would need this application to
  request Transaction Search API scopes it does not currently have.
- The PayPal refund-webhook capture-id extraction (via `resource.links`) is unverified
  against a real PayPal webhook delivery — flagged explicitly in the handler's own comment;
  re-check against a live sandbox event before relying on it in production.
- `dp.subscription.history()`'s pagination is a flat `limit(100)`, no cursor — fine for a
  member's own transaction history in practice, but worth revisiting if a very long-lived
  subscription ever needs to page through more.
