# 04 — Subscription State — Complete

## Status

Done, with one documented scope gap (Checkpoint bearer resolution — see below) and
verification limited to static checks (no live DB in this environment — see below).

> **Superseded 2026-09-23** — every `member_reference_num` reference below (the link
> table's column, `DirectPaymentSubscriptionController::resolveMemberReferenceNum()`,
> `BillingBackfillDpSubscriptions::resolveMemberReferenceNum()`) describes this step's
> original design, since replaced by a generic `payer_reference_num` pointing at
> `laravel_direct_payment_payers` — see `plans/brain_membership_identity/02-database-and-server.md`
> §2.1 (BM1) and its completion doc. Both methods were renamed to `resolvePayerReferenceNum()`
> and no longer touch `brain_memberships.customer_id` (dropped in the same pass). Kept
> here as the historical record of this step, not the current schema.

## Summary

Built the database-is-the-answer layer §4.1 says everything else depends on: the two
migrations, the DP-owned link table and model, the three provider readers plus the sync
service that normalises `next_charge_at`/`status`/amount across Stripe/Square/PayPal
(§4.3–§4.5), wired the normaliser into both the creation paths and the webhook handlers
(§4.6, fixing **B3** and **B4**), added the DP↔subscription link endpoint and its SDK call
sites (§4.2.3), fixed the forgeable-tier signup defect (**B38**, §4.2.5) and its member
adoption (§4.2.4), and wrote the two backfill/reconciler commands (§4.7).

## Files changed

### Migrations
| File | Contents |
|---|---|
| `www/database/migrations/2026_08_25_000001_add_billing_fields_to_processor_subscriptions_table.php` | §4.2.1 columns on `laravel_processor_subscriptions` + composite index, `Schema::hasColumn()`-guarded |
| `www/database/migrations/2026_08_25_000004_create_direct_payment_subscriptions_table.php` | §4.2.2 — new `laravel_direct_payment_subscriptions` link table |

### Models
| File | Change |
|---|---|
| `www/app/Models/Externals/Processor/ProcessorSubscription.php` | Added the §4.2.1 columns to `$fillable`/`$casts`. **No relation added** to DirectPayment/Membership — verified by the §4.9 grep below |
| `www/app/Models/Externals/DirectPaymentSubscription.php` | New — the §4.2.2 link model, with `directPayment()`, `processorSubscription()` (joined on `provider_subscription_id`, not a real FK — §4.2.3's step-2-can-fail note), `tier()` |

### Provider sync
| File | Change |
|---|---|
| `www/app/Externals/DirectPayments/Subscriptions/Providers/SubscriptionReader.php` | New interface, exact shape from §4.4 |
| `www/app/Externals/DirectPayments/Subscriptions/Providers/StripeSubscriptionReader.php` | New — `read()` (GET + expand) and public static `normalise()`, reused by the Stripe webhook handler |
| `www/app/Externals/DirectPayments/Subscriptions/Providers/SquareSubscriptionReader.php` | New — `read()`, a catalog plan-variation amount lookup, and public static `normalise()`, reused by the Square webhook handler |
| `www/app/Externals/DirectPayments/Subscriptions/Providers/PayPalSubscriptionReader.php` | New — `read()`, OAuth token fetch, plan billing_cycles amount fallback |
| `www/app/Externals/DirectPayments/Subscriptions/Providers/SubscriptionGoneException.php` / `SubscriptionUnavailableException.php` | New — §4.5's two failure modes |
| `www/app/Externals/DirectPayments/Subscriptions/SubscriptionSyncService.php` | New — `syncDueSubscriptions()` (bounded candidate set, §4.4) and `syncOne()` (used by both the scheduler and the backfill command), gone/unavailable handling per §4.5 |
| `www/config/billing.php` | New — `sync_limit_per_run`, `default_notice_window_days` |

### Creation + webhook paths (B3, B4)
| File | Change |
|---|---|
| `www/app/Http/Controllers/Externals/Processor/ProcessorSubscriptionController.php` | All three `createXSubscription()` methods now populate the normalised fields from their own creation response (§4.6 point 1). No new request fields, no DP/member knowledge added |
| `www/app/Http/Controllers/Externals/Processor/ProcessorWebhookController.php` | `logStripeSubscription()`/`logSquareSubscription()` routed through `StripeSubscriptionReader::normalise()`/`SquareSubscriptionReader::normalise()` (§4.6 point 2). `logPayPalSubscription()` changed from a bare `->update()` to `updateOrCreate()` (§4.6 point 3, **B4**) |

### DP link endpoint + signup fixes
| File | Change |
|---|---|
| `www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentSubscriptionController.php` | New — `store()`, the §4.2.3 link-write endpoint. Best-effort: logs and returns `success: true` on any resolution failure, per §4.2.3's "never fail the subscription" rule |
| `www/routes/portal.php` | `POST /api/v1/directpayments/{dpRef}/subscriptions`, in the same public route group as the existing `/{dpRef}/connect/account` |
| `www/app/Http/Controllers/Portal/Admin/MembershipController.php` | `store()`: derives `membership` from the DP link row's tier instead of trusting the request body (§4.2.5, **B38** — the fix ordered first in the group by severity), then adopts the link row's `member_reference_num` after the member is created (§4.2.4) |

### Backfill / reconciler commands (§4.7)
| File | Command |
|---|---|
| `www/app/Console/Commands/ProcessorBackfillSubscriptions.php` | `processor:backfill-subscriptions [--dry-run]` |
| `www/app/Console/Commands/BillingBackfillDpSubscriptions.php` | `direct_payments:backfill-subscriptions [--dry-run]` — also the orphan reconciler §4.2.3 depends on |

### External SDK (§4.2.3's client side)
| File | Change |
|---|---|
| `www/resources/js/external/direct_payments/api.ts` | New private `_linkSubscriptionToDirectPayment()` helper (fire-and-forget POST to the new endpoint), called from three sites — see deviation note below |

## Deviation from the plan text — three SDK call sites, not two

§4.2.3 names two call sites: `_confirmSubscriptionPaymentInternal()` and "the `charge()` subscription branch". Reading the actual control flow: Stripe's subscription is created (and its id first exists) inside `setupSubscriptionPayment()`'s Stripe/Square combined branch, not inside `_confirmSubscriptionPaymentInternal()` — that function's Stripe branch only confirms an already-created subscription's payment intent. Similarly, PayPal's subscription is created in `setupSubscriptionPayment()`'s `paypal` branch's `onResult` callback; `_confirmSubscriptionPaymentInternal()`'s paypal branch only checks the `_paypalPaymentDone` flag that callback sets — it creates nothing.

Following the plan's two named sites literally would link Square subscriptions (confirmed in `_confirmSubscriptionPaymentInternal`) and the `charge()` fast path, but leave every Stripe mount-flow and every PayPal signup permanently unlinked except via the backfill command. That contradicts §4.1's premise ("every later document in this plan queries this state"), so the link call was added at all three genuine subscription-creation points instead:

1. `setupSubscriptionPayment()`'s Stripe/Square combined `subResult.success` block (Stripe's creation point)
2. `setupSubscriptionPayment()`'s PayPal `onResult` callback (PayPal's creation point)
3. `_confirmSubscriptionPaymentInternal()`'s Square branch (Square's creation point — this one matches the plan's naming)
4. `charge()`'s `SUBSCRIPTION` branch (matches the plan's naming)

Each call is fire-and-forget (`void this._linkSubscriptionToDirectPayment(...)`, errors caught and logged, never thrown) so a link-write failure can never surface as a payment failure, per §4.2.3's own rule.

## Known scope gap — Checkpoint bearer member resolution

§4.2.3 specifies resolving `member_reference_num` "from the Checkpoint bearer when present, else by matching the processor customer's `provider_customer_id` against `brain_memberships.customer_id`." `www` has no local Checkpoint JWT verification today — every existing Checkpoint controller (`MemberInitController`, `AllocationV2Controller`, `SecondaryUserController`) only **proxies** the bearer to the central auth server (`auth.appfactory.studio`); none decode it locally. Implementing that decode is a auth-service-level capability this step did not add.

`DirectPaymentSubscriptionController::resolveMemberReferenceNum()` and `BillingBackfillDpSubscriptions::resolveMemberReferenceNum()` therefore resolve via the provider-customer-id match only. This is not a functional regression — the match is always available for a subscription (a customer was necessarily resolved to charge it) — but it means a member who is logged in via Checkpoint at signup time and whose `brain_memberships.customer_id` hasn't been written yet will resolve to `null` at link-write time exactly as documented in §4.2.4, and gets adopted by `MembershipController::store()` moments later. Flagged here rather than silently narrowed.

## Verification performed

- `php -l` on every changed/new PHP file — all clean.
- `tsc --noEmit` on the full `www` TypeScript project — zero errors attributable to `api.ts`; the project's pre-existing 2-error baseline (`tsc_errors.txt`, unrelated file) was not reproduced by this run at all (0 total errors), so no regression either way.
- `grep -rn "direct_payment\|DirectPayment\|brain_membership\|Membership" app/Http/Controllers/Externals/Processor/ app/Models/Externals/Processor/` — every match is pre-existing (`DirectPaymentInvoice` charge/invoice linkage that predates this plan). Nothing this step added appears in either directory — the §4.9 layering-boundary check passes.
- **Not** run against a live database: `php artisan migrate:status` fails in this checkout with `include_once(...public/_portal_/admin/core/php/includes/functions.php): Failed to open stream` — `routes/portal.php` unconditionally includes a directory that is not part of this repo checkout (deployed/symlinked separately in production). This is a pre-existing environment limitation, not something this step introduced, and blocks running **any** artisan command here, not just migrations. Migrations, model casts and the sync service should be exercised against a real deployment or a local checkout that has `public/_portal_/admin` present.

## Acceptance checklist vs §4.9

- [x] Processor layer gains no Direct Payments/Membership knowledge (grep above).
- [x] Creating a subscription with the DP link endpoint unreachable still succeeds (`store()`'s try/catch always returns `success: true`; the SDK call is fire-and-forget) — `direct_payments:backfill-subscriptions` adopts the orphan afterwards.
- [ ] "Every active/trialing subscription has a non-null `next_charge_at` within 24 hours of the backfill" — **not verified live**, no reachable database in this environment (see above).
- [ ] "Turning webhooks off... still yields a fresh `next_charge_at`" / "killing provider network access... leaves `next_charge_at` unchanged" — same limitation; the code paths implementing both rules exist (`SubscriptionSyncService::syncOne()`'s catch blocks) but were not exercised end-to-end.

## Follow-ups for later steps

- Step 05 creates `laravel_direct_payment_notifications` / `App\Models\Externals\DirectPaymentNotification`; `SubscriptionSyncService::resolveHorizonDays()` already queries that model behind a `Schema::hasTable()` guard, so no revision to this step's code is needed when 05 lands.
- Step 03's completion doc already flags that `syncNotificationConfigs()` (05) and `syncAdvancedConfig()` (12) must be inserted into `DirectPaymentsController::saveDirectPayment()` **before** the cron-bootstrap trigger this step did not touch.
- The Checkpoint-bearer gap above is worth a dedicated follow-up if member resolution accuracy at signup time becomes an issue in practice — currently mitigated by §4.2.4's adoption step.
