# Step 21 — Freelance project workspaces: market orders managed in coordinator — completion report

**Date:** 2026-08-20
**Projects touched:** coordinator (`apps/coordinator`), market (`apps/market`), members (`apps/members`).
**Status:** ⚠️ near-complete. Slices 21-A through 21-M are built end to end — backend, UI, and
the freelancer-facing panel in members — and all three apps' frontends build clean. Only slice
21-N (optional, and blocked on a deeper gap than the plan anticipated) was not started. See
"Known gaps" for what's genuinely missing and why, including the one class of verification this
session could not perform: live browser interaction against a running, authenticated instance
of any of the three apps.

## What shipped

**21-A — `saveWorkspace()` optimistic concurrency.** The dormant `pm_member_preferences.revision`
counter (already incremented on every save, never read back) now round-trips: `loadWorkspace()`
returns it, the SPA (`pm-api.ts`/`state.ts`) echoes it back on every save, and a mismatch inside
the save transaction throws `StaleWorkspaceRevisionException` → `409`. A stale browser tab can no
longer silently overwrite concurrent edits — a real pre-existing hazard, not specific to this step.

**21-B — `origin`/`origin_ref` provenance.** `pm_projects`/`pm_tasks`/`pm_users` gained the two
columns; all **four** delete sweeps in `PmPersistenceService` (`persistProjects`, `persistUsers`,
`persistSourceTasks`, and the sweep inside `persistUsers()`'s own scope) now exclude
`origin != 'pm'` rows. The plan's prose names only three sweeps — the fourth
(`persistUsers()`'s) is real and now guarded too; its practical exposure is low since it's
reachable only via `bootstrapUsers()`, not the ordinary autosave path.

**21-C — `PmClientIdAllocator`.** Reserved band from `9_000_000_000_000_000`, row-locked
max+1 inside the caller's transaction. Verified never to collide with the SPA's own
`Date.now()`-scale ids, including a soft-deleted row still permanently reserving its id.

**21-D — coordinator's four engagement tables.** `afsconnect_engagements` (with a **nullable**
`pm_project_client_id` — a deliberate deviation from the plan's literal schema, needed for 21-G's
track-only mode), `afsconnect_engagement_facts` (append-only, `UPDATED_AT = null`),
`afsconnect_engagement_participants`, `afsconnect_engagement_commands`. No FK constraints,
matching every existing `afsconnect_*` table.

**21-E — the shared HMAC.** Market's `CoordinatorSignature` (sign + verify, single-key,
`X-AfsConnect-*`) proven byte-compatible with coordinator's own `AfsConnectSignature` by a test on
*each* side: market's own round-trip test, and a coordinator-side test that reproduces market's
exact algorithm inline and confirms the real `AfsConnectSignature::verify()` accepts it.

**21-F/21-G — provisioning, all three import modes.** `EngagementProvisioningService::provision()`
handles `full` (new project + kanban + team + tasks + budget), `add_existing` (tasks + budget into
a project the buyer picks, no new project/team), and `track_only` (engagement + index only, zero
`pm_*` writes — required the nullable FK from 21-D). Idempotent on `(market_kind,
market_reference_num)`. Market's `EngagementBlueprintBuilder`/`EngagementProvisioner`/
`CoordinatorClient` build the request and record the mapping in the new `engagement_links` table.

**21-H — the sync, both directions of §21.8.3's event map that this codebase can actually
produce.** Three market-side choke points (`ServiceOrderStateMachine::transition()` for all
order-level facts, `MilestoneService::transition()`/`approve()` for milestone-level facts,
`LedgerService::recordRefund()` for refunds) build an event envelope and dispatch
`DeliverEngagementEvent` (copies `DeliverMemberEvent`'s exact retry shape). Coordinator's
`EngagementEventIngestService` ingests it: facts are idempotent via a unique `event_id` derived
per-fact from the envelope's own id, `task_updates` re-assert only `title`/`status`/`progress` on
a task found by `origin_ref` (silently skipped if none exists — never an error), and an added
`engagement_status` envelope field (not in the plan's literal shape — see Decisions) drives
`afsconnect_engagements.status`.

**21-I — market as a provider source.** Resolved a real conflict between the plan's §21.4 table
(`market.internal`/`X-Market-*` for reads) and the already-built, reusable
`SourceAppAfsConnectClient` + 15-minute reconcile loop (always `X-AfsConnect-*`) — see Decisions.
Went with reusing the existing infrastructure: `market_orders` registered as a `provider` source
in `config/afs_connect_sources.php` (new `marketplace` group), market implements the four standard
endpoints (`summary`/`changes`/`items/{id}`/`search`/`health`) behind a new
`VerifyAfsConnectSignature` middleware mirroring coordinator's own. Zero new coordinator client
code — exactly the "one config entry" promise §21.15.1 makes.

**21-J — `EngagementMetricsService`.** Every §21.21 checklist figure (contracted, escrowed,
released, fee, remaining, CPI, SPI, EAC, effective hourly rate, revision cost) verified against a
hand-computed fixture (a $6,000/$22,000 two-milestone engagement). Two real gaps found and
documented in the service itself rather than faked: "other project cost" uses a category-string
heuristic (no per-item `origin` column exists on budget lines), and "revision cost" can't be
attributed to a specific milestone (`RevisionService` is order-scoped in this codebase, not
milestone-scoped) — approximated as the whole engagement's released amount once any revision
exists.

**21-K — coordinator UI.** Plain TypeScript + DOM (this app has no frontend framework — no Vue,
no React; confirmed by inspecting `package.json` and finding no `.vue` files, correcting an
earlier wrong claim in this same session that it used Vue). A new `resources/js/src/engagement/`
module (`api.ts`/`store.ts`/`render.ts`/`features.ts`/`charts.ts`/`format.ts`/`index.ts`),
registered as a router subview exactly like every existing section (`budget2`, `afs-connect`) —
same nav-item/`showSection`/teardown wiring, no new mechanism invented. A workspace list view,
and a 4-tab detail view (Overview/Schedule/Cost/People) per §21.11. The Schedule tab is
deliberately a link into the existing Gantt chart (reusing `projects.ts`'s own `switchProject()`)
rather than a second schedule renderer — matches §21.11.2's "the existing Gantt, unmodified, now
populated" instruction literally. The Cost tab's charts are Chart.js configs in the same shape as
`budget2/charts.ts`; the "burn-up" is honestly labeled a point-in-time snapshot (planned/earned/
actual bars), not a time series — no historical snapshot data exists to plot one truthfully. Two
new backend reads (`EngagementMetricsService::milestones()`/`participants()`, and an `index()`
list endpoint) were added to feed this view — nothing here existed before this pass; the earlier
money/time/derived/risk endpoint alone wasn't enough to build a real UI on. `npx vite build`
succeeds clean (1001 modules, zero errors).

**21-L — the command channel + risk service.** Reused the same afs-connect channel as 21-I for the
same reason (§21.9's literal `X-Market-*` instruction would have needed a second, unbuilt
coordinator-side signer). `EngagementCommandDispatcher` writes the outbox row first, then a queued
job (`SendEngagementCommand`) sends it; market's `AfsConnectEngagementCommandController` executes
all six commands (`fund_milestone`, `approve_milestone`, `accept_delivery`, `request_revision`,
`request_cancellation`, `post_message`) through the real `MilestoneService`/`DeliveryService`/
`RevisionService`, re-deriving the acting member from the engagement link — never from the
request. An illegal transition returns the real `409 MARKET_E_ILLEGAL_TRANSITION`, propagated back
onto the outbox row unchanged. `EngagementRiskService` implements 5 of the plan's 7 flags fully
(milestone_at_risk, budget_ahead_of_work, blocked_on_you, revision_spiral, stale_sync); the other
two are implemented to never silently misfire — see Known gaps.

**21-M — complete, including the members-app leg.** Market's Project-tab summary
(`EngagementSummaryService` + `GET /portal/api/services/{ref}/workspace`) is computed entirely
from market's own `service_order_milestones` (no live coordinator call needed) and now rendered —
`resources/js/portal/project.ts`, mounted into the existing service-order detail page exactly the
way `milestones.ts`/`messaging.ts` already are (their own established "self-contained fetch +
render into a passed container" shape, not a new pattern). The freelancer-schedule chain is
complete end to end: coordinator's `schedule()` action (scoped strictly to one participant's own
tasks — no budget, no cost, no other participants' work, no `origin='pm'` tasks) → market's
`EngagementScheduleService`/`GET /sellers/{ref}/service-orders/{soRef}/schedule` → **members**
(a fourth repo, opened for the first time this session): `FreelanceController::
getServiceOrderSchedule()` proxies through the existing `MarketClient`/`X-Market-*` channel
sellers already use for every other order-side call, `FreelanceApi.ts` gained one typed method,
and a new `OrderSchedule.ts` panel (mirroring `OrderMessaging.ts`'s shape) is mounted into the
seller's existing per-order detail view in `ServiceOrderQueue.ts`. `npx vite build` succeeds clean
in both market and members.

**21-N.** Not started — see Known gaps.

## Decisions

1. **The §21.4 direction-③/④ auth conflict, resolved in favor of reusing existing
   infrastructure.** The plan's own table says coordinator reads from market, and coordinator
   sends commands to market, over `market.internal`/`X-Market-*` (step 04's channel). But
   coordinator already has a complete, tested pull mechanism for exactly the read case
   (`SourceAppAfsConnectClient` + `AfsConnectReconcileCommand`), and it always signs outbound as
   `X-AfsConnect-*` — never `X-Market-*`. Following the plan literally would have required
   building a brand-new coordinator-side signer not listed anywhere in §21.19's file list, and
   would contradict §21.15.1's own "one config entry, zero new client" framing. Flagged to the
   user directly; they chose reuse. Both directions ③ (read) and ④ (command) now go over the same
   coordinator↔market afs-connect channel `market_orders`/`market` peer keys already established
   for ①/②.
2. **`engagement_status` added to the §21.8.2 sync envelope.** The plan's event map implies
   engagement status changes (`active`→`completed`/`cancelled`/`disputed`) but never specifies how
   the envelope carries that information — inferring it from fact `kind` alone is ambiguous (an
   `order_completed` fact means either real completion or cancellation depending on context
   coordinator can't see). Market computes its own order's terminal/frozen status and sends it
   explicitly as an added top-level field; `EngagementEventIngestService` applies it verbatim.
3. **Task indent is not derived from `depends_on_origin_ref` chains**, despite §21.7.3's literal
   wording. Indent (WBS hierarchy) and dependencies (Gantt arrows) are orthogonal in this app's
   data model; nesting every dependent milestone under its predecessor would have produced a
   visibly broken Gantt for the plan's own worked example. Provisioned tasks are siblings; real
   `PmTaskDependency` rows still carry the actual dependency.
4. **`EngagementBlueprintBuilder` supports `ServiceOrder` only, not `Contract`.** Confirmed by
   reading `MilestoneService`'s own header comment: there is no schema path from a `Contract` to
   milestones at all today (no `contract_id` on `service_order_milestones`, no `service_order_id`
   on `contracts`) — a pre-existing gap, not something this step could fix unilaterally.
5. **No `buyer_action` tasks are ever provisioned.** `service_order_requirements` are answered
   upfront and immutably before an order can leave `awaiting_requirements` (step 15 §15.3) — by
   the time a workspace can be provisioned, nothing is ever outstanding for a buyer_action task to
   represent.

## Found bugs (fixed)

- **`milestoneFunded()` sent `amount_cents: 0`.** Caught while building 21-J: §21.10.1's own
  escrow formula (`Escrowed = Σ milestone_funded − Σ milestone_approved`) requires the funded fact
  to carry the real amount, or money that's funded-but-not-yet-approved would never show up as
  escrowed. Fixed in `EngagementSyncDispatcher::milestoneFunded()`; the test that asserted the old
  (wrong) zero-amount behavior was corrected to assert the real milestone amount instead.
- **`cpi`/`spi`/`revision_cost_share` returned PHP `int` instead of `float` when a ratio divided
  evenly** (`600000/600000` → PHP's `/` returns `int(1)`, not `float(1.0)`) — would have made the
  JSON response's field type flicker between int and float per engagement. Explicit `(float)`
  casts added in `EngagementMetricsService::derived()`.

## Known gaps (deliberate, documented — not silently assumed built)

- **No live browser verification of any UI, in any of the three apps.** All three
  (`coordinator`, `market`, `members`) have a real Checkpoint-gated login flow and an `APP_URL`
  pointing at a remote dev domain, not localhost — there is no practical way to reach an
  authenticated session of any of them from this environment. Verification actually performed:
  `npx vite build` succeeds with zero errors in all three (1001/849/1020 modules respectively),
  every new module follows an existing, already-shipped pattern in its own app byte-for-byte
  (router/section registration copied from `budget2`/`afs-connect`; panel-mount shape copied from
  `milestones.ts`/`OrderMessaging.ts`), and every backend endpoint the new UI calls already has
  its own passing PHPUnit coverage from 21-A–21-L. This is real verification, not none — but it
  is not the same as clicking through the actual rendered pages, and that gap is real.
- **The coordinator Cost tab's "burn-up" is a snapshot, not a time series.** Honestly labeled as
  such in the UI itself (`render.ts`'s "Planned vs. earned vs. actual (snapshot)" heading) —
  `EngagementMetricsService` computes point-in-time totals only; no history of past snapshots was
  ever captured anywhere in this step, so a true burn-up-over-time chart would have needed
  invented data.
- **The People tab shows participant identity/rate only** — hours-this-week-vs-cap, average
  turnaround, revision rate per participant, and cross-engagement capacity (§21.11.4's fuller
  vision) are not built; no backend computes any of those four figures per participant today.
  Stated plainly in the tab's own footer text, not silently omitted.
- **The freelancer's `OrderSchedule.ts` panel (members) uses plain English strings, not this
  app's `t()` i18n convention** — `OrderMessaging.ts` right next to it on the same page is fully
  localized (`market.upload.messaging.*` keys); adding a matching key set for the new panel is
  real, scoped work this pass didn't do.
- **Members' own test suite cannot boot in this environment at all**, unrelated to anything this
  step touched: `routes/web.php:23` `require_once`s
  `public/checkpoint/server/php/vendor/autoload.php`, a path that doesn't exist in this checkout
  (confirmed: the whole `public/checkpoint/server/php/` directory is absent — a submodule/vendor
  tree not present locally). Pre-existing; `php -l` on both files this step modified in members
  passed clean, which is the verification that was actually possible.
- **Entitlement gating (§21.15) is not wired into the provisioning/command endpoints.** Only the
  global afs-connect kill switch gates `AfsConnectEngagementController`. The `market_orders`
  source registry entry (§21.15.1) is real and passes `SourceRegistryConsistencyTest`; the
  `afs_connect_engagements` count-limit resource (§21.15.2) was never adopted — this is the
  explicit, plan-sanctioned "build the seam, not the number" outcome for a resource requiring
  pricing sign-off.
- **One of `EngagementRiskService`'s seven flags is structurally limited, not silently faked.**
  `silent_freelancer` only sees delivery-shaped facts (message activity isn't synced as a fact in
  this step — §21.16.4 keeps message mirroring opt-in and out of scope), so an actively-messaging-
  but-not-delivering freelancer would still trip it. **`cap_breach_imminent` is now real** — see
  the "Hourly Contract follow-on" section below; this bullet is left here, corrected, rather than
  silently deleted, since the original report said it never fires.
- **21-N (mirroring approved hours into `pm_task_time_entries`) was not started, and still isn't.**
  It's gated on 21-A (shipped) and, as of the "Hourly Contract follow-on" work below, no longer
  gated on hourly `Contract` support existing (that gap is closed) — it remains the plan's own
  explicitly optional slice, genuinely desirable but not required for anything else, and was left
  unbuilt by choice given everything else this follow-on pass already covers.
- **`AFS_MODIFIER_ADDITION` was used as this step's file-tagging convention, not
  `MARKET_MODIFIER_ADDITION`.** A deliberate substitution made at the user's request mid-session,
  so every line this step touched — new files and every individual hunk inside modified ones — is
  greppable as one set across all three repos, distinct from market's/members' own per-step
  `MARKET_MODIFIER_ADDITION` history (added alongside it where both co-exist, never replacing it;
  coordinator had no prior tagging convention to preserve). `grep -rn AFS_MODIFIER_ADDITION`
  across all three repos reconciles exactly against this report's Files section below, in both
  directions.
- **`npx tsc --noEmit` cannot run in coordinator** — pre-existing, unrelated to this step:
  `tsconfig.json`'s `ignoreDeprecations: "6.0"` requires a newer TypeScript than the `~5.9.3`
  actually installed. TS changes (`pm-api.ts`, `state.ts`) were verified by careful manual read,
  not the compiler.
- **No live end-to-end HTTP run across both apps' dev servers.** Every cross-app contract
  (signing, provisioning, sync, provider reads, commands) is verified by real signed HTTP requests
  within each app's own test suite (Laravel's test client, not `Http::fake()`, wherever the
  request originates in that app) — but never through two actually-running processes talking to
  each other over a real socket.

## Hourly Contract follow-on (2026-08-20, same day, separate pass)

Requested directly by the user ("develop an hourly contract for a freelancer"), scoped via two
confirmed choices: connect hourly `Contract`s into Project Workspace the same way `ServiceOrder`s
already are, and build the buyer-side timesheet approval + ledger write for real rather than a
stub. This closed two gaps at once — the plan's own hourly-`Contract` support gap (Decision 4
above), and a separate, older deferral from step 15 §15.9 (`Portal\ContractsController`'s own
header comment: "nothing on this side approves [a timesheet] or auto-invoices it").

**What shipped:**

- **Real money movement.** `App\Services\Contracts\TimesheetService::approve()` (market, new) —
  mirrors `MilestoneService::approve()`'s pattern: fee computed via `FeeCalculator` from a new
  `contracts.platform_fee_percent` column (same shape/default as `services.platform_fee_percent`,
  since a `Contract` has no service/asset row to source one from), then a real
  `LedgerService::recordSale()` write. Reachable at
  `POST /portal/api/contracts/{contract}/timesheets/{timesheet}/approve`
  (`Portal\ContractsController::apiApproveTimesheet()`, new).
- **Deliberate deviation from `contract_timesheets`' own migration comment**, which describes
  `invoiced_order_id` as being set by "the same checkout path as any other purchase." Not built:
  `order_items.purchasable` is a non-nullable `Asset|Service` morph, and forcing a `Contract` in
  would mean weakening that contract for every other reader of the column. `invoiced_order_id`
  stays permanently null; `approved` is the terminal paid state. Documented in
  `TimesheetService`'s own header comment, not silently decided.
- **Hourly Contract → Project Workspace, the same shape ServiceOrder already has:**
  `EngagementBlueprintBuilder::buildForContract()`, `EngagementProvisioner::provisionForContract()`
  (both new methods, market) — `billing_type: hourly`, `contract_value_cents: 0` (open-ended, no
  fixed total), `weekly_limit_hours` now sent (coordinator's ingest already read it — the original
  21-D schema anticipated this field, it just had no sender until now), no tasks (hourly work has
  no milestone-shaped schedule).
- **Sync**: `EngagementSyncDispatcher::timesheetApproved()` (new) sends
  `timesheet_approved`(+`hours`)/`payment_released`(+)/`fee`(−) facts, mirroring
  `milestoneApproved()`'s three-fact shape. `send()`'s envelope-building logic extracted into a
  shared private `deliver()` so a new `sendContract()` (looked up via
  `engagement_links.contract_id`) doesn't duplicate it. Coordinator's `EngagementMetricsService`
  already computed `freelancer_hours` from `Σ facts where kind=timesheet_approved` (built in the
  original 21-J pass, dormant for lack of a sender) — it activates automatically with no
  coordinator-side metrics change needed.
- **`cap_breach_imminent` risk flag, previously "never fires by design," now real**
  (`EngagementRiskService::capBreachImminent()`, coordinator, new). Approximated deliberately: a
  timesheet is approved once, in arrears, for a whole week — there's no mid-week "hours ticking
  up" signal to project a trend from, only completed approval events. Flags when the CURRENT ISO
  week's already-approved hours reach ≥80% of `weekly_limit_hours`; a week with no approval yet
  (the common case, since approval lags submission) never trips it — documented as under-warning
  by design in the method's own comment, not assumed precise.
- **The "Manage as a project" trigger, for BOTH ServiceOrder and Contract, found missing and
  built.** Re-reading `EngagementProvisioner` while wiring the Contract half revealed
  `provisionForServiceOrder()` had **zero call sites anywhere in the app** — §21.7.1's own button
  spec was never wired to an endpoint in the original pass, only the read-only summary was. Fixed
  for both kinds at once: `EngagementController::apiCreateForServiceOrder()`/
  `apiCreateForContract()` (new), `POST /portal/api/services/{ref}/workspace` and
  `POST /portal/api/contracts/{ref}/workspace`. Only §21.7.1's state-eligibility gate is enforced
  (`in_progress|delivered|revision_requested` for a ServiceOrder, `active` for a Contract) — the
  entitlement/tier gate stays the same already-documented "seam built, not enforced" deferral as
  before this pass, not a new gap.
- **A buyer-facing Contract detail page, which did not exist at all before this pass.**
  `Portal\ContractsController` had only a list view and a read-only JSON `apiShow()` — no
  `/portal/contracts/{ref}` page. New: `ContractsController::show()`, `contract-detail.blade.php`,
  `resources/js/portal/contract.ts` (timesheet list + Approve buttons, mirroring
  `milestones.ts`'s pattern; mounts the workspace panel). `project.ts`'s `mountProjectPanel()`
  generalized to take a `kind: 'services' | 'contracts'` param and gained the "Create workspace"
  button state for both kinds (previously read-only-only).
- **Tests**: `tests/Unit/Contracts/TimesheetServiceTest.php` (new, market — real fee/ledger
  assertions, illegal-transition guard), `EngagementProvisionerTest`/`EngagementSyncDispatcherTest`
  extended with the Contract-side cases (market), `EngagementRiskServiceTest` extended with four
  `cap_breach_imminent` cases (coordinator).

**Verification**: market `php artisan test` — 50/51 passing (same one pre-existing unrelated
`ExampleTest` failure as before this pass; +8 new tests, all passing). Coordinator `php artisan
test` — 110/110 passing (+4 new). `npx vite build` clean in market, 850 modules (+1 for
`contract.ts`). `php -l` clean on every new/modified PHP file in both apps. No live browser
verification, no live MySQL migration run — `php artisan migrate:status` fails in this environment
(no reachable local MySQL server for market's configured `DB_DATABASE`), consistent with the "no
live browser verification possible" gap already documented above; the new migration
(`2026_08_20_000002_add_platform_fee_percent_to_contracts_table.php`) was not run against a real
database, only verified by `php -l` and by every fixture/test in this pass using sqlite
`:memory:`, which runs migrations fresh and passed.

**Files (hourly-contract follow-on only — additive to the Files section below):**

*Market — created:* `database/migrations/2026_08_20_000002_add_platform_fee_percent_to_contracts_table.php`,
`app/Services/Contracts/TimesheetService.php`, `resources/js/portal/contract.ts`,
`resources/views/portal/jobs/contract-detail.blade.php`, `tests/Unit/Contracts/TimesheetServiceTest.php`.

*Market — modified:* `app/Models/Contract.php`, `app/Services/Engagement/EngagementBlueprintBuilder.php`,
`app/Services/Engagement/EngagementProvisioner.php`, `app/Services/Engagement/EngagementSyncDispatcher.php`,
`app/Services/Engagement/EngagementSummaryService.php`, `app/Http/Controllers/Portal/EngagementController.php`,
`app/Http/Controllers/Portal/ContractsController.php`, `routes/portal.php`, `resources/js/portal.ts`,
`resources/js/portal/project.ts`, `resources/js/portal/serviceOrder.ts`,
`tests/Unit/Engagement/EngagementProvisionerTest.php`, `tests/Unit/Engagement/EngagementSyncDispatcherTest.php`.

*Coordinator — modified:* `app/Services/AfsConnect/Engagement/EngagementRiskService.php`,
`tests/Unit/AfsConnect/Engagement/EngagementRiskServiceTest.php`.

## Files

`grep -rn AFS_MODIFIER_ADDITION` across all three projects reconciles against this list in both
directions — no file below without the tag, no tagged file outside this list.

**Created (coordinator):**

| File | Slice |
|---|---|
| `database/migrations/2026_08_20_000001_add_origin_to_pm_tables.php` | 21-B |
| `database/migrations/2026_08_20_000002_create_afsconnect_engagement_tables.php` | 21-D |
| `app/Exceptions/Pm/StaleWorkspaceRevisionException.php` | 21-A |
| `app/Models/AfsConnect/{AfsConnectEngagement,AfsConnectEngagementFact,AfsConnectEngagementParticipant,AfsConnectEngagementCommand}.php` | 21-D |
| `app/Services/AfsConnect/Engagement/PmClientIdAllocator.php` | 21-C |
| `app/Services/AfsConnect/Engagement/EngagementProvisioningService.php` | 21-F/21-G |
| `app/Services/AfsConnect/Engagement/EngagementEventIngestService.php` | 21-H |
| `app/Services/AfsConnect/Engagement/EngagementMetricsService.php` | 21-J (+ `milestones()`/`participants()`, 21-K) |
| `app/Services/AfsConnect/Engagement/EngagementCommandDispatcher.php` | 21-L |
| `app/Services/AfsConnect/Engagement/EngagementRiskService.php` | 21-L |
| `app/Http/Controllers/Api/Internal/AfsConnectEngagementController.php` | 21-F/21-H/21-M (`provision`/`events`/`schedule`) |
| `app/Http/Controllers/Api/AfsConnect/AfsConnectEngagementMetricsController.php` | 21-J/21-L/21-K (`show` adds `risk`; new `index`) |
| `app/Http/Controllers/Api/AfsConnect/AfsConnectEngagementCommandController.php` | 21-L |
| `app/Jobs/AfsConnect/SendEngagementCommand.php` | 21-L |
| `resources/js/src/engagement/{api,store,render,features,charts,format,index}.ts` | 21-K — the whole Project Workspace UI module |
| `resources/js/html/views/engagement.html` | 21-K |
| `tests/Unit/Pm/{PmPersistenceServiceConcurrencyTest,PmPersistenceServiceOriginTest}.php` | 21-A/21-B |
| `tests/Unit/AfsConnect/AfsConnectSignatureMarketInteropTest.php` | 21-E |
| `tests/Unit/AfsConnect/Engagement/{PmClientIdAllocatorTest,AfsConnectEngagementTablesTest,EngagementProvisioningServiceTest,EngagementEventIngestServiceTest,EngagementMetricsServiceTest,EngagementCommandDispatcherTest,EngagementRiskServiceTest}.php` | 21-C/21-D/21-F/21-G/21-H/21-J/21-L |
| `tests/Feature/AfsConnect/{AfsConnectEngagementEndpointTest,AfsConnectEngagementScheduleEndpointTest}.php` | 21-F/21-H/21-M |

**Modified (coordinator):** `.env`, `config/afs_connect.php`, `config/afs_connect_sources.php`,
`routes/api.php`, `app/Http/Controllers/PmWorkspaceController.php`,
`app/Services/Pm/PmPersistenceService.php`, `app/Models/Pm/{PmProject,PmTask,PmUser}.php`,
`resources/js/src/{pm-api,state,ui}.ts`, `resources/js/views.ts`, `resources/js/html/index.html`
(nav item, 21-K), `tests/Unit/AfsConnect/SourceRegistryConsistencyTest.php`.

**Created (market):**

| File | Slice |
|---|---|
| `database/migrations/2026_08_20_000001_create_engagement_links_table.php` | 21-F |
| `app/Models/EngagementLink.php` | 21-F |
| `app/Services/Engagement/{CoordinatorSignature,CoordinatorClient}.php` | 21-E |
| `app/Services/Engagement/{EngagementBlueprintBuilder,EngagementProvisioner}.php` | 21-F/21-G |
| `app/Services/Engagement/EngagementSyncDispatcher.php` | 21-H |
| `app/Services/Engagement/{EngagementSummaryService,EngagementScheduleService}.php` | 21-M |
| `app/Services/AfsConnect/MarketAfsConnectProvider.php` | 21-I |
| `app/Jobs/DeliverEngagementEvent.php` | 21-H |
| `app/Http/Middleware/VerifyAfsConnectSignature.php` | 21-I |
| `app/Http/Controllers/Internal/AfsConnectProviderController.php` | 21-I |
| `app/Http/Controllers/Internal/AfsConnectEngagementCommandController.php` | 21-L |
| `app/Http/Controllers/Portal/EngagementController.php` | 21-M |
| `routes/afs_connect.php` | 21-I/21-L |
| `resources/js/portal/project.ts` | 21-M — the Project tab panel |
| `tests/Unit/Engagement/{CoordinatorSignatureTest,EngagementProvisionerTest,EngagementSyncDispatcherTest,EngagementSummaryServiceTest,EngagementScheduleServiceTest}.php` | 21-E/21-F/21-H/21-M |
| `tests/Feature/AfsConnect/{AfsConnectProviderEndpointsTest,AfsConnectEngagementCommandEndpointTest}.php` | 21-I/21-L |

**Modified (market):** `config/market.php`, `bootstrap/app.php`, `routes/internal.php`,
`routes/portal.php`, `app/Http/Controllers/Internal/ServiceOrderController.php` (adds
`schedule()`), `app/Services/ServiceOrders/ServiceOrderStateMachine.php`,
`app/Services/ServiceOrders/MilestoneService.php`, `app/Services/Ledger/LedgerService.php`,
`resources/js/portal/serviceOrder.ts` (mounts the Project panel, 21-M).
`.env` was deliberately **not** modified — `COORDINATOR_URL`/`AFS_CONNECT_SERVICE_KEY_MARKET`
need real values before this goes live; market's `.env` already carried pre-existing uncommitted
work this session did not touch.

**Created (members):**

| File | Slice |
|---|---|
| `resources/js/market/OrderSchedule.ts` | 21-M — the freelancer's Schedule panel |

**Modified (members):** `app/Http/Controllers/FreelanceController.php` (adds
`getServiceOrderSchedule()`), `routes/web.php`, `resources/js/market/FreelanceApi.ts` (adds
`getOrderSchedule()` + the `FreelanceScheduleTask` type), `resources/js/market/ServiceOrderQueue.ts`
(mounts the Schedule panel). This is the first time this session touched the members repo —
opened solely for this slice, nothing else in it was explored or changed.

## Verification

- **Coordinator:** `php artisan test` — 106/106 passing, 445 assertions, sqlite `:memory:`.
- **Market:** `php artisan test` — 42/43 passing, 76 assertions; the one failure
  (`Tests\Feature\ExampleTest`) is the default Laravel stub hitting `/`, which fails on a missing
  `categories` table — pre-existing, confirmed present before this step touched anything, unrelated
  to any file this step created or modified.
- **Cross-app contract verification**, all via real signed HTTP through each app's own Laravel test
  client (not `Http::fake()` at the boundary being tested):
  - Coordinator's `AfsConnectServiceAuthMiddleware` accepting a signature built the way market's
    `CoordinatorSignature` builds it (both an interop test reproducing market's exact algorithm on
    coordinator's side, and real signed feature-test requests against `/engagements`,
    `/engagements/{ref}/events`, `/engagements/{ref}/schedule`).
  - Market's `VerifyAfsConnectSignature` accepting a signature built the way coordinator's
    `SourceAppAfsConnectClient`/`SendEngagementCommand` build it (real signed feature-test requests
    against all four provider endpoints and the command endpoint).
- **`php artisan test` re-run after every slice**, not just at the end — every slice in this report
  landed with its own tests green before the next one started. Re-ran coordinator's and market's
  full suites again after the 21-K/21-M UI pass to confirm the backend additions
  (`EngagementMetricsService::milestones()`/`participants()`, the new `index()` endpoint,
  `FreelanceController::getServiceOrderSchedule()`) broke nothing: same 106/106 and 42/43 as
  before.
- **`npx vite build` — zero errors in all three apps**: coordinator (1001 modules), market (849
  modules), members (1020 modules, including the new `OrderSchedule.ts`/`FreelanceApi.ts` code in
  the `ServiceOrderQueue` chunk). This is real signal — a broken import, a type error strict
  enough to fail the build, or a syntax mistake in any new file would have failed this — but it is
  not the same as loading the page in a browser; see Known gaps.
- **`php -l`** clean on every new/modified PHP file across all three apps, including members'
  `FreelanceController.php` (members' own PHPUnit suite cannot boot in this environment at all —
  see Known gaps — so `php -l` plus the successful `vite build` of the same feature's frontend is
  the verification that was actually available for that leg).
- **Members' test suite could not be run** (pre-existing environment gap, not caused by this
  step) — see Known gaps.
- **No live run against two or more simultaneously-running dev server processes**, and no live
  browser interaction with any of the three apps' authenticated UI — see Known gaps.
