# 08 — Members to Brain: transport, API contracts and screens: completion report (domain name management)

> **Split 2026-10-23.** This completion report covered a step that served both features. It is now two reports: this file is the **domain name management** half; the **website deployment** half is [../../new_way/complete/08-brain-transport-api-and-ui.complete.md](../../new_way/complete/08-brain-transport-api-and-ui.complete.md). Numbers (§, F, D, E) are unchanged.

> **Status: built and tested on the server side (the Brain's signed routes and the members pass-through), and written and type-checked on the browser side; the screens have not been driven in a browser. The members routes are behind a flag that is OFF, so nothing the owner uses today changes.**
> Carried out 2026-10-09 against [08-brain-transport-api-and-ui.md](../08-brain-transport-api-and-ui.md). Nothing is committed. Code is in `w/www` (the Brain) and `w/members`.
>
> **Update 2026-10-09:** the members switch `DOMAINS_USE_BRAIN_SURFACE` described below was replaced by a per-member Brain setting (`new_flow.*`, `complete/00_extras.md` E9). Where this report says "the flag", read "the Brain's answer for that member".
>
> This document says what was actually built, including where it differs from the plan. For questions about current behaviour it supersedes the plan file.

## 1. What the step delivers

Members stops being the place where domain logic lives. Every domains call goes to the Brain over the **signed surface** (`POST /api/internal/brain/domains/…`, `brain.only` then `brain.surface:MEMBERS`); the Brain names the member from the call, checks that **every claim, purchase and website named belongs to that member** (a 404, never a 403, and the same message for "not yours" and "does not exist"), and answers one envelope. The browser never supplies a member, a price or a "paid" flag.

| Plan section | Built as |
|---|---|
| §8.2 channel | the existing `BrainSurfaceClient::post()` (unchanged); `DomainsSurfaceController` in www under `routes/api.php`, **MEMBERS-only** (another app's valid key gets a 401) |
| §8.3 Brain routes | 19 routes, all `POST`: `/list`, `/claims`, `/{claim}/get` (claim + the last 20 events, **whitelisted names, no detail** — it can name servers), `/{claim}/verify`, `/{claim}/nameservers` (new: looks at the delegation now and sets `managed` / `external`), `/{claim}/release` (a purchased domain goes through the release rules of step 05), `/search`, `/purchases`, `/purchases/{ref}/status`, `/purchases/{ref}/confirm` (the browser says it believes it has paid; the saga asks billing), `/{claim}/zone`, `/{claim}/records`, `/{claim}/websites` (202), `…/status`, `…/retry`, `…/cancel`, `…/enable`, `…/disable`, `…/delete` |
| §8.5 errors | the envelope `{success, data}` / `{success:false, code, message, details}`; every refusal carries its `DOM_E_*` code and status; a defect inside a service is `DOM_E_INTERNAL` (500) with **no** message or trace |
| §8.4 members routes | `DomainSurfaceController` in members: derives the member from the verified token, sends only the fields each call needs (a member, a price or a `paid` in the request is dropped; the purchase email is the **token's**), returns the Brain's answer and status unchanged, a Brain that cannot be reached is a plain `DOM_E_UNAVAILABLE` (502) with no internal address; **upserts the `members_websites` mirror** when a deployment is accepted and **on every list** (heals a crash between the Brain's 202 and the write; marks the row inactive when the Brain no longer reports the website); a mirror failure never fails the list |
| §8.6 screens | see §3 — a second code path in the existing screens, switched on by the backend's own answer |
| §8.7 staff | **not built** (P1; see §4) |

## 2. Files

`C:\projects\vagrant\afs-eco\w\www`:
- `app/Http/Controllers/Internal/DomainsSurfaceController.php`, the group in `routes/api.php`.
- `app/Services/Brain/Domains/DomainMemberView.php` (list and detail), `DomainClaimService::checkNameservers()`, `DomainVerifier::delegation()`.
- `tests/Feature/Brain/Domains/Deploy/DomainsSurfaceTest.php` (12).

`C:\projects\vagrant\afs-eco\w\members`:
- `app/Http/Controllers/Brain/DomainSurfaceController.php`; `routes/web.php` (the old group and the new group, chosen by the flag); `config/services.php` (`services.brain.domains_surface`, env `DOMAINS_USE_BRAIN_SURFACE`, **default false**).
- `resources/js/controllers/domains/`: `surfaceTypes.ts` (the answers' shapes), `surfaceRules.ts` (pure rules, tested), `surface.ts` (the typed client, `SurfaceApiError`), `surfaceViews.ts` (the screens), `surface.test.ts` (8 tests); edits to `initDomains.ts`, `api.ts`, `search.ts`, `purchase.ts`.
- `resources/js/templates/views/domain_verify.html` (new), `domain_website.html` (one container added), `resources/js/domains/domains.ts` (the new view is registered), `resources/js/i18n/en.json` (109 keys).
- `tests/Feature/DomainSurfaceControllerTest.php` (12).

## 3. How the screens change, and how both backends coexist

The owner is working on the old shell pipeline, and `createWebsite()` and the old routes are what the old screens call, so **replacing them was not an option before the cut-over (step 10)**. Instead:

- `DOMAINS_USE_BRAIN_SURFACE` (members `.env`, default off) chooses which group of routes `/api/v1/domains` serves: the old `DomainController` (unchanged) or the new pass-through.
- `GET /api/v1/domains` from the new group answers with `api: "brain-surface"`. The screens read that field once, in `loadDomains`: when present, `controller.surfaceMode` is on and each screen hands over to `SurfaceViews`; when absent, **every old code path runs exactly as before**. One build of the UI serves both.

What the surface-mode screens do (08 §8.6):

| Screen | Surface mode |
|---|---|
| list | a **state chip** (Pending verification · Active · Waiting for another account · Lapsed · Suspended · Purchasing), the **mode** (Managed DNS / External DNS), the **site status** (none · being created · failed · removing · online · offline), the **sites name with a copy button**, a row menu built from the state (*Verify*, *DNS settings*, *Website*, *Release*); `has_website` comes from the deployment, never from a zone object; Release asks first |
| add existing | the claim is created on the Brain, then the new **verification view** (`domain_verify`): the TXT record (type, host, value with copy buttons), the optional nameserver step with its warning, **Verify now** which polls every 15 s for up to 5 minutes and shows the reason (translated by the verifier's reason code), and a "I changed the nameservers — check" button |
| search | the Brain's filtered search; each offer carries a server-held quote; the price shown is the server's |
| purchase | one idempotency key per quote (reopening the screen is the same purchase); the Brain creates the purchase and fixes **its** quote's price; **the page builds the invoice with the Direct Payments SDK** (the Direct Payment's purchase item, a dynamic item, at that amount — `createInvoice` / `addItem` / `lock`, as the playground samples do), tells the Brain which invoice it is paying (`/purchases/{ref}/invoice`), charges it with the existing card UI; then `/confirm`; the next screen shows the saga as **Payment received › Registering › Setting nameservers › Done** and polls until it is done. **No `saveDomain(own=1)` from the browser.** |
| DNS | `/zone`: platform records shown **locked** ("Managed by the platform"), member records editable; saving sends the whole member set to `/records`; the publish state and serial are shown and polled; a publish failure is shown; for external DNS a banner says records here are not published. The client-side serial, `createZoneData` and the legacy reader are not used in this mode |

## 4. Where the build differs from the plan text

1\. **A flag, default off.** The plan says to delete the members provisioning half "behind a feature flag (old path stays until cut-over)". The old controller, its routes and `core/scripts` are **untouched**; the new routes replace them only when the flag is on. Nothing was deleted.
3\. **Members routes use REST verbs** (`GET /{ref}`, `PUT /{ref}/records`, `DELETE /{ref}`, `POST /{ref}/website/{retry,cancel,enable,disable}`), as in §8.4; each is forwarded to the Brain as a signed `POST` (the client offers `post()` only — D14 is not needed). The old `POST /` is an alias of `POST /claims` and reads `domain_name`.
4\. **The staff surface (§8.7) is not built** — the contested list, force-verify, transfer, force-lapse, publish-job retry, the deployment detail with the selection explanation, and the kill switches as endpoints. The services behind most of them exist (`contested()`, `transfer()`, `suspend()`, `DnsPublisher::retry()`, `DeploymentService`, the `selection` stored on every deployment); the three kill switches are config (`claims_enabled`, `purchases_enabled`, `deploys_enabled`) with no endpoint to flip them.
6\. **Translations:** the 109 new keys are in `en.json` only; the other five languages have none of them and will show the key text until they are added.
7\. **Brain→members push events (P2)** and **Flutter staff screens (P2)**: not built.
8\. **`/nameservers` is new behaviour**, not just routing: `DomainClaimService::checkNameservers()` reads the delegation through the verifier's resolvers and, for a claim that holds the name, sets `managed` or `external` (and so publishes or withdraws the zone) — the same transition the re-verification sweep already makes.

## 5. Verification

| Result | |
|---|---|
| www `DomainsSurfaceTest` | **12 tests, 123 assertions, all passing**, through the real HTTP stack and the real MariaDB. Unsigned, wrongly signed, stale, **replayed**, and another app's valid key are all 401; a call that names no member is a 422 envelope; a non-Brain app answers 404; create → list → get (event names whitelisted, no `detail`); **every one of the 13 routes that names a claim answers the same 404 for another member** and the same message as for a reference that does not exist, and the owner's claim is untouched; the nameservers call flips managed ↔ external and records the event; the zone and a validated record set (a bad type is refused, an empty body is a 422); search → intent → status → confirm with a browser-supplied price ignored (the stored price is the quote's 1299), a double click returning the same purchase, "I paid" changing nothing, and another member getting 404; a website started (202), the same click answered with the same deployment, polled, finished, listed as `has_website`, disabled, enabled, refused cancel when done (409), deleted; a failed website retried then cancelled; the kill switch (503 `DOM_E_DISABLED`); a defect becomes `DOM_E_INTERNAL` with the exception text **absent** from the body |
| members `DomainSurfaceControllerTest` | **12 tests, 30 assertions, all passing** (Brain faked): the call is signed (the signature is recomputed in the test) and names the token's member; a member, a price and a `paid` in the request are not forwarded; a purchase sends exactly five fields and the **token's** email; the Brain's body and status come back unchanged; an unreachable Brain is a 502 `DOM_E_UNAVAILABLE` with no internal address; no member → 403 and nothing sent; a reference that tries to leave the path, and an unknown website action, are never forwarded; the mirror row is written once for a repeated click, healed on list, retired when the Brain drops the website, and a mirror failure leaves the list intact; exactly one of the two route sets is registered for the flag |
| members `surface.test.ts` (vitest) | **8 tests passing**: chip names, the menu per state (only an active domain can have a website), site status from the deployment, polling stops at the right states, the purchase strip, money from cents, HTML escaping |
| members TypeScript | `tsc --noEmit` with the project's own strict settings (`noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`): **no errors in any `controllers/domains` or `domains/` file**; the errors that remain (94 lines of compiler output) are in other files (`settings/`, `BillingApp.ts`, `conference/`, `views/secondary.ts`, …) that this step did not touch. **Note:** the project's `tsconfig.json` sets `ignoreDeprecations: "6.0"` but the installed TypeScript is 5.9.3, so a plain `tsc -p tsconfig.json` stops at TS5103 in this checkout; the check was run with a temporary tsconfig that overrode that one option (removed afterwards) |
| Whole www Domains folder + guardrail, one run | **230 tests, 3,421 assertions, 8 skipped** (opt-in lab tests) |

## 6. Not verified, honestly

- **No screen was run in a browser.** The compiled-in logic is type-checked and its pure rules are unit-tested, but the DOM code in `surfaceViews.ts` (rendering, clicks, polling, the modals) and the new template were not exercised. The project's rule is that the Vite dev server is never started, and no build was made; the first real look is the owner's.
- **The Direct Payments handoff — corrected 2026-10-09 (`complete/00_extras.md` E11).** As first built, the Brain wrote the invoice itself (an item `domain:<fqdn>` in no catalogue) and the page loaded it with `loadPreparedInvoice()`. That departed from the Direct Payments API and the playground samples and was replaced: the page now builds the invoice with the SDK exactly as the old flow did, and the Brain only verifies. The charge against a real processor was **not run** (it needs a processor account).
- **The members side with a real Brain.** The two halves were tested separately (the members test fakes the Brain; the www test calls the routes with a hand-signed request). They were not run against each other over HTTP.
- **`checkpoint.verify`** is not exercised by the members test (the controller is called with the token's attributes already set).
- **Other languages** (see §4.6).

## 7. To do next, in order

1\. **Open the screens in a browser** with `DOMAINS_USE_BRAIN_SURFACE=true` on a lab members and `WWW_BRAIN_SURFACE_URL` / `_KEY` set (they exist in `.env` already) and look at: the list, add-existing → verify, search → purchase (needs the Direct Payments product), the DNS tab, the website screen. Report what is wrong; the pure rules are tested, the layout is not.
2\. **Staff endpoints** (§8.7) and their Flutter screens (P2).
3\. **Translate the 109 keys** into the other five languages.
