# 05 — Notification Configuration

The operator-facing half of "add to the Direct Payments API the functionality to configure
notifications and events on actions".

## 5.1 The model

A **notification config** is one row binding four things:

```
  Direct Payment  ──▶  event  ──▶  mail handler  ──▶  when
  (which product)      (what       (which email     (for upcoming_charge:
                       happened)    to send)         which days before, ≤3)
```

Multiple configs per DP — one per event — so an operator can send the upcoming-charge
notice from a "billing" handler and the payment-failed notice from a "support" handler.

## 5.2 `laravel_direct_payment_notifications`

Modelled on `laravel_direct_payment_connect` (decision **D6**): a child config table keyed
by `direct_payment_reference_num`, written from a key on `saveDirectPayment`'s request.

| Column | Type | Notes |
|---|---|---|
| `id` | `id` | |
| `reference_num` | `string(32)` unique | `Str::random(12)`, stable across saves — the send ledger points at it |
| `direct_payment_reference_num` | `string(128)` index | → `laravel_direct_payments.reference_num` |
| `event` | `string(64)` | See §5.3. Unique together with the DP ref |
| `is_active` | `boolean` default `true` | The **only** thing that makes the scheduler do work |
| `mail_handler_reference_num` | `string(64)` | → `laravel_mail_handlers.reference_num`. Exactly as requested: the reference_num is what is stored |
| `days_before` | `json` null | `upcoming_charge` only. Array of ≤3 distinct ints in 1..7, stored sorted descending |
| `recipient_mode` | `string(32)` default `customer` | `customer` \| `handler` \| `custom`. §5.4 |
| `recipient_emails` | `json` null | Used when `recipient_mode = 'custom'` |
| `send_window_hour` | `tinyint` null | Optional 0–23 local hour to prefer. Null = send as soon as the day bucket opens |
| `timestamps` | | |

```php
$table->unique(['direct_payment_reference_num', 'event'], 'dp_notifications_dp_event_uq');
$table->index(['is_active', 'event'], 'dp_notifications_active_event_idx');
```

Model `App\Models\Externals\DirectPaymentNotification`, `$casts`: `days_before` → `array`,
`recipient_emails` → `array`, `is_active` → `boolean`. Relation
`directPayment()` → `belongsTo(DirectPayment::class, 'direct_payment_reference_num', 'reference_num')`,
mirroring `DirectPaymentConnect`.

## 5.3 The event vocabulary

| Event | Source | `days_before` | Applies to |
|---|---|---|---|
| `upcoming_charge` | **Scheduler** — `next_charge_at` minus N days | ✅ required, 1–3 entries | `SUBSCRIPTION` |
| `payment_succeeded` | Webhook `invoice.payment_succeeded` / Square `invoice.payment_made` / PayPal `PAYMENT.SALE.COMPLETED` | ✖ | `SUBSCRIPTION`, `SINGLE_PAY` |
| `payment_failed` | Webhook `invoice.payment_failed` / PayPal `BILLING.SUBSCRIPTION.PAYMENT.FAILED` | ✖ | `SUBSCRIPTION` |
| `subscription_canceled` | Webhook `customer.subscription.deleted` and equivalents. Carries `access_ends_at`, so the email can say when benefits actually stop | ✖ | `SUBSCRIPTION` |
| `trial_ending` | **Scheduler** — `trial_ends_at` minus N days | ✅ reuses `days_before` | `SUBSCRIPTION` |
| `subscription_cancel_scheduled` | Webhook — `cancel_at_period_end` flipped false→true, i.e. an operator cancelled at period end **directly at the provider**, out of band ([28](28-subscription-actions.md) §14.7) | ✖ | `SUBSCRIPTION` |
| `subscription_access_ended` | **Scheduler** — `access_ends_at` passed and the tier was dropped ([28](28-subscription-actions.md) §14.3) | ✖ | `SUBSCRIPTION` |
| `subscription_updated` | Webhook — the subscription's item price changed ([28](28-subscription-actions.md) §14.7) | ✖ | `SUBSCRIPTION` |
| `payment_method_changed` | **Controller**, after a confirmed 2xx — no reliable webhook on all three providers ([28](28-subscription-actions.md) §14.7) | ✖ | both |

Scheduler-sourced events go through [24](24-notification-scheduler.md)'s evaluate phase;
webhook-sourced events are dispatched from `ProcessorWebhookController` through the same
`NotificationDispatcher`, so there is exactly one send path and one ledger.

### `days_before` validation (decision **D8**)

Server-side, in the controller — never trusted from the client:

```
- must be an array
- 1..3 elements                        ("allow multiple sends up to 3")
- every element an integer in 1..7     ("up to 7 days before the next charge")
- no duplicates
- stored sorted descending, e.g. [7,3,1]
```

Reject with `422` and code `DP_E_INVALID_NOTIFICATION_DAYS`, in the style of
`saveDirectPayment`'s existing `DP_E_INVALID_PAY_TYPE`. An `upcoming_charge` config with an
empty `days_before` is rejected, not silently treated as inactive — a silent no-op is the
failure mode operators cannot debug.

### `payment_method_expiring` is deliberately absent

An earlier draft listed a `payment_method_expiring` event, evaluated from a card's
`exp_month`/`exp_year`. It is removed for the same reason [26](26-advanced-policy.md)
§12.3 removed `suspend`: **nothing implements it.** [24](24-notification-scheduler.md)'s
evaluate phase works from `next_charge_at`, and card expiry would need a per-customer
payment-method fetch against each provider — a different sweep with a different cost profile,
which is not specified anywhere in this plan.

An event an operator can select, save and never receive is precisely the silent no-op this
section refuses two paragraphs below. It is a good feature and worth building; it needs its
own bounded sweep first, and until then it is not in the vocabulary.

Zero is deliberately not allowed: "0 days before" is "at the moment of charge", which is
the `payment_succeeded` event and is delivered by webhook with an accurate outcome, not by
a scheduler guessing.

## 5.4 Who receives it

| `recipient_mode` | Recipients |
|---|---|
| `customer` *(default)* | The subscription's own customer — `laravel_processor_customers.email`, falling back to `brain_memberships.email` via `member_reference_num`. This is the "tell the member their card is about to be charged" case |
| `handler` | Whatever `emails` the mail-handler JSON already carries. The operator-alert case: "tell billing@ every time a payment fails" |
| `custom` | The `recipient_emails` array |

`customer` and `custom` **overwrite** the handler's `emails` array before sending, exactly
as `MembershipController` does (audit §2.3). `handler` leaves it untouched.

A `customer`-mode send with no resolvable email writes a `status = 'skipped'` ledger row
with `error = 'no_recipient'` and does **not** retry — a missing email is a data problem,
not a transient one, and retrying it 96 times a day produces nothing but log volume.

## 5.5 The mail-handler dropdown

Finding **B13**: `MailHandlerController::index()` returns `{}` without an
`item_reference_num`, so nothing can list all handlers today.

New endpoint, in `routes/portal.php` beside the existing email-handler routes:

```
GET /portal/api/v1/email-handlers/list
    → [ { reference_num, name, category, from, subject }, … ]
```

`DirectPaymentNotificationController::listMailHandlers()`:

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/23-notification-config.md §5.5.
 *
 * A flat list of every mail handler for the notification-config dropdown.
 * MailHandlerController::index() cannot serve this — it requires an
 * item_reference_num and returns {} without one (audit B13).
 *
 * Returns SUMMARY fields only. The full handler JSON contains recipient
 * addresses and the rendered body; the dropdown needs neither, and shipping
 * them to an admin page that only stores a reference_num is gratuitous.
 */
```

Each row's `json` is decoded with `Utils_decodeStringToJson()` (it is htmlentities-encoded
— audit §2.3), and `from`/`subject` are lifted from the **first** entry of its `handlers`
map purely as a preview label. A row whose JSON will not decode is returned with
`"name": <row name>, "warning": "unparseable"` rather than omitted — an operator needs to
see that a handler is broken, not have it silently vanish from the list.

## 5.6 Write path

Extend `DirectPaymentsController::saveDirectPayment()`, after `syncConnectConfig()`:

```php
$this->syncNotificationConfigs($directPayment, $request->input('notifications', []));
```

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/23-notification-config.md §5.6.
 *
 * updateOrCreate keyed on (dp_ref, event) — NOT the delete-and-rebuild used
 * for items and tiers. A rebuild would mint a new reference_num on every save
 * and orphan every send-ledger row pointing at the old one, which would let a
 * notification already sent this period be sent again after any edit
 * (audit B7/B8).
 *
 * An event omitted from the payload is DEACTIVATED (is_active = false), never
 * deleted, so the ledger's history stays resolvable.
 */
private function syncNotificationConfigs(DirectPayment $dp, array $configs): void
```

For each incoming config: validate the event against the vocabulary, validate
`days_before` (§5.3), verify `mail_handler_reference_num` actually exists in
`laravel_mail_handlers` (422 `DP_E_MAIL_HANDLER_NOT_FOUND` if not — a dangling reference
would fail silently at 3am), then `updateOrCreate`.

Then the cron bootstrap from [21](21-cron-bootstrap.md) §3.1 runs, and the save response
gains `$saved->notifications` alongside the existing `items`, `tiers` and `connect` — so a
caller re-rendering from the save response sees the normalised rows (`days_before` sorted,
inactive events present) rather than what it happened to send. Same contract
`saveDirectPayment` already honours for `connect`.

Read path: `getDirectPayments()` attaches `notifications` the same way it attaches
`tiers`.

## 5.7 Admin UI

**`directpayments-form.html`** — a fourth card after `#dpConnectSection`:

```html
<div class="card mb-3 d-none" id="dpNotificationsSection">
  <div class="card-header">
    <h3 class="card-title" data-i18n="directpayments-form.notifications">Notifications</h3>
    <button class="btn btn-sm btn-outline-secondary" id="dpAddNotificationBtn">Add Notification</button>
  </div>
  <div class="card-body" id="dpNotificationsContainer">
    <p class="text-muted" id="dpEmptyNotificationsText">
      No notifications configured — nothing will be emailed for this Direct Payment.
    </p>
  </div>
</div>
<template id="dpNotificationTemplate"> … </template>
```

Each templated row carries: an **Event** `<select>`, a **Send from** `<select>` populated
from §5.5 (option value = `reference_num`, label = `name — from` ), an **Active** switch, a
**Recipients** `<select>` (`recipient_mode`) with a conditional emails input, and — shown
only for `upcoming_charge`/`trial_ending` — seven day checkboxes (`1`–`7`) that
**disable the unchecked ones once three are ticked**, enforcing D8 in the UI as well as
the server.

The empty-state copy above is deliberate: an operator who sees "No notifications
configured" understands the scheduler's silence. Blank space does not explain itself.

**`directpayments.ts`** — three edits:

1. `payTypeSelect` change handler: show `#dpNotificationsSection` for `SUBSCRIPTION`, and
   for `SINGLE_PAY` with the event list filtered to `payment_succeeded` /
   the action events from [28](28-subscription-actions.md) §14.7.
2. Fetch `/portal/api/v1/email-handlers/list` once on form open, cache it, and populate
   every "Send from" select from it. When the list is empty, render a disabled select
   reading "No mail handlers configured" with a link to the email-handlers page — an
   operator cannot configure a notification without one, and should be told where to go.
3. In the save builder (line ~665), assemble `dpObj.notifications` from the rows —
   the same shape and place `dpObj.connect` is assembled.

## 5.8 Migrations in this step

| File | Contents |
|---|---|
| `2026_08_25_000002_create_direct_payment_notifications_table.php` | §5.2 |
| `2026_08_25_000003_create_direct_payment_notification_sends_table.php` | [24](24-notification-scheduler.md) §6.5 — created here so the two ship together |

## 5.9 Acceptance

- Saving a DP with no `notifications` key leaves existing configs untouched (not wiped).
- Saving with an event omitted deactivates it and preserves its `reference_num`.
- `days_before: [7,7,3]`, `[0,3]`, `[8]`, `[1,2,3,4]` all reject with
  `DP_E_INVALID_NOTIFICATION_DAYS`.
- `days_before: [1,7,3]` stores as `[7,3,1]`.
- A `mail_handler_reference_num` that does not exist rejects at save time, not at send
  time.
- Renaming the DP and re-saving does not change any notification `reference_num`.
