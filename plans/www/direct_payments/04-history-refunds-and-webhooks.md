# 04 — History, Refunds and Webhooks

Money already taken lives on the account that took it, forever. This document is the one
invariant that makes that survivable, and the three places that break without it.

## 4.1 The invariant (P5)

> **A provider call about an object that already exists resolves its processor from that
> object. Never from the Direct Payment's current binding.**

The Direct Payment's binding answers exactly one question — *where does the next charge go* —
and no others. Every other question is about something that already happened, and the thing
that happened knows where it happened.

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/04-history-refunds-and-webhooks.md §4.1.
 *
 * The two resolutions, and the rule for choosing.
 *
 *   FORWARD  — "charge this" / "start a subscription"
 *              → BindingRegistry::active($dpRef)
 *
 *   BACKWARD — "refund that" / "cancel that subscription" / "what did this cost"
 *              → the object's own processor_reference_num
 *
 * Every bug this plan fixes is a BACKWARD question answered with a FORWARD
 * lookup. If a method takes an existing reference — an invoice, a charge, a
 * subscription — and then reads the DP's binding, it is wrong.
 */
```

A grep gate enforces it, because this is a rule that decays silently
([06](06-rollout-and-testing.md) §6.5): no method that receives an invoice, charge or
subscription reference may call `BindingRegistry::active()`.

## 4.2 Reading history

Every read already has what it needs, once [02](02-payers-and-bindings.md) §2.5 lands.

| Question | Resolved from |
|---|---|
| What did this order cost? | the invoice's own `processor_reference_num` + `amount_cents` — `PaymentGateway::snapshot()`, unchanged |
| Who paid it? | the invoice's `payer_reference_num` → `laravel_direct_payment_payers` |
| Which provider customer was charged? | the invoice's `customer_id` snapshot, or `payer_customers` filtered to that processor — including `superseded` rows |
| What has this person ever bought? | `payer_reference_num` across invoices — **one query, spanning every processor the tenant has ever used** |
| Was it refunded? | the charge's `processor_reference_num` + `charge_id` |

That fourth row is worth pausing on, because it is the payer's whole point. A customer's
purchase history today is a join through a per-processor customer, so it breaks at every
swap. After this plan it is a single indexed lookup on a value that never changes, and a
tenant who has used three providers in five years still shows one continuous history.

`/me/purchases` ([`../connections/07`](../connections/07-direct-payments-connection.md) §7.6)
therefore joins on the payer rather than on the customer link, and gets simpler.

## 4.3 Refunds (D5)

`ProcessorBillingController::refund()` today resolves the processor from the **request** and
then checks the charge belongs to that processor's customer. After a swap the customer lookup
runs against the new account, misses, and returns `404 Charge not found for this customer` —
so a legitimate refund on a past charge cannot be issued at all.

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/04-history-refunds-and-webhooks.md §4.3 (D5).
 *
 * BACKWARD question (§4.1): the charge decides the account, and the caller's
 * opinion about which processor to use is not consulted at all.
 *
 * A request naming a different processor is not an error to correct — it is
 * ignored, because there is exactly one account this charge can be refunded
 * from and it is not a matter of opinion.
 */
public function refund(Request $request): JsonResponse
{
    $charge = ProcessorCharge::query()->where('charge_id', $request->input('charge_id'))->first()
        ?? ProcessorCharge::find($request->input('charge_id'));
    if ($charge === null) {
        return response()->json(['success' => false, 'message' => 'Charge not found.'], 404);
    }

    $processor = Processor::query()->where('reference_num', $charge->processor_reference_num)->first();
    if ($processor === null) {
        // The processor record was deleted rather than retired. Recoverable only
        // by restoring it — say so instead of failing obscurely.
        return response()->json([
            'success' => false,
            'message' => "This charge was taken on processor {$charge->processor_reference_num}, which no longer exists. "
                       . 'Restore it to refund this charge.',
            'code' => 'DP_E_ORIGIN_PROCESSOR_MISSING',
        ], 409);
    }

    return match ($charge->provider) {                   // already switches on the CHARGE's provider ✅
        'stripe' => $this->refundStripe($charge, $processor->getSecretKey(), $request->input('amount_cents')),
        'square' => $this->refundSquare($processor, $charge, $processor->getSecretKey(), $request->input('amount_cents')),
        'paypal' => $this->refundPayPal($processor, $charge, $processor->getSecretKey(), $request->input('amount_cents')),
        default  => response()->json(['success' => false, 'message' => 'Unsupported provider.'], 422),
    };
}
```

Two consequences worth stating:

- **A retiring binding must keep working for refunds**, which is why §3.7 refuses to retire a
  binding inside its refund window.
- **A processor is retired, never deleted.** The Money ledger
  ([`29`](29-money-and-refunds.md)) already assumes a charge's
  processor is resolvable; this makes that assumption explicit and gives the failure a code
  rather than a stack trace.

## 4.4 Subscription actions (D2)

`cancel()`, `update()` and `setPaymentMethod()`
([`28`](28-subscription-actions.md) §14.2) resolve their
subscription through the Direct Payment. That is a BACKWARD question answered forward, and it
is the most dangerous instance of it: after a swap, a member's cancellation would be sent to
an account where their subscription does not exist, so the provider returns nothing to cancel
while the **old** account keeps billing them.

```php
// BEFORE — the DP decides the account
$directPayment = DirectPayment::where('reference_num', $dpRef)->first();
$processor = Processor::where('reference_num', $directPayment->processor_reference_num)->first();

// AFTER — the subscription decides the account (02 §2.5's new column)
$link = DirectPaymentSubscription::where('reference_num', $subRef)->firstOrFail();
$processor = Processor::where('reference_num', $link->processor_reference_num)->firstOrFail();
```

The `{dpRef}` in the route stays, because it scopes and authorises the call. It stops being
where the account comes from.

**`update()` has a second BACKWARD question hiding in the same call, and it is not the
account — it is the price.** Fixing which processor to call and then asking that tier row for
a price id is only correct while the tier row's price has never been repointed either. It is
D10, and [07](07-tier-bindings.md) §7.3 is its fix:

```php
// AFTER, complete — account AND price resolved from the subscription's own history,
// never from the Direct Payment's or the tier's current state (07 §7.3).
$processor = Processor::where('reference_num', $link->processor_reference_num)->firstOrFail();
$binding   = $this->tierBindings->priceForExistingSubscription($link);
$this->actionsFor($processor->provider)->update($processor, $subscription, $binding->processor_tier_id, $proration);
```

## 4.5 Webhooks (D7)

The Stripe path resolves its processor by scanning `Processor::where('provider','stripe')
->where('is_active', true)->get()` and trying each candidate's signing secret. So a processor
that a tenant deactivates after a swap stops being a candidate, and **every webhook for money
still outstanding on it is silently dropped** — refunds, disputes, subscription renewals,
payment failures.

Three changes:

| Change | Detail |
|---|---|
| **A retired processor stays `is_active`** until its binding is retired (§3.7). "Active" in the processor table means *reachable*, not *accepting new charges*; the binding is what decides new charges now | The admin's processor list shows `Retiring — accepts no new charges` so the flag's meaning is not confusing |
| **The candidate scan includes processors bound to a `retiring` binding**, explicitly, rather than depending on `is_active` alone | One `orWhereIn` on the processors named by non-retired bindings |
| **A webhook that matches no candidate is recorded, not discarded** | A `laravel_processor_webhook_orphans` row with the payload hash, provider and received time. Today an unmatched webhook vanishes; after a swap that is exactly when they appear, and an operator needs to see the count |

The orphan table is small and self-limiting: it is empty in normal operation, and a non-zero
count is the signal that a binding was retired too early.

## 4.6 What an operator sees

The Money ledger ([`29`](29-money-and-refunds.md) §15.5) gains one
column and one filter:

- **Processor** — which account took this money. Invisible until a tenant has more than one
  binding on any Direct Payment, then always shown, because "why did this refund go somewhere
  else" is otherwise unanswerable.
- **Customer** resolves through the **payer** rather than the processor customer, so a
  person's ledger rows are continuous across a swap. Where Site Users is connected, that payer
  resolves further to an account
  ([`../connections/07`](../connections/07-direct-payments-connection.md) §7.6).

## 4.7 Acceptance

- A refund on a charge taken before a swap succeeds against the **old** account, with the
  request naming the new processor, the old one, or neither.
- A refund on a charge whose processor record was deleted returns
  `409 DP_E_ORIGIN_PROCESSOR_MISSING` naming the reference, not a 500.
- Cancelling a subscription taken before a swap cancels it **at the old provider**, and the
  old provider stops billing — verified by the provider's own response, not by our row.
- **Upgrading** a subscription taken before a swap sends the **old** processor's price id to
  the **old** processor, even after the tenant has re-priced every tier for the new one
  ([07](07-tier-bindings.md) §7.3).
- A webhook from a retiring binding's processor is accepted and attributed.
- A webhook matching no candidate creates an orphan row; the count is visible in the admin.
- `payer_reference_num` returns a person's full purchase history across two bindings in one
  query.
- The grep gate is clean: no method taking an invoice, charge or subscription reference calls
  `BindingRegistry::active()`.
