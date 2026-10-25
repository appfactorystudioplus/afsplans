# 04-charge-succeeded-hook — completion record

**Plan file:** [`../04-charge-succeeded-hook.md`](../04-charge-succeeded-hook.md)
**Repo:** `w/www`
**Date:** 2026-10-15
**Status:** 🟡 Complete for the hook and its call sites; the open provider gaps are listed below. Nothing is pushed.

## What shipped

* `ChargeSucceeded::handle()` (`app/Externals/DirectPayments/ChargeSucceeded.php`): skips invoices with no Direct Payment, claims each charge once in the cache, queues `SendDirectPaymentMail` with the `mail_delay_seconds` delay (`config/direct_payments.php`, 30), never throws.
* `SendDirectPaymentMail` (`app/Jobs`): the seam; it loads the invoice and sends nothing until plan 07.
* Call sites: Stripe `charge.succeeded`, Square `payment.updated` (COMPLETED), PayPal webhook, PayPal capture call. Stripe's invoice event records but does not fire.
* Fixes the hook needed: Stripe's charge event completes an invoice the invoice event recorded first (adds card brand and last4, fires); Square's completed payment is routed to the recorder; PayPal's invoice lookup matches `custom_id` to `reference_num`; Stripe's invoice event reads the reference from `subscription_details.metadata`.

## Deviation from the plan text

* The once-per-charge claim in the cache is new; the plan relied on webhook early returns, which miss the case where the PayPal webhook arrives before the capture call saves.

## How it was checked

* `tests/Feature/DirectPayments/ChargeSucceededHookTest.php`, 7 tests through the real signed webhook route (Stripe, Square) and the PayPal recorder, queue faked; removing the Stripe or Square fix makes them fail. Direct Payments + Processor + DirectPayments unit tests: 116 of 116 on a throwaway local MySQL 8.0.36.

## Not verified

* `capturePayPalOrder()` (real PayPal API call); a real delivery from each provider's sandbox.

## Residue

* Gaps 5, 6, 8, 9, 10, 13, 14 of the plan are not fixed (listed in the plan's last section).
* The mail itself is plan 07; the worker that runs the delayed job is plan 08.
