# 05 — Provider Parity and Portability Classes

> Owner requirement: *"each provider needs to have parity with one another as much as possible so that the switch can
> happen seamlessly … providers do offer features so unique that it is impossible to switch between two different
> providers, like Stripe's Connect — if that's enabled on a processor only another Stripe account should be able to
> switch within the Direct Payment."*

Fixes **A40–A49**, **A12** (with [06](06-swap-engine-v2.md)), **A16–A18**.

## 5.1 Capability matrix

What each provider can do, what the app does with it today, and the target. Provider facts are as documented by each
provider at the time of writing; rows marked † must be confirmed in sandbox before the phase that relies on them ships.

| Capability | Stripe | Square | PayPal | App today | Target |
|---|---|---|---|---|---|
| One-time card charge | PaymentIntent | Payments API | Orders v2 (card or PayPal wallet) | ✅ all three | unchanged |
| Hosted checkout | Checkout Session | Payment link | Approval redirect | ✅ Stripe session; Square `payment_link` config; PayPal approve URL | unchanged |
| Customer object | Customer | Customer | Vault customer (exists once something is vaulted) | Stripe ✅, Square ✅, PayPal ❌ fake `paypal:{email}` (**A26**) | PayPal vault customer ([04](04-payer-customer-identity.md) §4.4) |
| Save a card | SetupIntent / attach | Cards API (card on file) | Vault v3 setup token † | ✅ Stripe, Square; PayPal broken by A26/A45 | all three |
| Refund (full / partial) | ✅ | ✅ | ✅ | ✅ all three, resolved from the charge | unchanged |
| Subscription, fixed price | ✅ | ✅ (catalog plan variation + card on file + location) | ✅ (buyer approval) | ✅ all three | unchanged |
| Intervals | any unit × count | fixed cadences (weekly, monthly, quarterly, annual, …) | DAY/WEEK/MONTH/YEAR × count | weekly / monthly / yearly | canonical set = **weekly, monthly, quarterly, yearly** — the intersection all three express exactly |
| Free trial | `trial_end` | a first phase priced 0 † | TRIAL billing cycle | Stripe ✅, PayPal ✅, **Square ❌** (**A41**) | Square via a zero-priced first phase |
| **Deferred start** (no charge until a date) | `trial_end` or `billing_cycle_anchor` + no proration | `start_date` | `start_time` | ❌ unused (**A34**) | **required** by [07](07-subscription-continuity.md) on all three |
| Discount on a subscription | Coupon (once / repeating / forever) | Discount via phase pricing † | No coupons; per-subscription plan override with custom pricing † | Stripe mints a coupon **per subscription**; Square stub; PayPal ignored (**A40**) | one canonical discount, provisioned per provider (§5.3) |
| Plan change | ✅ | `swap-plan` endpoint † | Revise (may need buyer approval) | Stripe ✅, PayPal ✅, Square refused (**A42**) | Square via swap-plan |
| Change card on subscription | ✅ | ✅ | ❌ buyer only, at PayPal | matches (**A43**) | unchanged; surfaced as a capability |
| Cancel at period end | `cancel_at_period_end` | cancel takes effect at period end | cancel stops future charges at once (paid period unaffected) | per provider | one verb "end at period end"; access governed by the DP's policy (`../direct_payments/26`) |
| Pause | ✅ | ✅ | suspend | ❌ | not in scope |
| Wallets (Apple/Google Pay) | Payment Element | Web Payments SDK | — (PayPal / Venmo / Pay Later instead) | provider UI decides | unchanged; tokens are never portable |
| Marketplace split (Connect) | ✅ | ❌ | (Commerce Platform — not implemented) | Stripe only | **provider-locked** (§5.5) |
| Tax | Stripe Tax | — | — | app computes its own (`ProcessorTaxDiscountController`) | portable because it is ours |
| Webhook signature | ✅ | ✅ | ✅ (verify API) | ✅ | per-processor URL ([10](10-webhooks-history-and-integrity.md)) |
| Disputes | events | events | events | logged only (**A58**) | stored ([10](10-webhooks-history-and-integrity.md) §10.4) |
| Failed-payment retry | Smart Retries (account-level, declared) | fixed by Square | per-plan threshold | `../direct_payments/27` | unchanged; the swap preview lists what does not carry over |
| Idempotency | `Idempotency-Key` header | `idempotency_key` field | `PayPal-Request-Id` header | `uniqid()` on Square/PayPal (**A48**) | deterministic keys derived from our own reference |

## 5.2 Portability classes

Every feature a Direct Payment uses gets one class. The swap computes the DP's class as the **most restrictive** of the
features it actually uses (read from data, not from configuration flags alone), and [06](06-swap-engine-v2.md) §6.4
refuses any swap outside it.

| Class | Meaning | Features in it |
|---|---|---|
| **Portable** | Works on all three with the same meaning | one-time charges, refunds, fixed-price subscriptions on the canonical intervals, customers, saved cards (re-collected), app-side tax, notifications, the DP's grace and retry policy |
| **Portable with differences** | Works on the target, but differently; the preview lists each difference and the tenant acknowledges it | PayPal: subscribers change their card at PayPal, not on the site. Retry policy is reconfigured per provider. Wallet buttons differ |
| **Same provider only** | Can move only to another account of the same provider | **Stripe Connect** (`pay_type = CONNECT`, split invoices, transfer intents) and, by the owner's rule, **any DP bound to a processor with Connect enabled** |
| **Same account only** | Cannot move to another account without a migration program | Stripe Connect **with live connected accounts** — sellers' `acct_` ids exist only on the platform that created them (§5.5) |

Until §5.4's parity work lands, features it fixes count as "same provider only": e.g. a Stripe DP whose live subscriptions
use discounts cannot move to PayPal until PayPal discounts exist — the preview says exactly that instead of letting the
discount silently disappear.

## 5.3 A provider-free catalog

### Items (already provider-free)

Price, tax, SKU, dynamic bounds are stored on the item, in the Direct Payment's currency. Nothing to provision on a
swap. Changes:

- Saves upsert by `reference_num` (server-issued, never blank) instead of delete-and-recreate (**A17**).
- Removing an item archives it (`archived_at`); invoices keep their line snapshot (TODOS P0.1) so history never depends
  on the item still existing.
- `pay_type` cannot change on a Direct Payment that has invoices or subscriptions (**A16**) —
  `409 DP_E_PAY_TYPE_LOCKED`. The tenant creates a new DP for a different kind of selling.
- A Direct Payment that has ever had an invoice, subscription or binding is **archived, never deleted** (**A10**, owner
  decision **OQ2**): it stops taking new payments; history, refunds and live subscriptions keep working; it can be
  unarchived, audited. A never-used DP may still be deleted. Archive is refused while it is a module's active payment
  method on a published page (the existing `blockIfPublished()` check) unless the operator overrides, as today.

### Tiers get a canonical price (the swap's biggest manual step, removed)

Today a tier's price exists only as a provider object (`processor_tier_id`, the raw `original_data`, a
`ProcessorPlan` row). The tenant must re-create every plan by hand on the new processor, and the SDK cannot even show
Square/PayPal prices (**A46**). Target:

| New tier column | Source |
|---|---|
| `amount_cents`, `currency` | the tier's price |
| `interval` (`week`/`month`/`quarter`/`year`), `interval_count` (1) | canonical set of §5.1 |
| `trial_days` | nullable |
| `archived_at` | replaces hard delete (**A18**) |

Set by the tenant when they create the tier (no back-fill — development, no data). The admin's tier form asks for
the price instead of a provider plan id; the plan on each processor is created from it.

`TierPriceProvisioner::ensure(Tier $tier, Processor $processor, bool $dryRun)`:

1. Look for an active `ProcessorPlan` on that processor with the same canonical price. Found → bind it.
2. Otherwise create it through `ProcessorPlanProvisioner` — the provider-specific `createStripePlan/createSquarePlan/
   createPayPalPlan` code extracted unchanged from `ProcessorPlanAdminController` so the admin and the provisioner share
   one copy — and bind it (`tier_bindings.provisioned_by = 'auto'`, `processor_plan_id` set).
3. Dry run returns what it would create, which the swap preview shows.

**Changing a tier's price** creates a new price on each processor and rebinds the tier for **new** subscribers. Existing
subscribers keep the price they bought: each subscription contract stores the price it is charged at
([07](07-subscription-continuity.md) §7.3), and a migration provisions a plan at **that** price on the target. This is the
grandfathering rule; a tenant who wants existing subscribers moved to the new price does it explicitly (a plan change),
never as a side effect of a swap.

### Discounts become one canonical record

`laravel_processor_discounts` is keyed by processor; a code a tenant sells disappears on a swap. Target: a canonical
discount (code, `percent_off` or `amount_off` + currency, duration `once`/`repeating N`/`forever`, max redemptions)
owned by the Direct Payment's website, provisioned per processor like tiers:

| Provider | Provisioning |
|---|---|
| Stripe | **one** coupon per discount per processor (not per subscription — **A40**), reused |
| Square | discount applied through the subscription's phase pricing † |
| PayPal | per-subscription plan override with the discounted price for the discounted cycles † |

A subscription migrated to a new leg carries its **remaining** discount (forever → forever; repeating with 3 cycles left →
3 cycles).

## 5.4 Parity work list

| # | Work | Fixes | Needed by |
|---|---|---|---|
| P1 | One PayPal client id (`publishable_key`); migrate `extra_config.paypal_client_id` | A45 | PA3 |
| P2 | PayPal vault customer | A26, A44 | PA3 |
| P3 | Deferred start on all three providers (`ProviderSubscriptionCreator::create(…, startsAt)`) | A34 | PA6 |
| P4 | Square trial via zero-priced first phase † | A41 | PA4 |
| P5 | Square plan change via swap-plan † | A42 | PA4 |
| P6 | Canonical discounts, provisioned per provider; Stripe coupon reuse | A40 | PA4 |
| P7 | Deterministic idempotency keys on every create call (`{our_ref}:{operation}:{attempt-scope}`) | A48 | PA6 (migrations retry) |
| P8 | Return/cancel URLs from the tenant site (the SDK passes the page origin; the server checks it against the website's domains) | A49 | PA6 (hosted re-authorisation) |
| P9 | Direct Payment currency; SDK sends none, the server uses the DP's | A47 | PA2 |
| P10 | `ProviderCapabilities::for($provider)` — one declaration read by the preview, the admin (greys out what a provider cannot do) and the SDK (hides "change card" for PayPal) | A42, A43 | PA4 |
| P11 | SDK tier display from canonical tier fields; `original_data.processor` never read | A46 | PA9 |

## 5.5 Stripe Connect — the provider-locked case

Connected accounts belong to the Stripe **platform** that created them; `acct_` ids do not transfer between platforms
and do not exist at all on Square or PayPal. Bank details are not readable from the old platform and terms-of-service
acceptance is per platform. `DirectPaymentsController.php:834–855` already describes the full migration this would need.

Rules, applied by `SwapCompatibility` — the one check both `saveDirectPayment()` and the swap use (**A12**):

| DP situation | Allowed targets |
|---|---|
| The current processor is a Stripe processor with Connect enabled (owner's strict rule) | **Stripe processors only** |
| `pay_type = CONNECT`, or split invoices exist | **Stripe processors only**, with Connect enabled on the target |
| …and the current processor has connected accounts that are not closed | **Only the same Stripe platform** (`provider_account_id` equal — i.e. key rotation, not a swap) **unless** a `laravel_processor_migrations` run from the current processor to the target has completed (`completed_at` set, not aborted). Go live from sandbox is exempt: sandbox seller accounts are test data |
| Any other DP | any provider, subject to §5.2 |

The connected-accounts check counts from the processor, as `guardConnectPayType()` does today; the target's Connect
profile is read through `ConnectProfileService` and the target's provider is checked first, so `extra_config` cannot
fake it (**A7**).

**Wiring the Connect platform migration** (`laravel_processor_migrations`, `…_accounts`, `…_events` — schema built in
`market` step 08 §08.5.8, never wired) is **deferred to its own plan** (owner decision OQ6, 2026-10-16). It is a seller-facing campaign
(re-onboarding, ToS, bank details), not a background job. Until it exists, the rule above is the whole behaviour and the
preview explains it. The strict reading of the owner's rule applies: Connect **enabled on the processor** is enough to
make the Direct Payment Stripe-only.
