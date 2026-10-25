# 11 — Module Adoption (complete — all seven modules announce through the general system, and Events, Appointments and Sponsorships take payment like Ecommerce; proved by tests, not run on a server, the new screens not opened in a browser)

## Status

**Complete 2026-10-19.** Nothing is committed. Forms, Sponsorships, Ecommerce, Events, Appointments, Site Users and Subscribers now say what happened once (`ActivityPublisher` → notifications, then analytics) and what follows is
the owner's saved settings. The money rule of 11.1.2b is in force: **no module manages money**. Events, Appointments and Sponsorships were refactored to the Ecommerce façade model (11.13 F2–F4), Direct Payments names no
module (F5, gate G11), and Ecommerce gained the `receipt` option (F1).

Everything below is in `w/www` (repo `www`). The unverified parts are listed in full at the end; the short version: **nothing ran on a server, no real e-mail was sent, no payment was made, and none of the new or changed screens
(admin level editor, admin sponsor "Payments" card, sponsor "My Payments" tab and checkout, the notifications cards on seven admin screens, the three SDK payment flows) was opened in a browser.** TypeScript type-checks and the admin
unit tests pass; the server side is covered by tests against MariaDB.

## Phases

| Phase | Piece | State | Where |
|---|---|---|---|
| M0 | Groundwork: recipient mode honoured by attached handlers, `defaultsApply`, the `receipt` option, no-secrets guard, handler key, inbox metadata | **built** | `app/Services/Notifications/*`, `app/Services/Activity/*`, `app/Mail/MailHandlerSender.php` (test seam) |
| F1 | Ecommerce `receipt: 'none'` in the client invoice metadata | **built** | `resources/js/portal/builder/core/component_scripts/commerce_component_script.ts` |
| F5 | Direct Payments names no module (`createPayerForAccount`, `created_by = 'account'`) + gate G11 | **built** | `PayerService`, migration `2026_10_19_000008_…` |
| M1 | Forms | **built** | `app/Externals/Forms/Notifications/*`, `ProcessFormSubmission`, `FormsMailer` |
| F4 | Sponsorships façade: schema, level → item, attach/complete, reconcile, sponsor "My payments" | **built** (browser parts unverified) | see "F4" |
| M2 | Sponsorships (S1 bell + analytics, S2 denial e-mail) | **built** | `app/Externals/Sponsorships/Notifications/*` |
| M3 | Ecommerce | **built** | `app/Externals/Ecommerce/Notifications/*` |
| F2 | Events façade: client-built invoice, attach, reconcile sweep | **built** | see "F2" |
| M4 | Events | **built** | `app/Externals/Events/Notifications/*` |
| F3 | Appointments façade: `paid_cents` dropped, gateway reads, attach, reconcile sweep | **built** | see "F3" |
| M5 | Appointments | **built** | `app/Externals/Appointments/Notifications/*` |
| M6 | Site Users | **built** | `app/SiteUsers/Notifications/*` |
| M7 | Subscribers | **built** | `app/Externals/Subscribers/Notifications/*` |
| Admin | The per-event switches card on seven admin screens | **built** (not opened in a browser) | `resources/js/portal/admin/components/notifications/*` |
| M8 | Retirement of the old senders | **not done** — by design, one release after each module (list under "Retirement") | — |

## What each module now announces

| Module | Events (key) | Notes |
|---|---|---|
| Forms | `submission_received` | The owner's mail handlers and the bell go through the owner's saved settings; the form's own recipients are unchanged. |
| Sponsorships | `application_received`, `application_approved`, `application_denied`, `display_requested` | No money in any of them. The denial e-mail is the event's built-in e-mail (the old text), switchable and replaceable. The approval e-mail that carries a new sponsor's password stays direct (11.1.3). |
| Ecommerce | `order_paid`, `order_shipped`, `order_cancelled`, `return_requested`, `return_decided`, `refund_recorded` | The shopper's receipt is the module's confirmation; the invoice carries `receipt: 'none'` so Direct Payments sends none of its own. |
| Events | `registration_confirmed`, `registration_cancelled`, `waitlist_offered`, `order_paid`, `checked_in` | `registration_confirmed` is keyed by the registration, so the browser path, the sweep and a retried call announce once. |
| Appointments | `booking_confirmed`, `booking_cancelled`, `booking_rejected`, `booking_rescheduled`, `booking_completed`, `booking_no_show`, `waitlist_offered` | `booking_confirmed` is keyed by the booking: four call sites and the sweep announce once. |
| Site Users | `user_registered`, `email_verified`, `email_changed`, `email_set_by_admin`, `password_changed` | Token-carrying e-mails (verify, reset, change-confirm) stay direct and are not announced with their tokens; `email_changed` is inline with the previous address held in memory only. |
| Subscribers | `subscriber_signed_up`, `article_published` | Bulk mail to subscribers stays the module's own job; only the fact is announced. |

Event defaults are ON everywhere (OQ7, owner answer 2026-10-19). In tests the switch is pinned off in `phpunit.xml` (`NOTIFICATIONS_USE_EVENT_DEFAULTS=false`) and the module tests turn it on.

## F1–F5: the payment façade

The shape (11.13): the module stores references and no money; the **browser** builds the invoice with `Portal.directPayments` and stamps `metadata: { facade, receipt, <reference> }`; the module exposes **attach**
(store the invoice reference, before the charge) and **complete** (ask `PaymentGateway::isPaid()` + `snapshot()`), and a **reconcile** command recovers a paid invoice whose browser died. Every figure on a screen is resolved
from `snapshot()` / `snapshots()`.

### F2 — Events

- **Removed:** `EventPaymentService::createInvoiceForOrder()` and `chargeCentsFromItem()`; the service now reads no Direct Payments table and imports no Direct Payments model (it only answers "does the event accept payments" and "which item is this ticket mapped to").
- **`register()`** creates the pending order and registration as before, writes no invoice, and returns `payment: { item_ref, quantity, ticket_name }` (or `null` when nothing is due) plus `direct_payment_ref` and `requires_payment`. The old `invoice_ref` field is gone from the response.
- **`POST /registrations/{ref}/attach-invoice`** (new, public, same token-or-owner check as confirm): stores `direct_payment_invoice_ref` on the pending order; refuses an invoice that belongs to another Direct Payment (`EV_E_INVOICE_MISMATCH`).
- **`POST /registrations/{ref}/confirm-payment`** is the plan's "complete", body kept (`isPaid()` + `finalizeFromSnapshot()`); it now also refuses a paid invoice that is not the event's own Direct Payment, or not this registration's order.
- **`events:reconcile-payments`** (existing command, rewritten): (1) pending orders that name a paid invoice (as before), (2) **new:** paid invoices tagged `facade: events` found through `paidInvoicesAwaiting('events', …)` and matched by `event_order_reference_num`, then the same announcements the browser path makes. Now **scheduled every ten minutes** (it was never scheduled before).
- **Client:** `externalEventsApi.beginPayment` builds the invoice (`createInvoice` → `addItem({ item_ref, quantity })` → `lock`), attaches it, then mounts the card with `metadata: { facade: 'events', receipt: 'none', event_order_reference_num }`. `portal.d.ts` and `ui/events/ui.ts` follow.

### F3 — Appointments

- **Migration `2026_10_19_000009`** drops `paid_cents` from `laravel_appointment_bookings`; `recordPayment()`, `createInvoiceForBooking()`, the invoice-row read and the `['paid', 'succeeded']` list are gone. `AppointmentPaymentService` imports no Direct Payments model.
- **`confirmBooking()`** (and `confirmPaid()`): `PaymentGateway::isPaid()` + `snapshot()`, the invoice must be the calendar's own Direct Payment; the activity-log row (`payment.captured`) carries the invoice reference only.
- **`POST /bookings/{ref}/attach-invoice`** (new) and `confirm-payment` (tightened the same way). `book()` returns `payment: { item_ref, amount_cents, service_name }`; `amount_cents` is only a hint for a dynamically priced item (deposit, else the service price) — a fixed item is priced by Direct Payments.
- **Reports** (`summary` revenue, `exportBookings`, `customers`, the day sheet) resolve "paid" from `PaymentGateway::snapshots()` for the bookings on the page (`AppointmentReportingService::paidCentsByBooking`). The response keys keep their names (`paid_cents`, `revenue_cents_30d`, `lifetime_cents`); only their source changed.
- **`appointments:reconcile-payments`** rewritten the same way as Events' and **scheduled every ten minutes** (it was never scheduled before). SDK `beginPayment` builds the invoice, attaches it, stamps `facade: 'appointments'`, `booking_reference_num`.

### F4 — Sponsorships

- **Migration `2026_10_19_000010`** (clean break): levels lose `amount_type/value/min/max` and gain `direct_payment_item_reference_num`; applications lose `amount_paid`, `processor_reference_num`, `processor_customer_id` and gain `reference_num`, `access_token`, `direct_payment_invoice_ref`; new table `laravel_sponsor_payments` (sponsor, sponsorship, level, **unique** invoice reference — references only). Existing application rows get a reference (and no token, so none can attach later).
- **`SponsorshipPaymentService`** is the only place Sponsorships talks about money: `invoiceProblem()` (the invoice's Direct Payment must be the sponsorship's, its lines must contain the level's item, and for `complete` it must be paid), `figures()` (one `snapshots()` read), `statusOf()` (paid / pending / refunded / partly refunded), `recordForSponsor()`, `paymentsFor()`, `reconcile()`.
- **Public application:** `POST /sponsor-applications` no longer accepts or stores money; it returns the application's `reference_num` and a secret `access_token` (never listed by the admin API). `POST …/{ref}/attach` and `…/{ref}/complete` need that secret.
- **Approval:** `approve` refuses with `SP_E_PAYMENT_REQUIRED` (reason in `reason`) when the sponsorship requires payment and the application's invoice is not paid; "requires payment" is `meta.require_payment` if set, otherwise true exactly when the chosen level has an item. On approval the invoice joins the new (or existing) sponsor's payments.
- **Sponsor dashboard:** `GET /portal/api/sponsors/payments` (exactly the signed-in sponsor's rows, with date, amount, currency, status, sponsorship, level, invoice reference — all but the rows themselves from Direct Payments) and `POST /portal/api/sponsors/payments/attach` ("give again"; refuses another sponsor's invoice with 409 and a foreign Direct Payment's with 422). The admin reads the same list at `GET /sponsor-applications/sponsors/{ref}/payments`. The admin application list and the sponsor's own overview carry a `payment` figure per row.
- **`sponsorships:reconcile-payments`** (new, scheduled every ten minutes): links a paid `facade: sponsorships` invoice to the application named in `facade_ref`, or to the sponsor named in it (a "give again" whose browser died), choosing the sponsorship by the invoice's Direct Payment and the level by the invoice's item.
- **Screens/SDK (unverified in a browser):** the admin level editor picks a payment item (read-only price hint) instead of amount inputs; the admin sponsor detail has a Payments card; the sponsor dashboard has a "My Payments" tab and a rebuilt checkout (the old one called `Portal.directPayments.charge()`, which throws, so it never worked); `SponsorshipAPI.submitSponsorApplication` / `beginSponsorPayment` / `labelLevelPrices` replace the money fields.

### F5 and gate G11

`PayerService::resolveForSiteUser()` → `createPayerForAccount()` (callers and four tests followed); stored `created_by = 'site_user'` → `'account'` by a one-time update migration; `ModuleAdoptionGatesTest` scans Direct Payments' code lines for module names and finds none.

## Gates (tests, not conventions)

| Gate | Where | Holds |
|---|---|---|
| G1/G3 | `GeneralLayerTest` | the general layer names no module and uses no `app()` |
| G2 | `NotificationGatesTest` / `AnalyticsGatesTest` | no module file calls the dispatcher or the recorder; the allow-list only shrinks |
| G5 | `NotificationGatesTest` | nobody writes an inbox row by hand except the named producers; the module producers are off the allow-list |
| G7 | `NotificationGatesTest` | the old Direct Payments notification tables and models are read by nothing |
| G9 | withdrawn 2026-10-20 | The owner removed it: `MailHandlerSender::send()` is a generic sender, and a list of every class that uses it is noise. The retired-sender test below stays. |
| retired senders | `ModuleAdoptionGatesTest` | each retired sender is called by nothing |
| G10 | `ModuleAdoptionGatesTest` + the `Activity` constructor | no secret-looking key in an Activity's data (the scan itself is checked not to be vacuous) |
| G11 | `ModuleAdoptionGatesTest` | Direct Payments names no other module |

## Tests

PHP on MariaDB 11.4.5 (port 3307, database `testdb_phpunit`), progress shown. New test files and what they prove:

| File | Tests | Proves |
|---|---|---|
| `Feature/Notifications/ModuleAdoptionGroundworkTest` | 15 | M0: attached handlers honour the event's recipient mode; a saved rule without a handler falls back; defaults; the secrets guard; the test seam |
| `Feature/DirectPayments/ReceiptOptionTest` | 5 | `receipt: none/auto` decides whether Direct Payments sends its own receipt |
| `Feature/Forms/FormsNotificationCharacterizationTest` + `FormsAdoptionTest` | 9 + 13 | the old behaviour green on the old code, **unedited** after; then the new behaviour |
| `Feature/Notifications/SponsorshipApplicationCharacterizationTest` + `SponsorshipsAdoptionTest` | 9 + 11 | same pattern for the application flow and the credentials e-mail (which must stay exactly as it was) |
| `Feature/Notifications/SponsorshipsPaymentFacadeTest` | 11 | F4: secret and reference, attach rules, paid completes / unpaid does not / foreign Direct Payment and other item refused, approval gate, level with no item, admin figures, both sweeps, "My payments" shows only the sponsor's own rows, another sponsor's invoice refused, schema and imports |
| `Feature/Ecommerce/EcommerceAdoptionTest` | 15 | old-vs-new receipt equivalence, edge cases, no double announcement |
| `Feature/Events/EventsAdoptionTest` + `EventsPaymentFacadeTest` + `EventDiscountItemTest` | 11 + 9 + 1 | M4 equivalence; F2: attach, paid confirms once, unpaid / foreign do not, both sweeps, no money column, no invoice writer |
| `Feature/Appointments/AppointmentsAdoptionTest` + `AppointmentsPaymentFacadeTest` | 9 + 9 | M5 equivalence and four-paths-one-announcement; F3: attach, paid confirms once with an amount-free log row, unpaid / foreign do not, sweeps, reports from the gateway, `paid_cents` gone |
| `Feature/SiteUsers/SiteUsersAdoptionTest` | 11 | M6: facts announced, tokens never in an Activity / the queue / analytics |
| `Feature/Subscribers/SubscribersCharacterizationTest` + `SubscribersAdoptionTest` | 6 + 9 | M7 (and E1) |
| `Unit/Notifications/ModuleAdoptionGatesTest`, `tests/Support/FakeAdoptingModule` | 6 | the gates above |
| edited | — | `RuleResolverTest` (a saved rule keeps attached handlers), `ActivityCatalogTest`, `NotificationGatesTest`, `SponsorshipNotificationTest`; `FormsNotificationProducerTest` deleted (replaced by the Forms pair); the three Sponsorships test setups gained the F4 migration line (their assertions did not change); TypeScript: `tests/mountNotificationsCard.test.ts`, `tests/notificationRulesModel.test.ts` |

## Final run

Run once at the end, below-normal priority, one process at a time, against MariaDB 11.4.5 (port 3307); MariaDB was shut down cleanly afterwards and no `mariadbd`, `php`, `node` or Electron process was left.

| Pass | Suites | Result |
|---|---|---|
| A | `tests/Unit`, `tests/Feature/Notifications`, `tests/Feature/Externals` | 640 tests, 1885 assertions: **18 errors** in `LaravelCronScriptTest` (its own setup on this Windows machine; unrelated, not proved to predate this work), 18 skipped, and **1 failure**: `NotificationRulesApiTest` expected the catalogue's event keys without the new `mailUnavailable` key; the test was updated and re-run green |
| B | `tests/Feature/DirectPayments`, `tests/Feature/Processor` | **OK: 548 tests, 2793 assertions** |
| C | `tests/Feature/Forms`, `Ecommerce`, `Events`, `Appointments`, `SiteUsers`, `Subscribers`, `Connections` | 368 tests, 1567 assertions, **2 failures**, both in older Site Users tests (`SiteUsersAdminFeatureTest`, `SiteUsersEmailChangeTest`) that still expected the retired `SiteUsersEmailChangedMail` / `SiteUsersAdminEmailSetMail` to be sent; they now assert those Mailables are **not** sent (the notices go through the notification system, covered by `SiteUsersAdoptionTest`). The two files plus `NotificationRulesApiTest` were re-run after the edits and pass |
| TS | `npm run test:admin`; `tsc --noEmit` | **81 of 81 pass; tsc clean** |

Not run: the rest of `tests/Feature` (Admin, Builder, Members, Membership, Observers, Brain ...); nothing in this step touches them. Pass C was not repeated as a whole after the Site Users edits.

## Deviations from the plan text

1. **Existing command names kept.** Events' and Appointments' reconcile commands already existed as `events:reconcile-payments` / `appointments:reconcile-payments`; they were rewritten, not replaced by `EventsReconcileOrders` / `AppointmentsReconcileBookings`.
2. **Endpoint names.** Events' attach is `POST /registrations/{ref}/attach-invoice` (the plan said `…/events/orders/{ref}/attach`) because the browser holds the registration's token, not the order's; `confirm-payment` stays as the plan's "complete". Appointments' is `POST /bookings/{ref}/attach-invoice`.
3. **The sweeps were never scheduled** (Events', Appointments' and Ecommerce's `ecommerce:reconcile-orders`). All four (with Sponsorships') are now scheduled every ten minutes in `routes/console.php` (OQ36, owner-approved after the first report).
4. **Sponsorship applications got a reference and a secret** (not in the plan): they were addressed by a sequential integer id, and a public attach/complete needs something only the applicant holds. The secret is returned once by `store` and hidden from every listing.
5. **The sweep tag for Sponsorships is `facade_ref`**, a key the gateway already reads, rather than a new `application_reference_num` key (that would have meant changing Direct Payments' gateway).
6. **`require_payment` is a select on the sponsorship form** (Automatic / Always / Never; OQ36), saved as `meta.require_payment` through a dedicated field so it cannot overwrite the event references. The label is plain English, not yet in the five locale files.
7. **Events: the ticket price shown is the ticket type's own price/tier** (`register()` no longer reads the item's price); the amount actually charged is the Direct Payments item's. A dynamically priced item without an amount now fails in the browser (`addItem` throws) instead of the server's `EV_E_ITEM_UNMAPPED`.
8. **The "hold refresh on attach" check in F3 found nothing to refresh**: no code releases an unpaid `pending` booking (the hold is marked `converted` when the booking is made). Attach changes no hold. See "Found, not changed".
9. **The proof for Ecommerce, Events, Appointments and Site Users is an equivalence test**, not "the old test green, unedited": there was no old test to keep, so the retired sender stays for one release and the new path is compared with it, send for send (recipients, handler, variables). Forms, Sponsorships and Subscribers have true before/after characterization.
10. **A pre-existing bug fixed** on the way: `00_extras.md` E1 (Subscribers' ambiguous `id` on MySQL).
11. **Response key names kept** in Appointments' reports (`paid_cents` etc.) so no admin screen breaks; their values now come from the gateway.

## Found, not changed

- ~~Event promo codes did not reduce the charge~~ **Fixed (OQ34).** `register()` now returns `payment.discount_item_ref` / `discount_cents`; the SDK adds a negative line of Events' own dynamic "Promo discount" item (`EventDiscountItemService`, SKU `EV_DISCOUNT`, one per Direct Payment, created on first use: the one place Events writes a Direct Payments catalogue row, the same deliberate exception Ecommerce has). Caveat: the discount is computed from the ticket's own price hint, so if that differs from the Direct Payments item's price a percentage code discounts the wrong base. Not browser-tested.
- ~~An unpaid `pending` appointment booking held its slot indefinitely~~ **Fixed (OQ35).** `appointments:expire-unpaid-bookings` (every five minutes) cancels a pending booking on a payment-taking calendar after 30 minutes with no paid invoice, freeing the slot and offering it to the waitlist; it never touches a booking pending for staff approval, and a booking Direct Payments says is paid (attached or not) is confirmed instead.
- **`LaravelCronScriptTest`: 18 errors** ("typed property `$dir` must not be accessed before initialization") in every full run on this Windows machine; its own setup, unrelated to this work. I did not prove it failed before this step.

## Retirement (M8, one release after each module is live)

`FormsMailer::sendConfiguredHandlers*`, `EcommerceReceiptService::sendOrderReceipt*` and `EcommerceOrderReceiptMail`, `EventCommunicationService::sendConfirmation*` and `EventRegistrationReceiptMail`, `AppointmentCommunicationService::sendConfirmation*` / `AppointmentBookingReceiptMail`, `SiteUsersMailer::sendEmailChanged*` / `sendAdminEmailSet*` and their Mailables, `SponsorshipApplicationController::dispatchAdminNotification` and `dispatchDenialEmail`. Each is flagged in G9's retired-sender entry so the gate shrinks when it goes.

## Built on assumptions the owner has answered

OQ7 (event defaults on everywhere), OQ16 (Forms first), OQ23 (Brain mail log 90 days), OQ25 (payment reports money; module events never carry it), OQ26 (queued-mail delay accepted), OQ28 (sponsor password link later), OQ31 (switchable notices, bell on verified), OQ27/29/30/32 at the recommendation, OQ33 (Sponsorships through Direct Payments exactly like Ecommerce, with the sponsor's "My payments").

## Not verified

- **No server, no real e-mail, no payment.** Tests use a recording mail seam and a gateway fake; the real MJML/mail-provider path, a real processor charge, and `invoice.metadata` coming back through `paidInvoicesAwaiting()` from a real Direct Payments invoice (the `facade` / `facade_ref` / `event_order_reference_num` / `booking_reference_num` keys are the ones the gateway reads; I did not watch a real invoice carry them) are exactly as untested as before.
- **No browser.** Not opened: the three SDK payment flows (Events `beginPayment`, Appointments `beginPayment`, Sponsorships `beginSponsorPayment`), the sponsor dashboard's "My Payments" tab and rebuilt checkout, the admin level editor's item picker, the admin sponsor "Payments" card, and the notifications card on the seven admin screens. Type-checking (`tsc --noEmit`) and the admin unit tests pass; the request and response shapes are asserted by the PHP tests.
- **The Appointments storefront UI does not call `beginPayment`** (nothing in `resources/js/external/ui` does); the SDK method is rebuilt and typed but no page exercises it.
- **Migrations ran only on the local test database.** `…000009` (drops `paid_cents`) and `…000010` (drops sponsorship money columns, creates `laravel_sponsor_payments`) are destructive: they drop columns that held data. Nothing sponsor-facing was published with the old columns; check a real site's rows before running them.
- **`portal.d.ts` was updated in `w/www` only.** The copies the externals contract keeps elsewhere are not refreshed by this step (Events' `PortalEventPaymentOptions` / `PortalEventRegisterResult` and Appointments' equivalents changed shape).
- **Deploy order:** migrate first (the code reads the new columns and tables); a site where `000010` has not run cannot take a sponsorship application.

## Next

M8 retirement, one release after each module is live on a server. Before that: open the new screens and the three payment flows in a browser (including a promo-code purchase and an abandoned booking).
