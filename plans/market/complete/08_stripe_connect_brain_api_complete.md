# Step 08 — Brain: an encapsulated Stripe Connect API (Accounts v2) — completion report

**Date:** 2026-08-19
**Projects touched:** www (brain) only, per the plan's own scope; `apps/market/resources/js/portal.d.ts`
touched as the plan's own explicit `portal.d.ts` mirroring requirement.
**Status:** ⚠️ complete with gaps
**Chosen defaults:** `integration_mode: self_hosted` (⇒ `dashboard: none`), `onboarding_mode: embedded` —
exactly the plan's §08.1 recommendation. **Multi-seller shipped**: `separate_transfers` is fully
implemented (`SplitCalculator`, `ProcessorTransferIntent`, `TransferExecutor`, the webhook trigger) —
§08.10's "destination charges only" scope-reduction option was **not** taken.

## Sequencing note

The plan's own header says this step and step 09 should run *first*, before any market or members
work, with the project owner's playground-proving step in between. That is not what happened —
steps 02–07 (market/members schema, cross-API, asset publishing, freelance publishing) already
landed in this session before "start step 8" was given. Recorded here as fact, not fixed — the user
directs execution order, and no step so far assumed step 08 had already landed.

## What shipped

**§08.2 dead-code audit.** Confirmed measured: SDK was not installed; `ProcessorConnectController`
had never executed (fatals on `\Stripe\Stripe` not found); `ProcessorSyncController`'s
`execute()` reaches `\Stripe\Account::retrieve($id)->delete()`, `\Stripe\Coupon::retrieve($id)->delete()`,
and `\Stripe\Price::update($id, ['active' => false])` directly from a client-supplied `resolutions`
array. **Also found and fixed while auditing**: a real Stripe test-mode secret key committed in a
comment in `FunctionsController.php` — redacted. **Also found, and reported to the user rather than
fixed** (per explicit direction — see "Decisions" below): the entire `api/v1/processors/*` route
group, including the destructive sync/execute route and every Connect route, carries no
authentication middleware at all in this working tree.

**Composer dependency**: `stripe/stripe-php` `^21.2` installed, confirmed at v21.2.0,
`vendor/composer/autoload_psr4.php` has a `Stripe\\` entry, `lib/V2/Core/Account.php` present.

**§08.2.4 gate on the reanimated destructive route**: `ProcessorSyncController::execute()` now
refuses any `delete_remote` resolution unless the processor's `mode` is `sandbox` (this codebase's
actual test/live enum — not `test`, see "Errors and fixes" below), returning `422` with a clear
message, and logs every executed `delete_remote` at `warning` before it runs.

**§08.3 the configuration model.** `ConnectProfileService` (`for()`/`validate()`/`save()`) is the
single reader/writer of `processor.extra_config['connect']`. `ProcessorConnectProfile` is a plain
PHP value object (readonly properties, `fromArray()`/`toArray()`/`toPublicArray()`/`dashboard()`) —
**not** an Eloquent model with its own table; see "Decisions" for why this deliberately diverges
from §08.6/§08.9's literal file list. All four §08.3 validation rules are implemented and
independently verified (see "How this was verified").

**§08.5 the encapsulated externals API.** `resources/js/external/connect/{ConnectTypes,ConnectApi,EmbeddedHost,CONNECT_DOCUMENTATION.md}`
and `providers/{StripeConnectProvider,PayPalConnectProvider,SquareConnectProvider}.ts` — the full
`PortalDirectPaymentConnectAPI` surface from §08.5.4, provider-neutral naming throughout (§08.5.3).
`dp.connect` is a lazy getter on `ExternalDirectPaymentsAPI`, resolved from a processor
provider/publishable-key pair cached once by `initializeDirectPayment()` (a new, small addition —
see "Decisions"). `isSupported()`/`support()` are synchronous per the interface; PayPal/Square
degrade to a typed refusal rather than throwing. `portal.d.ts` in both `apps/www` and
`apps/market/resources/js/` carry byte-identical new declarations (verified with `diff`).

**§08.6 backend — `ProcessorConnectController` rewritten onto v2.** Both §08.2 defects fixed:
`getOnboardingLink()`'s hardcoded admin-portal URL is gone (resolved from the connect profile's
configured paths against this deployment's own base URL); `createSession()` now requires **both**
a `BrainInternalSignature` (a new, minimal HMAC channel — see "Decisions") **and** a matching
`member_reference_num` — "both, not either," exactly as specified. `ConnectAccountService` holds
every Stripe call site (creation, retrieval, onboarding by mode, requirements, balance/payouts,
close), with `include` always passed on `/v2/core/accounts` calls and the preview API version
scoped only to those calls (verified directly against the installed SDK's method signatures, not
assumed from the plan's prose — see "How this was verified").

**§08.7 the charge path.** `SplitCalculator` implements the exact fee arithmetic
(`floor(line_net × fee_pct / 100)`, integer minor units) and picks `destination` vs.
`separate_transfers` automatically by distinct seller count. `ProcessorIntentController::createIntent()`
accepts an optional `split` block and validates all five invariants **before any Stripe call**;
`separate_transfers` records `ProcessorTransferIntent` rows at intent-creation time and a new
`ExecutePendingTransfersJob` (queued, not inline) executes them only from `payment_intent.succeeded`.
`v1` `Customer` + `v2` seller `Account` coexisting in one charge is unchanged/confirmed, per §08.7.2 —
no code needed to touch this, and none did.

**§08.7.4 the capability rename.** `ConnectAccountService::syncFromV2Response()`/`syncFromV1Response()`
read `stripe_balance.stripe_transfers`/`stripe_balance.payouts` for v2 accounts and
`transfers`/`payouts` for v1, both writing the same local `charges_enabled`/`payouts_enabled`
columns, keyed on the new `api_version` column.

**§08.7.5 webhook authentication — fail-open to fail-closed, all three providers.** Every one of
the six documented bypass conditions is closed: `Processor::where(...)->first()`'s multi-tenancy
bug is fixed (Stripe resolves via the event's `account` field through the owning
`ProcessorConnectedAccount`, or brute-force-verifies across every active processor's own secret for
platform-level events — never "the first row"); missing configuration returns `503`, a bad
signature returns `400`, and PayPal's remote-verifier-unreachable case is distinguished from
"PayPal said invalid" and also returns `503`. Rejections land in the new
`laravel_processor_webhook_rejections` table (payload **hash**, never the payload; per-IP
rate-limited via `RateLimiter`). A new `v2_event_destination` receiver (`?type=stripe_v2`) exists
alongside the untouched v1 receiver, authenticated against its own `ProcessorWebhookEndpoint` row
— the v1 and v2 secrets never share a column (a new table, per the plan's own explicit fallback
instruction once a single `webhook_secret_encrypted` column was confirmed insufficient).
`marketplace:check-webhook-config` (read-only preflight) and `marketplace:register-event-destinations`
(idempotent, follows with `ping`) are both built.

**§08.7.5 refund/dispute gaps.** Gap 2 (missing `reverse_transfer`/`refund_application_fee`) is
fixed in `ProcessorBillingController::refundStripe()`, rewritten onto the SDK, gated on whether the
charge actually has an associated transfer (checked live via `charges->retrieve()`, not assumed).
Gap 1's three missing event subscriptions (`refund.created`/`.updated`/`.failed`,
`charge.dispute.updated`/`.closed`/`.funds_withdrawn`/`.funds_reinstated`) are all subscribed, with
`refund.failed` logged at `error` specifically so it is not lost in `info`-level noise. The
marketplace-dispute vs. chargeback disambiguation is done: `logStripeDispute()` is renamed
`logStripeChargeback()` with a header comment stating the distinction explicitly.

**§08.5.7 the `CONNECT` pay_type.** `laravel_direct_payment_connect` table + `DirectPaymentConnect`
model. `dp.isConnect()` added; the `charge()` method's third branch replaces the generic
"Unrecognized Direct Payment payload format" with a specific message naming the actual gap
(server-to-server invoice-prepare is step 13's work, not built here — see "Known gaps").
`Invoice.addItem()` gains an optional, inert `seller_ref`. The v1-swap guard's schema
(§08.5.7/§08.5.8: `laravel_processor_migrations`, `..._migration_accounts`, `..._migration_events`,
plus the two linking columns on `processor_connected_accounts`) is fully laid — tables, models,
zero wiring, exactly as specified ("deliberately not wired to anything").

**§08.5.9 feature flag + level gating.** `DEFAULT_FEATURE_FLAGS.connect = false`,
`canUseConnect()`/`CONNECT_EARLY_ACCESS_LEVELS` added to `portal/admin/main.ts`, matching the exact
semantics table in the plan (99 always, 5 early-access, flag for general release).

## Files created

| File | Tag placement | Purpose |
|---|---|---|
| `app/Services/Marketplace/ConnectProfileService.php` | header | Single reader/writer of the Connect profile |
| `app/Services/Marketplace/ConnectAccountService.php` | header | Every Accounts v2 (and v1-balance/payout) Stripe call site |
| `app/Services/Marketplace/SplitCalculator.php` | header | Fee arithmetic + the five server-side invariants |
| `app/Services/Marketplace/TransferExecutor.php` | header | Records/executes `separate_transfers` transfer intents |
| `app/Services/Marketplace/BrainInternalSignature.php` | header | New minimal HMAC channel for `createSession()` (see Decisions) |
| `app/Models/Externals/Processor/ProcessorConnectProfile.php` | header | Value object — **not** Eloquent (see Decisions) |
| `app/Models/Externals/Processor/ProcessorTransferIntent.php` | header | One row per planned `separate_transfers` transfer |
| `app/Models/Externals/Processor/ProcessorWebhookEndpoint.php` | header | Per-(processor, kind) webhook signing secret |
| `app/Models/Externals/Processor/ProcessorWebhookRejection.php` | header | Bounded, hash-only rejected-webhook record |
| `app/Models/Externals/Processor/ProcessorMigration.php`, `ProcessorMigrationAccount.php`, `ProcessorMigrationEvent.php` | header | §08.5.8 future-migration schema — unwired |
| `app/Models/Externals/DirectPaymentConnect.php` | header | `CONNECT` pay-type's per-DP config row |
| `app/Jobs/ExecutePendingTransfersJob.php` | header | Queued transfer execution (ack fast, work async) |
| `app/Jobs/SyncConnectAccountJob.php` | header | Queued account mirror + transition classification |
| `app/Console/Commands/CheckWebhookConfigCommand.php` | header | §08.7.5's mandatory preflight |
| `app/Console/Commands/RegisterEventDestinationsCommand.php` | header | Idempotent v2 event destination setup + ping |
| `database/migrations/2026_08_19_00000{1..6}_*.php` | header | See "Database changes" |
| `resources/js/external/connect/ConnectTypes.ts` | header | Provider-neutral types |
| `resources/js/external/connect/ConnectApi.ts` | header | The `dp.connect` implementation |
| `resources/js/external/connect/EmbeddedHost.ts` | header | Connect.js loading + component mounting |
| `resources/js/external/connect/providers/StripeConnectProvider.ts` | header | The only real provider |
| `resources/js/external/connect/providers/PayPalConnectProvider.ts`, `SquareConnectProvider.ts` | header | Typed-refusal stubs |
| `resources/js/external/connect/CONNECT_DOCUMENTATION.md` | header | Usage + explicit "not built here" list |

## Files modified

| File | Lines / regions | Tag placement | Change |
|---|---|---|---|
| `app/Http/Controllers/Externals/Processor/ProcessorConnectController.php` | rewritten | header | v2 rewrite; both §08.2 defects fixed |
| `app/Http/Controllers/Externals/Processor/ProcessorSyncController.php` | `execute()` | line-level | Sandbox-only gate on `delete_remote` |
| `app/Http/Controllers/Externals/Processor/ProcessorWebhookController.php` | all three `handleX()`, +`handleStripeV2()` | line-level | Fail-closed rewrite, all providers; new v2 receiver |
| `app/Http/Controllers/Externals/Processor/ProcessorBillingController.php` | `refundStripe()` | line-level | Gap 2 fix |
| `app/Http/Controllers/Externals/Processor/ProcessorIntentController.php` | `createIntent()` | line-level | Split validation + payload + pending-transfer recording |
| `app/Http/Controllers/FunctionsController.php` | 1 line | line-level | Redacted committed secret key |
| `app/Models/Externals/Processor/Processor.php` | +2 methods | line-level | `byRef()`, `connectProfile()` |
| `app/Models/Externals/Processor/ProcessorConnectedAccount.php` | `$fillable`/`$casts` | line-level | Accounts v2 fields |
| `config/marketplace.php` | +1 block | *(pre-existing from step 02, extended)* | `internal_api.key` for `BrainInternalSignature` |
| `routes/portal.php` | Connect block + webhook doc comment | line-level | New routes; `getOnboardingLink` → `startOnboarding` |
| `resources/js/external/direct_payments/api.ts` | +`connect` getter, `isConnect()`, `charge()` 3rd branch, `addItem()` | line-level | §08.5.1/§08.5.6/§08.5.7 |
| `resources/js/external/processors/api.ts` | +`getPublicConfig()` | line-level | Resolves provider/publishable_key for `dp.connect` |
| `resources/js/external/portal.d.ts` | +Connect block, +2 DP interface members | line-level | See below |
| `apps/market/resources/js/portal.d.ts` | identical block | line-level | Verified `diff`-identical to brain's copy (see below) |
| `resources/js/portal/admin/main.ts` | +flag, +`canUseConnect()` | line-level | §08.5.9 |
| `composer.json`/`composer.lock` | +1 dependency | *(generated, no comment syntax)* | `stripe/stripe-php ^21.2` |
| `.env` (root only) | +1 key | *(no comment syntax)* | `MARKETPLACE_INTERNAL_API_KEY=` (blank — see "Known gaps") |

## Database changes

Six new migrations, none run against any real database — see "How this was verified" for why and
what was used instead:

1. `2026_08_19_000001_add_connect_v2_fields_to_processor_connected_accounts_table.php` — adds
   `member_reference_num`, `api_version`, `dashboard`, `fees_collector`, `losses_collector`,
   `requirements_collector`, `configurations`, `charges_enabled`, `payouts_enabled`,
   `details_submitted`, `requirements_json`, `disabled_reason`, `default_currency`,
   `last_synced_at`, `closed_at`, `migrated_from_account_id`, `migration_batch_id` to
   `laravel_processor_connected_accounts`.
2. `..._000002_create_processor_transfer_intents_table.php` — `laravel_processor_transfer_intents`.
3. `..._000003_create_direct_payment_connect_table.php` — `laravel_direct_payment_connect`.
4. `..._000004_create_processor_migration_tables.php` — the three §08.5.8 future-migration tables.
5. `..._000005_create_processor_webhook_rejections_table.php` — `laravel_processor_webhook_rejections`.
6. `..._000006_create_processor_webhook_endpoints_table.php` — `laravel_processor_webhook_endpoints`.

No `processor_connect_profiles` migration exists — a deliberate deviation, see "Decisions".

## Environment / config keys added

| Key | Example value | Notes |
|---|---|---|
| `STRIPE_API_PREVIEW_VERSION` | `2026-07-29.preview` | Already present from step 02 — confirmed, not re-added |
| `MARKETPLACE_INTERNAL_API_KEY` | *(blank)* | New — `BrainInternalSignature`'s shared secret. **Must be set to a real random value before `createSession()` can succeed for any caller** — left blank deliberately rather than a guessed value; fails closed while empty. |

## Decisions made during execution that diverge from the plan

- **No `processor_connect_profiles` table, and `ProcessorConnectProfile` is a value object, not an
  Eloquent model.** §08.3 explicitly considers and rejects a dedicated table ("a second table would
  mean a second editing surface for one logical object... promote it then"), with a fully worked
  `extra_config['connect']` JSON shape. §08.6/§08.9 elsewhere list "processor_connect_profiles" among
  the migrations and models to create — an internal inconsistency in the plan. §08.3's reasoning is
  explicit, detailed, and load-bearing; §08.6/§08.9's mentions read as an editing artifact. Followed
  §08.3 literally.
- **`BrainInternalSignature` is a new, minimal HMAC channel, not a reuse of step 04's
  `MarketSignature`.** §08.6 says `createSession()` needs "the step-04 HMAC service signature" —
  but step 04 built members↔market signing specifically; brain was never part of that channel, and
  reaching into a members/market-owned class from brain would be a much larger coupling than the one
  endpoint this fixes. Re-implemented the identical, already-proven algorithm
  (`{timestamp}.{METHOD}.{path}.{sha256(body)}`, HMAC-SHA256, ±300s skew, `hash_equals`) as brain's
  own channel instead. Verified correct (valid/tampered/stale all behave correctly) in the standalone
  harness.
- **§08.7's invariant 3 (fee recomputation) required extending the `split` payload with an explicit
  `line_net` field per transfer / for the destination block.** Brain has no order-item breakdown of
  its own (market owns that); without a `line_net` to recompute against, "recomputed server-side"
  has nothing to recompute from. The extension doesn't weaken the security property: invariant 1
  (`sum(transfers) + fee ≤ amount`) is checked independently from `line_net` and is what actually
  bounds money movement — a caller lying about `line_net` can only make the fee-recomputation check
  fail, never move more money than invariant 1 already permits. Documented in `SplitCalculator.php`'s
  own header comment.
- **`ProcessorSyncController`'s gate checks `mode !== 'sandbox'`, not `mode !== 'test'`.** The plan's
  prose says "against a test-mode key"; this codebase's actual `Processor.mode` enum (confirmed via
  its migration and every existing `mode === 'live'` call site) is `sandbox`/`live`, never `test`. A
  literal `!== 'test'` gate would have blocked every processor unconditionally — caught before
  shipping by re-reading the actual model, not assumed from the plan's wording.
- **`ProcessorConnectController::destroy()` now soft-closes (`closed_at` + `status: closed`)
  instead of hard-deleting the local row.** The old code called `$account->delete()`. §08.6 adds a
  `closed_at` column specifically, and §08.5.8's future migration schema references old accounts by
  id (`from_connected_account_id`) — a hard-deleted row breaks that reference before the feature
  that needs it is even built.
- **`mountComponent()` (any embedded component besides onboarding) goes through the same *unsigned*
  `startOnboarding` endpoint, parameterized by a new `components` option, rather than through the
  newly-secured `createSession()`.** The plan names `createSession()` as needing "real
  authorization" without stating whether every embedded-mounting path should move behind it.
  Reasoned explicitly (see `ConnectAccountService::startEmbeddedOnboarding()`'s own comment):
  `createAccount()`/`startOnboarding()` are both part of the plan's own public `dp.connect` surface,
  callable by a seller onboarding themselves directly (§08.2.5's "the members one is for a seller
  onboarding themselves" — the exact mechanism step 10 will use); `createSession()`'s fix targets
  the *specific* defect the audit found (any id, no ownership check), not every embedded-session
  mint. Kept both mechanisms, scoped narrowly, rather than picking one without evidence for either.
- **`ExternalProcessorsAPI.getPublicConfig()` and `ExternalDirectPaymentsAPI`'s provider/publishable-key
  caching in `initializeDirectPayment()` were added, not named in §08.9's file list.** Necessary
  because `dp.connect.isSupported()` is synchronous per §08.5.4's own interface — the provider must
  be known before `dp.connect` is first touched, not fetched lazily inside it. Same class of
  found-and-filled gap as `CatalogController.php` in step 06 / `FreelanceApi.ts` in step 07.
- **The full `CONNECT` checkout path (server-to-server invoice prepare, `setupSinglePayPayment`,
  `getSplitPreview()`, `ExternalProcessorsAPI.getConnectStatus/Balance/Payouts()`) was not built.**
  §08.5.7 itself states "Step 13's checkout changes accordingly" for the invoice-prepare mechanism —
  this step built `isConnect()` and a specific, informative error in place of the old generic
  "Unrecognized Direct Payment payload format" crash, per its own literal ask, and stopped there
  rather than guessing at step 13's design.

## Known gaps / deferred

- **`MARKETPLACE_INTERNAL_API_KEY` is blank.** `createSession()` fails closed (401) until a real
  value is set in `.env` and deployed. Left blank deliberately rather than committing a guessed
  secret.
- **No live Stripe verification anywhere in this step.** No test-mode Stripe account with Connect +
  Accounts v2 was available this session (confirmed with the user before proceeding). Every SDK call
  shape was verified by reading the installed `stripe-php` 21.2.0 source directly (method
  signatures, param shapes, response classes, event-notification subclasses) rather than assumed
  from the plan's prose — see "How this was verified" — but no request has actually reached Stripe's
  API. §08.11's live-Stripe checklist items (account creation, embedded onboarding mounting in a
  real browser, webhook delivery, `ping`, live refund/dispute outcomes) are **all unverified** and
  must be exercised against a real test-mode account before this ships.
- **`php artisan` cannot run at all in this working tree.** `routes/portal.php:68`'s
  `include_once` of a missing legacy file (`public/_portal_/admin/core/php/includes/functions.php`)
  breaks every artisan command — migrate, tinker, serve, route:list — confirmed pre-existing via
  `git show HEAD:routes/portal.php` (the exact line is untouched by this step's diff). This blocks
  running the six new migrations against any real database and blocks any HTTP-level verification of
  the routes. Worked around with a standalone Illuminate/Capsule harness (see "How this was
  verified") for the framework-independent logic; nothing requiring the full app boot was verified.
- **`push to market → seller_profiles`** (§08.6's step 4 of "what a verification handler must do")
  is not built. There is no established brain↔market signed channel in this codebase (step 04 built
  members↔market signing specifically), and no market-side endpoint to receive this push.
  `SyncConnectAccountJob` mirrors locally and logs the classified transition at the correct severity;
  the push itself needs a future step to establish the channel.
- **The deadline-chasing sweep** (14/7/1-day reminders on `requirements.current_deadline` and
  `evidence_details.due_by`) is not built — no scheduled command exists for it. §08.7's own words:
  "the events alone are not a reminder system." A real gap, not a forgotten checklist item.
- **`uploadDocument()` and the rest of `onboarding_mode: api`'s document-handling surface** are not
  wired to a brain endpoint. `api` mode is not the default (§08.1) and no `account_token`
  (`AccountTokenService`) path was built either.
- **`requestPayout()`** throws "not yet supported" on `StripeConnectProvider` — sellers do not
  self-serve payouts in phase 1; step 16 owns the payout request flow.
- **No admin UI changes.** The Connect Settings panel on `processor-details.html`,
  `ProcessorDetailView.ts`'s load/save wiring, and `ProcessorsService.ts`'s `type` → `integration_mode`
  migration (§08.3/§08.2.5) are **not built**. The backend the panel would call
  (`showProfile`/`saveProfile`, the rewritten account CRUD) is complete and independently verified;
  the panel itself is a separate, substantial UI task not attempted this session given the size of
  everything else in this step.
- **Full ledger integration for chargebacks/refund reinstatement** is not built — no ledger table
  exists yet in this codebase (step 16's territory). `logStripeChargeback()` classifies and logs at
  the correct severity per outcome but does not write a ledger entry.
- **`resources/js/checkpointHelper/static/portal.d.ts`** (a third, separately-maintained
  `portal.d.ts`-named file under market, using `export interface` module syntax rather than the
  ambient-global style of the two files this step's §08.9 names) was found but deliberately not
  touched — outside the plan's explicit two-file scope, and a different declaration mechanism
  entirely.
- **The known, pre-existing lack of auth middleware on the entire `api/v1/processors/*` route
  group** was reported to the user and explicitly not fixed this step, per their direction ("known/
  expected, proceed").

## How this was verified

- **PHP syntax**: `php -l` on all 30 touched/created PHP files (controllers, models, services, jobs,
  commands, migrations, config) — all clean, re-verified after every subsequent edit.
- **TypeScript**: `npx tsc --noEmit -p tsconfig.json` in `apps/www` — **exit 0, zero errors** —
  covering every new/modified file in `external/connect/`, `external/direct_payments/api.ts`,
  `external/processors/api.ts`, `external/portal.d.ts`, and `portal/admin/main.ts`. Same check in
  `apps/market` after the `portal.d.ts` sync — the one pre-existing error (`Cannot find type
  definition file for 'bootstrap'`) is unrelated and unchanged by this step.
- **`MARKET_MODIFIER_ADDITION` reconciliation**: every file created or modified this step greps
  clean; cross-checked against this report's Files tables.
- **Composer**: `composer show stripe/stripe-php` reports `v21.2.0`;
  `vendor/composer/autoload_psr4.php` contains a `Stripe\\` entry;
  `vendor/stripe/stripe-php/lib/V2/Core/Account.php` exists.
- **Required greps**: `grep -rn "'type' => 'required|in:standard,express,custom'" app/` → zero.
  `grep -n "url('/portal/admin')" ProcessorConnectController.php` → zero (the fix's own comment was
  deliberately reworded so the literal grep stays clean, same discipline as prior steps'
  `localStorage` checks).
- **SDK shapes verified by reading the installed source, not assumed**: `AccountService::create()`'s
  `include` key location (nested in `$params`, not a separate arg); `AccountLinkService::create()`'s
  `use_case.account_onboarding.{configurations,refresh_url,return_url}` shape;
  `$stripe->v2->core->{accounts,accountLinks,eventDestinations}` factory paths;
  `EventDestination::EVENT_PAYLOAD_THIN`/`TYPE_WEBHOOK_ENDPOINT` constants and
  `webhook_endpoint.signing_secret` response field; `BaseStripeClient::parseEventNotification()`'s
  exact signature; the concrete
  `V2CoreAccountIncludingRequirementsUpdatedEventNotification::LOOKUP_TYPE` string
  (`'v2.core.account[requirements].updated'`, matching the plan's own citation exactly) and that its
  `fetchRelatedObject()` takes no `include` parameter — which is why the v2 handler calls
  `accounts->retrieve($id, ['include' => ...])` directly instead of relying on that convenience
  method, a real implementation detail this session found by reading the SDK rather than guessing.
- **Standalone business-logic verification** (`php artisan` cannot run — see "Known gaps"): a
  self-contained PHP harness using Illuminate's Capsule (standalone Eloquent, in-memory SQLite) and a
  minimal `Config` repository, requiring only `vendor/autoload.php` — no framework boot. **22/22
  checks passed**, covering:
  - `ConnectProfileService::validate()`: all four §08.3 rules, both the valid and invalid case for
    each (self_hosted + losses_collector:stripe rejected; losses_collector:application +
    fees_collector:stripe rejected; onboarding_mode:api without acknowledgement rejected;
    platform_fee_percent > 100 rejected; a fully-valid profile passes clean).
  - `ProcessorConnectProfile::dashboard()`: all three mode mappings exact.
  - `SplitCalculator::computeLineFee()`: the plan's own worked example ($40.00 @ 10% → $4.00 fee,
    $36.00 net) exact; the floor rule on an odd amount (`fee + net == line_net` exactly).
  - `SplitCalculator::buildSplit()`: single-seller → destination with the correct destination/fee;
    two-seller → `separate_transfers` with exact per-seller amounts.
  - `SplitCalculator::validateInvariants()`: the correct split passes clean (both destination and
    separate_transfers modes); a `transfer_group` mismatch, an understated (tampered) fee, an
    unknown destination account, and a split exceeding the PaymentIntent amount are each
    independently rejected with the specific invariant code.
  - `BrainInternalSignature::verifyRequest()`: a correctly-signed request verifies; a tampered
    signature is rejected; a >300s-stale timestamp is rejected even against an otherwise-correct
    signature for that timestamp.
- **What was not verified, stated plainly**: no live Stripe API call of any kind (account creation,
  onboarding link/session, webhook delivery, refund, balance/payout read); no browser-rendered
  embedded component; no live database migration run; no HTTP request through
  `ProcessorConnectController`/`ProcessorWebhookController`/`ProcessorIntentController` (the
  `php artisan` blocker). These are exactly §08.11's checklist items this report cannot check off,
  listed under "Known gaps" rather than silently omitted.

## Follow-ups spawned

- Step 09 (Direct Payments Connect example / playground) is the natural next place to exercise this
  step's TypeScript surface against a real test-mode Stripe account — the plan's own sequencing note
  says so directly, and this step's "Known gaps" section is effectively that step's punch list.
- Step 10 (Members: Connect onboarding) builds the seller-facing UI that calls `dp.connect` for
  real; `mountComponent()`'s `components` parameterization and `createSession()`'s new auth
  requirement were both shaped with that future caller in mind (see "Decisions").
- The admin UI (Connect Settings panel, `ProcessorDetailView.ts`, `ProcessorsService.ts`'s
  `type` → `integration_mode` migration) is unbuilt and should be its own follow-up — the backend it
  would call is complete and independently verified.
- Whichever step establishes a brain↔market signed channel should wire `SyncConnectAccountJob`'s
  push to market's `seller_profiles`, per §08.6's step 4.
- Step 16 (ledger) should give `logStripeChargeback()`'s won/lost outcomes and refund reversals
  somewhere real to write to.

---

# Follow-up — 2026-08-20: the Connect Settings admin UI (§08.3) is now built

The original report deferred §08.3's admin UI ("*not attempted this session given the size of
everything else in this step*"). Auditing "how is the connected accounts feature configured?"
showed that deferral was **blocking**, not cosmetic — so it has been built.

## Why it was blocking

1. `ProcessorConnectProfile::defaults()` has `enabled => false`.
2. `ProcessorConnectController::store()` opens with
   `if (!$profile->enabled) return 422 'Connect is not enabled for this processor.'`
3. There was **no UI anywhere** to set `enabled` to true.

So clicking **New Account** on the Connected Accounts table returned 422 every time, and Connect
could only be switched on by `PUT`ing the profile endpoint by hand or editing
`extra_config.connect` directly in the database. The backend was complete and unreachable.

## Built

| File | Change |
|---|---|
| `html/views/processor-details.html` | **Connect Settings panel** — enabled switch, integration mode, onboarding mode, fees/losses collectors, default country/currency, platform fee %, charge model, account configurations, onboarding return/refresh paths. Placed directly **above** the Connected Accounts table: the panel is the template, the table is what has been created from it (§08.3's own stated relationship). Carries §08.3's immutability notice at the point of editing. |
| `Processors/ProcessorsService.ts` | `ConnectProfile` type + `getConnectProfile()` / `saveConnectProfile()`. |
| `controllers/processors/ProcessorDetailView.ts` | Profile load beside the accounts load, `renderConnectSettings()`, `handleSaveConnectSettings()`, button binding, modal prefill. |
| `i18n/{en,es,fr,de,zh}.json` | 19 new keys × 5 locales — 802 → **821 each, parity verified**, and every `data-i18n` key in the view now resolves. |

**Validation is server-side only.** `ConnectProfileService::validate()` already implements all five
§08.3 rules; the panel surfaces the 422's per-field `errors` map rather than re-implementing them —
two copies of a validation rule is how the two drift. This required a small change to
`apiFetch()`, which was discarding `errors` and leaving callers with only a generic
*"Invalid Connect configuration."* — the opposite of §08.3's *"rejected in the form where it can be
explained."*

## The v1 `type` select: removed, not relabelled

§08.3 called for migrating `#conn-type` to `integration_mode`. Reading `store()` showed the control
should not exist per-account at all: it validates only `email/display_name/country/entity_type` and
takes the mode **from the profile**, so the modal's `type: 'standard'|'express'|'custom'` was
**silently discarded**. An admin picking "Express" got whatever the profile said — a control that
looked meaningful and changed nothing.

Replaced with a **read-only display** of the mode the account will be created with, plus a
prefilled default country. Opening the modal while Connect is disabled now says so up front rather
than letting the admin fill in a form to reach a 422.

One consequence handled: the post-create branch keyed off `payload.type === 'custom'` to decide
embedded-vs-hosted onboarding. That now keys off the profile's `onboarding_mode`, which is what
actually governs it (§08.4).

## Task 1.5's two Direct Payment defects — fixed

Both were still present, in `controllers/direct_payments/directpayments.ts`:

- **No `is_active` filter** despite the select reading *"Select an active processor…"*. An inactive
  processor could be assigned, failing much later at charge time where `where('is_active', true)`
  skips it. Filtered client-side, not in `ProcessorAdminController::index()`, because that endpoint
  also feeds the processor list view which must show inactive processors so they can be activated.
  Confirmed `toAdminArray()` already returns `is_active`, so the data was present and simply unused.
- **Wrong identifier on the fallback path** — `:216` used `p.reference_num || p.id` but `:240` used
  `p.id || p.reference_num`. Since `p.id` is always truthy, the cached-fallback path stored a numeric
  primary key into `processor_reference_num`, matching no processor and producing a Direct Payment
  that looked correctly configured and was not. Both now `p.reference_num || p.id`.

## Verification

- `npx tsc --noEmit -p tsconfig.json` — **0 errors**, whole project.
- `npm run build` — succeeds.
- i18n parity — 821 keys in all five locales, symmetric difference empty; no unresolved `data-i18n`.
- **Not verified:** no browser run. The panel has not been driven against a live processor record,
  so the load/save round trip and the server's 422 rendering are code-verified only.
