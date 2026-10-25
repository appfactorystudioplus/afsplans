# Mail Handler implementation

How a "mail handler" is authored, stored, and fired across the `www` app: an admin builds an
email visually in the browser, saves it against a specific admin-dashboard item (a store, an
event, a form, …), and from then on the *server* decides when to send it — reacting to
whatever happens in that module (an order, a registration, a booking, a form submission, an
account event) — without anyone touching code.

---

## 0. This is not the webmail client

**`/portal/mail`** (`App\Http\Controllers\Portal\Mail\MailMuaController`) is a completely
separate system: a PHPMailer-based mail user agent — an inbox, compose window, and IMAP/SMTP
account — for a human to read and send arbitrary email, the same way Gmail or Outlook does.

The mail handler system described in this document has nothing to do with that. It is a
**reactive, event-triggered notification system**: a fixed, prebuilt set of emails that fire
automatically when something happens elsewhere in the admin dashboard (ecommerce order placed,
event registration confirmed, appointment booked, form submitted, a Site Users account event) —
not a mailbox anyone reads, replies from, or manually composes into.

---

## 1. The shape of it, end to end

```
  ┌──────────────────────────────────────────────────────────────────────┐
  │  ADMIN BROWSER                                                        │
  │  resources/js/portal/admin/afsemail/  — EmailConstructionBuilder      │
  │    GrapesJS+MJML (layout)  +  TinyMCE (variable content)              │
  └───────────────────────────────┬────────────────────────────────────--┘
                                  │  POST /portal/api/v1/email-handlers
                                  │  { category, item_reference_num, handlers }
  ┌───────────────────────────────▼──────────────────────────────────────┐
  │  MailHandlerController::store()                                      │
  │    one `laravel_mail_handlers` row per handler GROUP                 │
  └───────────────────────────────┬──────────────────────────────────────┘
                                  │  persisted, admin-editable, at rest
  ┌───────────────────────────────▼──────────────────────────────────────┐
  │  laravel_mail_handlers  (MailHandlerModel)                            │
  │    category, item_reference_num, reference_num, name, json           │
  └───────────────────────────────┬──────────────────────────────────────┘
                                  │  looked up by category + item_reference_num
                                  │  at the moment a real event happens
  ┌───────────────────────────────▼──────────────────────────────────────┐
  │  App\Mail\*  — one class per module (§4)                              │
  │    EcommerceReceiptService, EventCommunicationService,               │
  │    AppointmentCommunicationService, SiteUsersMailer, FormsMailer      │
  └───────────────────────────────┬──────────────────────────────────────┘
                                  │  MailHandlerSender::send(new MailHandlerSenderOptions(...))
  ┌───────────────────────────────▼──────────────────────────────────────┐
  │  App\Mail\MailHandlerSender                                           │
  │    loads the ONE handler row by reference_num, injects `emails` and  │
  │    `client_variables`, hands it to FunctionsController                │
  └───────────────────────────────┬──────────────────────────────────────┘
                                  │  FunctionsController::SendEmailBuildIntegration()
                                  │    → SendEmailBuilder() → real SendGrid API call
  ┌───────────────────────────────▼──────────────────────────────────────┐
  │  SendGrid                                                             │
  └────────────────────────────────────────────────────────────────────--┘
```

---

## 2. Client side — authoring the email

**Folder:** `resources/js/portal/admin/afsemail/` (a self-contained module; its own docblock at
the top of `afsemail.ts` is the authoritative spec for its internals — this section summarizes
it).

An email handler is built from two cooperating editors, not one:

- **GrapesJS + MJML** is the *layout* builder. It produces the responsive HTML shell, stored on
  `handler.body_mjml_html` (plus a round-trippable editor blob on
  `handler.body_mjml_projectData`).
- **TinyMCE** is the *word processor*. Its content is not the email itself — it's the value of
  a single named variable. The layout references those values with `${variable}` placeholders,
  substituted server-side at send time (`FunctionsController::SendEmailBuilder()`).

Three kinds of variables exist in the builder's own model (`EmailHandlerVariables`):

| Kind | Meaning |
|---|---|
| `schema` | Authored content variables — the admin's own named TinyMCE blocks |
| `client` | Read-only reference list of what the *server* will supply at send time (this is exactly `client_variables` in `MailHandlerSenderOptions` — see §5) |
| `system` | Read-only reference list flattened from `app.window.settings` (brand, logo, domain, …), rebuilt on every render, never persisted stale |

**Saving** goes through `dataStore.saveEmailHandlers(category, item_reference_num, handlers)` →
`POST /portal/api/v1/email-handlers` with body `{ category, item_reference_num, handlers }`.
**Loading** goes through `dataStore.retrieveEmailHandler(item_reference_num)` →
`GET /portal/api/v1/email-handlers?item_reference_num=...`.

The object tree saved (and the shape every stored row's `json` column holds — unchanged from
the builder's v1, so old data keeps working) is:

```
{
  "<group_ref>": {
    reference_num, name, item_reference_num,
    handlers: {
      "<handler_ref>": {
        active, alert, emails[], from, subject, body_mjml_html,
        body_mjml_projectData, variables: { schema[], client[], system[] }, ...
      }
    }
  }
}
```

One `<group_ref>` becomes one `laravel_mail_handlers` row (see §3) — its `json` column is that
group's own value, `{reference_num, name, item_reference_num, handlers: {...}}`. A group can
hold more than one actual handler variant under its own `handlers` map, which is why
`MailHandlerSender` (§5) reads `$mailHandlerJson['handlers']` off the row it loads, then
iterates it.

---

## 3. Server side — where a saved handler lives

**Model:** `App\Models\MailHandlerModel`, table `laravel_mail_handlers`.

| Column | Meaning |
|---|---|
| `reference_num` | This row's own id — the `<group_ref>` from §2, and what `MailHandlerSenderOptions::$mailhandler_reference_num` addresses |
| `category` | Which module this belongs to — `ecommerce`, `events`, `appointments`, `site_users`, `forms` today |
| `item_reference_num` | Which *instance* of that module — a store's ref, an event's ref, a form's ref, or (for `site_users`, which needs finer scoping — see §4) `"{site_users_id}:{purpose}"` |
| `name` | Display name in the builder |
| `json` | The group's own value from §2's tree — `htmlentities(json_encode(...))`, decoded with `html_entity_decode()` + `json_decode()` at read time |

**CRUD controller:** `App\Http\Controllers\MailHandlerController` — `index()` (list by
`item_reference_num`), `store()` (upsert by `reference_num`, one row per entry in the posted
map). Routes at `routes/portal.php:1276-1277`.

An admin can therefore already configure a mail handler for any of the five connected modules
today, entirely through the UI in §2 — no code required to add or edit a notification once its
module is wired (§4).

---

## 4. Where each module fires its handlers

Every one of these lives under `App\Mail\` now (moved there from their original module-specific
namespaces specifically so all mail-sending code sits in one place — see §7 for the history).
Each has a **live** method (uses `MailHandlerSender`) and an **`_obsolete`** twin (the original
hardcoded `Mail::to()` / `FunctionsController::SendEmailHandler()` implementation, kept only as
a reference and for the tests written against it — see §8).

| Module | Class | Live method | `category` | `item_reference_num` | Fires from |
|---|---|---|---|---|---|
| Ecommerce | `EcommerceReceiptService` | `sendOrderReceipt()` | `ecommerce` | the store's ref | `EcommerceCheckoutService::complete()` |
| Events | `EventCommunicationService` | `sendConfirmation()` | `events` | the event's ref | `EventStorefrontController::register()` / `confirmPayment()` |
| Appointments | `AppointmentCommunicationService` | `sendConfirmation()` | `appointments` | the calendar's ref | `AppointmentBookingService`, `AppointmentPaymentService`, `AppointmentLifecycleService` |
| Site Users | `SiteUsersMailer` | `sendVerificationToken()`, `sendEmailChanged()`, `sendAdminEmailSet()` | `site_users` | `"{site_users_id}:{purpose}"` — see below | `SiteUsersVerificationService` (delegates; owns the token/session/payer logic, not the sending) |
| Forms | `FormsMailer` | `sendConfiguredHandlers()` | `forms` | the form's ref | `ProcessFormSubmission` job (delegates; owns the admin notification, webhook dispatch, and audit trail, not the sending) |

**Why Site Users needs finer scoping:** one `laravel_mail_handlers` row is addressed by
`category` + `item_reference_num` alone — there's no third axis for "which of several possible
notices is this." Site Users can need up to five distinct notices for the same module
(`email_verify`, `password_reset`, `email_change` token mails, plus an `email_changed` notice
and an `admin_email_set` notice), so each gets its own `item_reference_num`:
`"{site_users_id}:email_verify"`, `"{site_users_id}:password_reset"`,
`"{site_users_id}:email_change"`, `"{site_users_id}:email_changed"`,
`"{site_users_id}:admin_email_set"`. Never a bare `site_users_id` — that would fire every
configured notice on every trigger.

**Why Site Users and Forms got a new class instead of a straight move:**
`SiteUsersVerificationService` and `ProcessFormSubmission` each do far more than send mail
(token issuance, session revocation, payer propagation; admin notifications, webhook dispatch,
form-event audit trail). Only the sending itself moved out into a dedicated class, the same
reasoning `EcommerceReceiptService` already established. Both now delegate to their mailer
instead of touching `Mail::to()` or `FunctionsController` directly.

**A bug fix along the way:** Forms' old path (`FunctionsController::SendEmailHandler()`) only
ever processed the *first* configured handler row per form (`array_shift($data)` silently
dropped the rest). `FormsMailer::sendConfiguredHandlers()` iterates every row.

---

## 5. `MailHandlerSender` — the one send path

**File:** `App\Mail\MailHandlerSender` (also declares the sibling `MailHandlerSenderOptions`).

```php
MailHandlerSender::send(new MailHandlerSenderOptions(
    mailhandler_reference_num: $handler->reference_num,   // one laravel_mail_handlers row
    emails: [$recipient],                                 // who gets it (overwrites every
                                                            // inner handler's own `emails`)
    client_variables: $clientVariables,                    // see below
));
```

Internally: resolves the active `MailProviderModel` + SendGrid token
(`FunctionsController::getBrainConfig()`), loads the one `MailHandlerModel` row by
`reference_num`, decodes its `json`, overwrites every inner handler's `emails` with the ones
passed in, and hands the whole thing to
`FunctionsController::SendEmailBuildIntegration()` — the same low-level SendGrid dispatch
primitive `BillingMailer` (billing/dunning's own equivalent, unrelated to this refactor) also
sits on top of.

**`client_variables` can only be a string or a number** — there is nowhere in the
substitution model (`${variable}` → `str_replace`) for a list or an object to go. Where a
module's data is naturally a list (ecommerce line items, a form's answers), the mailer renders
it to a plain HTML string (`<ul>…</ul>`, escaped with `e()`) before putting it in
`client_variables`, rather than passing the list itself. `EcommerceReceiptService::products()`
and `FormsMailer::answersHtml()` are the two examples of this today.

**The catalog:** `app/Mail/variables.json` — a flat list of every `client_variables` name any
module currently sends, in the same `{ name, value }` shape as the builder's own `EmailVariable`
type (`resources/js/portal/admin/afsemail/afsemail.ts`), so the builder can eventually read it
for its "Submission Variables" reference list (§2). Each module's names are prefixed with its
own name — `commerce_*`, `events_*`, `appointments_*`, `site_users_*`, `forms_*` — both in this
file and in the actual `$clientVariables` arrays in the PHP.

---

## 6. What happens if nothing is configured

Every live method's flow is: look up `MailHandlerModel` rows for this `category` +
`item_reference_num`, then loop over whatever comes back. **If an admin has never built a
handler for that item, the loop runs zero times and nothing is sent — silently, by design**
(the same fail-quiet posture the original per-module implementations already had around a
transport failure: never roll back the order/registration/booking/token issuance over a
notification problem).

This is a real behavior change from the previous hardcoded-Mailable implementations, which
always sent *something* the moment the triggering event fired. It is most consequential for
**Site Users** — email verification and password reset are auth-critical, not "nice to have"
receipts — so a `site_users` handler should exist for every purpose in §4 before relying on
this in a real environment.

---

## 7. History — why this code lives where it does

This is the result of an incremental refactor, in order:

1. `EcommerceReceiptService` moved from `App\Externals\Ecommerce\Services` to `App\Mail`, established the
   `sendX()` / `sendX_obsolete()` pattern, and became the template for everything after it.
2. `EventCommunicationService` and `AppointmentCommunicationService` moved the same way (whole
   class — their only job was already "send this module's receipt").
3. `SiteUsersMailer` and `FormsMailer` were extracted as new classes (not whole-class moves —
   see §4) from `SiteUsersVerificationService` and `ProcessFormSubmission`.
4. `MailHandlerSender` (and `MailHandlerSenderOptions`) moved from `App\Http\Controllers` to
   `App\Mail` last, once every caller already lived there — every `use App\Http\Controllers\
   MailHandlerSender;` import became unnecessary (same namespace as the caller) and was removed.

Every step was verified against the existing test suite; tests written against the pre-refactor
`Mail::to()` behavior were pointed at the `_obsolete` methods rather than deleted (for Site
Users, via `tests/Support/ObsoleteSiteUsersMailer.php`, a container rebind so the live methods
run their obsolete implementation for the tests that predate the switchover, without touching
each test individually).

---

## 8. Explicitly out of scope

These all send email in some form, but none of them are part of the reactive system above, and
none were touched by this refactor:

| File | Why it's different |
|---|---|
| `App\Http\Controllers\Externals\MailHandler\MailHandlerApiController` | A test-send utility for the builder — its own `send()` has an unfinished `TODO` block and never completes a real integration-specific send. Excluded on request. |
| `MailHandlerController::SendEmail()` / `FunctionsController::GetActiveEmailIntegration2()` | Also a test-send utility (`/portal/api/v1/admin_email_test_send_email`) — sends whatever raw handler JSON the request body contains immediately, with no saved `MailHandlerModel` row involved at all. Same shape as the one above. |
| `App\Http\Controllers\Email\EmailManagerController` | A 623-line parallel reimplementation of `SendEmailBuilder`/`SendEmailHandler`. Not wired to any route — dead code. |
| `App\Jobs\SendSubscriberEmails` | Newsletter/subscriber bulk email. Its `Mail::to()` call is commented out; sending was never implemented (`// TODO: Replace this stub`). |
| `App\Http\Controllers\TestFunctions` | A developer scratchpad, not a real feature. |
| **`App\Http\Controllers\Portal\Admin\EmailAccountController::sendInviteEmail()`** | Sends the "set up your new mailbox" invite when an admin creates an email account. Builds a throwaway handler array in memory and sends it immediately via `FunctionsController::SendEmailHandler()` — it is **not** admin-configurable through §2's builder, and there is no saved row behind it. **This is fine as it currently works** and was deliberately left alone: it isn't reacting to a module event the way §4's five are, it's a one-shot system email tied directly to the account-creation request itself. |

---

## 9. Quick reference — file map

```
app/Mail/
  MailHandlerSender.php          the one send path (§5)
  variables.json                 the client_variables catalog (§5)
  EcommerceReceiptService.php    ecommerce (§4)
  EventCommunicationService.php  events (§4)
  AppointmentCommunicationService.php  appointments (§4)
  SiteUsersMailer.php            site_users (§4)
  FormsMailer.php                forms (§4)
  modules/                       the hardcoded Mailable classes the _obsolete methods still use
    Ecommerce/EcommerceOrderReceiptMail.php
    Events/EventRegistrationReceiptMail.php
    Appointments/AppointmentBookingReceiptMail.php
    SiteUsers/SiteUsersTokenMail.php
    SiteUsers/SiteUsersEmailChangedMail.php
    SiteUsers/SiteUsersAdminEmailSetMail.php

app/Http/Controllers/MailHandlerController.php   the CRUD the builder saves through (§3)
app/Models/MailHandlerModel.php                  laravel_mail_handlers (§3)

resources/js/portal/admin/afsemail/              the builder (§2)
```
