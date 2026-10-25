# 02 — The Target Model

The vocabulary every later document uses, the invariants that make a swap safe, and a summary of every schema change.
The detailed design of each part is in the document named beside it.

## 2.1 Entities

| Entity | Table | Identity | Changes over time? | Detail |
|---|---|---|---|---|
| **Processor** | `laravel_processors` | `reference_num` (7) **+ account fingerprint** (`provider_account_id`) | Name, keys (same account only), webhook secret, non-identity config. **Never** provider, mode or account. Archived, never deleted | [03](03-processor-lock-in-and-archive.md) |
| **Direct Payment** | `laravel_direct_payments` | `reference_num` | Name, catalog, binding. Gains `binding_version`, `currency`, `archived_at` | [06](06-swap-engine-v2.md) |
| **Binding** | `laravel_direct_payment_processor_bindings` | row id | `active` → `retiring` → `retired`; exactly one `active` per DP | existing; [06](06-swap-engine-v2.md) |
| **Payer** | `laravel_direct_payment_payers` | `pay_` + 12 | E-mail and name (snapshots, never join keys) | existing; [04](04-payer-customer-identity.md) |
| **Payer customer** | `laravel_direct_payment_payer_customers` | (payer, processor) while active | `active` → `superseded`; never deleted. Gains `source`, `superseded_reason` | [04](04-payer-customer-identity.md) |
| **Item** | `laravel_direct_payment_items` | `reference_num` | Price lives here, in the DP's currency — already provider-free. Archived, not deleted | [05](05-provider-parity-and-portability.md) §5.3 |
| **Tier** | `laravel_direct_payment_tiers` | `reference_num` | Gains a **canonical price** (amount, currency, interval, interval_count, trial_days). Archived, not deleted | [05](05-provider-parity-and-portability.md) §5.3 |
| **Tier binding** | `laravel_direct_payment_tier_bindings` | (tier, processor) while active | The provider's price/plan for that tier on that processor. Auto-provisioned from the canonical price | existing; [05](05-provider-parity-and-portability.md) §5.3 |
| **Subscription contract** | `laravel_direct_payment_subscriptions` | `reference_num` (**stable — A32 fixed**) | Tier, payer, access dates, status. The thing a module, a website or a host application holds | [07](07-subscription-continuity.md) |
| **Subscription leg** | **new** `laravel_direct_payment_subscription_legs` | (processor, provider_subscription_id) | One provider subscription that charges for the contract during a period. A contract has one or more legs over its life; at most one is renewing at any moment | [07](07-subscription-continuity.md) |
| **Migration** | **new** `laravel_direct_payment_subscription_migrations` (+ a run table) | `mig_` + 12 | The move of one contract from one leg to the next, with its own state machine | [07](07-subscription-continuity.md) §7.6 |
| **Invoice** | `laravel_direct_payment_invoices` | `reference_num` | Snapshots processor, provider, payer, amount. Created by the server (TODOS P0) | existing; [10](10-webhooks-history-and-integrity.md) |
| **Dispute** | **new** `laravel_processor_disputes` | (processor, provider_dispute_id) | open → won / lost / closed. Lets a binding know it is not finished |  [10](10-webhooks-history-and-integrity.md) §10.4 |

## 2.2 The invariants

Each is enforced in code and has a test and, where it can be expressed, a grep gate ([11](11-rollout-and-testing.md) §11.5).

| # | Invariant | Enforced by |
|---|---|---|
| **I1** | A processor row is never deleted | no route; `Processor::deleting` throws; RESTRICT foreign keys from bindings, payer customers, tier bindings, legs, invoices' processor column is kept as a soft reference ([03](03-processor-lock-in-and-archive.md) §3.3) |
| **I2** | A processor's provider, mode and account never change after creation | `ProcessorAccountGuard` on update; keys re-verified against `provider_account_id` ([03](03-processor-lock-in-and-archive.md) §3.4) |
| **I3** | A **new** payment, customer, saved card or subscription goes to the DP's **active** binding, decided by the server | `ProcessorRouter::forNewBusiness($dpRef)`; the public endpoints stop reading a processor from the request ([06](06-swap-engine-v2.md) §6.3) |
| **I4** | An action on an **existing** object (refund, capture, cancel, update, card change, dispute) goes to that object's own processor | `ProcessorRouter::forExisting($object)`; the first plan's FORWARD/BACKWARD rule, now with one resolver |
| **I5** | An archived processor takes no new business but answers every existing-object action and every webhook, forever | `ProcessorRouter`; webhook candidate rule ([03](03-processor-lock-in-and-archive.md) §3.5, [10](10-webhooks-history-and-integrity.md) §10.1) |
| **I6** | A provider-issued id (customer, subscription, card token, price) is never a module's or the browser's join key | SDK and API contract ([08](08-sdk-and-implementer-contract.md)); grep gate |
| **I7** | One payer has at most one **active** provider customer per processor, and keeps every old one | existing `active_key` unique + `PayerService` as the only writer ([04](04-payer-customer-identity.md)) |
| **I8** | A subscription contract has at most one leg that can bill in any instant; a new leg never starts before the previous leg is paid through | `SubscriptionMigrator` checks the provider's own next-charge date before ending the old leg ([07](07-subscription-continuity.md) §7.7) |
| **I9** | A swap happens only between compatible processors (same mode, target not archived, webhooks ready, currency equal, capabilities covered, Connect rules) | `SwapCompatibility` — the single place both the save guard and the swap call ([06](06-swap-engine-v2.md) §6.4) |
| **I10** | Direct Payments knows nothing about any host application: no appfactory vocabulary, tables or code | extension points ([09](09-extension-points.md)); vocabulary gate ([13](13-naming-and-separation.md) §13.6) |

## 2.3 Two resolvers replace every ad-hoc lookup

Today, processor lookups are written by hand in a dozen controllers (and get the precedence wrong, **A4**). The target
has exactly two entry points, both in `App\Externals\DirectPayments\Routing\ProcessorRouter`:

```php
/**
 * FORWARD — "where does a NEW payment for this Direct Payment go?"
 * Active binding only; refuses an archived/inactive processor, a DP with no
 * active binding, and (with the caller's binding_version) a stale caller.
 */
public function forNewBusiness(string $dpRef, ?int $callerBindingVersion = null): RoutedProcessor;

/**
 * BACKWARD — "which account holds THIS object?" Invoice, charge, refund,
 * subscription leg, payer customer, dispute. Never consults the binding.
 * Never refuses for archived/inactive: that is the point of archiving.
 */
public function forExisting(HasProcessorReference $object): Processor;
```

**Phasing.** PA0 ships the processor-level half as `App\Externals\Processor\ProcessorLookup`
(`forNewBusiness($processorRef, $provider)` / `forExisting($processorRef, $provider)`, [16](16-safety-rails-implementation.md) §2.2),
which fixes **A4** while the browser still names the processor. PA2 adds `ProcessorRouter` on top of it, routing by Direct
Payment instead.

`RoutedProcessor` carries the processor, the binding and the DP's `binding_version`, so every response the SDK gets can
tell it whether its cached configuration is current ([06](06-swap-engine-v2.md) §6.2).

## 2.4 Schema changes — summary

**Owner decision (2026-10-16): the app is still in development and no real data exists.** Every schema change —
added, renamed or dropped — is made directly in the module's existing create migration (one file per module, the
codebase's convention), and development databases are rebuilt from them. There are no upgrade migrations, no back-fill
commands and no add-then-drop transition releases. If real data exists before a later phase lands, that phase adds its
own migration path at that time.

| Table | Change | Phase | Doc |
|---|---|---|---|
| `laravel_processors` | + `provider_account_id`, `account_verified_at`, `identity_locked_at`, `archived_at`, `archived_by`, `archive_reason`, `currency` (char 3) | PA0/PA1 | 03 |
| `laravel_processor_connected_accounts`, `…_transfer_intents`, `…_webhook_endpoints` | FK `onDelete('cascade')` → `restrict` | PA0 | 03 §3.3 |
| `laravel_direct_payment_processor_bindings`, `…_payer_customers`, `…_tier_bindings` | + FK → `laravel_processors.reference_num` `restrict` | PA0 | 03 §3.3 |
| `laravel_direct_payments` | + `archived_at`, `archived_by`, `archive_reason` (PA0); `binding_version` (uint, default 1), `currency` (char 3) (PA2) | PA0/PA2 | 16-safety-rails-implementation.md, 06 |
| `laravel_direct_payment_processor_bindings` | + `kind` (`initial`/`change`/`go_live`/`revert`) (PA0); `refund_window_days` (default 180) (PA2) | PA0/PA2 | 16-safety-rails-implementation.md, 06 §6.9 |
| **new** `laravel_direct_payment_audit_log` | who archived, unarchived, changed processor, went live — append-only | PA0 | 16-safety-rails-implementation.md §1.1 |
| **new** `laravel_direct_payment_scheduled_changes` | | PA2 | 06 §6.8 |
| `laravel_direct_payment_notifications` | `mail_handler_reference_num` nullable (null = default template); `EVENTS` + `payment_receipt` and the four processor-change emails | DE | 15 §15.5 |
| `laravel_direct_payment_items` | + `archived_at`; saves upsert by `reference_num` | PA0 | 05 §5.3 |
| `laravel_direct_payment_tiers` | + `amount_cents`, `currency`, `interval`, `interval_count`, `trial_days`, `archived_at` | PA4 | 05 §5.3 |
| `laravel_direct_payment_tier_bindings` | + `processor_plan_id`, `provisioned_by` (`manual`/`auto`/`import`) | PA4 | 05 §5.3 |
| `laravel_direct_payment_payer_customers` | + `source`, `superseded_reason` | PA3 | 04 |
| `laravel_direct_payment_subscriptions` | `reference_num` stops regenerating (PA0); + `status`, `current_leg_id`, `price_amount_cents`, `price_currency`, `price_interval`, `price_interval_count`, `discount_reference_num`, `discount_cycles_remaining`, `started_at`, `ended_at`; existing `provider_subscription_id`/`processor_reference_num` become a cache of the current leg | PA5 | 07 §7.3 |
| **new** `laravel_direct_payment_subscription_legs` | | PA5 | 07 §7.3 |
| **new** `laravel_direct_payment_migration_runs`, `laravel_direct_payment_subscription_migrations` | | PA6 | 07 §7.6 |
| **new** `laravel_processor_disputes` | | PA0 | 10 §10.4 |
| `laravel_processor_charges` | unused; dropped after a final emptiness check | PA9 | 10 §10.3 |

## 2.5 What stays exactly as it is

- The **façade contract** of modules: a selling module stores a Direct Payment reference, an invoice reference and a
  payer reference, and no money ([`../ecommerce/AUDIT-direct-payments-decoupling.md`](../ecommerce/AUDIT-direct-payments-decoupling.md)).
- `PaymentGateway` and `InvoiceSnapshot` signatures.
- Every provider integration that works today keeps working; nothing is rewritten for style.
- Everything in appfactory studio; its own adjustments are listed separately in [14](14-appfactory-studio-modifications.md).
