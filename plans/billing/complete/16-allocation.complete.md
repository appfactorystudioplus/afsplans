# 16 — Allocation (Processors + Direct Payments) — Complete

## Status

Done. All three `AFS_ALLOC_V2_TAG` call sites wired for both `domain_processors_count`
(Processors) and `domain_direct_payments_count` (Direct Payments), the level-99 bypass added
to the shared `CheckpointAllocV2` client and wired once in `www/resources/js/registry.ts`,
`showAllocationLimit()` added and used by both resources, fail-closed `canUse` behaviour on
both resources, and `php artisan alloc:reconcile-payments` added. `php -l` on every changed
PHP file and `tsc --noEmit -p tsconfig.json` on the whole `www` TS project both pass clean.

## Summary

- **Level-99 bypass** (§16.6/PA3b) — the bypass did not exist anywhere in the actual
  `CheckpointAllocV2` client (`www/resources/js/checkpoint/alloc_v2.ts`), only in
  `plans/allocation.md`'s description of it. Added `bypass?: boolean` to `AllocV2Config`,
  threaded it through `canUse()`/`consume()`/`release()` (each short-circuits before touching
  Parse at all) and through `resolveLimit()` (so `get()`/`getAll()` report real usage with
  `limit: -1`/`enabled: true`). Wired once in `registry.ts`'s `createAllocV2()`:
  `bypass: Number(_app.window.member?.level ?? 0) === 99`.
- **`showAllocationLimit()`** (§16.5) — added to `www/resources/js/portal/admin/utils/helpers.ts`,
  reused by both Processors and Direct Payments, replacing the old always-"Not Authorized"
  dialog. Distinguishes tier-disabled / limit-reached / check-failed per §16.5's table, with no
  upgrade prompt on the check-failed case.
- **Processors — all three call sites** (§16.3/PA1), all new:
  - `canUse` preflight in `ProcessorsAdminView.ts`'s new `attemptOpenCreateModal()`, wrapping
    both places that used to call `openCreateModal()` directly (the header button and the
    empty-state button).
  - `consume` in `handleSave()`, after `saveProcessor()` resolves — **unconditional**, not
    guarded on `!isEditing` (see deviation below).
  - `release` in `ProcessorDetailView.ts`'s `handleConfirmDelete()`, after `deleteProcessor()`
    resolves.
- **Direct Payments — `release` and, since none existed, the delete UI it needed** (§16.4/PA2):
  - No delete endpoint existed for Direct Payments at all — no route, no controller method, no
    button in the admin list. Added a `Delete` button + confirm modal (`dpDeleteModal`) to
    `directpayments.html`, a `destroy()` method to `DirectPaymentsController` (deletes
    items/tiers/connect/notifications alongside the parent — config data, not an audit trail),
    and `DELETE /portal/api/v1/directpayments/{reference_num}`.
  - Fixed the pre-existing **fail-open** bug in the `canUse` `.catch()` on the create button —
    it navigated to the create form on every check failure, including
    `ALLOC_V2_TIER_DISABLED`. `domain_direct_payments_count` is `overage: block`; §16.7 requires
    fail-closed.
- **`alloc:reconcile-payments`** (§16.8/PA4) — new. See the deviations section for why it
  resolves exactly one member per deployment rather than iterating "for each member, for each
  domain" as §16.8's literal text describes, and for the Parse-side implementation.

## Files changed

**`www/resources/js/checkpoint/alloc_v2.ts`** (shared client — see deviations)
- `AllocV2Config.bypass?: boolean`; stored on `this.config` with `?? false`.
- `resolveLimit()`: under bypass, returns `{ limit: -1, enabled: true, ...real period/overage }`.
- `canUse()`/`consume()`/`release()`: each returns synthetically under bypass with **no** Parse
  round-trip (`canUse` never blocks even on a Parse outage; `consume`/`release` are true no-ops
  matching "writes nothing").
- `buildMetric()` takes a `bypass` flag; `getAll()`'s three call sites and `get()`'s one now
  pass `this.config.bypass` so real usage still shows through under bypass.
- Added `bypass?: boolean` to `AllocV2Metric`, `AllocV2CheckResult`, `AllocV2MutationResult`.

**`www/resources/js/registry.ts`**
- `createAllocV2()`: added `bypass: Number(_app.window.member?.level ?? 0) === 99`.

**`www/resources/js/portal/admin/utils/helpers.ts`**
- New `showAllocationLimit(app, check, resourceLabel)`.

**`www/resources/js/portal/admin/Processors/ProcessorsAdminView.ts`**
- New `attemptOpenCreateModal()` (canUse preflight), called from both places that used to call
  `openCreateModal()` directly.
- `handleSave()`: unconditional `consume()` after `saveProcessor()` resolves.
- New module-level `currentApp`, set in `initProcessorsAdminView()`, so `handleSave()` (bound as
  a bare event listener with no `app` param) can reach the current domain.

**`www/resources/js/portal/admin/controllers/processors/ProcessorDetailView.ts`**
- `handleConfirmDelete()`: `release()` after `deleteProcessor()` resolves.

**`www/resources/js/portal/admin/controllers/direct_payments/directpayments.ts`**
- `dpCreateBtn` handler: `.catch()` now fails closed via `showAllocationLimit()` instead of
  navigating to the create form.
- New delete UI: `.btn-delete-dp` buttons in `renderList()`, `dpConfirmDeleteBtn` handler
  (DELETE fetch + `release()` + list refresh).
- Both existing `canUse`/`consume` call sites switched from an inline `subdomain_properties`
  lookup to the existing `getSubdomainProperty()` helper (already used by the pattern this
  mirrors; no behavioural change).

**`www/resources/js/portal/admin/html/views/directpayments.html`**
- New `#dpDeleteModal`, mirroring `processorDeleteModal`'s markup.

**`www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php`**
- New `destroy(string $reference_num)`.

**`www/routes/portal.php`**
- New `Route::delete('/{reference_num}', [DirectPaymentsController::class, 'destroy'])` under
  `api/v1/directpayments`.

**`www/config/services.php`**
- New `services.parse` block (`app_id`/`master_key`/`server_url`), reading env vars that were
  already present in `.env` but unused anywhere in `app/` before this step.

**`www/app/Console/Commands/AllocReconcilePayments.php`** (new)
- `php artisan alloc:reconcile-payments [--dry-run] [--fix] [--member=]`.

## Design decisions not fully spelled out in the plan text

**The level-99 bypass had to be built, not just wired.** §16.6 and `plans/allocation.md` §6
describe `bypass` as an existing `CheckpointAllocV2` config option that the factory "lifts" —
"Passed into the client rather than checked at each call site... Every call site is then
unchanged." In the actual `alloc_v2.ts`, there was no `bypass` field on `AllocV2Config`, no
branch anywhere in `canUse`/`consume`/`release`/`resolveLimit` that read one, and no `bypass`
field on any result type. The described behaviour (`canUse` never blocks and never round-trips
to Parse to decide that; `consume`/`release` are no-ops that write nothing; `get`/`getAll` show
real usage with `limit: -1`) had to be implemented from scratch in the shared client before
`registry.ts` could wire a single line to it. `alloc_v2.ts` is a synced copy across eight apps
(`plans/allocation.md` §3) — this change is only in `www`'s copy and needs re-syncing
estate-wide the same way a `portal.d.ts` change does, per this project's own established
convention for that file.

**Processors' `handleSave()` needed no `isEditing` guard — the plan's own reasoning for adding
one does not hold against the current code.** §16.3 states: *"`handleSave()` currently has no
`isEditing` notion — creation and update share the path. It gains one, derived from whether
`payload` carries an existing id."* Reading the actual file: `handleSave()` calls
`saveProcessor()` unconditionally, which always POSTs to create
(`ProcessorsService.ts:350-355`). Editing a processor happens entirely in a different file —
`ProcessorDetailView.ts`, via `updateProcessor()` (PUT) — and `ProcessorsAdminView.ts` carries
an explicit comment confirming this split was deliberate: *"Edit form population logic removed
(handled in ProcessorDetailView now)."* `buildPayload()` never reads the `proc-db-id` field
`resetForm()` clears. So every successful `saveProcessor()` call in this file is a genuine new
processor, and `consume()` runs unconditionally rather than behind a guard that would have had
nothing to guard against. Direct Payments' `directpayments-form.html`/`directpayments.ts` genuinely
does share one form between create and edit (that `isEditing` guard was already correct there,
pre-existing from before this step) — the two views are not symmetric the way §16.3 assumes.

**`alloc:reconcile-payments` resolves exactly one member per deployment, not "for each member,
for each domain."** §16.8's table implies a loop over members/domains with a `WHERE` clause
scoping rows to each. Neither `laravel_processors` nor `laravel_direct_payments` has a
member/user column at all (verified against
`database/migrations/2026_05_21_000001_create_processor_tables.php` and
`2026_04_03_160000_create_laravel_direct_payments_table.php`); `laravel_direct_payments` does
declare `website_domain`/`website_reference_num` columns, but
`DirectPaymentsController::saveDirectPayment()` never populates either, so they carry no data
to filter on either. §16.6 itself assumes the opposite when it says the reconciler "does
[scope to the member] anyway by joining on the member" — there is no join available.

This matches `AdvancedConfigGate`'s own documented conclusion (`../../www/direct_payments/26-advanced-policy.md`
§12.8): a `www` deployment IS one member's site. The command resolves that one member via
`FunctionsController::GetManagedDomain()['Username']` → `Membership::where('reference_num',
...)`, the identical resolution `AdvancedConfigGate` and `BrainGuard` already use — including
from a console command: `BrainGuard` is called from `BillingEnforceMembership` (step 08) and its
own docblock documents the CLI path (no `HTTP_HOST` → `getSubdomain2()` reads `type.txt` off
disk; missing file fails closed). A background investigation into this exact question found
that `GetManagedDomain()` is unreachable in *this specific checkout* — it calls
`getSubdomain2()` unqualified inside a namespaced class, which PHP resolves as a global
function defined in `public/_portal_/admin/core/php/includes/functions.php`, a legacy tree not
present in this git checkout (the same pre-existing environment gap every prior completion doc
notes for why `php artisan` cannot boot end-to-end here) — but confirmed it works as designed on
a real deployment, since `BrainGuard` already depends on it from `BillingEnforceMembership`
without issue there. `--member=<reference_num>` is provided as an explicit override for this
environment (paired with `config('app.url')`'s host as the domain, since `GetManagedDomain()`
can't be reached to supply one).

**Parse access from PHP is new — there was no PHP Parse client anywhere in this app.**
`PARSE_APP_ID`/`PARSE_MASTER_KEY`/`PARSE_SERVER_URL` were already present in `.env` (no `VITE_`
prefix, so server-only) but unused anywhere in `app/` — every existing Parse interaction in this
codebase is client-side JS using the non-secret application id. Rather than adding a Composer
Parse SDK dependency, the reconciler talks to the Parse REST API directly via Laravel's `Http`
facade (`Http::withHeaders([...])->get(...)`), the same pattern `FunctionsController::brainCall()`
already uses for the Brain API. Credentials are read via `config('services.parse.*')`, added to
`config/services.php` in this pass (was previously absent), following the config-not-`env()`
convention `config/billing.php` established in step 08.

**The reconciler reads/writes the same flat `ac_*` counter `alloc_v2.ts` treats as authoritative**,
not the nested `domains[].resources` projection. `scopeToken()` (the DJB2-style hash that turns
a domain into the counter field's suffix, `alloc_v2.ts:613`) is ported to PHP byte-for-byte in
the command's own `scopeToken()` — verified before use by computing `scopeToken('example.com')`
and `scopeToken('www15.appfactory.studio')` in both Node and PHP and diffing the output
(`example_com_1ofa56m` / `www15_appfactory_studio_qt49jx` in both). `--fix` writes only the flat
counter; the nested projection is left stale until the next real `consume()`/`release()`
rewrites it, since `readUsage()` always prefers the flat field first — a cosmetic gap in Parse's
raw dashboard view only, not a functional one. Reproducing `writeNestedProjection()`'s full
find-or-create-by-`key` merge logic in PHP for a one-off reconciler was judged not worth
maintaining as a second implementation of the same logic.

**`showAllocationLimit()`'s signature is `(app, check, resourceLabel)`, not the plan's
single-argument `showAllocationLimit(check)`.** The plan's own snippets (§16.3, §16.7) call it
with one argument and never explain how it would reach `app.window.registryManager` or know
which resource's copy to show, despite §16.5 stating it "replaces [the dialog] for both
resources" with resource-specific wording ("Payment processors aren't included..."). Both are
needed for the function to actually work as specified, so both were added as parameters.

**Message copy for the three cases in §16.5's table is followed closely but not verbatim** — the
table's exact examples are tier/limit-specific ("aren't included on the Free plan", "using all 3
processors"); the implementation is one shared function for two resources across all five tiers,
so it parameterises on `resourceLabel` ("Payment Processors" / "Direct Payments") rather than
hardcoding "Free plan" or a specific number, while preserving the table's structure: no number
in the tier-disabled case, `limit` in the reached-limit case, and the exact check-failed string
("We couldn't verify your plan limits. Please try again.") verbatim, since that one has no
resource-specific content to interpolate.

## Verification performed

- `php -l` on every changed/added PHP file
  (`DirectPaymentsController.php`, `routes/portal.php`, `config/services.php`,
  `AllocReconcilePayments.php`) — all clean.
- `./node_modules/.bin/tsc --noEmit -p tsconfig.json` from `www/` — clean, zero errors, after
  every TS change in this step (checked incrementally, not just at the end).
- `scopeToken()`'s PHP port verified against its Node/TS source for two real inputs
  (`example.com`, a real tenant hostname) — outputs matched exactly, confirming the masking
  behaviour (`& 0xFFFFFFFF` per-iteration, matching JS's per-iteration `>>> 0` rather than a
  single mask at the end — the two are not equivalent for a value that overflows mid-loop).
- `grep -rn "AFS_ALLOC_V2_TAG"` returns all six call sites across the two files that own them
  (three in `ProcessorsAdminView.ts`/`ProcessorDetailView.ts` for processors, three in
  `directpayments.ts` for Direct Payments), plus the client-side implementation
  (`alloc_v2.ts`, `registry.ts`) and the reconciler and shared helper — satisfying §16.11's
  acceptance line.
- **Not verified**: no live runtime check of any of §16.11's behavioural acceptance criteria
  (tier-2 limits, tier-0 messaging, level-99 bypass end-to-end, drift correction) — this
  checkout's `php artisan` cannot boot at all (a pre-existing, already-documented gap: a missing
  legacy include under `public/_portal_/`, confirmed again directly in this step while
  investigating `GetManagedDomain()`'s CLI path), and there is no live Parse registry or member
  account available to exercise from here. `alloc:reconcile-payments` itself has not been run
  against a live Parse instance for the same reason.

## Follow-ups for later steps

- **`alloc_v2.ts`'s new `bypass` support needs syncing to the other seven apps' copies**
  (`plans/allocation.md` §3's table) if the bypass is meant to work outside `www` too — this
  step only touched `www`'s copy, since that's the one app step 16 covers.
- **§12.2's `main.ts:565-591` exploration code** (three discarded `allocV2` calls on every
  portal boot, with a commented-out `consume`) was left untouched — it predates this step (the
  email-accounts resource it exercises was already wired before step 16), and removing dead
  exploration code for an unrelated resource was judged out of scope for a step about
  processors and Direct Payments specifically.
- **`--fix`'s nested-projection staleness** (see deviations) would be closed by porting
  `writeNestedProjection()`'s full merge logic to PHP, if the Parse dashboard's raw view is ever
  actually used as a debugging surface for this data.
- **Server-side enforcement** remains explicitly out of scope per §16.9 — `ProcessorAdminController::store()`
  and `DirectPaymentsController::saveDirectPayment()` still accept a create regardless of any
  client-side allocation check, same as documented in the plan.
