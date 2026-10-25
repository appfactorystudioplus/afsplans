# 06 — Activity Fan-out: Notifications and Analytics Hand in Hand

Closes **M2** and **X2**, and connects [03](03-admin-inbox.md), [04](04-dispatcher-extraction.md) and [05](05-analytics.md). This is the step that answers
the owner's example: *a payment is made, which should trigger a notification and an analytical report.*

## 6.1 The shape

A module says **once** what happened. `ActivityPublisher` hands that to two independent systems. Neither system calls the other, imports the other, or
shares a class with the other; the only thing they share is the `Activity` they were given.

```php
final class ActivityPublisher
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly AnalyticsRecorder $analytics,
        private readonly ActivityCatalog $catalog,
    ) {
    }

    /** Never throws. A module's business flow must not fail because a notification or a metric did. */
    public function publish(Activity $activity): void
    {
        $event = $this->catalog->event($activity->module, $activity->event);   // unknown pair: log a warning and stop
        if ($event === null) { return; }

        try {
            $this->notifications->dispatch($activity, $event);
        } catch (\Throwable $e) {
            Log::warning('[Activity] notification failed (swallowed)', [...]);
        }

        try {
            if ($event->recordAnalytics) { $this->analytics->record($activity, $event); }
        } catch (\Throwable $e) {
            Log::warning('[Activity] analytics failed (swallowed)', [...]);
        }
    }
}
```

**Why a visible class and not Laravel's event bus** (recommended, [10](10-open-questions.md) OQ4): a reader who opens `ActivityPublisher` sees both consumers
by name, in order, and an IDE follows each call to its definition. With `event(new ActivityOccurred(...))` the consumers are listeners registered
elsewhere, and finding "what happens when a payment succeeds" means searching for listeners — the indirection the owner asked to remove from `app/`
(www commit `12975edab`). If a third consumer ever appears (a webhook out to a tenant, an audit log) it is a third constructor argument and a third `try` block.

## 6.2 `EventDefinition` — the fields the publisher and its consumers read

[02](02-target-model.md) §2.3 lists the core fields. These complete it:

| Field | Meaning |
|---|---|
| `recordAnalytics` (bool, default true) | The analytics recorder skips the event when false (an event that exists only to send a mail) |
| `mailDelaySeconds` (int, default 0) | The mail is queued with this delay; `payment_succeeded` uses 30 so card details from `charge.succeeded` have arrived (today's `direct_payments.mail_delay_seconds`) |
| `attachedHandlers` (bool, default false) | **Compatibility mode** for modules that send "every mail handler attached to the item" today. When the event has no rule, `MailChannel` sends the item's attached handlers exactly as the module does now; once an owner creates a rule, the rule wins. Adopting a module therefore changes nothing until someone opts in |
| `appliesTo` (a list of tokens the module defines, default empty = all) | Replaces `SINGLE_PAY_NOTIFICATION_EVENTS` in the Direct Payments form. The general layer does not interpret the tokens (it may not name a module's concepts); the editor only filters by them |
| `recipientMode` default | `customer` unless the event is owner-facing (then `handler`) |

## 6.3 Delivery is queued; recording is not

Today `dispatchEvent()` sends the e-mail **inside the webhook request** ([01](01-audit-findings.md) M10) — a slow mail provider delays the response to the
payment provider, which may then retry the webhook. The target:

| Step | Where it runs | Why |
|---|---|---|
| Rule lookup, ledger insert (`pending`), inbox row, analytics row | Synchronously, in the caller's request | Cheap indexed inserts; the ledger's unique key must be claimed before anything is sent |
| The e-mail send | A queued job `DeliverNotificationMail`, `delay(mailDelaySeconds)`, `tries = 1` | Takes provider latency out of the webhook; `tries = 1` because a retry could double-send — the ledger row records the outcome |

The queue worker already runs every minute (`routes/console.php:19` — `queue:work --stop-when-empty --max-time=55`). An e-mail therefore leaves within about
a minute of the event, which is the receipt's behaviour today (30-second delay plus the worker). The scheduled path (15-minute ticks) sends inline inside the
command, as today, because it is already off the request path.

## 6.4 The payment, end to end

Today a payment can send two e-mails ([01](01-audit-findings.md) M2). The recommendation ([10](10-open-questions.md) OQ13) is **one event for a successful
charge, `payment_succeeded`, with one rule and two switches**: the *e-mail* switch controls the receipt the payer gets; the *inbox* switch controls whether the
site owner sees it on the bell. The separate `payment_receipt` event is folded into it.

```
 Stripe / PayPal / Square
      │ webhook: invoice.payment_succeeded ──▶ ProcessorWebhookController   (saves the invoice; no longer calls dispatchEvent for this event)
      │ webhook: charge.succeeded          ──▶ …markChargeHookPending → ChargeSucceeded::handle($invoice)   (once per charge, under a lock)
      ▼
 ChargeSucceeded::handle                                              ◀── the single call site (X2)
      │  $this->activities->publish(new Activity(
      │      module: 'direct_payments', event: 'payment_succeeded',
      │      scopeRef: $invoice->direct_payment_reference_num, subjectRef: $invoice->reference_num,
      │      valueCents: $invoice->amount_cents, currency: $invoice->currency,
      │      dedupeKey: 'direct_payments.payment_succeeded.'.$invoice->reference_num,
      │      data: [ 'invoice' => $invoice->reference_num, 'processor' => ..., 'provider' => 'stripe', 'kind' => 'renewal'|'single' ]))
      ▼
 ActivityPublisher::publish
      │
      ├─▶ NotificationDispatcher::dispatch
      │      1. rule for ('direct_payments', <this Direct Payment>, 'payment_succeeded'); else module-wide; else the event's defaults
      │      2. inbox_active?  → ledger(pending, channel inbox) → InboxChannel → laravel_notifications  "Payment received — £25.00"   [severity: success]
      │      3. mail_active?   → recipients (customer → DirectPaymentsRecipientResolver: payer, then provider customer)
      │                          → ledger(pending, channel mail, per recipient) → DeliverNotificationMail (delay 30 s) → MailChannel → MailHandlerSender
      │                          handler = rule's, else attached handlers (compat), else the built-in default receipt
      │
      └─▶ AnalyticsRecorder::record
             one row in laravel_analytics_events: source server · direct_payments · payment_succeeded · resource_reference=<DP> ·
             subject_ref=<invoice> · value_cents · currency · dedupe_key        (no name, no e-mail)
             … later, analytics:rollup → laravel_analytics_daily_rollups (count and value per day, per Direct Payment)
```

What this changes for Direct Payments, concretely:

| Today | After |
|---|---|
| `invoice.payment_succeeded` calls `dispatchEvent('payment_succeeded')` for subscription invoices only | Removed; the one publish happens in `ChargeSucceeded`, for **every** successful charge (single pay and renewals) |
| `charge.succeeded` queues `SendDirectPaymentMail` → `DirectPaymentCommunicationService::paymentSucceeded()` | `ChargeSucceeded` publishes; `SendDirectPaymentMail` and the service's rule logic are replaced by the dispatcher (its attached-handler behaviour survives as `attachedHandlers` compatibility) |
| Two events, `payment_succeeded` and `payment_receipt`, each with a row | One event; existing rows are merged by the P2 copy (§6.5) |
| Silence when there is no row and no attached handler | The event's defaults apply: e-mail **on**, inbox **off** ([02](02-target-model.md) §2.6) |

`ChargeSucceeded::handle()` keeps its lock and its `metadata.mail_queued_at` marker: the marker stays the cheap first guard against re-running, and the
dedupe key is the second, durable one that survives a cache flush.

## 6.5 Merging the two payment events in the data

The P2 copy handles a Direct Payment that has either or both rows:

| Existing rows | Result |
|---|---|
| only `payment_receipt` | becomes `payment_succeeded`, same handler/recipients/active |
| only `payment_succeeded` | stays, same |
| both | `payment_succeeded` takes the `payment_receipt` row's handler and recipients (the receipt is the e-mail the payer expects); `mail_active` = either was active; the other row is not copied and **a line is written to the migration log** naming the Direct Payment so the owner can check it |

Because the migration is idempotent and non-destructive (the old tables stay until P6), a wrong merge is corrected by editing the rule or re-running with a
different policy. The two built-in templates `payment_receipt.mjml` and `payment_succeeded.mjml` both stay in the folder; `EventDefinition(payment_succeeded)`
points at `payment_receipt` as its `defaultTemplate`, and `payment_succeeded.mjml` is kept for rules whose handler explicitly chooses it (decision in
[10](10-open-questions.md) OQ13).

## 6.6 What every other module would publish

> **Superseded by [11-module-adoption.md](11-module-adoption.md)**, written after the code of each module was read. Where the two disagree (event names, defaults, what stays out of the system), 11 is right: for example Site Users' token e-mails and Sponsorships' credentials e-mail never go through the system, and module-owned payments are announced once. The table below is the original sketch.

Not built in this plan — each is a later, small plan that adds a module class and a few `publish()` calls. The table fixes the vocabulary now so the
catalogue is designed once.

| Module | Events (inbox · e-mail defaults) | Publish point | Analytics |
|---|---|---|---|
| Forms | `submission_received` (inbox on · mail via attached handlers) | `ProcessFormSubmission` — replaces `createAdminNotification()` and keeps `FormsMailer` as the attached-handler path | yes |
| Events | `registration_confirmed` (off · attached handlers), `registration_cancelled`, `order_paid` (on · off), `checked_in` | `EventRegistrationService`, the order paid path | yes, with `value_cents` on `order_paid` |
| Appointments | `booking_confirmed` (off · attached), `booking_cancelled`, `booking_no_show` | `AppointmentBookingService`, `AppointmentLifecycleService` | yes |
| Ecommerce | `order_paid` (on · attached), `order_refunded` | `EcommerceCheckoutService` / `EcommerceReceiptService` | yes, with `value_cents` |
| Site Users | `signup_completed`, `email_changed` — **transactional**: `alwaysOn`, mail only, no inbox | `SiteUsersVerificationService` | `signup_completed` only |
| Sponsorships | `application_received` (on · off), `display_requested` (on · off) | the two sponsorship controllers | yes |
| Subscribers | `subscriber_signup` (on · off) | the subscriber signup path | yes (also sent by the browser today) |
| Analytics (itself) | `report_ready` (inbox on · mail off) — a quarterly report was generated ([08](08-data-lifecycle.md) §8.6) | `analytics:report` | no |
| Direct Payments | the fourteen, with `payment_succeeded` per §6.4 | `ChargeSucceeded`, the webhook controller, the subscription controller, the scheduled source | `payment_succeeded`, `payment_failed`, `subscription_canceled`, `subscription_updated` |

`appliesTo` and the `attachedHandlers` mode mean **adopting a module changes no behaviour until its owner opts in**: with no rule, Forms still sends its
attached handlers and still shows the bell notification.

## 6.7 Idempotency and the dedupe key

Convention: `<module>.<event>.<subjectRef>` for one-off events; `<module>.<event>.<subjectRef>.<bucket>` for periodic ones (`bucket` = the period end). One key
is used by all three consumers: the ledger (`bucket` + `subject_ref` in its unique key), the inbox (`dedupe_key`), and the analytics event (`dedupe_key`).
A webhook retried by the provider, a job retried by the queue, and `repair-charge-hooks` re-running a charge all produce **one** of each.

## 6.8 Failure semantics

| Failure | Result |
|---|---|
| Rule lookup throws | Logged; the analytics half still runs |
| Inbox write fails | Logged; ledger row `failed`; e-mail half still runs |
| Mail provider missing or down | Ledger row `failed` with `no_provider_token` / the error; never retried automatically (D7); visible in the sends panel; the caller is unaffected |
| Analytics write fails | Logged; notifications unaffected |
| Unknown module/event pair | One warning; nothing else |
| `publish()` called with the same dedupe key again | A no-op in all three places |

## 6.9 Acceptance

- One successful Stripe charge produces exactly one inbox row (if switched on), exactly one e-mail per recipient (if switched on), exactly one analytics
  event and one ledger row per channel and recipient — also when the webhook is delivered twice and when `charge.succeeded` arrives before `invoice.payment_succeeded`.
- With the mail provider removed, the charge still completes, the webhook returns 2xx, the inbox row and the analytics event exist, and the ledger shows the mail `failed`.
- With no rule at all, the payer receives the built-in receipt and nothing appears on the bell.
- With `mail_active = false` and `inbox_active = true`, only the bell notification and the analytics event exist.
- No code in a module's controller calls `NotificationDispatcher` or `AnalyticsRecorder` directly (grep gate, [09](09-rollout-and-testing.md) §9.4).
