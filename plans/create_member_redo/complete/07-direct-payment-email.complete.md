# 07-direct-payment-email — completion record

**Plan file:** [`../07-direct-payment-email.md`](../07-direct-payment-email.md)
**Repo:** `w/www`
**Date:** 2026-10-15
**Status:** 🟡 Complete in code; no real mail was sent. Nothing is pushed.

## What shipped

* Part A: every module finds its mail handlers by `item_reference_num` alone (eight filters removed, docblocks reworded, a static test guards it).
* Part C: provider parsers (`StripeChargeParser`, `SquareChargeParser`, `PayPalChargeParser`) produce one `ChargeDetails`; the invoice applies it (`applyChargeDetails()`) and builds the module's variables (`clientVariables()`); the four charge handlers use them.
* Part D: a generic variable registry (`ClientVariableProvider`, `ClientVariables`, `config/mail_variables.php`) that never throws, with a locked-name rule and an `email` override.
* Part B: `DirectPaymentCommunicationService::paymentSucceeded()` sends each handler attached to the Direct Payment; `SendDirectPaymentMail` calls it from the delayed queue job.

## Deviations from the plan text

* `paymentSucceeded()` takes an optional sender closure (the seam the tests use).
* A charge now fills `customer_email` only when it is empty or the checkout placeholder; before, Square and PayPal overwrote it.

## How it was checked

* 18 new tests (parsers, per-provider end to end through the recorders into the service, handler lookup without category, handler failure isolation, registry rules, static no-category guard).
* Unit, Direct Payments, Processor, Site Users, Events, Ecommerce, Appointments and Connections suites pass on a throwaway local MySQL.

## Not verified

* A real send through `MailHandlerSender`; PayPal's card fields against a sandbox payload; the job under `queue:work` in the console (plan 08).

## Residue

* Zero-decimal currencies show a wrong amount; Square has no customer name.
