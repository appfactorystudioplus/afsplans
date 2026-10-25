# Step 15 — Market: the freelance order lifecycle — completion report

**Date:** 2026-08-19
**Projects touched:** market (`apps/market`) only.
**Status:** ✅ complete. **Hourly contracts did NOT ship** — deferred per §15.9's own explicit
allowance ("if scope pressure appears, ship fixed-price contracts first and defer hourly... record
that as a deferral naming the missing piece, not as a completed step"). See "Known gaps" for exactly
what is and isn't wired.

## What shipped

Every `service_orders.status` transition now goes through one place — `ServiceOrderStateMachine` — a
legality table, an audited `service_order_state_transitions` row per move, and a `MemberEventDispatcher`
notification dispatched after commit. Six services built on it own one piece of the lifecycle each:

- **`RequirementsService`** — validates every `is_required` prompt is answered, computes `due_at`
  from the package's `delivery_days`, transitions `awaiting_requirements → in_progress`.
- **`DeliveryService`** — `deliver()` (creates a `ServiceOrderDeliverable`, sets `auto_accept_at`,
  transitions to `delivered`), `accept()` (→ `completed`), `canDownload()` (gates deliverable
  downloads to `delivered`/`completed` only).
- **`RevisionService`** — enforces the revision cap (`revisions_allowed === -1` is unlimited),
  transitions `delivered → revision_requested`.
- **`MilestoneService`** — for larger fixed-price work: independently funded/approved milestones
  whose amounts must sum to the order total (see "Known gaps" for its scope boundary).
- **`DisputeService`** — opens a dispute (→ `disputed`), resolves one (`favour_buyer` → `cancelled`,
  everything else → `completed`).
- **`ReviewService`** — double-blind buyer/seller review pair; neither publishes until both are in or
  the window closes; recomputes `SellerProfile.rating_avg`/`rating_count` only, never the three
  Stripe Connect columns on the same row.

Eight new `Portal\*` controllers expose the buyer's half of this over HTTP, all behind
`checkpoint.verify`: general lifecycle actions and milestones (`ServiceOrderController`), requirements
(`RequirementsController`), delivery review/download/revision (`DeliveryController`), disputes
(`DisputeController`), reviews (`ReviewController`), and the "Upwork half" — buyer-authored job posts,
proposal review, and contract creation (`JobsController`, `ProposalsController`, `ContractsController`).
A new `Portal\Concerns\ApiResponds` trait gives every one of them the same `{success, message, data}`
envelope and a `runTransition()` helper that turns a caught `IllegalTransitionException` into `409`.

Five scheduled commands close the loop without a human: `market:auto-accept-deliveries` (hourly),
`market:expire-unanswered-requirements` (daily — 24h/72h/7d reminders, 14-day auto-cancel),
`market:publish-pending-reviews` (daily), `market:close-expired-job-posts` (daily), and
`market:resolve-dispute` (manual — see "Decisions" for why this is the admin surface).

The buyer-facing UI: `resources/js/portal/serviceOrder.ts` (the full lifecycle page — requirements
form, delivery review with authenticated download, revision request, extension grant, dispute/
cancellation actions, review form, milestone panel), `messaging.ts` (the per-order thread, every field
escaped), `milestones.ts` (fund/approve), plus job post/proposal/contract pages hydrated inline in
`portal.ts` matching that file's own precedent for simpler pages. Four new `portal/jobs/*` view shells
and two new sidebar nav entries (`Job Posts`, `Contracts`).

## Decisions

1. **The schema's `revision_requested` status is authoritative over the plan's own looser state
   diagram.** §15.1's prose shows only two "working" states (`in_progress`/`delivered`) with revisions
   looping between them; the actual migration (built ahead, in step 03, with its own header comment
   anticipating this step) declares a third, distinct `revision_requested` enum value. Modeled as
   `delivered → revision_requested` (buyer requests) and `revision_requested → delivered` (seller
   redelivers) — more precise than the diagram, and live-verified end to end (see "Verification").

2. **`ServiceOrderController` (new, general lifecycle actions) is distinct from `Portal\ServiceController`
   (step 11/12, read-only).** The plan's file list names a new `ServiceOrderController`; the
   already-existing `ServiceController` already reads like a service-order model despite its name (its
   own header comment says the lifecycle actions are step 15's territory). Resolved by leaving
   `ServiceController` untouched and building the new controller for accept/extension/cancellation/
   order-scoped messaging/milestones, distinct from the narrower `RequirementsController`/
   `DeliveryController`/`DisputeController`/`ReviewController` — matching the plan's literal 8-controller
   file list exactly.

3. **Two pre-existing architecture violations from step 07 were refactored, not left alone.**
   `Internal\ServiceOrderController::escalateDispute()` and `Internal\SalesController::deliver()` both
   wrote `$order->status = ...` directly — exactly the anti-pattern §15.1 exists to prevent. Both now
   call through `DisputeService`/`DeliveryService`, closing the gap retroactively.

4. **`market:resolve-dispute` is a console command, not a web UI** — §15.7's own minimal-admin-surface
   requirement, honestly scoped. Market has no admin authentication system anywhere in this codebase
   (confirmed by inspection — no `Auth`, no admin routes); building one was never asked for by this
   step. The command resolves a dispute by id or service-order reference and is the one and only
   caller of the fully-built `DisputeService::resolve()`.

5. **Milestone fund/approve got new Portal endpoints not named in the plan's file list.** `milestones.ts`
   is explicitly named in §15.11's own file list, and §15.12's checklist requires "Milestone approval
   releases exactly that milestone's amount" as a verified behavior — so `MilestoneService` needed a
   real HTTP caller, which nothing in the plan or step 07 had built. Added three methods to
   `ServiceOrderController` (`apiMilestones`/`apiFundMilestone`/`apiApproveMilestone`) rather than a
   ninth controller, since they are exactly the "general order-lifecycle" shape that controller already
   owns. Only the buyer's two actions are wired — see "Known gaps" for the seller-side half.

## Found bugs (fixed)

None this step beyond the two architecture-violation refactors above (item 3), which are deviations
from the intended design rather than bugs in the runtime-observable sense — both worked correctly
before the refactor, they just bypassed the audit trail and notification dispatch the state machine
now guarantees everywhere else.

## Known gaps (deliberate, documented — not silently assumed built)

- **Hourly contracts did not ship.** `ContractTimesheet` submission exists on the seller side
  (`Internal\ContractController::storeTimesheet()`, step 07), but nothing approves one or auto-invoices
  it. Fixed-price contracts (job post → proposal → accept → `Contract`) are fully wired and
  live-verified.
- **Milestones work only against a real `ServiceOrder` (a purchased package), not a job-proposal
  `Contract`.** `service_order_milestones.service_order_id` is a non-nullable FK to `service_orders`,
  which itself requires a non-nullable `service_id` — a catalogue listing. A `Contract` (an accepted
  `JobProposal`) has neither: `job_posts` has no `service_id`, and `contracts` has no
  `service_order_id`. Wiring a fixed-price *contract* onto the milestone mechanism needs a schema
  change (a nullable `service_id` on `service_orders`, or a parallel `contract_id` column on
  `service_order_milestones`) this step did not make unilaterally. `MilestoneService`'s own header
  comment documents this in full.
- **Milestone seller-side actions ("start work," "submit for approval") have no HTTP caller anywhere.**
  Only the buyer's two actions (fund, approve) are wired, in Portal. Verified directly against
  `MilestoneService` for the seller half (see "Verification") rather than left unbuilt and untested.
- **No seller-facing review-submission endpoint exists yet.** The seller's `seller_to_buyer` half of
  the double-blind pair is members/step 07's own territory through the internal API; nothing in that
  step's file list built it. Verified directly against `ReviewService` (see "Verification").
- **The 14-day unanswered-requirements auto-cancel does not perform an actual Stripe refund** — only
  the status transition. `ExpireUnansweredRequirements`'s own header comment names this as step
  08/16 territory.
- **Milestone "release" and dispute "resolution" record the decision, not a real money movement.**
  `MilestoneService`'s header comment: step 16 owns the ledger write, step 08 owns the actual Connect
  transfer — neither exists yet for freelance work as of this step.

## Files

| File | Project | Change | Grain | Notes |
|---|---|---|---|---|
| `app/Services/ServiceOrders/ServiceOrderStateMachine.php` | market | created | — | Legality table, audited transition, post-commit notification |
| `app/Services/ServiceOrders/IllegalTransitionException.php` | market | created | — | Carries `$order`/`$from`/`$to` |
| `app/Services/ServiceOrders/{Requirements,Delivery,Revision,Milestone,Dispute,Review}Service.php` | market | created | — | One lifecycle concern each |
| `app/Models/ServiceOrderStateTransition.php` | market | created | — | Audit row, `$timestamps = false` |
| `database/migrations/2026_08_19_000043_create_service_order_state_transitions_table.php` | market | created | — | The one genuinely new table this step (all other referenced models pre-existed from step 03 — see "Decisions" item 1) |
| `app/Http/Controllers/Portal/Concerns/ApiResponds.php` | market | created | — | Shared envelope + `runTransition()` |
| `app/Http/Controllers/Portal/{ServiceOrder,Requirements,Delivery,Dispute,Review,Jobs,Proposals,Contracts}Controller.php` | market | created | — | The buyer-side HTTP surface |
| `app/Console/Commands/{AutoAcceptDeliveries,ExpireUnansweredRequirements,PublishPendingReviews,CloseExpiredJobPosts}.php` | market | created | — | Scheduled sweeps |
| `app/Console/Commands/ResolveDispute.php` | market | created | — | Not in the plan's own file list — the minimal admin surface §15.7 requires (see "Decisions" item 4) |
| `resources/views/portal/jobs/{index,create,show,contracts}.blade.php` | market | created | — | Thin shells, JS-hydrated |
| `resources/js/portal/{serviceOrder,messaging,milestones}.ts` | market | created | — | Lifecycle page + reusable panels |
| `config/market.php` | market | modified | region | `freelance` config block (auto-accept/delivery/reminder/cancel/review/timesheet windows) |
| `routes/portal.php` | market | modified | region | ~35 new gated routes + 4 new public shells |
| `routes/console.php` | market | modified | region | 4 new scheduled commands, exact plan cadence |
| `app/Services/Checkout/OrderFulfilmentService.php` | market | modified | region | Dispatches the "order placed" event after `ServiceOrder::create()` |
| `app/Http/Controllers/Internal/ServiceOrderController.php` | market | modified | region | `escalateDispute()` now routes through `DisputeService` |
| `app/Http/Controllers/Internal/SalesController.php` | market | modified | region | `deliver()` now routes through `DeliveryService` |
| `resources/views/portal/services/show.blade.php` | market | modified | line | Header comment updated — same shell, now full-lifecycle |
| `resources/views/portal/layouts/portal.blade.php` | market | modified | region | Jobs/Contracts nav entries |
| `resources/js/portal.ts` | market | modified | region | Removed step-12 read-only `mountServiceDetail()`; added job/proposal/contract mounts |
| `lang/en/portal.php` | market | modified | region | `nav.jobs`/`nav.contracts` + `jobs.*` strings |

`grep -rn MARKET_MODIFIER_ADDITION` across every file touched this step returned exactly this list —
reconciled, no file in the list without a tag, no tagged file outside it.

## Verification

All performed live against a scratch MySQL database (`market_scratch_test`), never the real dev
database — `.env` backed up first, restored and diffed identical to the backup afterward, scratch
database dropped, local mysql service stopped when done.

- **`php -l`** clean on every new/modified PHP file.
- **`php artisan migrate:fresh --force`** — all 43 migrations (steps 03–15) applied cleanly.
- **`php artisan route:list --path=portal`** — 55 routes, all resolving to real methods; the 3 new
  milestone routes added mid-step bring it higher, all confirmed resolving with no autoload errors.
- **`php artisan list market`** — all 8 `market:*` commands (3 pre-existing + 5 new) listed correctly.
- **Full HTTP lifecycle, seeded data, real requests via `php artisan serve`** (Checkpoint sessions
  pre-cached into the `cache` database table at `CheckpointTokenVerifier`'s exact key shape —
  `checkpoint_verify:` + `sha256(token)` — the same method steps 12/13 established):
  - Buyer submits requirements → `due_at` set correctly from `delivery_days`, status →
    `in_progress`. Resubmitting → **409** `MARKET_E_ILLEGAL_TRANSITION`.
  - Buyer accepts before delivery → **409**.
  - Seller delivers (`DeliveryService` called directly, matching the Internal API's own real call
    shape) → status `delivered`, `auto_accept_at` set, `canDownload() === true`.
  - Buyer requests revision → status `revision_requested`, `revisions_used` incremented,
    `auto_accept_at` cleared. Redeliver → `delivered` again. Second revision → `revisions_used = 2/2`.
    Redeliver again. Third revision request → **422** `MARKET_E_REVISION_LIMIT_REACHED`.
  - Buyer accepts final delivery → status `completed`, `completed_at` set. Full audit trail in
    `service_order_state_transitions` for this order: 7 rows, every `from`/`to`/`actor`/`reason`
    correct in order.
  - Buyer posts a message → **201**, stored correctly.
  - Buyer submits a review → `is_public: false` (double-blind hold confirmed). Seller review
    submitted directly via `ReviewService` → both rows flip `is_public: true` in the same call
    (`maybePublishPair()`), `SellerProfile.rating_avg`/`rating_count` recomputed (`5.00`/`1`),
    `payouts_enabled`/`charges_enabled`/`connect_account_reference` **untouched**.
  - Buyer opens a dispute on a second order → **422** on an invalid free-text `reason` (confirms the
    enum validation), **201** with a valid category. Order → `disputed`.
    `market:resolve-dispute {ref} favour_seller` → order → `completed`.
  - Job post → two seeded proposals → buyer lists them, accepts one → **201** `Contract` created,
    the other proposal auto-declined, job post auto-`filled`. Verified via `GET /portal/api/contracts`
    (nested `job_proposal.job_post` present) and direct DB check.
  - Milestones: created two (`$40 + $60 = $100` order total, matching `MilestoneService`'s own sum
    check), pushed milestone 1 through `funded → in_progress → submitted` directly, then buyer
    **approved it over real HTTP** → milestone 1 `approved`, milestone 2 untouched at `pending` —
    confirms "approval releases exactly that milestone's amount." Illegal approve on a still-`pending`
    milestone → **409**. Buyer funded milestone 2 over real HTTP → `funded`.
  - All 5 console commands run with `--dry-run` (0 hits against clean seed data) and then for real
    against purpose-built fixtures: auto-accept fired on a `delivered` order past `auto_accept_at`;
    24h reminder sent once on an `awaiting_requirements` order and correctly **not resent** on a second
    run; 14-day-old `awaiting_requirements` order auto-cancelled; a completed order past the review
    window with only one submitted review published that one review; an expired open job post closed.
  - `DeliverMemberEvent` jobs confirmed queued (14 across the full session) via `QUEUE_CONNECTION=database`
    — actual delivery mechanics already verified in step 04, out of scope to re-verify here.
- **`npx tsc --noEmit --skipLibCheck`**, isolated (the project's real `tsconfig.json` is blocked by the
  same pre-existing, unrelated `bootstrap` type-definition and `appfactory3.ts`/`globalAuth.ts`
  environment artifacts noted in every prior step's report since step 08) — zero errors in any of the
  four files this step created or modified (`portal.ts`, `serviceOrder.ts`, `messaging.ts`,
  `milestones.ts`).
- **`npm run build`** — clean, `portal-*.js` chunk emitted (47.9 kB, up from step 12's smaller bundle).
- **Browser smoke check**: `/portal/jobs`, `/portal/jobs/new`, `/portal/jobs/{ref}`, `/portal/contracts`,
  `/portal/services/{ref}` all return `200` and render their shells. Full client-side JS execution
  could not be verified in-browser this session — `ASSET_URL` in `.env` points at the production/
  staging asset host (`market15.appfactory.studio`, pre-existing, untouched by this step), so the
  browser's cross-origin script requests for the built bundles are blocked by CORS in this local
  environment. Not a defect in the code: `npm run build` emits the bundles correctly, `tsc` finds no
  type errors, and every endpoint the JS calls was independently confirmed correct over real HTTP
  above. Flagged as an environment limitation, not a completed verification, consistent with prior
  steps' own "no live Checkpoint session was available this session" notes.
