# 04 — `ChargeSucceeded`: one hook for every successful charge

Status: DONE 2026-10-15 for the hook, the five call sites, decisions A (PayPal capture, Stripe `charge.succeeded`), gap 7 (Square) and the
two invoice lookups (gaps 4 and 11). Gaps 5, 6, 8, 9, 10, 13 and 14 are not fixed (see "Done 2026-10-15" at the end). Code blocks above that
section show the design; the code in `w/www` is the source of truth.

Files (all under `/Users/jamesmitchell/Desktop/projects/vagrant/afs-eco/w/www/`):

* new: `app/Externals/DirectPayments/ChargeSucceeded.php`
* called from: `app/Http/Controllers/Externals/Processor/ProcessorWebhookController.php` and
  `app/Http/Controllers/Externals/Processor/ProcessorIntentController.php`

## 1. Goal

Every successful charge, from any provider and of any kind (subscription or single payment), runs the same hook once
the charge has been recorded on its `DirectPaymentInvoice`. What the hook does is set in
[07-direct-payment-email.md](07-direct-payment-email.md): it queues a delayed job that sends the mail handlers attached to the
invoice's Direct Payment. This plan only adds the seam and its call sites.

## 2. The hook (one new class, because two controllers call it)

`app/Externals/DirectPayments/ChargeSucceeded.php`

```php
<?php

namespace App\Externals\DirectPayments;

use App\Models\Externals\DirectPaymentInvoice;
use Illuminate\Support\Facades\Log;

/**
 * Runs for every successful charge, whatever the provider or charge type.
 * $invoice is the saved DirectPaymentInvoice: direct_payment_reference_num, user_id, payer_reference_num,
 * amount_cents, currency, customer_email, metadata (card_brand, last4).
 * Never throws and never changes the caller's result.
 */
class ChargeSucceeded
{
    public static function handle(DirectPaymentInvoice $invoice): void
    {
        try {
            // queue the delayed mail job; what it sends is in plan 07
            SendDirectPaymentMail::dispatch($invoice->reference_num)
                ->delay(now()->addSeconds((int) config('direct_payments.mail_delay_seconds', 30)));
        } catch (\Throwable $e) {
            Log::error('[ChargeSucceeded] failed', [
                'invoice' => $invoice->reference_num,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
```

## 3. Where it is called (current line numbers)

| File | Handler | Event(s) | Call goes after | Line |
|---|---|---|---|---|
| `ProcessorWebhookController.php` | `recordStripeCharge()` | Stripe `charge.succeeded` (subscription and single payments) | `$invoice->save();` (before the `// TODO: Send payment-success` comment); the early return at line 471 changes, see 4b | 529 |
| `ProcessorWebhookController.php` | `recordStripeInvoiceCharge()` | Stripe `invoice.payment_succeeded` | **no call** (decision A, 4b: it records the invoice only) | 595 |
| `ProcessorWebhookController.php` | `recordSquareCharge()` | Square `payment.updated` with `status = COMPLETED` (see gap 7; today routed from the non-existent `payment.completed`) | `$invoice->save();` | 1096 |
| `ProcessorWebhookController.php` | `recordPayPalCharge()` | PayPal `PAYMENT.CAPTURE.COMPLETED`, `CHECKOUT.ORDER.COMPLETED` | `$invoice->save();` | 1677 |
| `ProcessorIntentController.php` | `capturePayPalOrder()` | PayPal one-time capture call (decision A, section 4a) | `$invoice->save();` inside `if ($invoice)` | ~695 |

Each call is the one line `ChargeSucceeded::handle($invoice);`, with `use App\Externals\DirectPayments\ChargeSucceeded;`
added to the file. The existing `NotificationDispatcher` block and the Stripe-only `ProcessorSubscription` lookup stay
where they are.

## 4. Known gaps that stop it firing once per charge (not fixed by this plan)

1. **Stripe sends two events per subscription payment** (`charge.succeeded` and `invoice.payment_succeeded`). Each
   handler returns early when the `charge_id` already exists (lines 471 and 545). RESOLVED by decision A (section 4b): only
   `charge.succeeded` fires the hook.
2. **Early return on retries.** A retry returns early and does not re-fire. A crash between `save()` and the hook means
   that charge never fires it.
3. **PayPal one-time:** `capturePayPalOrder()` (`ProcessorIntentController.php:594`) saves `charge_id` before the
   webhook arrives, so `recordPayPalCharge()` returns early (line 1636) and never reaches the hook. RESOLVED by
   decision A (section 4a): the capture call fires the hook itself, and the early-returning webhook does not fire it a
   second time.
4. **PayPal webhook invoice lookup** uses `event_identifier = custom_id`, but `custom_id` is the invoice's
   `reference_num`, so it never matches.
5. **Square one-time:** the browser posts to `/portal/api/v1/processors/square/create-payment`, which has no route or
   controller, so there is no Square single charge to hook.
6. **PayPal and Square subscriptions:** no payment handler records an invoice (PayPal subscription events are only
   logged; Square subscription payments are matched by a customer-pending guess).

7. **Square never records a charge: `payment.completed` is not a Square event.** Square's Payments API has only
   `payment.created` and `payment.updated`
   ([webhooks](https://developer.squareup.com/docs/payments-api/webhooks),
   [reference](https://developer.squareup.com/reference/square/payments-api/webhooks)). A completed payment arrives as
   `payment.updated` with `data.object.payment.status = "COMPLETED"`. `handleSquare()` routes `payment.completed` to
   `recordSquareCharge()` (never fires) and `payment.updated` to `logSquarePaymentUpdate()` (log only), so no Square
   invoice is ever marked succeeded and the hook would never run for Square.

   Example fix, in `handleSquare()` (current line ~970). Not applied:

   ```php
   'payment.updated'
   => (($payload['data']['object']['payment']['status'] ?? null) === 'COMPLETED')
       ? $this->recordSquareCharge($payload, $processorId)
       : $this->logSquarePaymentUpdate($payload, $processorId),
   ```

   `payment.created` also fires and can carry another status; it stays unhandled. `payment.updated` can be delivered
   more than once; `recordSquareCharge()`'s existing `charge_id` early return covers that.

## 4a. PayPal gaps (gaps 3, 4 and 6 above, expanded, plus 8-10)

PayPal has two flows and they fail differently.

### One-time payment (gaps 3 and 4)

Today (`ProcessorIntentController.php`):

* `createPayPalOrder()` (lines 544-546) sets `purchase_units[0].custom_id = <invoice reference_num>`.
* `capturePayPalOrder()` (line 594) captures the order, finds the invoice by `reference_num = custom_id`, and saves
  `status = 'paid'`, `charge_id` and `provider_response`. `event_identifier` is not set (the line is commented out).
* Later, `PAYMENT.CAPTURE.COMPLETED` arrives. `recordPayPalCharge()` returns early at line 1636 because the
  `charge_id` already exists, so it never reaches the hook.
* If the early return did not exist, its invoice lookup (line 1644-1647) is `event_identifier = custom_id`, but
  `custom_id` is the invoice's `reference_num`, so it would never match.

Example fix, in `recordPayPalCharge()`. Not applied:

```php
// find the invoice the way capturePayPalOrder() does: custom_id IS the invoice reference_num
$invoiceRef = $resource['custom_id'] ?? ($resource['invoice_id'] ?? null);
$invoice    = $invoiceRef ? DirectPaymentInvoice::where('reference_num', $invoiceRef)->first() : null;
```

Gap 3 (the early return): both the capture call and the webhook can reach the same charge.

**DECIDED: fire the hook from `capturePayPalOrder()` as well.** The capture call already saves the invoice, so it calls
the same hook. Rejected: changing the webhook's early return (needs a "hook already ran" record that does not exist),
and leaving it (the hook would never fire for PayPal one-time payments).

* Call site: `ProcessorIntentController::capturePayPalOrder()`, right after the existing `$invoice->save();`
  (line ~695, inside `if ($invoice) { ... }`), so it runs only when the invoice was found:

  ```php
  $invoice->save();
  ChargeSucceeded::handle($invoice);   // NEW
  ```

* Fires once per charge: the webhook that follows returns early at `recordPayPalCharge()` line 1636 (charge_id
  already saved), so it does not fire a second time.
* Consequence: the hook is called from two controllers, which is why it is the `ChargeSucceeded` class (sections 2 and 3)
  and not a private method.
* Still open for this flow: the invoice saved by the capture call has `status = 'paid'` (finding 8).

### Subscription payments (gap 6, plus 8)

* PayPal sends the recurring payment as **`PAYMENT.SALE.COMPLETED`**, "A payment is made on a subscription"
  ([event names](https://developer.paypal.com/api/rest/webhooks/event-names/)). `handlePayPal()` does not route it
  (it routes only `PAYMENT.CAPTURE.*`, `CHECKOUT.ORDER.COMPLETED` and `BILLING.SUBSCRIPTION.*`), so it falls to
  `default => false` and nothing is recorded.
* `BILLING.SUBSCRIPTION.*` events go to `logPayPalSubscription()`, which only updates the `ProcessorSubscription`
  row and dispatches `payment_failed` / `subscription_canceled`.
* The field names inside a `PAYMENT.SALE.COMPLETED` body (amount, the subscription id as `billing_agreement_id`,
  `custom`) are from memory; PayPal's docs pages I could reach do not show a sample. They must be confirmed against a
  sandbox event before any code reads them.
* `createPayPalSubscription()` (`ProcessorSubscriptionController.php:502`) sends no invoice reference to PayPal. The
  browser sets `invoice.event_identifier = subscription_id` afterwards. PayPal's API lists `custom_id` as an
  updatable subscription field, so it very likely exists on creation too; I could not read the create-request schema to
  confirm.

Example, in `handlePayPal()`. Not applied:

```php
'PAYMENT.SALE.COMPLETED'
=> $this->recordPayPalSale($payload, $processorId),
```

`recordPayPalSale()` would find the invoice by the subscription id the sale carries
(`event_identifier = <subscription id>`, which the browser already stores), record it as `recordPayPalCharge()`
does, and call the hook. Only the first sale of a subscription matches a stored invoice; later renewals
have none and would need a new invoice row (same as Stripe renewals today).

### Other PayPal findings

8. **Status mismatch.** `capturePayPalOrder()` saves `status = 'paid'`; the webhooks save `'succeeded'`. Some code
   accepts both (`InvoiceSnapshot.php:41`, `DirectPaymentsController.php:186`, `AppointmentPaymentService.php:150`),
   but `SwapService.php:151` and `BindingRegistry.php:179` filter on `'succeeded'` only, so they miss PayPal
   one-time invoices.
9. **Event names.** `handlePayPal()` routes `PAYMENT.ORDER.APPROVED` / `PAYMENT.ORDER.CANCELLED`. PayPal's
   checkout events are named `CHECKOUT.ORDER.APPROVED` (and `CHECKOUT.ORDER.COMPLETED`, which is routed). Confirm
   which names the registered webhook actually sends before changing it.
10. **Card brand and last4.** No PayPal webhook sample shows them; a wallet payment has no card. If wanted, read
    `payment_source.card` from the order returned by `capturePayPalOrder()`; otherwise the email shows "PayPal".

### Card brand and last4 per provider (from provider docs)

| Provider | In the success webhook? | Path |
|---|---|---|
| Stripe | yes, in `charge.succeeded` only | `data.object.payment_method_details.card.brand` / `.last4` |
| Square | yes, in `payment.updated` | `data.object.payment.card_details.card.card_brand` / `.last_4` |
| PayPal | no official sample shows it; wallet payments have no card (`payment_source.paypal`) | unconfirmed; the capture response in `capturePayPalOrder()` may carry `payment_source.card` |

`recordSquareCharge()` does not read the Square card fields yet.

## 4b. Stripe gaps (gaps 1 and 2 above, expanded, plus 11-14)

Evidence below marked "dev DB" was read from `laravel_processor_webhooks` and `laravel_direct_payment_invoices` on the
dev server (read-only). The dev DB has no `charge.succeeded` or `invoice.payment_succeeded` events yet, only the earlier
`invoice.created/finalized/updated/voided` events of one subscription signup. Stripe API version on those events:
`2023-10-16`.

### Two events for one subscription payment (gap 1)

A subscription payment sends `charge.succeeded` (→ `recordStripeCharge()`) and `invoice.payment_succeeded`
(→ `recordStripeInvoiceCharge()`), in either order. Both return early when the `charge_id` already exists (lines 471,
545). A single payment sends only `charge.succeeded`.

What goes wrong today, independent of the hook:

* Invoice event first: it saves the invoice with `charge_id`. `charge.succeeded` then returns early at line 471 and
  **never saves `card_brand` / `last4`** (only `recordStripeCharge()` writes them).
* Charge event first: the invoice event returns early; nothing is lost, but the subscription facts
  (`billing_reason`, `subscription`) are not seen.

**DECIDED: option A.** Stripe fires the hook from `charge.succeeded` only (`recordStripeCharge()`). It exists for every
successful Stripe charge, subscription or single, and is the only event with brand and last4.
`recordStripeInvoiceCharge()` keeps recording the invoice but does NOT fire the hook.

* `recordStripeCharge()` must not skip when the invoice event got there first: if the invoice exists but its `metadata`
  has no `card_brand` key, it merges the card fields and fires. Its own write of `card_brand` is the "already ran"
  marker, so no new column is needed.
* Rejected: firing from whichever event arrives first (brand/last4 are lost when the invoice event is first), and using
  the invoice event for subscriptions and the charge event for single payments (two rules for one concept).

Example, in `recordStripeCharge()` (replaces the early return at line 471). Not applied:

```php
$existing = DirectPaymentInvoice::where('charge_id', $chargeId)->whereNotNull('charge_id')->first();
if ($existing) {
    $meta = is_string($existing->metadata) ? json_decode($existing->metadata, true) : ($existing->metadata ?? []);
    if (array_key_exists('card_brand', (array) $meta)) return true;     // this event already ran for the charge
    $invoice = $existing;                                               // invoice event got here first: fill the card fields and fire
}
```

Then the existing code continues to `$invoice->save()` and `ChargeSucceeded::handle($invoice);`. (The remaining
`$invoice = null;` lookups only run when `$invoice` is not already set.)

### Finding the invoice (gap 11)

`recordStripeInvoiceCharge()` looks up `$obj['metadata']['invoice_reference_num']` (line 552). **dev DB:** on the invoice
object `metadata` is `[]`; the reference is in `subscription_details.metadata`:

```
invoice.created  data.object.metadata                          = []
invoice.created  data.object.subscription_details.metadata     = {"invoice_reference_num": "inv_pS0IHtJ11tWD"}
```

So lookup 1 misses on the invoice event, and the match falls to lookup 2 (`event_identifier = payment_intent`). That
works today because `invoice.*` events carry `payment_intent` (dev DB) and the server stores the intent id on the
invoice before the webhook (`ProcessorSubscriptionController.php:360-367`). Safer: add the direct lookup first.

```php
$invoiceRef = $obj['metadata']['invoice_reference_num']
    ?? $obj['subscription_details']['metadata']['invoice_reference_num']
    ?? null;
```

Applies to `recordStripeInvoiceCharge()` only. For `charge.succeeded` the metadata of a subscription charge is not
known (no sample in the dev DB); it matches through lookup 2 (`event_identifier = payment_intent`).

### No payer on the checkout invoice (gap 12)

**dev DB:** the stored checkout invoice has `user_id` = the member reference and `event_identifier` = the payment
intent, but `payer_reference_num` is NULL. `saveInvoice()` only resolves a payer when the Direct Payment has a
`website_reference_num` (`DirectPaymentsController.php:66`). So the hook receives `user_id` but no payer. Fixed by
the payer-scope removal in [01-website-reference-num.md](01-website-reference-num.md) §3.5, not here.

### Renewals and unmatched charges (gap 13)

A renewal payment has no stored checkout invoice, so the lookups fall through to a new blank
`DirectPaymentInvoice` with no `direct_payment_reference_num`, `user_id` or `payer_reference_num`. The same happens for
any charge made outside Direct Payments. The hook must treat an invoice without `direct_payment_reference_num` as "not
ours". Filling those fields from the `DirectPaymentSubscription` link (by the invoice event's `subscription`) is
possible only on the invoice event; `charge.succeeded` carries no subscription id. Not planned yet.

### Retries and crashes (gap 14, was gap 2)

`handleStripe()` always answers 200, so Stripe does not retry. A crash between `$invoice->save()` and the hook means
that charge never fires it; the `ProcessorWebhook` row then stays `processed = false`, which is the only trace.
Accepted for now.

## 4c. Domain purchases also reach the hook

A domain purchase made in the members dashboard pays through the same www code, so every Stripe domain payment runs
`recordStripeCharge()` and therefore `ChargeSucceeded::handle()`. Facts (details and file references in
[06-members-processor-removal.md](06-members-processor-removal.md) §2):

* The members browser builds the invoice with the Direct Payment from `VITE_DIRECT_PAYMENT_ID` (dev: `eq4W10iF8`) and the
  purchase item `VITE_DIRECT_PAYMENT_PURCHASE_ITEM_ID`, and charges with `user_id` = the member reference
  (`resources/js/controllers/domains/purchase.ts`). The members app only proxies to www; its own processor controllers are
  never routed.
* Dev: `eq4W10iF8` is bound (active) to the Stripe sandbox processor `2uDJGCr`. No domain Direct Payment is bound to
  PayPal or Square, so domain purchases are Stripe-only today.

Consequences for the hook:

* A signup payment and a domain payment arrive as the same kind of invoice, both with `user_id` = member reference and
  no payer. They differ by `direct_payment_reference_num`, and the hook sends only the mail handlers attached to that
  Direct Payment (plan 07), so nothing in the hook needs to tell them apart.
* Domain invoices carry no `subscription`; they are single charges. Under decision A they still fire the hook from
  `charge.succeeded`. The domain Direct Payment has no mail handler attached, so nothing is sent; if one is attached later,
  it will fire for domain purchases too.

## 5. Open

* Whether gaps 4-10 and 11-13 are fixed before or after the hook is used (gaps 1 and 3 are decided; gap 14 is accepted).
* `ChargeSucceeded::handle()` is no longer open: it queues the delayed mail job (plan 07 section 2.1).

## Done 2026-10-15

All in `w/www`, committed, nothing pushed. Tests: `tests/Feature/DirectPayments/ChargeSucceededHookTest.php` (7 tests; the Stripe, Square and PayPal ones
fail when the matching fix is removed). Direct Payments, Processor and DirectPayments unit tests: 116 of 116 on a throwaway local MySQL.

* **New:** `app/Externals/DirectPayments/ChargeSucceeded.php`, `app/Jobs/SendDirectPaymentMail.php`, `config/direct_payments.php` (`mail_delay_seconds` = 30).
* **Hook:** returns without doing anything when the invoice has no `direct_payment_reference_num` (gap 13: not ours). It claims each charge in the cache
  (`charge-succeeded:<invoice>:<charge_id>`, 24 h, no new table) so a duplicate delivery, or the PayPal capture call and its webhook arriving together, queues one job.
  This claim is an addition to the plan text; the plan relied on the webhook's early return alone, which does not cover the case where the webhook arrives before the capture call has saved.
* **Call sites** (`ChargeSucceeded::handle($invoice)` after `$invoice->save()`, `TODO: Send payment-success` comments removed): `recordStripeCharge()`,
  `recordSquareCharge()`, `recordPayPalCharge()` in `ProcessorWebhookController`, and `capturePayPalOrder()` in `ProcessorIntentController`.
  `recordStripeInvoiceCharge()` has no call (decision A).
* **Stripe (4b):** `recordStripeCharge()` no longer returns early just because the invoice event recorded the charge first; it continues unless `metadata.card_brand`
  is already there, then adds brand and last4 and fires. `recordStripeInvoiceCharge()` also reads `subscription_details.metadata.invoice_reference_num` (gap 11).
* **Square (gap 7):** `payment.updated` with `status = COMPLETED` goes to `recordSquareCharge()`; other statuses stay in `logSquarePaymentUpdate()`. The dead `payment.completed` route is left in place.
* **PayPal (gap 4):** `recordPayPalCharge()` finds the invoice by `reference_num = custom_id` first, then by `event_identifier` as before.
* **`SendDirectPaymentMail`** is the seam only: it loads the invoice and does nothing else until plan 07 puts `DirectPaymentCommunicationService::paymentSucceeded()` in `handle()`.
  Until plan 08's scheduled `queue:work` exists, the queued jobs wait in the queue table.
* **Not fixed:** 5 (no Square single-charge route), 6 (PayPal/Square subscription payments: `PAYMENT.SALE.COMPLETED` needs a sandbox sample first), 8 (PayPal `paid` vs `succeeded`
  in `SwapService` and `BindingRegistry`), 9 (PayPal event names), 10 (PayPal card fields), 13 (renewals fill no Direct Payment; the hook skips them), 14 (a crash between save and hook, accepted).
* **Not tested:** `capturePayPalOrder()` (it calls PayPal's API with curl); its call is the same one line as the others.
