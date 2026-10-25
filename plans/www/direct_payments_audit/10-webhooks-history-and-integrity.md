# 10 — Webhooks, History and Data Integrity

The parts that make "every purchase can be referenced back to its provider" true for years, across any number of swaps.
Fixes **A36**, **A37**, **A55–A58**.

## 10.1 Webhooks for every processor, forever (A55, A56)

| Change | Detail |
|---|---|
| **Candidate rule** | Every processor of that provider — regardless of `is_active`, `archived_at` or binding status; one without a secret (or PayPal webhook id) is skipped by the verifier ([03](03-processor-lock-in-and-archive.md) §3.5; PA0, [16](16-safety-rails-implementation.md) §5.3) |
| **Per-processor URL** | New registrations use `/portal/webhooks?type={provider}&p={processor_ref}`. The handler verifies against that processor's secret first; the scan remains only for endpoints registered before this change. `WebhookUrl` builds it; `WebhookRegistrars` re-registration is offered in the admin per processor |
| **Orphans always counted** | `ProcessorWebhookOrphan::create()` moves before the rejection rate-limiter (**A56**) |
| **Webhook registration cannot be removed while needed** | [03](03-processor-lock-in-and-archive.md) §3.5 |
| **Connect events** | unchanged: resolved through the connected account's processor |

## 10.2 A subscription ended at the provider ends here too (A36)

Today `customer.subscription.deleted` (and Square's / PayPal's equivalents — `logSquareSubscription()`,
`logPayPalSubscription()`) update `laravel_processor_subscriptions` only.

**PA0** fixes the contract side with one observer on that table (`ProcessorSubscriptionObserver` →
`SubscriptionEndSync::providerEnded()`, [16](16-safety-rails-implementation.md) §5.2): every webhook and the sync
sweep that marks a provider subscription ended sets the contract's `canceled_at` and `access_ends_at`. **PA5** extends the
same entry point to legs (`SubscriptionLegLifecycle::providerEnded(leg, endedAt, reason)`):

1. Leg → `ended`, `ended_at` set, `renewing_key` cleared.
2. If the leg was the subject of a migration in `scheduled` state, that is the expected hand-over — nothing else.
3. Otherwise, if the contract has no other live leg: contract → `ended`, `canceled_at` set if not already, and
   `access_ends_at` = the leg's paid-through moment (or now, per the DP's cancellation-access policy).
4. The existing `subscription_canceled` notification is unchanged — the webhook handlers already send it.

This is what lets `BindingRegistry::canRetire()` reach zero and the swap preview count only real live subscriptions.

## 10.3 One charge record, one meaning for `customer_id` (A57)

- `ProcessorCharge` stays a model over `laravel_direct_payment_invoices` (that consolidation already happened); the empty
  `laravel_processor_charges` table is dropped in PA9 after a final `COUNT(*) = 0` check.
- `laravel_direct_payment_invoices.customer_id` is documented as **the provider customer id snapshot** — the value the
  provider knew — on every write path. The three webhook recorders that write the local `laravel_processor_customers.id`
  (`ProcessorWebhookController.php:560/590`, `:1115/1150`, `:1765/1787`) change to the provider id.
- Every new invoice also gets `payer_reference_num` from the server (the leg's or the payer customer's payer), so the
  person is never inferred from `customer_id` again.
- `ProcessorBillingController::refund()`'s ownership check compares like with like (provider id on both sides), or,
  better, the endpoint is retired in favour of the authenticated Money refund (`MoneyController::refund()` →
  `RefundService`), which is already correct.

## 10.4 Disputes are stored (A58)

New `laravel_processor_disputes`: processor, provider, provider_dispute_id (unique per processor), invoice id, amount,
currency, reason, status (`open`/`won`/`lost`/`closed`), evidence due date, opened/closed timestamps. Written by the three
providers' dispute handlers (today they only log). Read by `canRetire()` ("open disputes"), by the Money ledger, and by
the archive warning ([03](03-processor-lock-in-and-archive.md) §3.2).

## 10.5 Renewals find their contract through legs

`SubscriptionInvoiceLinker` resolves a renewal's contract by `(processor_reference_num, provider_subscription_id)` on the
legs table instead of `provider_subscription_id` on the contract — so a renewal on leg 2 links to the same contract as a
charge on leg 1. Every `ProcessorSubscription` lookup by provider id is scoped by processor (**A37**).

## 10.6 History reads, across any number of swaps

| Question | Answer from |
|---|---|
| Which account took this money? | invoice `processor_reference_num` (+ provider) — shown in the Money ledger whenever a DP has more than one binding |
| Who paid? | invoice `payer_reference_num` → payer |
| Everything this person ever bought | `payer_reference_num` across invoices — one indexed query over every provider |
| Every period of this subscription | contract → legs → invoices per leg |
| Can this be refunded, and where? | `ProcessorRouter::forExisting(invoice)` — works for archived processors |

## 10.7 Acceptance

- A webhook signed by an archived processor's secret is processed; a webhook burst with bad signatures still counts
  every orphan.
- Cancelling a subscription in the Stripe / Square / PayPal dashboard ends the leg and, when it was the only one, the
  contract; the binding then becomes retirable.
- No write path stores a numeric local customer id in `invoice.customer_id`.
- A dispute opened on a retiring binding blocks its retirement until closed.
- A renewal charged on leg 2 links to the contract created with leg 1.
