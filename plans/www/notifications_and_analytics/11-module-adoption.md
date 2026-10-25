# 11 — Module Adoption: Forms, Sponsorships, Ecommerce, Events, Appointments, Site Users, Subscribers

Steps 02–09 built the general system and moved **Direct Payments** onto it. This step is the plan for the other seven modules. It is one step with seven parts (one per module) and a shared groundwork part that must land first. **Nothing in this step is built.**
Every statement about today's code was read from `w/www` on 2026-10-19; where a fact still has to be checked when the work starts, the text says **Verify**.

The order is the owner's answer to OQ16 ([10](10-open-questions.md)): **Forms → Sponsorships → Ecommerce → Events → Appointments → Site Users → Subscribers.**

## 11.0 What "adopting" means, and what it must not change

A module is *adopted* when it announces what happened **once**, through `ActivityPublisher` ([06](06-activity-fan-out.md)), and everything the owner sees follows from that one announcement:

| Before | After |
|---|---|
| A bell notification written by hand, or none | A bell notification the owner can switch on or off **per item** (per form, per event, per calendar, per store…) |
| E-mails sent by the module's own `*Mailer` / `*CommunicationService` class, called from a controller or service | The same e-mails, sent by the dispatcher (queued, once, in the send ledger), to the same people, with the same mail handlers |
| Nothing in analytics, or only what the visitor's browser reports | A server-recorded analytics event per important thing that happened (a count and references; **never money** — money is recorded once, by Direct Payments, 11.1.2) |
| Vocabulary written in the module's code and its admin screen | One list of events, in a module class, read by the editor from `GET /notification-catalog` |

**The rule that governs the whole step — adoption changes no behaviour until the owner opts in.** With no saved setting for an event, the module does what it does today: the bell appears if it appeared before, the mail handlers attached to the item are sent if they were sent before, and nothing new is e-mailed. The mechanism already exists and is tested for Direct Payments: `EventDefinition::$attachedHandlers` ("with no rule, send the handlers attached to the item") and defaults that are `false` for every event that did nothing before. A module's characterization tests (11.1.8) prove it.

**Out of scope for every module:** bulk mailing (the Subscribers article mailing), credentials and tokens in e-mail (11.1.3), the module's own audit/history tables (they stay; retention is decided in [08](08-data-lifecycle.md) `keep`), changing what any template says.

## 11.1 Shared groundwork — lands before the first module

These are changes to the **general** layer that every module needs. They are small, additive and tested on their own; none changes Direct Payments' behaviour. Build them as one piece (phase **M0**).

### 11.1.1 Attached handlers must honour the event's recipient mode

`NotificationDispatcher::deliverAttachedHandlers()` builds its stand-in rule with `recipientMode: 'customer'` for every module (`app/Services/Notifications/NotificationDispatcher.php`). That is right for a payment receipt, wrong for Forms, where the attached handler **carries its own recipients** (the form owner's addresses — `FormsMailer::configuredEmails()`), and for Sponsorships/Subscribers where the audience differs.
**Change:** use `$event->recipientMode` for the stand-in rule. `customer` keeps today's meaning (the module's resolver); `handler` sends one call per handler with `overrideEmails = false`, so the handler's own addresses are used and the ledger row's recipient is the handler's label. **Test:** a fake module with a `handler`-mode event and two attached handlers → two sends, each with its own addresses, none overridden; the Direct Payments tests unchanged.

### 11.1.1b Two small, defaulted additions to the module contract

Both are optional methods on `ActivityModule` with a default implementation, so Direct Payments and the test modules are untouched:
- `defaultsApply(Activity): bool` (default `true`) — lets a module say "do not apply this event's defaults to *this* Activity" (11.1.2).
- `attachedHandlerKey(Activity, EventDefinition): ?string` (default: the Activity's scope reference) — the value looked up in `laravel_mail_handlers.item_reference_num` for the compatibility mode. Site Users needs it because its handlers are keyed `<site_users_id>:<purpose>` (11.7); every other module uses the default.
**Test:** a fake module overriding each; the default path unchanged.

### 11.1.2 Money that a module owns is announced once

Events, Appointments, Ecommerce and (after F4) Sponsorships are **paid through a Direct Payment** (the façade model of `plans/www/ecommerce/AUDIT-direct-payments-decoupling.md`: the module stores references, Direct Payments holds the money). `ChargeSucceeded` therefore already publishes `direct_payments.payment_succeeded` for every such charge — and with event defaults on (OQ7), the payer would get **the Direct Payments receipt *and* the module's confirmation**, and analytics would count the money **twice** (once as a payment, once as an order/registration/booking with a value).

Decision (OQ25, answered 2026-10-19): **Direct Payments announces the money; the module announces the thing bought; module events carry no money; the payer gets one confirmation.** Direct Payments must not learn the names of its callers (the owner's rule, 11.1.2b), so the mechanism is a **payment option, not module knowledge**:

1. **A generic invoice option `receipt`** (`auto` — the default — or `none`), carried in the invoice's `metadata` by whoever builds the invoice (the client SDK; `metadata` is the free-form bag the façades already use for their own tags). It is an ordinary payment-API idea ("do not send the payer a receipt for this charge, the caller will confirm"), like the receipt switch of any payment provider; Direct Payments reads it and nothing else about the caller. `DirectPaymentsActivities::charge()` copies it into the Activity's `data['receipt']`.
2. Direct Payments' own module answers the new optional contract method `ActivityModule::defaultsApply(Activity): bool` with `false` for `payment_succeeded` when `data['receipt'] === 'none'` — so the event's **default** e-mail does not apply to that charge. An **explicit rule** on that Direct Payment still works (the owner can choose to send it).
3. **Module façades set `receipt: none`** when they build their invoice and send their own confirmation (Ecommerce's component script already stamps a `metadata` object with its tag; the option is added beside it; Events and Appointments do the same after the façade refactor of 11.13). **Sponsorships is the one exception: it sends no payment confirmation of its own, so its invoices keep `receipt: auto`** and the donor gets the Direct Payments receipt.
4. **Money in analytics is recorded once, and only by Direct Payments.** Module events carry **no `value_cents` and no currency, ever** — a count and references. Reports and the summary add up money only from `direct_payments.payment_succeeded`. (There is no "module whose money is not through Direct Payments": by the owner's rule there is none.)
5. The quarterly report's "payments" headline ([08](08-data-lifecycle.md) §8.6) and 05 §5.9's reconciliation count `direct_payments.payment_succeeded` only, so both stay correct.

**Test:** a charge whose invoice says `receipt: none` → one Direct Payments event (no default receipt; a rule still sends), one module event with no value, analytics: one payment with value and one module event without.

### 11.1.2b The owner's rule about money, and what the code does today (2026-10-19)

> **Rule (owner).** A module that uses Direct Payments **manages no money**. If it needs a payment, Direct Payments confirms it; the payment is recorded in the Money section and controlled by Direct Payments.
> **Reading is allowed and expected, one way only:** *module → Direct Payments → processor.* A module may ask Direct Payments for any fact it needs (is it paid, how much, which currency, refunds, a list of a person's payments) and Direct Payments may ask the processor; a module showing a payer their own payments is exactly this.
> **Two hard stops:** (1) **Direct Payments knows nothing about any other module** (no module name, no module logic, nothing flowing the other way); (2) **a module never stores or manages money data** — no amount, paid-amount, status words, charge ids, price columns; it stores *references* and resolves the figures on demand.

This is the **façade model** already written down for Ecommerce (`plans/www/ecommerce/AUDIT-direct-payments-decoupling.md`): a façade stores **three reference strings per purchase** (the Direct Payment, the processor, the invoice) and nothing about the money; the invoice is built **by the client** through the Direct Payments SDK; the module asks `PaymentGateway::isPaid()` / `snapshot()` / `snapshots()` and recovers lost purchases with `paidInvoicesAwaiting($facade, $since)`. The gateway contract is **read-only** and takes the façade tag as an opaque string — Direct Payments does not interpret it.

**State of each module** (read 2026-10-19), judged by the two hard stops. A one-way read is never counted as a gap.

| Module | Hard stop 2 — manages money? | What to remove |
|---|---|---|
| **Ecommerce** | **No.** Order created only after `isPaid()`; no money columns (dropped by the clean-break migration); all figures resolved from the snapshot. Reading the processor's currency through Direct Payments is an allowed one-way read | Nothing. (`ensureDiscountItem` is the audit's documented admin-time exception.) |
| **Events** | **Partly.** `EventOrder` stores three references and no money, and `EventOrderService` confirms through `PaymentGateway`. But `EventPaymentService::createInvoiceForOrder()` **builds and writes the invoice on the server**: it computes `amount_cents` and creates a `DirectPaymentInvoice` row carrying the module's own tags | The server-side invoice building (F2) |
| **Appointments** | **Yes.** The booking has a **`paid_cents` column**; the module builds the invoice on the server, **reads the invoice row** with a hard-coded list of Direct Payments status words (`paid`/`succeeded`), and **writes the amount back** (`recordPayment`, the activity-log row's `paid_cents`) | `paid_cents`, the invoice building, the invoice-row read, the written-back amount (F3) |
| **Sponsorships** | **Yes, in two places.** (1) The application stores `amount_paid`, `processor_reference_num`, `processor_customer_id` taken from the request body, unverified. (2) Each **level stores its own price** (`amount_type`, `amount_value`, `amount_min`, `amount_max`). And a sponsor currently has **no way to see what they have paid** | The three application fields, the level price columns, and a payments view built on the allowed read (F4) |
| Forms, Site Users, Subscribers | No payments | — |

**Direct Payments' side of the rule.** A search of `app/Externals/DirectPayments`, the Direct Payments controllers, the models and `app/Mail/Externals` for any other module's name finds **one** reference: `PayerService::resolveForSiteUser()` (and the stored value `created_by = 'site_user'`). Everything else names only the opaque *façade* tag. So Direct Payments needs **no new knowledge of any module** — and this step adds none (11.1.2 uses a payment option). The one name is cleaned up in 11.13 (F5).

**Therefore the work is not a Direct Payments change; it is bringing Appointments, Events and Sponsorships to where Ecommerce already is** — phase block **F** (11.13), sequenced before each module's adoption so the call sites are not rewritten twice. Notification adoption itself is unaffected by it: a module's Activity carries no money whichever side builds the invoice.

### 11.1.3 Secrets never travel through an Activity

Two modules send e-mails whose **point** is a secret: Site Users (a verification / password-reset / e-mail-change token inside a link) and Sponsorships (a generated password). An `Activity` is serialised into a queued job and into logs, and the queue is the database. A secret in `Activity::$data` would be stored in plain text in `jobs`.
**Rules (enforced, not advised):**
- `Activity`'s constructor rejects any `data` key that looks like a secret (`/pass(word)?|token|secret|api_?key|otp|credential|authorization|ticket/i`, at any depth; `ticket` keeps an event's bearer ticket code out too, 11.5) with an `InvalidArgumentException`. **Test:** each of those key names is refused; ordinary keys (`bucket`, `invoice`, `kind`) are not.
- E-mails that carry a secret **stay on the module's direct, inline send path.** They are not announced through the system and not queued. What *is* announced is the non-secret fact ("a sponsor application was approved", "a site user registered") — see 11.3 and 11.7.
- Token e-mails also must not be delayed: a password reset that arrives a minute late is a support ticket.

### 11.1.4 One variable contract per module

The existing mail handlers use **module-prefixed variable names** (`forms_*`, `commerce_*`, `events_*`, `appointments_*`, `site_users_*`; Direct Payments uses `direct_payments_*`). A module's `VariableBuilder` must emit **exactly the names its current service emits** — the handlers authors already used them — and values must be the same text. **Test (golden):** for each module, build the variables with the new builder and with the legacy service from the same fixture and assert the two maps are equal (the legacy method is called through reflection or its variable-building part is extracted unchanged and shared — extraction is preferred: one function, two callers during the transition, one after).

### 11.1.5 Built-in default e-mails: none in this step

Today, when no handler is attached, **nothing is sent** (the `*_obsolete` Mailable paths are not called). Adoption keeps that: every adopted event has `defaultTemplate: null` and `mailDefault: false`, so event defaults (OQ7) cannot start sending anything. Adding built-in MJML defaults for a module is a later, per-module decision (it needs the template files and a manifest like Direct Payments' `defaults.json`). The single exception inside this step is Sponsorships' denial e-mail (S2, 11.3), which replaces a hard-coded e-mail that exists today and so changes nothing for the recipient. The `*_obsolete` methods and Mailables stay until then and are listed for removal in 11.9.

### 11.1.6 Queued e-mail changes timing — decide once (OQ26)

Confirmations are sent **inline** today (inside the request or the job that completed the booking). Through the dispatcher they are **queued** (`DeliverNotificationMail`) and leave within about a minute (the `queue:work` line in `routes/console.php`). **Decided (OQ26, 2026-10-19):** accept the delay for every module except the secret-carrying e-mails of 11.1.3 (which do not move). If a module must stay immediate, `EventDefinition::mailDelaySeconds` is 0 *and* the dispatcher gets a `queueMail: false` flag per event for inline delivery — a two-line addition to `deliverMail()`, only built if the owner asks.

### 11.1.7 The admin card, once

`NotificationRulesEditor` ([07](07-admin-ui.md)) is mounted in each module's admin screen with three lines (`module`, `scopeRef` = the item's reference, optional `variant`) and one card in the module's HTML. Where the item is created and saved differs per module; each part below names the controller. Two shared facts: (a) every module's admin screen already lets the owner **author the attached mail handlers** with `EmailConstructionBuilder` and `dataStore.retrieveEmailHandler(itemRef)` — the editor does not replace that, it sits beside it and the *Send from* list offers the same handlers; (b) saving rules is a **separate request** from saving the item (`PUT /notification-rules`), so a module's own save endpoint is not changed. The Direct Payments form saving rules inside its own payload (07 §7.2) is a one-off kept for compatibility.

### 11.1.8 How each module is proven, in the same order every time

1. **Characterization first.** Before touching the module, write tests that drive its *public* paths and record what it does today (which handlers are sent, to whom, with which variables; which bell row; how often when called twice). They must be green on the old code. (This is how step 04 proved Direct Payments unchanged: 17 tests, unedited.)
2. **Module class + Activity builder** (`app/Externals/<Module>/Notifications/…`, like `DirectPaymentsActivityModule` / `DirectPaymentsActivities`), registered in `ActivityServiceProvider` (one line).
3. **Switch the call sites** to `ActivityPublisher::publish()`; delete the hand-written bell and the direct handler send **in the same change**.
4. **Run the characterization tests unedited.** If one needs editing, the adoption is wrong, not the test.
5. **New tests:** per-event rules (bell only / e-mail only / both / neither), dedupe on replay, the editor catalogue, analytics recorded once with no personal data, queue behaviour.
6. **Gate updates** (11.9) and a completion record `complete/11-<module>.complete.md`.

### 11.1.9 What every Activity carries

`module`, `event`, `scopeRef` = the item a rule hangs off (form, event, calendar, store, site-users module, sponsorship, subscriber list), `subjectRef` = the thing it happened to (submission, registration, booking, order, application, subscriber), `dedupeKey` = `Activity::dedupeKeyFor(module, event, subject[, bucket])`, `actorRef` ≤ 64 characters when the actor is a stored person, `data` = plain values only (no objects, no secrets). `analyticsKeys` are references and enumerations; **no names, e-mail addresses, phone numbers or free text** (the existing test `AnalyticsGatesTest` already scans every catalogue event; it keeps scanning).

---

## 11.2 Forms (first)

### Today
- **Announce point:** `FormsPublicController::submit` → saves the submission → `FormEvent submission.created` → `ProcessFormSubmission::dispatch($submission->id)` (`app/Http/Controllers/Externals/Forms/FormsPublicController.php:196`). A retried browser call with the same `idempotency_key` returns the same submission (lines 101–105), so the job is dispatched once per real submission.
- **The job** (`app/Jobs/ProcessFormSubmission.php`) does four things in order: (1) `createAdminNotification()` — a hand-written bell row, `firstOrCreate` on `forms.submission_received.<submission>` (always on, titled "New form submission", body `Form "<name>" received a new submission.`, link `/portal/admin/forms`); (2) `FormsMailer::sendConfiguredHandlers()` — **every** mail handler whose `item_reference_num` is the form's reference, each to **its own configured `emails`** (the form owner's addresses, not the submitter's), variables `forms_form_name`, `forms_submitter_email`, `forms_submitted_at`, `forms_answers` (HTML); (3) `FormWebhookDispatcher::dispatch()` — outbound webhooks; (4) a `laravel_form_events` audit row `mail.sent` (written **even when no handler exists** — it is a misnomer) or `mail.failed` per handler error.
- **Forms inside Events:** registration can reference a form submission (`EventRegistrationService` checks the submission exists). It does not send through the Forms job; no change.
- **Analytics today:** only the browser (`forms.form_loaded`, `forms.form_completed`).

### Target events

| Event | Bell · e-mail default | Recipients | Scope · subject | Analytics | Publish point |
|---|---|---|---|---|---|
| `submission_received` | **on** · off (`attachedHandlers` = the handlers attached to the form keep being sent) | `handler` mode: the handler's own addresses; a rule may choose `custom` addresses | form ref · submission ref | yes, count only | `ProcessFormSubmission::handle()` (replaces steps 1 and 2) |

One event, deliberately: a spam-scored submission (`validation_state = unverified`) is still a submission. A separate `submission_flagged` (high `spam_score`) bell was declined (OQ27).

### What changes
- `app/Externals/Forms/Notifications/FormsActivityModule.php` + `FormsActivities.php`. The Activity carries only the `submission` and `form` references; the template variables are read when the e-mail is built, by `FormsVariableBuilder`, which calls a `FormsMailer::variablesFor($form, $submission)` extracted unchanged from `sendConfiguredHandlers()`.
- `EventDefinition('submission_received', inboxDefault: true, mailDefault: false, attachedHandlers: true, recipientMode: 'handler', analyticsKeys: [], inboxTitle: 'New form submission', inboxBody: 'Form "${form_name}" received a new submission.', inboxLink: '/portal/admin/forms', severity: 'info')`. The bell **text is the text it has today**.
- `ProcessFormSubmission::handle()`: replace `createAdminNotification()` and `sendConfiguredHandlers()` by one `ActivityPublisher::publish()` (injected). Webhooks and the audit row stay. The `mail.sent` row becomes accurate: written only when at least one send was attempted; `mail.failed` rows come from the ledger's `failed` outcomes of this submission (**decided, OQ27:** the ledger replaces `mail.sent` and `mail.failed`; the ledger and the editor's *Recent sends* drawer are the place to look).
- Delete `createAdminNotification()` and `FormsMailer::sendConfiguredHandlers()` (keep the extracted variable function). `FormsMailer::sendConfiguredHandlers_obsolete()` is removed in the same change (it is already unreferenced).
- `FormsMailer` keeps `answersHtml()`; it moves to `FormsVariableBuilder` if nothing else calls it (**Verify**).

### Edge cases
- **Retried job:** `dedupeKey = forms.submission_received.<submission>` — the bell, the ledger and analytics each hold it, so a retried or duplicated job changes nothing. (The bell already used the same key, so existing rows still dedupe.)
- **A handler with no `emails`** is skipped today; keep that, and write no ledger row for a handler that was never going to send (skip before claiming).
- **Forms sends to several handlers, each with several addresses** — one ledger row per (handler, address) so a failure to one address is visible; this is a change from "one failure message per handler" and is the point of the ledger.
- **Submitter copy:** not today; not added.
- **Quota:** `FormSubmissionQuotaService` is unaffected (it counts submissions, not notifications).

### Admin
`forms-edit.html` / `FormsEditorController.ts` — a *Notifications* card beside the existing "email handlers" section in `FormsViewsController.ts` (`retrieveEmailHandler(referenceNum)` at ~288). Editor: `module: 'forms'`, `scopeRef: <form reference>`. One row. The submissions screen is untouched.

### Tests
Characterization (`FormsNotificationCharacterizationTest`): a submission with 0, 1 and 2 attached handlers (each with 1–2 addresses) → the same `MailHandlerSender` calls with the same variables; the bell row has the same title/body/link/metadata keys; calling the job twice → one bell, one set of sends. New: bell switched off by a rule → no bell, handlers still sent; e-mail switched off → no sends, bell stays; custom recipients; analytics event recorded once without the submitter's e-mail; the bell dedupes against a row written by the old code. Existing `FormsNotificationProducerTest` is rewritten to go through the publisher.

### Acceptance
A form submission produces exactly what it produced before when no setting exists; switching the *Dashboard* switch off for one form stops only that form's bell. Gate G5's present pattern (`Notification::create`) does **not** match the `Notification::firstOrCreate` that Forms uses, which is why Forms is not on its allow-list today; the pattern is widened in 11.9 so that, until Forms is adopted, the gate lists it, and afterwards it is clean.

### Rollback
`ACTIVITY_ENABLED=false` stops the bell and the handler sends together (09 §9.5) — for Forms that would stop **owner e-mails too**, so rollback of Forms specifically is a revert of the module's commit; document that in its completion record.

---

## 11.3 Sponsorships

### Today
- `SponsorshipApplicationController` (`app/Http/Controllers/Externals/Sponsorships/`): `store` (public) creates a `SponsorshipApplication` and calls `dispatchAdminNotification()` — a hand-written bell (`application_received`, link `/portal/admin/sponsorships/applications`, severity info); `approve` creates a `SponsorUser` with a **random password** and calls `dispatchApprovalEmail()` (or `dispatchSimpleApprovalEmail()` for an existing sponsor); `deny` calls `dispatchDenialEmail()`.
- All three e-mails are `Mail::raw` plain text. **`dispatchApprovalEmail()` looks up a mail handler by the sponsorship reference and then ignores it** — both branches send the same hard-coded text — so no mail handler is ever used by Sponsorships today. The approval e-mail **contains the new sponsor's username and plain-text password**.
- `SponsorPortalController::notifyEvent` (a logged-in sponsor asking for brand display at an event) writes a hand-written bell `display_requested`.
- `store` accepts `amount_paid`, `processor_reference_num`, `processor_customer_id` **from the request body** — client-asserted values; nothing verifies them against a payment.
- **Analytics today:** none.

### Target events

| Event | Bell · e-mail default | Recipient | Scope · subject | Analytics | Publish point |
|---|---|---|---|---|---|
| `application_received` | **on** · off | owner (bell) | sponsorship ref · application id | yes, count | `store()` (replaces `dispatchAdminNotification`) |
| `application_approved` | off · **off** (the credentials e-mail stays direct, 11.1.3) | applicant (customer) | sponsorship ref · application id | yes, count | `approve()` after the row is saved |
| `application_denied` | off · off, with `attachedHandlers` (a handler attached to the sponsorship is sent); until S2 the hard-coded plain text keeps going out as today (see below) | applicant (customer) | sponsorship ref · application id | yes, count | `deny()` |
| `display_requested` | **on** · off | owner (bell) | sponsorship ref of the sponsor's sponsorship · event ref | yes, count | `notifyEvent()` |

### Decisions specific to Sponsorships
- **The credentials e-mail does not move** (11.1.3). It is the single most sensitive message in the estate and a queued, logged, ledger-tracked path is the wrong home. Separately, the owner may want to replace "email a plain-text password" with "email a link to set a password" — a security improvement, **out of scope here, recorded as OQ28** (it would use the Site Users token machinery).
- **The denial e-mail** has no secret. Because today it is a hard-coded `Mail::raw`, adopting it means choosing between (a) keep sending the hard-coded text exactly as is, announcing the Activity only for bell/analytics (mail default off); or (b) give `application_denied` a built-in MJML default replicating the text and an attached-handlers path (the unused handler lookup finally does something). **Recommended (b)**, because (a) leaves a hard-coded e-mail that no setting can switch off — but (b) needs the template (11.1.5). Plan it as sub-phase **S2**, after the bell/analytics half (S1).
- **Money is not recorded here.** The application holds no payment fields after F4 (11.13): payment goes through Direct Payments like the other modules, a sponsor sees their payments by a one-way read, and a sponsor's payment is announced by Direct Payments (11.1.2). Analytics records the application as a *count* with **no `value_cents`**.
- **`dedupeKey`** for `application_received` is `sponsorships.application_received.<application id>`; the old bell rows had no key (they had `subject_ref` only), so a one-time check that the first publish after deploy does not duplicate a recent bell is unnecessary (new applications only).

### What changes
`app/Externals/Sponsorships/Notifications/SponsorshipsActivityModule.php`, `SponsorshipsActivities.php`; the two controllers publish instead of writing; `dispatchAdminNotification()` and `notifyEvent()`'s `Notification::create` are deleted; **gate G5's allow-list loses both Sponsorship lines** (the test `test_g5_the_two_named_producers_are_still_the_only_ones` is updated to expect none). The three `dispatch*Email` methods remain until S2 (denial) and indefinitely (credentials).

### Admin
`sponsorships-form.html` / `controllers/sponsorships/sponsorships.ts`: a *Notifications* card on the sponsorship form (`scopeRef` = sponsorship reference); `sponsorships-applications.html` unchanged. S2 adds the mail-handler authoring for the sponsorship (`retrieveEmailHandler` as in the other modules).

### Tests
Characterization: an application creates one bell with today's fields; approve and deny send the same text to the applicant (`Mail::fake`); approve creates the sponsor user. New: bell off by rule; analytics event has no money, name or e-mail; `notifyEvent` keeps creating a bell row for every request, as today (OQ29: no de-duplication).

### Acceptance
No `Notification::create` remains in `app/Http/Controllers/Externals/Sponsorships`; the bell texts are unchanged; the credentials e-mail is byte-for-byte what it was.

---

## 11.4 Ecommerce

### Today
- **One notification path:** `EcommerceReceiptService::sendOrderReceipt($order)` (`app/Mail/EcommerceReceiptService.php`), called from `EcommerceCheckoutService::complete()` (line 166) **and** from `EcommerceReconcileOrders` (line 89, the recovery of a paid invoice whose client died before `/checkout/complete`). It sends **every mail handler whose `item_reference_num` is the order's store reference** to the purchaser (`customer_email`), variables `commerce_order_number`, `commerce_total` (cents, integer), `commerce_currency`, `commerce_manage_url` (signed-in purchaser: the Dashboard deep link; guest: the storefront's order page or null), `commerce_products` (an HTML `<li>` list with images). No bell. Never rethrows ("the order is committed and paid for").
- **Other events that exist and tell nobody:** fulfilment created (`EcommerceFulfilmentService::createFulfilment`, with a tracking URL builder), order cancelled (`cancelOrder`), a return requested / decided / refund recorded (`EcommerceReturnsService::request`, `decide`, `recordRefund`). The module keeps its own history in `laravel_ecommerce_order_events` (written by `EcommerceOrderService`, `EcommerceFulfilmentService`, `EcommerceGuestClaimer`) — that table is *business history* and stays (08 `keep`).
- **Money** arrives through Direct Payments (`EcommerceDirectPaymentService`, `EcommerceCheckoutService::complete()` requires `gateway->isPaid($invoiceRef)`), so the charge is already announced as `direct_payments.payment_succeeded` (11.1.2).

### Target events

| Event | Bell · e-mail default | Recipient | Scope · subject | Analytics | Publish point |
|---|---|---|---|---|---|
| `order_paid` | **on** · off (`attachedHandlers`: the store's handlers keep being sent) | purchaser (customer) | store ref · order ref | yes, **no value** (11.1.2) | after `createFromCart()` in `complete()` and in `EcommerceReconcileOrders` |
| `order_shipped` | off · off | purchaser | store · order | yes | `createFulfilment()` once per fulfilment |
| `order_cancelled` | **on** · off | purchaser | store · order | yes | `cancelOrder()` |
| `return_requested` | **on** · off | owner (bell) | store · return | yes | `EcommerceReturnsService::request()` |
| `return_decided` | off · off | purchaser | store · return | yes | `decide()` |
| `refund_recorded` | off · off | purchaser | store · return | yes, **no value** (the refund is a Direct Payments refund record) | `recordRefund()` |

The bell for a new paid order is the new thing an owner gets; everything not marked "on" is available as a switch but silent until chosen. Handlers for shipped/cancelled/return events do not exist today, so an attached-handler event other than `order_paid` would send **every** handler of the store on a different event — wrong. **Therefore only `order_paid` has `attachedHandlers: true`; the others send only when a rule names a handler.** (This is the one place the compatibility mode is deliberately narrower than the item's handler list; the editor shows "needs a mail handler" on those rows.)

### What changes
- `app/Externals/Ecommerce/Notifications/EcommerceActivityModule.php` + `EcommerceActivities.php`; `EcommerceVariableBuilder` reuses an **extracted** `EcommerceReceiptService::variablesFor($order)` (the `commerce_*` map above, including `commerce_manage_url` and `commerce_products`). The Activity carries `order` (reference) only; the builder reloads the order **when the job runs**, so the purchaser's current data and the URL are fresh.
- `sendOrderReceipt()` is replaced by one publish in both callers; `EcommerceReceiptService` shrinks to the variable builder (the `_obsolete` method and the `EcommerceOrderReceiptMail` Mailable are listed for removal, 11.9).
- **Guest purchaser:** the recipient resolver returns `customer_email` (may be null → ledger `skipped: no_recipient`, as today it silently returns).
- **`commerce_total`** is an integer today; the builder returns strings (`'4999'`), which a template prints identically (**Verify** in the golden test).
- **Replay:** `order_paid` dedupes on `ecommerce.order_paid.<order ref>`. The reconcile command and `complete()` both calling it for the same order — the case the old code could double-send — becomes impossible.

### Edge cases
- The `Log::info('ecommerce.order_receipt')` line is dropped (the ledger replaces it).
- **A store with no mail handler** sends nothing, as today; the bell now appears (a rule can switch it off).
- **Stock and cart logic is untouched**; publishing happens after the cart is saved, exactly where the receipt call was, and never throws.

### Admin
`commerce-store.html` / `controllers/ecommerce/commerce-store.ts` (where the store's mail handlers are authored today: `retrieveEmailHandler`) — a *Notifications* card, `scopeRef` = store reference. The orders screen (`commerce-orders.ts`) could later link the ledger row for an order; not planned.

### Tests
Characterization: `complete()` with 0/1/2 store handlers → same sends, same variables (golden), exactly one even when the reconcile command runs afterwards; guest vs signed-in `commerce_manage_url`. New: each event's rule matrix; `order_shipped` with no handler sends nothing and no ledger noise; analytics: one `order_paid` without value, one `direct_payments.payment_succeeded` with value (11.1.2 test); the reconcile command publishes once.

### Acceptance
Receipt behaviour unchanged with no setting; the owner can switch the bell for new orders on a per-store basis; no money double count.

---

## 11.5 Events

### Today
- **One notification path:** `EventCommunicationService::sendConfirmation($registration)` (`app/Mail/EventCommunicationService.php`), called from `EventStorefrontController::register()` (line ~199) when a free registration is confirmed, and from `confirmPayment()` (line ~263) when a paid one is finalised. It sends every handler whose `item_reference_num` is the **event** reference to the attendee (`attendee_email`) with `events_event_name`, `events_attendee_name`, `events_occurrence_starts_at`, `events_ticket_code`, `events_manage_url`, `events_calendar_url`. No bell. (`buildIcs()` is a calendar file, unrelated.)
- **Silent today:** cancellation (`cancelRegistration`), waitlist join and the **waitlist offer** (`EventWaitlistService::promoteNext`, called when a registration is cancelled: it marks the next waiting entry `offered` with an `offer_expires_at` 60 minutes ahead — the person is offered a place and **told nothing**), check-in (`EventCheckinService`), pass redemption, holds.
- Payments go through a Direct Payment (`EventPaymentService`, invoice `metadata->facade = 'events'`) — 11.1.2.
- The module keeps `laravel_event_activity` (its own history) — stays.

### Target events

| Event | Bell · e-mail default | Recipient | Scope · subject | Analytics | Publish point |
|---|---|---|---|---|---|
| `registration_confirmed` | off · off (`attachedHandlers`) | attendee | event ref · registration ref | yes, **no value** | both `sendConfirmation` call sites |
| `registration_cancelled` | off · off | attendee | event · registration | yes | `cancelRegistration()` after the transaction |
| `waitlist_offered` | **on** · off | the waitlisted person (the entry's e-mail) | event · waitlist entry | yes | `promoteNext()` when it returns an entry (the offer is time-limited: 60 minutes) |
| `order_paid` | **on** · off | purchaser | event · event order | yes, no value | `finalizeFromSnapshot()` success in `confirmPayment()` |
| `checked_in` | off · off | owner | event · registration | yes | `EventCheckinService` |

`registration_confirmed` and `order_paid` can both fire for one purchase (the confirmation to the attendee; the bell for the owner). A free registration has no order, so only the first fires. The bell for `order_paid` is the only new default-on bell for Events; it is on so an owner learns that a ticket sold.

### What changes
- `app/Externals/Events/Notifications/EventsActivityModule.php` + `EventsActivities.php`; `EventsVariableBuilder` reuses an extracted `EventCommunicationService::variablesFor($registration)` (the six `events_*` names). The Activity carries `registration`, `event`, `occurrence` references.
- The controller's two `sendConfirmation` calls become one publish each; **the publish must still happen after the registration is committed and must never throw** (the publisher already guarantees the second).
- **The waitlist offer** is the one genuinely new message: today nobody is told. With no setting it e-mails nothing (the attached handlers are the *confirmation* template, not an "a place is yours" template) — the bell tells the owner, and a rule with a chosen handler tells the waitlisted person. The editor row says so. **Timing matters here:** the offer lasts 60 minutes and the queue adds up to about a minute, which is acceptable; if the owner wants it immediate that is the per-event inline flag of 11.1.6.
- The `*_obsolete` method and `EventRegistrationReceiptMail` are listed for removal (11.9).

### Edge cases
- **Idempotency:** `confirmPayment()` can be called twice by a client retry; `registration_confirmed` dedupes on `events.registration_confirmed.<registration>`, which also fixes a latent double confirmation.
- **Occurrence-level handlers:** handlers are attached to the **event**, not the occurrence; keep that.
- **Pass redemption** registrations (`EventPassService::redeem`) create registrations without the controller path — **Verify** whether they send a confirmation today (the grep found only the two controller sites) and keep that.
- **Ticket secrecy:** `events_ticket_code` is a **bearer code** (check-in by ticket code). It is not a password, but it is a capability. It is emailed to the attendee today and stays in the variables; it is **not** copied into `Activity::$data` or analytics (the builder reads it from the registration when the mail is built). The no-secrets guard of 11.1.3 includes `ticket` in its pattern, which keeps it out of Activity data by construction.

### Admin
`events-main.html` / `controllers/events/events.ts` (handlers are authored at ~line 831 with `item_reference_num = params.reference_num`) — a *Notifications* card, `scopeRef` = event reference.

### Tests
Characterization: free and paid registrations send the same handlers with the same variables once; `confirmPayment` twice → once. New: the rule matrix; a waitlist promotion creates a bell and nothing else by default; the golden variables test; ticket code absent from the stored Activity and the analytics row.

---

## 11.6 Appointments

### Today
- **One notification path:** `AppointmentCommunicationService::sendConfirmation($booking)` (`app/Mail/AppointmentCommunicationService.php`): first **`AppointmentActivityService::log(... 'email.confirmation.sent' ...)`** (the module's own history row — written **before** the send, whether or not it succeeds), then every handler whose `item_reference_num` is the **appointment calendar** reference, to `user_email`, with `appointments_service_name`, `appointments_customer_name`, `appointments_starts_at`, `appointments_manage_url`, `appointments_calendar_url`. `queueBookingConfirmed()` is just `sendConfirmation()` — the name is aspirational; nothing is queued today.
- **Call sites:** `AppointmentBookingService::book()` (line ~287, only when the booking is `confirmed` and there is no payment invoice yet), `AppointmentLifecycleService::confirm()` (admin approves a pending booking, ~135) and `reschedule()` (~113, for the new booking), `AppointmentPaymentService::confirmPaid()` (~174, after the invoice is paid).
- **Silent today:** cancellation (`AppointmentBookingService::cancel`), rejection (`reject`), completion (`markCompleted`), no-show (`markNoShow`), and the **waitlist offer** (`AppointmentWaitlistService::promoteOnCancellation` marks the next waiting entry `offered`, stores a random **`offer_token`** and an `offer_expires_at` four hours ahead — and tells nobody).
- Payments: through a Direct Payment (invoice facade `appointments`) — 11.1.2.
- Name collision to avoid: the module has its own **`AppointmentActivityService`** (a history log). It is *not* the new `Activity`; the new module class is `AppointmentsActivityModule` and the existing service is left alone.

### Target events

| Event | Bell · e-mail default | Recipient | Scope · subject | Analytics | Publish point |
|---|---|---|---|---|---|
| `booking_confirmed` | **on** · off (`attachedHandlers`) | customer | calendar ref · booking ref | yes, no value | the four call sites above |
| `booking_cancelled` | **on** · off | customer | calendar · booking | yes | `cancel()` (by customer or admin; `cancelled_by` in `data`) |
| `booking_rejected` | off · off | customer | calendar · booking | yes | `reject()` |
| `booking_rescheduled` | off · off | customer | calendar · new booking | yes | `reschedule()` |
| `booking_completed` | off · off | customer | calendar · booking | yes | `markCompleted()` |
| `booking_no_show` | off · off | customer | calendar · booking | yes | `markNoShow()` |
| `waitlist_offered` | **on** · off | the waitlisted person | calendar · waitlist entry | yes | `promoteOnCancellation()` |

`attachedHandlers` is on **only** for `booking_confirmed` (11.4 reasoning: the calendar's handlers are the confirmation template). Reschedule today sends the **confirmation** again for the new booking (line ~113) — keep that behaviour by publishing `booking_confirmed` for the new booking **and** `booking_rescheduled` for the move; the second has no default e-mail.

### What changes
- `app/Externals/Appointments/Notifications/AppointmentsActivityModule.php` + `AppointmentsActivities.php` + `AppointmentsVariableBuilder` (extracted `variablesFor($booking)`, the five `appointments_*` names).
- `AppointmentCommunicationService::sendConfirmation()` becomes a publish; `activity->log('email.confirmation.sent')` stays but moves to **after** a successful publish and records the ledger outcome (so the module's history stops claiming a send that failed) — **Verify** with the owner whether the history row should instead be written by an Activity listener; kept simple: written by the same call, after.
- `queueBookingConfirmed()` is kept as a thin alias only if other code calls it (three do); otherwise removed. **Verify** at build time.
- The calendar `.ics` builder is untouched.

### Edge cases
- **Four call sites for one event** — `book`, `confirm`, `reschedule`, `confirmPaid` can overlap (a paid booking: `book` skips because an invoice exists; `confirmPaid` sends). The dedupe key `appointments.booking_confirmed.<booking>` makes any overlap harmless.
- **Staff/resource notifications:** the staff member is not told today; not added (OQ30 if wanted).
- **Time zone in the variable:** `appointments_starts_at` is ISO-8601 today; keep.
- **The waitlist offer carries a bearer token** (`offer_token`, stored in the clear, valid four hours). It is a capability, so it falls under 11.1.3: the Activity carries only the waitlist **entry id**; the variable builder reads the token from the entry **when the e-mail is built**, and the guard's pattern (`token`) keeps it out of `Activity::$data`. Because the token is not hashed, a queued build is possible (unlike Site Users' verification tokens); the e-mail may therefore be queued, and a minute's delay is inside a four-hour offer.

### Admin
`appointments.html` / `controllers/appointments/AppointmentsController.ts` (handlers authored there) — a *Notifications* card, `scopeRef` = calendar reference.

### Tests
Characterization for the four call sites (same handlers, once), reschedule sends exactly one confirmation for the new booking; new: the rule matrix, cancellation bell by customer vs admin, waitlist promotion, golden variables.

---

## 11.7 Site Users

### Today
- **`SiteUsersMailer`** (`app/Mail/SiteUsersMailer.php`) has three methods, called from `SiteUsersVerificationService`: `sendVerificationToken()` (purposes `email_verify`, `password_reset`, `email_change`) — handlers are found by `item_reference_num = '<site_users_id>:<purpose>'`, variables `site_users_purpose`, `site_users_module_name`, **`site_users_action_url` (contains the token)**, `site_users_expires_in_minutes`; `sendEmailChanged()` — to the **old** address after an e-mail change (`'<site_users_id>:email_changed'`; variable `site_users_new_email`); `sendAdminEmailSet()` — to the new address when an admin sets it.
- **Registration** (`SiteUsersAuthService::register`) creates the user and, if the module requires verification, calls `issueEmailVerification()`. No bell, no analytics.
- Scope convention differs: a Site Users "module" is identified by `site_users_id`, and the handler's item reference **encodes the purpose**.

### What moves and what does not (11.1.3)
- **Does not move:** `sendVerificationToken()` (all three purposes). The token exists only as a hash in the database (`token_hash`), so it **cannot be rebuilt when a queued job runs**, and putting it in an Activity would store it in the queue. These stay inline, undelayed, untouched.
- **Moves:** the **non-secret** messages:

| Event | Bell · e-mail default | Recipient | Scope · subject | Analytics | Publish point |
|---|---|---|---|---|---|
| `user_registered` | off · off | owner (bell) | site-users module ref · user ref | yes, count | `register()` success |
| `email_verified` | **on** · off | owner (bell) | module · user | yes | `verifyEmail()` success |
| `email_changed` | off · off, with `attachedHandlers` (the module's `email_changed` handlers keep being sent, to the **old** address) | the old address (the resolver never takes it from the Activity: an address is personal data; it reads the user's pending-change record, **Verify** it still exists after confirmation) | module · user | yes | `confirmEmailChange()` |
| `email_set_by_admin` | off · off, with `attachedHandlers` | the new address | module · user | yes | `adminSetEmail()` |
| `password_changed` | off · off | the user | module · user | yes | `SiteUsersAuthService::changePassword` / `resetPassword` (new — nobody is told today) |

`email_changed` and `email_set_by_admin` are **transactional / security notices**, but with no built-in default (11.1.5) a module with no handler authored sends nothing. **Decided (OQ31):** they are rule-switchable now, and become compulsory (`alwaysOn`) only when a built-in default exists.

### What changes
- `app/SiteUsers/Notifications/SiteUsersActivityModule.php` + `SiteUsersActivities.php` + variable builder (extracted `variablesFor`, names `site_users_module_name`, `site_users_new_email`).
- **Scope mapping:** `scopeRef` = `site_users_id`; attached-handler lookup for an event uses `'<site_users_id>:<purpose>'` — the general `attachedHandlerRefs()` queries `item_reference_num = scopeRef`. Site Users needs a **handler-key hook**: a new optional contract `ActivityModule::attachedHandlerKey(Activity, EventDefinition): ?string` (default: the scope ref), implemented by Site Users as `scope . ':' . event-purpose`. Added in M0 as an additive, defaulted method (11.1.1b).
- `SiteUsersVerificationService` publishes at the points above; `SiteUsersMailer::sendEmailChanged` / `sendAdminEmailSet` and their `_obsolete` twins are removed; `sendVerificationToken` remains.
- **Brain-level billing mail** (`BillingMailer`, dunning) is a different system (the Brain's); out of scope.

### Edge cases
- **Enumeration:** `forgotPassword` / `resendVerification` answer identically whether or not the account exists (**Verify** in the current code); publishing `user_registered` must not leak through timing or through the bell to anyone but the owner — it does not.
- **Registration spam:** every registration makes a bell row. A module under bot attack would flood the owner's bell. Mitigation (decided, OQ31): `user_registered` bell **off by default**, `email_verified` (a real person) **on**.
- **SSO sign-ups** (`loginFromSso`) create users too; they publish `user_registered` with `data['via'] = 'sso'`.

### Admin
`site-users-detail.html` / `controllers/site_users/site-users.ts` — a *Notifications* card on the module's detail screen, `scopeRef` = `site_users_id`.

### Tests
Characterization: the three token e-mails are unchanged and **never reach the queue** (`Queue::fake` + assert nothing pushed, and the jobs table gains no row containing the token); email-changed/admin-set send the same handlers once. New: the Activity guard refuses a token-shaped key; the rule matrix; scope/handler-key mapping; the registration bell off/on.

---

## 11.8 Subscribers (last)

### Today
- **`SubscribersController::subscribe`** (public): validates, `Subscriber::firstOrNew(['email'])`, sets `active = true`, syncs categories, syncs to HubSpot (`syncSubscriberToHubspot`). **No confirmation e-mail, no double opt-in, no bell.** The unique key is the e-mail, so a repeat sign-up silently re-activates and re-saves.
- **`publishArticle`** counts the active subscribers in the article's category and dispatches `SendSubscriberEmails`. **That job's actual send is commented out** (`// Mail::to($subscriber->email)->send(new SubscriberArticleMail(...))`): it counts and simulates progress (`SubscriberEmailProgress`) and marks "Email delivery completed". The module does not send the mailing today.
- The browser already reports `subscribers.page_load` and `subscribers.subscriber_signup` (the shipped analytics helper), which feed the "subscribers" funnel.

### Target events

| Event | Bell · e-mail default | Recipient | Scope · subject | Analytics | Publish point |
|---|---|---|---|---|---|
| `subscriber_signed_up` | **on** · off | owner (bell); the subscriber's welcome e-mail only when a handler is attached | the category ref (or `''` for several) · subscriber id | yes, count (source `server`; the browser's `subscriber_signup` stays a separate `browser` event, so the funnel is not double counted) | `subscribe()`, **only when the subscriber is new or was inactive** |
| `article_published` | off · off | owner | category · article | yes | `publishArticle()` (the owner's confirmation that publishing was accepted; it does not claim delivery) |

### Decisions specific to Subscribers
- **The system is not the bulk mailer.** The article mailing (one message to thousands) must never go through the per-recipient ledger: a unique ledger row per subscriber per article would add millions of rows and the pruner would be the busiest job. The mailing stays its own job with `SubscriberEmailProgress` as its record; whether it should *really send* is an open product question (OQ32), not an adoption task.
- **Welcome e-mail:** none today; a rule with a chosen handler would send one on `subscriber_signed_up` to the subscriber's e-mail (customer mode: the resolver reads `Subscriber::email` by reference). There is no consent or unsubscribe link in the estate yet, so **no default welcome e-mail is added** and the handler authoring for it is *not* offered in the editor until the owner decides how consent works (OQ32). Until then the event is bell + analytics only; the *E-mail* switch is shown disabled with the reason.
- **Personal data:** the Activity carries the subscriber id, never the e-mail or name.
- **A person who re-subscribes** after being deactivated is a new sign-up for the bell; one who is already active is not announced again.

### What changes
`app/Externals/Subscribers/Notifications/SubscribersActivityModule.php` + `SubscribersActivities.php`; `subscribe()` and `publishArticle()` publish; HubSpot sync is unchanged. No `_obsolete` code to remove.

### Admin
`subscribers.html` / `controllers/subscribers/subscribers.ts` — a *Notifications* card, `scopeRef` = `''` (module-wide; subscriber categories are not items with their own notification settings). This is the first module whose only scope is module-wide — an intended use of OQ9.

### Tests
Characterization: `subscribe()` creates/reactivates the subscriber and syncs categories exactly as before. New: new vs already-active vs reactivated → bell once / not / once; analytics source `server` does not enter the browser funnel; no e-mail address anywhere in the stored Activity or analytics row; `publishArticle` unchanged apart from the publish.

---

## 11.9 Cross-module gates, retirement list and documentation

**Gates** (09 §9.4), updated as each module lands:
- **G2** — `NotificationGatesTest` / `AnalyticsGatesTest`: no module file mentions `NotificationDispatcher` or `AnalyticsRecorder`; the allow-list only ever **shrinks**.
- **G5** — the two Sponsorships lines come out of the allow-list; extend the pattern to `Notification::(create|firstOrCreate|updateOrCreate|insert)` so Forms' `firstOrCreate` (which the current pattern would not have caught) is covered.
- **G9 (one announcement per thing) was withdrawn 2026-10-20 by the owner:** `MailHandlerSender::send()` is a generic sender; a test that lists every class allowed to call it is not wanted.
- **New G11 — Direct Payments names no other module** (F5, 11.13).
- **New G10 — no secrets in Activities:** the constructor guard of 11.1.3 plus a test that scans every `new Activity(` in `app/` for a `data:` argument containing a forbidden key name.

**Retirement list** (after one release with each module adopted, same rule as plan P6): `FormsMailer::sendConfiguredHandlers*`, `EcommerceReceiptService::sendOrderReceipt*` and `EcommerceOrderReceiptMail`, `EventCommunicationService::sendConfirmation*` and `EventRegistrationReceiptMail`, `AppointmentCommunicationService::sendConfirmation*` / `AppointmentBookingReceiptMail`, `SiteUsersMailer::sendEmailChanged*` / `sendAdminEmailSet*` and their Mailables, `SponsorshipApplicationController::dispatchAdminNotification` and (S2) `dispatchDenialEmail`.

**Documentation:** each module's completion record `complete/11-<module>.complete.md` (status, phases, tests, deviations, **unverified**); [06](06-activity-fan-out.md) §6.6's table is now superseded by this step's per-module tables — a note is added there; [README](README.md) gains this step; new open questions in [10](10-open-questions.md).

## 11.10 Phases and order

| Phase | Contents | Depends on |
|---|---|---|
| **M0** | Groundwork 11.1: recipient mode in attached handlers (11.1.1), `defaultsApply` + the generic `receipt` invoice option (11.1.2), the no-secrets guard (11.1.3, with `ticket` in the pattern), the `attachedHandlerKey` contract method (11.7), gates G9/G10 | OQ25, OQ26 |
| **M1** | Forms (11.2) | M0 |
| **M2** | Sponsorships S1 (bell + analytics), then S2 (denial e-mail + handler authoring) (11.3) | M0, F4 (the password e-mail is its own later change, OQ28) |
| **M3** | Ecommerce (11.4) | M0, F1 (`receipt` option) |
| **M4** | Events (11.5) | M0 (money rule, ticket pattern), F2 |
| **M5** | Appointments (11.6) | M0 (money rule), F3 |
| **M6** | Site Users (11.7) | M0 (secrets guard, handler key) |
| **M7** | Subscribers (11.8) | M0; OQ32 for the welcome e-mail |
| **F1–F5** | Façade refactor (11.13): Ecommerce's receipt option, Events, Appointments, Sponsorships (incl. the sponsor's "My payments"), Direct Payments names no module (gate G11). F1 and F5 with M0; **F4 before M2**; **F2 before M4, F3 before M5** | — |
| **M8** | Retirement (11.9) | one release after each module |

Each phase ships on its own, leaves the system working, and has its own completion record. **Modules are independent of each other after M0** — they may be built in any order or in parallel sessions that touch different files; the only shared files are `ActivityServiceProvider` (one line each) and the allow-lists of the gates.

## 11.11 Risks

| Risk | Mitigation |
|---|---|
| A module's e-mails change recipient, wording or timing silently | The characterization tests are written first and run unedited (11.1.8); the golden variables test; the queued-delay decision made once (OQ26) |
| Forms owner e-mails stop because the dispatcher treated them as customer e-mails | 11.1.1 makes the recipient mode explicit and tests it; Forms' completion record states that its rollback is a commit revert, not `ACTIVITY_ENABLED=false` |
| A token or password ends up in the jobs table | The constructor guard; the secret e-mails stay inline; a Site Users test inspects the queue |
| A purchase produces two receipts and two money events | 11.1.2: `defaultsApply`, no value on module events, the three-way test |
| A flood of bell rows (bot sign-ups, form spam) | Per-event switches; `user_registered` off by default; the quota/spam layers of Forms are untouched; a later rate limit on the bell is a separate decision |
| The ledger grows with every notification | Retention is already in place ([08](08-data-lifecycle.md)); the bulk mailing is excluded on purpose (11.8) |
| Four call sites for one event (Appointments) | Dedupe keys make overlap harmless; tested |
| Admin card on seven screens | One component, one recipe (11.1.7); each card is three lines |

## 11.12 Open questions

All answered by the owner on 2026-10-19 (see [10](10-open-questions.md)); the table keeps the question and the answer taken. OQ27, OQ29, OQ30 and OQ32 were left at the recommendation.

| # | Question | Recommendation |
|---|---|---|
| **OQ25** | Who announces money for module-owned payments (events, appointments, ecommerce)? | **Direct Payments announces the money; the module announces the thing; module events carry no value; no default payer receipt when a module owns the charge** (11.1.2) |
| **OQ26** | Are confirmation e-mails allowed to be delayed by the queue (about a minute)? | **Yes**, except the secret-carrying e-mails, which stay inline |
| **OQ27** | Forms: keep the `mail.sent`/`mail.failed` audit rows, or let the ledger replace them? A separate `submission_flagged` bell for spam? | **Replace by the ledger** (drop `mail.sent`; keep `mail.failed` only if the Forms UI shows it); **no** separate flagged event now |
| **OQ28** | Sponsorships: replace "e-mail a plain-text password" by "e-mail a set-password link"? | **Yes, as its own change** using the Site Users token machinery; not part of adoption |
| **OQ29** | Sponsorships: should a repeated display request be deduplicated? | **No** (as today: every request is a row) |
| **OQ30** | Appointments: notify the staff member? | **Not now** |
| **OQ31** | Site Users: are the e-mail-changed / admin-set notices compulsory once a built-in default exists; is the registration bell on? | **Rule-switchable now; compulsory when a default exists; registration bell off, `email_verified` bell on** |
| **OQ32** | Subscribers: send the article mailing for real; double opt-in and unsubscribe; a welcome e-mail? | **Decide as a product question first; adoption covers only the bell and analytics** |
| **OQ33** | Sponsorships: how does money enter? | **Through Direct Payments exactly like Ecommerce, with a sponsor "My payments" view by a one-way read** (11.13 F4) |

---

## 11.13 Façade refactor — bring every module that takes payment to the Ecommerce model

This is **not** part of notification adoption; it is the owner's rule (11.1.2b) applied to the three modules that still hold payment knowledge. It is listed here because it changes the very call sites adoption publishes from (the "payment confirmed" points of Events and Appointments), and doing the two in the wrong order means rewriting the same lines twice. **Direct Payments receives no module-specific change in any phase below.**

### The target (what "like Ecommerce" means, concretely)
1. The **client builds the invoice** with the Direct Payments SDK (`createInvoice` / `addItem` / `lock`), choosing its prices from the mapped Direct Payment item the server hands back as a *hint*; it stamps `metadata: { facade: '<tag>', receipt: 'none', <its own reference> }` (`receipt: 'auto'` for Sponsorships, which sends no confirmation of its own).
2. The module exposes **`attach`** (store the invoice reference against its pending order/booking — the module's own table, no Direct Payments metadata needed), and **`complete`** (verify with `PaymentGateway::isPaid()` + `snapshot()`, then confirm the order/booking).
3. The module's tables hold **references only** — no amount, no status words from Direct Payments, no charge id.
4. A **reconcile command** scans `paidInvoicesAwaiting('<tag>', $since)` and recovers anything paid whose client never called `complete` (Ecommerce has `EcommerceReconcileOrders`; Events and Appointments get the same shape).
5. Any figure on a screen or in an e-mail is resolved from `snapshot()` / `snapshots()` when needed.

### F1 — Ecommerce: nothing to remove, one option to add (small, with M0)
- Ecommerce already meets both hard stops. Its read of the processor's currency is a one-way read (module → Direct Payments → processor) and **stays**.
- Add `receipt: 'none'` beside the façade tag in the client's invoice metadata (11.1.2), and a test that the component script passes it. `ensureDiscountItem` stays (the audit's deliberate exception).

### F2 — Events (before M4)
- Remove `EventPaymentService::createInvoiceForOrder()` and its use of `DirectPaymentInvoice`/`DirectPaymentItem` writes. The ticket **price hint** is read from the mapped item at admin-save or storefront-load time (as Ecommerce does with `price_cents`), not computed at checkout.
- New endpoints on the storefront controller, the same shape as Ecommerce's: `POST …/events/orders/{ref}/attach {invoice_ref}` (stores `direct_payment_invoice_ref` on the pending `EventOrder`) and `…/complete` (today's `confirmPayment`, which already calls `PaymentGateway::isPaid()` and `finalizeFromSnapshot()` — it keeps its body). The event SDK (`resources/js/external/api/events`) builds the invoice with `Portal.directPayments`.
- `EventsReconcileOrders` command over `paidInvoicesAwaiting('events', …)`; scheduled with the other Events commands. Also the missing safety net: today a paid ticket whose client died is only recovered if the browser retries.
- **Tests:** a paid invoice (a gateway fake) completes the order and confirms the registrations once; an unpaid one does not; the reconcile command recovers a paid, unattached-complete order; `EventOrder` and `EventPaymentService` import no Direct Payments model; the three references are the only payment columns.
- **Notification adoption (M4)** then publishes `registration_confirmed` / `order_paid` from `complete` and from the reconcile command, with the same dedupe keys.

### F3 — Appointments (before M5)
- Migration: **drop `paid_cents`** from `laravel_appointment_bookings` (nothing else stores it) and remove `recordPayment()`; reports that show "paid" resolve it from `snapshots()` for the page being shown (the way Ecommerce's overview does).
- Replace `AppointmentPaymentService::createInvoiceForBooking()` by the client-built invoice + `attach`; replace `confirmPaid()`'s invoice-row read and its `['paid','succeeded']` status list by `PaymentGateway::isPaid()` + `snapshot()`; stop writing `paid_cents` into the module's activity-log row (it logs the invoice reference only).
- `AppointmentsReconcileBookings` over `paidInvoicesAwaiting('appointments', …)`.
- **The held slot:** a pending booking holds a slot (`AppointmentHoldService`); the hold's expiry already releases an unpaid booking; unchanged. **Verify** that `attach` must refresh the hold's expiry so a customer who is paying is not released mid-checkout.
- **Tests:** booking confirmed on a paid snapshot; not on an unpaid one; reconcile; no `paid_cents` anywhere (a schema test); the activity-log row has no amount.
- **Notification adoption (M5)** publishes `booking_confirmed` from the same four call sites as today plus the reconcile command; the dedupe key makes the overlap harmless.

### F4 — Sponsorships: the same façade model, down to every detail (before M2)

Decision (OQ33, the owner, 2026-10-19): Sponsorships works **exactly like Ecommerce**, and a sponsor must be able to **see their own payments and donations in the sponsor dashboard** — the allowed one-way read.

**Schema (clean break, like Ecommerce's — nothing has been published, so there is nothing to migrate):**
- `laravel_sponsorship_levels`: **drop** `amount_type`, `amount_value`, `amount_min`, `amount_max`; **add** `direct_payment_item_reference_num`. A level's price rule now lives in the Direct Payments item it maps to — exactly the three shapes the levels have today: a **fixed** item (was `specific`), a **dynamic item with a minimum and maximum** (was `range`), a **dynamic item with no bounds** (was `any`). The admin level editor (`sponsorships-form.html` / `sponsorships.ts`) picks the item the way Events' ticket types do, and shows the price as a *hint* read from the item. The sponsorship itself already has `direct_payment_reference_num`; it stays.
- `laravel_sponsorship_applications`: **drop** `amount_paid`, `processor_reference_num`, `processor_customer_id`; **add** `direct_payment_invoice_ref` (nullable — a free application has none). The Direct Payment and processor references are read from the invoice snapshot, not stored twice.
- **New, references only:** `laravel_sponsor_payments` (`sponsor_reference_num`, `sponsorship_reference_num`, `level_reference_num` nullable, `direct_payment_invoice_ref` unique, `created_at`) — the list of invoices that belong to a sponsor, so a dashboard can ask Direct Payments about exactly those. No amount, no status.

**Public application (the same five steps as 11.13's target):**
1. `POST /sponsor-applications` creates the application as today (still public, still `application_received` for the bell) with **no money fields**. Then the storefront component builds the invoice with `Portal.directPayments` from the level's mapped item (the donor picks an amount inside the item's bounds when it is dynamic), stamping `metadata: { facade: 'sponsorships', receipt: 'auto', application_reference_num }`. `receipt` is **`auto`** here, unlike the other modules (11.1.2).
2. `POST /sponsor-applications/{ref}/attach {invoice_ref}` stores `direct_payment_invoice_ref`; `…/complete` verifies `PaymentGateway::isPaid()` and `snapshot()` (the invoice's Direct Payment must be the sponsorship's Direct Payment, and its item the level's — the façade's own checks).
3. `approve` / `deny` stay admin decisions. **On approval the application's invoice reference is also written to `laravel_sponsor_payments` for the new (or existing) sponsor**, because an applicant is not a sponsor until then. Whether approval **requires** a paid invoice is a per-sponsorship setting (`meta.require_payment`, default on when the level has an item): then `approve` asks `isPaid()` and refuses with a clear code if not.
4. **Recovery sweep:** `SponsorshipsReconcilePayments` over `paidInvoicesAwaiting('sponsorships', …)` links a paid, unattached invoice to its application (via the application reference in the invoice metadata or the attach record).
5. Every figure anywhere (admin application list, the review screen) is resolved with `snapshots()` for the rows on the page.

**The sponsor dashboard — "My payments" (the allowed one-way read):**
- `GET /sponsor-payments` (sponsor-authenticated, `SponsorPortalController`): reads the signed-in sponsor's invoice references from `laravel_sponsor_payments`, calls `PaymentGateway::snapshots($refs)` once, and returns per payment: date paid, amount, currency, status (`paid` / `pending` / `refunded` / `partly refunded` from `isPaid()` / `isFullyRefunded()` / `refundedCents`), which sponsorship and level, and the invoice reference. It never returns anything Direct Payments did not say, and it never lists another sponsor's invoice (the query is by the sponsor's own rows, not by e-mail).
- **Giving again from the dashboard:** the same attach pattern with the sponsor's session: `POST /sponsor-payments/attach {invoice_ref, sponsorship_reference_num, level_reference_num?}` records the invoice against the sponsor (after checking the invoice's Direct Payment is the sponsorship's), and a reconcile sweep catches the donor who closes the page after paying. Recurring donations (a subscription Direct Payment) are a later extension of the same pattern; not planned here.
- The dashboard view (`sponsorships-sponsor-details.html` is the *admin's* sponsor screen; the sponsor-facing portal is `SponsorPortalController::index`'s view) gets a "Payments" tab. **Verify** the exact view file at build time.
- The admin's sponsor detail screen shows the same list (the admin reads through the same gateway).

**Notification adoption (M2)** is unchanged by all this: a sponsor's payment is announced by Direct Payments (`payment_succeeded`, with the donor's `receipt: auto`); Sponsorships announces `application_received`, `application_approved`, `application_denied`, `display_requested` with **no money**.

**Tests:** characterization of the application → approval flow first; then: a paid invoice completes the application, an unpaid one does not, an invoice from another Direct Payment or another item is refused; `approve` with `require_payment` on refuses an unpaid application; the reconcile sweep links a paid orphan invoice; "My payments" returns exactly the signed-in sponsor's invoices with figures taken from a gateway fake (and never another sponsor's); a schema test: none of the dropped columns exist and no sponsorship table has an `*_cents`/`amount*` column; the sponsorship files import no `DirectPaymentInvoice`/`DirectPaymentItem`/`Processor` write.

### F5 — Direct Payments names no module (small, with M0)
- Rename `PayerService::resolveForSiteUser()` to a module-neutral name (`createPayerForAccount()`), keeping behaviour; its two callers (the Site Users controllers) and four tests follow. The stored `created_by` value `'site_user'` becomes `'account'` through a one-time update migration (**Verify** that nothing reads it by value: `grep "created_by"` in payer code and the SDK).
- **New gate G11 — Direct Payments names no other module (the reverse direction, module → Direct Payments, is never restricted):** a Unit test scanning `app/Externals/DirectPayments`, `app/Http/Controllers/Externals/DirectPayments`, `app/Models/Externals/DirectPayment*.php` and `app/Mail/Externals` for `Ecommerce|EventOrder|EventRegistration|Appointment|FormSubmission|Sponsor|SiteUser` (code lines, not comments) — empty after F5. (The word `Subscriber` is Direct Payments' own concept — a person on a subscription — and is not in the pattern.)

### Order and dependencies
F1 and F5 first (small, independent, with M0); **F4 before M2 (Sponsorships adoption); F2 before M4; F3 before M5.** Ecommerce adoption (M3) needs only F1's `receipt` option. Each F phase has its own characterization-first tests and completion record `complete/11-F<n>-<name>.complete.md`; none changes what a customer sees at checkout except that Events and Appointments gain the Ecommerce-style recovery sweep.
