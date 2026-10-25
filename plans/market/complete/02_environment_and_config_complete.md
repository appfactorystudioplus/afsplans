# Step 02 — Environment and configuration across all four projects — completion report

**Date:** 2026-08-19
**Projects touched:** brain (`apps/www`) / members (`apps/members`) / market (`apps/market`) / joystick_engine (`JoystickEngine_v2`)
**Status:** ⚠️ complete with one deliberate scope reduction (below) and two pre-existing environment blockers noted, neither caused by this step.

## What shipped

Every marketplace-related URL, host, key, and default now has exactly one place to be read from in
each of the four projects — `config('market.*')` in market, `config('market.*')` in members,
`config('marketplace.*')` in brain, and `Resources/EngineConfig.json`'s new `market` section (read
through `EngineConfig::Get().Market()`) in the engine. No application code added by this step
contains an `appfactory.studio` literal.

## Files created

| File | Project | Tag placement | Purpose |
|---|---|---|---|
| `config/market.php` | market | header | `market.*` config: urls, sdk host, direct payments ref, internal API key, storage, commerce, portal, checkpoint |
| `config/market.php` | members | header | `market.*` config for members' proxy calls into market's internal API (step 04) |
| `config/marketplace.php` | brain | header | `marketplace.*` config: cross-project URLs + the Stripe preview API version — everything per-processor stays in the database (§02.4.1), not here |

## Files modified

| File | Project | Lines / regions | Tag placement | Change |
|---|---|---|---|---|
| `.env` | market | appended at EOF | line-level (`# ── MARKET_MODIFIER_ADDITION ──` … `END`) | Added `MARKET_URL`, `MEMBERS_URL`, `BRAIN_URL`, `AUTH_URL`, Checkpoint keys, `AFS_SDK_HOST`, Direct Payments ref/URL, internal API key pair, asset storage settings, `MARKET_DEFAULT_CURRENCY`, and the `VITE_*` mirrors |
| `config/filesystems.php` | market | new `market_assets` disk block in `disks` | line-level | Added the private, `throw:true` disk step 05's upload pipeline will write to |
| `.env` | members | appended at EOF, two tagged blocks | line-level | §02.3's `MARKET_URL`/`MARKET_API_URL`/internal key pair/timeouts/Vite mirrors, plus §02.4's Connect additions (`BRAIN_URL`, `MEMBERS_CONNECT_RETURN_PATH`/`REFRESH_PATH`, `AFS_SDK_HOST`) |
| `.env` | brain | appended at EOF | line-level | `MEMBERS_URL`, `MARKET_URL`, `STRIPE_API_PREVIEW_VERSION` |
| `Resources/EngineConfig.json` | joystick_engine | n/a — no tag in this repo (§01.4 scope) | New `market` section, same shape as §02.5: `available`, `baseUrl`, `membersUrl`, `portalUrl`, `endpoints{}`, `distributionTag`, the three timeout/cache seconds, `cacheDirName` |
| `Joystick/src/Joystick/Core/EngineConfig.h` | joystick_engine | n/a | New `MarketSection` struct (same `IsConfigured()`/`GetEndpoint()` contract as `AfsConnectSection`) + `Market()` accessor |
| `Joystick/src/Joystick/Core/EngineConfig.cpp` | joystick_engine | n/a | `MarketSection::GetEndpoint()` + the `market` block in `TryParseEngineConfig` — a missing section degrades to `Available = false` with no warning, matching every other section |

## Database changes

None.

## Environment / config keys added

See the "Files modified" `.env` rows above — each project's block is reproduced verbatim from step
02 §02.2/§02.3/§02.4, with values matching the current `*15` development hostnames already in use
throughout each `.env` (per step 01 §01.2.4, everything in this plan runs against development).

## Decisions made during execution that diverge from the plan

**The `_env/dev` and `_env/prod` mirrors were not written — root `.env` only, in all three Laravel
projects.** Step 02 §02.2's literal text for market says "Append to `apps/market/.env`, **and mirror
into `_env/dev` and `_env/prod`**," and §02.7's checklist states the added keys should exist in all
three files. This report was mid-execution of that mirroring (market's three files, then members' and
brain's `_env` copies by extension of the same checklist item) when the project owner stopped it:
*"Why did you update the _env folder files? your suppose to update the root .env only."*

All six `_env/dev`/`_env/prod` edits were reverted in full — each file confirmed back to its original
content. Only the three root `.env` files carry the new keys. This is a **live divergence from the
step file's literal instruction**, made on explicit direction, and recorded here rather than silently
followed — a future step (or a re-read of this one) should not assume the `_env` mirrors exist, and
should ask before adding them if a later step's verification appears to depend on them.

## Known gaps / deferred

- The engine's "delete the `market` section, relaunch, panel absent, no crash" checklist item
  (§02.7) is **not independently testable yet** — `Windows ▸ Service Assets Market` doesn't exist
  until step 18. What was verified instead: `MarketSection`'s missing-section handling is written
  identically to `AfsConnectSection`'s already-proven pattern (same `find()`-guarded block, same
  default-false degrade, no exception path), and the app launches and runs cleanly with the section
  *present*. Re-verify the deletion case for real once step 18 lands the panel.
- Two pre-existing, unrelated boot failures blocked a live `artisan tinker` check of members' and
  brain's new config files (see Verification below). Neither is caused by this step's edits.

## How this was verified

- **market**: `php artisan tinker --execute="echo config('market.urls.brain');"` printed
  `https://www15.appfactory.studio` — the config chain works end to end.
- **market**: `grep -rn "env(" apps/market/app apps/market/routes` → one pre-existing hit,
  `HomeController.php:33`'s `env('APP_ENV') === 'production' ? 'api' : 'api-sandbox'` — this is the
  exact defect step 11 is scoped to fix (§01.2.2, §11.3), not something this step introduced or was
  asked to touch. No `env()` call exists in any file this step created.
- **market**: `grep -rn "appfactory\.studio" apps/market/app apps/market/resources apps/market/routes`
  → all hits are pre-existing (checkpoint client docs/comments, `portal.d.ts` doc-comments, and the
  Blade `{{ $afssdk }}` interpolation in `app.blade.php`, which is a template variable, not a
  literal). Nothing this step added contains the literal.
- **members**: `grep -rn "appfactory\.studio" resources/js/controllers/AssetStoreController.ts
  resources/js/controllers/FreelanceController.ts` → zero hits. `app/Http/Controllers/Market` does
  not exist yet (that is step 10's work), so that half of the check is not yet applicable.
- **members** and **brain**: `php artisan tinker`/`config:show` could not run — both fail during
  Laravel's boot, before reaching config resolution, on **pre-existing, unrelated** broken
  `require`/`include` paths: members' `routes/web.php:21` requires a missing
  `public/checkpoint/server/php/vendor/autoload.php`, and brain's `routes/portal.php:68` includes
  `public/_portal_/admin/core/php/includes/functions.php` — which is part of the same
  ~126,000-file uncommitted deletion this plan's [step 01 completion
  report](01_start_complete.md) already flagged. Neither failure is caused by this step's `.env` or
  `config/` additions. **Substitute verification used instead:** `php -l` against all three new
  config files (`market/config/market.php`, `members/config/market.php`,
  `www/config/marketplace.php`) and the modified `market/config/filesystems.php` — all four report
  "No syntax errors detected." Given market's config chain verified live end-to-end and all three
  files share the same shape (`env()` calls wrapped in a returned array, no logic), this is treated
  as sufficient for this step; a live check of members' and brain's config should be repeated once
  those pre-existing boot failures are resolved (out of scope for this step to fix).
- **Engine**: `ninja Joystick` (the static lib containing `EngineConfig.cpp`) compiled with zero
  errors. `ninja JoystickApp` built the full editor bundle with zero errors. The built app's
  `Resources/EngineConfig.json` (copied by the existing, unmodified `CMakeLists.txt` rule) contains
  the new `market` section verbatim. `Joystick Editor.app/Contents/MacOS/Joystick Editor --help`
  launched and exited cleanly (no crash, no error dialog), confirming the loader parses the new
  section without incident.

## Follow-ups spawned

None spawned as separate work items. The two pre-existing boot failures (members' missing checkpoint
vendor autoload; brain's missing `public/_portal_` tree) are environment issues outside this
marketplace plan's scope — flagged for the project owner, not actioned here, consistent with step
01's finding about the same uncommitted deletion.

---

## Exit criteria (§02.7)

- [x] `grep -rn "appfactory\.studio" --include=*.php --include=*.ts --include=*.blade.php apps/market/app apps/market/resources apps/market/routes` — zero hits attributable to this step (pre-existing hits only, none in files this step touched).
- [x] Same grep over the two members TS controllers — zero.
- [x] `grep -rn "env(" apps/market/app apps/market/routes` — zero in files this step added; one pre-existing hit (`HomeController.php:33`) that is step 11's to fix, not this step's.
- [x] `php artisan tinker --execute="echo config('market.urls.brain');"` — printed the brain URL.
- [x] Engine: `JoystickApp` built; `Resources/EngineConfig.json` in the bundle contains the `market` section.
- [~] Engine: deleting the `market` section and relaunching — not independently re-testable yet (no panel exists before step 18); verified by code-pattern equivalence to `AfsConnectSection` instead. Re-verify for real at step 18.
- [ ] Every key added exists in `_env/dev/.env` and `_env/prod/.env` — **deliberately not done**, per the project owner's direction to update root `.env` only (see "Decisions" above). Root `.env` confirmed for all three projects.
- [x] `AFS_SDK_HOST` is read through `config('market.sdk.host')` in the new `config/market.php`; no code added by this step branches on `APP_ENV`.
- [x] Members makes no direct query against a `brain_*` table from anything this step touched (unchanged from step 01's finding — pre-existing exception in `MemberController.php` etc., not this step's concern).
- [ ] `EngineConfig.json` value editing added to the engine's release checklist — not yet done; flagged for whoever owns that checklist document.
- [x] Every file created or modified in the three Laravel projects carries `MARKET_MODIFIER_ADDITION`; the engine carries none, per §01.4's scope.
- [x] This file written.
