# 12-guest-continuity — completion record

**Plan file:** [`../12-guest-continuity.md`](../12-guest-continuity.md)
**Repo:** `w/www`
**Date:** 2026-09-15 (updated 2026-09-15, after [13](13-disconnection.complete.md) shipped and
after re-checking this document's own §12.8 acceptance list line by line rather than trusting
this record's first pass)
**Status:** ✅ Both defects (X25, X26) fixed and tested. The one item originally left as residue
here — the disconnected-instance read-only rendering, X26's own last acceptance bullet — is now
closed: [13-disconnection](13-disconnection.complete.md) shipped the same day and built exactly
that surface (X41). Re-checking the rest of §12.8 afterward found four more claims that were
code-correct but not actually exercised by a test; all four are now closed too (see "Closed on
re-check" below). What remains is honestly disclosed as residue.

## X25 — the cart cookie

Built exactly as §12.2 specifies: `active_cart_token` (nullable, mirrors `cart_token` only
while `status === 'active'`) replaces the global unique on `cart_token`, which becomes a plain
index. Migration `2026_09_16_000001_add_active_cart_token_to_ecommerce_carts.php` — backfills
every existing active cart before adding the new unique index, `down()` restores the original
shape. Ran against the real local MySQL database; the resulting indexes were inspected directly
(`ec_carts_store_active_token_uq` unique on `(store_reference_num, active_cart_token)`,
`ec_carts_token_idx` a plain index on `cart_token`, the old global unique gone).

`EcommerceCartService::getOrCreate()` rewritten per the plan: the browser's token is always
reused as both `cart_token` and `active_cart_token`, never discarded. The three status
transitions off `active` the plan names as needing to null `active_cart_token` in the same
write: `EcommerceCheckoutService::complete()` (converted) — done; `EcommerceReconcileOrders`'
own duplicate of that same transition (the recovery path) — done, not named in the plan text but
the same transition; `EcommerceCartMergeService::merge()`'s `guestCart->status = 'abandoned'` —
identified as the "cart clearing where it closes a cart rather than emptying it" site the plan
describes without naming a file (no dedicated close-a-cart endpoint exists anywhere in this
codebase; the merge's own abandon-the-guest-cart step is the only real match for that
description). The plan's third named site, "the abandonment sweep (expired)", does not exist as
actual code anywhere in this codebase (confirmed — no scheduled command reads `laravel_ecommerce_carts.expires_at`)
— documented here as the design invariant for whenever one is built, not a site touched now.

**A correctness gap found while implementing, not in the plan's own code sample:**
`begin()`/`attach()` flip a cart from `active` to `checking_out` without nulling
`active_cart_token` (deliberately — the browser is still using that cart mid-checkout). Under
the new schema, if that same browser calls `getOrCreate()` again at the same store before
completing checkout (a stalled checkout plus another tab, or backing out and adding another
item), the naive rewrite would attempt to `INSERT` a second row with the same
`(store_reference_num, active_cart_token)` pair the stuck `checking_out` cart still holds —
a real `QueryException`, not a graceful fallback, and a regression the old (buggy) code never
had, since it silently discarded the token instead. Fixed by having `getOrCreate()` release any
non-active cart's claim on this store's slot for this token before creating the new cart — the
released cart's own `cart_token` (the only thing that still identifies it) is untouched. Covered
by `tests/Unit/Ecommerce/EcommerceCartServiceGuestTokenTest.php`'s
`test_a_stuck_non_active_cart_at_this_store_does_not_block_a_new_one_for_the_same_token`.

`resolveCartForShopper()` and the sign-in merge are untouched, per the plan.

## X26 — the receipt

**The mailables.** Three new `Mailable`s mirroring `SiteUsersTokenMail`'s own pattern (a Blade
view, the configured mailer, a `try`/`catch` around the send that logs and never rethrows,
because the registration/booking/order is already committed and paid for):
`App\Mail\Events\EventRegistrationReceiptMail`, `App\Mail\Appointments\AppointmentBookingReceiptMail`,
`App\Mail\Ecommerce\EcommerceOrderReceiptMail`. Wired from `EventCommunicationService::sendConfirmation()`,
`AppointmentCommunicationService::sendConfirmation()` (both existing log-stub methods, now
actually sending), and a brand-new `App\Externals\Ecommerce\Services\EcommerceReceiptService::sendOrderReceipt()`
— ecommerce had no confirmation service of any kind before this — called from
`EcommerceCheckoutService::complete()` after the order row and cart are both saved, and from
`EcommerceReconcileOrders`' own recovery path (not named by the plan, but the same write, and a
guest whose client died before `/checkout/complete` would otherwise never get a receipt at all).

**`ModulePagePaths`** (`app/Services/Builder/ModulePagePaths.php`) — the §12.4 resolver, built
as a new class rather than folded into `SiteUsersPagePaths` since the two answer genuinely
different questions from different tables (`laravel_builder_routes`' `login_route` vs.
`laravel_builder_module_refs`/`laravel_builder_published`'s page-family index).
`SiteUsersPagePaths` does become "a thin caller" of it for the one piece that actually is
shared — the sibling-suffix swap (`-login` → `-register`/`-dashboard`) is now
`ModulePagePaths::sibling()`, called by both classes instead of duplicated.

One deliberate deviation from the plan's own illustrative code: rather than resolving ONE
published row and deriving every sibling subpage from it by string surgery ("strip a known
suffix, append the wanted one"), each subpage is resolved **independently** — its own suffix
matched directly against `laravel_builder_module_refs`. A tenant is free to publish an
instance's `list` and `my-registrations` pages as unrelated pages rather than one shared family;
deriving siblings from whichever row sorts first would silently manufacture a URL for a page
that does not exist. Costs one query per known subpage (bounded, small) rather than one.

Known subpages mirror each module's own `SUBPAGES` constant in `resources/js/external/ui/<module>/ui.ts`
— there is no server-side registry of them to read instead, so this is now the one place both
sides must agree, called out in the class's own docblock. `ecommerce` is included
(`order-confirmation`) even though it has no sibling-page family of its own (its storefront is a
`data-commerce-kind` attribute, not a suffix convention) — a tenant who names their confirmation
page that way, or sets the `settings.pages.order-confirmation` override, is still found.

**The signed-in path.** A purchaser gets the Dashboard's own deep link (08 §8.4's hash form)
with no token, per §12.5's rule against minting a credential nobody needs. Reverse-engineered
the exact hash shape from the actual provider files rather than guessed from the plan's prose,
since the plan's own example (`ecommerce:store-9f3k:order/ord-a1b2`) turned out to be the only
one literally accurate — `events/dashboard.ts` uses `'ticket'` as its single-registration view
key, not `'upcoming'` (the list); `appointments/dashboard.ts` has **no** single-booking detail
view at all yet, only `'upcoming'`. **Also found and fixed along the way:** `SiteUsersPagePaths::forModule()`'s
`dashboard` path is relative to the site's own root (an href, meant for in-page links) — the
plan's own manageUrl code samples would have put a bare `/account-dashboard#...` fragment
straight into an email, which resolves to nothing outside a browser tab already on that origin.
All three signed-in branches now prepend `config('app.url')`.

**The frontend guest branch** (§12.6) — `?registration=<ref>&t=<token>` on the events
`my-registrations` page, `?booking=<ref>&t=<token>` on the appointments `my-bookings` page, both
in `resources/js/external/ui/`. Events reuses the existing `buildTicketDetailMarkup()` (already
built for the Dashboard's own `ticket` view, K14) — extended with an optional `allowCancel` flag
(default `false`, so the Dashboard's own call site is unchanged) rather than a second markup
function. Appointments has no single-booking builder at all yet (dashboard.ts's own `'upcoming'`
view never got a detail pane) — rather than invent one, the guest view reuses
`buildMyBookingsMarkup([booking], true)` as a "list of one," which already renders Cancel with
the server-computed `can_cancel` gate; the Reschedule button it also renders is stripped by the
guest view, since the plan names cancel and calendar only for this surface, and a reschedule
flow needs the availability/slot-picker machinery the Dashboard's own wiring pulls in — out of
scope for getting a guest back to something actionable. Ecommerce needs no branch, per the plan
— its order-confirmation page already reads `?order=` and the endpoint already serves a guest
order by reference.

## Files

**New (backend):** `app/Services/Builder/ModulePagePaths.php`, `app/Externals/Ecommerce/Services/EcommerceReceiptService.php`,
`app/Mail/Events/EventRegistrationReceiptMail.php`, `app/Mail/Appointments/AppointmentBookingReceiptMail.php`,
`app/Mail/Ecommerce/EcommerceOrderReceiptMail.php`, `resources/views/mail/events/registration_receipt.blade.php`,
`resources/views/mail/appointments/booking_receipt.blade.php`, `resources/views/mail/ecommerce/order_receipt.blade.php`,
`database/migrations/2026_09_16_000001_add_active_cart_token_to_ecommerce_carts.php`

**Modified (backend):** `app/Externals/Ecommerce/Services/EcommerceCartService.php` (`getOrCreate()`
rewritten, `createCartForSiteUser()` gains `active_cart_token`), `app/Externals/Ecommerce/Services/EcommerceCheckoutService.php`
(`complete()` nulls `active_cart_token`, calls the new receipt service), `app/Externals/Ecommerce/Services/EcommerceCartMergeService.php`
(`merge()` nulls the abandoned guest cart's `active_cart_token`), `app/Console/Commands/EcommerceReconcileOrders.php`
(same nulling + receipt call, recovery path), `app/Models/Externals/EcommerceCart.php` (`active_cart_token`
fillable), `app/Externals/Events/Services/EventCommunicationService.php` (`sendConfirmation()` actually
sends), `app/Externals/Appointments/Services/AppointmentCommunicationService.php` (same),
`app/SiteUsers/SiteUsersPagePaths.php` (sibling-swap delegated to `ModulePagePaths::sibling()`)

**Modified (frontend):** `resources/js/external/ui/events/ui.ts` (`buildTicketDetailMarkup()`
gains `allowCancel`; `renderMyRegistrations()` branches on `?registration=&t=`),
`resources/js/external/ui/appointments/ui.ts` (`renderMyBookings()` branches on `?booking=&t=`)

**New (tests):** `tests/Unit/Services/Builder/ModulePagePathsTest.php` (6),
`tests/Unit/Events/EventCommunicationServiceTest.php` (5, incl. the mail-failure test below),
`tests/Unit/Appointments/AppointmentCommunicationServiceTest.php` (4, ditto),
`tests/Unit/Ecommerce/EcommerceReceiptServiceTest.php` (4, ditto),
`tests/Unit/Ecommerce/EcommerceCartServiceGuestTokenTest.php` (5),
`tests/Unit/Ecommerce/EcommerceCheckoutServiceCartTokenTest.php` (1, new — see "Closed on
re-check" below) — 25 new tests total, hand-built sqlite schemas per this codebase's own
established convention (`PublishedModuleReferencesTest`'s own docblock explains why: no suite
here runs the full migration set against sqlite). `tests/Feature/Connections/EventsEnforcementTest.php`
gained 6 more across the `calendarIcs()` follow-up and the [13](13-disconnection.complete.md)
re-check pass (counted in that document's own file list, not repeated here).

## Verification

```bash
cd w/www
php artisan migrate --force                                      # new active_cart_token column + index, real local MySQL
npx tsc --noEmit -p resources/js/external/ui/tsconfig.json       # exit 0
npx tsc --noEmit -p resources/js/external/api/tsconfig.json      # exit 0
npx tsc --noEmit -p tsconfig.json                                 # exit 0 (root)
npm run test:external-ui                                          # 81 passed
npm run test:external-api                                         # 11 passed
npm run build-external-webpack                                    # exit 0
npm run build-external-ui-webpack                                 # exit 0
npm run build                                                     # exit 0 (root vite, 1m44s)
php artisan test                                                  # 518 passed, 3 pre-existing unrelated failures
```

## Closed on re-check (2026-09-15, prompted by "is this completely done?")

Re-reading §12.8's acceptance list line by line — rather than trusting this document's own
summary — found five claims that were true in principle but not actually verified by a test.
All five are now closed:

- ~~**Disconnected-instance read-only rendering (X26's last bullet) is not built.**~~ — **Closed
  by [13-disconnection](13-disconnection.complete.md)**, shipped the same day. `registration()`/
  `booking()`/the single-order endpoint now carry `read_only`/`read_only_notice`; the guest
  storefront views render the notice and omit the write action. See that document for the full
  account, including a real ownership bug found and fixed along the way that this bullet's own
  acceptance test would otherwise have failed against.
- ~~**"A mail-transport failure logs an error and does NOT roll back the registration, booking or
  order — asserted by forcing the mailer to throw and checking the row still exists" was never
  actually tested.**~~ — **Closed.** One new test per module (`Mail::shouldReceive('to')->andThrow(...)`
  against a REAL, persisted row — the other tests in these files use in-memory unsaved instances,
  which can't prove a rollback didn't happen). Confirmed the exception is genuinely thrown and
  caught, not vacuously passing, by checking `storage/logs/laravel.log` picked up the forced
  message.
- ~~**`EcommerceCheckoutService::complete()` itself was never exercised — only `getOrCreate()`'s
  reaction to a null `active_cart_token`, which assumed `complete()` sets it correctly.**~~ —
  **Closed.** New `EcommerceCheckoutServiceCartTokenTest` calls the real `complete()` (a
  test-double `EcommerceOrderService`/`PaymentGateway` at the DirectPayments boundary, which is
  not X25's concern; a real `EcommerceCartService` underneath), confirms `active_cart_token` is
  actually nulled and `cart_token` untouched, and that the same browser token then gets a fresh
  cart — §12.8's own words, checked end to end for the first time.
- ~~**The guest TOKEN path's `read_only` exposure was only owner-path-tested.**~~ — closed as
  part of [13-disconnection](13-disconnection.complete.md)'s own re-check pass (its Residue
  section covers this one; noted here only for cross-reference, since it is this document's own
  acceptance line).
- ~~**`grep -rn "where('cart_token'" app/` "still shows exactly two call sites" — stale.**~~ —
  **Noted, not "fixed":** re-run today, it shows **one** site (`EcommerceCartService::findActiveByToken()`).
  `getOrCreate()`'s own destructive check — the second site the plan's acceptance line was
  written against — is gone by design: it no longer needs to read `cart_token` at all, since
  uniqueness moved to `active_cart_token`. Fewer sites touching the identifying field is the
  better outcome; the acceptance line's literal count is simply superseded by the fix it was
  written to describe.

## Residue

- ~~**Appointments has no single-booking deep-link view.** `appointments/dashboard.ts`'s only view
  is `'upcoming'` — a signed-in purchaser's emailed link still lands correctly (on the list,
  where the booking is visible), just not scrolled-to/highlighted the way events' `'ticket'` view
  does. The `ref` rides along in the hash unused rather than being dropped, so the link starts
  working precisely the moment such a view is added — no receipt-side change needed then.~~ —
  **Built, 2026-09-15.** `appointments/dashboard.ts` gained a `'booking'` view (registered
  alongside `'upcoming'` in `views()`), matching events' own `'ticket'` pattern: fetches
  `api.myBookings()`, finds the matching booking, renders it via the existing
  `buildMyBookingsMarkup([booking], true)` "list of one" (same convention events already used),
  with a Back button to the list. `AppointmentCommunicationService::manageUrl()`'s signed-in
  branch now points at `#appointments:*:booking/<ref>` (was `upcoming/<ref>`, which never
  scrolled to anything). `AppointmentCommunicationServiceTest` and
  `resources/js/external/ui/tests/appointments.test.ts` updated/extended accordingly.
- **The sign-in cart merge (`EcommerceCartMergeService::merge()`, `resolveCartForShopper()`) has
  no test coverage, before or after this document.** Deliberately "untouched" by X25 per the
  plan's own text (§12.2: "resolveCartForShopper() and the sign-in merge are untouched"), and
  confirmed by grep that no test anywhere in this suite has ever exercised it. §12.8's "a shopper
  with carts at two stores keeps both after signing in" acceptance line is therefore unverified —
  not because X25 broke anything (the code is unchanged), but because it was never covered to
  begin with, and building that coverage now would be auditing pre-existing, untouched ecommerce
  behavior under this document's name rather than finishing what X25/X26 actually asked for.
- ~~**The migration's backfill (§12.8: "no guest loses a cart on deploy") was verified by reading
  the migration and running it once against real local MySQL with no pre-existing data** — not
  by an automated test seeding active carts before migrating. `EcommerceCheckoutServiceCartTokenTest`
  and `EcommerceCartServiceGuestTokenTest` both prove the POST-migration schema behaves
  correctly; neither proves the backfill step itself moves pre-existing data over correctly.~~ —
  **Covered, 2026-09-15.** New `EcommerceCartTokenMigrationTest` builds the PRE-migration schema
  directly (the old global unique on `cart_token`, no `active_cart_token` column), seeds rows the
  way a real deploy would find them, runs the migration's own `up()` in isolation via
  `require database_path(...)`, and asserts the backfill mirrors the token onto every active cart
  and leaves converted/abandoned carts untouched.
- ~~**"Following that link renders the single record and allows exactly the actions its
  connection's settings permit" (§12.8) was verified only for the connected/default-settings
  case.** No test drives a guest's cancel action against an event/calendar with
  `cancel.enabled: false` or a closed cutoff window specifically through the token path — those
  settings are exercised elsewhere (09's own tests) against the signed-in/owner path, and the
  guest token path shares the exact same `cancelRegistration()`/`cancelBooking()` code after the
  ownership check, so the risk is low, but it was not independently re-confirmed here.~~ —
  **Covered, 2026-09-15.** Four new tests drive the guest token path specifically (no bearer,
  `access_token`/`?token=` only): `EventCancelPolicyTest` gets a disabled-setting case and a
  closed-cutoff case, `BookingSelfServiceTest` gets the connection-level kill-switch case and the
  policy-window case — mirroring each file's existing signed-in equivalents exactly, through the
  same route, so the two paths are now independently confirmed rather than inferred from shared
  code.
- ~~**The ICS attachment's own "no token" guarantee (§12.8) has no test**, before or after this
  document — `buildIcs()` was read and confirmed unchanged by this work, not covered by a new
  assertion.~~ — **Covered, 2026-09-15.** `EventCommunicationServiceTest` and
  `AppointmentCommunicationServiceTest` each gained a test asserting `buildIcs()`'s returned
  string never contains the record's `access_token`.
- ~~**A pre-existing bug found, not fixed (out of this plan's scope):** `EventStorefrontController::calendarIcs()`
  has no owner-check fallback when the `token` query parameter is blank~~ — **Fixed, 2026-09-15,
  as its own follow-up commit** (spawned as a separate task rather than folded into this pass, to
  keep this document's own diff to what X25/X26 actually asked for). `calendarIcs()` now requires
  the same owner-or-token check `registration()` already used — 404, not 401, on a blank token
  with no owning session, matching `AppointmentStorefrontController::calendarIcs()`'s own correct
  behavior next to it. Four new tests in `tests/Feature/Connections/EventsEnforcementTest.php`
  (no token and no session refused; a foreign session refused; a valid token succeeds; the real
  owner's session succeeds).
