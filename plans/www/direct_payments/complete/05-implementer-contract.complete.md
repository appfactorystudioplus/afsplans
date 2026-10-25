# 05-implementer-contract — completion record

**Plan file:** [`../05-implementer-contract.md`](../05-implementer-contract.md)
**Rollout phase:** DP6
**Repo:** `w/www`
**Date:** 2026-09-13
**Status:** ✅ Backend shipped and applied to the local database. SDK/client work explicitly not
done — see Not implemented.

## What shipped

A code-grounded audit (via a research pass across `app/Externals/Ecommerce/`, `app/Events/`,
`app/Externals/Appointments/`, `app/Models/Externals/SiteUsers/`, and `resources/js/external/`) found the plan's
"BEFORE" framing did not match the actual codebase in three important ways, each of which
changed what DP6 actually needed to build:

1. **`payer_reference_num` existed nowhere outside Direct Payments' own core module.** Not a
   rename or a fold — pure net-new columns on all three consumers.
2. **The TypeScript SDK's `DirectPaymentsPayerResolver`/`useCustomerResolver()`/`adopt()`
   (§5.3) do not exist in this codebase at all**, in any form — not to be modified, nothing to
   remove. The `connections/07` plan that was meant to introduce them was never implemented in
   code.
3. **Ecommerce, events and appointments all build/finalize their Direct Payments invoice or
   order server-side**, using an email already in hand (`$cart->email`,
   `$order->purchaser_email`, `$booking->user_email`) — none of the three needed any client-side
   identity resolver to get a payer. This made DP6 achievable as pure backend work, mirroring
   exactly how `saveInvoice()` was wired in DP2.

### §5.4 — ecommerce, events, appointments

- `payer_reference_num` added: `laravel_ecommerce_orders` + `laravel_ecommerce_carts`,
  `laravel_event_orders` (not `_registrations`, per the plan's own table), `laravel_appointment_bookings`.
- Write-side wired at the point each module already knows the buyer's identity:
  `EcommerceOrderService::createFromCart()`, `EventOrderService::finalizeFromSnapshot()`,
  `AppointmentBookingService::book()`'s booking-create call. Each resolves a **signed-in**
  buyer's own linked payer first (via the new `SiteUserPayer` link, never re-derived by email),
  falling back to `PayerService::resolveForCheckout()` by email for a guest. All three
  best-effort — a payer-resolution failure never blocks an already-paid/confirmed write.
- `laravel_ecommerce_carts.payer_reference_num` exists but is **not written** — nothing in the
  cart-creation path resolves one, and nothing reads it yet. Stated explicitly in that migration's
  docblock as deferred, not forgotten.
- **Appointments' pre-existing structural asymmetry, found and left alone**: `laravel_appointment_bookings`
  never gained `direct_payment_reference_num`/`processor_reference_num` the way ecommerce/events
  did — money resolves through `direct_payment_invoice_ref` alone. Adding `payer_reference_num`
  doesn't depend on those two missing columns, so this pass didn't add them; fixing that asymmetry
  is a separate appointments architecture decision.

### §5.5 — Site Users

- `laravel_site_user_payers` created (one row per user, replacing the *concept* the per-processor
  `laravel_site_user_customer_links` table represents — that table is **not** touched, dropped,
  or migrated; it has three live consumers today and none were in DP6's scope).
- `website_reference_num` added to `laravel_site_user_modules` — a genuine prerequisite gap found
  during implementation, not anticipated by the plan: Site Users had **no notion of which website
  a module belongs to at all**. Nullable, unbackfilled; every payer-resolution path degrades to
  `payer_reference_num: null` for an unset module rather than erroring.
- `GET /me/payer` (`SiteUsersPublicController::myPayer()`) — resolves-and-links on first call,
  returns the same link on every call after, gated on the same `link_payment_customers` flag
  `POST /me/customers` already uses.
- `siteusers:link-customers` re-targeted from matching `laravel_processor_customers` (one
  candidate per processor) to `laravel_direct_payment_payers` (one candidate per person) — "one
  match instead of one per processor," per the plan's own framing. All three original rules
  (verified email only, unique on both sides, `linked_by = 'email_match'`) unchanged; one rule
  added (a user already linked to a payer is skipped, not reassigned — `SiteUserPayer` is
  one-per-user where the old table was one-per-processor-per-user).
- `PayerService::updateEmail()` added, reusing the existing `ProcessorCustomerGateway::updateCustomerEmail()`
  extracted in DP2 — updates the payer's own record, then every **active** payer_customer,
  best-effort per processor.

## Not implemented (at the time this document was written — see update below)

- **The TypeScript SDK payer-resolver interface (§5.3)** — deliberately not built. Nothing in the
  actual checkout flows needs it (see point 3 above); building a brand-new client SDK interface
  and wiring three separate checkout UIs (only one of which — ecommerce — even has a dedicated
  storefront component script) is a substantial, separate-domain undertaking warranting its own
  pass, not a "fold in" of something that already existed.
- **`PATCH /me/email` or any real email-change endpoint** — `site_users/13`'s email-change plan
  was never implemented in code (confirmed by the same audit: no email-change flow exists in Site
  Users at all). `PayerService::updateEmail()` exists and is tested, ready for that endpoint to
  call once it's built.

> **Update, 2026-09-14 (connections/07 K6, `site_users/13` K11):** both of the above are built
> now. The SDK resolver interface — `DirectPaymentsPayerResolver`, `useCustomerResolver()`,
> `savedPaymentMethods()` on `ExternalDirectPaymentsAPI`, `SiteUsersClient.paymentsResolver()` —
> ships as a real, standalone addition, though with no live checkout wired to it (a
> full-codebase check found no storefront that both calls the raw Direct Payments client
> directly and already carries a Site Users session — see
> `../../connections/complete/07-direct-payments-connection.complete.md`'s own "§7.3/§7.4"
> section for the exact reasoning, kept there rather than duplicated here since that document
> owns the resolver work). `PATCH /me/email` + `POST /email/change/confirm` now exist in full,
> and `updateEmail()` finally has the caller this document said it was "ready for" — see
> `../../site_users/complete/13-identity-and-email-change.complete.md`.
- **A dedicated `AppointmentBookingService` integration test** — the payer-resolution logic there
  is a near-verbatim copy of the already-tested ecommerce/events pattern, but constructing the
  full `book()` flow needs mocking 9 constructor dependencies (availability, capacity, holds,
  staff, activity, communications, payments, waitlist, `SiteUsersGateway`) for marginal
  additional confidence over the two already-proven cases. Verified by `php -l` and manual code
  review only.

## Files

**New:** `app/Models/Externals/SiteUsers/SiteUserPayer.php`,
`database/migrations/2026_09_13_000000_add_payer_reference_num_to_ecommerce_tables.php`,
`2026_09_13_000001_add_payer_reference_num_to_event_orders.php`,
`2026_09_13_000002_add_payer_reference_num_to_appointment_bookings.php`,
`2026_09_13_000003_create_site_user_payers_table.php`

**Modified:** `app/Externals/DirectPayments/PayerService.php` (`updateEmail()`),
`app/Externals/Ecommerce/Services/EcommerceOrderService.php`, `app/Models/Externals/EcommerceOrder.php`,
`app/Externals/Events/Services/EventOrderService.php`, `app/Models/Externals/EventOrder.php`,
`app/Externals/Appointments/Services/AppointmentBookingService.php`, `app/Models/Externals/AppointmentBooking.php`,
`app/Http/Controllers/Externals/SiteUsers/SiteUsersPublicController.php` (`myPayer()`),
`app/Console/Commands/SiteUsersLinkCustomers.php` (re-targeted),
`app/Models/Externals/SiteUsers/SiteUserCustomerLink.php` (pointer docblock only),
`app/Models/Externals/SiteUsers/SiteUserModule.php` (`$fillable` — see Residue),
`routes/auth1.php`, `routes/portal.php` (the `GET /me/payer` route),
`tests/Support/CreatesSiteUsersSchema.php` (shared fixture extended),
`tests/Unit/Events/EventOrderServiceTest.php` (constructor signature updated, pre-existing test)

**Tests (new):** `tests/Feature/DirectPayments/PayerServiceUpdateEmailTest.php`,
`tests/Feature/SiteUsers/SiteUsersMyPayerTest.php`, `SiteUsersLinkPayersReconcilerTest.php`,
`tests/Feature/Events/EventOrderPayerTest.php`, `tests/Feature/Ecommerce/EcommerceOrderPayerTest.php`

## Acceptance

| §5.7 criterion | Status | Evidence |
|---|---|---|
| `grep -rn "cus_\|provider_customer_id" app/Externals/Ecommerce app/Events app/Externals/Appointments` is empty | ✅ | Confirmed by the audit before writing any code — was already true; DP6 didn't need to remove anything |
| An order before and after a swap resolve to the same `payer_reference_num` | ✅ (no new code) | Falls out of `resolveForCheckout()`'s email-based reuse — a payer's identity is swap-independent by construction |
| A module's tables are not written by a swap | ✅ (unchanged) | `SwapService`/`BindingRegistry::swap()` never touch ecommerce/events/appointments tables |
| `Portal.directPayments` exposes no method returning a provider customer id | ⚠️ | **Not addressed** — `getStoredCustomerId()` still exists in `resources/js/external/api/direct_payments/api.ts`; its only real customer-id consumer is `resources/js/afs/create.ts`, which is `brain_membership_identity`'s scope, not DP6's. Ecommerce's own checkout script never called it in the first place |
| Site Users holds no provider identifier after the fold | ⚠️ | True for the **new** path (`SiteUserPayer` holds only `payer_reference_num`) — **not** true overall, since `laravel_site_user_customer_links` (raw `provider_customer_id`) is deliberately untouched, still has 3 live consumers |
| A guest checkout with an email produces a payer, reused on a second checkout | ✅ | `EcommerceOrderPayerTest::test_two_guest_carts_with_the_same_email_share_one_payer` |
| A signed-in checkout never adopts a guest payer, even on an email match | ✅ | `EcommerceOrderPayerTest::test_a_signed_in_shoppers_own_linked_payer_wins_over_email_matching`, same pattern in `EventOrderPayerTest` |
| A cart begun as guest, finished signed-in, produces one consistent payer (D11) | ❌ | Not addressed — the mid-checkout sign-in reassignment (`reassignCartPayer()`, §5.2) needs the cart-cookie/session machinery `connections/12`'s guest-continuity plan was meant to provide, never implemented in code |

## Verification

```bash
cd w/www
php artisan test tests/Feature/DirectPayments/PayerServiceUpdateEmailTest.php tests/Feature/SiteUsers/SiteUsersMyPayerTest.php tests/Feature/SiteUsers/SiteUsersLinkPayersReconcilerTest.php tests/Feature/Events/EventOrderPayerTest.php tests/Feature/Ecommerce/EcommerceOrderPayerTest.php
php artisan test tests/Feature/SiteUsers/SiteUsersCustomerLinkTest.php  # regression check on the untouched sibling endpoint
php artisan test tests/Unit/Events/EventOrderServiceTest.php            # regression check on the pre-existing test this pass had to update
```

## Residue

- **`SiteUserModule::$fillable` was missing `website_reference_num` even after the migration
  added the column** — caught by `SiteUsersMyPayerTest` failing with a silently-null value rather
  than an error. Fixed in the same pass; recorded because it's the kind of bug that would have
  shipped invisibly (mass-assignment silently drops unlisted fields, no exception) had the test
  not exercised it.
- **`resources/js/afs/create.ts`'s `getStoredCustomerId()` usage** is `brain_membership_identity`'s
  stated scope (see that plan's own README), not DP6's — flagged here so it isn't mistaken for an
  oversight.
- **D11 (guest-to-signed-in payer reassignment)** remains unimplemented in code, same as its
  sibling cart-cookie mechanism in `connections/12` — both depend on guest-checkout session
  machinery that was only ever planned, never built.
