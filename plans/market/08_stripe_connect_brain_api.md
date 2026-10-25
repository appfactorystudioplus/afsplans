# Step 08 — Brain: an encapsulated Stripe Connect API (Accounts v2), configurable hosted or self-hosted

**On completion:** write `complete/08_stripe_connect_brain_api_complete.md`.
**Depends on:** [02](02_environment_and_config.md).
**Blocks:** 09, 10, 13, 16.

**Prerequisites — must be in place before the first line of this step:**

| | |
|---|---|
| **`stripe/stripe-php` `^21.2`** installed in `apps/www` | `composer require "stripe/stripe-php:^21.2" --no-scripts --no-interaction`. **Not installed today** (§08.2.1). Pin the major: `lib/V2/Core/Account.php` and `lib/Service/V2/Core/AccountService.php` exist only from 21. |
| The §08.2.4 **dead-code audit**, completed and signed off | Installing the SDK makes `ProcessorSyncController` and `FunctionsController` executable for the first time, including account/coupon deletion calls. Audit and gate before deploying the dependency. |
| [Step 20](20_retire_legacy_stripe_scripts.md) **§20.4** — deployed host inventoried, Stripe webhooks repointed | The legacy tree is gone from this working copy but may still be serving on the host. Repoint webhooks at `/portal/webhooks?type=stripe` before the new SDK path ships. |
| Stripe **test-mode** platform account with Connect enabled, and Accounts v2 access confirmed on it | v2 is *"broadly available to Connect platforms, and to other users in preview."* |

> ## ▶ This is the FIRST step executed, with [09](09_direct_payments_connect_example.md)
>
> Project owner's sequencing: build the external API and the server side, then prove both in the
> playground, **before** any market or members work begins. See [00_README](00_README.md)'s
> execution order — file numbers are identifiers, not sequence.
>
> Order within this step: **§08.5 (external TypeScript) → §08.6 (Laravel services) → §08.7.5
> (webhooks) → ⏸ owner builds `sdk.js` → [09 §09.2](09_direct_payments_connect_example.md) →
> §08.7 (the split charge path) → ⏸ rebuild → [09 §09.3](09_direct_payments_connect_example.md).**
>
> Only brain (`apps/www`) is touched. It may need rework once the split behaviour is seen against
> real Stripe test data — which is exactly why it runs first, against a playground page rather than
> a live checkout.

**Two goals, in order:**

1. An **encapsulated Connect API inside `resources/js/external/`** through which a platform user
   configures Connect — including choosing **hosted** or **self-hosted (custom)** account
   integration.
2. That API **used through Direct Payments and the processors layer**, driven from the members
   dashboard (step 10).

---

## 08.1 The question this step answers, answered

> *"Connected account types has been deprecated so I would like to use version 2, but I want to
> host Stripe connected accounts completely and not defer to Stripe hosted such as using the Express
> dashboard. Is this possible with version 2?"*

**Yes.** Accounts v2 replaces the `type` enum with two orthogonal properties, and full self-hosting
is a first-class configuration rather than a workaround.

| Legacy v1 `type` (deprecated) | Accounts v2 equivalent |
|---|---|
| `custom` | `dashboard: "none"` + `defaults.responsibilities.losses_collector: "application"` |
| `express` | `dashboard: "express"` |
| `standard` | `dashboard: "full"` |

Stripe's own words for `none`: *"The connected account can't access either Stripe Dashboard. Your
platform must provide all Stripe-related functionality."*

Confirmed against the vendored SDK (`stripe-php` **21.2.0**):

- `lib/V2/Core/Account.php` declares `DASHBOARD_NONE`, `DASHBOARD_EXPRESS`, `DASHBOARD_FULL`.
- `lib/Service/V2/Core/AccountService.php::create()` accepts `dashboard?: string` and
  `defaults?: array{..., responsibilities?: array{fees_collector: string, losses_collector: string}}`.
- `lib/Service/V2/Core/AccountLinkService.php::create()` posts to `/v2/core/account_links` with
  `use_case.account_onboarding.{configurations, refresh_url, return_url, collection_options}`.
- `$stripe->v2->core->accounts` is reachable via `lib/Service/V2/V2ServiceFactory.php`.

### The four constraints that shape the rest of this step

**1. `requirements_collector` is derived, not settable.**

> *"In Accounts v2, this value is automatically calculated based on the `losses_collector` and
> `dashboard` values. You can't set it."*

| `losses_collector` | `dashboard` | ⇒ `requirements_collector` | Who collects KYC |
|---|---|---|---|
| `application` | `none` | `application` | **You** |
| `application` | `express` / `full` | `stripe` | Stripe |
| `stripe` | any | `stripe` | Stripe |

So **full white-labeling and Stripe-owned risk are mutually exclusive.** Choosing `dashboard: none`
means taking negative-balance liability. Additional rule: `losses_collector: application` *requires*
`fees_collector: application`.

**2. `dashboard: "none"` does NOT oblige you to build KYC forms.**

> *"Regardless of the `dashboard` setting, your platform can use Connect embedded components."*

Dashboard access and onboarding surface are independent axes. This is the single most useful fact in
this step, and §08.4 is built on it.

**3. Pure API-only onboarding has a hard wall.** `proof_of_liveness` requirements *cannot* be
satisfied through the Accounts API — only Stripe-hosted onboarding, embedded onboarding, or Stripe
Identity. Likewise: *"You can't use the API to respond to Stripe risk reviews."* And API onboarding
obliges you to track regulatory change and re-collect *"at least once every six months."* Stripe
*"strongly recommends"* embedded instead.

**4. Version and v1 fallbacks.** v2 Accounts require `Stripe-Version: 2026-07-29.preview`; the SDK
pins `2026-07-29.dahlia` (`lib/Util/ApiVersion.php`), so the preview version is a per-client
override. `AccountSession` — which powers embedded components — is **v1 only**; there is no
`lib/V2/.../AccountSession.php`. That is fine, because *"you can still pass the ID of a v2 Account to
an Accounts v1 API endpoint."* v1 remains mandatory for OAuth, recipient service agreements,
`treasury`, and `card_issuing_*`.

### The recommendation

**`dashboard: "none"` + Connect embedded components.** Nothing ever sends a seller to
`express.stripe.com`; onboarding, payouts and balances all render inside the members dashboard under
your branding. You keep the white-label outcome without owning form maintenance for every
jurisdiction Stripe supports. Full API onboarding stays available as a configuration option
(§08.4) but is not the default, and §08.4 states plainly what it costs.

---

## 08.2 What exists today — measured, and worse than it looks

### 08.2.1 The Stripe PHP SDK is not installed

Verified in `apps/www`:

| Check | Result |
|---|---|
| `stripe/stripe-php` in `composer.json` `require` | **absent** |
| `stripe` package in `composer.lock` (368 KB, 2026-04-28) | **absent** |
| `vendor/stripe/` | **does not exist** |
| `Stripe` entries in `vendor/composer/autoload_psr4.php` | **0** |
| `core/services/stripe/` | Stripe's *sample apps* repo (`Gemfile`, `demo.gif`, `seed.json`, `subscription-with-fixed-price.png`) — **not** the PHP SDK, and not autoloaded |

`composer.json`'s `autoload` block declares only `App\`, `Database\Factories\` and
`Database\Seeders\` — no `files`, no `classmap`. So `\Stripe\Stripe` is unresolvable.

### 08.2.2 Two incompatible Stripe access patterns, one of which cannot execute

**Pattern A — raw cURL. This is the code that actually runs.** 29 call sites to
`https://api.stripe.com/v1/...` across seven controllers:

`ProcessorIntentController` (`curl_init('https://api.stripe.com/v1/payment_intents')`,
`/v1/setup_intents`, `/v1/checkout/sessions`), `ProcessorCustomerController`,
`ProcessorBillingController`, `ProcessorSubscriptionController`, `ProcessorPlanAdminController`,
`ProcessorDiscountAdminController`, `ProcessorTaxDiscountController`.

**Pattern B — SDK classes. This code fatals on first call.** 30 references across three files:

| File | Calls |
|---|---|
| `ProcessorConnectController.php` | `\Stripe\Stripe::setApiKey` ×4, `\Stripe\Account::create`, `\Stripe\AccountLink::create`, `\Stripe\AccountSession::create`, `\Stripe\Account::retrieve(...)->delete()` |
| `ProcessorSyncController.php` | `\Stripe\Price::all`, `\Stripe\Coupon::all`, `\Stripe\Account::all`, `\Stripe\Price::update`, `\Stripe\Coupon::retrieve(...)->delete()`, `\Stripe\Account::retrieve(...)->delete()` |
| `FunctionsController.php` (legacy) | `\Stripe\StripeClient` ×4, `\Stripe\Webhook::constructEvent`, exception classes |

> ### ⛔ `ProcessorConnectController` has never run
>
> Every one of its five methods throws `Error: Class "Stripe\Stripe" not found` on the first line
> that touches the SDK. The five Connect routes at `routes/portal.php:520–524` are wired to a
> controller that cannot execute. `ProcessorSyncController`'s sync routes are in the same state.
>
> This changes the character of the step. It is not "Connect exists but nothing charges through it."
> It is **Connect has never existed** — what is in the tree is an unexecuted sketch against a
> deprecated API. There is correspondingly nothing to preserve, and no working behaviour to regress.

### 08.2.3 The third problem — the API it sketches is deprecated

`store()` calls `\Stripe\Account::create(['type' => $request->type, ...])` with
`'type' => 'required|in:standard,express,custom'` — the exact enum the brief calls deprecated.
Stripe's own guidance on that page: *"If you are an agent or an LLM, ignore the content on this
page… Instead, try to use the Accounts v2 API."*

Plus two defects to fix in the same pass: `getOnboardingLink()` hardcodes
`$returnUrl = url('/portal/admin')` (its own comment concedes *"A realistic application would use
its own dynamic URLs based on environment"*), and `createSession()` carries the comment *"In a
production app, this endpoint should verify auth from the client's website"* and does not — it mints
an `AccountSession` client secret for **any** id supplied. That is an authorization hole and it
closes here.

### 08.2.5 A working Connect admin UI already exists — this is a migration, not a build

The front end is materially further along than the back end. Measured:

| File | Role |
|---|---|
| `resources/js/portal/admin/html/views/processor-details.html` | Tabler/Bootstrap markup with `data-i18n` attributes. Has a **Connect Accounts section** (`#proc-connect-section`, `#proc-connect-tbody`, `#proc-connect-empty`) and a **New Stripe Connected Account** modal. |
| `resources/js/portal/admin/controllers/processors/ProcessorDetailView.ts` (40.5 KB) | The controller. `openConnectModal`, `handleSaveConnect` (`#proc-save-connect-btn`), `handleDeleteConnect`, `handleOnboardConnect`, `handleEmbedCode`, `renderConnectAccountsTable`. |
| `resources/js/portal/admin/Processors/ProcessorsService.ts` | Typed API client: `getConnectedAccounts`, `saveConnectedAccount`, `deleteConnectedAccount`, `getConnectedAccountOnboardingLink`, plus `ProcessorConnectedAccountRecord` / `ProcessorConnectedAccountPayload`. |

The flow that exists today: open the modal → pick **Account Type** (`standard` / `express` /
`custom`), country, optional email → `#proc-save-connect-btn` → `POST .../stripe/connect` → the row
is pushed into the table → then it branches: `custom` opens `handleEmbedCode()` (a modal showing the
`embedded_ui_code` boilerplate the server generated), anything else opens the AccountLink in a new
tab via `window.open(res.url, '_blank')`.

**So the surface to migrate is:**

```ts
// ProcessorsService.ts — both typed to the deprecated enum
export interface ProcessorConnectedAccountPayload {
    type: 'standard' | 'express' | 'custom';   // ← the deprecated account-type model
    country: string; email?: string;
    processor_reference_num: string;
}
export interface ProcessorConnectedAccountRecord {
    account_type: 'standard' | 'express' | 'custom';  // ← same
    stripe_account_id: string; status: string; embedded_ui_code: string | null; …
}
```

These two interfaces are the contract, and they encode the deprecated model directly. Changing them
is the visible edge of the v2 migration: `type` becomes `integration_mode`
(`self_hosted` / `express` / `full_dashboard`), and the record gains `dashboard`,
`requirements_collector`, `charges_enabled`, `payouts_enabled`, `api_version`.

> **The `<select id="conn-type">` in the modal is the deprecated enum rendered as UI.** Its three
> options are Standard / Express / Custom (Embedded UI). Relabel to the v2 mode names and change the
> values; the mapping is 1:1 (§08.3), so no option is lost.

Two observations worth carrying forward:

- **`embedded_ui_code` anticipates embedded components but is not them.** The server *generates
  boilerplate HTML/JS* for the admin to copy (`generateBoilerplate()` in `ProcessorConnectController`).
  Real embedded components mount a live `AccountSession` in the page. The generated-code path can
  stay as an admin convenience, but §08.4's embedded mode is a different mechanism, not an
  evolution of it.
- **This admin UI and the members seller dashboard are two different consumers of one API.** The
  admin one (here) is for the platform operator creating and inspecting accounts. The members one
  (step 10) is for a seller onboarding themselves. Both call the same endpoints; only the surface
  differs. Keep them that way rather than sharing components between two dashboards with different
  audiences.

### ⚠️ `resources/js/portal/admin/schemas/` is deprecated — do not extend it

`payments_processor_schema.ts` is a JSON-schema form definition that **nothing imports**. Verified:
the only occurrences of `schema_payments_processor` / `payments_processor_schema` in the repository
are its own declaration and its `.d.ts`.

Extending that schema would produce a form nobody renders. The live admin UI is the
HTML-view + controller + service triple above. The whole `schemas/` directory appears to be a
superseded approach; treat it as read-only history.

### 08.2.4 Decision: install the SDK, do not extend the cURL pattern

**Add `"stripe/stripe-php": "^21.2"` to `apps/www/composer.json` and `composer update stripe/stripe-php`.**

Reasons, specific to this step rather than general preference:

- **v2 payloads are too large to hand-roll.** `AccountService::create()`'s param typehint is
  thousands of characters — `identity.business_details.documents.proof_of_ultimate_beneficial_ownership.signer.person`
  is a real path. Form-encoding that by hand into cURL, correctly, repeatedly, is a defect generator.
- **The SDK knows the v2 transport.** `$stripe->v2->core->accounts` posts JSON to `/v2/core/...`,
  not form-encoded to `/v1/...`. The existing cURL helpers assume v1 form encoding and would each
  need a v2 variant.
- **`fieldEncodings()`.** `lib/V2/Core/Account.php` declares
  `identity.individual.relationship.percent_ownership` as a `decimal_string`. Getting that wrong by
  hand produces a silent ownership-percentage bug in a KYC payload.
- **Webhook signature verification is security-critical.** `Webhook::constructEvent` and
  `WebhookSignature` are not code to reimplement, and v2 events additionally need
  `parseEventNotification` / `EventNotification`.
- Version 21.2.0 pins API `2026-07-29.dahlia` and supports a per-request `stripe_version` override
  and `rawRequest()` — both needed for the `2026-07-29.preview` header v2 accounts require.

**The seven cURL controllers may be refactored, and probably should be.** Earlier drafts of this plan
told you to leave them untouched to avoid risk. That constraint came from assuming live tenants;
there are none (step 01 §01.2.4), and the project owner's architecture rule (D6) puts processor
management in **Laravel service classes**, not in controllers doing `curl_init` inline.

So the preference is: move provider calls out of the controllers into
`app/Services/Processors/{Stripe,PayPal,Square}Gateway.php` behind a common interface, with the
controllers reduced to HTTP shape — validate, delegate, respond. Do it where a controller is already
being touched for Connect, rather than as a separate sweep.

What matters is the boundary, not the transport: a gateway class may use the SDK or raw cURL
internally, as long as no controller and nothing in `resources/js/external/` talks to a provider
with a secret key.

> ### ⚠️ Installing the SDK reanimates dead code — audit before deploying
>
> Once `stripe/stripe-php` autoloads, `ProcessorSyncController` and `FunctionsController` stop
> fatalling and **start executing**. Code that has never run in this application will run.
>
> `ProcessorSyncController` alone contains `\Stripe\Account::retrieve($res['id'])->delete()`,
> `\Stripe\Coupon::retrieve($res['id'])->delete()` and `\Stripe\Price::update($res['id'], ['active' => false])`
> — destructive calls reachable from `POST /api/v1/processors/{reference_num}/sync/execute`.
>
> **Before the dependency lands:** read every `\Stripe\` call site in those two files, confirm each
> is correct and intended against a **test-mode** key, and gate or disable
> `ProcessorSyncController`'s execute route until it has been reviewed. Do not discover this in
> production. This audit is a checklist item in §08.11 and is not optional.

---

## 08.3 The configuration model — a processor setting, configured in the dashboard

**This is the first thing built in the whole plan** (00_README phase 1). Nothing else can be
configured or tested until a Stripe processor can be set up for Connect and the settings read back
out of the database.

### Where it lives

`laravel_processors.extra_config` — the existing JSON column the `Processor` model already casts to
array and already uses for provider extras (`$processor->extra_config['webhook_id']` in
`handlePayPal()`). Adding a `connect` key follows the established precedent rather than inventing a
parallel store.

```jsonc
// laravel_processors.extra_config
{
  "webhook_id": "...",                    // existing, PayPal
  "connect": {                            // MARKET_MODIFIER_ADDITION
    "enabled": true,
    "integration_mode": "self_hosted",    // self_hosted | express | full_dashboard
    "onboarding_mode": "embedded",        // embedded | hosted | api
    "account_configurations": ["merchant", "recipient"],
    "responsibilities": { "fees_collector": "application", "losses_collector": "application" },
    "default_country": "US",
    "default_currency": "usd",
    "capabilities": ["card_payments", "stripe_balance.payouts", "stripe_balance.stripe_transfers"],
    "charge_model": "auto",               // auto | destination | separate_transfers
    "platform_fee_percent": 10,
    "onboarding_return_path": "/services/assetstore?connect=return",
    "onboarding_refresh_path": "/services/assetstore?connect=refresh",
    "branding": { "icon": null, "logo": null, "primary_color": null, "secondary_color": null }
  }
}
```

**`platform_fee_percent` lives here**, not in any `.env` (step 02 §02.4.2). It is per-processor
because brain is multi-tenant: the marketplace processor charges 10%, a tenant's own processor
charges nothing.

> **A dedicated `processor_connect_profiles` table was considered and rejected.** `extra_config` is
> already the provider-extras bucket, the dashboard is already schema-driven over the processor
> record, and a second table would mean a second editing surface for one logical object. If
> per-field querying is ever needed (reporting across tenants by fee rate, say), promote it then —
> the JSON is a superset of any column set extracted from it later.

### Configuring it — extending the existing admin UI

**Not** `schemas/payments_processor_schema.ts` — that file is dead (§08.2.5). The live surface is:

| File | Change |
|---|---|
| `portal/admin/html/views/processor-details.html` | Add a **Connect Settings** panel to the processor detail page: enabled toggle, integration mode, onboarding mode, collectors, country, currency, configurations, charge model, **platform fee percent**, onboarding return/refresh paths. Same Tabler markup and `data-i18n` attributes as the surrounding page. |
| `portal/admin/controllers/processors/ProcessorDetailView.ts` | Load and save the panel alongside the existing processor fields. Relabel the `#conn-type` select in the create-account modal to the v2 modes. |
| `portal/admin/Processors/ProcessorsService.ts` | `ConnectProfile` type + `getConnectProfile` / `saveConnectProfile`; migrate `ProcessorConnectedAccountPayload.type` → `integration_mode`. |
| `ProcessorAdminController` | Validate the `connect` block through `ConnectProfileService` before persisting to `extra_config`. |

Validation happens at **configuration** time, not at account-creation time, so an invalid
combination is rejected in the form where it can be explained — rather than surfacing later as a
Stripe API error with no obvious connection to the setting that caused it.

The Connect Settings panel and the existing Connect Accounts table sit on the same page, which is
the right relationship: the panel is the template, the table is what has been created from it.

### The mode mapping

`integration_mode` → the v2 `dashboard` value, and the `requirements_collector` that Stripe derives
from it:

| `integration_mode` | `dashboard` | `losses_collector` | ⇒ derived `requirements_collector` | Seller sees |
|---|---|---|---|---|
| `self_hosted` | `none` | `application` | `application` | Only your UI |
| `express` | `express` | `application` or `stripe` | `stripe` | Express dashboard |
| `full_dashboard` | `full` | `stripe` | `stripe` | Full Stripe dashboard |

### Validation — enforced at configuration time

Each rejects in the dashboard, not later:

- `losses_collector: application` ⇒ `fees_collector: application` must also be set.
- `integration_mode: self_hosted` ⇒ `losses_collector: application`, otherwise
  `requirements_collector` silently resolves to `stripe` and the white label is not achieved.
- `onboarding_mode: api` ⇒ a recorded acknowledgement of §08.4's constraints, stored with timestamp
  and actor.
- `platform_fee_percent` between 0 and 100, two decimal places.
- `account_configurations` must include `recipient` when the marketplace charge models are used —
  `stripe_balance.stripe_transfers` is what destination and separate-transfer charges require.

### What is immutable, and why the record matters

`integration_mode` and `dashboard` are **fixed on a Stripe account at creation**. Editing the
processor's profile afterwards changes what *new* accounts get; it cannot change existing ones.

So the profile is a **create-time contract**, and each connected account records the values it was
actually created with (§08.6's `processor_connected_accounts` columns: `dashboard`,
`fees_collector`, `losses_collector`, `requirements_collector`, mirrored from the API response
rather than computed locally).

The dashboard must say so at the point of editing — otherwise the natural assumption is that
changing the setting changes behaviour, and the resulting bug is invisible until a seller's account
behaves unlike the configuration that appears to govern it.

### Reading it

`ConnectProfileService::for($processor)` returns a validated profile object with defaults applied for
any absent key. **Every consumer goes through it** — controllers, `ConnectAccountService`,
`SplitCalculator`, `TransferExecutor`, and the externals API's profile endpoint. Nothing reads
`extra_config['connect']` directly, so the defaults and validation exist once.

A processor with `connect.enabled` false, or no `connect` key at all, is a **non-Connect processor**:
it behaves exactly as it does today, and no Connect surface is offered for it anywhere. That is what
keeps tenant processors unaffected.

## 08.4 Onboarding modes

Chosen independently of `integration_mode`, per §08.1's constraint 2.

### `embedded` — the default, and the recommendation

Connect embedded components rendered in your page via `ConnectJS` and a v1 `AccountSession`
(v1 endpoint, v2 account id — supported and documented). Components relevant here:
`account_onboarding`, `account_management`, `notification_banner`, `payouts`, `balances`,
`documents`, `payment_disputes`. Every one is confirmed present in the SDK's
`AccountSessionService::create()` params.

Two features worth setting deliberately:

- `features.disable_stripe_user_authentication` — with `dashboard: none` there is no Stripe login to
  authenticate against, and the docs note *"for connected accounts without Stripe dashboard access
  (custom accounts), no user authentication is required in production."*
- `features.external_account_collection: false` when you collect bank details through another
  provider. Leave it `true` unless you have one.

Seller never leaves your site. Stripe owns form correctness and compliance updates.

### `hosted` — Stripe-hosted Account Links

`POST /v2/core/account_links` with `use_case.type = "account_onboarding"`,
`use_case.account_onboarding.{configurations, refresh_url, return_url, collection_options.fields}`.
Redirects the seller to Stripe and back. Lowest effort, least branding. Also the escape hatch when
embedded cannot satisfy a requirement.

Even with `integration_mode: self_hosted` this is legal and sometimes necessary — a
`proof_of_liveness` requirement is the common case.

### `api` — you build every form

Read `requirements.entries[]` / `currently_due`, render your own fields, PATCH the account.
Available, and **not** the default. The profile must surface these costs before it can be selected:

- Cannot satisfy `proof_of_liveness`. You will still need hosted, embedded, or Stripe Identity
  (`VerificationSession` with `related_person`) as a fallback path — so `api` mode is really
  "api plus a fallback", never "api only". Build the fallback in the same step or the mode is a trap.
- Cannot respond to Stripe risk reviews.
- Obliges tracking regulatory change and re-collection at least every six months.
- Handling government IDs and tax identifiers yourself brings its own regulatory and security
  obligations.

Consider `account_token` (`lib/Service/V2/Core/AccountTokenService.php`) if you take this path — it
moves PII from the browser to Stripe without it landing in your process. Required for platforms in
France; recommended elsewhere.

---

## 08.5 The encapsulated externals API — goal 1

### 08.5.1 Connect lives *behind* Direct Payments, not beside it

**Project owner's rule.** Processors are not addressed directly by client code; they are reached
through the Direct Payment that references them. Connected accounts are a processor capability, so
they are reached the same way:

```js
var dp = window.Portal.directPayments.create('eq4W10iF8');
await dp.initializeDirectPayment();

var profile = await dp.connect.getProfile();     // no processor ref — the DP already knows
```

**Not a top-level `window.Portal.connect`.** A global would need a `processorRef` argument on every
method — a value the Direct Payment instance already resolves. Two resolution paths for one value is
two chances to disagree, and a caller could end up operating on one processor's accounts while
charging through another's.

The existing code already establishes the seam:

| Fact | Where |
|---|---|
| `directPaymentsApi` is a **factory**; `create(dpId)` returns a per-DP instance | `direct_payments/api.ts:286`, `:1874` |
| `laravel_direct_payments.processor_reference_num` links a DP to a processor | `App\Models\Externals\DirectPayment` `$fillable` |
| `initializeDirectPayment()` caches `record.processor_reference_num` per DP | `api.ts:534` |
| The instance holds `_processorClient` and `processorsBackend` | `api.ts:312`, `:317` |

So `dp.connect` is a lazily-constructed accessor on the instance that closes over the already-resolved
processor. **No new resolution logic, no new argument.**

A DP additionally declares *what it is for* through `pay_type`; **§08.5.7 adds `CONNECT`** alongside
`SINGLE_PAY` and `SUBSCRIPTION`, which is what makes a Direct Payment charge with a split.

### 08.5.2 The chain, end to end

```
  Processor dashboard          admin creates a processor, configures its
  (authenticated, www)         connect block (§08.3)
            │
            ▼
  Direct Payment admin         admin assigns that processor to a Direct Payment
  (authenticated, www)         → laravel_direct_payments.processor_reference_num
            │
            ▼
  window.Portal.directPayments.create(dpRef)
            │                  client resolves the DP; the DP resolves the processor;
            ▼                  the processor carries the connect profile
        dp.connect             provider-agnostic connected-accounts API
```

The client never names a processor, never names a provider, and never sees the connect profile's
server-only fields. It names a Direct Payment. Everything else is resolved server-side from that.

> ### ✅ Verified — the whole chain already exists. No work needed here.
>
> | Link | Evidence |
> |---|---|
> | Admin picks a processor for a DP | `portal/admin/html/views/directpayments-form.html:34` — a `<select id="dpProcessor">` with *"Select an active processor…"* |
> | Populated from the processors API | `portal/admin/controllers/direct_payments/directpayments.ts:205` — `fetch('/portal/api/v1/processors')`, with a cached `app.extra.processors` fallback at `:237` |
> | Persists and round-trips on edit | `:222–223` — restores `existingDp.processor_reference_num` as the selected value |
> | Visible in the DP list | `:66` — `${dp.processor_reference_num || 'Unknown'}` |
> | Stored | `laravel_direct_payments.processor_reference_num`, `$fillable` on `App\Models\Externals\DirectPayment` |
> | Read by the SDK | `direct_payments/api.ts:534` — cached into sessionStorage on `initializeDirectPayment()` |
>
> So `dp.connect` has a resolved processor the moment `initializeDirectPayment()` returns, through
> machinery that predates this plan. Nothing in phase 1 needs to build or modify it.
>
> ### ⚠️ Two defects in that assignment UI — fix while you are in it (task 1.5)
>
> **1 — Nothing filters on `is_active`, despite the label.**
>
> ```php
> // ProcessorAdminController::index() — no filter
> $processors = Processor::orderBy('created_at', 'desc')->get();
> ```
> ```ts
> // directpayments.ts:215 — no filter either
> let html = '<option value="">Select an active processor...</option>';
> processors.forEach((p: any) => { html += `<option …>` });
> ```
>
> `toAdminArray()` already returns `is_active` — the data is present and simply unused. So an
> inactive processor can be assigned to a Direct Payment, and the failure surfaces at **charge
> time**, where queries like `handleStripe()`'s `where('is_active', true)` skip it — far from the
> assignment that caused it.
>
> **Fix client-side, not in `index()`.** That endpoint also feeds the processor *list* view, which
> must show inactive processors so they can be activated. One line in the DP form:
>
> ```ts
> processors.filter((p: any) => p.is_active).forEach(…)
> ```
>
> **2 — The fallback path stores the wrong identifier.**
>
> ```ts
> value="${p.reference_num || p.id}"   // :216  primary  → "aB3cD7e"
> value="${p.id || p.reference_num}"   // :240  fallback → "7"
> ```
>
> `p.id` is always truthy, so when the `fetch` fails and the cached `app.extra.processors` path runs,
> the option value is the numeric primary key. It is then saved into `processor_reference_num`, which
> matches no processor. Reachable only on the error path, which is why it has survived. Make both
> expressions `p.reference_num || p.id`.
>
> Neither defect blocks phase 1 — assign an active processor by hand and everything works. Both are
> one-line fixes in code task 1.4 already touches, and defect 2 in particular produces a Direct
> Payment that looks correctly configured and is not.

### 08.5.3 Provider-agnostic by construction

The API must not care which provider backs the Direct Payment. Only Stripe implements connected
accounts today; PayPal (Multiparty / Partner Referrals) and Square (OAuth-connected sellers) have
comparable capabilities, and the interface is shaped so adding one is an implementation, not a
redesign.

This mirrors the pattern already in `external/processors/`: `IProcessorClient` implemented by
`StripeClient`, `PayPalClient`, `SquareClient`. Connect follows it:

```
external/connect/
├── ConnectTypes.ts          provider-neutral types + IConnectProvider
├── ConnectApi.ts            the dp.connect surface; delegates to a provider impl
├── providers/
│   ├── StripeConnectProvider.ts    Accounts v2, AccountSession, ConnectJS
│   ├── PayPalConnectProvider.ts    stub — reports unsupported
│   └── SquareConnectProvider.ts    stub — reports unsupported
├── EmbeddedHost.ts          provider-agnostic component mounting
└── CONNECT_DOCUMENTATION.md
```

**Naming rule:** nothing provider-specific in the public surface. `ConnectedAccount`, not
`StripeAccount`. `account.reference_num`, not `stripe_account_id`. `integration_mode`, not
`dashboard`. Provider vocabulary stays inside the provider implementation and inside the server.

**Unsupported providers degrade, they do not throw.** `dp.connect` always exists; on a PayPal-backed
Direct Payment it reports unsupported and every method returns a typed refusal:

```js
if (!dp.connect.isSupported()) {
    // { supported: false, provider: 'paypal', reason: 'connected_accounts_unsupported' }
    show(dp.connect.support().reason);
    return;
}
```

Without this, every caller needs a provider `switch`, which is exactly the coupling the layer exists
to remove.

### 08.5.4 The surface

```ts
/** MARKET_MODIFIER_ADDITION */
interface PortalDirectPaymentConnectAPI {
  // ── Capability ────────────────────────────────────────────────────────
  isSupported(): boolean;
  support(): { supported: boolean; provider: string; reason?: string };

  // ── Configuration — READ ONLY (§08.5.5) ───────────────────────────────
  getProfile(): Promise<PublicConnectProfile>;
  validateProfile(p: ConnectProfileInput): ConnectProfileValidation;   // pure
  getCapabilityCatalog(country: string): Promise<CapabilityDescriptor[]>;

  // ── Accounts ──────────────────────────────────────────────────────────
  createAccount(opts: ConnectCreateAccountOptions): Promise<ConnectedAccount>;
  getAccount(accountRef: string): Promise<ConnectedAccount>;
  getStatus(accountRef: string): Promise<ConnectAccountStatus>;
  listAccounts(): Promise<ConnectedAccount[]>;
  closeAccount(accountRef: string): Promise<{ success: boolean }>;

  // ── Onboarding — one call, mode- and provider-agnostic ────────────────
  startOnboarding(accountRef: string, opts?: StartOnboardingOptions): Promise<OnboardingHandle>;
  mountOnboarding(handle: OnboardingHandle, target: string | HTMLElement): Promise<ConnectMount>;
  mountComponent(accountRef: string, component: ConnectComponentName,
                 target: string | HTMLElement): Promise<ConnectMount>;

  // ── Requirements (api onboarding mode) ────────────────────────────────
  getRequirements(accountRef: string): Promise<ConnectRequirement[]>;
  submitRequirements(accountRef: string, payload: Record<string, unknown>): Promise<ConnectAccountStatus>;
  uploadDocument(accountRef: string, purpose: string, file: File): Promise<{ file_id: string }>;

  // ── Money ─────────────────────────────────────────────────────────────
  getBalance(accountRef: string): Promise<ConnectBalance>;
  listPayouts(accountRef: string, params?: { limit?: number }): Promise<ConnectPayout[]>;
  requestPayout(accountRef: string, amountMinor: number): Promise<ConnectPayout>;
}
```

Note what is **absent**: no `processorRef` on any method, and no `saveProfile` (§08.5.5).

`OnboardingHandle` remains the encapsulation that makes onboarding mode-agnostic:

```ts
type OnboardingHandle =
  | { mode: 'embedded'; client_secret: string; components: string[] }
  | { mode: 'hosted';   url: string; expires_at: number }
  | { mode: 'api';      requirements: ConnectRequirement[]; liveness_fallback?: OnboardingHandle };
```

so every consumer writes one branch, and changing the mode in the Processor dashboard changes
behaviour with no code change:

```js
var handle = await dp.connect.startOnboarding(accountRef);
if (handle.mode === 'hosted') window.location.href = handle.url;
else await dp.connect.mountOnboarding(handle, '#connect-onboarding');
```

### 08.5.5 The public SDK cannot write processor configuration

`sdk.js` is loaded by every tenant website and is reachable from any browser console. A profile write
from there would let an anonymous caller change the platform fee, flip `losses_collector`, or switch
a processor's integration mode.

**Writes are an admin operation** and stay in the authenticated Processor dashboard
(`portal/admin/Processors/ProcessorsService.ts` → `ProcessorAdminController`). Two clients, two trust
levels, never merged.

`getProfile()` returns a narrowed projection:

```ts
interface PublicConnectProfile {
  enabled: boolean;
  integration_mode: 'self_hosted' | 'express' | 'full_dashboard';
  onboarding_mode: 'embedded' | 'hosted' | 'api';
  default_country: string;
  default_currency: string;
  platform_fee_percent: number;    // sellers must see what they are charged
  account_configurations: string[];
}
```

Excluded: `responsibilities` (liability allocation is not a caller's business), branding, and the
return/refresh paths — anything only the server acts on. `validateProfile()` stays because it is
pure: rules, no data.

### 08.5.6 Type declarations

`PortalDirectPaymentsAPI` (the per-DP instance type) gains a `connect: PortalDirectPaymentConnectAPI`
property, in **both** `external/portal.d.ts` and `apps/market/resources/js/portal.d.ts`. The two must
move in the same commit as the runtime — market type-checks against its copy while brain builds the
implementation, and drift there is a type-safe call into a method that does not exist.

### 08.5.7 A third `pay_type`: `CONNECT`

**Project owner's design.** `pay_type` already selects which configuration a Direct Payment exposes
to the outside world. `CONNECT` extends that pattern to connected accounts, and is offered only when
the assigned processor is Stripe.

#### What `pay_type` is today, measured

| | Value | Child config | Source of what is sold |
|---|---|---|---|
| Existing | `SINGLE_PAY` | `laravel_direct_payment_items` | Admin-defined items and prices |
| Existing | `SUBSCRIPTION` | `laravel_direct_payment_tiers` | Tiers pulled from the processor, admin selects which are exposed |
| **New** | **`CONNECT`** | **`laravel_direct_payment_connect`** | **Line items supplied per-checkout by the calling application; paid out to connected accounts** |

Mechanics as they stand:

- The column is `$table->string('pay_type')` — **a plain varchar, not a database enum.** Adding a
  value therefore needs **no migration** on that table. The comment `// SINGLE_PAY | SUBSCRIPTION`
  is documentation, not a constraint.
- `#dpPayType` in `directpayments-form.html:39` holds two `<option>`s.
- `DirectPaymentsController:140` does `$request->input('pay_type', '')` with **no validation** —
  any string is accepted today. Worth tightening to `in:SINGLE_PAY,SUBSCRIPTION,CONNECT` in the same
  pass.
- The SDK exposes `isSinglePay()` / `isSubscription()` (`api.ts:627`, `:633`) and branches on
  `dpConfig.pay_type` at `:709` / `:726`, falling through to
  `throw new Error('Unrecognized Direct Payment payload format.')`.

#### The problem `CONNECT` solves, which the plan had not yet confronted

`Invoice.addItem()` validates every `item_ref` against the DP's **static configured list**:

```ts
let item = (this._dpConfig.items || []).find(i => i.reference_num === options.item_ref);
if (!item) item = (this._dpConfig.tiers || []).find(t => t.reference_num === options.item_ref);
if (!item) throw new Error(`Item reference "${options.item_ref}" not found in Direct Payment configuration.`);
```

**A marketplace cannot work this way.** Sellers create assets and gigs continuously; market would
have to write a DP item for every listing and keep the two in sync forever. `CONNECT` is the right
place to fix this rather than special-casing `SINGLE_PAY`.

So the defining property: **a `CONNECT` DP has no static item list. Line items arrive per checkout.**

#### Where dynamic line items come from — server, not browser

If the browser supplies `{price, seller_ref}` and brain accepts it, anyone can buy a $40 asset for
$0.01. So for `CONNECT`:

> **The invoice is created server-to-server.** Market's Laravel side builds the invoice from its own
> `order_items` — prices it already holds — and posts it to brain over the signed internal channel
> (step 04's HMAC). The browser receives only an **invoice reference** and pays it. Prices never pass
> through the client at all.

That is D6 exactly — client proposes nothing, server disposes — and it reuses the signing this plan
already specifies. Two rejected alternatives, recorded so they are not re-proposed:

- *Client sends signed line items.* Works, but puts a signing secret near the browser or requires a
  round trip to fetch a signature — the round trip is the server call above, minus the safety.
- *Brain trusts amounts, market reconciles afterwards.* Detects theft after the goods are delivered.

Step 13's checkout changes accordingly: `POST /checkout/prepare` (market server → brain, signed)
returns an invoice ref; the browser then runs
`dp.setupSinglePayPayment({ invoice_reference_num })` and `confirmPayment()`. No `addItem` loop in
the browser.

#### Schema

Only one new table — plus nothing at all on `laravel_direct_payments`, since `pay_type` is a varchar.

```php
// laravel_direct_payment_connect — one row per CONNECT DP   MARKET_MODIFIER_ADDITION
$table->id();
$table->string('direct_payment_reference_num')->unique();   // FK → laravel_direct_payments
$table->string('charge_model')->default('auto');            // auto | destination | separate_transfers
$table->decimal('platform_fee_percent', 5, 2)->nullable();  // null ⇒ inherit the processor's
$table->boolean('allow_multi_seller')->default(true);
$table->boolean('dynamic_items')->default(true);            // false would restore a static list
$table->string('item_source')->default('application');      // application | configured
$table->json('exposed_account_refs')->nullable();           // null ⇒ all of the processor's accounts
$table->timestamps();
```

`exposed_account_refs` is what makes `CONNECT` follow the established pattern: `SUBSCRIPTION` selects
which processor tiers are exposed, so `CONNECT` selects which connected accounts are. Null means all,
which is what the marketplace wants.

**Fee resolution order:** DP row → processor's connect profile (§08.3) → refuse. A DP-level override
lets one processor serve a 10% marketplace and a 0% internal DP. Whichever value applies is still
snapshotted onto the listing at publish time (step 02 §02.4.2), so this changes the default, never a
sale already made.

#### Gating on Stripe

`CONNECT` is offered only when the selected processor's provider is `stripe` **and** its connect
profile has `enabled: true`. Enforced in three places:

| Where | Behaviour |
|---|---|
| `directpayments-form.html` / `directpayments.ts` | The `CONNECT` option is hidden or disabled until a Stripe processor with Connect enabled is chosen; changing the processor away re-evaluates it |
| `DirectPaymentsController` | Validation rejects `CONNECT` for a non-Stripe processor — the client-side gate is UX, not enforcement |
| SDK | `dp.connect.isSupported()` already reports unsupported per §08.5.3; a `CONNECT` DP on a non-Stripe processor is a data error and should log loudly |

#### Swapping a DP's processor — the guard, and the future it leaves room for

`processor_reference_num` is editable on an existing DP, so a processor can be swapped after the
fact. **Swapping is the entire point of the Direct Payments layer** — if a provider account has a
problem, the operator repoints the DP and no application code changes. For `SINGLE_PAY` that works
today. For `CONNECT` it cannot yet, and the guard must say so precisely rather than blanket-refusing.

##### Why same-provider is a different problem from cross-provider

| Swap | Verdict | Reason |
|---|---|---|
| Stripe → PayPal / Square | **Reject, permanently** | The target provider has no connected-accounts implementation (§08.5.3). There is nothing to migrate *to*. |
| Stripe A → Stripe B, DP has **no** connected accounts | **Allow** | Nothing to orphan. This is the ordinary "swap the account" case the DP layer exists for. |
| Stripe A → Stripe B, DP **has** connected accounts | **Reject in v1**, with a specific error | Tractable in principle, unbuilt in practice — see below. |

The pivot is that **`acct_` ids belong to the platform account that created them.** They are not
portable between Stripe platform accounts. So a same-provider swap does not move sellers; it requires
recreating every one of them on the new platform. The *shape* is identical on both sides — same API,
same object model, a 1:1 mapping — which is exactly why it is worth building later and why
cross-provider is not.

##### v1 guard

Implemented in `ConnectProfileService` (or a dedicated `DirectPaymentProcessorGuard`), enforced in
`DirectPaymentsController::update()`:

```php
/** MARKET_MODIFIER_ADDITION */
if ($dp->pay_type === 'CONNECT' && $newProcessorRef !== $dp->processor_reference_num) {
    $old = Processor::byRef($dp->processor_reference_num);
    $new = Processor::byRef($newProcessorRef);

    if ($old->provider !== $new->provider) {
        abort(422, 'DP_E_CONNECT_PROVIDER_CHANGE');   // permanent
    }
    if (!$new->connectProfile()->enabled) {
        abort(422, 'DP_E_CONNECT_TARGET_NOT_ENABLED');
    }
    if ($old->connectedAccounts()->whereNull('closed_at')->exists()) {
        abort(422, 'DP_E_CONNECT_ACCOUNTS_PRESENT'); // v1 only — see the migration note
    }
    // same provider, Connect enabled, zero accounts → permitted
}
```

Each error carries a message naming the actual constraint, not "validation failed".
`DP_E_CONNECT_ACCOUNTS_PRESENT` in particular must say *"N connected accounts exist on the current
processor; migrating them is not yet supported"* and cite the count — an operator hitting this needs
to know whether they are blocked by three test accounts or three hundred live ones.

Client-side, the `#dpProcessor` select on a `CONNECT` DP filters to same-provider processors and
disables the rest with a tooltip. UX only; the server is the enforcement.

##### The note that belongs in the code

The project owner's intent is that this becomes supported. Put the following as a `/** */` block
above the guard — the constraint and the reason it exists have to travel together, or the next
reader deletes one without understanding the other:

> **FUTURE: `CONNECT` processor migration.**
>
> This guard is a limitation, not a design goal. The Direct Payments layer exists so an operator can
> replace a failing or restricted provider account without touching application code, and `CONNECT`
> should eventually honour that too. The reason it does not yet is that connected accounts are owned
> by the platform account that created them — `acct_` ids do not transfer between Stripe platforms —
> so a swap is a **migration**, not a repoint.
>
> What a supported migration would have to do, in order:
>
> 1. **Freeze** new checkouts on the DP; existing invoices settle on the old platform.
> 2. **Enumerate** connected accounts on the old platform and record a migration batch.
> 3. **Create counterparts** on the new platform, prefilling identity through account tokens where
>    the data allows it (`lib/Service/V2/Core/AccountTokenService.php`).
> 4. **Re-verify each seller.** Terms of service acceptance is per-platform and cannot be inherited;
>    KYC may need re-collection depending on what prefill carries. **This is a seller-facing
>    campaign, not a background job** — plan the communications alongside the code.
> 5. **Re-attach external accounts.** Bank details are not readable from the old platform; the seller
>    re-enters them.
> 6. **Dual-run.** New charges route to the new platform while in-flight transfers, disputes and
>    payouts remain on the old. Both must be readable during this window.
> 7. **Drain** remaining balances on the old platform before decommissioning it.
> 8. **Migrate** webhook endpoints and the v2 event destination — both are per-platform.
> 9. **Repoint** the DP and archive the old processor.
>
> Not portable, and worth stating explicitly because each one is a place someone will assume
> otherwise: `acct_` ids, saved payment methods and customers, balances, in-flight transfers,
> open disputes, application-fee history, and ToS acceptance.
>
> **The schema is already laid — see §08.5.8.** Three tables (`laravel_processor_migrations`,
> `…_migration_accounts`, `…_migration_events`) plus two linking columns on
> `processor_connected_accounts`. Created and modelled, deliberately not wired to anything, so a
> future implementation starts from a data model rather than a blank page.
>
> Until then: create the new processor, create a new `CONNECT` DP against it, and onboard sellers
> there. That is the same work the migration would do, done manually, and it keeps the old DP intact
> as a fallback.

##### The other pay types, for contrast

Recorded so the guard reads as proportionate rather than arbitrary:

- **`SINGLE_PAY`** — swaps cleanly. Items and prices are local configuration; the new processor just
  charges. Saved payment methods do not port, so returning customers re-enter card details, but no
  data is orphaned.
- **`SUBSCRIPTION`** — swaps *poorly*, for a related reason: tiers are pulled from the processor, so
  tier references change and existing subscriptions keep billing on the old processor until migrated
  individually. This plan does not touch the subscription path (§08.7.3), so the behaviour is left
  as-is — but it is the same class of problem and worth knowing about before someone swaps a
  subscription DP expecting it to be clean.

#### SDK changes

```ts
dp.isConnect(): boolean              // dpConfig.pay_type === 'CONNECT'
```

and a third branch beside `:709` / `:726`, so a `CONNECT` DP no longer reaches
`throw new Error('Unrecognized Direct Payment payload format.')` — which is exactly what it would do
today, with a message pointing nowhere near the cause.

`dp.connect` (§08.5.4) remains available on **any** Stripe-backed DP for reading status. The
`CONNECT` pay_type is what makes a DP *charge* with a split.

#### Why this is the right shape

`CONNECT` reads at first like a category error — `SINGLE_PAY` and `SUBSCRIPTION` describe *how* money
is taken, while Connect describes *who receives* it, and a marketplace sale is still a single
payment.

It earns its place because the two properties that actually differ are both structural, not
cosmetic: **items are dynamic rather than configured**, and **funds are split rather than kept**. A
DP with those properties cannot share `SINGLE_PAY`'s validation path, its admin form, or its
checkout flow. Modelling it as a flag on `SINGLE_PAY` would mean every one of those paths growing a
conditional — which is the same distinction, expressed worse.

### 08.5.8 Schema for a future processor migration — built now, not wired

Per the project owner: lay the schema so a future implementation has something to build against.
**None of this is functional in phase 1.** The tables are created, the models exist, nothing writes
to them, and the §08.5.7 guard still refuses the swap. The point is that the hard part of starting
later — deciding how to represent a half-finished migration — is done.

Three tables, all `MARKET_MODIFIER_ADDITION` tagged, all in `apps/www`.

#### `laravel_processor_migrations` — one row per attempt

```php
$table->id();
$table->string('reference_num')->unique();                  // pm_XXXXXXXX
$table->string('from_processor_reference_num')->index();
$table->string('to_processor_reference_num')->index();
$table->string('provider');                                 // denormalized: MUST match both ends
$table->string('direct_payment_reference_num')->nullable(); // the DP that motivated it
$table->string('strategy')->default('recreate_accounts');   // only value today
$table->string('status')->default('draft');
$table->unsignedInteger('accounts_total')->default(0);
$table->unsignedInteger('accounts_migrated')->default(0);
$table->unsignedInteger('accounts_failed')->default(0);
$table->bigInteger('source_balance_minor')->nullable();     // drain tracking
$table->string('source_balance_currency', 3)->nullable();
$table->timestamp('source_balance_checked_at')->nullable();
$table->timestamp('frozen_at')->nullable();
$table->timestamp('dual_run_started_at')->nullable();
$table->timestamp('drained_at')->nullable();
$table->timestamp('completed_at')->nullable();
$table->timestamp('aborted_at')->nullable();
$table->text('abort_reason')->nullable();
$table->string('created_by_member_reference_num')->nullable();
$table->text('notes')->nullable();
$table->timestamps();
```

`status` follows §08.5.7's sequence:
`draft → preparing → awaiting_sellers → dual_run → draining → completed`, with `aborted` reachable
from any of them.

**`provider` is denormalized deliberately.** It is the guard's data: a migration row whose provider
does not match both processors is invalid by construction, and storing it means that can be asserted
without two joins at every check.

#### `laravel_processor_migration_accounts` — one row per seller

The table that makes a migration resumable. Without it, an interrupted run cannot tell which sellers
were recreated, which are mid-verification, and which never started.

```php
$table->id();
$table->foreignId('migration_id')->constrained('laravel_processor_migrations')->cascadeOnDelete();
$table->string('member_reference_num')->index();
$table->string('from_connected_account_id');                // old acct_
$table->string('to_connected_account_id')->nullable();      // new acct_, once created
$table->string('status')->default('pending');
$table->json('requirements_snapshot')->nullable();          // what the old account had satisfied
$table->timestamp('counterpart_created_at')->nullable();
$table->timestamp('tos_accepted_at')->nullable();           // per-platform; never inherited
$table->timestamp('external_account_attached_at')->nullable();
$table->timestamp('verified_at')->nullable();
$table->timestamp('notified_at')->nullable();
$table->unsignedTinyInteger('notify_count')->default(0);
$table->text('last_error')->nullable();
$table->timestamps();
$table->unique(['migration_id', 'from_connected_account_id']);
```

Per-account `status`: `pending → counterpart_created → awaiting_verification → external_account_pending
→ complete`, plus `failed` and `skipped`.

`tos_accepted_at` and `external_account_attached_at` are separate columns rather than flags because
they are the two things that **cannot** be carried across and therefore gate completion. A seller can
be verified and still unpayable because they have not re-entered bank details.

#### `laravel_processor_migration_events` — append-only audit

```php
$table->id();
$table->foreignId('migration_id')->constrained('laravel_processor_migrations')->cascadeOnDelete();
$table->foreignId('migration_account_id')->nullable()->constrained('laravel_processor_migration_accounts');
$table->string('type');            // froze, counterpart_created, seller_notified, tos_accepted, …
$table->json('payload')->nullable();
$table->timestamp('created_at');
```

Append-only, no `updated_at`. A migration that moves other people's money needs a record of what
happened, not a record of current state only.

#### Linking columns on the existing table

`processor_connected_accounts.migrated_from_account_id` and `.migration_batch_id` (§08.6) point back
here — `migration_batch_id` holds `laravel_processor_migrations.reference_num`. That is what makes
old and new accounts linkable during the dual-run window, when both exist and both are live.

#### What is deliberately absent

No service class, no jobs, no commands, no UI. Adding those without exercising them against a real
Stripe migration would produce code that looks finished and has never run — which is worse than an
empty schema, because the next person trusts it.

---

### 08.5.9 Connect is experimental — feature flag plus level gating

Connect ships hidden. Two independent gates, matching patterns the admin portal already uses.

#### The flag

`resources/js/portal/admin/main.ts:59` already has the mechanism:

```ts
// Feature flag defaults for quick toggling (not persisted locally; evaluated fresh on each load)
const DEFAULT_FEATURE_FLAGS = {
    ads_manager: true,
    connect: false,          // MARKET_MODIFIER_ADDITION — experimental; general release OFF
};
```

Flipping `connect: true` is the "ship it" action and the only change needed for general release.
`applyFeatureFlagsUI(app)` already re-runs on change (`main.ts:86`), so the UI responds without a
reload.

#### The levels

`main.ts:575` already gates the Brain nav on `app.window.member?.level == 99`, and
`app.window.member` is set at `main.ts:481` from `brainClient.getMember(...)`. Same source, same
shape.

```ts
/** MARKET_MODIFIER_ADDITION
 *  Levels with early access to Connect while the `connect` flag is false.
 *  Level 99 is unrestricted everywhere in this portal and bypasses both gates. */
export const CONNECT_EARLY_ACCESS_LEVELS = [99, 5];

export function canUseConnect(app: ApplicationContextManager): boolean {
    const level = Number(app.window.member?.level ?? 0);
    if (level === 99) return true;                                     // 99 does anything
    if (app.window.featureFlags?.get('connect') === true) return true; // general release
    return CONNECT_EARLY_ACCESS_LEVELS.includes(level);                // early access
}
```

| `connect` flag | level | Sees Connect |
|---|---|---|
| `false` | 99 | ✅ unrestricted |
| `false` | 5 | ✅ early access |
| `false` | anything else | ❌ hidden entirely |
| `true` | any | ✅ general release |

**Read the semantics deliberately:** the flag is a *general-release* switch, not a kill switch —
levels 99 and 5 keep access while it is `false`, which is what makes the early-access period useful.
If a true kill switch is wanted later (turn it off for *everyone* mid-incident), that is a second
flag or an inverted one; say which, rather than overloading this.

#### What is hidden

Removed from view, not merely disabled — an element that is visible but greyed advertises a feature
the viewer cannot have:

| Surface | When `canUseConnect()` is false |
|---|---|
| Connect Settings panel on `processor-details.html` | Not rendered |
| Connect Accounts section + New Account modal | Not rendered |
| `CONNECT` option in `#dpPayType` (`directpayments-form.html`) | Option not emitted |
| Any Connect nav entry or badge | Not rendered |

Follow the `EMAIL_PROVIDERS` precedent in the same file — its comment is explicit that a provider set
to `false` *"never appears as an option at all"*. Same treatment.

Default the markup to hidden and reveal it, rather than rendering and then hiding. The existing
level-99 nav gate does exactly this (`brainNav.style.display = 'block'` on match, nothing on the
else branch), which is why a slow member fetch never flashes the Brain item to an unprivileged user.

> #### ⚠️ Client-side gating is UX, not enforcement
>
> `app.window.member` comes from a fetch the browser makes; a console user can set
> `app.window.member.level = 99`. Hiding the UI stops accidents, not attempts.
>
> **The same rule must be enforced server-side** in `ProcessorAdminController` (saving a `connect`
> block), `ProcessorConnectController` (every method), and `DirectPaymentsController` (accepting
> `pay_type: CONNECT`). Without it, the gate is decoration and an unprivileged admin can create live
> connected accounts by hand-crafting one request.
>
> **Unverified, and it needs checking before this is built:** how the admin portal authenticates
> server-side, and whether the member level is available to those controllers. `main.ts` fetches the
> member through `Portal.brain.Client` from the *browser*, which tells us nothing about the server's
> view of the request. Resolve this when task 1.4 is in that code — if there is no server-side
> identity for the admin portal, that is a larger finding than this feature and should be raised
> rather than worked around.

## 08.6 Backend

### `ProcessorConnectController` — rewritten against v2

| Method | Route | v2 call |
|---|---|---|
| `showProfile` / `saveProfile` | `GET/PUT .../connect/profile` | none — local |
| `store` | `POST .../connect` | `$stripe->v2->core->accounts->create([...])` |
| `show` | `GET .../connect/{id}` | `accounts->retrieve($id, ['include' => [...]])` |
| `status` | `GET .../connect/{id}/status` | `retrieve` with `include: ['configuration.merchant','configuration.recipient','requirements','identity']` |
| `startOnboarding` | `POST .../connect/{id}/onboarding` | mode-dependent (below) |
| `requirements` / `submitRequirements` | `GET/POST .../connect/{id}/requirements` | `accounts->update` |
| `balance` / `payouts` | `GET .../connect/{id}/{balance,payouts}` | v1, with `stripe_account` |
| `close` | `DELETE .../connect/{id}` | `accounts->close($id)` |

Creation:

```php
/** MARKET_MODIFIER_ADDITION */
$account = $stripe->v2->core->accounts->create([
    'contact_email' => $email,
    'display_name'  => $displayName,
    'dashboard'     => $profile->dashboard,          // 'none' | 'express' | 'full'
    'identity'      => ['country' => $country, 'entity_type' => $entityType],
    'configuration' => [
        'merchant'  => ['capabilities' => ['card_payments' => ['requested' => true]]],
        'recipient' => ['capabilities' => ['stripe_balance' =>
                          ['stripe_transfers' => ['requested' => true]]]],
    ],
    'defaults' => [
        'currency'         => $profile->default_currency,
        'responsibilities' => [
            'fees_collector'   => $profile->fees_collector,
            'losses_collector' => $profile->losses_collector,
        ],
    ],
    'include' => ['configuration.merchant', 'configuration.recipient', 'identity', 'requirements'],
], ['stripe_version' => config('marketplace.stripe.preview_version')]);
```

Three notes that will otherwise cost debugging time:

- **`include` is mandatory to see anything.** *"By default, Accounts v2 API calls return values for
  certain properties and null for other properties, regardless of their actual values."* An
  integration that omits `include` and concludes capabilities are missing has misread a null.
- **The preview version header is required** for v2 accounts, and the SDK pins the GA version.
  Pass `stripe_version` per request, or build a dedicated `StripeClient` with it. Keep it in config
  so bumping it is not a code change.
- `recipient` with `stripe_balance.stripe_transfers` is **not optional** for this marketplace —
  it is what indirect (destination and separate-transfer) charges require.

Onboarding, by mode:

- `embedded` → v1 `\Stripe\AccountSession::create(['account' => $v2AccountId, 'components' => [...]])`,
  return `client_secret`. **v1 endpoint, v2 account id** — supported.
- `hosted` → `$stripe->v2->core->accountLinks->create(['account' => $id, 'use_case' => ['type' => 'account_onboarding', 'account_onboarding' => ['configurations' => ['merchant','recipient'], 'return_url' => ..., 'refresh_url' => ...]]])`, URLs from config (§08.2 defect 1 fixed).
- `api` → the `requirements` hash, plus a `liveness_fallback` handle when any entry needs
  `proof_of_liveness`.

**`createSession` gets real authorization** (§08.2 defect 2): the step-04 HMAC service signature,
plus a check that the account's `member_reference_num` matches the requesting member. Both, not
either.

### Migration of existing v1 accounts

Any `ProcessorConnectedAccount` created by the current code has a `type`. Per Stripe, v2 can manage
v1-created accounts. The plan: keep reading legacy rows through v1, create all **new** accounts
through v2, and record `api_version` on the row so both paths are explicit rather than inferred.
Do not attempt a bulk conversion — `dashboard` and `country` are immutable, so a "conversion" would
mean new accounts and re-onboarding for every existing seller.

### Migration on the local table

`processor_connected_accounts` gains: `member_reference_num` (index), `api_version` (`v1`|`v2`),
`dashboard`, `fees_collector`, `losses_collector`, `requirements_collector` (mirrored from the
response, never computed locally), `configurations` json, `charges_enabled`, `payouts_enabled`,
`details_submitted`, `requirements_json`, `disabled_reason`, `default_currency`, `last_synced_at`,
plus `closed_at` (nullable) — and two nullable columns for the future migration described in
§08.5.7: `migrated_from_account_id` and `migration_batch_id`. Those two cost nothing now and are the
expensive part of starting a migration later.
New table `processor_connect_profiles` for §08.3.

---

## 08.7 The charge path — goal 2

Accounts v2 does not change the charge APIs. Destination charges and transfers remain v1
PaymentIntent / Transfer calls that take the account id.

Charge-model decision, forced by market's per-item `seller_reference_num` (step 03): a Direct
Payments invoice is multi-item and can contain two sellers' work, while a single PaymentIntent has
exactly one `transfer_data[destination]`.

| Model | Multi-seller | Verdict |
|---|---|---|
| Direct charges | one per charge | Rejected — seller becomes merchant of record, owns disputes and refund liability. Wrong for individual creators. |
| **Destination charges** | one destination | **Chosen for single-seller carts.** Platform is merchant of record; funds move at capture. |
| **Separate charges and transfers** | any number | **Chosen for multi-seller carts.** The only model that splits one payment N ways. |

Selected automatically by distinct seller count. **Do not** solve multi-seller by splitting into N
PaymentIntents — N authorizations, N partial-failure modes, and a buyer charged for one item and
declined for another with no atomic remedy. Rejected explicitly so it is not re-proposed.

`ProcessorIntentController::createIntent()` accepts an optional `split` block:

```json
{ "split": { "mode": "destination", "destination": "acct_…",
             "application_fee_amount": 400, "transfer_group": "or_ABC12345" } }
```

```json
{ "split": { "mode": "separate_transfers", "transfer_group": "or_ABC12345",
             "transfers": [ { "destination": "acct_A", "amount": 3600,
                              "seller_reference_num": "m1x…", "metadata": { } } ] } }
```

> **Capability check (step 01 §01.2.5).** A server that does not understand `split` ignores it and
> returns a **successful charge with no transfer** — nobody is paid, nothing errors. Adding the field
> is safe in itself; *relying* on it without confirmation is not.
>
> So the SDK checks a capability or version field on the processors config endpoint before using the
> split path, and refuses if absent. Cheap, and the failure it prevents is silent. Market always
> calls brain, so this is insurance against a propagation window rather than a live risk.

**Server-side invariants, enforced before any Stripe call** — these are what stop a client-supplied
split from becoming a way to pay an arbitrary account:

1. `sum(transfers[].amount) + application_fee_total ≤ amount`. Never `>`; a rounding remainder stays
   with the platform.
2. Every `destination` resolves to a `ProcessorConnectedAccount` on this processor with
   `charges_enabled = true` **and** the recipient configuration applied.
3. `application_fee_amount` equals `floor(line_net × platform_fee_percent / 100)` recomputed
   server-side. The client's number is validated, never trusted.
4. `transfer_group` present and equal to the market order reference — what makes charge and
   transfers reconcilable later.
5. Every transfer carries `metadata.seller_reference_num` and `metadata.order_item_reference_num`.

`separate_transfers` records intents and executes them from the `payment_intent.succeeded` /
`charge.succeeded` webhook, **never inline** — transferring before the charge settles is how a
platform moves money it does not have.

New model `ProcessorTransferIntent`: `payment_intent_id`, `transfer_group`, `destination`, `amount`,
`seller_reference_num`, `order_item_reference_num`, `status` (`pending`/`sent`/`failed`/`reversed`),
`stripe_transfer_id`, `attempts`, `last_error`. Without it the webhook has nothing to act on and the
split is irrecoverable if the originating request is lost.

### What Accounts v2 changes — and does not change — for charges, customers and subscriptions

Three separate questions, three different answers. Recorded here because the plan previously
answered only the first, and left the other two to be inferred.

#### 08.7.1 One-time charges — unchanged

Accounts v2 introduces no new charge endpoints. A payment is still a v1 PaymentIntent, and
`transfer_data[destination]` / `on_behalf_of` accept an `acct_` id whether that account was created
through `/v1/accounts` or `/v2/core/accounts`. The split block above is the whole of it.

Practical consequence: **`createIntent()` does not need the preview API version.** Only the
`/v2/core/*` calls do. Scope `stripe_version` to the account-management client, not globally — a
blanket preview version on every processor call changes behaviour on endpoints this step never
intended to touch.

#### 08.7.2 Customers — buyers stay v1 `Customer`. Deliberately.

v2's headline claim is that you can stop using the Customers API: *"In most cases, you can represent
any customer with an `Account` object… You don't have to use the Customers API at all."* Every v1
endpoint taking `customer` also takes `customer_account`, which accepts an Account id:

```bash
curl https://api.stripe.com/v1/setup_intents \
  -d customer_account=acct_123 -d "automatic_payment_methods[enabled]=true" ...
```

**The plan does not adopt this for buyers.** Market's buyers are not connected accounts, and the
decision is:

> **Buyers remain v1 `Customer` objects. Sellers are v2 Accounts. The two coexist in one charge.**

Reasoning, in order of weight:

- **Mixing is the supported case, not a compromise.** The payer and the transfer destination are
  different objects on opposite sides of the transaction. A v1 `Customer` paying a charge that
  transfers to a v2 seller Account is exactly what destination charges describe.
- **The buyer path already works and has no SDK dependency.** `ProcessorCustomerController`
  (`/customers/create-anonymous`, `/customers/find-or-create`, `/customers/update-email`) is raw
  cURL against `/v1/customers`. Moving it to v2 would pull the preview version, the `include`
  semantics, and the SDK into the one part of checkout that is currently working.
- **Direct Payments already threads buyer identity through as a customer id** —
  `SESSION_KEY_CUSTOMER_ID = 'afs_customer_id'`, `ProcessChargePayload.customer_id`,
  `getPayments({customer_id})`. Changing the object type changes every one of those call sites for
  no gain to this plan.
- **Nothing in the marketplace charges a seller.** The `customer` configuration exists to bill an
  Account; market bills buyers, and buyers are not Accounts.

**Where `customer_account` does become the right answer — recorded so it is not rediscovered.**
The moment the platform bills *sellers* — a listing fee, a SaaS tier, a featured-placement charge —
the seller already has a v2 Account. Adding the `customer` configuration to it lets you invoice or
subscribe them directly, with **no second object and no duplicate identity**. Under v1 that is
impossible: *"If you use the Accounts v1 API, you can't pass an `Account` ID to an endpoint that
expects a `Customer` ID."*

This is why `customer` is already an option in §08.3's `account_configurations` even though the
marketplace does not request it today. The profile admits the case; the plan does not build it.

#### 08.7.3 Subscriptions — out of scope, and explicitly unchanged

Market sells one-time asset purchases and freelance orders. **No subscriptions, anywhere in this
plan.**

But step 08 edits the shared processors layer, which *does* have a subscription path:
`ExternalProcessorsAPI.chargeSubscription()`, `SetupSubscriptionPayload`, the tier handling in
`direct_payments/api.ts` (`getTierInfoFromRecord`, `PortalDirectPaymentTier`),
`ProcessorSubscriptionController`, and `ProcessorPlanAdminController`.

> **That path stays v1 and is not modified by this step.** No preview version, no v2 objects, no
> `customer_account`. Touching it is out of scope, and a subscription regression traced back to a
> Connect step would be an expensive way to learn that.

One caveat to record rather than assume: a subscription **with** a Connect split is a different
mechanism from a one-time split — it uses `application_fee_percent` on the Subscription plus
`on_behalf_of`/`transfer_data`, not the `application_fee_amount` in §08.7. If seller subscriptions
are ever wanted, that is new design work, not a reuse of `SplitCalculator`.

#### 08.7.4 The capability rename that will bite

v2 renamed two capabilities. Code that reads the v1 names against a v2 account finds nothing —
silently, because a missing capability reads as "not granted" rather than as an error:

| v1 capability | v2 equivalent |
|---|---|
| `transfers` | `stripe_balance.stripe_transfers` (under the **recipient** configuration) |
| `payouts` | `stripe_balance.payouts` (under the **merchant** configuration) |
| `card_payments` | `card_payments` (unchanged, under **merchant**) |

Any status mapping — §08.6's `status()`, step 10's three-state UI, market's `seller_profiles`
mirror — must read the v2 names for v2 accounts and the v1 names for legacy ones, keyed on the
`api_version` column. A single hardcoded `capabilities.transfers` check is a seller who appears
unable to receive money when they can.

---

### Direct Payments SDK

- `Invoice.addItem()` gains optional `seller_ref`, echoed to the server on lock.
- The locked invoice computes a split plan (group by `seller_ref`, sum line totals net of tax, apply
  the fee) and emits a `destination` or `separate_transfers` block.
- `setupSinglePayPayment()` forwards it to `createIntent`.
- New `directPayments.getSplitPreview()` for the checkout UI, so the arithmetic is not implemented
  twice.
- `ExternalProcessorsAPI` gains `getConnectStatus()`, `getConnectBalance()`, `getConnectPayouts()`,
  each delegating to the Direct Payment instance's `connect` accessor rather than duplicating it.

### Fee arithmetic — the single definition

```
line_net     = unit_price × quantity            (tax excluded)
platform_fee = floor(line_net × fee_pct / 100)  (minor units)
seller_net   = line_net − platform_fee
buyer_pays   = Σ line_net + Σ tax
```

Integer minor units throughout; `bcmath` or cents, never floats. `floor` on the fee so remainders
favour the seller and `fee + net == line_net` exactly. Tax never in the fee base. Free items produce
no fee and no transfer. `fee_pct` comes from `assets.platform_fee_percent` on the row (step 03), not
from config, so a seller's agreed rate survives a config change.

### Webhooks and the verification lifecycle

This is the mechanism the whole self-hosted configuration rests on. Under
`requirements_collector: application` (which `dashboard: "none"` forces — §08.1), **Stripe does not
contact the seller.** No email, no dashboard notice, no hosted nag. If a seller's verification
lapses, the only thing standing between them and silently losing the ability to be paid is this
pipeline.

Treat a dropped verification event as a money-losing defect, not a missed notification.

#### The delivery surface is split — this is the part that surprises

| API | Registered via | Received by | Payload |
|---|---|---|---|
| **v1** (charges, transfers, payouts, disputes, legacy accounts) | `/v1/webhook_endpoints`, or the Stripe dashboard | Existing `ProcessorWebhookController` at `POST /portal/webhooks?type=stripe` | **Fat** — `$event->data->object` is the full object |
| **v2** (`v2.core.account*`) | **`/v2/core/event_destinations`** — a different resource entirely | **Needs a new receiver** | **Thin** — id, type, created, `context`, `reason`, `livemode`, and a `related_object` reference |

Registering a v2 event type on a v1 webhook endpoint does not work. The SDK exposes the correct
surface at `lib/Service/V2/Core/EventDestinationService.php`: `create`, `retrieve`, `update`,
`all`, `enable`, `disable`, `delete`, and **`ping`** — use `ping` in verification.

**v2 notifications carry no object.** `lib/V2/Core/EventNotification.php` holds a
`RelatedObject` with a `url`; getting the account means calling `fetchRelatedObject()` (an
authenticated GET) or `fetchEvent()`. A handler written in the v1 idiom — reach into
`$event->data->object` — gets null and silently does nothing.

Two consequences to design for:

- **Every v2 handler makes an API call.** Budget for it; a burst of requirement updates is a burst of
  outbound requests. Handle the fetch failing independently of the notification arriving.
- **The fetch must pass `include`** (§08.6). A fetched account with no `include` returns nulls for
  `requirements` and `configuration`, and the handler concludes nothing changed.

The parsing helpers are in 21.2.0: `parseEventNotification`, `constructEventWithoutVerification`,
and `WebhookSignature::generateSignatureHeader` (the last is genuinely useful for unit-testing this
without Stripe in the loop).

#### The event set — exact type strings, verified against the SDK

Verification lifecycle, in rough order of importance:

| Event | Why it matters |
|---|---|
| **`v2.core.account[requirements].updated`** | **The workhorse.** New requirements due, a deadline set or moved, verification errors returned. This is "the seller needs to update their verification." |
| **`v2.core.account[future_requirements].updated`** | Requirements coming into force later — the periodic re-verification case. Fires *before* the account is restricted, which is the only useful time to tell someone. |
| **`v2.core.account[configuration.merchant].capability_status_updated`** | `card_payments` / `stripe_balance.payouts` flipping active ↔ inactive. This is what actually gates selling. |
| **`v2.core.account[configuration.recipient].capability_status_updated`** | `stripe_balance.stripe_transfers`. Loses this and destination charges fail — far from the cause. |
| **`v2.core.account_person.created` / `.updated` / `.deleted`** | **Company accounts only, and easy to miss.** Directors, owners and representatives each carry their own requirements and their own verification state. An account whose own requirements are clear can still be blocked by one unverified person. `lib/Service/V2/Core/Accounts/PersonService.php`. |
| **`v2.core.account_link.returned`** | The seller came back from a hosted-onboarding link. Fires whether or not they finished — a trigger to re-fetch status, never a completion signal (step 10 §10.4). |
| `v2.core.account[identity].updated` | Identity data changed; may trigger re-verification. |
| `v2.core.account.updated` / `.created` / `.closed` | Coarse lifecycle. |
| `v2.core.account[configuration.merchant].updated` / `[configuration.recipient].updated` | Configuration changes, distinct from capability status. |
| `v2.core.account[defaults].updated` | Responsibilities/currency defaults. |
| `v2.core.account[configuration.customer].*` | Only if the `customer` configuration is ever applied (§08.7.2). Not requested today. |

Legacy v1 accounts keep their own set — `account.updated`, `capability.updated`,
`person.created` / `.updated` / `.deleted`, `account.external_account.*` — and both receivers run in
parallel until no v1 accounts remain. Route on the `api_version` column.

Money events (unchanged, v1): `payment_intent.succeeded`, `charge.succeeded`, `transfer.created` /
`.paid` / `.failed` / `.reversed`, `payout.paid` / `.failed`, `charge.refunded`,
`charge.dispute.created`.

`account.application.deauthorized` is OAuth-only and does not apply here.

#### What a verification handler must do

`v2.core.account[requirements].updated` arrives →

1. `fetchRelatedObject()` with `include: ['configuration.merchant','configuration.recipient','requirements','future_requirements','identity']`.
2. Mirror into `processor_connected_accounts`: `charges_enabled`, `payouts_enabled`,
   `details_submitted`, `requirements_json`, `disabled_reason`, `last_synced_at`.
3. Push to market → `seller_profiles` (step 04 callback), which is what the publish gate reads.
4. Classify the transition and notify accordingly:

| Transition | Seller-facing action |
|---|---|
| Requirements cleared, `charges_enabled` false → true | *"Your account is verified."* Unblock paid publishing. |
| New `currently_due`, deadline distant | In-app notice + a resume-onboarding link. |
| New `currently_due`, deadline near, or `past_due` | Escalate: email as well as in-app. Name the deadline and the consequence. |
| `charges_enabled` true → false | **Urgent.** Sales stop now. Existing entitlements are unaffected — say so, or the seller assumes their buyers lost access. |
| `payouts_enabled` true → false | Earnings still accrue in the ledger, payouts pause. Say both halves. |
| `future_requirements` populated | Early warning, low urgency, explicit deadline. |
| `disabled_reason` set | Show Stripe's string **verbatim** (step 10 §10.3). |

Deadline chasing is the platform's job here, so a scheduled sweep over
`requirements.current_deadline` is required alongside the event handler — an event fires once, and a
seller who ignores it needs reminding at 14, 7 and 1 days. **The events alone are not a reminder
system.**

Person-level events follow the same path, keyed on the parent account, with a message naming *which*
person is blocking. "Your account needs more information" is unactionable when the missing document
belongs to a co-director.

#### Refunds and disputes — measured, then designed

`ProcessorWebhookController` (1011 lines) already has the right skeleton: signature check →
`ProcessorWebhook` audit row with the full payload → a `match` on event type → `processed` flag. Keep
all of that. But measure what the refund and dispute arms actually do before building on them.

**`logStripeRefund()` and `logStripeDispute()` write a log line and return `true`.** That is the
whole implementation:

```php
private function logStripeDispute(array $payload, string $processorId): bool
{
    $obj = $payload['data']['object'] ?? [];
    Log::warning('[Webhook][Stripe] Dispute created', [...]);
    return true;
}
```

No database write, no ledger entry, no transfer reversal, no entitlement change, no notification.
The event is observed and discarded. Since the row is marked `processed = true`, it does not even
show up as outstanding work.

Three gaps follow from that, plus one defect found in passing.

##### Gap 1 — the events that carry the outcome are not subscribed

| Currently handled | Missing, and why it matters |
|---|---|
| `charge.refunded` | `refund.created`, `refund.updated`, **`refund.failed`** — a refund that fails (closed card, unsupported method) is currently invisible. The buyer is told they were refunded and they were not. |
| `charge.dispute.created` | **`charge.dispute.closed`** — carries `status: won \| lost`. Without it the outcome is never learned, so a won dispute never reinstates the seller's ledger. Also `charge.dispute.updated`, `charge.dispute.funds_withdrawn`, `charge.dispute.funds_reinstated`. |

`charge.dispute.created` alone tells you a fight started and never who won.

##### Gap 2 — refunds on a destination charge need two flags, or money is lost

A refund on a destination charge does **not** claw back the transfer by default:

```php
/** MARKET_MODIFIER_ADDITION */
$refund = $stripe->refunds->create([
    'charge'                 => $chargeId,
    'amount'                 => $amountMinor,       // omit for full
    'refund_application_fee' => true,               // else the platform keeps its 10% on a refunded sale
    'reverse_transfer'       => true,               // else the seller keeps the money the platform just returned
]);
```

- Omit `reverse_transfer` → buyer is refunded from the **platform** balance while the seller keeps
  their 90%. The platform silently eats the sale.
- Omit `refund_application_fee` → the platform keeps a fee on revenue that no longer exists. Small,
  wrong, and it accumulates.
- Partial refunds reverse **proportionally** — the fee refund must use the same `floor` rule as
  §08.7 or fee and net stop summing to the line total.
- If the seller's balance cannot cover the reversal, the connected account goes **negative**. Under
  `losses_collector: application` (which self-hosting forces) that lands on the platform. Step 16's
  ledger must be able to represent a negative seller balance and show it honestly.

##### Gap 3 — on a destination charge the dispute is against *you*

The platform is merchant of record. Consequences, none of which are automatic:

1. `charge.dispute.created` → funds withdrawn from the **platform** balance immediately, plus a
   non-refundable dispute fee. Both hit before anyone has looked at the case.
2. The transfer to the seller is **not** reversed automatically. The platform must call
   `Transfer::createReversal` if it wants the seller to bear it.
3. **Evidence lives with the seller, submission is the platform's.** The seller has the deliverable,
   the messages, the delivery timestamps. The platform is the only party Stripe will accept evidence
   from. That is a workflow, not an API call: notify the seller → collect evidence → platform
   submits before `evidence_details.due_by`. Miss the deadline and the dispute is lost by default.
4. `charge.dispute.closed` with `status: won` → funds reinstated, minus the fee. `lost` → the
   platform is out the amount plus the fee, and recovers from the seller only if the ledger says so.

A dispute deadline that passes unattended is a guaranteed loss. Treat `evidence_details.due_by` the
same way §08.7 treats `requirements.current_deadline`: a scheduled sweep with escalating reminders,
not a single event.

##### Two different things are both called "dispute" — disambiguate now

This plan uses one word for two unrelated mechanisms, and they meet in the same code:

| | Marketplace dispute | Card dispute (chargeback) |
|---|---|---|
| Parties | Buyer vs seller | Buyer's **bank** vs the platform |
| Trigger | Buyer unhappy with a deliverable | Buyer calls their card issuer |
| Where | Step 15 §15.7, the `order_disputes` table | Stripe `charge.dispute.*` |
| Resolved by | Platform admin, per §15.7 | Stripe, on submitted evidence |
| Money | Ledger adjustment | Funds withdrawn from platform balance + fee |

Rename before implementation, or step 15's `DisputeService` will be written with no thought for
chargebacks and this section's handler will be written with no thought for order state:

- Step 15's table and service → **`order_disputes`** / `OrderDisputeService`.
- Stripe's → **`chargebacks`** / `ChargebackService`, in the payments layer.

They do interact — a lost chargeback on an order with an open marketplace dispute should close both
— and that interaction is only expressible once they have different names.

#### 08.7.5 Webhook authentication is fail-open on all three providers — fix before anything else here

Not a Connect issue, and not optional. This receiver is about to start moving money between
accounts; it must authenticate first.

##### 08.7.5.1 The hole

The same pattern appears three times. Missing configuration silently downgrades the endpoint to *no
authentication*, and the payload is processed anyway.

```php
// Stripe — handleStripe()
if ($processor && !empty($processor->webhook_secret_encrypted)) {
    $secret = $processor->getWebhookSecret();
    if ($secret && !$this->verifyStripeSignature($rawBody, $sigHeader, $secret)) {
        return response('Invalid signature', 400);
    }
}
// ── falls through to processing when: no processor row, no stored secret,
//    or decryption returns empty ──
```

```php
// Square — handleSquare(): identical shape, identical two escapes.
if ($processor && !empty($processor->webhook_secret_encrypted)) { ... }
```

```php
// PayPal — handlePayPal(): three ways to skip instead of two.
if ($processor) {
    $webhookId = $processor->extra_config['webhook_id'] ?? null;
    $clientId  = $processor->publishable_key;
    $secret    = $processor->getSecretKey();
    if ($webhookId && $clientId && $secret) { ...verify... }
}
```

**Six independent bypass conditions across three providers.** And the fall-through is deliberate
rather than overlooked — the next line is `$processorId = $processor?->reference_num ?? 'unknown'`,
which exists specifically to carry on with no processor.

**Impact.** `charge.succeeded` routes to `recordStripeCharge()`, which writes records. An
unauthenticated caller who can reach `/portal/webhooks?type=stripe` can fabricate charges. The route
is deliberately CSRF-exempt (`withoutMiddleware([ValidateCsrfToken::class])`, correctly — HMAC is the
control), so the signature check is the *only* thing standing there. After step 08 the same receiver
gains transfer, payout, refund and dispute handling, which turns forged events into forged money
movement.

##### The fix

Invert the guard. **Absence of configuration is a rejection, not a bypass.**

```php
/** MARKET_MODIFIER_ADDITION */
$processor = $this->resolveProcessor($request, $provider);   // see below

if (!$processor) {
    Log::error('[Webhook] No processor configured', ['provider' => $provider]);
    return response('Webhook not configured', 503);          // 503, not 400 — see status codes
}

$secret = $processor->getWebhookSecret();
if (empty($secret)) {
    Log::error('[Webhook] No signing secret', [
        'provider' => $provider, 'processor' => $processor->reference_num,
    ]);
    return response('Webhook not configured', 503);
}

if (!$this->verifySignature($provider, $rawBody, $request, $secret, $processor)) {
    $this->recordRejectedWebhook($provider, $request);       // bounded, see below
    Log::warning('[Webhook] Signature verification failed', [
        'provider' => $provider, 'ip' => $request->ip(),
    ]);
    return response('Invalid signature', 400);
}

// only now: parse, audit-row, dispatch
```

Rules, each with its reason:

- **No configuration ⇒ `503`, not `400`.** A missing secret is *our* misconfiguration, not a
  malformed request. `400` tells Stripe the event is bad and it stops retrying, so events are lost
  permanently while the real problem is a blank config field. `503` keeps them in Stripe's retry
  queue, and they deliver once the secret is set. This distinction is the difference between an
  outage and a data-loss incident.
- **Bad signature ⇒ `400`**, and never a retry-inviting code. It will not become valid.
- **Never `200` on an unverified payload**, under any condition.
- **`hash_equals` only**, on every provider's comparison. Confirm `verifyStripeSignature` and
  `verifySquareSignature` already use it; if either uses `===` or `==`, fix it here.
- **Verify against the raw body.** `$request->getContent()`, before any decode. Any middleware that
  re-encodes JSON breaks the HMAC — and the symptom is "signatures randomly fail", which is
  expensive to diagnose.
- **Keep the ±300s replay window** already in `verifyStripeSignature`, and pair it with event-id
  idempotency (§08.7 Reliability). The window bounds replay; idempotency handles legitimate retries.
- **PayPal's verification is a remote API call** (`verifyPayPalSignature` → `paypalPost`). A PayPal
  outage must return `503` — retryable — not `400`, and must never fall through to processing.
  Distinguish "PayPal says invalid" from "PayPal did not answer".

##### Record rejections, but boundedly

Today a rejected webhook leaves only a log line, so a forgery attempt is invisible in the
application. Do **not** write rejects into `ProcessorWebhook` — that is the processing pipeline and
attacker-controlled payloads do not belong in it.

Add `processor_webhook_rejections`: provider, ip, user agent, event type as claimed, reason
(`no_processor` / `no_secret` / `bad_signature` / `stale_timestamp` / `verifier_unreachable`), a
**truncated** payload hash rather than the payload, and a timestamp. Rate-limit inserts per IP so
the table cannot itself be used as an attack. It exists for detection, not forensics.

##### Per-endpoint secrets — check the schema before implementing

Stripe issues a **distinct** `whsec_` per endpoint. This integration will have at least three: live
v1, test v1, and the v2 event destination (§08.7 — its own surface, its own secret). Square and
PayPal add more.

`Processor` currently holds a single `webhook_secret_encrypted`. Verify whether that can represent
the set. If it cannot, this needs a `processor_webhook_endpoints` table (processor, provider, mode,
kind (`v1` | `v2_event_destination`), endpoint id, encrypted secret) before the v2 receiver ships —
otherwise the v2 destination's secret overwrites the v1 endpoint's and both break at once.

##### Resolve the processor from the event — `first()` is a live multi-tenancy bug

```php
$processor = Processor::where('provider', 'stripe')->where('is_active', true)->first();
```

The **first** active Stripe processor, whatever the event concerned.

Brain is multi-tenant (step 01 §01.2.5): every tenant website configures its own processor. So this
line resolves **every Stripe webhook, for every tenant, to the same processor row.** Not a
hypothetical — a property of the current code with more than one tenant.

Trace what that produces, because the two defects mask each other:

1. Tenant B's event arrives. `first()` returns tenant A's processor.
2. If A has a webhook secret, verification runs **A's secret against B's payload** and fails ⇒ B's
   events are rejected.
3. If A has **no** secret, the fail-open guard skips verification entirely ⇒ B's events are
   processed and attributed to **A**.

Neither branch is correct, and branch 3 is how forged and misattributed events both get recorded.
Fixing the fail-open guard alone converts silent misattribution into visible rejection — better, but
still broken. **Both must be fixed together.**

`resolveProcessor()` keys on the event envelope's `account` where present, falls back to the platform
processor for platform-level events, and **rejects** an unknown account rather than defaulting to
anything.

##### Rolling this out without losing live events

> **Do this now, in one change, without a staged rollout.** The environment is pre-production
> (step 01 §01.2.4): no tenants, no live revenue, no events that matter if they bounce. The cautious
> sequence below exists for the *post*-go-live case and for anyone reading this later — today it
> collapses to "run the preflight, fix what it says, ship it."
>
> The reason to do it now rather than later is specific: because the code fails open, a processor
> with no webhook secret **appears to work**. Every processor configured between now and go-live
> inherits that illusion, and each one is a tenant to contact after go-live instead of a config
> field to fill in today. The cost of this fix only goes up.

Post-go-live, flipping to fail-closed while a secret is unset turns every live webhook into a `503`.
Sequence it:

1. Ship `marketplace:check-webhook-config` — a read-only command listing **every processor across every tenant**, with provider, mode, whether a secret is present, and whether it decrypts. **Run it first**, and read the whole output rather than the summary line.
2. Fix whatever it reports. Pre-go-live that means filling in config; post-go-live it may mean contacting tenants whose processors were configured without a webhook secret. Confirm with a dashboard test delivery per endpoint.
3. Then deploy the fail-closed change.
4. Watch `processor_webhook_rejections` for the first 24h. A burst of `no_secret` means step 2 was
   incomplete; the events are still in Stripe's retry queue, so fix and let them redeliver.

**No config flag, no `WEBHOOK_STRICT=false` escape hatch.** A bypass switch on an authentication
control is how the fail-open state becomes permanent — it gets set once during an incident and never
unset. The preflight command exists so the switch is not needed.

#### Reliability

- **Idempotent by event id**, both receivers. Store handled ids; a duplicate delivery is a no-op.
- **Out-of-order delivery is normal.** Never derive state from the *event*; always re-fetch the
  account and write the fetched state. That makes ordering irrelevant by construction — the single
  most valuable property in this section.
- **Missed events happen.** A daily reconciliation sweep re-fetches every account not synced in 24h.
  Same command as §08.8's transfer sweep, extended.
- **Signature verification** on both receivers, with separate signing secrets. `hash_equals` only.
- **Ack fast, work async.** Return 200 immediately, queue the fetch-and-mirror. A slow handler
  becomes a retry storm, and retries plus non-idempotent handlers becomes duplicate notifications.
- **Risk reviews cannot be answered through the API** — Stripe is explicit: *"You can't use the API
  to respond to Stripe risk reviews."* A review-related event must route to a human queue, not an
  automated remediation path. Build the queue or the event is noise.

#### Bootstrapping

Registering the v2 event destination is setup, not runtime — an idempotent console command
(`marketplace:register-event-destinations`) that creates or updates the destination for the
configured event types, per environment, with the URL from config. Follow with `ping` to prove
delivery before trusting it.

Record the destination id on the processor row so it can be found, disabled and re-enabled without
hunting through the dashboard.

---

## 08.8 Failure paths

| Failure | Handling |
|---|---|
| Charge succeeds, transfer fails | Charge stands. Intent → `failed`; backoff retry; after 3, alert admin and notify seller. **Never refund the buyer for a transfer failure** — they received their goods. |
| Account restricted between checkout and transfer | Transfer held; funds stay in platform balance; ledger `pending_transfer`; retried when the account reports `charges_enabled`. |
| Platform balance insufficient | Retried next settlement. Alert. |
| Refund after transfer | `Transfer::createReversal` + `refund_application_fee`. Step 16 owns the ledger side. |
| Chargeback | Platform absorbs, reverses transfer, negative seller entry, dispute opened. |
| Webhook missed | Daily reconciliation walks `pending` intents older than 24h against Stripe. |
| Preview API version withdrawn or changed | Version is config; a startup check logs loudly if Stripe rejects it. Do not let this fail silently at account-creation time. |

---

## 08.9 Files

> **Tagging (step 01 §01.4) — every file below.** Created files carry
> `MARKET_MODIFIER_ADDITION` in their top doc block; modified files carry it at each changed line or
> region. `grep -rn MARKET_MODIFIER_ADDITION` must return this list in full, or the change index is
> already wrong.


**Created:** `resources/js/external/connect/{api,ConnectTypes,ConnectProfile,EmbeddedHost}.ts`
and `CONNECT_DOCUMENTATION.md`;
`app/Services/Marketplace/{ConnectProfileService,ConnectAccountService,SplitCalculator,TransferExecutor}.php`;
`app/Models/Externals/Processor/{ProcessorConnectProfile,ProcessorTransferIntent}.php`;
`config/marketplace.php`; migrations for `processor_connected_accounts`,
`processor_connect_profiles`, `processor_transfer_intents`; the reconciliation console command.

**Modified:** `ProcessorConnectController.php` (v2 rewrite), `ProcessorIntentController.php`,
`ProcessorWebhookController.php`, `routes/portal.php`,
`external/direct_payments/api.ts`, `external/processors/api.ts`,
`external/processors/StripeClient.ts`, `external/direct_payments/api.ts` (add the `connect` accessor to the DP instance — **not** `external/main.ts`; there is no new top-level `Portal` key),
`external/portal.d.ts` **and** `apps/market/resources/js/portal.d.ts` — **these two must stay
identical**; market consumes the declarations while brain builds the runtime, and drift between them
is a type-safe call into a method that does not exist. Note the obligation in both file headers.

**Dependency:** add `"stripe/stripe-php": "^21.2"` to `apps/www/composer.json` — it is **not
installed today** (§08.2.1). Earlier majors have no `lib/V2/Core/Account.php` and none of this step
compiles against them. The reference copy at `~/Downloads/stripe-php-master` is 21.2.0 and matches.
Landing this dependency requires the §08.2.4 dead-code audit first.

---

## 08.10 Scope option, if this step runs long

Ship **destination charges only**, and have market restrict the cart to one seller
("Items from different sellers must be checked out separately"). A real limitation, but an honest
one, and it removes `separate_transfers`, `ProcessorTransferIntent`, the transfer webhooks, and the
reconciliation command from the critical path.

If taken, record it in the completion report **as a deferral naming the multi-seller work**, not as
a completed step. Steps 13 and 16 both branch on this decision.

Do **not** apply the same reasoning to the Accounts v2 migration. Shipping new sellers onto the
deprecated `type` enum to save time means every one of them needs re-onboarding later — `dashboard`
and `country` are immutable, so there is no in-place fix.

---

## 08.11 Verification

- [ ] `composer show stripe/stripe-php` reports **≥ 21.2.0**, and `vendor/composer/autoload_psr4.php` contains a `Stripe\\` entry.
- [ ] **Dead-code audit done:** every `\Stripe\` call site in `ProcessorSyncController.php` and `FunctionsController.php` reviewed and signed off, and `POST .../sync/execute` gated or verified against a test-mode key **before** the dependency is deployed.
- [ ] `POST .../sync/execute` no longer deletes anything unintended — exercised once in test mode with the outcome recorded.
- [ ] Profile with `integration_mode: self_hosted` creates an account whose retrieved `dashboard` is `none` and whose `defaults.responsibilities.requirements_collector` is `application`. Assert on the API response, not on local state.
- [ ] Profile with `integration_mode: express` yields `dashboard: express` and `requirements_collector: stripe`.
- [ ] `self_hosted` + `losses_collector: stripe` is rejected at profile save, with the reason stated.
- [ ] `losses_collector: application` + `fees_collector: stripe` is rejected.
- [ ] `onboarding_mode: api` cannot be saved without the recorded acknowledgement.
- [ ] Embedded onboarding mounts and completes in-page for a `dashboard: none` account; the seller never reaches a `stripe.com` URL. Verify with the network log.
- [ ] Switching the profile's `onboarding_mode` from `embedded` to `hosted` changes no consumer code — the same `startOnboarding` call now returns `mode: 'hosted'` and redirects.
- [ ] An account with a `proof_of_liveness` requirement in `api` mode returns a populated `liveness_fallback`.
- [ ] Omitting `include` returns nulls; adding it returns values. Confirm both, so the behaviour is understood rather than worked around.
- [ ] Requests without the preview version string fail on `/v2/core/accounts`, and the failure is a clear logged error rather than a null account.
- [ ] `grep -n "url('/portal/admin')" ProcessorConnectController.php` → zero.
- [ ] `createSession()` without a valid service signature → `401`; for another member's account → `403`.
- [ ] `grep -rn "'type' => 'required|in:standard,express,custom'" app/` → zero.
- [ ] A buyer paying with a v1 `Customer` successfully funds a transfer to a v2 seller Account — the mixed case, exercised once end to end.
- [ ] `createIntent()` works **without** the preview version string; only `/v2/core/*` calls carry it. Confirm by inspecting the outbound headers, not the config.
- [ ] The subscription path (`chargeSubscription`, `ProcessorSubscriptionController`, tier fetch) is byte-unchanged and still works after this step lands.
- [ ] Status mapping reads `stripe_balance.stripe_transfers` / `stripe_balance.payouts` for v2 accounts and `transfers` / `payouts` for v1, keyed on `api_version`. Test with one account of each.
- [ ] Single-seller $40 purchase: `application_fee_amount = 400`, seller receives $36.00.
- [ ] Two-seller cart: one charge, two transfers, one `transfer_group`, amounts exact.
- [ ] Each of the five §08.7 invariants rejects its tampered payload **before** any Stripe call. Test all five separately.
- [ ] **The split path is capability-gated.** Simulate a server without split support and confirm the SDK **refuses** rather than charging with no transfer. Test deliberately — a passing happy path cannot reveal it.
- [ ] Every new webhook is idempotent under a duplicate delivery, on **both** receivers.
- [ ] The v2 event destination is registered via `/v2/core/event_destinations` and `ping` delivers successfully.
- [ ] A v2 event type registered on a **v1** webhook endpoint is confirmed **not** to deliver — so the split surface is understood, not assumed.
- [ ] `v2.core.account[requirements].updated` handler fetches with `include` and mirrors non-null `requirements` into the row. Verify the null case too by omitting `include` once.
- [ ] Triggering a requirement in test mode produces the correct seller notification for each row of the transition table.
- [ ] A company account with an unverified **person** produces a notification naming that person.
- [ ] `charges_enabled` true → false notifies urgently **and** states that existing buyer entitlements are unaffected.
- [ ] Events delivered out of order converge to correct state, because handlers re-fetch rather than read the payload.
- [ ] The deadline sweep fires reminders at 14/7/1 days independently of any event.
- [ ] A review-related event lands in a human queue, not an automated remediation path.
- [ ] **All six bypass conditions closed.** Test each explicitly: Stripe with no processor row; Stripe with an empty secret; Square with no processor row; Square with an empty secret; PayPal with a missing `webhook_id`; PayPal with a missing client id/secret. Every one must reject.
- [ ] A fabricated `charge.succeeded` creates no records and no `ProcessorWebhook` row.
- [ ] Missing configuration returns **`503`** (retryable) and a bad signature returns **`400`** — verified separately, because getting these the wrong way round converts an outage into permanent event loss.
- [ ] PayPal's verifier being unreachable returns `503`, not `400`, and never falls through to processing.
- [ ] Every provider's comparison uses `hash_equals` — read each one.
- [ ] A replayed event older than 300s is rejected; a legitimate Stripe retry inside the window is processed exactly once.
- [ ] Rejections land in `processor_webhook_rejections`, rate-limited per IP, storing a payload **hash** not the payload.
- [ ] `marketplace:check-webhook-config` reports accurately against a deliberately blanked secret.
- [ ] The v1 endpoint secret and the v2 event-destination secret are stored separately and neither overwrites the other.
- [ ] `resolveProcessor()` keys on the event envelope's `account`; an unknown account is rejected, not defaulted.
- [ ] No `WEBHOOK_STRICT`-style bypass exists anywhere in the shipped code.
- [ ] Refund with `reverse_transfer` + `refund_application_fee`: buyer refunded, transfer reversed, fee returned, ledger balanced, entitlement revoked.
- [ ] Refund **without** those flags is impossible through the application's own code path — verified by reading the call site, not by intent.
- [ ] Partial refund reverses proportionally and the fee refund still satisfies `fee + net == line_net`.
- [ ] A reversal against an insufficient seller balance produces a negative balance that the ledger and UI both represent honestly.
- [ ] `refund.failed` is handled and surfaces to an operator — the buyer must not be told a failed refund succeeded.
- [ ] `charge.dispute.closed` with `won` and with `lost` each produce the correct ledger outcome.
- [ ] A dispute's `evidence_details.due_by` drives escalating reminders, not a single notification.
- [ ] Marketplace disputes and card chargebacks are separately named in schema and services before either is implemented.
- [ ] Legacy v1 accounts still read and charge correctly alongside new v2 ones.
- [ ] `portal.d.ts` in brain and market are identical in the declarations added here.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/08_stripe_connect_brain_api_complete.md` written, stating the chosen `integration_mode` and `onboarding_mode` defaults and whether multi-seller shipped.
