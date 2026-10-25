# Step 01 — Start: audit, architecture, and the conventions every later step obeys

**On completion:** write `complete/01_start_complete.md` (see [complete/README.md](complete/README.md)).
**Depends on:** nothing. This is the first step.
**Produces:** no code. This step is the shared vocabulary and the measured starting point. Every
later step cites it instead of re-deriving it.

---

## 01.1 Why this step exists

Four projects, three of which already contain a *partial, mock* implementation of the thing we are
about to build for real. Writing code before writing down what already exists means one of two
outcomes: we rebuild something that already works, or we build on top of a mock and ship it.

Both have already happened once in this codebase — the members asset store has a full Laravel
controller, a full TypeScript controller, a full HTML template, and eleven registered API routes,
and **none of it touches a database**. It stores assets in the PHP session and in `localStorage`.
It looks finished from the outside. That is exactly the failure mode this step prevents.

So: measure first.

---

## 01.2 Current state, measured

### 01.2.1 members (`apps/members`)

| Surface | File | What it actually is |
|---|---|---|
| Asset store UI | `resources/js/templates/views/assetstore.html` | Real Bootstrap/Tabler markup — tables, modals, form fields. Usable. |
| Asset store logic | `resources/js/controllers/AssetStoreController.ts` (467 lines) | **`localStorage` only.** `saveAssetsToStorage()` writes `assetstore_assets`. The upload is a comment: *"Simulate file upload (in production, this would be actual file upload)"*. No network call anywhere in the file. |
| Asset store backend | `app/Http/Controllers/AssetStoreController.php` | **PHP session only.** `session("assetstore_assets_{$userId}")`. Files *are* written to disk (`storeAs("assets/{$userId}")`) but the records that point at them evaporate when the session does. Keyed on `Auth::id()`, not `member_reference_num`. |
| Freelance UI | `resources/js/templates/views/freelance.html` | Same shape. |
| Freelance logic | `resources/js/controllers/FreelanceController.ts` (439 lines) | Same — `localStorage` (`freelance_services`, `freelance_payments`, `freelance_account`). |
| Freelance backend | `app/Http/Controllers/FreelanceController.php` | Same session-mock shape. |
| Routes | `routes/web.php:75–99` | 22 API routes registered against the two mock controllers. |
| View registration | `resources/js/views.ts:610–640` | Routes `/services/market`, `/services/freelance`, `/services/assetstore`. |
| Controller wiring | `resources/js/main.ts:116–118` | `app.window.market`, `.freelance`, `.assetstore`. |
| Cross-project API precedent | `app/Http/Controllers/Brain/DomainController.php` | The pattern this plan copies: `Http::withHeaders(['Accept' => 'application/json'])->get($this->brainApiUrl() . '/dns/zones', ['member_reference_num' => $memberRef])`, base URL from `env('BRAIN_API_URL', …)`. |

**Migrations for assets or freelance in members: none.** `database/migrations/` contains users,
cache, jobs, user_files, domains, websites, deployment progress, progress records, checkpoint
tables, processor plans. Nothing marketplace-shaped.

> This matters for the brief. The instruction was "if there is already database migration tables
> for the freelance and assets just move them over to the market project and modify as necessary."
> There are none to move. The tables are created fresh in market (step 03). The *data* that exists
> today lives in browser `localStorage` and PHP sessions on individual machines — it is not
> migratable and is not worth migrating. Step 03 states this as an explicit non-goal.

### 01.2.2 market (`apps/market`)

Further along than members, and real — but built for a different identity model.

| Surface | File | State |
|---|---|---|
| Migrations | `database/migrations/2026_01_23_*` | `services`, `assets`, `categories`, `orders` exist and are real tables. |
| Identity | `assets.user_id` / `services.user_id` | **`foreignId('user_id')->constrained()`** — a FK to market's local `users` table. This is the single largest schema change in step 03: the identity must become `member_reference_num`, and market must have no local user records at all. |
| Models | `app/Models/{Asset,Service,Category,Order,User}.php` | Thin, `$fillable` + `$casts` + `belongsTo`. Fine as a base. |
| Storefront | `app/Http/Controllers/{Home,Service,Asset}Controller.php`, `resources/views/{home,services,assets}` | Working Blade + Tailwind storefront. Real. Keep and extend. |
| Checkout | `app/Http/Controllers/PurchaseController.php` | **Fake.** `processPayment()` validates a raw card number with `regex:/^\d{13,19}$/`, invents `'TXN-' . bin2hex(random_bytes(8))`, marks the order `completed`, and redirects. It never contacts a processor. Replaced wholesale in step 13. |
| SDK tag | `resources/views/layouts/app.blade.php:15` | `<script src="https://{{ $afssdk }}.appfactory.studio/sdk.js"></script>` — already there. |
| SDK variable | `app/Http/Controllers/HomeController.php:33` | `$afssdk = env('APP_ENV') === 'production' ? 'api' : 'api-sandbox';` — **passed by `HomeController` only.** `ServiceController`, `AssetController` and `PurchaseController` do not pass it, so every page except the home page renders `<script src="https://.appfactory.studio/sdk.js">`. That is a live 404 on the checkout page today. Fixed in step 11 by moving it to a view composer. |
| Auth client | `resources/js/checkpoint/`, `resources/js/checkpointHelper/` | Complete Checkpoint client, already vendored. `checkpoint/app-names.ts` already declares `MARKET: 'MARKET'`. |
| Auth entry | `resources/js/dashboard.ts` (48 lines) | **Copy-pasted from Dream Graph.** Calls `handler.buildLoginUrl('normal', CHECKPOINT_APPS.GRAPH_EDITOR)` and throws `'Dream Graph requires an authenticated Checkpoint session'`. Both wrong for market. Fixed in step 11. |
| Vite entries | `vite.config.ts:7` | `input: ['resources/css/app.css', 'resources/js/app.ts']` — `dashboard.ts` is **not built**. A portal entry must be added. |
| Routing | `bootstrap/app.php` | `withRouting(web:, commands:, health:)` only. No `routes/portal.php`, no `then:` closure. |
| Types | `resources/js/portal.d.ts` (1269 lines) | Full ambient declarations for `window.Portal`, including `PortalDirectPaymentsAPI` and `PortalDirectPaymentInvoice`. Types only — no runtime. The runtime comes from the `sdk.js` script tag. |
| `.env` | present | `APP_URL` / `ASSET_URL` = `https://market15.appfactory.studio`. **No** `MEMBERS_URL`, `BRAIN_URL`, `AUTH_URL`, no Direct Payments ref, no asset root, no internal API key. All added in step 02. |

### 01.2.3 brain (`apps/www`)

| Surface | File | State |
|---|---|---|
| Direct Payments SDK | `resources/js/external/direct_payments/api.ts` (1881 lines) | Mature. Invoice-first: `create(ref)` → `initializeDirectPayment()` → `createInvoice()` → `addItem()` → lock → `setupSinglePayPayment()` → `confirmPayment()`. Handles dynamic pricing, tiers, tax, discounts. |
| Processors SDK | `resources/js/external/processors/` | `ProcessorClient`, `StripeClient` (412 lines), `PayPalClient`, `SquareClient`, `api.ts`. |
| **Stripe SDK** | `composer.json` / `composer.lock` / `vendor/` | **NOT INSTALLED.** No `stripe/stripe-php` in `require`, none in the lock file, no `vendor/stripe/`, zero `Stripe` entries in `autoload_psr4.php`. (`core/services/stripe/` is Stripe's *sample apps* repo, not the SDK.) |
| Stripe access — working | 7 Processor controllers | **Raw cURL**, 29 call sites to `https://api.stripe.com/v1/...`. This is the code that runs. |
| Connect controller | `app/Http/Controllers/Externals/Processor/ProcessorConnectController.php` (249 lines) | **Cannot execute.** All five methods call `\Stripe\*`, which is unresolvable — every one throws `Class "Stripe\Stripe" not found`. The five Connect routes point at dead code. Same for `ProcessorSyncController`. |
| Connect routes | `routes/portal.php:520–524` | `GET/POST /api/v1/processors/stripe/connect`, `DELETE /{id}`, `POST /{id}/onboarding`, `POST /{id}/session`. |
| **The gap** | `app/Http/Controllers/Externals/Processor/ProcessorIntentController.php` | `createIntent()` contains **no** `application_fee_amount`, **no** `transfer_data`, **no** `on_behalf_of`, **no** `stripe_account`. Combined with the row above: money cannot reach a seller today, and the code that would create the accounts has never run. Step 08 §08.2 has the full measurement. |
| Secondary gap | `ProcessorConnectController::getOnboardingLink()` | `$returnUrl = url('/portal/admin'); $refreshUrl = url('/portal/admin');` — hardcoded, with the code's own comment admitting *"A realistic application would use its own dynamic URLs based on environment"*. |
| Identity | `app/Models/Membership.php`, `brain_memberships` table | `reference_num` is the cross-project identity. |
| Legacy Stripe scripts | `core/scripts/includes/AppfactoryStudio/Plugins/StripeModule.php`, `core/scripts/includes/services/payments/stripe/stripe_payments.php` | Standalone PHP, **outside Laravel's autoloader**. They resolved `\Stripe\` through the `include.php` → `init.php` chain, which loaded a vendor autoloader from `core/scripts/includes/libs/payments/`. **That folder has been removed** (confirmed 2026-08-18), so these now fail too — and a `composer require` at the Laravel root does **not** fix them, because their require chain never reaches `vendor/autoload.php`. **Now covered by [step 20](20_retire_legacy_stripe_scripts.md)**, which found six such files, a second vendored SDK (17.6.0, still present under `public/_portal_/`), 30 routed legacy endpoints, and one live consumer in the legacy `afssdk.js`. |
| Playground | `public/playground/direct-payments/{single_charge,subscription,dashbaord}` | Each is an `.html` + an `-example.js`. `single_charge/direct-payments-charge-example.js` sets `window.Portal.httpUrl = 'https://www15.appfactory.studio'` and uses DP ref `eq4W10iF8`. This is the shape step 09 copies. (Note the existing folder typo `dashbaord` — leave it; renaming it is unrelated churn.) |

### 01.2.4 joystick_engine (`JoystickEngine_v2`)

| Surface | File | State |
|---|---|---|
| Config | `Resources/EngineConfig.json` | Exists. Sections: `checkpoint`, `dreamGraph`, `afsConnect`. Copied into both app bundles by `JoystickWindow/CMakeLists.txt:174` and `JoystickLauncher/CMakeLists.txt:121`. |
| Config loader | `Joystick/src/Joystick/Core/EngineConfig.h/.cpp` | Meyers-singleton, load-once, tolerant of a missing file *and* a missing section. Structs: `DreamGraphSection`, `AfsConnectSection`, `CheckpointSection`. A `MarketSection` slots straight in. |
| Panels | `JoystickWindow/src/Panels/` | 25 panels. `ConnectPanel` and `DreamGraphSyncPanel` are the structural models for a network-backed panel (`DrawContent()` only; the caller owns `ImGui::Begin/End`). |
| Panel registry | `JoystickWindow/src/EditorLayer.h:249` | `enum class PanelId { …, Network, Count };` — append before `Count`. |
| Menu | `JoystickEditorCore/Source/EngineMenus.cs` | `RegisterDynamicItems()` walks `MenuBridge_PanelCount()` and builds `Windows/Panels/<Category>/<Name>`. `TopLevelOverridePathFor(panelName)` promotes a panel to its own path — `"Connect" => "Settings/Connect"`. **This is the exact hook** for `"Service Assets Market" => "Windows/Service Assets Market"`. No new menu machinery is needed. |
| HTTP | `Joystick/src/Joystick/Net/HttpClient.h/.cpp` + `Platform/{MacOS,Windows}/HttpClient.*` | Existing cross-platform client. |
| Identity | `Joystick/src/Joystick/Connect/ConnectIdentity.h` | Process-wide Checkpoint identity, polls the per-user session file. Gives us the signed-in member without a new login flow. |
| Async pattern | `Joystick/src/Joystick/Connect/ConnectService.h` | Background worker + main-thread snapshot. The pattern the market client must follow. |
| Assets | `Joystick/src/Joystick/Asset/{AssetLibrary,AssetMeta}` | `.jmeta` sidecars, `Project::GetAssetDirectory()`. |
| Localization | `JoystickWindow/Resources/lang/{en,es}.json` | Must stay at **exact key parity**. Adding a key to one without the other is a defect. |

---

## 01.2.4 All work happens in development

Every project runs against its development environment: the `*15` hostnames, the `testdb_*`
databases, the development `.env`. Production deployment is a file swap (`_env/prod/.env`) that the
project owner audits at deploy time — see step 02 §02.8 for the recorded values.

**Environment correctness is not a plan concern.** No step verifies it, blocks on it, or treats a
mismatched value as a defect.

Two consequences that *are* plan concerns, because they affect what gets built rather than how it is
deployed:

- **Nothing is serving live traffic yet**, so the destabilising fixes — fail-closed webhook auth, the
  `Processor::first()` multi-tenancy fix, the Accounts v2 migration — are cheap now and expensive
  later. Do them decisively rather than staging cautious rollouts against an empty environment.
- **Correctness requirements are unaffected.** Both flags on a destination-charge refund, the v2
  capability rename, the fee arithmetic: all still exactly as specified. Pre-production changes how
  carefully you roll out, never whether the code is right.

---

## 01.2.5 Brain is a platform, and tenants are *deployments* — not rows

The most consequential fact in this architecture, and the one least visible from the repository.

`www.appfactory.studio` is the **brain**. It holds the member records — `brain_memberships` lives
only here. When a member signs up at `members.appfactory.studio` and creates a website, they get a
**blank duplicate of the brain**: `apps/wwwafsportal_v1`, provisioned to its own server and its own
domain (`www.example.com`), with **fresh migrations**, **its own login system**, and some
brain-specific pieces stripped during duplication. It calls the brain for what it does not own —
chiefly which member the website domain belongs to.

So multi-tenancy here is **by deployment, not by database row**. Each tenant runs their own copy of
the portal software, with their own database, their own processors, their own Direct Payments, their
own admin.

### How the SDK decides which server to call

Three routing modes, and knowing which applies is what makes the rest of this section precise.

| Mode | Mechanism | Resolves to |
|---|---|---|
| **Relative (default)** | `Portal.httpUrl` defaults to `window.location.origin` (`external/main.ts:109`), and calls are built as `${Portal.httpUrl}${baseUrl}` | **The site the page is served from.** On a tenant site, that is the tenant's own Laravel |
| **Explicit managing site** | The page sets `window.Portal.httpUrl` before `sdk.js` loads | Whatever it names |
| **Brain, always** | Hardcoded — `BrainClient.ts:27–28` (`www15` / `www`), `documents/api.ts:371` (`api` / `api-sandbox`) | Brain, regardless of host |

So the SDK assumes the hosting site *is* the managing site unless told otherwise, and reaches for
brain explicitly only where brain is the only possible answer.

**This is why market's checkout is safe, and the reason is a single line.** Market sets
`window.Portal.httpUrl = '{BRAIN_URL}'` in its blade layout (step 11 §11.3), so every processor call
from market's checkout goes to **brain's** Laravel, which is always current. Remove that line and
market's checkout would call market's *own* Laravel, which has no processors API at all — a 404, not
a subtle failure. It is load-bearing, not configuration hygiene.

### Deployment skew — small, and mostly theoretical

The SDK is served centrally from brain, so an SDK change reaches every site immediately. Tenant
Laravel code lives on the tenant's own server, cloned from a per-member Gitea repo at provisioning
(`app_install.sh:457`, `:966`).

**A mechanism updates clones when brain updates**, so tenant servers track brain rather than
diverging. What that leaves is ordinary release hygiene:

- **Renaming or removing a request field is a coordinated change** — SDK and servers move together,
  same as any versioned deployment. Avoid it casually; it is not forbidden.
- **There is a propagation window.** The SDK updates instantly; clones update through the mechanism.
  Between the two, a new SDK can reach a not-yet-updated server, and a site whose update *failed* is
  behind until someone notices.

That window is the only real exposure, and for this plan it is theoretical: market and members set
`httpUrl` to brain, so they always call a current server, and the split path is only exercised by a
marketplace integration — which no tenant runs.

**So the capability check on `split` (§08.7) is cheap insurance, not load-bearing.** Keep it, because
the failure it prevents is silent — a server that ignores `split` returns a successful charge with no
transfer, and nobody is paid — but do not let it drive the design of anything else.

### What this means in practice

- **The marketplace is unaffected**, because market and members set `httpUrl` to brain. Steps 03–20
  need nothing from this section.
- **Step 08 edits `resources/js/external/`**, which ships centrally. Keep changes additive where it
  costs nothing, and treat a rename as a coordinated release rather than an ordinary edit.
- **Connect should be on the strip list.** Brain-specific pieces are already removed during
  duplication; the marketplace Connect surface belongs with them, and §08.5.9's flag defaults to off
  in any case.

### First-party consumers

Three, all Appfactory Studio's own, all talking to brain's deployment:

| Consumer | Uses | Status |
|---|---|---|
| **www** | `resources/js/afs/main.ts` → `Portal.directPayments.setupSubscription()`; `create.ts` completes with `getStoredSubscriptionId()` / `customerId` / `allocId` / `memberRef` | Complete and working in dev; becomes membership revenue at go-live |
| **members** | A Stripe processor configured for connected accounts — seller onboarding, verification, payouts | Being built |
| **market** | Buyer checkout with a Connect split | Being built |

---

## 01.3 Architecture decisions

### D1 — Market is the only database

Members stores nothing marketplace-shaped. Its controllers become **proxies**: they take a request
from the browser, attach the member's `reference_num`, sign it, forward it to market, and return
market's answer. No local table, no local cache, no "sync" job.

*Why.* A cache is a second source of truth, and a second source of truth for money is a
reconciliation project. Members already proves the cost of the alternative: two mock stores
(session + `localStorage`) that already disagree with each other today.

*Cost, stated honestly.* Every members page load becomes an outbound HTTP call, and market being
down means the members asset store is down. That is the correct failure: a seller cannot
meaningfully manage a listing whose system of record is unreachable. Steps 04 and 06 specify the
loading and error states this requires rather than pretending the call is free.

### D2 — `member_reference_num` is the only identity

No `user_id`, no email, no Checkpoint user id in any marketplace foreign key. `reference_num` from
`brain_memberships` is the join key across all four projects, in every table, in every API payload,
and in every engine request.

Market's `users` table stays for Laravel's framework plumbing only and gains **no** marketplace
relationships. Step 03 drops `assets.user_id` and `services.user_id`.

### D3 — Money is brain's, always

Market never talks to Stripe. Market creates an invoice through Direct Payments and reacts to the
result. The 10% platform fee is computed by market (it owns the price and the order) and *applied*
by brain (it owns the processor). Step 16 pins down which side owns which number so the two cannot
drift.

### D4 — Not every asset is an engine package

`distribution_tag ∈ {joystick_engine, other}` is set at publish time. The engine's endpoints filter
on it server-side, not client-side. An `other`-tagged asset must be invisible to the engine even to
a member who owns it — the filter is part of the query, never a UI condition.

Services are never installable. The engine lists purchased services read-only and links out to the
market portal in the system browser.

### D5 — URLs come from environment, everywhere

No string literal containing `appfactory.studio` in any file this plan creates, in any of the four
projects. Laravel reads `config('market.*')`, never `env()` at call time — because `php artisan
config:cache` makes `env()` return `null` in production and the failure is silent. The engine reads
`Resources/EngineConfig.json`. TypeScript reads a server-rendered config object, never a literal.

*Note:* the existing `HomeController.php:33` uses `env()` directly. Step 11 replaces it. New code
does not copy it.

---

### D6 — The encapsulation boundary: TypeScript is the client, Laravel manages the processors

**Project owner's rule, and the one architectural constraint this plan is actually held to.**

| Layer | Owns | Must never |
|---|---|---|
| `apps/www/resources/js/external/**` — compiled to `public/external/api/sdk.js`, exposed as `window.Portal.*` | The **client-side system**. Invoice construction, item and cart shape, mounting payment elements, mounting Connect components, calling brain's own HTTP API. | Hold a secret key. Call `api.stripe.com` (or PayPal/Square) directly with anything but a publishable key. Contain business rules that decide money. |
| `apps/www/app/**` — controllers plus **service classes** | **Processor management.** Secret keys, PaymentIntents, transfers, refunds, Connect accounts, webhooks, fee arithmetic, and every validation that protects money. | Trust a number the client supplied. Push provider-specific logic back into the browser. |

Everything else follows from this:

- **The client proposes, the server disposes.** The SDK computes a split preview so the checkout can
  render it; the server recomputes it and validates before charging (§08.7's five invariants). The
  two agreeing is a UX nicety; the server's copy is the truth.
- **Provider logic belongs in service classes**, not inline in controllers. `SplitCalculator`,
  `TransferExecutor`, `ConnectAccountService`, and a `{Stripe,PayPal,Square}Gateway` behind a common
  interface. A controller validates, delegates, responds.
- **`dp.connect` is a client, not a Connect implementation.** It reads, mounts and requests; every
  state-changing call lands on a Laravel endpoint. It hangs off a Direct Payment instance rather than
  the global, so the processor is resolved once (step 08 §08.5.1).
- **Whether a gateway uses the SDK or raw cURL is an implementation detail.** The boundary is what
  matters, not the transport.

**On backward compatibility:** prefer additive changes, but do not contort a design to avoid
touching shared code. There are no third-party tenants yet (§01.2.4), and getting the encapsulation
right matters more than preserving a call signature nobody depends on.

Two things stay untouched regardless, because they work and nothing in this plan needs them changed:
the subscription path (`setupSubscription`, `ProcessorSubscriptionController`, the tier handling) and
the `customer` path for buyers (§08.7.2, §08.7.3).

### D7 — A shared database is not a shared schema. Members calls brain's API.

**Project owner's rule, and it overrides convenience.** brain and members run against the same
database (`afsdb_appfactorystudio` / `testdb_appfactorystudio`), so members' connection *can* reach
brain's tables. It must not.

The line is drawn by **migration ownership**, not by reachability:

| Table | Owner | How members accesses it |
|---|---|---|
| Anything in `apps/members/database/migrations/` — `users`, `user_files`, `laravel_domains`, `members_websites`, `laravel_website_deployment_progress`, `progress_records`, the Checkpoint tables | **members** | Direct Eloquent. It is members' own schema. |
| `brain_memberships`, `brain_dns_zones`, `brain_config`, `brain_http_servers`, and every other `brain_*` table | **brain** | **HTTP, through `BRAIN_API_URL`.** Never a query, never a join, never a raw `DB::table()`. |

*Why, given the connection would work.* A direct query is a schema dependency with no contract. It
binds members to brain's column names, cannot be versioned, is invisible to brain's own tests, and
silently breaks the day brain refactors a table or moves to its own database. The API is the
contract; the shared connection is an implementation accident that happens to be true today.

**This is already the established pattern**, not a new imposition.
`members/app/Http/Controllers/Brain/DomainController.php` reads DNS zones by calling
`$this->brainApiUrl() . '/dns/zones'` rather than selecting from `brain_dns_zones` — while querying
`members_websites` directly through `App\Models\Brain\Website`, because that table is members'
own. Follow that split exactly.

**Consequence for this plan:** any statement that members "reads `brain_memberships` locally" is
wrong. Where members needs membership data it either already has it from the Checkpoint session or
it asks brain. If a new marketplace-related table would sit naturally in members, it must be added
to members' own migrations to be queryable — otherwise it belongs behind an API.

The same rule governs market, which has its own database and therefore no temptation.

## 01.4 Conventions — binding on every later step

### The tag

Every file this plan creates or modifies carries the marker `MARKET_MODIFIER_ADDITION`.

- **File created:** the marker goes in the file's top doc block, on its own line.
- **File modified:** the marker goes on, or immediately above, each changed line or region.
- Contiguous regions get one marker at the top of the region and a `MARKET_MODIFIER_ADDITION END`
  at the bottom when the region is longer than ~20 lines.

**Scope: the three Laravel projects only — brain, members, market.** `JoystickEngine_v2` is
**excluded**; it has its own established documentation conventions and this marker must not appear
there. Step 18's verification asserts a zero-hit grep across that repository.

Comment syntax per language: `/** MARKET_MODIFIER_ADDITION */` (PHP/TS),
`{{-- MARKET_MODIFIER_ADDITION --}}` (Blade), `<!-- MARKET_MODIFIER_ADDITION -->` (HTML),
`# MARKET_MODIFIER_ADDITION` (env/yaml/sh).

The point is `grep -rn MARKET_MODIFIER_ADDITION` returning a complete, honest change index.

### Documentation

`/** … */` block comments on every file, class, method, migration, route group and non-obvious
branch. Match the density already in `members/app/Http/Controllers/Brain/DomainController.php` and
`JoystickWindow/src/Panels/ConnectPanel.h` — those files explain *why*, not *what*. Record the
reasoning that would otherwise be lost: why a column is nullable, why a call is not cached, why a
seller cannot publish before onboarding completes.

### Things not to do

- Do not modify `apps/auth`. Read-only reference.
- Do not run git operations in `apps/*` — no branching, staging, or committing.
- Do not touch `apps/wwwafsportal_v1` (dead v1 copy).
- Do not build the engine's `Sandbox` / `JoystickSandbox` target, or anything on the legacy non-Filament rail.
- Engine interactive verification uses `~/Documents/joystick_projects/MyFirstProject`.

---

## 01.5 Glossary

| Term | Meaning here |
|---|---|
| **Asset** | A downloadable package a member sells. Zipped, versioned, licensed, tagged for engine or general distribution. |
| **Service** | A freelance offering (a "gig"). Never downloadable; fulfilled through an order lifecycle. |
| **Gig package** | Basic / Standard / Premium tier of a service — price, delivery days, revision count, feature list. |
| **Entitlement** | The durable record that a buyer owns a thing. Survives price changes, unpublishing, and seller departure. What `/portal` and the engine both read. |
| **Distribution tag** | `joystick_engine` or `other`. Server-side filter for engine visibility. |
| **EULA / license** | The end-user license attached to an asset. A system default, or a member-authored one. Snapshotted into the entitlement at purchase. |
| **Platform fee** | 10% of each purchase, retained by Appfactory Studio. Disclosed to the seller at publish time. |
| **Direct Payment ref** | `eq4W10iF8` — the brain-side Direct Payment configuration id. From env, never inline. |
| **`$afssdk`** | `api` in production, `api-sandbox` otherwise. The subdomain of the `sdk.js` script tag. |
| **Connected account** | A seller's Stripe Connect account, created and onboarded through brain. |
| **Package (engine)** | An installed asset inside a Joystick project, tracked in a per-project lockfile. |

---

## 01.6 Exit criteria

- [ ] Every table in §01.2 has been re-verified against the working tree (files move).
- [ ] D1–D5 are agreed, or amended here with the reasoning recorded before step 02 begins.
- [ ] Steps 02–19 have been read end to end so the shape is known before the first line of code.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/01_start_complete.md` written.
