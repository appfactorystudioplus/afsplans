# 05 — Mail and notifications

Per-app email responses, configured in the Brain and sent by `www` (B6). No app sends email today
([01 W10](01-current-state-audit.md)), so this is greenfield.

## 5.1 The shape

```
 app (notes)                         Brain (www)
 ───────────                         ───────────
 event happens ──▶ POST …/mail/send  ──▶ find handler (app, event_key)
   {event_key, to,                        │ enabled? ─ no ─▶ reply "fallback"
    variables, request_id}                ▼
                                       render (variables → afsemail handler)
        ◀── {status: sent|fallback|…} ── MailHandlerSender ─▶ provider
   if "fallback" or unreachable:
   app sends its built-in message
```

The app never renders, never holds provider credentials and never transports mail. It says *what
happened and to whom*; the Brain decides *what to send*.

## 5.2 Events and variables come from the manifest

Every event an app can fire is declared in its manifest ([02 §2.2](02-app-registry-and-manifest.md)):
a key, a label, a flat variable list (`{name, type, sample}`, string/number only) and a **fallback**.
The Brain builds the `afsemail` variables dialog from that list, so a staff member designing the
`note_shared` handler sees exactly the variables that event provides — no more, no fewer.

Rules carried over from the mail work already done for `www`:

- **Flat `{name, value}` variables only**, string or number. A list (products, line items) is
  pre-rendered by the app to an **HTML string** and passed as one variable.
- **A variable used in a handler but absent from the send** renders empty; it never aborts the send.
- **Escaping.** A variable's value is HTML-escaped when it is substituted into a message, so an app bug or hostile input cannot become markup in an email. A variable the manifest declares `html: true` is passed through as the app supplied it (the app pre-rendered it and vouches for it).
- **An unknown `event_key`** is refused (`422`) — apps can only fire what they declared.

## 5.3 The handler

The handler is the **same JSON object the `afsemail` builder already produces** and
`normalizeHandler()` already reads (`www/resources/js/portal/admin/afsemail/afsemail.ts`), stored in
`brain_app_mail_handlers.handler` ([03](03-data-model.md)). Consequences:

- The Brain UI reuses the builder as it is — nothing about it is repackaged ([07](07-brain-admin-ui.md)).
- `MailHandlerSender` renders it exactly as it renders a tenant handler; the only new code is the
  lookup by `(app, event_key)`.
- One handler per `(app, event_key)` in v1. Variants (per language, per audience) are a later
  extension — the table's unique key would relax to include a `variant`.

## 5.4 The send call

`POST /api/internal/brain/apps/{app}/mail/send` (signed, [02 §2.5](02-app-registry-and-manifest.md)):

```json
{
  "event_key": "note_shared",
  "to": [{ "email": "sam@example.com", "name": "Sam" }],
  "variables": [
    { "name": "recipient_name", "value": "Sam" },
    { "name": "note_title",     "value": "Q4 plan" },
    { "name": "share_url",      "value": "https://…" }
  ],
  "request_id": "b1c7…"
}
```

Up to 10 recipients per call; each is sent separately. The answer is
`{ success, status: sent|failed|skipped, via: handler|fallback, results: [{status, error}] }`.

| Response | Meaning | App should |
|----------|---------|------------|
| `200 {status:"sent", via}` | Sent — `via: handler` if a configured, enabled handler was used, `via: fallback` if the manifest's built-in message was sent instead | Nothing |
| `502 {status:"failed"}` | The send pipeline or provider failed (the Brain never answers `500` for that — a transport error is caught and logged) | Treat as not sent; the app decides whether to retry |
| `422` | Unknown event, malformed body, variable of a disallowed type | Log; do not retry |
| `429` | Per-app send rate limit hit (§5.7) | Back off; try later or fall back |
| `5xx` / timeout | Brain unavailable | Send the fallback (or queue and retry, app's choice) |

`request_id` makes the call **idempotent**: a repeat within 24 h returns the first result without a
second send. The app generates it once per logical event.

## 5.5 The fallback — why every event has one

A configured handler is an *upgrade*; an app must never depend on it existing. Each manifest event ships a plain
fallback (`subject` + `body_text`). **When there is no enabled handler the Brain sends that fallback itself** (built
into a minimal MJML message with the platform sender), so an app needs no mail transport of its own for the normal case.

The app-side fallback is only for **"the Brain cannot be reached"**, and only matters for `essential` events (a password
reset, a receipt). An app can name a `fallback_mailer` class in its `brain-client` config to send such an event itself;
with none configured the event is logged and dropped like any other ([06 §6.4](06-app-integration.md)). No app has such a
transport today, so **`essential` events must not be added to a manifest until the app has one.**

## 5.6 Sender identity and provider

One **platform sender**: the mailbox in `config('brain.mail.from')` (`BRAIN_MAIL_FROM`, default `no-reply`) is the
`from` of the built-in fallback message; a designed handler carries its own `from`, as it does for any handler the
builder produces. A per-app override setting is not built. Provider credentials are never stored in a handler — the
Brain's send path injects them.

## 5.7 Abuse and volume limits

The Brain is a shared sender for every app, so one buggy app must not exhaust the provider quota or
get the platform's domain blocked:

- **Per-app rate limit** (default e.g. 60 sends/min, 5,000/day; a Brain setting).
- **Per-recipient cap** (same recipient + same event: at most N per hour).
- **Recipient must be supplied by the app for that event** — the handler cannot add arbitrary
  recipients from the Brain side except a fixed staff-configured cc/bcc list.
- Over-limit sends are counted and surfaced (`status = 'skipped'` in the log), never silently
  dropped.

## 5.8 Logging

`brain_app_mail_log` records app, event, handler, a **hash** of the recipient, status and error
([03 §3.1](03-data-model.md)). The Apps → {app} → Mail screen shows recent sends per event so staff
can confirm a handler is firing. Message bodies are **not** stored.

## 5.9 Acceptance

- A handler designed in the Brain for `NOTES_EDITOR/note_shared` is used when the app fires that event.
- Disabling the handler makes the Brain send the manifest fallback; no app change needed.
- An undeclared `event_key` is refused; an app cannot fire another app's events.
- Repeating a send with the same `request_id` sends once.
- A variable of a disallowed type is rejected with `422`.
- Exceeding the per-app rate limit returns `429` and logs `skipped`.
- The mail log contains no recipient address and no message body.

## 5.10 How it is wired, and the limits of what was checked

- **The seam.** `AppMailService` decides *what* to send and logs it; it hands one recipient at a time to an
  `AppMailTransport`. The production transport is `BillingMailerTransport`, which calls
  `BillingMailer::sendOneJson()` — `sendOne()` split so it can send a handler group that is not a
  `laravel_mail_handlers` row. Tests bind a fake transport, so they need no provider, MJML or shell.
- **A defect found and fixed on the way.** `FunctionsController::__get_main_configurations()` called
  `GetClientRequestToken()` (and that called `ClietRequestTokens()`) as bare functions; both are static methods, so every
  caller — `BillingMailer`, `MailHandlerSender` — died with "undefined function" before sending anything. Both calls are
  now `self::`.
- **What the legacy pipeline still needs.** The pipeline behind `SendEmailBuildIntegration` writes debug files under
  `/home/appfactorystudio/websites/dev/www15/storage/store/` and shells out to an MJML transform on the production
  host layout. **On the vagrant lab it fails at the first of those** — so a real send cannot be exercised there. The route
  answers `502 failed`, logs `transport_exception` and never a `500`. A real delivery has not been verified anywhere.
