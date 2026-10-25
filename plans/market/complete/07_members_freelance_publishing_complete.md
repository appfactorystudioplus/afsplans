# Step 07 — Members: freelance service authoring — completion report

**Date:** 2026-08-19
**Projects touched:** members, market
**Status:** ⚠️ complete with gaps

## What shipped

A seller can now author a full Fiverr-style gig from members, with real backend behind every
screen — no `localStorage`, no `alert()`, no session-held payment details.

- **Gig editor** (`GigEditor.ts`, five tabs, not a wizard): Overview (title, category, tags, gig
  type); Pricing (three-tier comparison table with a shared feature checklist, extras, and a live
  10%-fee earnings preview per tier — `GigPricingTable.ts`); Description & FAQ (markdown with live
  preview, drag-sortable FAQ); Requirements (drag-sortable questionnaire — `GigRequirementsEditor.ts`);
  Gallery (3 images / 1 video / 2 documents with drag reorder — `GigGalleryEditor.ts`). Each tab
  saves independently. Publish renders every `ServicePublishGate` failure at once, each deep-linked
  to the tab that fixes it.
- **Seller profile** (`SellerProfileEditor.ts`): display name, headline, bio, country, languages,
  skills, education, certifications, hourly rate — editable — beside a read-only stats panel
  (level, completed orders, rating, response time, on-time delivery rate) computed entirely on
  market.
- **Order queue** (`ServiceOrderQueue.ts` + `OrderMessaging.ts`): grouped by the six lifecycle
  states, a due-date countdown that turns red inside 24h, requirements/messaging/deliver/
  request-extension/request-cancellation/escalate-dispute per order.
- **Proposals inbox** (`ProposalsInbox.ts`): browse open jobs with a skills-match badge, submit and
  withdraw proposals, manage active contracts, submit weekly timesheets for hourly contracts.
- Payment-method UI and its three routes are gone outright — there was no session-free equivalent
  to proxy to (Connect is step 10).

A market-side internal API surface for all of the above — order detail/messaging/actions, the
proposals/contracts/timesheets surface, gallery video/document upload, and the seller-profile
extensions — did not exist before this step (step 04 only ever built Seller/Asset/Service/License/
Sales). It was built here, narrowly scoped to what the members screens above actually call, mirroring
exactly how step 05/06 added `UploadController`/`CatalogController` when a genuine gap blocked the
UI being built.

Two genuine, previously-undiscovered bugs were found and fixed while wiring this (see below):
`ServiceGallery`'s table name mismatch (every gallery-touching call has 500'd since step 04), and a
date-comparison bug in this step's own new `ContractController::storeTimesheet()`.

## Files created

| File | Project | Tag placement | Purpose |
|---|---|---|---|
| `app/Http/Controllers/Internal/ServiceOrderController.php` | market | header | Order detail, messaging, extension/cancellation requests, dispute escalation |
| `app/Http/Controllers/Internal/DeliverableController.php` | market | header | Private-root deliverable file upload (the missing half of step 04's `deliver()`) |
| `app/Http/Controllers/Internal/JobController.php` | market | header | Browse open jobs, submit/withdraw proposals, list own proposals |
| `app/Http/Controllers/Internal/ContractController.php` | market | header | List contracts, submit weekly timesheets |
| `database/migrations/2026_08_19_000037_add_freelance_profile_fields_to_seller_profiles_table.php` | market | header | `education`, `certifications`, `hourly_rate` on `seller_profiles` |
| `app/Http/Controllers/Market/MarketNotificationController.php` | members | header | Read side of the step-04 notification webhooks (index/unread-count/mark-read) — never built before this step |
| `resources/js/market/GigEditor.ts` | members | header | The five-tab gig editor orchestrator |
| `resources/js/market/GigPricingTable.ts` | members | header | Tier comparison editor + monotonic-pricing validation |
| `resources/js/market/GigRequirementsEditor.ts` | members | header | Drag-sortable requirements questionnaire editor |
| `resources/js/market/GigGalleryEditor.ts` | members | header | Image/video/document gallery editor with caps and drag reorder |
| `resources/js/market/SellerProfileEditor.ts` | members | header | Profile form + read-only stats |
| `resources/js/market/ServiceOrderQueue.ts` | members | header | Grouped order queue + order-detail panel |
| `resources/js/market/OrderMessaging.ts` | members | header | Reusable per-order message thread |
| `resources/js/market/ProposalsInbox.ts` | members | header | Browse/bid/track proposals; contracts and timesheets |
| `resources/js/market/FreelanceApi.ts` | members | header | Typed client for `/api/freelance/*` (deviation — see below) |
| `resources/js/market/GigPricingTable.test.ts` | members | header | Tier-monotonicity and feature-checklist tests |
| `resources/js/market/GigGalleryEditor.test.ts` | members | header | Gallery-cap counting tests |
| `resources/js/templates/views/gig_editor.html` | members | header | Gig editor shell |
| `resources/js/templates/views/service_orders.html` | members | header | Order queue shell |
| `resources/js/templates/views/seller_profile.html` | members | header | Seller profile shell |
| `resources/js/templates/views/proposals.html` | members | header | Proposals inbox shell |

## Files modified

| File | Project | Lines / regions | Tag placement | Change |
|---|---|---|---|---|
| `app/Services/Catalog/ServicePublishGate.php` | market | +1 block | line-level | Added the tier price/delivery monotonicity check — never enforced before this step |
| `app/Http/Controllers/Internal/SellerController.php` | market | `show()`, `update()` | line-level | Added `education`/`certifications`/`hourly_rate` to the editable fields; added computed `on_time_rate` to `show()` |
| `app/Models/SellerProfile.php` | market | `$fillable`, `$casts` | line-level | Added the three new columns |
| `app/Models/ServiceGallery.php` | market | +`$table` override | line-level | **Bug fix** — see below |
| `app/Http/Controllers/Internal/MediaController.php` | market | +`uploadFile()` method | line-level | Video/document gallery upload (allowlist + MIME sniff, no re-encode possible) |
| `app/Http/Controllers/Internal/AssetController.php` | market | *(step 06, untouched this step)* | — | listed only for completeness of the reconciliation grep — no step 07 changes |
| `app/Models/Asset.php` | market | *(step 06, untouched this step)* | — | same |
| `routes/internal.php` | market | +25 routes | line-level | Registers every controller above |
| `resources/js/controllers/FreelanceController.ts` | members | whole file rewritten | header | Storage/mock logic removed; now a thin `FreelanceApi`-backed listing controller with nav into the four new screens |
| `resources/js/templates/views/freelance.html` | members | header cards, services table, removed service+payment modals and payment section, +nav buttons | line-level | Table gains type/orders columns; gig editor replaces the modal; toast host added |
| `resources/js/views.ts` | members | 4 import lines + 4 new subview registrations | line-level | Registers `gig_editor`, `service_orders`, `seller_profile`, `proposals` |
| `app/Http/Controllers/FreelanceController.php` | members | +23 new proxy methods | line-level | Gig editor, seller profile, order queue/messaging/actions, proposals, contracts/timesheets |
| `app/Services/Market/MarketClient.php` | members | `postRaw()` signature | line-level | Added an optional `$extraHeaders` parameter (for `X-Media-Type`) — generic rather than a near-duplicate method |
| `routes/web.php` | members | +40 routes, +1 import | line-level | Backs every new `FreelanceController.php` method and `MarketNotificationController` |
| `resources/js/i18n/en.json` | members | +142 keys appended | *(JSON — no comment tag possible, same unavoidable exception as every prior step's JSON file)* | All `market.upload.gig.*`/`pricing.*`/`requirements.*`/`gallery.*`/`profile.*`/`queue.*`/`messaging.*`/`proposals.*`/`nav.*` strings |

## Database changes

One migration this step: `2026_08_19_000037_add_freelance_profile_fields_to_seller_profiles_table.php`
(market) — adds `education` (text, nullable), `certifications` (text, nullable), `hourly_rate`
(decimal 10,2, nullable) to `seller_profiles`. Run via `php artisan migrate` against a scratch
SQLite database seeded from scratch for this step's testing (see below); no production database was
touched, and the migration is purely additive (three nullable columns) so it is safe against
existing rows.

## Environment / config keys added

None.

## Decisions made during execution that diverge from the plan

- **`ServiceGallery::$table` override — a bug fix, not a preference.** The migration creates
  `service_gallery` (singular — every sibling table in that same migration file uses plural), but
  Eloquent's default naming convention for the class resolves to `service_galleries`, which never
  existed. Every call through `Service::gallery()` — `ServiceController`'s gallery collection
  endpoints and `ServicePublishGate::evaluate()`'s gallery-required check — has thrown
  `no such table: service_galleries` since step 04. Live-reproduced (`php artisan tinker` calling
  `$asset->... wait, $service->gallery()->exists()` directly) before fixing, exactly the way step
  06 confirmed `Asset::entitlements()`'s missing relation. Fixed with an explicit `$table` override,
  the same pattern `Dispute` (step 03) already uses for its own non-default table name.
- **The tier price/delivery monotonicity rule's wording was read non-literally, and this is stated
  explicitly rather than silently "fixed".** §07.3 says tiers "must be monotonically priced and no
  faster to deliver than the tier below" — read literally, a higher tier must be *slower*, which
  contradicts both real-world tiered pricing and the plan's own worked "Premium package" example two
  paragraphs later. §07.9's checklist ("Standard priced below Basic is rejected") only pins down the
  price direction, not delivery. The enforced rule, in both `ServicePublishGate.php` (server) and
  `GigPricingTable.ts` (client): price non-decreasing, delivery time non-increasing, as tier rises.
  Documented in both files' own header comments so a future reader hits the explanation immediately,
  not just here.
- **`ContractController::storeTimesheet()`'s duplicate-week check used a plain string `where()`
  against a `date`-cast column and always missed** — Eloquent casts apply to attribute access, not
  to query-builder `where()` values, so `'2026-08-17'` never matched the stored
  `'2026-08-17 00:00:00'`. Found by the step's own test suite (submitted the same week twice,
  expected a `409`, got a second `201`), fixed with `whereDate()` instead of `where()`.
- **`FreelanceApi.ts` created, not named in §07.8's file list.** The direct, necessary
  TypeScript-side counterpart to every new route on `FreelanceController.php` — kept separate from
  step 06's `MarketApi.ts` because the two proxy controllers (assets vs. gigs) have almost no
  overlapping call shapes; merging them would mostly concatenate two unrelated method lists. Same
  class of found-and-filled gap as `CatalogController.php` in step 06.
- **`AssetController.php`/`Asset.php` (market) are listed in the modified table only for
  reconciliation completeness** — both still carry `MARKET_MODIFIER_ADDITION` from step 06 and were
  not touched this step; they appear because the whole-project tag grep naturally includes them and
  the reconciliation needed to say so explicitly rather than leave an unexplained gap.
- **`escalateDispute()` deliberately does not dispatch `MemberEventDispatcher::dispute()`.** That
  event exists to tell the *seller* a dispute's status changed; this endpoint is always the seller's
  own action, so dispatching here would just notify them of something they already know they did.
  The natural firing point is a future *resolution* (step 15), once a workflow exists to reach one
  from.
- **`postMessage()` on `ServiceOrderController` never dispatches `MemberEventDispatcher::message()`
  either**, for the same self-notification reason, and — more importantly — because there is still
  no buyer-facing endpoint anywhere in this codebase that could originate the *other* direction (a
  buyer message that should raise the seller's unread badge). §07.9's own checklist item ("a message
  sent from the market portal raises the members unread badge without reload") cannot be made fully
  true until such an endpoint exists; see Known gaps.
- **`requestExtension()`/`requestCancellation()` post a flagged system message rather than mutating
  `service_orders.status` or `due_at`.** `ServiceOrder`'s own header comment (step 03) reserves every
  state transition for step 15's `ServiceOrderStateMachine`, explicitly because "every transition
  carries money and at least two notification targets, and that logic must exist in exactly one
  place." A seller-side request that unilaterally changed the due date or status would be exactly the
  kind of ad-hoc transition that comment warns against. The chosen shape — a message tagged
  `attachments.system_flag` — is real, visible, and actionable (both `ServiceOrderController.php`
  and `OrderMessaging.ts` render it distinctly), without inventing an approval workflow the schema
  was never given for.
- **`escalateDispute()` *does* flip `service_orders.status` to `disputed`.** Narrower and better
  justified than the above: `disputed` is an explicit, already-declared enum value with no other
  writer anywhere in this codebase, and `order_disputes` (step 03) exists for exactly this. Verified
  live that the existing `deliver()` endpoint correctly refuses a disputed order (`409`), confirming
  the flip does not silently bypass step 04's own transition guard.
- **`es.json` was not touched**, for the same reason as step 06 and with the gap now larger still:
  `en.json` is at 837 keys after this step, `es.json` at 253 — 584 missing project-wide, not specific
  to this step. `Localizer.t()` falls back to English silently on a missing key.

## Known gaps / deferred

- **No buyer-facing message endpoint exists anywhere in this codebase.** `ServiceOrderController`'s
  seller-side messaging is real and fully wired (verified live — post + list round-trips correctly),
  and `MarketNotificationController`'s read side (unread count, list, mark-read) is real and wired to
  the members-local `market_notifications` table `MarketEventController` (step 04) already writes to.
  What is missing is the other end: nothing in market or members ever creates a message *as the
  buyer* and calls `MemberEventDispatcher::message()`. That is buyer-portal work with no natural home
  in this step's scope (step 07's own Files section names no market-portal buyer files), and is left
  for whichever step builds market's buyer-facing `/portal` messaging surface.
- **`requestExtension()`/`requestCancellation()` are informational only** (flagged messages, not
  status changes) until step 15's state machine exists to give them somewhere real to resolve to —
  see "Decisions made" above.
- No `Contract` is ever created by this codebase yet — `JobProposal.status` can reach `accepted` but
  nothing turns that into a `Contract` row (that transition belongs to step 15's proposal-acceptance
  flow, buyer-initiated). `ContractController` was tested against a directly-seeded `Contract` row;
  it will start receiving real traffic once step 15 lands the acceptance flow.
- No milestone support was wired into `ProposalsInbox.ts`'s proposal form even though
  `job_proposals.milestones` (JSON) exists — §07.6 describes an "optional milestone breakdown" but
  gives no shape for it, and `MilestoneService`/`ServiceOrderMilestone` are explicitly step 15's
  territory. The field is accepted server-side (`JobController::storeProposal()` validates
  `milestones` as an optional array) but the client never populates it.
- Same members-boot blocker recorded in steps 02/04/05/06: `routes/web.php:21` requires a missing
  `public/checkpoint/server/php/vendor/autoload.php`, so no live HTTP verification of the members
  proxy layer itself was possible — verification leaned on `php -l`, a full `tsc --noEmit`, a full
  `vitest run`, a full production `vite build`, and live HTTP round-trips against market's real
  internal API using the exact request shapes the new members code produces.

## How this was verified

- **PHP syntax**: `php -l` on every touched/created PHP file (market and members) — all clean.
- **TypeScript**: `npx tsc --noEmit -p tsconfig.json --ignoreDeprecations 5.0`, diffed against the
  step 06 baseline at every stage of this step (new market backend, new TS modules, controller
  rewrite + views.ts, new tests) — **zero new errors** introduced at any point.
- **Unit/DOM tests**: `npx vitest run resources/js/market/` → **8 files, 48 passed, 1 pre-existing
  skip, 0 failed.** New this step: `GigPricingTable.test.ts` (monotonic-pricing rule against the
  same pairs `ServicePublishGate.php` checks, feature-checklist union, regressed-feature detection)
  and `GigGalleryEditor.test.ts` (gallery-cap counting; needed a `@vitest-environment jsdom` pragma
  once discovered that `FreelanceApi.ts` transitively imports `checkpointHelper/app.ts`, which
  instantiates `ApplicationContextManager` — and touches `document` — as an import-time side effect).
- **Production build**: `npx vite build` → succeeds, 1015 modules transformed;
  `GigEditor`/`ServiceOrderQueue`/`SellerProfileEditor`/`ProposalsInbox` each appear as their own
  lazy chunk, confirming the `views.ts` dynamic imports actually code-split.
- **Required greps** (§07.9): `grep -n "localStorage" resources/js/controllers/FreelanceController.ts`
  → zero hits. `grep -rn 'session("freelance' app/` (members) → zero hits — this file was already
  proxy-only as of step 04, so this bullet was already true going in and stayed true.
- **`MARKET_MODIFIER_ADDITION` reconciliation** (§07.9): every file created or modified this step
  greps clean; the full list matches this report's Files tables exactly (the one exception, `en.json`,
  is a JSON file with no comment syntax, called out explicitly rather than silently omitted).
- **Live backend verification**, market side, against a scratch SQLite database (never the shared
  `testdb_afsdb_market`) — same `.env`-swap-and-restore discipline as steps 03–06, `diff`-verified
  restored both times:
  - Seeded a `Service` with intentionally-wrong tier pricing (Standard cheaper than Basic), an
    `Order`/`OrderItem`/`ServiceOrder`, a `JobPost`, and a `SellerProfile`.
  - `PUT /sellers/{ref}` with the three new fields → `200`, values round-trip.
  - `GET /sellers/{ref}` → `on_time_rate` present (`null` with zero completed orders, as expected).
  - `POST /services/{ref}/publish` with bad tier pricing → `422` with `MARKET_E_PUBLISH_TIER_PRICING`
    **and** `MARKET_E_PUBLISH_NO_GALLERY` **and** `MARKET_E_PAYOUTS_REQUIRED` all in one response
    (this is where the `service_galleries` bug was first hit and fixed) — fixed the pricing, republished,
    confirmed the tier-pricing error was gone and the other two remained.
  - `POST /sellers/{ref}/media/file` with wrong content for the declared type → `422`
    `MARKET_E_MIME_MISMATCH`; with a real PDF header → `201`.
  - `GET`/`POST` order detail, messages, request-extension (confirmed `service_orders.status`
    unchanged), escalate-dispute (confirmed status flipped to `disputed`).
  - Uploaded a deliverable, called `deliver()` with the returned `file_path` → correctly rejected
    `409` because the order was now `disputed` (cross-check that the dispute flip didn't bypass the
    existing transition guard from step 04).
  - Job board: browse (skills-match count correct), submit proposal, duplicate-proposal rejection
    (`409`), list own proposals, withdraw.
  - Contracts: seeded a `Contract` directly, listed it, submitted a timesheet, over-weekly-limit
    rejection (`422`), duplicate-week rejection (`409` — after fixing the `whereDate()` bug above).
  - `.env` restored and `diff`-confirmed byte-identical to the pre-test backup.
- **i18n integrity**: every literal `t('...')` call (including bare `t(...)`, not just `this.t(...)`)
  and `data-i18n="..."` attribute across every file this step created or modified was extracted by
  script and checked against `en.json`, including the two dynamic template-literal key families
  (`pricing.tier_${tier}`, `gallery.type_${mediaType}`) added by hand — **zero missing keys.**
  `en.json` was rewritten via Python `json.load`/`json.dump` (`ensure_ascii=True`), re-verified with
  `file` ("JSON data", not binary) and `json.load()`.

## Follow-ups spawned

- Step 15 (order lifecycle) should replace `requestExtension()`/`requestCancellation()`'s flagged
  messages with real state transitions once `ServiceOrderStateMachine` exists, and should give
  `escalateDispute()`'s counterpart resolution flow a place to call
  `MemberEventDispatcher::dispute()` from.
- Whichever step builds market's buyer-facing `/portal` messaging surface should call
  `MemberEventDispatcher::message()` from wherever a buyer's message is created — the receiving side
  (`MarketEventController::message()`, `MarketNotificationController`) is already fully built and
  tested.
- Step 15's proposal-acceptance flow should create the first real `Contract` rows; `ContractController`
  is ready and tested against a hand-seeded one.
