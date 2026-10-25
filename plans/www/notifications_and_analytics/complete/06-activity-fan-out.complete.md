# 06 — Activity Fan-out (complete — one publish reaches the notification system and analytics; a successful charge is one `payment_succeeded`; proved by tests, not yet run on a server)

## Status

**Complete 2026-10-19.** Nothing is committed. `ActivityPublisher::publish()` hands an Activity to the notification system and to analytics, independently of each other. The e-mail of an announced event is queued instead of sent inside the request.
Direct Payments' webhook events go through it, and — with the owner's go-ahead on OQ13 (a) — **a successful charge is now ONE event, `payment_succeeded`**, announced by `ChargeSucceeded` for every charge (single payment or renewal): the site owner can see it on the
bell, the payer gets the receipt as the Direct Payment's rule says, and it is counted once in analytics.

Closes **M2** (one payment could send two e-mails), **M10** (the e-mail was sent inside the webhook request) and **X2** (one call site). Everything is in `w/www`.

## Phases

| Phase | Piece | State | Where |
|---|---|---|---|
| P4 | `ActivityPublisher` (notifications first, then analytics; each swallowed; unknown pair = one warning; `ACTIVITY_ENABLED=false` switches it off) | **built** | `app/Services/Activity/ActivityPublisher.php`, `config/activity.php` |
| P4 | `DeliverNotificationMail` (one recipient, delayed by `mailDelaySeconds`, `tries = 1`, builds its variables when it runs, finishes the `pending` ledger row) | **built** | `app/Jobs/DeliverNotificationMail.php` |
| P4 | The dispatcher queues the e-mail of an announced event; the scheduled run still sends inline | **built** | `app/Services/Notifications/NotificationDispatcher.php` |
| P4 | "Attached handlers" compatibility mode in the dispatcher | **built** | `NotificationDispatcher::deliverAttachedHandlers()` |
| P4 | Direct Payments webhook events publish; four events are counted in analytics | **built** | `Externals/DirectPayments/Notifications/NotificationDispatcher.php`, `DirectPaymentsActivityModule.php` |
| P4 | `ChargeSucceeded` publishes `payment_succeeded` for every charge; the webhook no longer announces it | **built** | `ChargeSucceeded.php`, `ProcessorWebhookController.php`, `DirectPaymentsActivities::charge()` |
| P2/P4 | `payment_receipt` folded into `payment_succeeded`: the module, the invoice variables and recipient, the data merge, the save path, the form | **built** | `DirectPaymentsActivityModule.php`, `DirectPaymentsInvoiceVariables.php`, migration `2026_10_19_000005_…`, `DirectPaymentsController::foldPaymentReceipt()`, `directpayments-form.html` |
| P6 | Deleting `SendDirectPaymentMail`, the receipt service's rule logic, the old adapter class | **not done — by design, a later release** | — |

## What was built

- **`ActivityPublisher`**: checks the catalogue, calls `NotificationDispatcher::dispatch`, then `AnalyticsRecorder::record` (unless the event opts out), each in its own `try`. Neither failing stops the other or reaches the caller. Gate G2 (a test) keeps every other file from
  calling the recorder or the dispatcher, except the named Direct Payments entry points.
- **Queued e-mail.** The dispatcher claims the ledger row (`pending`) and pushes `DeliverNotificationMail` with `delay(mailDelaySeconds)`. The job rebuilds the template variables when it runs (so the card details that arrive with `charge.succeeded` are there), sends, and writes
  `sent`/`failed`. A row that is no longer `pending` is left alone, so a second run sends nothing; a failed send is final. If the queue itself refuses the job the row says `failed: queue_failed …`. With the synchronous driver the job runs at once and the dispatcher counts the real
  outcome. The 15-minute scheduled run still sends inline.
- **One event for a charge.** `ChargeSucceeded::handle()` keeps its lock and its `mail_queued_at` marker and now publishes `DirectPaymentsActivities::charge($invoice)`: scope = the Direct Payment, subject = the invoice, value and currency, `kind` = `single` or `renewal`, dedupe key =
  the invoice. A webhook delivered twice, a retried job and `direct_payments:repair-charge-hooks` therefore produce one bell notification, one e-mail per recipient and one analytics event. `invoice.payment_succeeded` no longer announces anything (it announced for subscription invoices only,
  which is why a subscription payment could send two e-mails).
- **What the event sends** (`DirectPaymentsActivityModule::paymentSucceeded()`): with a **rule** for the Direct Payment, the rule decides (handler or the built-in receipt, recipients, the e-mail and dashboard switches); with **no rule**, the mail handlers attached to the Direct Payment are sent
  exactly as before (minus any a rule of that Direct Payment has chosen); with no rule and none attached, **silence on an existing site, the built-in receipt on a new install** (`notifications.use_event_defaults`, OQ7 (c)). The bell is off unless a rule switches it on; when on it reads
  "Payment received — 25.00 GBP", severity success. The e-mail waits `direct_payments.mail_delay_seconds` (30).
- **The receipt's values** come from the invoice (`DirectPaymentsInvoiceVariables`, the same values and provider overrides the old receipt used); the recipient is the invoice's `email` value, and the guest placeholder address is not a recipient (recorded as `skipped: no_recipient`).
- **The data merge** (migration `…000005`): a Direct Payment with only a `payment_receipt` rule gets it renamed to `payment_succeeded`; only `payment_succeeded` is left alone; both → `payment_succeeded` takes the receipt's handler and recipients, its e-mail switch is on if either was, the receipt row is removed, and a log line names the Direct Payment. Idempotent; only Direct Payments rows; the old
  tables are untouched.
- **The admin form.** The *Payment receipt* choice is gone from the notification drop-down and *Payment succeeded* now reads "Payment received (the payer's receipt)". A page cached from before that still sends `payment_receipt` is understood by the save path (renamed, or merged with the
  `payment_succeeded` entry, the receipt's settings winning).
- **Analytics.** `payment_succeeded` (value in cents, currency, `kind`), `payment_failed`, `subscription_canceled`, `subscription_updated` are recorded; the analytics keys are `processor_reference_num`, `subscription_status`, `kind` — no name or address.

## Tests

PHP on MariaDB 11.4.5, port 3307.

| File | Tests | Proves |
|---|---|---|
| `Feature/Notifications/ActivityPublisherTest` | 17 | one publish = bell + e-mail + analytics; replay changes nothing; each consumer failing is isolated; unknown pair; the config switch; the e-mail is queued with the event's delay; the job finishes the row once, builds variables when it runs, a failed send is final, a queue that refuses; the scheduled run stays inline |
| `Feature/DirectPayments/PaymentSucceededFoldTest` | 15 | **one charge = one bell notification, one receipt, one analytics event**; delay and not-in-the-request; the hook running again (also with the marker lost) changes nothing; renewal vs single; no Direct Payment = not announced; e-mail off / bell on; a rule chooses handler and recipients (one call each); **no rule: existing site silent but counted, new install sends the built-in receipt and the bell stays empty**; attached handlers sent as before; a handler a rule chose is not sent twice; a rule switching the e-mail off beats attached handlers; guest checkout skipped; **no mail provider: the bell and analytics exist and the ledger says why**; the old job is no longer queued |
| `Feature/Notifications/MergePaymentReceiptRulesTest` | 7 | receipt only; succeeded only; both (handler/recipients/switch/log); both off; per Direct Payment and other modules untouched; idempotent; no table |
| `Feature/DirectPayments/DirectPaymentsAnalyticsFanOutTest` | 4 | a webhook event notifies as before and is counted with references only; the four counted events |
| `Feature/DirectPayments/NotificationDispatchCharacterizationTest` | 17 | **unchanged** and green — the notification behaviour of the scheduled and webhook path did not change |
| `Feature/DirectPayments/DirectPaymentsNotificationModuleTest` | edited +2 | thirteen events; receipt defaults; the save path understands `payment_receipt` |
| `ChargeSucceededHookTest`, `PaymentGapsTest`, `DefaultEmailsTest` | edited | they asserted `SendDirectPaymentMail` was queued; they now assert the delivery job (with a rule switched on) — same scenarios, same counts |
| `Unit/Analytics/AnalyticsGatesTest` | +1 | gate G2 for the dispatcher |

## Final run

Run once at the end, one process at a time, against MariaDB 11.4.5: `tests/Unit`, `Feature/Notifications`, `Feature/Analytics`, `Feature/Externals`, `Feature/DirectPayments`, `Feature/Processor` — **1167 tests, 4466 assertions; the only errors are the 18 of the known Windows `LaravelCronScriptTest` setUp problem** (the same as steps 04 and 05). `npx tsc --noEmit` clean. Not run: the other Feature folders (Admin, Appointments, Builder, Connections, Ecommerce, Events, Forms, Members, Membership, Observers, SiteUsers).

## Deviations from the plan text

1. **`ActivityPublisher` calls `NotificationDispatcher::dispatch(Activity)`**, which looks the event up itself, instead of `dispatch($activity, $event)`.
2. **The Direct Payments entry class is kept** for webhook events: `dispatchEvent()` keeps its signature and publishes inside, so the two controllers are unchanged (gate G2 lists them by name). The webhook `payment_succeeded` call is gone.
3. **`ChargeSucceeded` asks the container for the publisher** (`app(ActivityPublisher::class)`, with a comment): it is a static hook called from seven places that cannot hand it one. The only `app()` this step adds.
4. **No rule + nothing attached → silence on existing sites, the receipt on new installs** — the plan's §6.4 table says "defaults apply: e-mail on", §6.9 says "the payer receives the built-in receipt". I followed OQ7 (c), the flag the plan already recommends, so an upgrade cannot start e-mailing payers. Turn `NOTIFICATIONS_USE_EVENT_DEFAULTS` on (or set it in the clean-install data) to get §6.9 literally.
5. **`payment_succeeded.mjml` stays in the folder, unused**; the event's built-in e-mail is `payment_receipt.mjml` (the plan's OQ13 decision). The manifest keeps both entries.
6. **Old receipt code stays for one release**: `SendDirectPaymentMail`, `DirectPaymentCommunicationService::paymentSucceeded()` and the `payment_receipt` lookups are no longer called by the hook, but remain (and keep their own tests) until plan P6.
7. **A handler attached to the Direct Payment gets its own ledger identity** (`attached:<handler>`), so two attached handlers sent to one payer are two sends.

## Not verified

- Nothing was run on a server; no real queue worker, no real e-mail. The delay and `tries = 1` are asserted on the job with the queue faked; the job itself is run by hand in the tests. A real payment (sandbox charge → webhook → receipt) has not been tried (handoff §7.9).
- **Deploy order:** `migrate --force` first (the merge runs after the copy migration and the analytics extension), then the new code. A site where the migrations have not run behaves as "no rules" (silent), not as an error.
- With a `database` queue the receipt leaves within about a minute (the existing `queue:work` schedule line). A site whose worker does not run would leave rows `pending`; step 08 turns old `pending` rows into `failed: stale_pending`.
- The admin form was not opened in a browser; the removed drop-down choice and the label are the only front-end changes (`tsc` is clean).
- **Behaviour that changes for a live site** (the OQ13 answer): `payment_succeeded` now also fires for single payments; a Direct Payment with both old rows has them merged (logged); subscription payments no longer announce `payment_succeeded` from the invoice webhook.

## Next

Step **07** (the admin UI: the generic rule editor built from the catalogue, the sends panel with *Resend*) and step **08** (the data lifecycle). Plan P6 later deletes the old receipt job and service once a release has run with nothing reading them.
