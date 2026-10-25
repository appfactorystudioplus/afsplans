# 03 — Welcome email, sent from the payment-success webhook

> **SUPERSEDED by [07-direct-payment-email.md](07-direct-payment-email.md).** Direct Payments has no welcome email, member or tier;
> it sends the mail handlers attached to a Direct Payment on a successful charge. Nothing in this file is to be built. In
> particular section 4.4 (changing `store()`) is withdrawn: the `store()` block is left as it is. Kept only as a record.

Status: PLAN ONLY. Nothing below is written to the repo. Code blocks are examples for approval.

Paths are under `/Users/jamesmitchell/Desktop/projects/vagrant/afs-eco/w/www/` unless stated.

## 1. Goal

After a member signs up and pays, the payment provider's webhook arrives and the welcome email is sent **from that
webhook**, because the email carries the payment info (amount, card brand, last 4).

Rules agreed so far:

* Direct Payments (DP) has no concept of a member and no tier registry. Its tiers are provider plans.
* The email is sent with the mail handler registered to the Direct Payment:
  `laravel_mail_handlers.item_reference_num` = the Direct Payment's `reference_num`.
* The sender is `MailHandlerSender` (`app/Mail/MailHandlerSender.php`), as the original `contructMailHandler()` used.
* A DP invoice always has a payer (`payer_reference_num`); the member takes that payer id from DP
  (see [01-website-reference-num.md](01-website-reference-num.md) §3.5).
* No new settings, no `default_mail_handler_reference_num`, no `welcome_sent_at` on `brain_memberships`.

## 2. What exists now (facts read from the code)

| Fact | Where |
|---|---|
| The browser pays first, then calls `POST /memberships/register`. So the webhook can arrive before, during or after `store()` creates the member. | `resources/js/afs/create.ts:436-457` |
| The browser sends `user_id: memberRef` with the payment; DP saves it on the invoice (`laravel_direct_payment_invoices.user_id`). `store()` reuses it as `brain_memberships.reference_num`. | `create.ts:442`, `DirectPaymentsController.php:98`, `MembershipController.php:397` |
| Card brand and last 4 are saved on the invoice's `metadata` (`card_brand`, `last4`) by the `charge.succeeded` webhook, not by `invoice.payment_succeeded`. | `ProcessorWebhookController.php:453-526` |
| `invoice.payment_succeeded` is handled by `recordStripeInvoiceCharge()`, which **returns early** when an invoice with the same `charge_id` already exists (so a handler placed after that line can be skipped). | `ProcessorWebhookController.php` (`recordStripeInvoiceCharge`) |
| Webhooks are **not** de-duplicated: every delivery creates a `ProcessorWebhook` row and runs the handler again, so Stripe retries re-run it. | `handleStripe()` |
| `store()` now contains the restored original block: `if ($membershipTier==0 \|\| $data['testing']==true) { contructMailHandler(...) }`. It still has two known faults (bare function call; recipient hard-coded). | `MembershipController.php:497-503, 1245` |
| Other modules send exactly this way: handlers by `item_reference_num`, then `MailHandlerSender::send`. | `app/Mail/EcommerceReceiptService.php:62-77` |
| `MailHandlerSender` → `FunctionsController::SendEmailBuildIntegration()`. It sends only when the active provider row's `provider` is `appfactory_studio`; otherwise nothing happens (intended). | `FunctionsController.php` (`SendEmailBuilder`) |

## 3. The flow

```
browser ── pay (user_id = memberRef) ──────────────► DP saves invoice  (invoice.user_id = memberRef,
   │                                                                     invoice.payer_reference_num = payer)
   │
   ├── Stripe ── invoice.payment_succeeded ───────► ProcessorWebhookController
   │                                                   └─ raises SubscriptionPaymentSucceeded (payment facts only)
   │                                                        └─ appfactory listener: SendMembershipWelcome::handle()
   │
   └── POST /memberships/register ────────────────► MembershipController::store()
                                                       └─ creates member (payer_reference_num from DP)
                                                       └─ SendMembershipWelcome::forNewMember()
```

Whichever of the two (webhook, `store()`) runs **second** sends the email, because the first one cannot: the
webhook may find no member yet, and `store()` may find no recorded payment yet. Both call the same class.

## 4. Pieces

### 4.1 DP raises an event with payment facts only

New: `app/Externals/DirectPayments/Events/SubscriptionPaymentSucceeded.php`

```php
final class SubscriptionPaymentSucceeded
{
    public function __construct(
        public readonly DirectPaymentSubscription $link,
        public readonly string $directPaymentReferenceNum,
        public readonly ?string $invoiceUserId,     // opaque string the caller attached to the invoice
        public readonly bool $isFirstPayment,       // Stripe billing_reason === 'subscription_create'
        public readonly int $amountCents,
        public readonly string $currency,
        public readonly ?string $chargeId,
    ) {
    }
}
```

DP never mentions a member. `invoiceUserId` is the string DP already stores as `user_id`.

### 4.2 The webhook raises it

`app/Http/Controllers/Externals/Processor/ProcessorWebhookController.php`, `recordStripeInvoiceCharge()`, **before**
the `charge_id` early return:

```php
if (empty($chargeId)) return false;

// Announce the paid invoice. Before the dedupe return below, because charge.succeeded may already have created
// the invoice row. Never changes the webhook response.
if (!empty($obj['subscription'])) {
    try {
        $link = DirectPaymentSubscription::where('provider_subscription_id', $obj['subscription'])->first();
        if ($link) {
            $invoiceUserId = DirectPaymentInvoice::where('charge_id', $chargeId)->value('user_id')
                ?? DirectPaymentInvoice::where('event_identifier', $obj['payment_intent'] ?? '')->value('user_id');
            event(new SubscriptionPaymentSucceeded(
                link: $link,
                directPaymentReferenceNum: (string) $link->direct_payment_reference_num,
                invoiceUserId: $invoiceUserId,
                isFirstPayment: ($obj['billing_reason'] ?? null) === 'subscription_create',
                amountCents: $amount,
                currency: $currency,
                chargeId: (string) $chargeId,
            ));
        }
    } catch (\Throwable $e) {
        Log::error('[Webhook][Stripe] SubscriptionPaymentSucceeded listener threw', ['error' => $e->getMessage()]);
    }
}

if (DirectPaymentInvoice::where('charge_id', $chargeId)->whereNotNull('charge_id')->exists()) return true;
```

Registered in `app/Providers/AppServiceProvider.php`, next to the `SubscriptionPlanChanged` listener:

```php
Event::listen(SubscriptionPaymentSucceeded::class, [SendMembershipWelcome::class, 'handle']);
```

### 4.3 The appfactory sender

New: `app/Services/Billing/SendMembershipWelcome.php` (next to `ApplyTierOnPlanChange`; appfactory side, so it may
use `Membership` and the tier registry).

```php
class SendMembershipWelcome
{
    /** Webhook path. */
    public function handle(SubscriptionPaymentSucceeded $event): void
    {
        if (!$event->isFirstPayment) {
            return;                                    // renewals do not get a welcome email
        }

        // the member: by the payer DP gave them, else by the reference the browser attached to the invoice
        $member = $event->link->payer_reference_num
            ? Membership::where('payer_reference_num', $event->link->payer_reference_num)->first()
            : null;
        $member ??= $event->invoiceUserId ? Membership::where('reference_num', $event->invoiceUserId)->first() : null;
        if (!$member) {
            return;                                    // webhook beat /register; store() sends instead
        }

        $this->send($member, $event->directPaymentReferenceNum, $this->paymentVars($event->amountCents, $event->currency, $event->chargeId));
    }

    /** store() path. $link is null for a free signup. */
    public function forNewMember(Membership $member, ?DirectPaymentSubscription $link): void
    {
        if (!$link) {
            $this->send($member, (string) config('brain.membership_direct_payment'), []);   // free: no webhook will come
            return;
        }

        $invoice = DirectPaymentInvoice::where('user_id', $member->reference_num)
            ->where('status', 'succeeded')->latest('charged_at')->first();
        if (!$invoice) {
            return;                                    // the payment webhook has not arrived; it sends
        }

        $this->send($member, (string) $link->direct_payment_reference_num,
            $this->paymentVars((int) $invoice->amount_cents, (string) $invoice->currency, $invoice->charge_id));
    }

    private function send(Membership $member, string $directPaymentRef, array $paymentVars): void
    {
        if (!$member->email || $directPaymentRef === '' || $this->alreadySent($member)) {
            return;
        }

        $handlers = MailHandlerModel::where('item_reference_num', $directPaymentRef)
            ->where('category', '<CATEGORY>')          // see question 1
            ->get();
        if ($handlers->isEmpty()) {
            return;                                    // no handler registered = nothing to send
        }

        $clientVariables = [[
            ['name' => 'full_name',        'value' => trim(($member->firstname ?? '') . ' ' . ($member->lastname ?? ''))],
            ['name' => 'username',         'value' => (string) $member->username],
            ['name' => 'membership_title', 'value' => app(TierRegistry::class)->labelFor((int) $member->membership) ?? ''],
            ...$paymentVars,
        ]];

        try {
            foreach ($handlers as $handler) {
                MailHandlerSender::send(new MailHandlerSenderOptions(
                    mailhandler_reference_num: $handler->reference_num,
                    emails: [$member->email],          // the member's own address
                    client_variables: $clientVariables,
                ));
            }
            $this->markSent($member);
        } catch (\Throwable $e) {
            Log::error('[Membership] welcome email failed to send', ['reference_num' => $member->reference_num, 'error' => $e->getMessage()]);
        }
    }

    /** @return array<int, array{name:string, value:string}> amount, currency, brand, last4 */
    private function paymentVars(int $amountCents, string $currency, ?string $chargeId): array
    {
        $meta = $chargeId ? (DirectPaymentInvoice::where('charge_id', $chargeId)->value('metadata') ?? []) : [];
        $meta = is_array($meta) ? $meta : (json_decode((string) $meta, true) ?: []);

        return [
            ['name' => 'amount',   'value' => number_format($amountCents / 100, 2, '.', '')],
            ['name' => 'currency', 'value' => strtoupper($currency)],
            ['name' => 'brand',    'value' => (string) ($meta['card_brand'] ?? '')],
            ['name' => 'last4',    'value' => (string) ($meta['last4'] ?? '')],
        ];
    }
}
```

### 4.4 `store()`

`app/Http/Controllers/Portal/Admin/MembershipController.php`: the restored `if (... testing ...) {
contructMailHandler(...) }` block and the `contructMailHandler()` method are replaced by:

```php
$welcomeLink = !empty($membershipId) ? DirectPaymentSubscription::where('provider_subscription_id', $membershipId)->first() : null;
app(SendMembershipWelcome::class)->forNewMember($membership, $welcomeLink);
```

## 5. Sending it only once (the open design point)

The webhook is re-run by Stripe retries, and `store()` and the webhook can both reach `send()` close together. Some
record of "sent" is needed. No column is added to `brain_memberships`. Options:

| Option | How | Cost |
|---|---|---|
| **A. Audit event (recommended)** | `alreadySent()` checks for a `brain_membership_events` row `(member_reference_num, event='welcome_email_sent')`; `markSent()` inserts one. The table is already the append-only audit log for member actions. | no new table or column; one new `EVENT_WELCOME_EMAIL_SENT` constant. The check-then-insert is not atomic, so two deliveries within the same few milliseconds could both send. |
| B. Same as A plus a unique index on `(member_reference_num, event)` for this event only | insert first, send only if it succeeded | closes the race, but a unique index on that table would also block repeated dunning events, so it would need a partial/dedicated key |
| C. Do nothing | | Stripe retries and the store()/webhook overlap can send duplicates |

Example for A:

```php
private function alreadySent(Membership $member): bool
{
    return BrainMembershipEvent::where('member_reference_num', $member->reference_num)
        ->where('event', BrainMembershipEvent::EVENT_WELCOME_EMAIL_SENT)->exists();
}

private function markSent(Membership $member): void
{
    BrainMembershipEvent::create([
        'member_reference_num' => $member->reference_num,
        'event'  => BrainMembershipEvent::EVENT_WELCOME_EMAIL_SENT,
        'source' => 'webhook',                      // documented values: scheduler | webhook | admin
        'created_at' => now(),                      // the model has no updated_at; created_at is set explicitly in the table
    ]);
}
```

(Checked in `create_brain_tables.php:306-330`: `event` is `varchar(40)` and `welcome_email_sent` is 18 characters;
`source` defaults to `scheduler` and takes `scheduler | webhook | admin`; every other column is nullable. If the model
already fills `created_at`, drop that line.)

## 6. Things this plan does not fix

* `MailHandlerSender::send()` writes a debug file to a hard-coded path
  (`/home/appfactorystudio/websites/dev/www15/storage/store/hello99.json`) and returns nothing, so success cannot be
  confirmed from the return value.
* `SendEmailBuilder` sends only when the provider row's `provider` is exactly `appfactory_studio` (as intended).
* Card brand and last 4 are empty if `charge.succeeded` has not been processed yet when `invoice.payment_succeeded`
  runs (Stripe does not guarantee the order).

## 7. Questions

1. What is the `category` value of a Direct Payment's mail handlers in `laravel_mail_handlers`? Or match on
   `item_reference_num` alone?
2. Free signup: which Direct Payment's handler? The example uses `config('brain.membership_direct_payment')`.
3. The restored `if ($membershipTier==0 || $data['testing']==true)` gate: remove it (as in the example), or keep
   `testing` as a way to force the email during development?
4. Sent-once record: option A (audit event), B, or C?
