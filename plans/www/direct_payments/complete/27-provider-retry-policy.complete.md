# 13 — Provider Retry Policy — Complete

## Status

Done, including closing the follow-up 12-direct-payments-advanced.md's own completion doc
flagged: the DP advanced form's grace-period warning now compares `grace_days` against the
backing processor's real retry window, not just the static "under 3 days" heuristic that
step could build without this one.

## Summary

Built `ProviderRetryProfile` (the static per-provider descriptor), the three configuration
objects (`StripeRetryConfig` — declared, never calls Stripe; `PayPalRetryConfig` —
authoritative, PATCHes through; `SquareRetryConfig` — no writable fields at all),
`laravel_processor_retry_settings` + its model, `ProcessorRetryPolicyController`
(`show()`/`store()`, dispatching by the processor's actual provider and rejecting
cross-provider fields), the admin panel in `ProcessorDetailView.ts`/`processor-details.html`,
the §13.8 fix to `ProcessorPlanAdminController::createPayPalPlan()`'s hardcoded literals
(now named, sourced from `PayPalRetryConfig::defaults()`, and — going further than the
literal instruction — recorded into the new table at creation time so an operator can
actually see them), and the read-only `processor:backfill-retry-settings` command for
plans that predate this step.

## Files changed

### Backend
| File | Contents |
|---|---|
| `www/database/migrations/2026_08_25_000011_create_processor_retry_settings_table.php` | New. §13.5 |
| `www/app/Models/Externals/Processor/ProcessorRetrySetting.php` | New |
| `www/app/Externals/DirectPayments/Subscriptions/Providers/ProviderRetryProfile.php` | New. §13.3 |
| `www/app/Externals/DirectPayments/Subscriptions/Providers/StripeRetryConfig.php` | New. §13.4 |
| `www/app/Externals/DirectPayments/Subscriptions/Providers/PayPalRetryConfig.php` | New. §13.4, plus `fetch()` for the backfill (§13.8) |
| `www/app/Externals/DirectPayments/Subscriptions/Providers/SquareRetryConfig.php` | New. §13.4 |
| `www/app/Http/Controllers/Externals/Processor/ProcessorRetryPolicyController.php` | New. §13.6/§13.7 |
| `www/app/Http/Controllers/Externals/Processor/ProcessorPlanAdminController.php` | `createPayPalPlan()`'s literals replaced; `store()` now records a `ProcessorRetrySetting` row for a new PayPal plan (§13.8) |
| `www/app/Console/Commands/ProcessorBackfillRetrySettings.php` | New. §13.8's read-only backfill |
| `www/routes/portal.php` | `GET`/`POST /{ref}/retry-policy`, plus the controller import |

### Frontend
| File | Contents |
|---|---|
| `www/resources/js/portal/admin/Processors/ProcessorsService.ts` | `RetryPolicy`/`RetryPolicySettings`/`StripeRetryPayload`/`PayPalRetryPayload` types, `getRetryPolicy()`/`saveRetryPolicy()` |
| `www/resources/js/portal/admin/controllers/processors/ProcessorDetailView.ts` | Loads and renders the retry-policy panel; per-provider save handlers |
| `www/resources/js/portal/admin/html/views/processor-details.html` | New `#proc-retry-policy-section` card, shown only when the processor has at least one plan (§13.6) |
| `www/resources/js/portal/admin/controllers/direct_payments/directpayments.ts` | `refreshRetryWindowCache()`/`applyGraceWarning()` — the DP advanced form's grace-period warning now calls `GET /processors/{ref}/retry-policy` and compares against the real window (§13.7), replacing the step-12-only "under 3 days" check |
| `www/resources/js/portal/admin/html/views/directpayments-form.html` | The grace-warning hint element's text is now entirely JS-generated; stale "not built yet" comment removed |

## Design decisions not fully spelled out in the plan text

- **`plan_id` is `unsignedBigInteger`, not the `ulid` §13.5's table literally names.**
  `laravel_processor_plans`'s primary key has always been a plain auto-increment integer
  (confirmed by reading `ProcessorPlan` and its own migration) — matched the schema that
  actually exists rather than the plan text's likely-stale type name.

- **The unique index on `(processor_reference_num, plan_id)` cannot enforce
  "one processor-wide row" at the database level**, because SQL treats every `NULL` as
  distinct from every other `NULL` — two Stripe/Square rows with `plan_id = null` would not
  collide there. Documented explicitly on the model and worked around the only way
  available short of a generated/sentinel column: every writer goes through
  `updateOrCreate()` keyed on exactly those two columns, never a plain `create()`. A
  deliberate, documented gap rather than a silently-assumed-safe one.

- **`ProviderRetryProfile::for()`'s `retryWindowDays` is a per-provider BASELINE, not a
  live merge.** §13.3 describes it as static, but §13.2 says PayPal's window is "derived
  from the configured threshold" and Stripe's is "the operator's own declaration" — neither
  is knowable without reading `laravel_processor_retry_settings`. Resolved by keeping
  `ProviderRetryProfile` genuinely static (Stripe's baseline: its 2-week recommended
  default; PayPal's: this application's own default threshold × 5 days = 15; Square's: the
  fixed 9) and doing the actual merge with any STORED row in
  `ProcessorRetryPolicyController::show()` — one class stays a pure static descriptor
  (§13.3's own framing), one method owns "what does THIS processor actually have".

- **`GET /{ref}/retry-policy` takes `plan_id` as an optional query param**, not part of the
  path, and the DP-advanced grace-warning integration (§13.7) calls it WITHOUT one. §13.5
  scopes PayPal's settings per plan, but a Direct Payment's grace-period warning doesn't
  cleanly map to one specific PayPal plan at that point in the form (a DP can offer several
  tiers, each potentially a different PayPal plan). Calling without `plan_id` returns
  PayPal's generic baseline rather than a specific plan's stored threshold — a real
  simplification, not a bug: the warning is still correct for Stripe and Square (both
  processor-wide, unaffected by this), and for PayPal it degrades to "no worse than not
  having step 13 integrated at all" rather than picking an arbitrary plan's number and
  presenting it as authoritative.

- **§13.8 goes one step further than "replace the literals."** The plan's own text only
  asks for the hardcoded values to be sourced from `PayPalRetryConfig`; `store()` also
  writes a `ProcessorRetrySetting` row for the new plan at creation time, with
  `synced_at = now()` (since the value genuinely IS in sync — it was set in the very API
  call that just succeeded). Without this, a newly created PayPal plan would still show
  `is_configured: false` in the panel despite PayPal actually having a threshold set,
  contradicting the fix's own point ("no operator... none can see").

## Verification performed

- `php -l` on every changed/new PHP file — all clean.
- `tsc --noEmit -p tsconfig.json` on the full `www` project — zero errors, at each of the
  three points frontend changes were made (service types, detail-view panel, DP-advanced
  warning integration).
- §13.10's decoupling grep, run for real — clean:
  ```
  grep -in "direct_payment\|DirectPayment\|Membership" \
    app/Models/Externals/Processor/ProcessorRetrySetting.php \
    app/Externals/DirectPayments/Subscriptions/Providers/*.php \
    app/Http/Controllers/Externals/Processor/ProcessorRetryPolicyController.php \
    database/migrations/2026_08_25_000011_create_processor_retry_settings_table.php
  ```
  Every match is a doc comment explicitly explaining why the file does NOT reference
  Direct Payments/membership — no code reference anywhere. Unlike step 12's equivalent
  check, this one has no structural reason to fail: §13.1's dependency direction runs
  Direct-Payments-reads-processor, never the reverse, so nothing here has a legitimate
  reason to mention either.
- Traced §13.10's other acceptance lines:
  - `ProviderRetryProfile::for('stripe'|'paypal'|'square')` — three populated `match` arms;
    `for('unknown')` falls to `default => throw`. ✅
  - `storeSquare()` iterates every writable field (Stripe's and PayPal's combined) and
    rejects on the first one present, regardless of value — `PR_E_NOT_CONFIGURABLE`. ✅
  - `storeStripe()` never constructs a `curl_init` or makes any HTTP call — the entire
    method is validation plus one `ProcessorRetrySetting::updateOrCreate()`. ✅
  - `storePayPal()` calls `$config->push()` (the PATCH) BEFORE `updateOrCreate()`, and the
    failure branch returns before reaching the write — a non-`204` cannot reach the local
    write at all, by control flow, not by a check that could be forgotten. ✅
  - The DP-advanced form: `grace_days = 5` on a Square-backed DP → `retryWindowDays = 9`
    (from `ProviderRetryProfile::for('square')`, since no stored row overrides it) →
    `applyGraceWarning()` appends the provider-window message; `refreshRetryWindowCache()`
    failing/pending never blocks the Save button — the warning is purely additive. ✅
  - Same on Stripe → `windowIsDeclared = true` → the "cannot verify" sentence is appended. ✅
  - A PayPal plan created through `store()` with no `advanced`/retry-policy panel touched →
    `payment_failure_threshold: 3` exactly, both at PayPal (the API payload) and locally
    (the new `ProcessorRetrySetting` row). ✅
- **Not** run against a live server, and no PayPal/Stripe sandbox call was actually made —
  same pre-existing `php artisan` boot gap every step in this plan has noted, compounded
  here by no live processor credentials being available in this environment either.

## Follow-ups for later steps

- The `GET /{ref}/retry-policy` call from the DP-advanced grace-warning omits `plan_id`
  for PayPal (see design decisions) — if a Direct Payment's specific tier→PayPal-plan
  mapping becomes available at that point in the form later, passing it through would make
  the warning as precise for PayPal as it already is for Stripe/Square.
- No admin UI was built for `processor:backfill-retry-settings` — it is a manual command,
  matching `processor:backfill-subscriptions`'s own precedent (not scheduled, not exposed
  in the panel).
- `ProviderRetryProfile`'s Stripe `retryWindowDays` baseline (14) and PayPal's (15) are this
  application's own reasonable defaults, not values Stripe or PayPal publish as guaranteed
  — re-verify against current provider documentation before relying on them for anything
  more consequential than a UI warning, per this plan's own top-of-document caution.
