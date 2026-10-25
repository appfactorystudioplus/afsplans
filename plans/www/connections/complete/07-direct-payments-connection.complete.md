# 07-direct-payments-connection — completion record

**Plan file:** [`../07-direct-payments-connection.md`](../07-direct-payments-connection.md)
**Rollout phase:** K6 (doc's own "Depends on": K0, K2 — not K3/K5)
**Repo:** `w/www`
**Date:** 2026-09-14
**Status:** ✅ Shipped in full, including both pieces an earlier pass in this same round
correctly identified as blocked and then unblocked by building the sibling plans they depended
on. §7.5 (the payer link, security-fixed), §7.8 (`linkCustomer()` retirement), §7.6/§7.7
(`GET /me/purchases`), and §7.9 (rate limiting + caching) shipped first; §7.10 (email-change
propagation) shipped once `site_users/13`'s email-change flow existed for it to call
(see `site_users/complete/13-identity-and-email-change.complete.md`); §7.3/§7.4 (the SDK
payer-resolver) shipped as a real, working, standalone SDK addition — with one honestly-scoped
exception: no live call site exists anywhere in this codebase to wire it into (see "What
shipped" and "Not implemented" below for exactly what that means and doesn't mean).

## Scope correction, made before writing any code

The first read of this document concluded §7.3/§7.4 and §7.10 were blocked and proposed
skipping them via a question to the user. The user's actual reply was not a choice between the
options offered — it was a correction: *"Both direct_payments/05 and site_users/13 have
completion reports are they not done?"* That was right to ask. Reading both completion docs in
full (rather than trusting this session's own greps) confirmed the blocked conclusion for
§7.3/§7.4 and §7.10 specifically, but also revealed the starting mental model — "doc 07 = build
everything from scratch" — was wrong: `direct_payments/complete/05-implementer-contract.complete.md`
(DP6) had already shipped most of this document's groundwork under its own §5.5 ahead of this
phase:

- `laravel_site_user_payers` + `SiteUserPayer` model.
- `PayerService::resolveForCheckout()`, `customerFor()`, `upsertActiveCustomer()`,
  `updateEmail()` — all pre-existing.
- `GET /me/payer` (`SiteUsersPublicController::myPayer()`) — but, per DP6's own acceptance
  table, its "signed-in checkout never adopts a guest payer" row was evidenced only by
  `EcommerceOrderPayerTest`, not by `myPayer()` itself. It hadn't been.
- `siteusers:link-customers`, re-targeted onto payers in DP6.

So the real, narrower task this phase actually needed was: close the security gap DP6's own
acceptance table didn't cover, retire the endpoint DP6's §5.5 said no longer belongs, and build
the two pieces of doc 07 genuinely new to K6 — the payment-methods CRUD surface and purchases.

## What shipped

### §7.5 — a real, previously-shipped security gap, closed

`myPayer()`'s first-call resolution went through `PayerService::resolveForCheckout()` — the
guest-oriented method, whose `firstOrCreate(['website_reference_num' => ..., 'email' => ...],
...)` **adopts an existing payer via email match**. That is exactly the guest convenience
`direct_payments/02` §2.2 forbids for a signed-in user: a stranger's guest purchase history
(and any payment methods later added to that payer) would have silently attributed to whichever
signed-in user later shared that email address.

Fixed with a new `PayerService::resolveForSiteUser(string $websiteReferenceNum, ?string $email,
?string $name): ?DirectPaymentPayer` — deliberately not a shared code path with
`resolveForCheckout()`. It never queries by email at all; every call creates a fresh payer.
`myPayer()` rewritten to call it, and re-pointed onto `SiteUsersConnectionRegistry::paymentsLinked()`
(replacing the legacy `link_payment_customers` module flag doc 07 itself retires — §7.8).

Regression-proven by a new test, not just the fix: `SiteUsersMyPayerTest::
test_a_first_call_never_adopts_an_existing_payer_with_a_matching_email` seeds a
`DirectPaymentPayer` under a different email-sharing identity *before* the signed-in user's
first call, and asserts the returned payer is a distinct row (2 payer rows exist afterward, not
1). This is the one test in the whole suite that would have failed against the pre-fix code —
confirmed by temporarily reverting the fix and watching it fail before finalizing.

### §7.8 — `linkCustomer()` / `POST /me/customers` retired

Per the plan's own words: *"`linkCustomer()` and `siteusers:link-customers` no longer
exist[...] There is nothing left to explicitly link — `GET /me/payer` creates the one row Site
Users keeps."* (`siteusers:link-customers` itself is untouched — DP6 already re-targeted it
onto payers, and doc 07 doesn't ask for more.)

- `SiteUsersPublicController::linkCustomer()` deleted outright (~55 lines), along with the now-
  unused `SiteUserCustomerLink` import.
- `POST /me/customers` removed from both `routes/portal.php` and `routes/auth1.php`.
- `tests/Feature/SiteUsers/SiteUsersCustomerLinkTest.php` deleted — confirmed by a full read
  first that it only exercised the removed endpoint.
- `SiteUserCustomerLink`'s docblock updated to state the table is now read-only: its two real
  remaining consumers, `SiteUsersAdminController`'s admin display and
  `SiteUsersUserDeletion`'s cleanup, are both readers, never writers, and neither was in this
  phase's scope to touch.

### §7.5/§7.9 — `GET/POST/DELETE /me/payment-methods*`, new `SiteUsersPaymentsController`

New `app/Http/Controllers/Externals/SiteUsers/SiteUsersPaymentsController.php` and
`app/Externals/Processor/ProcessorPaymentMethodService.php` (a re-keyed extraction of
`ProcessorBillingController`'s existing Stripe/Square/PayPal payment-method logic — the same
provider wire format and response shape, but parameterized on a raw provider-customer-id string
instead of a `ProcessorCustomer` Eloquent row, matching the payer/payer-customer model DP6
already put in place).

- `GET /me/payment-methods?processor=` — gated on `paymentsVisible()` (active **or**
  disconnected — 13 §13.6/X35: a user must still be able to see, and remove, cards they have
  after a tenant disconnects). Merges `list()` across every `DirectPaymentPayerCustomer` row the
  linked payer holds.
- `POST /me/payment-methods`, `POST /me/payment-methods/setup` — gated on the tighter
  `paymentsLinked()` (active only). Both refuse (404) rather than lazily mint a payer-customer
  row when the payer has no existing active row on the named processor — reasoned from
  `PayerService::customerFor()`'s own docblock ("this runs once per payer per processor, the
  first time they pay on it, not pre-created for everyone at once"): a card-management surface
  is not a checkout, and shouldn't create billing relationships a payer has never actually used.
- `DELETE /me/payment-methods/{id}`, `POST /me/payment-methods/{id}/default` — gated on
  `paymentsVisible()` (a disconnected instance still lets a card be removed), and read-then-act:
  the method must already be in `list()`'s own result or the id is refused as `404`, never acted
  on blind.
- §7.9's cache: `ProcessorPaymentMethodService::list()` — `Cache::remember("pm:{processor_ref}:
  {provider_customer_id}", 60s, ...)`, busted by `store()`/`delete()`/`setDefault()` on success.

### §7.6/§7.7 — `GET /me/purchases`, new `SiteUsersPurchasesService`

New `app/SiteUsers/SiteUsersPurchasesService.php`, wired as `SiteUsersPaymentsController::
purchases()`. Unions, per the plan's own three-part rule:

1. Façade records the user owns — `EcommerceOrder`, `EventOrder`, `AppointmentBooking`, each
   matched on `site_user_reference` and filtered to instances `SiteUsersConnectionRegistry::
   resolveAny()` reports visible (active or disconnected — excluded only once the connection
   row is `removed`, per 13 §13.4's "disconnection is read-only").
2. Direct Payment invoices whose `customer_id` names a provider customer in one of the payer's
   own `DirectPaymentPayerCustomer` rows, not already named by (1) — a donation, a bare DP
   block, a subscription. **Never** joined on `laravel_direct_payment_invoices.user_id` (X23):
   that field is validated `nullable|string` and set by the client, so a forged value could
   otherwise stamp a stranger's invoice into a victim's purchases view. Regression-tested
   directly (`test_a_spoofed_user_id_on_an_invoice_never_surfaces_it`).
3. Money for the whole page from one `PaymentGateway::snapshots()` call (the façade rule, K15's
   neighbour) — nothing about money is stored in the new service.

An invoice linked via `laravel_direct_payment_subscriptions.invoice_reference_num` is tagged
`kind: subscription` per §7.7 (no actions — cancel/update needs the DP subscription controller
to accept a Site Users session as a second identity resolver, correctly out of this document's
scope per its own §7.7 text, and recorded in `11-rollout-and-testing.md` §11.7).

Pagination is a keyset cursor (`base64url({s: sort_timestamp, i: reference_num})`), applied
in-memory after the union rather than at the SQL layer — the existing `DirectPaymentsController::
indexItems()` cursor convention this codebase already uses is a single-table SQL `WHERE`,
which doesn't apply once the page is a merge of three unrelated Eloquent models plus a fourth
raw-invoice source; the encode/decode shape and semantics otherwise match that convention
exactly (see "Deviations").

### §7.9 — rate limiting, `SiteUsersPublicThrottle`

Before this phase, the `/me/*` family matched none of `SiteUsersPublicThrottle`'s `if/elseif`
branches and fell through completely unthrottled — the exact gap the plan's own X18 names: `GET
/me/payment-methods` issues one provider HTTP call per linked customer, and an authenticated
session left on a refreshing dashboard tab could turn into sustained load against a tenant's
provider account (which is rate-limited per tenant, not per visitor).

- `su-me-pm:{session_hash}` (30/300s) + `su-me-pm-ip:{ip}` (120/300s) for
  `/payment-methods*` — deliberately tight, since this is the provider fan-out.
- `su-me:{session_hash}` (240/300s) + `su-me-ip:{ip}` (600/300s) for the rest of `/me/*` —
  `/me`, `/me/payer`, `/me/purchases`, `/me/sessions`.
- `su-emailchange-user:{session_hash}` (3/3600s) + `su-emailchange-ip:{ip}` (10/3600s) for
  `/me/email`, ordered **before** the generic `/me` branch per the plan's own X38 note — not yet
  reachable by any route (site_users/13's `/me/email` doesn't exist — see "Not implemented"
  below), but built now so whoever wires that endpoint doesn't also have to remember the
  ordering rule.
- Keyed on the session token's SHA-256 hash (`SiteUsersSessionService::extractToken()`), not the
  user id — avoids a database read before deciding to reject, same reasoning the plan gives.

### §7.10 — email-change propagation, shipped once there was an endpoint to call it from

`site_users/13`'s email-change confirm handler (`SiteUsersVerificationService::
confirmEmailChange()`) resolves the caller's `SiteUserPayer` link, if any, and calls
`PayerService::updateEmail($link->payer_reference_num, $user->email, $user->display_name ?? '')`
on success — exactly the one call §7.10's own code sample shows, best-effort and logged, never
on the critical path. `updateEmail()` itself was already built and tested by `direct_payments/05`
(DP6); this round's only job was giving it a real caller, which meant building the email-change
flow `site_users/13` §13.4 specifies (full detail in that plan's own completion record) —
genuinely out of doc 07's own scope, but the only way to close a gap doc 07 names as blocked on
it.

### §7.3/§7.4 — the SDK payer-resolver, built as a real standalone addition

New `DirectPaymentsPayerResolver`/`DirectPaymentsSavedMethod` types and `useCustomerResolver()`/
`savedPaymentMethods()` on `ExternalDirectPaymentsAPI` (`external/api/direct_payments/api.ts`),
matching `direct_payments/05` §5.3's interface exactly (not doc 07's own now-superseded earlier
draft, which returned a raw provider customer id). `saveInvoice()` now consults the installed
resolver automatically when the caller doesn't already name a `userId`, stamping
`user_id = 'su:' + <payer reference>` on the invoice per §7.6's "Stamping user_id" subsection —
wrapped in a try/catch so a throwing or null-returning resolver leaves the invoice exactly as it
would be with none installed (§7.11's own acceptance: "the charge succeeds anonymously").
`SiteUsersClient.paymentsResolver()` (`external/api/site_users/SiteUsersClient.ts`) is the one
implementation that ships in this estate, calling `/me/payer` and `/me/payment-methods` exactly
as §7.4's own code sample shows. Both `portal.d.ts`'s ambient ecosystem types
(`PortalDirectPaymentsPayerResolver`, `PortalDirectPaymentsSavedMethod`,
`PortalSiteUsersInstance.paymentsResolver()`) were updated to match, and both SDK bundles
(`external/api`, `external/ui`) type-check and build clean.

**What this does NOT include, and why — verified, not assumed.** §7.4's own wiring line
(`const dp = window.Portal.directPayments.create(dpRef); if (session)
dp.useCustomerResolver(session.su.paymentsResolver());`) assumes a live storefront call site
that both (a) calls the raw Direct Payments SDK client directly and (b) already has a Site
Users session in scope. A full-codebase grep for every real caller of
`Portal.directPayments.create()` — not just the three `external/ui` reference storefronts doc 07
names, but also `resources/js/portal/builder/core/component_scripts/` (the actual widgets a
real published "designed" page uses) — found none that satisfy both:

- **The three `external/ui` reference storefronts** (`ecommerce/ui.ts`'s `renderCheckout()`,
  `events/ui.ts`'s `renderRegister()`, `appointments/ui.ts`'s `renderBooking()`) never call
  `Portal.directPayments.create()` at all. They call their own module-specific checkout/
  registration/booking endpoints, which already resolve a signed-in buyer's payer **server-side**
  from the session's `site_user_reference` (`EcommerceOrderService`/`EventOrderService`/
  `AppointmentBookingService::resolvePayerReferenceNum()`, all DP6-era, all still correct) —
  independently re-confirming exactly what `direct_payments/05`'s own completion doc already
  found: a client-side resolver adds nothing here, because the server already knows who the
  buyer is without being told.
- **`commerce_component_script.ts`** (the real page-builder checkout widget, per its own
  header comment "Payment is where an inert block costs the most") calls
  `portal.directPayments.create()` but carries no Site Users session integration of any kind —
  confirmed by grep, zero hits for `site_users`/`siteUsers` anywhere in that file, matching
  `06-checkout-guard.complete.md`'s own Residue finding that this file's checkout path is
  largely unwired to begin with.
- **`direct_payments_component_script.ts`** (the bare Direct-Payment-block widget — genuinely
  the "third door" case doc 07 §7.1 describes, since it has no façade module in front of it to
  resolve a payer server-side) is the one real, live call site that charges money through
  `Portal.directPayments.create()` directly — but it receives no Site Users module context at
  all today (no `data-*` attribute names one), and giving it one is a Page Builder block-schema
  change, not something doc 07 defines or this round's scope covers.

Forcing `useCustomerResolver()` into any of these would mean attaching a resolver to a client
instance whose result nothing then uses — dead, decorative code of exactly the kind
`direct_payments_component_script.ts`'s own header warns against for a different feature ("it
renders convincingly, which is the part that makes it worse"). The resolver infrastructure
itself is complete and real; wiring it into a live checkout is correctly left undone because no
live checkout exists to wire it into.

## Deviations from the plan text, found while implementing

- **The plan's own throttle snippet matches `str_starts_with($path, 'portal/api/siteusers/
  public/me')` — too narrow for this codebase.** `SiteUsersPublicThrottle` is attached to
  **both** `routes/portal.php`'s and `routes/auth1.php`'s `/me/*` groups (the same
  dual-registration pattern every other Site Users route in this codebase follows), so a
  `portal/`-prefixed-only match would leave every `auth1/`-prefixed request on this family
  completely unthrottled — reintroducing the exact gap this section exists to close, just on
  the other vhost. Implemented as `str_contains($path, '/siteusers/public/me...')` instead,
  matching the style every other branch in this same middleware already uses for the identical
  reason.
- **The purchases cursor is applied in-memory, not as a SQL `WHERE`** (see above) — the
  encode/decode format and the "first match wins, keyset not offset" semantics are unchanged
  from this codebase's existing convention; only where the comparison happens differs, because
  the source is a merge of three Eloquent models plus a fourth raw-invoice query.
- **Response `kind`/`manage.view` values for event and appointment purchases are not given
  literally by the plan** — its one worked JSON example is for an ecommerce order
  (`kind: "order"`). Assigned `kind: "event"` / `manage.view: "order"` for event orders (`EventOrder`
  is itself named "order" in that module's own domain language) and `kind: "appointment"` /
  `manage.view: "booking"` for appointment bookings, following each module's own noun. No
  frontend consumes this endpoint yet (confirmed by grep across `resources/js/`), so this is a
  documented choice, not a breaking one.
- **`store`/`setupPaymentMethod` refuse rather than lazily create a payer-customer row** when
  none exists yet for the named processor (see "What shipped" above) — the plan doesn't say
  this explicitly for the management endpoints, but it follows directly from
  `PayerService::customerFor()`'s own "not pre-created for everyone at once" docblock, and
  keeps this surface from becoming a second, undocumented way to originate a billing
  relationship outside an actual checkout.

## Not implemented

- **Wiring `useCustomerResolver()` into a live checkout call site.** See "§7.3/§7.4" above for
  the full, verified reasoning: no file anywhere in this codebase both calls
  `Portal.directPayments.create()` directly for a real charge AND already carries live Site
  Users session context. The resolver interface, the SDK method, and `SiteUsersClient.
  paymentsResolver()` are all real and complete; attaching them to a call site would mean
  inventing one that does nothing, which was judged worse than leaving it correctly disclosed
  as unwired. The one genuine candidate (`direct_payments_component_script.ts`, the bare
  DP-block widget) needs a Page Builder block-schema change (a Site Users module attribute on
  the block) that belongs to the Builder plan, not this document.
- **§13.4.4's Dashboard/Admin UI for the email-change flow** (the Profile pane field, the admin
  per-user email screen) — `site_users/complete/13-identity-and-email-change.complete.md`
  covers this in full: both need a Dashboard shell (`connections/` K7/K8) that doesn't exist yet
  anywhere in this codebase. The backend and SDK are built and tested, waiting for it.

## Files

**New:** `app/Http/Controllers/Externals/SiteUsers/SiteUsersPaymentsController.php`,
`app/Externals/Processor/ProcessorPaymentMethodService.php`,
`app/SiteUsers/SiteUsersPurchasesService.php`,
`tests/Feature/SiteUsers/SiteUsersPaymentsControllerTest.php`,
`tests/Feature/SiteUsers/SiteUsersPurchasesTest.php`,
`tests/Feature/SiteUsers/SiteUsersMeThrottleTest.php`

**Modified:** `app/Externals/DirectPayments/PayerService.php` (`resolveForSiteUser()` added),
`app/Http/Controllers/Externals/SiteUsers/SiteUsersPublicController.php` (`myPayer()` rewritten,
`linkCustomer()` deleted), `app/Models/Externals/SiteUsers/SiteUserCustomerLink.php` (docblock only),
`app/SiteUsers/SiteUsersDerivedColumns.php` (docblock only),
`app/Http/Middleware/SiteUsersPublicThrottle.php` (`/me/*` buckets added),
`routes/portal.php`, `routes/auth1.php` (`POST /me/customers` removed; `GET /me/payer`,
`/me/payment-methods*`, `GET /me/purchases` added to both)

**Modified (tests):** `tests/Feature/SiteUsers/SiteUsersMyPayerTest.php` (rewritten to use
`CreatesConnectionsFixtures::connect()` instead of the retired `link_payment_customers` flag;
one new regression test for the email-adoption fix)

**Deleted:** `tests/Feature/SiteUsers/SiteUsersCustomerLinkTest.php`

**§7.10/§7.3/§7.4 (this round's second pass) — new:**
`resources/js/external/api/direct_payments/api.ts` (`DirectPaymentsPayerResolver`/
`DirectPaymentsSavedMethod` types, `useCustomerResolver()`, `savedPaymentMethods()`, `saveInvoice()`
resolver wiring — all additions, no existing method's behaviour changed for a caller that never
installs a resolver). Everything else §7.10/§13.4 needed (the email-change flow itself, its
tests) is filed under `site_users/complete/13-identity-and-email-change.complete.md`, not
duplicated here.

**§7.3/§7.4 — modified:** `resources/js/external/api/site_users/SiteUsersClient.ts`
(`paymentsResolver()` added), `resources/js/external/api/portal.d.ts`
(`PortalDirectPaymentsPayerResolver`, `PortalDirectPaymentsSavedMethod`,
`PortalDirectPaymentsAPI.useCustomerResolver()`/`savedPaymentMethods()`,
`PortalSiteUsersInstance.paymentsResolver()`/`changeEmail()`/`confirmEmailChange()`)

## Verification

```bash
cd w/www
php artisan test tests/Feature/SiteUsers tests/Feature/DirectPayments   # 189 passed (K6-adjacent surface, K11's email-change tests included)
php artisan test                                                       # 393 passed, 3 pre-existing unrelated failures (Builder/Forms/ExampleTest — untouched by this phase)
php artisan route:list --path=siteusers                                # confirms both portal/ and auth1/ carry the same eight new routes (six K6 + two K11)
npx tsc --noEmit -p resources/js/external/api/tsconfig.json            # exit 0
npx tsc --noEmit -p resources/js/external/ui/tsconfig.json             # exit 0
npm run build-external-webpack                                         # exit 0
npm run build-external-ui-webpack                                      # exit 0
npm run test:external-ui                                               # 48 passed, unaffected by this round
npm run build                                                          # exit 0 (root vite build)
```

## Residue

- **`DirectPaymentGateway::snapshots()` is not internally batch-optimized** — it loops
  `snapshot()` per ref rather than issuing one query for N invoices. `SiteUsersPurchasesService`
  still makes exactly one *call* to it, satisfying §7.6's façade rule (never resolving money
  itself, never storing it) — the N+1 is an existing characteristic of `DirectPaymentGateway`
  itself, unrelated to this phase, and out of scope to fix here.
- **No dedicated test for the payment-methods cache** (`Cache::remember`/`Cache::forget` in
  `ProcessorPaymentMethodService`) — the behavior is a direct, small wrapper around Laravel's
  cache facade with no branching logic of its own; verified by code review rather than a
  dedicated test, consistent with how thin a wrapper it is.
- **No dedicated JS test for `useCustomerResolver()`/`saveInvoice()`'s resolver wiring** —
  `external/api` has no test harness at all (unlike `external/ui`'s `node --test` suite);
  verified by `tsc --noEmit` type-checking, a clean webpack build, and code review. The
  resolver's SERVER side (`/me/payer`, `/me/payment-methods`) is exhaustively covered by the
  existing PHP suite.
- **`SiteUsersPaymentsThrottleTest.php`/`EmailChangeTest.php`** — `11-rollout-and-testing.md`'s
  own K11 acceptance table names these two file names; the actual files this round produced are
  `SiteUsersMeThrottleTest.php` (extended, not new — it already existed from K6) and
  `SiteUsersEmailChangeTest.php`. Same coverage, different names — flagged here rather than
  renamed, since the extended throttle file already had K6 tests in it before this round and
  splitting it would have been pure churn.
