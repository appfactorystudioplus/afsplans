# 08 — Members to Brain: transport, API contracts, and the screens (domain name management)

> **Split 2026-10-23.** This step was written as one plan for domain names *and* website deployment. It is now two plans: this file is the **domain name management** half; the **website deployment** half is [../new_way/08-brain-transport-api-and-ui.md](../new_way/08-brain-transport-api-and-ui.md). Step, section (§), finding (F), decision (D) and report (E) numbers are unchanged, so a reference such as "04 §4.5" or "D19" means the same thing in both folders. Where a section moved to the other half, a pointer stands in its place. The shared channel (§8.1, §8.2) and the domain routes and screens are here; the website routes and screen are in the website-deployment half.

This step settles how the two apps talk (the open blocker D5 of feature 1), what each call means, and what changes in
the domains screens.

## 8.1 Who does what

| | Members (`w/members`) | Brain (`w/www`) |
|---|---|---|
| Identity | Verifies the member's Checkpoint token (`checkpoint.verify`); knows `member_reference_num` | Trusts the signed app, **re-checks that every claim, purchase and website named in a call belongs to the member in the call** |
| Truth | Nothing about claims, zones, servers or deployments | Claims, zones, publish jobs, site references, tenants, deployments |
| Does | Pass-through, UI, the `members_websites` mirror, quota `canUse` in the browser, receipts/mail via the Brain mail surface | Verification, activation, purchase saga, publishing, selection, the deploy runner |
| Never | Reads the Brain's `/config`, writes runtime config files, `popen`s a script, picks a server, builds a tenant spec, calls a registrar, SSHes anywhere | Trusts a client-supplied member, price, or "paid" flag |

This removes `getBrainConfig`, `getRegistrarCredentials`, `getTenantData`, `writeRuntimeConfig`, `createWebsite2`,
`recordProgress` and the whole provisioning half of `DomainController` (≈ 900 of its 1337 lines), leaving the controller
as a thin, authenticated pass-through.

## 8.2 The channel

Use the **signed Brain surface** that already carries billing enforcement: members' `BrainSurfaceClient` →
`https://<brain>/api/internal/brain/…`, middleware `brain.only` then `brain.surface:MEMBERS` (per-app HMAC key,
timestamp, nonce; `WWW_BRAIN_SURFACE_URL` / `WWW_BRAIN_SURFACE_KEY` already exist in members' `.env`). That answers
feature 1's D5 for this area: **domains stops calling the open `/portal/api/v1/brain/*` routes** and stops depending on
`portal.level99` being on or off.

- The client today offers `post()` only, so every call below is a signed `POST` (reads included), the same shape as
  `/billing/enforce`. A `get()` is a small addition if wanted (**D14**).
- Brain-originated events back to members (a claim activated, a deployment finished) use the existing reverse surface,
  `api/internal/www/v1/*` with `www.internal`, in the way `EmailReconcileController` is called.
- The Brain's old `portal/api/v1/brain/dns/*` and `http/*` routes stay for the staff apps; domains simply no longer uses
  them.

## 8.3 Brain routes (all `POST`, under `/api/internal/brain/domains`)

Every request carries `member_reference_num` (from members' verified token) and an `X-Request-Id`; every mutation carries
an `idempotency_key`. Responses are `{success, data}` or `{success:false, code, message, details}`.

| Route | Purpose | Notes |
|---|---|---|
| `/list` | The member's claims with state, mode, reasons, site name, website summary, publish state | Replaces `index()`'s zone fetch + `has_website` join |
| `/claims` | Create a claim | 02 §2.9; uniform response; returns challenge |
| `/{claim}/get` | One claim in full, including events (last 20) | |
| `/{claim}/verify` | Run the verifier now (rate-limited) | Returns the verdict and the reason |
| `/{claim}/nameservers` | Check NS delegation, return what to set | Sets `dns_mode` |
| `/{claim}/release` | Give the name up | 05 §5.5 |
| `/search` | Registrar search through the Brain | Server-side filter, quotes (05 §5.2) |
| `/purchases` | Create a purchase intent | Server price, invoice, name lock |
| `/purchases/{ref}/status` | Poll the saga | |
| `/{claim}/zone` | System + member records, publish state | 04 §4.6 |
| `/{claim}/records` | Replace the member record set | Validated server-side |

Ownership is enforced in one place (a `ResolvesOwnedClaim` helper that looks the claim up by `reference_num` **and**
`member_reference_num` and returns 404 otherwise — the pattern `ownsZone()` has today, kept, but against the Brain's own
table instead of an HTTP round trip). A 404, not a 403, so references are not an enumeration oracle.

## 8.4 Members routes (browser-facing, `/api/v1/domains`, `checkpoint.verify`)

| Route | Maps to | Replaces |
|---|---|---|
| `GET /` | `/list` | `index` |
| `POST /claims` | `/claims` | `store` (the old `POST /` stays as an alias for one release) |
| `POST /{ref}/verify`, `GET /{ref}`, `POST /{ref}/nameservers`, `DELETE /{ref}` | `/verify`, `/get`, `/nameservers`, `/release` | `destroy` |
| `POST /search`, `POST /purchases`, `GET /purchases/{ref}` | `/search`, `/purchases`, `/purchases/{ref}/status` | `search`, `purchase` |
| `GET /{ref}/zone`, `PUT /{ref}/records` | `/zone`, `/records` | `update` (both the whole-zone and `json_patch` forms, which are **removed**) |

## 8.5 Error codes

`DOM_E_VALIDATION`, `DOM_E_RESERVED_NAME`, `DOM_E_NOT_REGISTRABLE`, `DOM_E_RATE_LIMITED`, `DOM_E_CLAIM_CAP`,
`DOM_E_NOT_FOUND`, `DOM_E_STATE` (action not allowed in this state), `DOM_E_VERIFY_PENDING` (carries the reason),
`DOM_E_CONTESTED`, `DOM_E_REQUOTE`, `DOM_E_PAYMENT_UNCONFIRMED`, `DOM_E_REGISTRAR`, `DOM_E_QUOTA`, `DOM_E_NO_CAPACITY`,
`DOM_E_WEBSITE_EXISTS`, `DOM_E_DISABLED` (kill switch). Each has a stable `message` key for i18n and a `details` object that
never contains another member's data.

## 8.6 The screens (all under `resources/js/controllers/domains/`)


| File | Change |
|---|---|
| `initDomains.ts` | List renders from `/list`: a **state chip** (Pending verification · Active · Waiting for another account · Lapsed · Suspended), the **mode** (Managed DNS / External DNS), the **site status**, and the **sites name** with a copy button. The "Managed/Unmanaged" badge driven by `own` goes. The row menu is built from the state: *Verify*, *DNS settings*, *Website*, *Release*. `has_website` is read from the response, never from the zone JSON |
| `search.ts` | "Add existing domain" becomes a two-step: submit → **verification screen** (new view `domain_verify`): the TXT record to publish with copy buttons, the optional nameserver step, a **Verify** button that polls for a few minutes and shows the reason when it fails, and the claim's current state. Search results come from the Brain's filtered search with quotes |
| `purchase.ts` | Creates the **purchase intent** first and pays the server-issued invoice (the direct-payments flow itself is unchanged); then polls the purchase status and shows the saga's state ("Payment received · Registering · Setting nameservers · Done"). **No more `saveDomain(own=1)` from the browser** |
| `dns.ts` | Loads `/zone`: platform records shown **locked** with a "managed by the platform" label; member records editable; saving sends the member set via `PUT /records` and shows the publish state and serial. `createZoneData`, `parseOrNormalizeZoneData` (legacy reader) and the client-side serial are deleted; the server owns the shape |
| `api.ts` | New methods for the routes above; `updateDomainJson` and `saveDomainZone` are removed |
| `resources/js/i18n/en.json` | New keys for states, reasons, steps and errors (the `Localizer` pattern in the README) |

The domains view templates (the HTML fragments those controllers bind to by id) gain: the verification view, the state
chip and reason line, the locked-record styling, the retry/cancel controls and the purchase-state strip.

## 8.7 Staff surface (Brain)

Level-99 endpoints in the existing `Staff*` family: the **contested list** with each claim's evidence; claim detail with its events; force-verify, **transfer** a name between members (audited), force-lapse; publish-job list with retry; the kill switches `domains.claims_enabled` and `domains.purchases_enabled`. The Flutter Brain app can adopt them later; the endpoints come first.

## 8.8 Phases

| Phase | Work |
|---|---|
| **P0** | Signed routes for list/claims/verify/nameservers/zone/records; members pass-through for the same; list, verify view and DNS tab changes |
| **P1** | Purchase routes and screen; release; error-code i18n; staff endpoints (claims, publishing) |
| **P2** | Flutter Brain screens for the staff surface; Brain→members push events replace the polling in the list view |
