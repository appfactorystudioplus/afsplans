# 01 — Current State Audit

Read from `w/www` on 2026-09-12. Paths are relative to `w/www/`. Line numbers will drift; the
symbol names will not.

## 1.1 How the layers are keyed

```
  laravel_direct_payments
    reference_num              ← the stable handle a module stores
    processor_reference_num    ← ONE processor, a plain mutable column          ⚠ D1
    pay_type                   SINGLE_PAY | SUBSCRIPTION | CONNECT

  laravel_processors
    reference_num (7)          one account, one provider, one mode

  laravel_processor_customers
    id (bigint)                                    ← charges and subscriptions join here
    processor_reference_num, provider, mode        ← the customer IS per-processor            ⚠ D3
    email, provider_customer_id
    unique(processor_reference_num, provider, mode, provider_customer_id)

  laravel_direct_payment_invoices
    reference_num
    direct_payment_reference_num
    processor_reference_num    ← snapshotted ✅
    customer_id (string)       ← the RAW PROVIDER customer id, set by the client              ⚠ D4
    charge_id, event_identifier, amount_cents, status

  laravel_processor_charges
    processor_reference_num    ← snapshotted ✅
    customer_id (bigint) → laravel_processor_customers.id
    provider, charge_id

  laravel_direct_payment_subscriptions
    direct_payment_reference_num
    provider_subscription_id
    member_reference_num, direct_payment_tier_reference_num, access_ends_at
    ── NO processor_reference_num ──                                                          ⚠ D2

  laravel_direct_payment_tiers
    direct_payment_reference_num
    reference_num               ← REGENERATED on every saveDirectPayment() call               ⚠ D9
    processor_tier_id           ← ONE raw provider price/plan id, no processor of its own      ⚠ D10
    level

  laravel_site_user_customer_links      (Site Users, plans/www/site_users/08)
    site_users_id, user_id / site_user_reference
    processor_reference_num, provider, provider_customer_id                                   ⚠ D4
```

Two of the four money tables snapshot their processor. The two that matter most for a swap —
subscriptions, and the customer itself — do not. Tiers are worse than either: not only do
they carry no processor of their own, their own identity does not survive an ordinary edit.

## 1.2 The findings — D1 to D11

| # | Finding | Where | Consequence |
|---|---|---|---|
| **D1** | **A Direct Payment's processor is a mutable column with no ceremony.** `saveDirectPayment()` does `DirectPayment::firstOrNew(['reference_num' => $ref])` and fills `processor_reference_num` from the request | `DirectPaymentsController::saveDirectPayment()` | Any authenticated caller re-points a live Direct Payment with one field. No warning, no dry run, no record of what it was, and none of D2–D5's consequences are handled. **This is the whole problem in one line of code** |
| **D2** | **`laravel_direct_payment_subscriptions` has no processor reference.** It stores `direct_payment_reference_num` and `provider_subscription_id`, so which account a subscription lives on is resolved *through the Direct Payment's current binding* | that table; `DirectPaymentSubscriptionController` | **The sharpest breakage.** The instant a DP is re-pointed, every live subscription resolves to the new account, where its `provider_subscription_id` does not exist. `cancel()`, `update()` and `setPaymentMethod()` then act against the wrong account — at best a provider 404, at worst a lookup that matches something else. A member's cancellation would silently not happen while the old account kept billing |
| **D3** | **A provider customer is per-processor by construction.** Lookup is `(processor, provider, mode, email)`; uniqueness is `(processor, provider, mode, provider_customer_id)` | `ProcessorCustomer::findOrCreateForEmail()` | A swap orphans every customer with no forwarding address. Nothing records that the customer on the new processor is the same person as the one on the old |
| **D4** | **The implementer stores a provider-issued identifier.** `invoices.customer_id` holds the raw `cus_…` string the client read from `sessionStorage`; `laravel_site_user_customer_links` holds `provider_customer_id` | `saveInvoice()`; `SiteUsersPublicController::linkCustomer()`; `direct_payments/api.ts` `SESSION_KEY_CUSTOMER_ID` | The abstraction leaks by construction. **This is the finding the whole plan turns on** — see [00](00-overview.md) §3 |
| **D5** | **A refund resolves its processor from the request, not from the charge.** `refund()` calls `resolveProcessorAndCustomer($request)`, then checks `$charge->customer_id === $customer->id` | `ProcessorBillingController::refund()` | After a swap, a refund on an old charge fails: the customer lookup runs against the new processor and misses, giving `404 Charge not found for this customer`. Safe by accident — it refuses rather than refunding from the wrong account — but an operator cannot refund a legitimate past charge at all |
| **D6** | **Nothing anywhere says a stored card cannot move**, while [`../ecommerce/07`](../ecommerce/07-direct-payments-integration.md) §7.2 promises "no re-keying" | that section; no counter-statement exists | A tenant reads the promise, swaps, and discovers 38 customers have no payment method. The cost is not the engineering; it is that nobody warned them |
| **D7** | **The Stripe webhook path resolves its processor by scanning active candidates** — `Processor::where('provider','stripe')->where('is_active',true)->get()` — and tries each | `ProcessorWebhookController` | If a tenant deactivates the old processor after a swap, webhooks for money already taken on it stop being attributable: refunds, disputes and subscription lifecycle events on the old account are silently dropped |
| **D8** | **There is no swap surface at all.** No preview, no impact count, no audit row, no state | admin UI; `saveDirectPayment()` | An operation with the consequences of D2–D7 is currently a dropdown and a save button |

### Found in the plan review pass (2026-09-12) — D9, D10

Both found by tracing `laravel_direct_payment_tiers`, which nothing before this pass had
followed all the way through. Both are pre-existing defects, independent of any swap.

| # | Finding | Where | Consequence |
|---|---|---|---|
| **D9** | **A tier's identity does not survive a save.** `saveDirectPayment()` runs `DirectPaymentTier::where('direct_payment_reference_num', $ref)->delete()` and recreates every tier with `'reference_num' => Str::random(12)` — a **fresh, unrelated** reference each time, not an upsert keyed on anything stable | `DirectPaymentsController::saveDirectPayment()` | Every existing subscription's `direct_payment_tier_reference_num` goes stale the next time anyone edits that Direct Payment's tier list — **with no swap involved at all**. `DirectPaymentSubscriptionController::update()`'s tier lookup (`where('reference_num', $tierRef)`) then returns nothing and the plan-change action 404s. Entitlement itself partially self-heals — `MembershipTierResolver::deriveTierPaid()` has a fallback that re-derives the level by matching the processor's own plan id — but the *stored reference* stays dangling, and nothing that resolves a tier by reference alone has an equivalent fallback |
| **D10** | **A tier's `processor_tier_id` is one global column — a raw provider price/plan id — with no processor of its own.** The tier is scoped to the Direct Payment; the price object inside it is scoped to whichever processor happened to be active when someone typed it in | `laravel_direct_payment_tiers.processor_tier_id`; read by `DirectPaymentSubscriptionController::update()`, the SDK's `plan_id`, `MembershipTierResolver`, `BillingBackfillDpSubscriptions` | **D2's exact shape, one layer up.** After a swap, every tier's price id names an object on the account the tenant left. A **new** subscription sends that dead id to the new processor and fails at checkout. An **existing** subscription being upgraded correctly reaches the *old* processor (D2's fix resolves that part) but sends whatever price id the tier row *currently* holds — which, the moment a tenant reconfigures pricing for the new processor, is the **new** processor's id, sent to the **old** account. [07](07-tier-bindings.md) is the fix |
| **D11** | **A guest checkout writes a payer reference to the cart before anyone knows whether the shopper will sign in.** [`../connections/06`](../connections/06-checkout-guard.md) §6.7 already merges the cart's *lines* and re-attributes `site_user_reference` when a guest signs in mid-checkout, but nothing reassigns the cart's `payer_reference_num` at the same moment | [05](05-implementer-contract.md) §5.2's new column; the connections plan's sign-in gate | Left alone, the resulting order is owned by the account (`site_user_reference` correct) but paid-as a payer nobody else will ever see again (`payer_reference_num` still the discarded guest one) — the shopper's own purchase history, keyed on payer ([04](04-history-refunds-and-webhooks.md) §4.2), silently splits across two payers for the one session where they happened to sign in partway through |

## 1.3 What already works, and is worth not breaking

Checked, because the plan builds on each:

- **Invoices and charges snapshot their processor.** `invoices.processor_reference_num` and
  `charges.processor_reference_num` are both written at creation. Half the history problem is
  already solved; [02](02-payers-and-bindings.md) §2.5 finishes it.
- **The façade contract is sound.** A selling module stores three references and no money, and
  resolves every figure through `PaymentGateway`
  ([`../ecommerce/AUDIT`](../ecommerce/AUDIT-direct-payments-decoupling.md)). That contract is
  unchanged by this plan — only the customer reference inside it changes.
- **The processor layer is already provider-agnostic at the call site.**
  `ProcessorClient` dispatches to `StripeClient` / `SquareClient` / `PayPalClient`, and
  `refund()` already switches on `$charge->provider` rather than assuming. The provider
  abstraction is real; it is the *identity* abstraction that is missing.
- **`laravel_processor_charges.customer_id` is a local bigint**, not a provider id. The
  processor layer already understood that a local handle beats a provider one. Direct Payments
  did not inherit the lesson.
- **The customer resolver interface exists.** The connections plan added
  `DirectPaymentsCustomerResolver` so a host page can tell the SDK who is paying
  ([`../connections/07`](../connections/07-direct-payments-connection.md) §7.3). This plan
  changes what it returns — a payer reference rather than a provider customer id — and
  changes nothing about its shape.

## 1.4 What a swap does today, step by step

Worth walking, because it shows that no single component is wrong — the failure is that
nothing owns the transition:

1. An operator edits the Direct Payment and picks a different processor. `saveDirectPayment()`
   writes the column. **(D1)**
2. The next checkout calls `_createAnonymousCustomer()` or the resolver, which looks up a
   customer for the *new* processor, finds none, and creates one. The shopper is now a
   different customer with no cards. Nothing links them to their old one. **(D3, D6)**
3. The client stamps the new `cus_…` on the invoice. Old invoices keep the old one. Nothing
   knows the two are the same person. **(D4)**
4. A member cancels a subscription taken out last year. The controller resolves the DP, gets
   the *new* processor, and calls it with a `provider_subscription_id` from the old account.
   **(D2)**
5. An operator refunds a charge from last year. The customer lookup runs against the new
   processor and misses. `404`. **(D5)**
6. The tenant tidies up and deactivates the old processor. Webhooks for the old account's
   outstanding subscriptions stop resolving. **(D7)**

Steps 4, 5 and 6 are all invisible until someone tries the thing that fails.
