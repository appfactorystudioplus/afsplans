# 00 — Overview and Verdict

## 0.1 The verdict in one paragraph

The pieces exist, but they are three separate systems that were never designed to meet. The **admin inbox** is a small, global list of messages that only
three places ever write to, one of which writes it correctly; its "mark all read" button cannot reach its endpoint, and its client prints message text
into the page without escaping it. The **notification dispatcher** is good engineering — a per-event switch, a unique send ledger, a scheduler that does
nothing when nothing is configured — but it is a Direct Payments class in everything except its name, and it is the *only* place in the application that
has a per-event on/off setting; every other module simply sends every mail handler attached to the item. **Analytics** is a table the visitor's browser
writes to through a public endpoint, a rollup table nothing writes to, and a summary that loads every event in the window into PHP memory; the server
never records an analytics event of its own, so "a payment was made" is invisible to it. The plan below keeps what is right, removes the Direct
Payments coupling, gives the inbox and the e-mail the same per-event switches, and puts one small input — an `Activity` — in front of both the
notification system and the analytics recorder so a module says what happened exactly once.

## 0.2 What exists today, in one picture

```
 browser (visitor)                         www server                                       admin dashboard
 ─────────────────                         ──────────                                       ───────────────
 page_load, form_loaded ──POST──▶ AnalyticsApiController::create ──▶ laravel_analytics_events
                                  (public, throttled)                       │
                                                                            ▼ (summary loads ALL rows into PHP)
                                                              AnalyticsApiController::summary ────────────▶ Analytics screen
                                  laravel_analytics_daily_rollups  ◀── nothing writes it
                                                │
                                                ▼ (reads it, finds it empty → 0)
                                  WwwAnalyticsProvider (afs-connect)

 Stripe/PayPal/Square ──webhook──▶ ProcessorWebhookController
                                       ├─ invoice.payment_succeeded ─▶ NotificationDispatcher::dispatchEvent('payment_succeeded')   ┐ may send
                                       │                                   (needs an active DP notification row)                     │ TWO e-mails
                                       └─ charge.succeeded ─▶ ChargeSucceeded ─▶ SendDirectPaymentMail (30 s) ─▶ receipt e-mail      ┘ for one payment

 5 other modules (forms, events, appointments, ecommerce, site users) ─▶ their own *Mailer ─▶ MailHandlerSender  (no per-event switch)
 forms job, sponsorship controllers ─▶ Notification::create ─▶ laravel_notifications ──GET /notifications (15 s poll)──▶ the bell
```

Three different things are called a "notification" in this code base: a row in `laravel_notifications` (the bell), a row in
`laravel_direct_payment_notifications` (a per-event e-mail setting), and an e-mail send. That is the confusion this plan removes.

## 0.3 What works today — keep it

| Area | What is right | Where |
|---|---|---|
| Per-event switch | One row per (Direct Payment, event) with its own `is_active`; the unique key means an event cannot be configured twice | `laravel_direct_payment_notifications`, `DirectPaymentsController` save path |
| Send ledger | A unique key over (config, subscription, period, days, recipient): an e-mail is never sent twice by the scheduler *and* a webhook, and a `failed` row is deliberately not retried | `laravel_direct_payment_notification_sends`, `NotificationDispatcher::insertPendingLedgerRow()` |
| Zero work when idle | The 15-minute command does one indexed query and exits when no row is active | `DirectPaymentsSubscriptionNotify::handle()` phase 0 |
| One recipient per send | Each recipient is its own send, so one bad address cannot hide the others | `NotificationDispatcher::dispatchToRecipients()` |
| Failure never reaches the caller | A webhook must not fail because an e-mail did | `NotificationDispatcher::dispatchEvent()` |
| Default templates | Built-in e-mails that need no database row, with brand fallbacks | `DirectPaymentDefaultEmail`, `…/mjml/defaults/dp/` |
| One send seam | `MailHandlerSender` is the only place an e-mail leaves; the transport is replaceable in tests | `MailTransport`, `MailHandlerTransport` |
| Inbox API shape | CRUD + archive + read flags, consumed by the dashboard and the external SDK (`window.Portal.notifications`) | `NotificationApiController`, `resources/js/external/api/notifications/api.ts` |
| Module reporting from truth tables | Events and appointments reports read their own rows, never a statistics copy | `EventReportingService`, `AppointmentReportingService` |

## 0.4 What is wrong — the eleven that matter most

Full list with evidence in [01](01-audit-findings.md).

| # | Finding | Why it matters | Fixed in |
|---|---|---|---|
| 1 | "Mark all read" is declared after `PATCH /notifications/{id}` with no constraint, so the request is matched as an id and 404s; the client then marks everything read locally and the next 15-second poll puts it back (N1) | The button does nothing that lasts | [03](03-admin-inbox.md), P0 |
| 2 | The bell renders `title` and `body` into `innerHTML` unescaped; one producer builds `body` from sponsor-typed text (N2) | Stored cross-site scripting in the admin dashboard | [03](03-admin-inbox.md), P0 |
| 3 | Only the newest 20 notifications are ever fetched, and the unread badge counts those (N4) | An admin with 40 unread sees "20" | [03](03-admin-inbox.md), P3 |
| 4 | Two of the three producers are broken or unverified: one writes columns that do not exist, one passes an array to a text column (N6) | Sponsorship notifications are lost or fail silently | [03](03-admin-inbox.md), P0 |
| 5 | `NotificationDispatcher` is named general and is Direct Payments throughout: its config table, ledger, recipient lookup, variable builder, vocabulary (M1) | No other module can use it | [04](04-dispatcher-extraction.md), P1–P2 |
| 6 | A successful payment can send two e-mails — the receipt and a `payment_succeeded` notification — through two unrelated mechanisms (M2) | Duplicate mail, and nobody can say which is "the" payment e-mail | [06](06-activity-fan-out.md), P4 |
| 7 | A Direct Payment with no notification row and no attached handler sends **no** receipt; the built-in default is only reached through a row (M3) | A new site sends nothing until someone configures it | [02](02-target-model.md) §2.6, [10](10-open-questions.md) OQ7 |
| 8 | The server never records an analytics event; the only writer is a public, unvalidated browser endpoint (A1) | Payments, signups and bookings cannot appear in analytics; anyone can poison the data | [05](05-analytics.md), P4 |
| 9 | The rollup table is never written, and the connect provider reads it in preference to the events table, so it always reports 0 page loads (A2, A3) | The afs-connect summary is wrong today | [05](05-analytics.md), P0 |
| 10 | The analytics summary loads every event in a window of up to 365 days into PHP memory and loops over it once per day (A4) | The screen slows with the data and eventually fails | [05](05-analytics.md), P4 |
| 11 | **Nothing deletes anything on a tenant site.** The scheduler has no cleanup; raw analytics events, the send ledger, full webhook payloads, rejected-request logs and expired sessions and tokens only ever grow, and most of those tables have no index on their time column (D1–D8) | The database grows with traffic without limit; a delete-by-age would scan the whole table | [08](08-data-lifecycle.md), P7 |

## 0.5 The target in one picture

```
   module code (a controller, a webhook, a job)                      says what happened ONCE
            │
            ▼
   ┌───────────────────────────────────────────┐
   │ ActivityPublisher::publish(Activity)      │   explicit: two named dependencies, two try/catch blocks,
   │   module · event · scope · subject ·      │   no event bus to hunt through, never throws to the caller
   │   value · currency · data · dedupe key    │
   └───────────┬────────────────────┬──────────┘
               │                    │
               ▼                    ▼
   NotificationDispatcher       AnalyticsRecorder                  two systems, one input, no shared class
    ├─ InboxChannel  ─▶ laravel_notifications  ─▶ the bell        ├─ laravel_analytics_events (source = server)
    └─ MailChannel   ─▶ MailHandlerSender      ─▶ the e-mail      └─ laravel_analytics_daily_rollups (scheduled)
         │   rules: laravel_notification_rules (per module · scope · event · channel)
         └─  ledger: laravel_notification_sends (unique, never double-sends)
```

## 0.6 Rules the rest of the plan follows

1. **Say it once.** A module publishes one `Activity`. It never calls the notification system and the analytics recorder separately, and neither
   system knows the other exists.
2. **Per-event switches, per channel.** Every event has an inbox switch and an e-mail switch, on every scope that can have a rule. (Owner, 2026-10-19: "per event switches".)
3. **Modules own their vocabulary; the system owns the mechanics.** Event names, labels, variables, recipients and default templates are registered by
   the module. The dispatcher, the ledger, the channels and the editor are generic.
4. **Failure never reaches the business flow.** Publishing is best-effort, logged, and swallowed — the rule `dispatchEvent()` already follows.
5. **Idempotent by key.** Every `Activity` that can be replayed (a webhook retry, a job retry) carries a dedupe key; both systems honour it.
6. **Analytics is not the source of truth for money.** Revenue and refunds stay in invoices and the money ledger; analytics holds counts, funnels and
   dashboard values, and says so on screen ([05](05-analytics.md) §5.1).
7. **No personal data in analytics.** Server-recorded events carry references and amounts, never names or e-mail addresses.
8. **No `app()`.** New code is constructor-injected; the only registrations are in one service provider.
9. **Nothing renames the cron command.** `direct_payments:subscription-notify` stays — the crontab bootstrap and `BrainCommandsEndpointTest` depend on it.
10. **Rapid data is bounded.** Every table that grows with activity has a retention policy or is listed as kept with a reason; detail is deleted only after the summary that
    replaces it exists; a run never deletes most of a large table without a supervised `--force` ([08](08-data-lifecycle.md)).
