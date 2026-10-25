# 05-webhook-registration — completion record

**Plan file:** [`../05-webhook-registration.md`](../05-webhook-registration.md)
**Repo:** `w/www`
**Date:** 2026-10-15
**Status:** 🟡 Complete in code; not yet run against a real provider or the dev database. Nothing is pushed.

## What shipped

* Registrars for Stripe, Square and PayPal (`app/Externals/Processor/Webhooks/`): list, match by URL, update or create; the signing secret / webhook id is saved on `laravel_processor_webhook_endpoints` and in the field the receiver already reads (`webhook_secret_encrypted`, `extra_config.webhook_id`).
* `config/processor_webhooks.php` (one event list per provider); unknown names are dropped and reported.
* Admin API (`GET/POST/DELETE /portal/api/v1/processors/{ref}/webhook[...]`) and the status card on the processor detail page.
* Square's receiver verifies against the registered URL.

## Deviations from the plan text

* A shared `AbstractWebhookRegistrar` was added next to the interface and three registrars.
* Stripe uses `Http` like the other two instead of an injected `StripeClient`.
* Open questions answered by default: Square token as bearer, Stripe delete-and-recreate after confirmation, Connect and the legacy webhook left alone, unknown events dropped with a warning.

## How it was checked

* `ProcessorWebhookRegistrationTest`: 13 tests (create, reuse by URL, update events, Stripe confirm-and-recreate, provider error, unreachable URL, missing credentials, Square unknown events and key rotation, PayPal create and replace events, no secret in any response, no Direct Payments or appfactory terms in the registrar classes, Square signature against the registered URL).
* Processor, DirectPayments, Unit/Database and Externals suites: 159 of 159 on a throwaway local MySQL 8.0.36; `tsc --noEmit` clean.

## Not verified

* A real registration with each provider's sandbox; the Square token permission; the UI in a browser.

## Residue

* The processors list page has no per-row status and the card has no i18n keys.
* Step 7: the dev database needs the new columns (rebuild) before the page can register the dev processors.
