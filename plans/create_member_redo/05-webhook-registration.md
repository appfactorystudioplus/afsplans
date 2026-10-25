# 05 — Register provider webhooks from the admin processor dashboard (Stripe, Square, PayPal)

Status: DONE 2026-10-15 in `w/www` (steps 1 to 6 of section 5); step 7 (the dev database) is not done and waits for your word. The five open questions in
section 6 were answered with the defaults listed under "Done 2026-10-15" at the end. Code blocks above that section show the design; the code is the source of truth.

Paths are under `/Users/jamesmitchell/Desktop/projects/vagrant/afs-eco/w/www/` unless stated. Legacy code
(`packages/wwwafsportal_v1`) is only read for how it worked; nothing is reused from it.

## 1. Goal

On the processor detail page the admin presses **Register webhook**. The server calls the provider's API, creates (or
reuses) the webhook for this site's URL with the events the handler needs, stores the provider's id and signing
secret, and the page shows the result. No pasting of URLs into a provider dashboard and no copying a secret back.
Works the same for Stripe, Square and PayPal, per processor row, sandbox or live.

## 2. How the legacy Stripe registration worked (read for reference)

`packages/wwwafsportal_v1/public/_portal_/admin/core/services/stripe/app1/server/server.js`, `RunWebooks2()`:

1. `stripe.webhookEndpoints.list({limit:16})`.
2. For each configured webhook, build its URL; if one in the list has the same URL, reuse it and **skip** (the secret
   of an existing endpoint was never recovered).
3. Otherwise `stripe.webhookEndpoints.create({ url, enabled_events })` and keep the returned `secret`.

Lessons: list-then-match-by-URL is right (no duplicates); the Stripe secret is only returned on create; the event
list lived in per-webhook JSON, which is why it drifted from what the handler needs.

## 3. What exists now (facts read from the code)

| Fact | Where |
|---|---|
| The admin page shows a copy-only URL and instruction text; it calls no provider API. Badge says "Auto-configured". | `resources/js/portal/admin/controllers/processors/ProcessorDetailView.ts` `renderWebhookSection()`, `getWebhookInstructions()` (line 1422) |
| Instruction text lists Stripe `charge.succeeded`, `payment_intent.succeeded`; Square `payment.completed` (not a Square event); PayPal `PAYMENT.CAPTURE.COMPLETED` | same file |
| Secrets are typed in by the admin: Stripe `whsec_…` and Square signature key into `laravel_processors.webhook_secret_encrypted`; PayPal webhook id into `extra_config.webhook_id` | `ProcessorAdminController.php:77,139`; handler reads them in `authenticateStripeWebhook()`, `authenticateSquareWebhook()`, `authenticatePayPalWebhook()` |
| Table `laravel_processor_webhook_endpoints` exists: `processor_reference_num`, `provider`, `mode`, `kind` (comment: `v1 \| v2_event_destination`), `endpoint_id`, `secret_encrypted`; unique `(processor_reference_num, kind)`; model has `setSecret()/getSecret()` | `database/migrations/2026_05_21_000001_create_processor_tables.php:353`, `app/Models/Externals/Processor/ProcessorWebhookEndpoint.php` |
| Only Stripe **v2** is registered by code, via artisan | `app/Console/Commands/RegisterEventDestinationsCommand.php` |
| Square verifies with `HMAC(url('/portal/webhooks?type=square') + body, key)`, so the registered URL must equal that string exactly | `ProcessorWebhookController::handleSquare()` line 942 |
| PayPal verifies by calling PayPal's `verify-webhook-signature` with the stored webhook id | `verifyPayPalSignature()` |
| Receiver URL for all three: `POST /portal/webhooks?type={stripe\|square\|paypal}` | `routes/portal.php:1055` |
| Admin processor routes sit under `api/v1/processors` with `externals.dashboard` middleware | `routes/portal.php:931-1034` |

## 4. Design

### 4.1 Database (edit the create file, no alter migration)

Per the consolidation rule, columns go into the existing create statement in
`database/migrations/2026_05_21_000001_create_processor_tables.php` (table `laravel_processor_webhook_endpoints`).
Added columns:

```php
$table->text('url')->nullable();                       // the exact URL registered with the provider
$table->json('enabled_events')->nullable();            // the event names last sent to the provider
$table->string('status', 20)->default('pending');      // pending | registered | failed | removed
$table->text('last_error')->nullable();
$table->timestamp('registered_at')->nullable();
```

`kind` values: `v1` (Stripe, today only the comment), `v2_event_destination` (existing), `square_subscription`,
`paypal_webhook`. `endpoint_id` = Stripe `we_…` / Square webhook subscription id / PayPal webhook id.
`secret_encrypted` = Stripe signing secret / Square signature key / null for PayPal.

One source for the handler: after a successful registration the registrar also writes the values the handlers already
read (`processors.webhook_secret_encrypted` for Stripe and Square, `extra_config.webhook_id` for PayPal), so
`ProcessorWebhookController` does not change. Manual entry on the form stays as a fallback.

No table for delivery health: "last event received" and counts come from the existing `laravel_processor_webhooks`
(and `ProcessorWebhookRejection` for rejects).

Existing databases: every database is rebuilt with `migrate:fresh` (owner), so the new columns arrive with the rebuild and
no alter is written. The `Schema::hasTable` guard around the create statement stays, as in every consolidated file.
The rebuild itself (dev included) is done only when you say so.

### 4.2 Event lists in one place

New `config/processor_webhooks.php`, one array per provider. Drawn from what `ProcessorWebhookController` handles, with
the corrections found in [04](04-charge-succeeded-hook.md):

```php
return [
    'stripe' => [
        'charge.succeeded', 'payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.canceled',
        'charge.failed', 'charge.refunded', 'refund.created', 'refund.updated', 'refund.failed',
        'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed',
        'charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated',
        'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted',
        'invoice.payment_succeeded', 'invoice.payment_failed',
    ],
    'square' => [
        'payment.updated',                      // replaces payment.completed (not a Square event), plan 04 gap 7
        'refund.created', 'refund.updated',     // the handler matches refund.completed; verify against the catalog
        'dispute.created', 'dispute.state.changed',
        'order.created', 'order.updated',
        'subscription.created', 'subscription.updated',
    ],
    'paypal' => [
        'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED',
        'CHECKOUT.ORDER.APPROVED', 'CHECKOUT.ORDER.COMPLETED',
        'PAYMENT.SALE.COMPLETED',               // recurring subscription payment, plan 04
        'BILLING.SUBSCRIPTION.CREATED', 'BILLING.SUBSCRIPTION.ACTIVATED', 'BILLING.SUBSCRIPTION.UPDATED',
        'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.SUSPENDED', 'BILLING.SUBSCRIPTION.EXPIRED',
        'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
        'CUSTOMER.DISPUTE.CREATED', 'CUSTOMER.DISPUTE.RESOLVED',
    ],
];
```

Several handler names (`payment.completed`, `refund.completed`, `payment.canceled`, `PAYMENT.ORDER.*`) are not
confirmed provider events. At registration time each registrar asks the provider for its event catalog (Square
`GET /v2/webhooks/event-types`, PayPal `GET /v1/notifications/webhooks-event-types`; Stripe rejects unknown names
itself) and drops names the provider does not know, returning them as a warning. The handler's `match` arms are fixed
separately.

### 4.3 Code layout (explicit, no container magic)

```
app/Externals/Processor/Webhooks/
    WebhookRegistrar.php            interface
    StripeWebhookRegistrar.php
    SquareWebhookRegistrar.php
    PayPalWebhookRegistrar.php
    WebhookRegistrars.php           static for(Processor): WebhookRegistrar  (a match on provider)
    WebhookUrl.php                  static for(string $provider): string
app/Http/Controllers/Externals/Processor/ProcessorWebhookAdminController.php
```

```php
interface WebhookRegistrar
{
    /** Create or update the provider webhook for this processor; returns the saved endpoint row. Throws WebhookRegistrationException with a user-readable message. */
    public function register(Processor $processor): ProcessorWebhookEndpoint;

    /** Delete it at the provider and mark the row removed. */
    public function remove(Processor $processor): void;

    /** Ask the provider to send a test event, if the provider supports it. Returns false when it does not. */
    public function sendTest(Processor $processor): bool;
}
```

```php
final class WebhookUrl
{
    /** The one URL builder. Square's HMAC is computed over this exact string, so the receiver and the registrar share it. */
    public static function for(string $provider): string
    {
        return url('/portal/webhooks?type=' . $provider);
    }
}
```

The receiver's Square check then reads the stored `url` from the endpoint row when present, instead of recomputing it.

Pre-checks in every registrar, before any API call: the URL is `https`, the host is not `localhost`, `127.0.0.1` or a
`.test`/`.local` name, and the processor has the credentials that provider needs. Failure message names what is
missing.

### 4.4 Per provider

**Stripe** (`StripeWebhookRegistrar`, `kind = v1`; secret key from `Processor::getSecretKey()`):

1. `webhookEndpoints->all(['limit' => 100])`; find the one with this URL.
2. Found and we hold its secret: `webhookEndpoints->update($id, ['enabled_events' => ...])`.
3. Found but we do not hold its secret (registered by hand, or by the legacy service): the secret cannot be read
   back from Stripe. Delete it and create a new one (the admin confirms in the UI first).
4. Not found: `webhookEndpoints->create(['url' => ..., 'enabled_events' => ..., 'description' => 'AFS ' . $processor->reference_num])`; keep `secret`.
5. Save the row and write `processors.webhook_secret_encrypted`.

**Square** (`SquareWebhookRegistrar`, `kind = square_subscription`; the access token is the stored secret key; base
`https://connect.squareupsandbox.com` or `https://connect.squareup.com` by `mode`; `Square-Version` header as in
`ProcessorSubscriptionController`):

1. `GET /v2/webhooks/subscriptions`; find the one whose `notification_url` equals ours.
2. Found: `PUT /v2/webhooks/subscriptions/{id}` with `event_types`; read `signature_key` (retrieve the subscription;
   if the key is not returned, rotate it with `POST /v2/webhooks/subscriptions/{id}/signature-key`).
3. Not found: `POST /v2/webhooks/subscriptions` with `{ idempotency_key, subscription: { name, event_types, notification_url, enabled: true } }`; keep `signature_key`.
4. Save the row and write `processors.webhook_secret_encrypted`.
5. `sendTest()` uses `POST /v2/webhooks/subscriptions/{id}/test`.

**PayPal** (`PayPalWebhookRegistrar`, `kind = paypal_webhook`; client id = `publishable_key`, secret =
`getSecretKey()`; base `api-m.sandbox.paypal.com` / `api-m.paypal.com` by `mode`):

1. OAuth token with client credentials.
2. `GET /v1/notifications/webhooks`; find the one with this URL (PayPal allows one webhook per URL per app, so reuse is required, not optional).
3. Found: `PATCH /v1/notifications/webhooks/{id}` replacing `/event_types`.
4. Not found: `POST /v1/notifications/webhooks` with `{ url, event_types: [{name}, ...] }`; keep `id`.
5. Save the row and write `extra_config.webhook_id`.
6. `sendTest()` uses `POST /v1/notifications/simulate-event` with `webhook_id` and one event type.

New registrars use Laravel's `Http` client (not raw curl) so tests can fake the provider.

### 4.5 Controller and routes

`ProcessorWebhookAdminController`, routes added inside the existing `api/v1/processors` group in `routes/portal.php`
with `externals.dashboard`, placed before the `/{id}` routes:

| Method | Route | Does |
|---|---|---|
| GET | `/{ref}/webhook` | status: row fields, url, events, last event received, counts (no secrets) |
| POST | `/{ref}/webhook/register` | `WebhookRegistrars::for($processor)->register($processor)` |
| DELETE | `/{ref}/webhook` | `remove()` |
| POST | `/{ref}/webhook/test` | `sendTest()` |

Responses never include a secret; only `has_secret`.

### 4.6 Admin UI

`ProcessorDetailView.ts` `renderWebhookSection()` becomes a real status card:

* URL with Copy (kept), status badge (Not registered / Registered / Failed), last event received, events subscribed.
* Buttons: **Register** (becomes **Update events** when registered), **Send test** (Stripe: hidden), **Remove**.
* On error, the provider's message is shown (`last_error`).
* The manual secret fields and `getWebhookInstructions()` stay under an "Advanced / manual" fold, with the event names
  corrected from the config.
* The wrong "Auto-configured" badge goes.

The processors list page (`processors.html`) gets a one-word status per row. i18n keys added to the existing admin
locale files.

### 4.7 Tests

`tests/Feature/...`: for each provider, fake the provider with `Http::fake` (Stripe with an injected client), and check
create, reuse-by-URL, update-events, the Stripe "no secret held" recreate path, the unknown-event warning, and that no
response contains a secret. A layering test keeps these classes free of Direct Payments and appfactory terms.

## 5. Implementation order (after approval)

1. Migration create-file columns + `ProcessorWebhookEndpoint` fillable.
2. `config/processor_webhooks.php`, `WebhookUrl`, interface, the three registrars, `WebhookRegistrars`.
3. Controller and routes.
4. Receiver change: Square reads the stored `url`.
5. UI card, i18n, instruction text.
6. Tests.
7. Dev DB: add columns (needs your OK), then register the dev processors from the page.

## 6. Open

1. Square: does the stored "secret key" for your Square processors hold a **personal access token** with webhook
   subscription permission (OAuth app tokens differ)? Needed for step 4.4.
2. Stripe "found but no secret held": delete and recreate (recommended, with confirm), or require the admin to paste
   the secret?
3. Stripe Connect: the handler also accepts events with an `account` field (connected accounts), which need a separate
   Connect endpoint (`connect: true`). Include a second Stripe endpoint kind now, or leave Connect as is?
4. Event names marked "verify against the catalog" in 4.2: accept the drop-and-warn approach?
5. Should registering also deactivate the legacy Node webhook at Stripe (the `:9295` endpoint) if the list shows it, or
   leave it alone?

## Done 2026-10-15

Committed in `w/www`, nothing pushed. Tests: `tests/Feature/Processor/ProcessorWebhookRegistrationTest.php` (13 tests, providers faked with `Http::fake`); Processor, DirectPayments,
Unit/Database and Externals suites 159 of 159 on a throwaway local MySQL; `tsc --noEmit` is clean.

* **Database:** the five columns (`url`, `enabled_events`, `status`, `last_error`, `registered_at`) are in the create block of `laravel_processor_webhook_endpoints`
  (`2026_05_21_000001_create_processor_tables.php`), no alter migration; model fillable and casts updated.
* **Code:** `app/Externals/Processor/Webhooks/` holds `WebhookRegistrar` (interface), `AbstractWebhookRegistrar` (added: the endpoint row, failure recording and the event list that the three registrars
  share), the three registrars, `WebhookRegistrars::for()`, `WebhookUrl`, `WebhookRegistrationException`; `config/processor_webhooks.php` holds the event lists.
  They use Laravel's `Http` client, including Stripe (the plan said an injected client; `Http` fakes the same way and matches the other two).
* **Routes** (`routes/portal.php`, `externals.dashboard`): `GET /{ref}/webhook`, `POST /{ref}/webhook/register`, `POST /{ref}/webhook/test`, `DELETE /{ref}/webhook`, in
  `ProcessorWebhookAdminController`. No response contains a secret, only `has_secret`.
* **Receiver:** the Square check verifies against the stored `url` of the processor's `square_subscription` row when there is one, and the computed URL otherwise.
* **Admin page:** `ProcessorDetailView.ts` shows a status card (badge, URL with Copy, last event, counts, subscribed events, last error) with Register / Update events, Send test (not Stripe) and Remove;
  a Stripe endpoint whose secret is not held asks for confirmation and then recreates. The manual instructions sit under "Advanced / manual" with the corrected event names; the
  "Auto-configured" badge is gone.
* **Answers to section 6, taken as defaults (change any of them):** (1) the stored Square secret key is used as a bearer token for the webhook subscription calls; this is untested against Square;
  (2) Stripe "found but no secret held" deletes and recreates after the admin confirms (`recreate`); (3) Stripe Connect events are left as they are (no second endpoint);
  (4) event names a provider does not know are dropped and reported in `last_error`; (5) the legacy Node webhook at Stripe is left alone.
* **Not done:** the one-word status per row on the processors list page, and i18n keys (the processor detail view uses English literals throughout); step 7, adding the columns to the dev database
  and registering the dev processors (needs the `migrate:fresh` rebuild you control); a real registration against each provider's sandbox.
