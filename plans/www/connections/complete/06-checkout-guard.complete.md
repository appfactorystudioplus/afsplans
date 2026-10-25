# 06-checkout-guard — completion record

**Plan file:** [`../06-checkout-guard.md`](../06-checkout-guard.md)
**Rollout phase:** K5 (the frontend half — `04-connection-registry-and-enforcement.complete.md`
already shipped K5's server-side half, `publicBlock()` on all three public configs)
**Repo:** `w/www`
**Date:** 2026-09-14
**Status:** ✅ §6.1–§6.6 and §6.9's client-side acceptance items are built, tested, and
live-verified in a real browser across all three storefronts. §6.7's cart-merge addition was
already satisfied by K3, but the merge it modifies has no test coverage in either direction —
see the acceptance table. §6.8 (the standalone-site `tokenStorage` story) is infrastructure
only — see "Not implemented."

**Started only once K3 (doc 04's server-side enforcement) had actually shipped.** An earlier
turn in this session flagged the rollout doc's own ordering rule — "K5 after K3, never
before... a gate that says 'sign in' while the server still accepts anonymous requests is
decoration, not enforcement" — and the user asked to hold off. K3 shipped in the interim;
starting doc 06 now is what that hold was waiting for, not a reversal of it.

## What shipped

### §6.2/§6.3 — `shared/authGate.ts` (new)

`gateState()`, `requireSignIn()`, `isAuthRequired()`, exactly as the plan's own code blocks
give them, plus one addition the plan's prose calls for but its code doesn't show:
**`offerOptionalSignIn()`** — see "A real design decision," below, for why it exists.

`SiteUsersBlock` lives in its own new file, `shared/siteUsers.ts`, not in `authGate.ts` or
`site_users/ui.ts` — those two import from each other (`authGate.ts` calls
`buildGateMarkup()`/`wireLoginForm()`; `buildGateMarkup()`'s own signature takes the block
type), so a third, dependency-free home avoids a cycle the plan's own file layout doesn't
mention having to solve.

### §6.3 — `wireLoginForm()` extracted, `site_users/ui.ts`

The exact submit-handler body `renderLogin()` already had, lifted into an exported function
taking a `container` (the element holding both the form and the `[data-su-status]` line) and
an `onSuccess` callback — `renderLogin()` navigates to the dashboard; the gate resolves
`su.me()` and continues the interrupted checkout/registration/booking in place. One
implementation of "submit → `su.login()` → error copy," used by both.

### §6.4 — `buildGateMarkup()`, `site_users/ui.ts`

As given, with one real gap closed only by testing it live: `config.allow_registration` gates
whether *any* registration affordance shows; **`block.register_path`** (separately) decides
whether that affordance is a working link or the honest "Ask the site owner for an account"
text — the plan states both rules in §6.4's prose but its shown code only implements the
first. Missing the second would show a dead `href="#"` link on a module whose pages are
activated but never published.

**Gate styling, found broken by looking at it, not by re-reading the plan.** §6.4 says the
fallback `injectChrome()` "is invoked by the gate so the form is never unstyled." Calling the
real `injectChrome()` would have been wrong: its CSS is scoped to `[data-siteusers-kind]`, an
attribute the gate's host must never carry — `site_users/ui.ts`'s own
`mountAll('[data-siteusers-kind]', mount)` scans the whole document on load and on `pageshow`,
and would treat the gate as a second, real Site Users page view and try to mount a `"No Site
Users module selected"` error into it. Live-tested this exact failure on the first pass (a
plain checkout gate with no styling at all, since nothing scoped to `[data-siteusers-kind]`
ever matched it). Fixed with a new `injectGateChrome()` in `authGate.ts` — the same visual
language, copied and rescoped to `[data-su-gate]`, a hook nothing else scans for.

### §6.4 — the register page's `?redirect=`, `site_users/ui.ts`

`renderRegister()` (the account-creation page, not a storefront's own form) now resolves a
`postRegisterRedirect()` helper on success: a same-origin `?redirect=` path wins; anything
else — including a same-page relative path that resolves off-origin, or a parse failure —
falls back to the dashboard. Matches §6.9's own worked example exactly (verified by a
dedicated jsdom test, not just read).

### §6.5 — `PortalApiError`, all three SDK clients

New `external/api/shared/apiError.ts`: a `PortalApiError` class (`status`, `code`, `module`)
and `apiErrorFromBody()`, one implementation shared by `commerce/api.ts`'s private
`fetchJson()`, `events/api.ts`'s and `appointments/api.ts`'s module-level `fetchJson()` — three
near-identical five-line blocks collapsed into one. `portal.d.ts` gained the matching ambient
`PortalApiError` interface plus a shared `PortalSiteUsersBlock` interface, referenced from
`PortalEventDetail` and `PortalAppointmentSummary`.

### §6.6 — per-storefront wiring, adapted to what each file actually does

The plan's worked examples assume a shape none of the three real files quite has. Each wiring
below is a deliberate adaptation, not a literal transcription — see "Deviations" for exactly
where and why.

- **Ecommerce (`renderCheckout()`)** — fetches `shop.init()` for `store.site_users`, same as
  the plan. `required` mode (or no session state other than guest) gates unconditionally via
  `requireSignIn()`; `guest` mode with nobody signed in mounts the checkout form immediately
  (via a new `mountCheckoutForm()`, the old function body split out) and shows
  `offerOptionalSignIn()`'s one-line link instead. The email field is prefilled and read-only
  when signed in. `begin`'s and `pay`'s own catch handlers both wrap with `isAuthRequired()` →
  the gate, reusing the real `block` fetched at mount (not a synthetic one — see "A real bug,
  found only by testing it"). **`pay`'s wrapping was missing from the first pass** — found on a
  later re-check against §6.5's own "every anonymous-sensitive call" line, not by live-clicking
  through it (the live pass never exercised a full begin→pay round trip). `pay`'s handler calls
  `confirmCheckout`, which reaches the same K3-gated `checkout/complete` endpoint `begin`'s
  handler protects — a session expiring, or a tenant flipping the setting, between the two
  clicks needed the same fallback. Verified afterward by monkey-patching `mountPayment`'s
  `confirm()` to reject with an `EC_E_AUTH_REQUIRED`-shaped error against a real published page;
  no dedicated automated test exists for it (see Residue).
- **Events (`renderRegister()`)** — identical shape: `api.event(eventRef)` now carries
  `site_users`; a new `mountRegisterForm()` (the form body, split out, taking an optional
  `prefill`) is the `onSignedIn`/`onGuest` target. `attendee_name`/`attendee_email` prefill
  from the account and stay editable — the registration row still snapshots whatever is
  submitted (events/02 §2.4), not the account, so signing in never freezes an attendee's
  details. `api.register()`'s own catch also wraps with `isAuthRequired()`.
- **Appointments (`renderBooking()`)** — the one storefront where §6.6 was too terse to copy
  ("Same shape around the service select and slot list") — see "Deviations" for the different
  shape actually built: no up-front page gate, a defense-in-depth gate on the hold/book calls
  themselves, because that is genuinely where this UI's own claim moment is.

### §6.9's client-side acceptance items

| Criterion | Status | Evidence |
|---|---|---|
| `required` mode, no session → gate with no "Continue as guest" | ✅ | Live: ecommerce checkout, events registration, appointments hold — all three, real local data |
| `guest` mode → optional row, not a mandatory gate | ✅ | Live, all three — see "A real design decision" for why this isn't literally `requireSignIn()` called unconditionally |
| Not connected → byte-identical to today | ✅ | `gateState()` returns `not_connected` → `onGuest()` runs with zero gate-host content, structurally guaranteed |
| Signing in through the gate resumes the interrupted flow | ✅ | Live: ecommerce prefills+continues checkout; events prefills+continues registration; appointments retries the *same* hold automatically (`authGate.test.ts`, `appointments.test.ts`'s new suite) |
| Signing in through the gate keeps the cart — line count before == after | ⚠️ not independently verified | This is `EcommerceCartService::resolveCartForShopper()`'s own merge, pre-existing and untouched by this phase (K3 only changed *which* module-scoped identity feeds it, not the merge algorithm). No test exercises it in either direction anywhere in the suite — a pre-existing gap, not one this phase closed or worsened |
| `?redirect=` same-origin wins; cross-origin is ignored | ✅ | `authGate.test.ts` (the gate's own register link) + `site_users/ui.ts`'s `postRegisterRedirect()` (the account page itself) |
| Flipping the setting mid-visit shows the gate via `isAuthRequired()`, not an error string | ✅ | Live: appointments' hold refusal → gate, not a raw error message; `authGate.test.ts`'s `isAuthRequired()` suite (6 tests) |
| The login form is `buildLoginMarkup()` — one definition | ✅ | Unchanged structurally; `wireLoginForm()`'s extraction didn't touch `buildLoginMarkup()` |
| A `required` event's picker doesn't act until signed in | ✅ | This reference UI's `register()` has no separate hold step to gate independently — the up-front page gate (built here) is what stands between an anonymous visitor and the form; the *server's* hold endpoint (a different call this UI doesn't use) was already gated in K3 |
| Prefilled fields are editable, stored on the row not referenced | ✅ | Live: email/name inputs remain plain, editable `<input>`s after prefill, all three modules |
| `su_session` `secure: true` on HTTPS, still `SameSite=Lax` | ✅ | `SiteUsersAuthFeatureTest` (2 new tests) — `config('session.secure')` wins when set, `app()->isProduction()` otherwise |

## A real design decision: `requireSignIn()` is not called unconditionally

§6.3's own `requireSignIn()` code — reproduced faithfully in `authGate.ts` — renders the gate
for *any* `guest`-state visitor, hard or dismissible depending on `guest_checkout`. §6.6's
ecommerce example calls it unconditionally on every page load. Read literally, that means a
full-page sign-in interstitial in front of every anonymous shopper on every `guest`-mode
store — the common case, not the exception — before the checkout form even renders. §6.6's own
prose immediately after that example contradicts its own code: *"In guest mode with a guest
visitor, the form shows a one-line… link that opens the same gate… so no second markup is
needed."* A one-line link cannot come from a function whose first line unconditionally
overwrites the host with the full gate.

Built the prose's behavior, not the contradicting literal code path: each storefront calls
`gateState()` directly first, mounts its form immediately in `guest` mode with nobody signed
in, and offers `offerOptionalSignIn()` — a new, small, shared helper — which renders the exact
same full `requireSignIn()` gate, but only once the visitor clicks. `required` mode still calls
`requireSignIn()` unconditionally, exactly as shown, because there `buildGateMarkup()` omits
the guest button anyway and unconditional-vs-lazy makes no visible difference. This is
disclosed as a deviation, not silently "the plan," because a different, equally defensible
reading exists (follow the literal code, treat the prose as aspirational) and someone revisiting
this later should know which one was chosen and why.

## Deviations from the plan text, found while implementing

- **Appointments has no up-front page gate.** Ecommerce and events both fetch a detail object
  (`shop.init()`, `api.event()`) before rendering their form, so `site_users` is available for
  free. `renderBooking()` never fetched appointment detail at all — only
  `api.services(apptRef)` — because the visitor hasn't committed to anything until they pick a
  slot; the actual claim moment (and where K3's server-side gate actually bites) is the
  `hold()` call on slot click, which this UI already made before this phase. Rather than add an
  unused up-front detail fetch just to build a page gate nothing needs, the fix is
  defense-in-depth at the two calls that matter: `attemptHold()` (new — the slot-click handler,
  refactored so both the initial click and a post-sign-in retry share one implementation) and
  the book button, both wrapped with `isAuthRequired()`. Signing in through either retries the
  *same* hold/booking automatically and prefills name/email — the visitor never has to re-pick
  a slot or retype anything. `api.appointment(apptRef)` is now fetched **alongside** (not
  instead of) `services()`, in parallel, purely so the gate has real `register_path`/
  `login_path` to offer if it ever has to render — seemingly free until the next finding below
  showed it wasn't optional.
- **A real bug, found only by testing it live, not by re-reading the plan.** The first working
  version of every defense-in-depth catch handler (ecommerce's `begin`/`pay`, events'
  `register()`, appointments' `hold()`/`book()`) built a synthetic
  `{connected: true, guest_checkout: false, module}` block from nothing but the error's own
  `module` hint. It rendered a gate — the tests all passed, because the tests asserted a gate
  appeared, not what was *in* it. Live-clicking through appointments' refused-hold path is what
  showed the actual defect: with no `register_path` on the synthetic block, `buildGateMarkup()`
  correctly (by its own logic) fell through to *"Ask the site owner for an account"* instead of
  a working link — silently worse UX than the up-front gate two lines earlier, which uses the
  real block and shows a real link. Fixed by reusing the real block already in scope at each
  catch site (`block` threaded as a new parameter into `mountCheckoutForm()`; `ev.site_users`
  for events, already in scope; `block` from the new parallel `api.appointment()` fetch for
  appointments) and overriding only `guest_checkout`/`connected`/`module` — the fields that
  particular 401 actually tells you something new about. A regression test
  (`appointments.test.ts`) pins the fixed behavior; the other two modules share the identical
  shape and fix but were not separately test-covered (see Residue).
- **`SiteUsersPagePaths` (doc 04) already existed and needed no changes** — doc 06 doesn't ask
  for anything from it beyond the paths `publicBlock()` already resolves.

## Not implemented

- **§6.8 — the standalone-site `tokenStorage` story.** `GateOptions.su` and `gateState()`'s
  `su` parameter exist and are threaded through exactly as the plan shows — a caller that
  constructs `Portal.siteUsers.create(module, { tokenStorage: 'localStorage' })` and passes the
  same instance to the gate, to a future payments resolver, and to a dashboard, would get
  correct, consistent behavior. But no caller does that: none of the three storefronts built
  here ever pass `opts.su` — every real call site lets `requireSignIn()`/`gateState()`
  default-construct a cookie-mode client via `window.Portal.siteUsers.create(block.module)`,
  because a builder-published page is always same-origin with `/portal/api/siteusers/public/*`
  today. The infrastructure is real and tested (`authGate.test.ts` doesn't specifically drive
  the `su` parameter, since nothing calls it yet); the standalone-site scenario itself — and the
  `secure: true`-adjacent `SameSite=None` question §6.8 explicitly defers — remain unbuilt,
  correctly, since no external-site pilot exists yet to build it against.
- **`EcommerceCartService::resolveCartForShopper()`'s module-scoped merge (§6.7).** The plan
  names one addition here: cart merge on sign-in must use the store-scoped user reference
  (`attributableUserReference()`), not any-module identity, so a module-B user signing in on a
  module-A store never merges into a cart that store can never check out against. This was
  **already fixed in K3** (`04-connection-registry-and-enforcement.complete.md`) —
  `EcommerceShopperAuth::siteUserId()`/`siteUserReference()` are module-aware by construction,
  and every checkout call site in this phase already goes through them. §6.7 names no new work
  beyond confirming that; nothing further was needed here.

## Files

**New:**
`resources/js/external/ui/shared/authGate.ts`, `resources/js/external/ui/shared/siteUsers.ts`,
`resources/js/external/api/shared/apiError.ts`,
`resources/js/external/ui/tests/authGate.test.ts` (21 tests)

**Modified (backend):**
`app/Http/Controllers/Externals/SiteUsers/SiteUsersPublicController.php` (`su_session` `secure`)

**Modified (SDK — API clients + types):**
`resources/js/external/api/commerce/api.ts`, `resources/js/external/api/events/api.ts`,
`resources/js/external/api/appointments/api.ts`, `resources/js/external/api/portal.d.ts`

**Modified (UI):**
`resources/js/external/ui/site_users/ui.ts` (`wireLoginForm()` extracted,
`buildGateMarkup()` added, `postRegisterRedirect()` added),
`resources/js/external/ui/ecommerce/ui.ts` (`renderCheckout()` rewritten,
`mountCheckoutForm()`/`offerOptionalSignIn()` call site/`ensureGateHost()` new),
`resources/js/external/ui/events/ui.ts` (`renderRegister()` rewritten,
`mountRegisterForm()` new), `resources/js/external/ui/appointments/ui.ts`
(`renderBooking()`'s hold/book flow rewritten, `attemptHold()` new)

**Modified (tests):**
`resources/js/external/ui/tests/appointments.test.ts` (5 new tests — the gate suite plus the
register-link regression), `tests/Feature/SiteUsers/SiteUsersAuthFeatureTest.php` (2 new tests)

**Build artifacts (committed per this repo's convention):**
`public/external/api/sdk.js`, `public/external/ui/sdk.js`, and the `.d.ts`/`.d.ts.map` files
under `resources/js/external/api/build/`

## Verification

```bash
cd w/www
php artisan test tests/Feature/SiteUsers/SiteUsersAuthFeatureTest.php
php artisan test                                        # 343 passed, 3 pre-existing unrelated failures
npx tsc --noEmit                                         # exit 0 (root — vite-bundled code)
npx tsc --noEmit -p resources/js/external/ui/tsconfig.json    # exit 0
npx tsc --noEmit -p resources/js/external/api/tsconfig.json   # exit 0
npm run build                                            # exit 0 (vite)
npm run build-external-webpack                           # exit 0 (external/api SDK)
npm run build-external-ui-webpack                        # exit 0 (external/ui SDK)
npm run test:external-ui                                 # 48 passed (was 22 before this phase)
```

## Live verification

Real local data, a disposable test `SiteUser` (`usr-gatetest01` on `demo-site-users`, deleted
after), and three real published pages:

- **Ecommerce** — `demo-store-test` connected `required`: the checkout page had no reachable
  `[data-commerce-kind="checkout"]` auto-mount path in this codebase's current state (a
  pre-existing gap unrelated to this phase — see Residue), so a minimal test page was published
  and `Portal.ui.ecommerce.mount()` invoked directly. Confirmed: the hard gate with no guest
  button, the real `demo-site-users` login form, a working "Create an account" link to the real
  published register page with a correct `?redirect=`, signing in clearing the gate and
  mounting the checkout form with the email field prefilled and read-only. Switched to `guest`
  mode: confirmed the form mounts immediately with the one-line optional link, clicking it opens
  the same gate inline, and "Continue as guest" clears it leaving the form untouched.
- **Events** — `demo-tech-conf` connected `required`: `new-page-events-register` (a real
  six-design page) showed the gate **styled entirely by the tenant's own theme CSS** — no
  fallback chrome needed, confirming §6.4's "for free" promise for a page whose CSS already
  carries the Site Users hooks. Signed in; the real registration form mounted with the email
  field prefilled.
- **Appointments** — `demo-appointments` connected `required`: the booking page loaded with no
  gate (correct — nothing to gate until a slot is picked); clicking a slot triggered the hold,
  which the server correctly refused, which rendered the inline defense-in-depth gate exactly
  where the slot list was. This click-through is what surfaced the register-link bug above —
  the first version showed "Ask the site owner for an account" here, which is what led to
  finding and fixing the synthetic-block defect.

All test fixtures (the disposable SiteUser, the test connections, the one test published page)
were removed afterward; `siteusers:connections-audit` confirmed clean.

## Residue

- **Ecommerce's `[data-commerce-kind]` blocks have no generic auto-mount scan.** Unlike
  events/appointments/site_users (`onReady(() => mountAll('[data-*-kind]', mount))`),
  `ecommerceUi.install()` never calls `mount()` for any `[data-commerce-kind]` root — it only
  wires already-rendered chrome (cart badges, favorite buttons, nav hrefs). The real
  `new-page-checkout` page in this local database is a "designed" (six-design) page whose
  `hydrateDesigned()` path for `kind === 'checkout'` does nothing but paint cart totals — it
  does not implement `begin`/`pay` at all, designed or not. How a real ecommerce checkout page
  is supposed to invoke `mount()`/`renderCheckout()` today is a genuine open question this
  phase's own live verification surfaced but did not answer — it is a pre-existing gap in
  ecommerce's own externals-UI wiring (unrelated to connections), not something this phase
  introduced or was asked to fix. The gate itself works correctly once `mount()` runs, which is
  what was verified; *what triggers `mount()` on a real page* is the open question.
- **Only appointments' defense-in-depth fix has a dedicated regression test.** The identical
  bug (and identical fix) was applied to ecommerce's `begin`/`pay` catch and events'
  `register()` catch, but neither module has an existing jsdom test harness anywhere near as
  developed as `appointments.test.ts`'s (which already mocked the full booking flow before this
  phase touched it) — building one from scratch for each, just to duplicate one assertion,
  was judged not worth the size of that addition. Both were verified live instead (see above);
  ecommerce's specifically via the synthetic test page, since no real checkout page could be
  driven to the `begin`/`pay` catch without one.
- **The first version of this document claimed `pay`'s catch was already wrapped** before it
  actually was — an overclaim caught by a later "are you completely done?" re-check that went
  back to §6.5's text line by line ("every anonymous-sensitive call") rather than trusting the
  summary already written. Fixed in place rather than left to stand; recorded here because the
  same live-verification pass that caught the register-link bug did *not* catch this one — the
  live pass never drove a full begin→pay round trip, only a single click into each handler in
  isolation.
- **The synthetic-block defect's root cause — building a fresh state object instead of
  reusing what's already in scope — is a pattern worth watching for elsewhere** in any future
  defense-in-depth wiring this codebase adds; it passed type-checking and a "does a gate
  render" test cleanly both times before live-clicking through it exposed what was actually
  inside the gate.
