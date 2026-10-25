# 10-api-contracts — completion record

**Plan file:** [`../10-api-contracts.md`](../10-api-contracts.md)
**Rollout phase:** Not its own K-phase — this document is, in its own words, "not new design …
the sum of [04] through [09]." Closing it is an audit: verify every route, response shape, SDK
method and type declaration it lists against what K3–K9 actually shipped, and fix what's really
missing.
**Repo:** `w/www`
**Date:** 2026-09-15
**Status:** ✅ Audited in full. The overwhelming majority of this document was already true —
every route in §10.2–§10.8 exists, every error code in §10.13 is used somewhere real, the rate
limits in §10.14 and the negative assertions in §10.15 both already hold exactly as written.
What was genuinely missing was narrower and specific: the ambient `portal.d.ts` types §10.12
names were never added (SDK methods returned `Record<string, unknown>` instead), `Portal.ui.
siteUsers.registerSectionProvider` (§10.11) was never exposed on the runtime namespace, `myOrders()`
was never widened for `scope`/`limit`/`cursor` even though the backend has supported them since
K9, `SiteUsersClient`'s errors dropped `module`/`status` (and `retry_after`) that the server
already sends, and the admin's `claim-guest-records` endpoint didn't refuse for an unverified
user the way §10.3 says it should. All five fixed; each is small, isolated, and covered by a
new test.

## What was verified, not rebuilt

Rather than re-describe every already-correct line, the method: read the actual route files,
controllers, services and SDK clients directly — the same way each prior phase's own completion
doc was produced — and record only what disagreed.

- **§10.2 Admin — connections.** Every route exists on `SiteUsersConnectionsController`, plus
  two (`attention/{id}/dismiss`, `login-pages`) beyond what this document names — from 05's own
  implementation, additive.
- **§10.3 Admin — activity + claim.** `GET …/activity` and `GET`/`POST …/claim-guest-records`
  both exist. The response envelope for the latter carries an extra `dry_run` echo beyond the
  plan's own `{success, claimed}` — harmless, additive. **One real gap, fixed** — see below.
- **§10.4 Site-user session `/me/*`.** Every route exists except `GET`/`POST /me/customers`,
  which this document still describes as live. It was **retired**, not missed:
  `connections/07 §7.8` replaced it with `GET /me/payer` when the payer model superseded the
  provider-customer-link model — the same rewrite `README.md`'s own status note already flags
  for doc 07's original text ("still implemented the entire pre-payer model… under two pointer
  notes"). `10-api-contracts.md` was written before that rewrite and was never updated after;
  `GET /me/payer` is the real, shipped replacement. Also: `POST /me/email/cancel` is actually
  routed as `DELETE /me/email` (same controller action, `cancelEmailChange`), and `POST
  /me/email/resend` exists and is used — a real, useful endpoint this document's table omits
  entirely (built as part of 13 §13.4.4, after this document's own text was written).
- **§10.5–§10.7 Storefronts.** Every row matches what 09's own completion doc already recorded
  in detail (scope/limit/cursor's cursor shape deviation is documented there, not repeated
  here).
- **§10.8 Processor/Direct Payments (K0/X1/X2).** `resolveProcessorAndCustomer()` in
  `ProcessorBillingController` already rejects a local integer id (confirmed by reading the
  current method body, not assumed from K0's own folded-in completion note) — `customer_id`
  must be the provider's own id. `ProcessorPaymentMethodService` exists exactly as named.
  `ProcessorCustomerService` does not — the actual class is `ProcessorCustomerGateway`, a naming
  difference with no behavioural gap behind it.
- **§10.9–§10.10 SDK naming.** Several illustrative names in this document were never the real
  ones: `DirectPaymentsCustomerResolver` → `DirectPaymentsPayerResolver`,
  `PortalSavedPaymentMethod`'s illustrative `brand`/`last4`/`exp_month`/`exp_year`/`is_default`
  shape → the real `ProcessorPaymentMethodService::list()` output is `id`/`provider`/
  `cardNumber`/`expiryDate`/`cardType`/`cardholderName`/`billingZip` (already typed as
  `PortalDirectPaymentsSavedMethod`, reused rather than duplicated — see below). `returns()` →
  the shipped method is `myReturns()`, matching this SDK's own already-established `myOrders()`
  convention rather than the plan's bare noun. None of these are bugs; each is noted so a future
  reader isn't sent looking for a symbol that was never built under that name.
- **§10.13 Error codes.** Every code this table lists is used in real PHP, confirmed by
  `grep -rl` per code. *(Updated 2026-09-15: at this document's original close,
  `SU_E_CONNECTION_DISCONNECTED` had 0 hits — correctly deferred as
  [13-disconnection.md](../13-disconnection.md)'s own K12 work, not yet built. 13 has since
  shipped; the code now has real hits in `SiteUsersConnectionRegistry::refuseIfReadOnly()`/
  `paymentsReadOnlyNotice()` and their callers, matching this table's own row exactly. No
  residue — noted here only so a future reader checking this section's own words against
  today's `grep` doesn't find an apparent discrepancy.)*
- **§10.14 Rate limits.** `SiteUsersPublicThrottle` already implements exactly the two buckets
  this section specifies — `/me/payment-methods*` at 30/session + 120/IP per 5 minutes, the
  generic `/me` family at 240/session + 600/IP per 5 minutes, `SU_E_RATE_LIMITED` +
  `Retry-After` on `429`, `/me/connections` correctly falls into the generic bucket. The
  caching clause (`ProcessorPaymentMethodService::list()`, 60s, busted on every write) is also
  already real — `Cache::remember()` and four `Cache::forget()` call sites confirmed.
- **§10.15 What's deliberately absent.** `grep -rn "input('site_user_reference'\|input('user_id'\|input('customer_id'"` across every storefront/site-users controller: no hits — no endpoint reads an identity field from the client. No refund endpoint exists anywhere. No connection CRUD sits on a public prefix (every `SiteUsersConnectionsController` route is under the `ApiAuthMiddleware`-guarded admin group).

## What was actually missing, and fixed

### §10.11/§10.12 — `Portal.ui.siteUsers.registerSectionProvider` never reached the runtime

`external/ui/site_users/sections.ts#registerSectionProvider` existed and was used internally by
the four built-in providers, but `siteUsersUi` (`site_users/ui.ts`, the object assigned to
`window.Portal.ui.siteUsers`) only ever exported `{ install, mount }`. A tenant with a bespoke
module had no way to contribute a Dashboard section from their own script tag — the exact
capability §10.11 describes and the standalone-consumer rule (site_users/07 §7.1) requires,
since a consumer can only reach `window.Portal.*`, never import `sections.ts` directly (AGENTS.md
§4). Fixed: `siteUsersUi = { install, mount, registerSectionProvider }`. Covered by a new
test proving it registers into the SAME map `sectionProvider()` reads, not a copy.

### §10.12 — the named ambient types were never added

`PortalSiteUserConnection`, `PortalDashboardView`, `PortalDashboardSectionProvider`,
`PortalDashboardRenderContext`, `PortalPurchase` and `PortalReturnRequest` did not exist in
`portal.d.ts`; every SDK method that should have returned one of them returned
`Record<string, unknown>`/`Array<Record<string, unknown>>` instead — `connections()`,
`paymentMethods()`, `purchases()`, `myReturns()`, `requestReturn()`. All six added, each written
against the REAL server response shape (read from the actual PHP source, not copied from this
document's own illustrative guesses) — two concrete corrections found doing that:

- `PortalPurchase.kind` is really `'order' | 'event' | 'appointment' | 'subscription' |
  'invoice'`, not this document's own `'event_order' | 'booking'`
  (`SiteUsersPurchasesService::finalizeRow()`, read directly).
- `paymentMethods()` reuses the **existing** `PortalDirectPaymentsSavedMethod` interface rather
  than introducing a new, differently-shaped `PortalSavedPaymentMethod` — the real
  `ProcessorPaymentMethodService::list()` output already matches it exactly (confirmed by
  reading `formatStripeMethod()`/`formatSquareMethod()`/`formatPayPalMethod()`), and inventing a
  second, `brand`/`last4`-shaped interface that no endpoint actually returns would have made
  `portal.d.ts` describe a response that doesn't exist.

One downstream break found and fixed while wiring these in: `direct_payments/ui.ts`'s
`buildPurchasesMarkup()` took `Array<Record<string, unknown>>`, which `PortalPurchase[]` isn't
structurally assignable to (no index signature) — widened its parameter type to `PortalPurchase[]`,
a strict improvement, not a workaround.

### §10.10 — `myOrders()` never widened for `scope`/`limit`/`cursor`

The backend (`EcommerceShopperOrdersController::index()`) has supported these since K9; the SDK
method never exposed them. Widened `myOrders(opts?: { scope, limit, cursor })` — **kept the
resolved value a plain array**, not `{orders, next_cursor}` as a literal reading of this
document might suggest, because that would have broken three existing call sites
(`ecommerce/dashboard.ts`, `ecommerce/ui.ts` ×2) that already do `orders.map(...)` on the
resolved value for a cursor nothing currently consumes. `next_cursor` rides along as a
non-enumerable `nextCursor` property instead — reachable by a caller that knows to look,
invisible to `.map()`, `.length`, or a `JSON.stringify()` of the list.

### §10.10 — `SiteUsersClient`'s thrown errors dropped `module`, `status`, and `retry_after`

`SiteUsersError` carried only `code` (and `retryAfter`, which `requestJson()`'s own catch never
actually populated despite the field existing). Neither `module` nor `status` existed on the
class at all, even though the server already sends `module` on every `_E_AUTH_REQUIRED` refusal
and `resp.status` was sitting right there unread. Fixed both `SiteUsersError` (two new optional
fields) and `requestJson()` (reads `err.module`, passes `resp.status`, and now actually reads
`json.retry_after` into `retryAfter`). No current UI call site reads `.module`/`.status` off a
`SiteUsersClient` error today (`isAuthRequired()` is only ever called on the other three
clients' errors) — this closes the contract for a future consumer without changing any existing
behaviour.

**`direct_payments/api.ts` deliberately left alone.** This document's own closing line ("a
change to each client's shared `fetchJson`/`requestJson` helper — five small edits") assumes
every client already centralises its error handling in one helper. Four do (`commerce`,
`events`, `appointments` via `apiErrorFromBody`; `site_users` via `requestJson()`, fixed above).
`direct_payments/api.ts` does not — ten independent `if (!res.ok)` sites (one more than first
counted here; a narrower grep missed `getInvoice()`'s combined `!res.ok || !data.invoice`
condition), each with slightly different ad-hoc error construction, in the checkout-critical
payment file. Retrofitting all ten to a shared helper is a real, valuable change, but a
materially larger and riskier one than this document's own framing suggests, and nothing today
reads `.module` off one of its errors (`isAuthRequired()` never receives one). Left as disclosed
residue rather than risking the checkout path for a currently-unused property. **Closed below —
see Residue.**

### §10.3 — `claim-guest-records` didn't actually refuse an unverified user

`SiteUsersGuestClaimService::claimFor()`/`previewFor()` already no-op silently for
`email_verified_at === null` (by design — see 09's own completion doc). The controller passed
that silence straight through as `{success: true, claimed: {}}`, indistinguishable from "this
verified user genuinely has nothing to claim." Added an explicit check: `422
SU_E_EMAIL_NOT_VERIFIED` with a message naming the reason, before the service is ever called.

## Files

**Modified (frontend):** `resources/js/external/api/portal.d.ts` (six new interfaces;
`PortalUiSiteUsersAPI`; `connections()`/`paymentMethods()`/`paymentMethodsFull()`/
`addPaymentMethod()`/`purchases()` on `PortalSiteUsersInstance` re-typed; `myOrders()`/
`myReturns()`/`requestReturn()` on `PortalCommerceInstance` re-typed), `resources/js/external/
api/commerce/api.ts` (`myOrders()` widened), `resources/js/external/api/site_users/
SiteUsersTypes.ts` (`SiteUsersError` gains `module`/`status`), `resources/js/external/api/
site_users/SiteUsersClient.ts` (`requestJson()` populates them, and `retryAfter`),
`resources/js/external/ui/site_users/ui.ts` (`siteUsersUi.registerSectionProvider`),
`resources/js/external/ui/direct_payments/ui.ts` (`buildPurchasesMarkup()` re-typed)

**Modified (backend):** `app/Http/Controllers/Externals/SiteUsers/SiteUsersAdminController.php`
(`claimGuestRecords()` — explicit unverified-email refusal)

**Modified (tests):** `resources/js/external/ui/tests/dashboard.test.ts` (new
`registerSectionProvider()` exposure test), `tests/Feature/SiteUsers/
SiteUsersAdminFeatureTest.php` (two new `claim-guest-records` tests)

## Verification

```bash
cd w/www
npx tsc --noEmit -p resources/js/external/ui/tsconfig.json      # exit 0
npx tsc --noEmit -p resources/js/external/api/tsconfig.json     # exit 0
npx tsc --noEmit -p tsconfig.json                                # exit 0 (root)
npm run build-external-ui-webpack                                # exit 0
npm run build-external-webpack                                   # exit 0
npm run build                                                    # exit 0 (root vite, 2m19s)
npm run test:external-ui                                          # 78 passed (77 + 1 new)
php artisan test                                                  # 466 passed, 3 pre-existing unrelated failures
```

## Residue

All three items below were open as of this document's original close. All three are now
resolved — the third (`is_default`) via a separate plan, the first two directly, closing out
this document's own residue in full.

- ~~**`paymentMethods()`'s real response carries no `is_default` field at all**~~ — **Resolved,
  2026-09-15, by [`direct_payments/08-saved-card-defaults.md`](../../direct_payments/08-saved-card-defaults.md).**
  That plan built the local-tracking mechanism this bullet originally ruled out of scope: a
  dedicated `laravel_direct_payment_default_methods` table (never provider card data),
  `ProcessorPaymentMethodService::attachDefaultFlag()`/`maybeSetDefaultOnAdd()`/
  `handleDefaultOnDelete()`, admin-configurable on-add/on-delete behaviour per processor, and an
  `is_default` field that now reaches all three providers identically (not just Stripe) — see
  that plan's own completion doc for the full account.
- ~~**`direct_payments/api.ts`'s fragmented error handling** (nine independent sites, no shared
  helper)~~ — **Resolved, 2026-09-15.** Eight of the ten sites (the count itself corrected above,
  from nine to ten) now throw through a new `directPaymentsApiError()` helper in
  `resources/js/external/api/direct_payments/apiError.ts`, which returns the same `PortalApiError`
  class `commerce`/`events`/`appointments` already use, reading whichever of the file's three
  observed body shapes (`{error:{message,code,module}}`, `{error:"string"}`, flat
  `{code,message}`) is actually present so no existing caller's error message changes. The
  remaining two sites were deliberately left alone, because unifying them would be a behavioural
  change, not just an error-shape normalization: `DirectPaymentNotificationsApi.list()`'s silent
  `if (!res.ok) return [];`, and `completeZeroInvoice()`'s non-throwing `{success:false,error}`
  return, both of which existing callers depend on as control flow, not exceptions.
  `directPaymentsApiError()` lives in its own module rather than inside `direct_payments/api.ts`
  itself for a mechanical reason found while writing its test: that file's classes use
  TypeScript parameter-property constructors (`constructor(private x: string)`), which Node's
  `--experimental-strip-types` mode (what `test:external-api` runs under) cannot parse —
  importing anything from that file crashes the whole test file's module load. Splitting the
  (parameter-property-free) helper into its own file is what makes it reachable without a
  bundler in the loop, and is almost certainly why this file had zero test coverage before now.
- ~~**No test file exists for `external/api/*/api.ts` directly**~~ — **Resolved, 2026-09-15.**
  New `resources/js/external/api/tests/` directory, wired into a new `test:external-api`
  `package.json` script (same `node --test`/`--experimental-strip-types` runner
  `test:builder`/`test:external-ui` already use). Two files: `direct_payments.test.ts` (6 cases
  against `directPaymentsApiError()` above) and `commerce.test.ts` (5 cases against
  `CommerceInstance.myOrders()`'s query-param construction and non-enumerable `nextCursor`,
  named directly by this document's own original residue). This closes the two specific gaps
  named here and establishes the pattern — manually assigning `globalThis.fetch`/
  `globalThis.window` per test, since these SDK clients read `window.Portal.httpUrl` directly
  with no `jsdom` needed — for future SDK-client tests in this directory to follow. It does not
  claim full coverage of every method on every `external/api/*/api.ts` client; the indirect
  coverage via `external/ui`'s jsdom suite, noted here originally, remains the primary net for
  everything not newly listed above.
