# 11 — Integrations and Notifications

Closes **F9**, **F10**, **F11**, **F57**–**F61**.

## 11.1 The problem in one sentence

Every consequence of a submission is currently triggered by the respondent's browser, and one
of them lets that browser choose who receives the tenant's email.

```ts
// external/forms/renderer.ts — model.onComplete
await formsApi.submit(submitPayload);            // :86
notificationsApi.create({ … });                  // :83   ← admin notification, client-composed
mailHandlerApi.send(form.reference_num, {        // :103  ← mail, client-composed recipient
    recipient: submissionData['email'] || '',
    subject:   `New form submission: ${form.reference_num}`,
    submission_data: submissionData,
});
analyticsApi.forms.completed(…);                 // :118
```

Three failure modes, all live:

1. **Close the tab and nothing happens.** The submission is stored, and the tenant is never
   told. Every one of these calls is fire-and-forget after an `await` the respondent can
   interrupt.
2. **The browser names the recipient.** And the server honours it — `MailHandlerApiController::send()`
   overwrites each configured handler's recipient list with the client's value:
   `$han['emails'] = [$data['recipient']];` (`app/Http/Controllers/Externals/MailHandler/MailHandlerApiController.php:143`).
   Whatever the tenant configured in the response builder, the browser's value wins. That is an
   open relay through the tenant's mail provider, reachable with one `curl`.
3. **The configuration surface is not connected to the sending path.** The `forms:response`
   view configures mail handlers through `EmailConstructionBuilder`
   (`FormsViewsController.ts:196–230`) and saves them under `ViewsCategories.FORMS`.
   **`FormsController::submitForm()` never reads them** (**F57**).

## 11.2 Everything moves to the server

```
POST /forms/submit
  ├── validate                         06 §6.9
  ├── persist                          04 §4.4, §4.5
  ├── record event submission.created  04 §4.10
  └── dispatch ProcessFormSubmission (queued) ──┐
                                                │
      ┌─────────────────────────────────────────┘
      ├── mail handlers          §11.3
      ├── admin notification     §11.4
      ├── webhooks               §11.5
      ├── afs-connect ingest     §11.6
      └── analytics              §11.7
```

Rules for the job:

- **The submission is already committed** before the job runs. A failing integration never costs
  a response ([03](03-architecture.md) §3.8).
- Each effect is **independently retried** with backoff. A failing webhook does not block the
  confirmation email.
- Each attempt writes an event ([04](04-database-schema.md) §4.10), so the submission timeline
  answers "was this person emailed, and when" ([10](10-submissions-and-data.md) §10.9).
- Each effect is **idempotent** on the submission reference — a retried job does not send a
  second email.

The corresponding calls are **removed from `renderer.ts`**. The browser's only job is to POST
the answers.

## 11.3 Mail handlers (F9, F57)

### The recipient is decided server-side

The core change. `ProcessFormSubmission` reads `laravel_mail_handlers` for the form's
`item_reference_num` — exactly the rows `EmailConstructionBuilder` already writes — and sends to
the recipients **those rows configure**. The submission's own answers are available as
substitution data, not as routing.

| Handler kind | Recipient |
|---|---|
| **Notify the team** | Addresses configured in the handler. Never from the payload |
| **Confirm to the respondent** | The question named by `settings.identity.email_question` ([04](04-database-schema.md) §4.4.1) — resolved from the **version**, validated as an email, and only when the tenant has enabled a respondent confirmation |

The distinction is the whole fix. A respondent-facing confirmation legitimately goes to an
address the respondent supplied — but it goes there because **the tenant configured a
confirmation handler and named which question holds the address**, not because a browser put a
string in a field called `recipient`.

`settings.identity.email_question` also repairs **F22**: the `email` column stops depending on a
question happening to be *named* `email`.

### Templates

The mail body should be able to include the response. Substitution tokens over the submission,
resolved through its version:

```
Hello {first_name},

Thanks for registering for {event_name}.

{{all_answers}}

Reference: {{submission_reference}}
```

| Token | Resolves to |
|---|---|
| `{question_name}` | That answer's **display value** ([06](06-logic-and-validation.md) §6.7) |
| `{{all_answers}}` | A formatted table of every answered question |
| `{{submission_reference}}` | The submission reference |
| `{{form_name}}`, `{{submitted_at}}` | Form metadata |
| `{{view_link}}` | A signed admin link — **team handlers only, never respondent-facing** |

Attachments: submission files, and the submission PDF
([10](10-submissions-and-data.md) §10.8), both size-capped and both **opt-in per handler** —
attaching an uploaded file to an outbound email is a decision, not a default.

### A cross-module hazard worth naming

`GET /portal/api/v1/mail-handler/{reference_num}` is in the same unauthenticated
`portal.cors` group (`routes/portal.php:778`) and returns the full provider record — which,
per the SDK's own type, includes `key` and `secret`
(`resources/js/external/mail_handler/api.ts:20–32`).

**That is unauthenticated disclosure of mail-provider credentials, and it is not a forms
defect** — it belongs to the mail-handler module and should be raised there. It is recorded here
because forms is a consumer of that endpoint, because removing the client-side
`mailHandlerApi.send()` call (§11.2) reduces the forms module's dependence on it, and because a
forms plan that quietly built on top of it would be building on sand.

## 11.4 Admin notifications (F10)

Server-side, from the job. The title, body and metadata are composed from data the server holds
— today they are composed in the browser and are therefore attacker-controlled, which combines
with **F36** to put attacker markup in the admin UI.

Configurable per form (`settings.notify`):

- notify on every submission, or on a digest (hourly / daily);
- notify only when a condition holds — *"only when `budget` > 10000"* — using the same
  expression evaluator as [06](06-logic-and-validation.md) §6.9;
- which admin users to notify.

The digest matters for a form receiving hundreds of responses a day, where per-submission
notifications are noise that trains an operator to ignore the channel.

## 11.5 Webhooks (F59)

New, and the seam that lets a tenant integrate with anything without a per-integration build.

```json
{ "url": "https://hooks.example.com/forms",
  "secret_ref": "…",
  "events": ["submission.created", "submission.flagged"],
  "active": true }
```

- **Signed**: HMAC-SHA256 over the raw body, in an `X-AFS-Signature` header, with a timestamp
  to bound replay. Unsigned webhooks cannot be trusted by the receiver, which makes them
  useless for anything that matters.
- **The secret is a reference**, never a value in the settings JSON
  ([04](04-database-schema.md) §4.8), and never returned by any admin endpoint.
- **Retries**: exponential backoff, 5 attempts, then disabled with an admin notification. A
  webhook that retries forever against a dead endpoint is a queue backlog.
- **Deliveries are logged** — attempt, status, response snippet, timing — and replayable from
  the admin. "Did it arrive?" must be answerable without the receiving system.
- **URL restrictions**: `https` only, no private/loopback ranges, DNS re-resolved at send time.
  A webhook is a server-side request to a tenant-supplied URL, which is SSRF by construction
  unless bounded.

Payload: submission reference, form reference, version, answers as display values **and** raw
values, metadata. Files as authorised URLs, not bytes.

## 11.6 afs-connect (F58)

`WwwAfsConnectIngestDispatcher::formUpserted()` fires on create and update
(`FormsController.php:65`, `:143`). There is no dispatch on **submit**, none on **delete**, and
none on **publish**.

So `WwwAfsConnectMetaProvider`'s per-site `forms` figure counts forms, not activity — a site with
40 forms and no responses looks identical to one with 40 forms and 4,000 responses.

Add: `formSubmitted`, `formPublished`, `formDeleted`. Same dispatcher, same tenant resolution
(`WwwAfsConnectTenantFromRequest::resolve()`), same failure posture — ingest failures are logged
and retried and never affect the submission.

Note the ordering rule from [02](02-security-and-tenancy.md) §2.3: tenant resolution for ingest
happens **after** authorisation, and is never the thing that grants it.

## 11.7 Analytics (F11)

`analyticsApi.forms.loaded()` and `.completed()` are called from the browser
(`renderer.ts:130`, `:118`), so completion counts can be inflated or suppressed by the page
hosting the form.

- **`completed` moves to the server**, emitted from the job. A completion is a stored
  submission, by definition.
- **`loaded` stays client-side** — a page view has no server-side equivalent, and its
  soft-ness is acceptable in a way that a completion count is not.
- `pageLoad` gains per-page progression events, which is what the drop-off funnel
  ([10](10-submissions-and-data.md) §10.7) is built from.

The existing `PortalAnalyticsFormsHelpers` contract (`portal.d.ts:826–830`) keeps its shape;
what changes is who calls `completed`.

## 11.8 Site Users (F60)

Optional, per form, and guest submission stays the default — the same posture as
[ecommerce](../ecommerce/01-site-users-guard.md) and
[appointments](../appointments/02-site-users-guard.md).

`settings.submission.require_login`:

| Value | Behaviour |
|---|---|
| `off` (default) | Anonymous. Nothing changes |
| `optional` | Sign-in offered; if signed in, the submission carries `site_user_id` |
| `required` | Sign-in required before the form renders |

What identity buys, once present:

- **`site_user_id` on the submission** ([04](04-database-schema.md) §4.4) — "my submissions",
  survivable across devices.
- **Prefill from the account** — name, email, phone, without the allowlist that link-prefill
  needs ([09](09-publishing-and-versioning.md) §9.6.1), because the source is trusted.
- **One response per account** — `settings.submission.allow_multiple: false`, actually
  enforceable. Without an account it is a cookie, which is a suggestion.
- **Resume across devices** ([10](10-submissions-and-data.md) §10.5).
- **Edit a submitted response**, where the tenant allows it — recorded as a revision, never an
  in-place overwrite, because a submission is a record of what happened.

Everything in this plan works with `require_login: off`. Identity is an addition, never a
prerequisite.

## 11.9 Paid forms — Direct Payments (F61)

A registration form with a fee, an application with a processing charge, a donation form. The
ecosystem already has the payment engine; forms has no path to it.

The boundary is the same one [ecommerce/03](../ecommerce/03-architecture.md) §3.2 draws:
**Direct Payments owns money; forms owns the form.** Forms never touches a provider, never holds
a key, never creates a provider customer.

```
form marked "requires payment"
  ├── amount: fixed, or an EXPRESSION over the answers    06 §6.6
  │     e.g.  {ticket_count} * 2500          (integer minor units)
  ├── on completion → dp.createInvoice() · addItem() · lock()
  ├── payment element renders as a final step
  └── on confirmation → submission written with the DP reference
```

Three rules:

1. **Money is integer minor units**, matching the rest of the ecosystem
   ([ecommerce/03](../ecommerce/03-architecture.md) §3.4). A form that computes a price in
   pounds and hands pence to the processor is a rounding disagreement waiting for a chargeback.
2. **The amount is computed server-side from the version**, never taken from the payload. A
   client-supplied price is the oldest bug in commerce.
3. **Payment-confirmed-but-submission-not-written is the unacceptable outcome**, and is
   reconciled from the DP invoice, the same way orders are
   ([ecommerce/08](../ecommerce/08-orders-and-fulfilment.md) §8.6). Taking money and recording
   nothing is the one failure with no recovery path.

Deliberately **not** built here: subscriptions from a form, marketplace splits, refunds from the
forms admin. Each already exists in the Direct Payments layer and each arrives through that seam
when it is wanted, rather than being rebuilt.

## 11.10 Other modules

Forms is the natural front door to several modules that already exist, and each is one mapping,
not an integration project:

| Module | Mapping |
|---|---|
| [Subscribers](../subscribers/) | A consent checkbox plus an email question adds the respondent to a list |
| [Appointments](../appointments/) | A form that ends in a slot selection creates a booking |
| [Events](../events/) | A registration form creates an attendee |
| [Ecommerce](../ecommerce/) | A form as a custom product configurator, priced through §11.9 |
| Discussions | A form as a moderated submission channel |

Each is a **post-submit action** configured per form, running in the same job, and each is
opt-in. None is built in the first pass; the job's action list is the extension point that makes
them small later.

## 11.11 Acceptance

- Closing the tab immediately after submitting still sends every configured email and
  notification.
- A `curl` to the mail-handler endpoint with an arbitrary `recipient` **cannot** send mail on
  behalf of a form. Recipients come from the handler configuration.
- A respondent confirmation goes to the question named by `settings.identity.email_question`,
  validated as an email.
- Mail handlers configured in the `forms:response` view actually fire on submission (**F57**).
- A failing mail handler retries, then appears on the submission's timeline with its error — and
  the submission is unaffected.
- The same submission processed twice sends one email.
- An admin notification's title and body cannot be influenced by the submission payload.
- A conditional notification fires only when its condition holds.
- A webhook is signed, verifiable, retried with backoff, logged, and replayable.
- A webhook to `http://`, to a private IP, or to a loopback address is refused.
- A webhook secret never appears in any admin API response.
- afs-connect receives an event on submit, publish and delete.
- `analytics.forms.completed` is emitted by the server and cannot be inflated from the page.
- With `require_login: off`, every feature in this plan works and no account is involved.
- With `allow_multiple: false` and Site Users on, a second submission by the same account is
  refused.
- A paid form computes its amount server-side from the version; a tampered client amount is
  ignored.
- Payment confirmed with a failed submission write is reconciled, not lost.
