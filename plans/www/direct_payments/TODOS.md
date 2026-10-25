# TODOs — Direct Payments: every charge goes through an invoice of catalogued items (2026-10-09)

Raised by the owner while reviewing how the domain purchase flow (`plans/domains_deploy`, D29) would charge a member. Everything below was read in the code on 2026-10-09; nothing in
this file has been built.

## The rule

1. **Every charge belongs to an invoice, and every invoice line is a Direct Payment item** (`laravel_direct_payment_items`, or a tier for subscriptions). The invoice is what is
   reviewed, refunded and reported on, so a charge with no invoice, or an invoice line that is not in the catalogue, is a hole in all of those.
2. **The price is decided by the server, from the catalogue.** A fixed item costs its catalogue price plus tax. A dynamic item costs the amount the caller supplies, **and the server
   checks that amount against the item's minimum and maximum**. The browser's total is never believed.
3. **"Paid" is decided only by the provider.** The status of an invoice moves to paid because a provider-authenticated event (webhook) or a server-side read of the provider says so,
   never because a caller said so.
4. **The provider knows nothing about our system.** It receives an amount, a currency, a customer and a payment method, and charges. No invoice reference, no Direct Payment name,
   no internal identifier goes to it. We find our invoice from the provider's own identifier, which we store ourselves when we create the intent.
5. **The SDK runs anywhere, with nobody signed in, so there is no caller authentication to rely on.** The protection is rules 2 and 3: the server treats every browser request as
   *a customer proposing a cart*, computes the truth itself, and takes money status only from the provider. Fraud costs the attacker the price, not us.

## What the code does today (verified)

All paths are relative to `w/www`.

| # | Finding | Where |
|---|---|---|
| F1 | **An intent (and a hosted-checkout session, and a wallet order) can be created with no invoice at all.** The request names a processor and an `amount`; the amount-versus-invoice check only runs *when the request names an invoice that already exists*. The SDK saves its invoice **after** it creates the intent, so on the normal first charge the check never fires | `Http/Controllers/Processor/ProcessorIntentController.php:115` (`createIntent`, check at `:178`), `:393` (`createSession`), `:471` (`createPayPalOrder`); SDK `resources/js/external/api/direct_payments/api.ts:2037`–`2069` (`createPayment` then `saveInvoice`) |
| F2 | **The invoice is written by the browser and the server believes it.** `saveInvoice` is an `updateOrCreate` keyed by the reference the caller chose, taking `total_amount`, `items_data`, `status`, `event_identifier`, `processor_reference_num` and `provider` from the request | `Http/Controllers/DirectPayments/DirectPaymentsController.php:34` |
| F3 | **Nothing checks an invoice line against the catalogue.** No code compares `items_data` to `laravel_direct_payment_items`; a DYNAMIC item's `min_amount` / `max_amount` are enforced only in the browser (`api.ts:200`–`212`) | `grep DYNAMIC app/` shows the controller's item CRUD and the appointments / events / ecommerce services only |
| F4 | **A caller can set any status.** `POST …/invoice` takes `status`, and `POST …/invoice/update-status` sets any string with no check. The SDK's own `charge()` calls it with `paid` after a charge | `DirectPaymentsController.php:141` (`updateInvoiceStatus`); `api.ts:1298`, `:2276`, `:2283` |
| F5 | **"Paid" is read from that row.** `rowSaysPaid()` returns true on a status of `paid` or `succeeded` without asking the provider | `app/Externals/DirectPayments/DirectPaymentGateway.php:124` |
| F6 | **A free "paid" invoice can be made from nothing.** `completeZeroInvoice` completes any invoice whose *stored* amount is zero; the stored amount is the one the browser wrote | `DirectPaymentsController.php:165`–`190` |
| F7 | **The webhook makes the charged amount the invoice amount, and invents an invoice when it finds none.** `recordStripeCharge` (and the Square and PayPal equivalents) overwrite `amount_cents` with what the provider charged and never compare it with the invoice's lines; with no invoice found, it creates one with no items. So a charge made through F1 produces a believable paid invoice | `Http/Controllers/Processor/ProcessorWebhookController.php:443` (`:493` invents, `:503` overwrites) |
| F8 | **Internal identifiers are sent to the provider.** The SDK sends `metadata.invoice_reference_num` and any caller metadata; `createIntent` forwards all metadata, and the description is the Direct Payment's name. The webhook then looks the invoice up *by that metadata* first | `api.ts:2050`–`2053`; `ProcessorIntentController.php:266`–`270`; `ProcessorWebhookController.php:479` |
| F9 | **The caller chooses the processor.** The intent request names `processor_reference_num` itself, and the SDK's `charge()` honours a `processor_override` | `ProcessorIntentController.php:118`; `api.ts:1265` |
| F10 | **`loadPreparedInvoice()` / `Invoice.fromPrepared()` build a locked invoice from data the caller supplies, skipping the catalogue** — written for the marketplace case, where a trusted server prepared it, but nothing makes the data trusted | `api.ts:106`, `:750` |
| F11 | The parts that are already right and should be kept: the webhooks are signature-checked per provider; `getPaymentIntentStatus` reads the provider directly and is the one trustworthy status; `DirectPaymentGateway::confirmWithProcessor` and `snapshot()` exist; `charge()` already saves the invoice before charging | `ProcessorIntentController.php:337`; `DirectPaymentGateway.php:129` |

## Phase P0 — the server owns the invoice

**P0.1 Create the invoice first, on the server.** Add (or turn `saveInvoice` into) an invoice-creation call that takes the Direct Payment reference, the lines
(`item_ref`, `quantity`, `amount` for a dynamic item), the customer e-mail and, optionally, the payer. The server:
- loads the Direct Payment and its catalogue; refuses a line whose item is not in that Direct Payment (and refuses an inactive or deleted item);
- prices every line itself: fixed → catalogue price + tax; dynamic → the supplied amount, rejected when below `min_amount` or above `max_amount`; tier → the tier's amount;
- computes the total, stores `amount_cents`, `currency`, and a snapshot of each line (name, sku, unit price, tax, quantity, whether it was dynamic) so a later catalogue edit cannot
  change what was sold;
- ignores whatever `total_amount`, `status`, `event_identifier`, `charge_id`, `processor_reference_num` or `provider` the request carries — the processor and provider come from
  the Direct Payment's current binding;
- returns the invoice (reference, lines, total) as the single source the SDK displays.

**P0.2 No charge without an invoice.** The intent, hosted-session and wallet-order endpoints (and the Square equivalent) take **an invoice reference** and nothing about money from the
caller: the server reads the amount and currency from the invoice, picks the processor from the invoice, and writes a generic description. A request without a known, unpaid invoice is
refused. Remove the `amount`, `description`, `processor_reference_num` and `metadata` inputs from the public contract (keep `email`, `customer_id`, `setup_future_usage`).

**P0.3 The server records the provider's identifier on the invoice** when it creates the intent / order / session (this already happens for the intent at `:297`–`:308`; make it
universal), and the webhook finds the invoice by that identifier alone.

**P0.4 Status is not writable by a caller.** `saveInvoice`/create ignores `status`; `updateInvoiceStatus` is removed from the public routes (or restricted to non-money states such as
`cancelled`); the SDK's `charge()` stops calling it. `completeZeroInvoice` recomputes the total from the catalogue lines and completes only when that is zero. `rowSaysPaid()` is kept
for rows the webhook wrote, because with the above only the webhook and the verified server read can write them.

**P0.5 Provider-bound data is minimal (rule 4).** Nothing internal in metadata; a neutral description (or none); the webhook never reads `metadata.invoice_reference_num` for lookup.
Check what the subscription, setup-intent and Connect paths send too.

## Phase P1 — the webhook becomes a check, not a recorder

**P1.1 Match, compare, then record.** `recordStripeCharge` (and the Square and PayPal recorders) finds the invoice by the provider identifier. When the charged amount or currency differs
from the invoice, the invoice is marked `amount_mismatch` for review and is **not** reported as paid; the charged amount is stored beside the invoice amount, not over it.

**P1.2 No invented invoices.** A provider charge that matches no invoice is stored as an unmatched charge for staff (it still must be refundable), not turned into a paid invoice with no
items.

**P1.3 Legacy rows.** Invoices written before this change have client-supplied lines. Decide how they are shown and refunded (keep them readable; mark them `legacy` rather than
re-pricing them).

## Phase P2 — SDK, samples and reference

**P2.1 SDK (`resources/js/external/api/direct_payments/api.ts`, `portal.d.ts`).** `Invoice` is created on the server and returns server totals; `addItem` stays as the browser's
convenience for building the request but is advisory; `setupSinglePayPayment` creates the invoice **before** the intent and passes only the invoice reference; remove
`processor_override`; `loadPreparedInvoice` becomes "load invoice by reference from the server" (the marketplace case keeps working because its trusted server creates the invoice through
P0.1 and the browser loads it by reference). House rules for new TypeScript: no optional chaining, no `any`; `directPayments.create()` typed.

**P2.2 Samples (`public/playground/externals/direct-payments/`).** Update `single_charge`, `subscription`, `marketplace_split`, `connect_setup`, `dashbaord` and everything in `server/`
(`checkout-and-post.js`, `validate-order.php`, `show-purchase.php`, `reconcile-sweep.php`, the reference gateway classes) so each builds the invoice first and shows the dynamic-price case.

**P2.3 Reference.** Update `resources/js/external/api/direct_payments/README.md` with the invoice-first contract and a **dynamic pricing** section (below), and the server README's
"round trip" diagram.

**P2.4 Tests.** A charge request with no invoice is refused; a line outside the catalogue is refused; a dynamic amount below minimum / above maximum is refused by the **server**; a forged
total is ignored; a forged `paid` status changes nothing; a zero invoice with priced lines is not completable; a charge for a different amount than the invoice is flagged and not
reported as paid; nothing internal appears in the request sent to the provider (fake the provider and assert the payload).

## Reference — how dynamic pricing works (to document in P2.3)

- A **dynamic item** is a catalogue item with `price_type = DYNAMIC`. Its catalogue `price` is stored as 0; it has an optional `min_amount` and `max_amount`
  (`DirectPaymentsController::storeItem` / `updateItem`, `:602`–`:636`, `:720`–`:755`).
- The caller supplies the amount for the line when the item is added: `addItem({ item_ref, quantity, amount })` (`api.ts:166`). The SDK checks the bounds in the browser.
- **The server must check the same bounds and must store the amount it accepted on the invoice line** (P0.1). Today it does neither.
- Selling modules that map a service to a dynamic item recompute the expected amount themselves and compare it with the paid invoice (the appointments service does this,
  `Appointments/Services/AppointmentPaymentService.php:54`–`:64`). That check is a good second line, not a replacement for P0.1.
- Bounds are optional: an item with no `max_amount` accepts any amount the caller names; a selling module that wants a ceiling must set one.

## Consumers to change when this lands

- **`plans/domains_deploy` (D29).** Already on the Direct Payments API as the samples use it (`complete/00_extras.md` E11): the members app builds the invoice with the SDK on its dynamic purchase item and the Brain only verifies it. Nothing to change there when P0 lands, except that the server will then also check the dynamic amount against the item's bounds.
- **Events, appointments, ecommerce, market** write their own invoices today; each needs checking against P0.1 once it exists.

## Open questions for the owner

1. **Legacy invoices (P1.3):** keep as they are, marked legacy — or is there a migration you want?
2. **Dynamic item bounds:** should creating a dynamic item require a maximum, so an unbounded dynamic line cannot exist by accident?
3. **Unmatched provider charges (P1.2):** where should staff see them — the Money ledger?
4. **Descriptions on the provider's statement:** a neutral fixed description per Direct Payment (set in its admin), or none?
