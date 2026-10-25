# Market Plan — Index

**Execution home.** Every step in this multi-project effort is executed from **this folder**
(`apps/afsserver/plans/market/`). The Joystick Engine step is *duplicated* into
`JoystickEngine_v2/plan/steps/090_service_assets_market.md` so it is visible in the engine's own
step index — but that copy is a mirror, not a second workstream. The plan of record is here.

**Naming.** `NN_step_name.md`, two-digit, ordered by execution.

**Completion reports.** When a step finishes, its report is written to
`complete/NN_step_name_complete.md` using the template in [complete/README.md](complete/README.md).
A step is not "done" until its completion file exists. The engine step additionally appends to
`JoystickEngine_v2/plan/_guides/steps_plan_execution/something24-step-90-service_assets_market.md`,
which is that repo's own convention.

---

## The four projects

| Alias | Path | Stack | Role in this plan |
|---|---|---|---|
| **brain** | `apps/www` | Laravel + TS externals | Payments. Owns Direct Payments, the processors layer, Stripe Connect, and `brain_memberships` (the `reference_num` every other project keys on). |
| **members** | `apps/members` | Laravel + TS SPA shell | Seller-facing. Where a member authors and publishes an asset or a freelance service. Stores **nothing** — proxies to market. |
| **market** | `apps/market` | Laravel + Blade + TS | The storefront **and the system of record**. Every asset, service, order, entitlement and ledger row lives in market's database. Hosts `/portal`, the buyer dashboard. |
| **joystick_engine** | `JoystickEngine_v2` | C++ / Coral C# / ImGui | Consumer. `Windows ▸ Service Assets Market` — a Unity-Package-Manager-shaped panel that installs assets the signed-in member has purchased. |

Not in scope, do not modify: `apps/auth` (Checkpoint IdP). It is referenced only as the login
redirect target.

---

## The flow this plan builds

```
  members                market                     brain                 joystick_engine
  ───────                ──────                     ─────                 ───────────────
  author asset  ──┐
  author service ─┼─ POST internal API ──▶ row created (draft)
                  │                             │
  publish ────────┘                             ├──▶ listed on storefront
                                                │
                                    buyer adds to cart
                                                │
                                    checkout ───┼──▶ Direct Payments invoice
                                                │      (Stripe Connect split:
                                                │       90% seller / 10% platform)
                                                │◀───── paid
                                                │
                                    Order + Entitlement created
                                                │
        seller stats ◀── callback ──────────────┤
                                                ├──▶ buyer /portal dashboard
                                                │
                                                └──▶ engine: entitlements filtered to
                                                     distribution_tag = joystick_engine
                                                     → download → verify → install
```

---

## The one convention that applies to every step

**Every file this plan creates or modifies carries `MARKET_MODIFIER_ADDITION`.**

- **Created** → the marker in the file's top doc block, on its own line.
- **Modified** → the marker on, or immediately above, each changed line or region. Regions longer
  than ~20 lines close with `MARKET_MODIFIER_ADDITION END`.

Per language: `/** MARKET_MODIFIER_ADDITION */` (PHP, TS) · `{{-- … --}}` (Blade) ·
`<!-- … -->` (HTML) · `# …` (env, yaml, sh).

**Scope: brain, members and market only.** `JoystickEngine_v2` is excluded — it follows its own
documentation conventions, and step 18 verifies the marker never appears there.

This is not bookkeeping. Four codebases are being modified at once, much of it inside files that
already work, and `grep -rn MARKET_MODIFIER_ADDITION` is the only complete answer to *"what did this
effort touch?"* — for testing it, debugging it, or reverting a piece of it. A file changed without
the tag is invisible to that question.

Each step restates it against its own Files section, and each step's verification reconciles the
grep against that list in both directions: a listed file without a tag, and a tagged file not in the
list, are both defects. Full rules in [step 01 §01.4](01_start.md).

---

## Prerequisites — install before step 08

These are external dependencies, not work items. Nothing in the plan creates them, and step 08 does
not compile without the first one.

| # | Prerequisite | Project | Why |
|---|---|---|---|
| P1 | **`stripe/stripe-php` `^21.2`** added to `composer.json` and installed | brain (`apps/www`) | **Not installed today** — no entry in `composer.json` `require`, none in `composer.lock`, no `vendor/stripe/`, zero `Stripe` entries in `vendor/composer/autoload_psr4.php`. Accounts v2 lives in `lib/V2/Core/` and `lib/Service/V2/Core/`, which exist only from major 21. Step 08 §08.2 has the full measurement. |
| P2 | Stripe **test-mode** platform account with Connect enabled | brain | Every verification step in 08–10, 13 and 16 runs in test mode. |
| P3 | Accounts v2 access on that platform | brain | v2 is *"broadly available to Connect platforms, and to other users in preview"*. Confirm before designing around it. |
| P4 | `/mnt/node2` mounted and writable by the web user | market | Step 05's storage root. |
| P5 | Both member refs present in `brain_memberships` | brain | Seller `m1xTHpRmdtH2Y`, buyer `m1xJppRmdtH2Y`. Already exist — do not create. |

### P1 — the install

```bash
composer require "stripe/stripe-php:^21.2" --no-scripts --no-interaction
```

Run from `apps/www`. `stripe-php` requires `php >=7.2` with `ext-curl`, `ext-json` and
`ext-mbstring`; the project is on PHP 8.4 and satisfies all three. `--no-scripts` because the
`post-autoload-dump` hook runs `php artisan package:discover`, and `stripe-php` ships no Laravel
service provider — discovery buys nothing here and only adds a way for the install to fail against
`.env` or the database. Follow with `composer dump-autoload -o` if the optimized classmap matters.

Pin `^21.2` rather than `*`: major 21 is where `lib/V2/Core/Account.php` (with `DASHBOARD_NONE`) and
`lib/Service/V2/Core/AccountService.php` (with the `dashboard` and `defaults.responsibilities`
params) first appear. An earlier major resolves cleanly and then fails at the first v2 call.

> ### ⚠️ P1 has two blocking pre-conditions
>
> **1 — Step 08 §08.2.4's dead-code audit.** Installing the SDK makes previously-unresolvable
> `\Stripe\` code **executable**, including destructive calls in `ProcessorSyncController`
> reachable from `POST .../sync/execute`. Audit and gate those call sites before the dependency is
> deployed. This is a property of the install, not a step-08 detail.
>
> **2 — [Step 20](20_retire_legacy_stripe_scripts.md) §20.4.** Confirm the deployed host's Stripe
> webhook endpoints are repointed at `/portal/webhooks?type=stripe` in live *and* test mode before
> the new SDK path goes out.
>
> P1 does **not** conflict with the legacy 17.6.0 vendor tree under `public/_portal_/` — separate
> autoloaders, separate processes. Three Stripe SDKs will coexist until step 20 finishes. Step 20
> §20.1 explains why consolidating them early is the wrong move.

---

## Steps

| # | File | Scope | Status |
|---|---|---|---|
| 01 | [01_start.md](01_start.md) | Current-state audit of all four projects, architecture decisions, glossary, and the conventions every later step obeys (`MARKET_MODIFIER_ADDITION`, `/** */` docs, no hardcoded URLs, `member_reference_num` as the only identity) | ○ planned |
| 02 | [02_environment_and_config.md](02_environment_and_config.md) | Every `.env` key in all four projects, the Laravel `config/` files that wrap them, the Vite mirrors, and the engine's `Resources/EngineConfig.json` `market` section | ○ planned |
| 03 | [03_market_database_schema.md](03_market_database_schema.md) | Market's full schema: reshape `assets`/`services`/`orders` onto `member_reference_num`, plus licenses, versions, entitlements, gigs, jobs, ledger | ○ planned |
| 04 | [04_internal_cross_api.md](04_internal_cross_api.md) | The signed members ↔ market internal API, modeled on `members/app/Http/Controllers/Brain/DomainController.php` with HMAC service auth | ○ planned |
| 05 | [05_asset_storage_and_upload_pipeline.md](05_asset_storage_and_upload_pipeline.md) | `/mnt/node2/market/members/{ref}`, client-side filename analysis, zipping, server re-validation, signed downloads | ○ planned |
| 06 | [06_members_asset_publishing.md](06_members_asset_publishing.md) | Rewrite `AssetStoreController.ts` into a real publish wizard: files, EULA, distribution tag, price/tax, 10% fee disclosure | ○ planned |
| 07 | [07_members_freelance_publishing.md](07_members_freelance_publishing.md) | Rewrite `FreelanceController.ts` into Fiverr-style gig authoring: packages, extras, gallery, FAQ, requirements | ○ planned |
| 08 | [08_stripe_connect_brain_api.md](08_stripe_connect_brain_api.md) | **Runs first.** Connect on **Accounts v2** (`dashboard: none` for full self-hosting), configured per-processor in the Processor dashboard, surfaced as **`dp.connect`** behind Direct Payments, provider-agnostic, gated by feature flag + member level. Adds the **`CONNECT` pay_type**. Expect rework | ○ planned |
| 09 | [09_direct_payments_connect_example.md](09_direct_payments_connect_example.md) | **Standalone.** Two playground examples — `connect_setup/` (configure + onboard) and `marketplace_split/` (pay + split) — matching `single_charge`'s shape | ○ planned |
| 10 | [10_members_connect_onboarding.md](10_members_connect_onboarding.md) | Fully white-labelled seller onboarding in the members dashboard via Connect embedded components — no Express dashboard, no Stripe redirect; gates paid publishing on `charges_enabled` | ○ planned |
| 11 | [11_market_portal_auth.md](11_market_portal_auth.md) | `routes/portal.php` + `bootstrap/app.php` wiring; auth is **client-side against auth15** (no Laravel session, no local users), with data endpoints verifying the token via `/auth/token/verify`; `$afssdk` on **every** page | ○ planned |
| 12 | [12_market_portal_dashboard.md](12_market_portal_dashboard.md) | The `/portal` buyer dashboard: purchases, downloads, licenses, service orders | ○ planned |
| 13 | [13_market_cart_and_checkout.md](13_market_cart_and_checkout.md) | Cart + checkout on a **`CONNECT` Direct Payment**: market's server builds the invoice from its own `order_items` and posts it to brain signed, so prices never reach the browser. Typed against `portal.d.ts`, runtime from the `sdk.js` tag | ○ planned |
| 14 | [14_market_storefront_and_listings.md](14_market_storefront_and_listings.md) | Public browse/search/detail/seller pages, EULA display, reviews | ○ planned |
| 15 | [15_freelance_order_lifecycle.md](15_freelance_order_lifecycle.md) | The complex freelancing system: orders, milestones, deliverables, revisions, messaging, disputes, job posts and proposals | ○ planned |
| 16 | [16_payouts_fees_and_ledger.md](16_payouts_fees_and_ledger.md) | Fee math, the earnings ledger, payouts, refunds/reversals, and the seller-stats callback into members | ○ planned |
| 17 | [17_seed_data.md](17_seed_data.md) | Seeded fake assets and services for the fixed seller/buyer member refs | ○ planned |
| 18 | [18_joystick_engine_service_assets_market.md](18_joystick_engine_service_assets_market.md) | `Windows ▸ Service Assets Market` — the engine panel, client, cache and installer. Mirrored to `plan/steps/090_service_assets_market.md` | ○ planned |
| 19 | [19_end_to_end_verification.md](19_end_to_end_verification.md) | The full author → publish → buy → dashboard → install walkthrough, with a defect log | ○ planned |
| 20 | [20_retire_legacy_stripe_scripts.md](20_retire_legacy_stripe_scripts.md) | **Parallel track, runs with 08.** `core/scripts/includes/` already deleted by the project owner; `public/_portal_` out of scope. What remains: verify the deletion was clean, remove three orphaned files, and inventory + repoint the deployed host | ○ planned |
| 21 | [21_freelance_project_workspace.md](21_freelance_project_workspace.md) | **Additive, runs after 16.** Promote a freelance order into a real coordinator project: schedule, budget, team, and a cost/schedule dashboard fed by market's ledger. Market joins afs-connect as a `provider` source; coordinator gains a narrow write surface and a command channel back. Touches coordinator and members, not brain | ○ planned |

### Execution order — file numbers are identifiers, not sequence

**Read this, not the numbering.** The files are numbered by topic and were written in that order;
they are **not** executed in it. Renumbering would break cross-links across twenty-two files for no
gain, so the running order lives here instead.

#### Phase 1 — Stripe Connect, end to end, proved in the playground

The whole of phase 1 happens in **brain** (`apps/www`). Nothing in market or members is touched.

| # | Do | File |
|---|---|---|
| 1.1 | Read the audit and the architecture decisions. No code. | [01](01_start.md) |
| 1.2 | Brain's `.env` + `config/marketplace.php` — **two keys and two URLs only**; every processor setting is database-held (§02.4.1) | [02 §02.4](02_environment_and_config.md) |
| 1.3 | Install `stripe/stripe-php ^21.2`, after the §08.2.4 dead-code audit | [00 P1](#prerequisites--install-before-step-08) |
| 1.4 | **Processor dashboard: Connect configuration** — behind the `connect` feature flag (default **off**) and levels 99/5 only, client- **and** server-side (§08.5.9). Add a Connect Settings panel to `processor-details.html` / `ProcessorDetailView.ts` / `ProcessorsService.ts` (**not** the dead `schemas/` folder — §08.2.5), validate via `ConnectProfileService` in `ProcessorAdminController`, store in `extra_config.connect`, and configure the marketplace Stripe processor. **Everything downstream reads its settings from this record — nothing from `.env`.** | [08 §08.3](08_stripe_connect_brain_api.md) |
| 1.5 | **Direct Payment ↔ processor assignment — ✅ exists.** `#dpProcessor` select → `laravel_direct_payments.processor_reference_num` → SDK at `api.ts:534`. Assign the marketplace processor to the marketplace Direct Payment. **Two one-line defects to fix while here:** the dropdown does not filter on `is_active` despite its label, and the fetch-failure fallback stores the numeric `id` instead of `reference_num` (§08.5.2). | [08 §08.5.2](08_stripe_connect_brain_api.md) |
| 1.6 | **The external TypeScript API** — `resources/js/external/connect/` with `providers/{Stripe,PayPal,Square}ConnectProvider.ts`, surfaced as **`dp.connect`** on the Direct Payment instance (not a top-level global), declared in `external/portal.d.ts` **and** market's copy | [08 §08.5](08_stripe_connect_brain_api.md) |
| 1.7 | **The Laravel server side** — `ProcessorConnectController` rewritten against Accounts v2, plus the service classes (`ConnectProfileService`, `ConnectAccountService`, `SplitCalculator`, `TransferExecutor`), migrations, routes | [08 §08.6](08_stripe_connect_brain_api.md) |
| 1.8 | Webhook receivers: the v2 event destination, the verification-lifecycle handlers, and the fail-open fix | [08 §08.7.5](08_stripe_connect_brain_api.md) |
| — | ⏸ **BUILD HANDOFF — project owner compiles `resources/js/external` into `public/external/api/sdk.js`.** See below. | |
| 1.9 | **`playground/direct-payments/connect_setup/`** — configure a Connect profile, create an account, onboard it embedded, mount the components | [09 §09.2](09_direct_payments_connect_example.md) |
| 1.10 | The charge path — the `split` block on `createIntent()`, `Invoice.addItem({seller_ref})`, transfers | [08 §08.7](08_stripe_connect_brain_api.md) |
| — | ⏸ **BUILD HANDOFF** — rebuild `sdk.js` | |
| 1.11 | **`playground/direct-payments/marketplace_split/`** — a two-seller cart paying through the accounts 1.9 created | [09 §09.3](09_direct_payments_connect_example.md) |

Phase 1 ends when a real Stripe test-mode payment splits correctly between two connected accounts,
driven entirely from a static playground page. **Everything after this is consuming an API that is
already proven.**

#### The build handoff

`resources/js/external/**` is TypeScript. The playground is static HTML loading
`https://{api|api-sandbox}.appfactory.studio/sdk.js`, which is built from
`public/external/api/sdk.js`.

**The plan writes the TypeScript; the project owner runs the build.** So each phase-1 step that
changes `resources/js/external/` ends at a handoff, and the playground work resumes after
`sdk.js` is rebuilt and deployed. Sequence anything that changes the external surface *before* a
handoff, not after it — a playground page written against an unbuilt SDK cannot be tested and will
be debugged against the wrong cause.

`external/portal.d.ts` and `apps/market/resources/js/portal.d.ts` must be updated in the same commit
as the runtime, or market's later type-checking passes against methods that do not exist.

#### Phase 2 — the marketplace

Runs on the proven API.

```
02 (rest) ─▶ 03 ─▶ 04 ─┬─▶ 05 ─▶ 06 ──┐
                       └─▶ 07 ────────┤
                                      │
10 (members Connect dashboard) ───────┤
                                      │
11 ─▶ 12 ─▶ 13 ─▶ 14 ─▶ 15 ─▶ 16 ─────┼─▶ 17 ─▶ 18 ─▶ 19
                          │           │
                          └─▶ 21      │
                                      │
20 ─▶ anytime; independent of the above
```

- **10** consumes phase 1's `dp.connect` — it is a layout exercise, not an integration one.
- **13** consumes phase 1's split path.
- **18** (engine) needs a real purchase, so it needs 13 and 17.
- **19** needs everything.
- **20** (legacy retirement) is independent throughout.
- **21** (project workspaces) needs 15's lifecycle and 16's ledger, and needs afs-connect steps 60/61
  landed in `apps/coordinator`. It is additive — 17/18/19 neither wait for it nor change because of it.

### Test identities (already in `brain_memberships`, do not create)

| Role | `reference_num` |
|---|---|
| Seller | `m1xTHpRmdtH2Y` |
| Buyer | `m1xJppRmdtH2Y` |
