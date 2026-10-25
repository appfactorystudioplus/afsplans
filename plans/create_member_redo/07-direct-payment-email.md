# 07 — Direct Payments sends its mail handlers on a successful charge

Status: DONE 2026-10-15 for parts A to D and the job (the `variables.json` group was already done); see "Done 2026-10-15" at the end. Not exercised against a real
mail provider. Code blocks above that section show the design; the code in `w/www` is the source of truth.

Paths are under `/Users/jamesmitchell/Desktop/projects/vagrant/afs-eco/w/www/` unless stated.

This replaces the email part of [03-welcome-email.md](03-welcome-email.md). Direct Payments has no welcome email, no member and
no tier: an operator attaches a mail handler to a Direct Payment, and the module sends it when a purchase succeeds.
Plan [04](04-charge-succeeded-hook.md) supplies the "a charge succeeded" call; this plan is what that call does.

## 1. Part A — look mail handlers up by `item_reference_num` only

A handler row carries its own unique item reference. Filtering on `category` as well makes the lookup fail whenever the
category was saved wrong or differently. Remove the `where('category', ...)` from every module's handler lookup.

### 1.1 The lookups to change (every one found in `app/`)

| File | Line | Lookup today | Becomes |
|---|---|---|---|
| `app/Mail/FormsMailer.php` | 44 | `category = 'forms'` + `item_reference_num = $form->reference_num` | `item_reference_num = $form->reference_num` |
| `app/Mail/EcommerceReceiptService.php` | 68 | `category = 'ecommerce'` + `item_reference_num = $order->store_reference_num` | `item_reference_num = $order->store_reference_num` |
| `app/Mail/EventCommunicationService.php` | 60 | `category = 'events'` + `item_reference_num` | `item_reference_num` only |
| `app/Mail/AppointmentCommunicationService.php` | 74 | `category = 'appointments'` + `item_reference_num` | `item_reference_num` only |
| `app/Mail/SiteUsersMailer.php` | 52 | `category = 'site_users'` + `item_reference_num = "{site_users_id}:{purpose}"` | `item_reference_num` only |
| `app/Mail/SiteUsersMailer.php` | 114 | same, `"{site_users_id}:email_changed"` | `item_reference_num` only |
| `app/Mail/SiteUsersMailer.php` | 161 | same, `"{site_users_id}:admin_email_set"` | `item_reference_num` only |
| `app/Http/Controllers/Externals/Sponsorships/SponsorshipApplicationController.php` | 330-331 | `item_reference_num = $mailHandlerRef` + `category = 'sponsorships'` | `item_reference_num` only |

Already without a category filter, nothing to change: `FormsMailer::sendConfiguredHandlers_obsolete()` (line 93),
`MailHandlerController::index()` (line 20), `MailHandlerApiController::resolveHandlers()` (line 46, a precedent for
this exact lookup). Handler lookups by `reference_num` (`MailHandlerSender`, `BillingMailer`, `TestFunctions`,
`DirectPaymentsController:1017`, `DirectPaymentAdvancedController:189`) are by the handler's own id and unaffected.

Not a mail-handler category, leave alone: `DocumentController.php:222` (`$request->input('category', 'documents')` is a
document category).

### 1.2 What stays

* The `category` column (`laravel_mail_handlers.category`, NOT NULL) and `MailHandlerController::store()` still write
  it; nothing reads it for lookup afterwards.
* Docblocks that say "rows tagged `category = 'x'`" (`FormsMailer.php:24`, `EcommerceReceiptService.php:39`,
  `EventCommunicationService.php:30`, `AppointmentCommunicationService.php:37`) are reworded to "rows whose
  `item_reference_num` is the module item's reference".

### 1.3 Checks

* Site Users already builds composite item references (`"{site_users_id}:{purpose}"`) so its three notices stay
  separate without a category.
* No test creates or reads these rows (`grep` over `tests/` finds only `tests/Support/ObsoleteSiteUsersMailer.php`).
* Assumption to accept: an `item_reference_num` is unique across modules. Two different module items sharing one
  reference string would send each other's handlers. The references are generated random strings, so this is a
  low-probability risk, not a guarantee.

## 2. Part B — `DirectPaymentCommunicationService`

New file `app/Mail/Externals/DirectPaymentCommunicationService.php`, namespace `App\Mail\Externals` (the first class in
that folder; `App\` is autoloaded from `app/`, so no config change). It follows `EcommerceReceiptService`, takes the
saved invoice, and runs for every provider.

```php
<?php

namespace App\Mail\Externals;

use App\Mail\MailHandlerSender;
use App\Mail\MailHandlerSenderOptions;
use App\Models\Externals\DirectPaymentInvoice;
use App\Models\MailHandlerModel;
use Illuminate\Support\Facades\Log;

class DirectPaymentCommunicationService
{
    /** Sends every mail handler attached to the invoice's Direct Payment. */
    public static function paymentSucceeded(DirectPaymentInvoice $invoice): void
    {
        if (empty($invoice->direct_payment_reference_num)) {
            return;
        }

        // The module's own variables, then whatever the providers registered in config/mail_variables.php add or
        // override (part D). Everything is locked except `email`, so a provider can change the recipient.
        $standard  = $invoice->clientVariables();
        $variables = ClientVariables::build(
            'direct_payments',
            $invoice,
            $standard,
            locked: array_values(array_diff(array_keys($standard), ['email'])),
        );

        $recipient = (string) ($variables['email'] ?? '');       // the final `email` variable is the recipient
        if ($recipient === '' || $recipient === 'guest@example.com') {
            return;
        }

        $handlers = MailHandlerModel::query()
            ->where('item_reference_num', $invoice->direct_payment_reference_num)
            ->get();

        foreach ($handlers as $handler) {
            try {
                MailHandlerSender::send(new MailHandlerSenderOptions(
                    mailhandler_reference_num: $handler->reference_num,
                    emails: [$recipient],
                    client_variables: $variables,
                ));
            } catch (\Throwable $e) {
                Log::warning('[DirectPayments] Mail handler send failed', [
                    'invoice' => $invoice->reference_num,
                    'handler' => $handler->reference_num,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
}
```

Domain purchases pass through the same call; their Direct Payment has no handlers, so nothing is sent.

### 2.1 The delay (a queued job, not a sleep in the webhook)

The webhook must answer the provider quickly, and other actions need a moment to finish first (for example the member
row that `POST /memberships/register` creates a few seconds after the payment, which a registered variable provider
reads). So `ChargeSucceeded::handle()` does not send; it queues a delayed job, and the job calls the service when it
runs, so the providers see whatever was written in the meantime.

`config/direct_payments.php` (a literal, no `.env` variable):

```php
<?php

return [
    // seconds between a successful charge and its mail handlers being sent
    'mail_delay_seconds' => 30,
];
```

`app/Jobs/SendDirectPaymentMail.php` (same shape as `ExecutePendingTransfersJob`):

```php
class SendDirectPaymentMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;          // a retry would send again the handlers that already went out

    public function __construct(public readonly string $invoiceReferenceNum)
    {
    }

    public function handle(): void
    {
        $invoice = DirectPaymentInvoice::where('reference_num', $this->invoiceReferenceNum)->first();
        if ($invoice === null) {
            return;
        }

        DirectPaymentCommunicationService::paymentSucceeded($invoice);
    }
}
```

`ChargeSucceeded::handle()` (plan 04):

```php
public static function handle(DirectPaymentInvoice $invoice): void
{
    try {
        SendDirectPaymentMail::dispatch($invoice->reference_num)
            ->delay(now()->addSeconds((int) config('direct_payments.mail_delay_seconds', 30)));
    } catch (\Throwable $e) {
        Log::error('[ChargeSucceeded] could not queue the mail', ['invoice' => $invoice->reference_num, 'error' => $e->getMessage()]);
    }
}
```

Running the queue: the delayed job is picked up by one scheduled command that runs `queue:work --stop-when-empty`
every minute from the existing Laravel scheduler (the crontab entry `core/scripts/laravel-cron.sh` installs, in apache's crontab, plan 10). No
separate worker process is needed. Details and what must be true on each server are in
[08-queue-worker.md](08-queue-worker.md). A `sleep()` in the webhook is not used: it holds the PHP process while the
provider waits for its response.

## 3. Part C — turning each provider's response into the client variables

The three providers send different shapes. The service must not know that, so the work is split in two:

1. **One parser per provider** reads the provider's object and returns the same plain value object.
2. **The invoice carries the result**: the webhook writes the parsed values onto the invoice, and the invoice builds
   the standard client variables from its own columns (`$invoice->clientVariables()`). The service adds the registered
   providers' variables on top (part D).

### 3.1 The value object

`app/Externals/Processor/ChargeParsers/ChargeDetails.php`

```php
final class ChargeDetails
{
    public function __construct(
        public readonly int $amountCents,
        public readonly string $currency,           // lower case, e.g. 'usd'
        public readonly ?string $cardBrand,         // normalised: visa, mastercard, amex, discover, diners, jcb, unionpay
        public readonly ?string $cardLast4,
        public readonly ?string $customerEmail,
        public readonly ?string $customerName,
        public readonly ?string $receiptUrl,
        public readonly string $paymentMethod,      // 'card' or 'paypal'
        public readonly ?\DateTimeInterface $chargedAt,
    ) {
    }
}
```

### 3.2 The parsers

`app/Externals/Processor/ChargeParsers/StripeChargeParser.php`, `SquareChargeParser.php`, `PayPalChargeParser.php`,
each `public static function parse(array $object): ChargeDetails`, no database access.

| Field | Stripe (`charge`) | Square (`payment`) | PayPal |
|---|---|---|---|
| amount | `amount` (minor units) | `amount_money.amount` (minor units; seen in Square's sample) | capture `amount.value` (decimal string) → `round(value * 100)` |
| currency | `currency` | `amount_money.currency` | capture `amount.currency_code` |
| card brand | `payment_method_details.card.brand` | `card_details.card.card_brand` (seen in Square's sample) | `payment_source.card.brand` in the order response; **unconfirmed**; absent for a PayPal-wallet payment |
| last4 | `payment_method_details.card.last4` | `card_details.card.last_4` (seen in Square's sample) | `payment_source.card.last_digits`; **unconfirmed** |
| email | `receipt_email`, else `billing_details.email` | `buyer_email_address` | order `payer.email_address` |
| name | `billing_details.name` | none on the payment object (customer lookup needed) | order `payer.name.given_name` + `surname` |
| receipt link | `receipt_url` | `receipt_url` | none |
| method | `card` | `card` | `card` when a card brand is present, else `paypal` |
| charged at | `created` (unix time) | `created_at` | `create_time` |

Fields marked unconfirmed are checked against a sandbox payload before they are coded. Brand names are normalised in
the parser (Square sends `MASTERCARD`, `AMERICAN_EXPRESS`; Stripe sends `mastercard`, `amex`), with the mapping table
inside each parser.

### 3.3 The invoice carries it

`app/Models/Externals/DirectPaymentInvoice.php` gains two methods (`applyChargeDetails()`, `clientVariables()`) and no new columns:

```php
/** Writes a parsed charge onto the invoice. Existing columns where they exist, metadata keys for the rest. */
public function applyChargeDetails(ChargeDetails $d): void
{
    $this->amount_cents = $d->amountCents;
    $this->currency = $d->currency;
    $this->charged_at = $d->chargedAt ?? now();
    if ($d->customerEmail !== null && ($this->customer_email === null || $this->customer_email === '' || $this->customer_email === 'guest@example.com')) {
        $this->customer_email = $d->customerEmail;          // also replaces the checkout placeholder
    }

    $existing = is_string($this->metadata) ? json_decode($this->metadata, true) : ($this->metadata ?? []);
    $this->metadata = array_merge((array) $existing, [
        'card_brand'     => $d->cardBrand,                  // key names unchanged from today
        'last4'          => $d->cardLast4,
        'customer_name'  => $d->customerName,
        'receipt_url'    => $d->receiptUrl,
        'payment_method' => $d->paymentMethod,
    ]);
}

/** The flat, module-prefixed variables every Direct Payments mail handler receives. */
public function clientVariables(): array
{
    $meta = is_array($this->metadata) ? $this->metadata : (json_decode((string) $this->metadata, true) ?: []);

    return [
        'direct_payments_amount'         => number_format(((int) $this->amount_cents) / 100, 2, '.', ''),
        'direct_payments_amount_cents'   => (int) $this->amount_cents,
        'direct_payments_currency'       => strtoupper((string) $this->currency),
        'direct_payments_card_brand'     => (string) ($meta['card_brand'] ?? ''),
        'direct_payments_card_last4'     => (string) ($meta['last4'] ?? ''),
        'direct_payments_payment_method' => (string) ($meta['payment_method'] ?? ''),
        'direct_payments_customer_name'  => (string) ($meta['customer_name'] ?? ''),
        'email'                          => (string) $this->customer_email,   // standard for every module: the recipient
        'direct_payments_receipt_url'    => (string) ($meta['receipt_url'] ?? ''),
        'direct_payments_invoice_number' => (string) $this->reference_num,
        'direct_payments_charged_at'     => (string) ($this->charged_at?->toDateTimeString() ?? ''),
    ];
}
```

Every key is always emitted, even when empty, so a template never shows a literal placeholder.

### 3.4 Where each provider calls its parser

Each `record…Charge()` replaces its inline field reads with the parser and one `applyChargeDetails()` call, then
`ChargeSucceeded::handle($invoice)` as in plan 04:

| Handler | Parser |
|---|---|
| `recordStripeCharge()` (`charge.succeeded`) | `StripeChargeParser::parse($payload['data']['object'])` |
| `recordSquareCharge()` (`payment.updated`, status `COMPLETED`) | `SquareChargeParser::parse($payload['data']['object']['payment'])` |
| `recordPayPalCharge()` (capture / order completed webhook) | `PayPalChargeParser::parse($payload['resource'])` |
| `ProcessorIntentController::capturePayPalOrder()` (decision A in plan 04) | `PayPalChargeParser::parse($captureRes)`; the capture response is where the order's `payment_source` and payer are available |

Example, in `recordStripeCharge()` after the invoice is found or created:

```php
$details = StripeChargeParser::parse($obj);
$invoice->applyChargeDetails($details);
$invoice->status = 'succeeded';
$invoice->charge_id = $chargeId;
// ...the existing processor, customer and split fields stay as they are...
$invoice->save();

ChargeSucceeded::handle($invoice);
```

`recordStripeInvoiceCharge()` (`invoice.payment_succeeded`) keeps recording the invoice as today and does not fire the
hook (plan 04, Stripe decision A).

## 4. Part D — a generic client-variable registry

Any API can add to, or override, the variables a module sends, without a line per event in `AppServiceProvider` and
without exceptions. One registry serves every module; a module is just a key.

### 4.1 Contract and registry

`app/Mail/Externals/ClientVariableProvider.php`

```php
interface ClientVariableProvider
{
    /** Variables to add for this subject (an invoice, an order, a form submission, ...). Return [] when it has nothing to add. */
    public function variables(object $subject): array;
}
```

`app/Mail/Externals/ClientVariables.php`

```php
final class ClientVariables
{
    /**
     * @param  string               $module    key in config/mail_variables.php, e.g. 'direct_payments'
     * @param  array<string,string> $standard  the module's own variables
     * @param  list<string>         $locked    standard names providers may not override
     * @return array<string,string>
     */
    public static function build(string $module, object $subject, array $standard, array $locked = []): array
    {
        $result = $standard;

        foreach ((array) config('mail_variables.' . $module, []) as $providerClass) {
            try {
                $provider = new $providerClass();
                if (!$provider instanceof ClientVariableProvider) {
                    Log::warning('[ClientVariables] Not a provider, skipped', ['module' => $module, 'class' => $providerClass]);
                    continue;
                }
                $values = $provider->variables($subject);
            } catch (\Throwable $e) {
                Log::warning('[ClientVariables] Provider failed, skipped', ['module' => $module, 'class' => $providerClass, 'error' => $e->getMessage()]);
                continue;
            }

            foreach ($values as $name => $value) {
                if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                    Log::warning('[ClientVariables] Invalid variable name, skipped', ['module' => $module, 'class' => $providerClass, 'name' => $name]);
                    continue;
                }
                if (in_array($name, $locked, true)) {
                    Log::warning('[ClientVariables] Locked variable, skipped', ['module' => $module, 'class' => $providerClass, 'name' => $name]);
                    continue;
                }
                $result[$name] = (string) $value;          // later providers override earlier ones
            }
        }

        return $result;
    }
}
```

It never throws: a provider that throws, is not a provider, or returns a bad name is logged and skipped, and the
email still goes out with everything else.

### 4.2 Registration: one config file

`config/mail_variables.php`

```php
<?php

return [
    // module => provider classes, run in this order
    'direct_payments' => [
        \App\Services\Billing\AddMemberVariables::class,
    ],
    // 'ecommerce' => [...],   any module name works with no other change
];
```

A provider is one line here; a new module needs no line. The classes are strings in config, so Direct Payments code
never imports `App\Services\Billing` and `LayeringTest` stays clean.

### 4.3 Rules

* The module's own variables are the base. Providers run in config order, and a later provider overrides an earlier one.
* The caller decides what is locked. Direct Payments locks every standard variable except `email`, so a provider may
  override `email` (the final value is the recipient) and may add any name of its own.
* Names are lowercase letters, digits and underscores.

### 4.4 Example provider (appfactory side)

`app/Services/Billing/AddMemberVariables.php`

```php
class AddMemberVariables implements ClientVariableProvider
{
    public function variables(object $subject): array
    {
        if (!$subject instanceof DirectPaymentInvoice) {
            return [];
        }

        $member = Membership::where('reference_num', $subject->user_id)->first();
        if ($member === null) {
            return [];                                    // not a member payment (a domain purchase): add nothing
        }

        return [
            'username'         => (string) $member->username,
            'full_name'        => trim(($member->firstname ?? '') . ' ' . ($member->lastname ?? '')),
            'membership_title' => (new TierRegistry())->labelFor((int) $member->membership) ?? '',   // labelFor() is an instance method; only clamp() is static
            'email'            => (string) $member->email,   // overrides the invoice email: send to the account address
        ];
    }
}
```

This provider is an example of what a hooking API writes. It is not part of the Direct Payments work and is not built by
this plan.

## 5. Variable reference: `app/Mail/variables.json`

The email editor imports `app/Mail/variables.json` (`resources/js/portal/admin/afsemail/afsemail.ts:102`) as the catalog
of variable names. DONE: the standard Direct Payments variables were added to that file as a new group:

`email`, `direct_payments_amount`, `direct_payments_amount_cents`, `direct_payments_currency`,
`direct_payments_card_brand`, `direct_payments_card_last4`, `direct_payments_payment_method`,
`direct_payments_customer_name`, `direct_payments_receipt_url`, `direct_payments_invoice_number`,
`direct_payments_charged_at`.

Variables added by a registered provider (such as `username` above) are added to the file by whoever writes that
provider; they are not sent unless the provider is registered.

## 6. Order of work (after approval)

1. Part A: remove the eight category filters and update the four docblocks.
2. `ChargeDetails`, the three parsers, and the invoice methods, with unit tests per parser against recorded payload
   samples (Stripe and Square from their docs; PayPal after a sandbox capture).
3. Wire the parsers into the four handlers (with plan 04's hook calls and the Square event fix).
4. `ClientVariableProvider`, `ClientVariables`, `config/mail_variables.php`, `DirectPaymentCommunicationService`, `config/direct_payments.php`,
   `SendDirectPaymentMail`, then `ChargeSucceeded::handle()` queues it; the `queue:work` scheduled line from plan 08.
5. A feature test per provider: a fake payload in, handler lookup by `item_reference_num`, `MailHandlerSender` called
   once with the expected variables; a registry test (a failing provider, a bad name, a locked name, an `email` override).
   One test runs `SendDirectPaymentMail` through `queue:work` from the console (section 8, console context).

## 7. Open

* Variable names: `direct_payments_*` is proposed, as the other modules prefix theirs; `email` is the standard recipient.
* DECIDED: the mail goes out 30 to about 90 seconds after the charge (30-second queue delay plus the next minute's scheduler run).
* The scheduler entry must be installed in apache's crontab on each server as part of this rollout (plans 08 and 10).
* Square gives no customer name on the payment; PayPal's card fields are unconfirmed (section 3.2).
* `amount / 100` assumes a two-decimal currency; zero-decimal currencies (JPY) would show wrongly.
* Whether Stripe's `invoice.payment_succeeded` should also feed a parser for subscriptions' extra facts later.

## 8. Facts about `MailHandlerSender` that this plan relies on (carried over from the superseded plan 03)

* `MailHandlerSender::send()` ends in `FunctionsController::SendEmailBuilder`, which sends only when the active mail provider
  row's `provider` is `appfactory_studio`; otherwise nothing happens (intended).
* It reads `FunctionsController::getBrainConfig()['controller']['email_providers'][0]['token']` without a guard
  (`app/Mail/MailHandlerSender.php:41-43`; `BillingMailer` records the same hazard as B24). On a server with no Brain config
  the call throws; the service catches it per handler and logs it, so the job does not fail, but no mail is sent. Tenants are the
  case to test.
* Its callback writes a debug file at a hard-coded path
  (`/home/appfactorystudio/websites/dev/www15/storage/store/hello99.json`) on every send. Not changed by this plan; under
  "everything runs as apache" (plan 10) that path must be writable by apache or the write silently fails.
* The job runs in the scheduler's console context (no HTTP request). `BillingMailer` already runs the same
  `FunctionsController` configuration calls from a scheduled command, so this should work, and the feature test in section 6 runs
  it from the console to prove it.
* `MailHandlerSenderOptions` is declared inside `app/Mail/MailHandlerSender.php`, so Composer's PSR-4 autoloader can find it only after `MailHandlerSender` has been
  loaded. The service therefore writes `MailHandlerSender::send(new MailHandlerSenderOptions(...))` in that order, as the existing modules do (PHP loads the
  static class before it evaluates the arguments). Do not reference `MailHandlerSenderOptions` first (a type hint, or `new` on its own line) without moving it to its own file.

## Done 2026-10-15

Committed in `w/www`, nothing pushed. Tests: `tests/Unit/DirectPayments/ChargeParsersTest.php` (7), `tests/Feature/DirectPayments/DirectPaymentMailTest.php` (10),
`tests/Unit/Mail/MailHandlerLookupTest.php` (1). Unit, Direct Payments, Processor, Site Users, Events, Ecommerce, Appointments and Connections suites all pass on a throwaway local MySQL
(run per directory with `memory_limit=512M`; one process for all of them runs out of the default 128 MB).

* **Part A:** the eight `where('category', ...)` filters are gone (`EcommerceReceiptService`, `AppointmentCommunicationService`, `FormsMailer`, `SiteUsersMailer` x3, `EventCommunicationService`,
  `SponsorshipApplicationController`); the five docblocks are reworded. A static test fails if one comes back.
* **Part C:** `app/Externals/Processor/ChargeParsers/` (`ChargeDetails`, `StripeChargeParser`, `SquareChargeParser`, `PayPalChargeParser`); `DirectPaymentInvoice::applyChargeDetails()` and `clientVariables()`.
  `recordStripeCharge()`, `recordSquareCharge()`, `recordPayPalCharge()` and `capturePayPalOrder()` use them. `ChargeDetails::normaliseBrand()` and `::date()` hold the brand names and date reading.
  The `card_brand` metadata key written by `applyChargeDetails()` is the "this charge event already ran" marker plan 04 relies on.
* **Part D:** `app/Mail/Externals/ClientVariableProvider.php`, `ClientVariables.php`, `config/mail_variables.php` (`direct_payments` => none registered; the member provider of section 4.4 is an example and is not built).
* **Part B:** `DirectPaymentCommunicationService::paymentSucceeded()` and `SendDirectPaymentMail::handle()` call it. Addition to the plan text: `paymentSucceeded()` takes an optional closure that receives
  (handler reference, recipient, variables) instead of calling `MailHandlerSender`; the tests use it because `MailHandlerSender` is static and talks to the Brain config.
* **Behaviour changes to know about:** a charge no longer overwrites an invoice's `customer_email` with the provider's (or with null for Square and PayPal); it fills it only when it is empty or the checkout
  placeholder `guest@example.com`. `direct_payments_amount_cents` is sent as a string like the other variables.
* **Not verified:** a real send through `MailHandlerSender` (no mail provider or Brain config in the tests; the job test shows a failing send is caught); PayPal's `payment_source.card` field names against a
  sandbox payload; the console context of `queue:work` (plan 08).
* **Open as in section 7:** zero-decimal currencies show a wrong amount (`/ 100`); Square gives no customer name; whether `invoice.payment_succeeded` should feed a parser later.
