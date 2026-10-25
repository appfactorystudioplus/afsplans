# 05 — Provider Parity and Portability Classes (complete — three things deliberately narrower than the table)

## Status

**Complete 2026-10-17.** The work list of §5.4 (P1–P11), the canonical catalog of §5.3 and the portability classes of §5.2 are built, tested on a
faked Http client, and run against MariaDB. Three places are narrower than the plan's table, each on purpose and each listed under "Deviations":
a discount that **ends after N cycles** can be sold on Stripe only; the admin tier form still **picks a processor plan** (the tier's canonical price
is read from it, or sent explicitly through the API); and the **SDK** still sends its own currency and no `return_url`/payer token (step 08).
Everything marked † in the plan — Square's plan shape, trial phase, swap-plan and price override, PayPal's inline plan override, PayPal Vault —
is implemented to each provider's documentation and **has not been run against a provider sandbox**; the plan itself requires that confirmation.
Nothing is committed.

Verification: on MariaDB 11.4.5 (`C:\Users\mrmit\mariadb`), `tests/Feature/{DirectPayments,Processor,SiteUsers,Connections,Ecommerce,Events,Membership}`:
**605 tests, 2403 assertions, all green**. `tsc --noEmit` clean for `w/www` and for the external SDK project (`resources/js/external/api`). Grep gates G6 and G8 empty.

## Phases

| Phase | What shipped | Findings |
|---|---|---|
| P0 — schema | `laravel_direct_payments.currency`; tiers + `amount_cents`, `currency`, `interval`, `interval_count`, `trial_days`; tier bindings + `processor_plan_id`, `provisioned_by`; new `laravel_direct_payment_discounts`; `laravel_processor_discounts` + `canonical_reference_num`, `duration`, `duration_cycles` — all straight into the two create migrations | §5.3 |
| P1 — one plan-creation copy | `ProcessorPlanProvisioner` (the three `createStripePlan/createSquarePlan/createPayPalPlan`, moved onto the Http client, same wire format) + `BillingInterval` (canonical week / month / quarter / year and each provider's spelling; quarter = Stripe month × 3, PayPal MONTH × 3, Square QUARTERLY). The admin plan screen now calls it. Fixed on the way: `store()` used a `$retryDefaults` variable that only existed inside the PayPal helper | §5.3 |
| P2 — Square trial (P4) | A Square plan with a trial gets a zero-priced `DAILY` first phase of N periods; the paid phase has no length. Deterministic catalog idempotency key | A41 |
| P3 — Square plan change (P5) | `SquareSubscriptionActions::update()` posts `/v2/subscriptions/{id}/swap-plan` instead of refusing | A42 |
| P4 — deferred start (P3) | `ProviderSubscriptionCreator`: Stripe `trial_end` + `proration_behavior=none`, Square `start_date`, PayPal `start_time`; a past date is ignored. The three subscription-creation paths of `ProcessorSubscriptionController` call it, taking an optional server-only `starts_at` — this is the mechanism step 07 moves subscriptions with | A34 |
| P5 — idempotency (P7) | Every provider create call carries a key derived from our own reference (the invoice; the card token for a Square card; the order id for a PayPal capture; charge + amount + refund count for a refund). No `uniqid()` remains in `Http/Controllers/Externals/Processor` or `Externals` (gate G8). Without an invoice reference the key falls back to a unique one — the old behaviour | A48 |
| P6 — return URLs and location (P8) | A PayPal approval returns to the page the SDK names only when its host is one of the tenant's own (the application's own host or a Direct Payment's `website_domain`); otherwise to the application's URL. A Square subscription uses the processor's own `location_id` and refuses a processor without one — no more `env('SQUARE_LOCATION_ID')` | A49 |
| P7 — canonical discounts (P6) | `DirectPaymentDiscount` + `DiscountProvisioner` + admin API (`GET/POST …/directpayments/discounts`, `PUT …/{ref}`, `POST …/{ref}/provision`). Stripe: **one** coupon per discount per processor, reused (the per-subscription coupon minting in both the create-subscription and apply-discount paths is gone). Square: the discount is carried in `price_override_money`. PayPal: an inline `plan.billing_cycles` override of the regular cycle. A code is provisioned on a processor the first time it is redeemed there. The per-processor admin screen no longer refuses PayPal | A40 |
| P8 — catalog | `TierPriceProvisioner`: bind an active plan with the same canonical price, else create it and bind it (`provisioned_by = auto`); `dry run` writes nothing; re-running is a no-op. Saving a tier **with a price and no plan** provisions it on the Direct Payment's active processor; saving a tier that names a plan reads its price from the plan. The swap preview lists what it would create (`provisioning`, `tiers_to_provision`) and the swap creates the target's plans **before** the locked move (a provider call never holds a lock); a failure stops the change with `SWAP_E_PROVISION_FAILED` and nothing moves. `tiers_to_provision` is left out of the impact hash so the acknowledged hash stays valid | §5.3 |
| P9 — capabilities and classes (P10) | `ProviderCapabilities` (one declaration: intervals, trial, deferred start, change card, plan change, discount durations, Connect); `PortabilityClassifier` — `portable` / `portable_with_differences` / `same_provider_only` / `same_account_only`, read from the Direct Payment's data and shown in the swap preview with the list of differences. A live subscription whose discount the target cannot express blocks the change (`SWAP_E_DISCOUNT_UNSUPPORTED`) instead of letting it vanish | §5.2 |
| P10 — currency (P9) | `laravel_direct_payments.currency` (set by a save, kept when a save names none). An invoice's currency: the Direct Payment's, else the processor's account currency (read from the provider in step 03), else the browser's, else usd | A47 |
| P11 — SDK display (P11) | `getTierInfoFromRecord` reads the tier's canonical `amount_cents` / `currency` / `interval`; `original_data` is only a fallback and the processor it names is never read (it used to steer which processor was charged). Square and PayPal tiers no longer show $0 | A46 |
| P12 — tests | 5 new classes, 50 tests (below) | — |

## Files

**New:** `app/Externals/Processor/{ProviderCapabilities}.php`, `Processor/Plans/{BillingInterval,ProcessorPlanProvisioner}.php`,
`app/Externals/DirectPayments/{TierPriceProvisioner,PortabilityClassifier}.php`, `DirectPayments/Discounts/DiscountProvisioner.php`,
`DirectPayments/Subscriptions/Creation/ProviderSubscriptionCreator.php`, `app/Models/Externals/DirectPaymentDiscount.php`,
`app/Http/Controllers/Externals/DirectPayments/DirectPaymentDiscountController.php`.

**Changed:** both create migrations; `ProcessorPlanAdminController`, `ProcessorSubscriptionController` (three create methods), `ProcessorDiscountAdminController`,
`ProcessorBillingController`, `ProcessorIntentController`, `RefundService`, `SquareSubscriptionActions`, `SwapService`, `SwapCompatibility`, `TierBindingRegistry`,
`DirectPaymentsController`, `DirectPaymentTier` / `…TierBinding` / `DirectPayment` / `ProcessorDiscount` models, `routes/portal.php`,
`resources/js/external/api/direct_payments/api.ts`.

## Tests (`tests/Feature/DirectPayments/`)

`ProcessorPlanProvisionerTest` (7), `TierProvisioningTest` (11), `CanonicalDiscountTest` (9), `ProviderParityTest` (11), `ProviderSubscriptionCreatorTest` (12). Provider HTTP is faked and the
requests are the evidence (deferred-start fields, idempotency keys, return URLs, coupon counts, phases and cycles).

## Acceptance

| Criterion | Result | Evidence |
|---|---|---|
| A plan can be created on all three providers from the same canonical fields | ✅ (faked providers) | `ProcessorPlanProvisionerTest` |
| A swap provisions matching plans on the target automatically, dry run first | ✅ | `TierProvisioningTest::test_a_swap_creates_the_targets_plans…`, `…_a_dry_run_reports…` |
| Re-running provisioning is a no-op; an existing equal plan is bound, not recreated | ✅ | `…_the_real_run_creates_one_plan_binds_it_and_a_rerun_changes_nothing`, `…_already_has_the_same_price…` |
| Deferred start on all three providers | ✅ requests | `ProviderSubscriptionCreatorTest` |
| One reusable Stripe coupon per discount | ✅ | `CanonicalDiscountTest::test_stripe_gets_one_coupon_per_discount_and_reuses_it` |
| Deterministic idempotency keys; no `uniqid()` | ✅ | G8 empty; `…_idempotency_keys_follow_our_reference…`, refund key test |
| Capabilities declared once and read by the preview | ✅ | `ProviderParityTest` |
| Portability class in the swap preview; unexpressible discount blocks | ✅ | `ProviderParityTest` |
| Direct Payment currency; server wins over the browser | ✅ | `test_the_servers_currency_wins_over_the_browsers_hardcoded_usd` |
| Square and PayPal tiers show their price in the SDK | ✅ typed; no browser run | `tsc` (external project) |
| Behaviour verified against Stripe / Square / PayPal sandboxes (†) | ❌ not done | the plan requires it before the † rows are relied on |

## Deviations from the table, and why

1. **Discounts that end after N cycles are Stripe-only.** The plan's table has Square (phase pricing) and PayPal (an override "for the discounted cycles") express them. A PayPal inline override can change the price of the plan's *existing* cycles but, as documented, cannot add a cycle; and Square's price override is subscription-wide. Both therefore carry only a discount that lasts as long as the subscription. Rather than let a `once` / `repeating` code disappear silently, the capability table says so, the swap refuses to move live subscriptions that carry one (`SWAP_E_DISCOUNT_UNSUPPORTED`), and a redemption on those providers is refused (`DISCOUNT_E_UNSUPPORTED`). Lifting it needs discounted plan *variants* (a second plan per discount) — a follow-up.
2. **The admin tier form still selects a processor plan.** The plan's "ask for the price instead of a plan id" form is not rebuilt: choosing a plan now sets the tier's canonical price from it (`original_data`), and the API accepts the canonical fields directly (`amount_cents`, `currency`, `interval`, `trial_days`) and provisions the plan itself. A price-entry form is UI work on top of an API that is finished and tested.
3. **The SDK is untouched except tier display (P11).** It still sends `'usd'` (the server now overrides it), no `return_url`, and no payer token — step 08. `ProcessorSubscriptionController` already accepts `return_url` / `cancel_url`.
4. **Changing a tier's price** (new price for new subscribers, old price kept for existing) is supported by the data model (a new tier binding retires the old one; each subscription keeps its own plan) but there is no screen or command for "reprice this tier"; that rule is enforced by step 07's subscription contract.

## Residue and notes

- **PayPal plans now have `total_cycles: 0` on the regular cycle.** The old code omitted it, which PayPal documents as a default of one cycle — a plan that would have ended after its first charge. Changed here; confirm in a sandbox.
- **Square's plan shape** (a `SUBSCRIPTION_PLAN` object with `phases`, its id used as `plan_variation_id`) is the shape this application always used; Square's current catalog separates plans from plan *variations*. The trial phase and the swap-plan call follow the same assumption. Verify in a sandbox first.
- A Stripe `repeating` coupon for a plan not billed monthly is minted per subscription (the months differ per plan); `once` and `forever` coupons are reused.
- Remaining uses of the browser's `processor_override`, the hardcoded `'usd'`, and the missing `return_url` are SDK items in step 08.
