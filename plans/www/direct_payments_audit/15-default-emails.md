# 15 — Default Emails

> **Approved by the owner (2026-10-16); revised 2026-10-17 to send everything through `MailHandlerSender`.** Not wired
> into the app yet — waiting for the owner's go-ahead.
>
> **Templates are in the code now** (inert until wired): 14 complete MJML files and `defaults.json` in
> `w/www/resources/js/portal/admin/afsemail/mjml-templates/mjml/defaults/dp/`, and their rendered previews in
> `w/www/resources/js/portal/admin/afsemail/mjml-templates/html/defaults/dp/`. All compile under MJML strict validation.
> The earlier drafts in [`emails/`](emails/README.md) are superseded by these.

Owner decisions (2026-10-16):

- **Scope: every Direct Payments email gets a default** — the four processor-change emails (always sent), the nine
  existing subscription notification events, and the payment receipt. The existing ones become a one-click "use the
  default" choice; they are **not** switched on for a Direct Payment that never set them up.
- **Mechanism (revised 2026-10-17).** `MailHandlerSender` stays the **only** way email is sent. When a Direct Payment has
  no mail handler for an email, the server sends the default MJML file through `MailHandlerSender` directly — no
  database row. The defaults live in afsemail's template folders, so they show in afsemail's starter gallery for
  inspection; a tenant who wants to change one picks it in the gallery and saves it as a normal handler (no separate
  Customise feature).

## 15.1 How mail works today (read 2026-10-16)

| Piece | What it does |
|---|---|
| afsemail (`resources/js/portal/admin/afsemail/afsemail.ts`) | GrapesJS MJML editor. Opens a handler from `body_mjml_html` (the MJML source) alone; saves both `body_mjml_html` and `body_mjml_projectData`. Starter gallery = pairs in `mjml-templates/html/*.html` + `mjml-templates/mjml/*.mjml` (only `activation.mjml` has content; the other 13 `.mjml` files are empty) |
| `laravel_mail_handlers` | `name`, `category`, `reference_num`, `item_reference_num` (the record it belongs to — for Direct Payments, the DP reference), `json` = `{reference_num, name, item_reference_num, handlers: {<ref>: {active, from, subject, title, emails[], body_mjml_html, body_mjml_projectData, variables: {schema[], client[], system[]}}}}`, stored HTML-entity-encoded |
| Variables | `${name}` placeholders. **schema** = per-template values set in the editor (may contain other variables, substituted first); **client** = values passed at send time; **system** = the site's settings, flattened from `laravel_settings` (`name`, `primaryColor1`, `logo1`, `copyrightShort`, `facebook`, …; unset ones hold the literal string `- NOT SET -`) |
| Send | `FunctionsController::SendEmailBuildIntegration()` → `SendEmailBuilder()`: writes the MJML to a scratch file, converts it with `commands.sh --transform-email-mjml`, substitutes schema → client → system variables into subject/title/HTML, sends through SendGrid |
| Direct Payments receipt | `ChargeSucceeded` → `SendDirectPaymentMail` job → `DirectPaymentCommunicationService::paymentSucceeded()` → `MailHandlerSender::send()` for **every** handler whose `item_reference_num` is the DP |
| Direct Payments subscription notifications | `NotificationDispatcher` → `App\Mail\BillingMailer::sendOne()` with the handler named in `laravel_direct_payment_notifications.mail_handler_reference_num` (required today) |

## 15.2 Problems found while reading (fixed by this plan)

| # | Finding | Where | Consequence |
|---|---|---|---|
| **E1** | **Every handler attached to a Direct Payment is sent as a payment receipt.** `paymentSucceeded()` sends through all handlers with `item_reference_num = DP` | `app/Mail/Externals/DirectPaymentCommunicationService.php` | Any other email a tenant attaches to the DP (a notification, a reminder) also goes out on every payment. Default handlers created by Customise would do the same |
| **E2** | **Receipt variables are very likely never substituted.** `ClientVariables::build()` returns a flat `name => value` array; `MailHandlerSender` passes it straight through; `SendEmailBuilder()` reads `variables.client[$i]` as a per-recipient list of `{name, value}`. `BillingMailer` documents and works around exactly this (its "B25" comment); `MailHandlerSender` does not | `app/Mail/MailHandlerSender.php`; `FunctionsController.php:1885–1888` | Receipts go out with literal `${direct_payments_amount}` etc. **Verify with one test send before fixing** — the code path is unambiguous but has not been run here |
| **E3** | **Direct Payments sends through `BillingMailer`**, an appfactory class name, and its variables include `manage_billing_url` and read `Membership` for the first name | `NotificationDispatcher.php:5, 27, 199, 349, 360–375` | Breaks the separation rule ([13](13-naming-and-separation.md)); listed there as A61 additions |
| **E4** | A notification cannot exist without a hand-built handler (`mail_handler_reference_num` is required and validated) | `DirectPaymentsController::syncNotificationConfigs()` | A tenant who turns on "upcoming charge" must first design an email from scratch |
| **E5** | Brand values that are not set are sent as the literal text `- NOT SET -` (e.g. into a colour attribute) | `SetupEmailSystemVariables()` | Broken colours/links in any template that uses an unset brand value |

## 15.3 The default emails

Fourteen templates, one shared layout. Each is a small MJML **body** in `resources/mail/direct_payments/` plus one line
in a manifest; the renderer wraps it in `_layout.mjml`.

| Key | When it is sent | Default on? | Subject (draft) |
|---|---|---|---|
| `payment_details_required` | Processor change, subscriber must re-enter payment details ([07](07-subscription-continuity.md) §7.6); first notice and each reminder (14/7/3/1 days) | **Always** | Action needed: update your payment details for ${plan_name} |
| `payment_details_updated` | The subscriber completed the renewal page | **Always** | Your payment details are updated |
| `subscription_provider_changed` | Processor change, card copied — nothing to do (S1) | **Always** | Nothing to do: a note about your ${plan_name} subscription |
| `subscription_ending_payment_details_required` | Closing date passed without new details (S3) | **Always** | Your ${plan_name} subscription is ending |
| `upcoming_charge` | Days before a renewal | Opt-in | Your ${plan_name} renews on ${next_charge_date} |
| `trial_ending` | Days before a trial ends | Opt-in | Your free trial ends on ${next_charge_date} |
| `payment_succeeded` | A renewal was paid | Opt-in | Payment received for ${plan_name} |
| `payment_failed` | A renewal payment failed | Opt-in | We couldn't process your payment for ${plan_name} |
| `payment_method_changed` | The subscription's card changed | Opt-in | Your payment method was updated |
| `subscription_updated` | Plan changed | Opt-in | Your subscription changed to ${plan_name} |
| `subscription_cancel_scheduled` | Cancellation scheduled for period end | Opt-in | Your ${plan_name} subscription will end on ${access_ends_at} |
| `subscription_canceled` | Subscription cancelled | Opt-in | Your ${plan_name} subscription is cancelled |
| `subscription_access_ended` | Access actually ended | Opt-in | Your access to ${plan_name} has ended |
| `payment_receipt` | A one-time payment succeeded | Opt-in | Receipt for your payment to ${site_name} |

"Always" emails can be **customised** but not switched off during a processor change (OQ14: never silently off).

## 15.4 Variables the defaults use

Every default uses only variables the sender provides, and every variable is always sent (empty when unknown) so no
literal `${…}` reaches a customer.

| Group | Variables |
|---|---|
| Brand — the site settings both afsemail and the sender already fill | `name`, `primaryColor1`, `logo1`, `copyrightShort`, `site_url`. Before sending a default the server replaces unset values (`- NOT SET -`, **E5**): colour → `#1f2937`, name → the site domain, footer → "© {year} {name}"; with no logo it keeps the brand-name block instead of the logo block |
| Added by the server for the defaults | `dp_greeting` ("Hi {first name}," or "Hello,"), `dp_card` ("Visa ending 4242" or "your saved payment method") |
| Subscription (existing `NotificationDispatcher::buildVars()`) | `firstname`, `lastname`, `email`, `amount`, `currency`, `plan_name`, `direct_payment_name`, `card_brand`, `card_last4`, `manage_subscription_url` (renamed from `manage_billing_url`, [13](13-naming-and-separation.md)), `site_name`, `site_url`, `access_ends_at`, `next_charge_date`, `days_until_charge`, `subscription_status` |
| Processor change (new, PA6) | `renewal_url`, `renewal_date`, `days_left`, `closing_date` (S3), `new_provider_label` ("card", "PayPal" — never an account name) |
| Receipt (existing `DirectPaymentInvoice::clientVariables()`) | `direct_payments_amount`, `direct_payments_currency`, `direct_payments_card_brand`, `direct_payments_card_last4`, `direct_payments_payment_method`, `direct_payments_customer_name`, `direct_payments_receipt_url`, `direct_payments_invoice_number`, `direct_payments_charged_at`, `email` |

For the defaults the server also strips HTML tags and `${` from every client value (a customer can type either into
their own name).

## 15.5 How it works

### Files (in place)

```
w/www/resources/js/portal/admin/afsemail/mjml-templates/
  mjml/defaults/dp/<event>.mjml     14 complete MJML emails (layout included)
  mjml/defaults/dp/defaults.json    label, subject, preview, always_on, variables — per event
  html/defaults/dp/<event>.html     the same emails rendered with sample values, for inspection
```

Other default sets later go beside `dp/` under `defaults/`.

### 1. `MailHandlerSender` — one optional field

```php
class MailHandlerSenderOptions {
    public function __construct(
        public string $mailhandler_reference_num = "",
        public array $emails = [],
        public array $client_variables = [],
        public ?array $mailhandler_json = null,   // new: send this handler JSON instead of loading a laravel_mail_handlers row
    ) {}
}

// MailHandlerSender::send(), replacing the two lines that load the row:
$mailHandlerJson = $options->mailhandler_json
    ?? FunctionsController::Utils_decodeStringToJson(
        \App\Models\MailHandlerModel::where('reference_num', $options->mailhandler_reference_num)->first()['json']
    );
```

Two further changes in the same method, each its own decision:

- **E2** (verify first with one test send): if the receipt arrives with literal `${…}`, `send()` converts a
  `name => value` array into the per-recipient `[[{name, value}, …]]` shape `SendEmailBuilder()` reads. Every module
  calls `send()` the same way, so the fix helps all of them.
- **E3**: `send()` returns a `SendResult` (callers that ignore it are unaffected), so `NotificationDispatcher` can switch
  from `BillingMailer` to `MailHandlerSender` and keep recording sent / failed in its ledger.

### 2. `DirectPaymentDefaultEmail` — reads the file, nothing else

`app/Externals/DirectPayments/Mail/DirectPaymentDefaultEmail.php`:

```php
final class DirectPaymentDefaultEmail
{
    private const DIR = 'js/portal/admin/afsemail/mjml-templates/mjml/defaults/dp/';

    public static function exists(string $event): bool
    {
        return isset(self::manifest()[$event]);
    }

    public static function alwaysOn(string $event): bool
    {
        return (self::manifest()[$event]['always_on'] ?? false) === true;
    }

    /** Handler JSON in the shape a laravel_mail_handlers row holds, for MailHandlerSender's mailhandler_json. */
    public static function json(string $event): array
    {
        $mjml = file_get_contents(resource_path(self::DIR . $event . '.mjml'));
        $mjml = self::applyBrand($mjml);   // E5 fallbacks; keep the logo block or the brand-name block

        return ['handlers' => ['default' => [
            'active' => true, 'integration' => [], 'alert' => false, 'emails' => [],
            'from' => 'no-reply', 'title' => null, 'body' => null,
            'subject' => self::manifest()[$event]['subject'],
            'body_mjml_html' => $mjml, 'body_mjml_projectData' => null,
            'variables' => ['schema' => [], 'client' => [], 'system' => []],
        ]]];
    }

    /** The caller's variables plus dp_greeting / dp_card, cleaned. */
    public static function variables(array $vars): array { /* … */ }

    private static function manifest(): array
    {
        static $m = null;
        return $m ??= json_decode(file_get_contents(resource_path(self::DIR . 'defaults.json')), true);
    }
}
```

### 3. Which handler — one rule, one call

```php
$handlerRef = DirectPaymentNotification::where('direct_payment_reference_num', $dpRef)
    ->where('event', $event)
    ->value('mail_handler_reference_num');

MailHandlerSender::send(new MailHandlerSenderOptions(
    mailhandler_reference_num: (string) $handlerRef,
    mailhandler_json: $handlerRef ? null : DirectPaymentDefaultEmail::json($event),
    emails: [$recipient],
    client_variables: $handlerRef ? $variables : DirectPaymentDefaultEmail::variables($variables),
));
```

When it runs:

| Email | Sent when |
|---|---|
| The four processor-change emails | Always during a processor change (PA6) — the tenant's handler if set, otherwise the default |
| The nine subscription emails | Only when the Direct Payment has turned the event on (`is_active`); handler if chosen, otherwise the default |
| `payment_receipt` | Below |

### 4. Receipts (E1)

`DirectPaymentCommunicationService::paymentSucceeded()`:

- The Direct Payment has a `payment_receipt` setting → send only that (its handler, or the default), if active.
- No such setting → today's behaviour (every handler attached to the DP), but only handlers with
  `category = 'direct_payments'` and not used by any notification setting — so a notification email is never sent as a
  receipt.

### 5. Settings (`laravel_direct_payment_notifications`)

- `mail_handler_reference_num` becomes nullable in the create migration: empty = use the default.
- `EVENTS` gains `payment_receipt`, `payment_details_required`, `payment_details_updated`,
  `subscription_provider_changed`, `subscription_ending_payment_details_required`.
- The four processor-change events cannot be switched off (`DP_E_NOTIFICATION_REQUIRED`).

### 6. Admin

- **Inspect:** afsemail's starter gallery shows the defaults. Its file loader currently reads only the top level of
  `mjml-templates/html` and `mjml-templates/mjml`; it changes to read sub-folders too, and to key each template by its
  path (`defaults/dp/payment_receipt`) so a future default with the same file name never collides.
- **Change one:** pick it in the gallery, edit, save — a normal handler — then choose that handler for the email in the
  Direct Payment's notification settings.
- **Use the default:** the handler dropdown for each email gains a first option, **Default template** (empty value).

## 15.6 Phasing

Phase **DE** in [11](11-rollout-and-testing.md) §11.1. Everything except the four processor-change emails can ship on its
own, before or alongside PA0; those four are sent by PA6, which produces their variables.

## 15.7 Acceptance

- A Direct Payment with no notification settings sends no subscription emails, and receipts exactly as today.
- Turning on `upcoming_charge` with **Default template** sends the default through `MailHandlerSender`, with the site's
  brand colour and name, and no `${…}` left in subject or body.
- An unset logo shows the brand name instead; an unset colour uses `#1f2937`.
- A customer name containing `<b>` arrives as plain text.
- The 14 defaults appear in afsemail's gallery; saving one creates a normal handler that can be chosen for the email.
- A handler used for a notification is never also sent as a receipt.
- One manual test send per template on dev (sandbox, the owner's address) through the real `--transform-email-mjml`
  path, checked in Gmail and Outlook.
