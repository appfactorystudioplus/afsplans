# Step 01 — Start: audit, architecture, and the conventions every later step obeys — completion report

**Date:** 2026-08-19
**Projects touched:** brain (`apps/www`) / members (`apps/members`) / market (`apps/market`) / joystick_engine (`JoystickEngine_v2`) — audit only, no code in any of them.
**Status:** ✅ complete, with three findings recorded below that later steps must account for.

## What shipped

Step 01 produces no code by design. What this pass delivered:

1. Re-verification of every row in §01.2's four project tables against the current working tree, via
   one dedicated read-only audit per project (members, market, brain/www, joystick_engine), run in
   parallel and checked line-by-line against the step file's claims.
2. A full read of steps 02–20 (19 was the stated exit criterion; 20 was also read since it is an
   active parallel track referenced throughout 08 and 09) to confirm the shape of the whole plan is
   understood before any code is written.
3. Three findings that were not visible when 01_start.md was originally written, recorded below so
   step 02 onward does not re-derive or contradict them.

## Files created

None. Per the step's own header: "Produces: no code."

## Files modified

None.

## Database changes

None.

## Environment / config keys added

None — step 02's job.

## Re-verification results, by project

### members (`apps/members`)

All eleven rows in §01.2.1 hold, with two corrections:

- **Route count is 23, not 22** at `routes/web.php:75–99` (11 freelance + 12 assetstore). Trivial, but
  step 04/06/07's file lists should say 23 if they enumerate routes.
- **`brain_memberships` is not accessed exclusively through the Brain HTTP API today**, contrary to
  what a literal reading of D7 (§01.3) implies about current state. `app/Models/Brain/Membership.php`
  sets `protected $table = 'brain_memberships'` and is queried directly via Eloquent in three
  controllers: `MemberController.php:28`, `AfsConnectSettingsController.php:177`,
  `AfsConnectTokenController.php:90`. D7's text already anticipates this kind of gap ("any statement
  that members reads brain_memberships locally is wrong" is stated as the *rule going forward*, not
  as a claim about every existing call site) — but it is worth being explicit: **this is a
  pre-existing exception to D7, not something this plan introduced, and not something step 04 needs
  to touch.** It should not be copied as a precedent by new marketplace code, and no later step
  should point at it as justification for a new direct `brain_*` query.

Everything else — `AssetStoreController.ts`/`.php`, `FreelanceController.ts`/`.php`, `views.ts`,
`main.ts`, the `DomainController.php` HTTP pattern, and the absence of any marketplace migration —
matches the step file exactly, including the specific `localStorage` keys, the "Simulate file upload"
comment, and the session-key shape.

### market (`apps/market`)

All thirteen rows in §01.2.2 hold. Two cosmetic notes, neither changing any later step's design:

- `PurchaseController::processPayment()`'s fake transaction id now reads
  `'TXN-' . strtoupper(bin2hex(random_bytes(8)))` — a `strtoupper()` was added since the audit was
  written. Still fake, still never contacts a processor. Step 13 deletes this method outright either
  way.
- A **second copy of `portal.d.ts`** exists at `resources/js/checkpointHelper/static/portal.d.ts`,
  in addition to the one the step file cites at `resources/js/portal.d.ts`. Step 08 §08.5.6 requires
  the Connect type additions to land in both `external/portal.d.ts` and market's copy "in the same
  commit as the runtime" — whichever step does that (08, then consumed by 11/13) should check both
  market-side files, not just one, or the second one silently drifts.

Everything else — the `2026_01_23_*` migrations, the `user_id` FKs, the thin models, the working
storefront, the `$afssdk` gap on three controllers, the Dream-Graph-shaped `dashboard.ts`, the
un-built `dashboard.ts`, `bootstrap/app.php`'s routing, `portal.d.ts`'s 1269 lines, and the missing
`.env` keys — matches exactly.

### brain (`apps/www`)

All eleven rows in §01.2.3 hold. One finding here is more consequential than a line-number drift:

> ### ⚠️ `apps/www` currently has ~126,600 uncommitted deletions on `main`
>
> Verified directly: `git status --porcelain` in `apps/www` reports **126,584 deleted, unstaged
> files**, on `main`, up to date with `origin/main`. This spans `core/scripts/includes/` in full
> (not only the `libs/payments/` subtree step 01 originally scoped), `core/scripts/_old/`, all of
> `core/services/*` (including the Stripe sample-apps repo at `core/services/stripe/`), and large
> parts of `public/_portal_/`.
>
> **What this means for the plan:** step 01 §01.2.3 and step 20 both describe
> `core/scripts/includes/` as already deleted by the project owner (2026-08-18) — that description is
> accurate for the *working tree*, but the deletion has never been committed. It is one
> `git checkout -- .` / `git restore .` / a bad merge away from being silently reversed, which would
> resurrect the legacy Stripe scripts, `core/services/stripe/`, and everything else step 20 treats as
> gone.
>
> **This is not a defect in this plan or something step 01 should fix** — per the standing "no git
> operations in `apps/*`" rule (§01.4) and step 20's own "NO DELETIONS" rule, committing this is the
> project owner's call, not a plan step's. It is recorded here so that:
> - Step 20's verification checklist item *"Nothing was deleted… `git status` in `apps/www` shows no
>   deletions attributable to this step"* is read correctly — the ~126K deletions predate step 20 and
>   are not attributable to it, but a naive reader running `git status` mid-step-20 would see a huge
>   diff and could misattribute it.
> - Anyone resuming this plan later checks `git status` in `apps/www` before assuming the legacy tree
>   is actually gone.

Two smaller corrections:

- Connect routes in `routes/portal.php` are now at **lines 522–526**, not 520–524 (shifted +2).
- In `ProcessorConnectController.php`, `index()` (a fifth public method beyond the four the step file
  names) does not touch `\Stripe\*` — it is a plain DB read. Doesn't change the "cannot execute"
  verdict for the four Connect-creating/managing methods, just a precision note.

Everything else — the 1881-line `direct_payments/api.ts`, the processors SDK files (`StripeClient.ts`
is exactly 412 lines as claimed), the absent Stripe PHP SDK, the 29 raw-cURL call sites across seven
controllers, `ProcessorConnectController`'s unresolvable `\Stripe\*` calls, `createIntent()`'s missing
split fields, the hardcoded onboarding URLs (verbatim, including the code's own comment), the
`Membership` model, and the `single_charge` playground's `httpUrl`/DP-ref literals — matches exactly.

### joystick_engine (`JoystickEngine_v2`)

All ten rows in §01.2.4 hold. One cosmetic correction: **~21 panel files exist in
`JoystickWindow/src/Panels/`, not ~25** (`ComponentRegistry.h` and `NodeEditorContext.h` in that
directory are support files, not panels, and were likely counted in the original estimate). Not
load-bearing for anything step 18 depends on.

Everything else — `EngineConfig.json`'s three sections and the two CMake copy lines,
`EngineConfig.h/.cpp`'s Meyers-singleton pattern and tolerance for a missing section,
`ConnectPanel`/`DreamGraphSyncPanel`'s `DrawContent()`-only convention, the `PanelId` enum at
`EditorLayer.h:249`, `EngineMenus.cs`'s `RegisterDynamicItems()`/`TopLevelOverridePathFor()` mechanism
(including the exact `"Connect" => "Settings/Connect"` precedent step 18 copies), the cross-platform
`HttpClient`, `ConnectIdentity`'s session-file polling, `ConnectService`'s worker/snapshot threading
pattern, `AssetLibrary`/`AssetMeta`'s `.jmeta` sidecars, and `Project::GetAssetDirectory()` — all
confirmed present and unchanged in shape.

`plan/steps/090_service_assets_market.md` (the mirror stub) already exists, as expected — it is the
untracked file visible in this repo's `git status` at the start of this session.
`plan/_guides/steps_plan_execution/something24-step-90-service_assets_market.md` does not exist yet,
also as expected, since step 18/90 has not been executed.

## Decisions made during execution that diverge from the plan

None. D1–D5 (§01.3) were read and are architecturally sound as written; no amendment is proposed.
The three findings above are **corrections to measured state**, not changes to any decision.

## Known gaps / deferred

- The `apps/www` uncommitted-deletion state (above) is not resolved by this step and is not this
  plan's to resolve — flagged for the project owner's attention, not actioned.
- The second `portal.d.ts` copy in market (`checkpointHelper/static/`) is not reconciled here — flagged
  for whichever of steps 08/11/13 next touches the Connect type declarations.

## How this was verified

Four parallel read-only audits (one per project), each checking every specific claim in step 01's
§01.2 tables — file existence, exact line numbers where cited, code shape (localStorage keys, cURL
call counts, struct names, route counts, etc.) — against the live working tree via `grep`, direct file
reads, and `git status`. Results cross-checked against the step file by hand. The `apps/www` git-status
finding was independently re-verified directly (`git status --porcelain | wc -l` → 126,585 lines;
`grep -c '^ D '` → 126,584 deletions) rather than taken solely on the sub-agent's word.

Steps 02 through 20 were read in full (not sampled) to satisfy the exit criterion "Steps 02–19 have
been read end to end so the shape is known before the first line of code" — 20 was included because
it runs in parallel with 08 and is referenced by 08's prerequisites.

## Follow-ups spawned

None spawned as separate work items. The `apps/www` uncommitted-deletion state should be raised with
the project owner directly (out of band — it is not a code change any plan step performs) before step
20's host work or step 08's SDK install proceeds, since both assume the deleted-file state is durable.

---

## Exit criteria (§01.6)

- [x] Every table in §01.2 re-verified against the working tree. Three corrections recorded above (route count, panel count, Connect-route line numbers); one consequential finding recorded (the uncommitted `apps/www` deletion); one pre-existing D7 exception noted (direct `brain_memberships` Eloquent queries in three members controllers).
- [x] D1–D5 read and agreed; no amendment needed.
- [x] Steps 02–19 (and 20) read end to end.
- [x] `MARKET_MODIFIER_ADDITION` tag check — not applicable: this step creates and modifies no files, so `grep -rn MARKET_MODIFIER_ADDITION` attributable to step 01 is correctly empty across all three Laravel projects.
- [x] This file written.
