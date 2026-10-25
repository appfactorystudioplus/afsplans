# 06 — Notification Scheduler — Complete

## Status

Done, with documented provider-parity gaps on the webhook (action-event) push path —
see below. The scheduled path (upcoming_charge/trial_ending) is provider-agnostic and has
no such gap.

## Summary

Built `direct_payments:subscription-notify` end to end: the phase-0 guard (D2), phase-1 sync
delegation to `SubscriptionSyncService`, phases 2/2b/3 in a new `NotificationDispatcher`
(evaluate → ledger insert with dedupe → `BillingMailer::sendOne()`), the send ledger's
dedupe mechanics (D7), and the action-event push path wired into
`ProcessorWebhookController`. Also added the admin "recent sends" endpoint (§6.8).

## Files changed

### Command
| File | Contents |
|---|---|
| `www/app/Console/Commands/BillingSubscriptionNotify.php` | New. Phase 0 guard, `--dry-run`/`--dp=`/`--force-sync`, dry-run evaluation table via `$this->table()` |

### Services
| File | Contents |
|---|---|
| `www/app/Externals/DirectPayments/Notifications/NotificationDispatcher.php` | New. `evaluateScheduled()` (phases 2/2b/3 for upcoming_charge/trial_ending) and `dispatchEvent()` (§6.7's action-event path, shared ledger/send code) |
| `www/app/Mail/BillingMailer.php` | New. `sendOne()` — resolves the mail integration once per instance (§6.6.1, B9/B24 guards), one recipient per call (B25, §6.6.3) |
| `www/app/Mail/SendResult.php` | New — `sent()`/`failed($error)`/`skipped($error)` |
| `www/app/Mail/Exceptions/MailIntegrationMissingException.php` / `MailHandlerMissingException.php` | New |
| `www/app/Externals/DirectPayments/Subscriptions/SubscriptionSyncService.php` | Added `syncAll(?$dpRef)` — the `--force-sync` path, bypassing the freshness floor |

### Webhook wiring (§6.7)
| File | Change |
|---|---|
| `www/app/Http/Controllers/Externals/Processor/ProcessorWebhookController.php` | Dispatch calls added: Stripe `recordStripeInvoiceCharge()` → `payment_succeeded` (replaced its `TODO_1` comment), `logStripeInvoiceFailure()` → `payment_failed`, `logStripeSubscription()` → `subscription_canceled` on `customer.subscription.deleted`; Square `logSquareSubscription()` → `subscription_canceled` when the normalised status becomes `canceled` (Square has no dedicated cancellation event); PayPal `logPayPalSubscription()` → `payment_failed` on `BILLING.SUBSCRIPTION.PAYMENT.FAILED`, `subscription_canceled` on `BILLING.SUBSCRIPTION.CANCELLED`. Every call is wrapped by `dispatchEvent()`'s own internal try/catch, so a notification failure can never turn a webhook's response non-2xx |

### Admin / config
| File | Change |
|---|---|
| `www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentNotificationController.php` | Added `listSends()` — §6.8's "the ledger is the report" |
| `www/routes/portal.php` | `GET /api/v1/directpayments/{dpRef}/notifications/sends` |
| `www/config/billing.php` | Added `sends_per_run` (B12's throughput cap) |

## Design decisions not fully spelled out in the plan text

- **`recipient_mode = 'handler'`'s ledger row.** The plan says this mode "leaves \[the handler's emails\] untouched" and is one `sendOne()` call, but the ledger's `recipient_email` column is part of the unique dedupe key and must be a real string. Implemented: `firstHandlerEmailLabel()` decodes the handler and uses its first configured address as a **label** for the ledger row only — the actual send still goes to whatever the handler's own `emails` array contains (`$overrideEmails = false`, so `sendOne()` never touches it). If the handler is unparseable, falls back to the literal string `'(handler-configured)'`.
- **No-resolvable-recipient ledger row** (`recipient_mode = 'customer'` with no email anywhere): inserted with `recipient_email = '(unresolved)'` so the NOT-NULL unique key is satisfiable, `status = skipped`, `error = no_recipient`, matching §5.4/§1.5's "does not retry" rule — the sentinel value is also what makes the retry-suppression real, since a genuine dedupe hit on a later tick requires the same key.
- **`buildVars()` re-queries `ProcessorSubscription`** by `provider_subscription_id` rather than reusing the row already joined in `evaluateScheduled()`'s cursor. Slightly less efficient (one extra query per candidate that reaches the day-bucket match, not per candidate evaluated) but keeps the action-event path (`dispatchEvent()`, which does have the `ProcessorSubscription` in hand from the webhook) and the scheduled path sharing one `buildVars()` implementation instead of two.
- **`card_brand`/`card_last4`** are always emitted as empty strings — no payment-method-fetch service exists in this codebase, and building one is out of this step's scope (§6.6.4 says every variable must be emitted even when empty; empty is what it gets).

## Known gap — action-event provider parity is incomplete

§6.7 describes the push path as symmetric across providers; the actual **webhook event
subscriptions already present in this codebase before this plan** are not:

| Event | Stripe | Square | PayPal |
|---|---|---|---|
| `payment_succeeded` | ✅ wired (`recordStripeInvoiceCharge`, keyed on the invoice's `subscription` field) | ❌ not wired — `payment.completed` (`recordSquareCharge`) has no reliable field linking a charge back to a subscription id in the payload this handler already parses | ❌ not wired — the subscribed events (`PAYMENT.CAPTURE.COMPLETED`, `CHECKOUT.ORDER.COMPLETED`) are order/capture events, not the recurring-billing event (`PAYMENT.SALE.COMPLETED`, per §5.3's own vocabulary table), which this webhook handler does not currently subscribe to at all |
| `payment_failed` | ✅ wired (`logStripeInvoiceFailure`) | ❌ no equivalent event subscribed at all (no `payment.failed` in the Square `match` block before or after this step) | ✅ wired (`BILLING.SUBSCRIPTION.PAYMENT.FAILED`) |
| `subscription_canceled` | ✅ wired (`customer.subscription.deleted`) | ✅ wired (detected via normalised status, since Square subscribes to no dedicated cancel event) | ✅ wired (`BILLING.SUBSCRIPTION.CANCELLED`) |

Where the push path cannot reach an event, the **pull path is the safety net by design**
(`../../../billing/01-architecture.md` §1.3) — `SubscriptionSyncService`'s sweep will eventually reflect a
Square/PayPal payment failure in `status = 'past_due'`, at which point
`08-brain-enforcement.md`'s dunning ladder (not this step) will still observe it correctly.
What is genuinely missing for Square/PayPal `payment_succeeded`/`payment_failed` is the
**push notification email** for those two specific event types on those two providers —
not the underlying state tracking. Closing this gap would require subscribing Square and
PayPal to additional webhook event types they do not currently receive
(`RegisterEventDestinationsCommand.php`'s territory), which is a larger change than this
step's file list contemplated and is flagged here rather than silently narrowed.

## Verification performed

- `php -l` on every changed/new PHP file — all clean.
- Manually traced §6.9's acceptance list against the code:
  - No active configs → phase-0 `exists()` check → `return self::SUCCESS` before any other query. ✅
  - `days_before = [7,3,1]`, one email per bucket per period: the ledger's unique key (`config, provider_subscription_id, period_end, days_before, recipient_email`) makes `$daysOut` 7/3/1 three independent keys within one `period_end`, each hit once across however many ticks match that bucket that day (the day-bucket comparison, not the ledger, is what limits it to ~1 in-window day per offset). ✅ by construction.
  - Deleting a ledger row and re-running → the unique key no longer matches → `insertPendingLedgerRow()` succeeds → sends again. ✅ by construction (this is the entire mechanism, not a special case).
  - `cancel_at_period_end = true` → excluded by the `evaluateScheduled()` query's `where('ps.cancel_at_period_end', false)`. ✅
  - Killing the mail provider → `BillingMailer::sendOne()` catches `MailIntegrationMissingException` and returns `SendResult::failed('no_provider_token')` for every call in that run (cached after the first failed resolution, so it fails fast rather than re-attempting the Brain HTTP call every recipient) → `failed` ledger rows, command still returns `self::SUCCESS`. ✅
  - `custom` mode with 3 emails → `resolveRecipients()` returns 3 entries → `dispatchToRecipients()`'s loop calls `insertPendingLedgerRow()`/`sendOne()` 3 times, one row each (B25). ✅
- **Not** run against a live database or a real `schedule:run` tick — same pre-existing environment limitation as steps 04/05 (`routes/portal.php` requires `public/_portal_/admin`, absent from this checkout, which blocks every artisan invocation here).

## Follow-ups for later steps

- Step 08 (`billing:enforce-membership`) is a second, independent consumer of `BillingMailer::sendOne()` (per §6.6's own note that it takes a mail-handler reference so the dunning ladder can call it without a `DirectPaymentNotification`) — no changes needed here for that to work.
- Step 14 adds `payment_method_changed`'s dispatch from its own controller (not a webhook), plus `subscription_cancel_scheduled` and `subscription_updated`'s webhook wiring, per §6.7's own note that those three are added there.
- The provider-parity gap above is worth its own follow-up if Square/PayPal push notifications for `payment_succeeded`/`payment_failed` turn out to matter in practice; until then the sync sweep is the correctness backstop, per this plan's own architecture.
