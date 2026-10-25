# Step 06 — Members: the asset publishing experience — completion report

**Date:** 2026-08-19
**Projects touched:** members, market
**Status:** ⚠️ complete with gaps

## What shipped

A seller in members can now publish a real asset end to end without a single `localStorage` call
or `alert()`. The old one-modal "Upload Asset" flow is gone; in its place is a six-stage wizard
(`AssetPublishWizard.ts`) reached from the assets listing page:

1. **Details** — title, short/long description, category (live from market), tags. Creates the
   market draft (`as_…`) on first Continue; every later stage `PUT`s the same draft.
2. **Files** — folder picker or drag-and-drop → `FilenameAnalyzer` (step 05) → the shared issue
   dialog → `PackageZipper.buildZip()` → chunked upload with a real progress bar
   (`ChunkedUploader`) → `finalize`. Continue is disabled while any blocking filename issue is
   unresolved, per §06.2's hard requirement.
3. **License** — the system default license shown in full, or a member-authored markdown license
   with a live preview, saved and reusable via a dropdown.
4. **Distribution** — Joystick Engine vs. Other, with live package-name format + uniqueness
   checking and a non-blocking "no `Assets/` folder" layout hint read from the version just
   finalized.
5. **Pricing** — price, currency, tax mode/rate, with the exact worked breakdown from §06.2
   ($40.00 / 8.25% exclusive / 10% fee) recomputed on every keystroke, plus the bordered 10%-fee
   disclosure and a "free asset" state at price = 0.
6. **Review and publish** — read-only summary with per-stage Edit links, cover-image upload, then
   `POST /assets/{ref}/publish`. A `422` gate failure renders every failing rule at once, each with
   a button back to the stage that fixes it.

The listing page (`assetstore.html` / `AssetStoreController.ts`) now reads real data from
`GET /sellers/{ref}/dashboard` and `GET /sellers/{ref}/assets`: status badge, distribution chip,
version count + latest version, buyers (entitlement count), lifetime revenue, and per-row
Edit / New version / Unpublish / Delete (only when zero entitlements) / View on Market. The
payment-methods section and its modal are deleted outright — no replacement, matching step 04's
already-made call that Connect (step 10) replaces this entirely.

A genuine, previously-undiscovered bug was found and fixed along the way: `AssetController::destroy()`
(market, step 04) has called `$asset->entitlements()` since it was written, but `Asset` had no such
relation — every delete attempt threw `BadMethodCallException`. See "Decisions made" below.

## Files created

| File | Project | Tag placement | Purpose |
|---|---|---|---|
| `resources/js/market/FilenameIssueDialog.ts` | members | header | Renders `FilenameAnalyzer`/`ArchiveValidator` issue reports; the Continue-button gate |
| `resources/js/market/MarketApi.ts` | members | header | Typed client for `/api/assetstore/*`; implements `ChunkUploadTransport` |
| `resources/js/market/AssetPublishWizard.ts` | members | header | The six-stage wizard orchestrator |
| `resources/js/market/LicenseEditor.ts` | members | header | License picker cards + a hand-written, escape-first markdown preview |
| `resources/js/market/PricingBreakdown.ts` | members | header | Integer-cents pricing math + live breakdown render |
| `resources/js/market/FilenameIssueDialog.test.ts` | members | header | jsdom tests: gating logic + live dialog interaction |
| `resources/js/market/LicenseEditor.test.ts` | members | header | Markdown-preview escaping/rendering tests |
| `resources/js/market/PricingBreakdown.test.ts` | members | header | Money-math tests against §06.2's worked example |
| `resources/js/templates/views/asset_publish.html` | members | header | Wizard shell (stage indicator, stage body, toast host) |
| `app/Http/Controllers/Internal/CatalogController.php` | market | header | `GET /categories`, `GET /package-name-availability` — gaps found wiring stage 1/4, not covered by step 04 |

## Files modified

| File | Project | Lines / regions | Tag placement | Change |
|---|---|---|---|---|
| `resources/js/controllers/AssetStoreController.ts` | members | whole file rewritten | header | All storage/mock logic removed; now a thin `MarketApi`-backed listing controller |
| `resources/js/templates/views/assetstore.html` | members | header cards, assets table, removed asset+payment modals and payment section | line-level | Extended table columns; wizard replaces the modal; toast host added |
| `resources/js/views.ts` | members | 2 import lines + new `asset_publish` subview registration | line-level | Registers the wizard view, lazy-imported |
| `app/Http/Controllers/AssetStoreController.php` | members | +11 new proxy methods | line-level (from step 04) | `getAsset`, `listCategories`, `checkPackageName`, `listLicenses`, `createLicense`, `beginVersion`, `uploadChunk`, `uploadStatus`, `abandonUpload`, `finalizeVersion`, `uploadMedia` |
| `app/Services/Market/MarketClient.php` | members | +2 methods | line-level (from step 04/05) | `postRaw()` (raw-body relay for media, mirrors `putChunk()`) |
| `routes/web.php` | members | 88–99 region (handlers only, paths unchanged) + 14 new routes after it | line-level | New routes for the wizard's backing calls |
| `resources/js/i18n/en.json` | members | +118 keys appended | *(JSON — cannot carry a code-comment tag; not in `grep -rn MARKET_MODIFIER_ADDITION`, same unavoidable exception every prior step's JSON/config file has had)* | All `market.upload.*` strings (stages, dialog, license, distribution, pricing, review, listing) plus the 22 `market.upload.rule.*` filename-issue reasons |
| `app/Http/Controllers/Internal/AssetController.php` | market | `index()` | line-level | Added `with('latestVersion')`, `withCount(['versions','entitlements'])`, `withSum('orderItems as revenue', 'seller_net_amount')` for the listing page's per-row data |
| `app/Models/Asset.php` | market | +2 relations | line-level | **Bug fix**: added the missing `entitlements()`/`orderItems()` `MorphMany` relations (see below) |
| `routes/internal.php` | market | +3 lines | line-level | Registers `CatalogController`'s two routes |

## Database changes

None. No migrations were needed this step — every table `AssetPublishWizard` writes to
(`assets`, `asset_versions`, `market_licenses`) already existed from steps 03/05.

## Environment / config keys added

None.

## Decisions made during execution that diverge from the plan

- **`Asset::entitlements()`/`Asset::orderItems()` added — a bug fix, not a feature.**
  `AssetController::destroy()` (market, written in step 04) calls `$asset->entitlements()->exists()`
  to gate deletion, but `Asset` never defined that relation. Live-reproduced before touching
  anything: `php artisan tinker` → `$asset->entitlements()` → `BadMethodCallException: Call to
  undefined method App\Models\Asset::entitlements()`. Every delete attempt on any asset, from step
  04 onward, would have 500'd. Fixed with two `morphMany()` relations against the existing
  `purchasable_type`/`purchasable_id` columns `Entitlement`/`OrderItem` already declare the inverse
  of (Laravel's default polymorphic column names — no new columns needed). Re-verified live:
  deleting a zero-entitlement asset now returns `200`, not a crash.
- **`AssetController::index()` (market) extended with `withCount`/`withSum` — not in step 06's
  Files list.** §06.3 explicitly requires "version count with the latest version, lifetime sales,
  revenue" on the listing table; nothing in step 04's `index()` provided it, and no other endpoint
  could. Added `with('latestVersion')`, `withCount(['versions', 'entitlements'])`, and
  `withSum('orderItems as revenue', 'seller_net_amount')` — all read-only aggregates on rows the
  endpoint already returns, not a new resource.
- **`CatalogController.php` created (market)** for the same reason step 05 added an upload-status
  endpoint: stage 1 needs a category list and stage 4 needs live package-name availability, and
  step 04 never built either. `GET /categories?kind=asset|service|both` and
  `GET /package-name-availability?name=…&exclude_asset_ref=…` — the latter is explicitly a UX
  convenience; `AssetPublishGate` remains the sole enforcement (step 01 D6), confirmed by testing
  that a duplicate `package_name` still fails `publish()` even if the availability check were
  bypassed.
- **`es.json` was not touched.** Confirmed before deciding: `en.json` now has 695 keys, `es.json`
  has 253 — a pre-existing, whole-app gap of 450 missing keys (183 of them already
  `market.upload.*`/`assetstore.*` before this step even started, per step 05's own finding).
  Adding only this step's 118 new keys in Spanish would not fix "parity" — 332 other keys would
  remain missing regardless — and `Localizer.t()` already falls back to English silently on a
  missing key, so nothing is functionally broken for a Spanish-locale user. This mirrors step 05's
  identical decision and is flagged again here because the gap has grown, not shrunk.
- **No chunked-upload resume across a page reload.** `ChunkedUploader`'s `alreadyReceived` +
  `GET /uploads/{sessionId}` status endpoint (step 05) both still work and are wired in
  (`AssetStoreController.ts.uploadStatus`/`abandonUpload`), but the wizard does not persist
  `assetRef`/`versionRef`/`sessionId` anywhere across a reload — state is in-memory only, by
  design, since step 06 explicitly deletes this file's only other persistence mechanism
  (`localStorage`). A crash mid-upload means re-entering the wizard on the same draft (stages 1–4
  fields already saved to market — the actual save-per-stage guarantee §06.2 asks for) and
  re-selecting/re-uploading the archive. This is a deliberate scope boundary, not an oversight.
- **The "Set up payouts" button (§06.2 stage 6, `MARKET_E_PAYOUTS_REQUIRED`) is disabled, not
  "working".** Step 10 ("Members: Connect onboarding"), which step 06 only *soft*-depends on, has
  not been built yet — there is no destination route to send the button to. It renders with the
  specific message §06.2 requires ("Set up payouts before selling a paid asset") and a disabled
  button with a tooltip explaining why, rather than linking somewhere that doesn't exist. This
  becomes a real, working button the moment step 10 lands; no wizard changes will be needed beyond
  removing the `disabled` attribute and pointing the `href` at whatever route step 10 defines.
- **Markdown rendering is hand-written, not a library** (`LicenseEditor.renderMarkdownPreview`),
  for the same reason `PackageZipper.ts` hand-writes the zip container: no markdown package exists
  in this project's `package.json`. It supports headings, bold/italic, http(s) links, and
  paragraphs — enough for a license document — and is escape-first so a license body can never
  inject markup into its own preview (tested explicitly in `LicenseEditor.test.ts`).
- **Pricing math is a client-side preview, not the authoritative calculation.** No checkout/order
  step exists yet in this codebase to compare against; `PricingBreakdown.ts`'s header comment states
  this plainly and documents the exact rounding rule chosen (fee floored, tax rounded normally) so a
  future step wiring real checkout has a stated contract to match or explicitly deviate from.

## Known gaps / deferred

- Chunked-upload resume across a browser reload (see above) — deferred, not attempted.
- `es.json` parity — pre-existing, whole-app, out of scope for this step (see above).
- The "Set up payouts" button is inert pending step 10.
- No browser-driven UI verification was possible: members' Laravel app still cannot boot in this
  working tree (`routes/web.php:21` requires a missing `public/checkpoint/server/php/vendor/autoload.php`
  — the same blocker recorded in steps 02/04/05, unrelated to this step's changes and out of scope
  to fix here). Verification leaned on `php -l`, a full `tsc --noEmit`, a full `vitest run`, a full
  production `vite build`, and live HTTP round-trips against market's real internal API using the
  exact request/response shapes the members-side code produces (see below).

## How this was verified

- **PHP syntax**: `php -l` on every touched/created PHP file (members and market) — all clean.
- **TypeScript**: `npx tsc --noEmit -p tsconfig.json --ignoreDeprecations 5.0` (the repo's
  `tsconfig.json` pins an invalid `ignoreDeprecations` value for the installed TS 5.9.3, a
  pre-existing config issue, worked around via CLI flag rather than editing the shared config).
  Diffed against a pre-change baseline run: **zero new errors** introduced by any file this step
  touched or created. The two pre-existing-baseline errors that still mention `market/` are both
  untouched leftovers from step 05 (`ChunkedUploader.test.ts`'s `Uint8Array<ArrayBufferLike>` typing,
  `ads/admarket/builder.ts`).
- **Unit/DOM tests**: `npx vitest run resources/js/market/` → **6 files, 37 passed, 1 pre-existing
  skip, 0 failed** (`FilenameAnalyzer`, `ChunkedUploader`, `PackageZipper` unchanged from step 05;
  `LicenseEditor`, `PricingBreakdown`, `FilenameIssueDialog` new this step, including two genuine
  jsdom tests that drive the real `showFilenameIssueDialog()` overlay end to end — render, read
  `disabled` state, click a real button, resolve the promise).
- **Production build**: `npx vite build` → succeeds, 1002 modules transformed, and
  `AssetPublishWizard-CnxJ3ohO.js` (45.71 kB) appears as its own lazy chunk — confirming the
  dynamic `import()` in `views.ts` actually code-splits as intended rather than bundling the wizard
  into the main chunk.
- **`localStorage` grep** (§06.6's own checklist item): `grep -n "localStorage"
  resources/js/controllers/AssetStoreController.ts` → zero hits (the one place the string would
  otherwise have appeared was reworded in a header comment specifically so the literal grep stays
  clean).
- **Live backend verification**, market side, against a scratch SQLite database (never the shared
  `testdb_afsdb_market`): `.env` swapped to a temp SQLite file + temp asset-storage root +
  `APP_PRODUCTION=false` + `MARKET_ASSET_XSENDFILE=0` (backed up first, restored byte-identical
  after, diff-verified both times), `php artisan migrate` (33+3 migrations, clean), a temp
  `php artisan serve --port=8901`, and a hand-written Python client computing real
  `MarketSignature`-compatible HMAC headers for every call:
  - `GET /categories?kind=asset|service` — correct kind filtering.
  - `GET /package-name-availability` — invalid format, valid+available, valid+taken (with and
    without `exclude_asset_ref`) — all four cases correct.
  - `POST /sellers/{ref}/assets` → draft created, `PUT /assets/{ref}` → `package_name` set.
  - `POST /assets/{ref}/versions` (begin) → `POST .../chunks/0` (raw-body PUT with
    `X-Chunk-Sha256`) → `GET /uploads/{sessionId}` (status) → `POST .../finalize` — full chunked
    pipeline round-trip, version reaches `status: ready`.
  - `POST /sellers/{ref}/media` with a real PHP-GD-generated PNG → `201`, re-encoded to `.webp`,
    `width`/`height` correct (a hand-crafted minimal PNG was tried first and correctly rejected —
    confirms the endpoint is actually validating, not just accepting anything).
  - `GET /sellers/{ref}/licenses`, `POST /sellers/{ref}/licenses` — both correct.
  - **Bug-fix re-verification**: `DELETE /assets/{ref}` on a zero-entitlement asset — `200`
    (previously threw `BadMethodCallException`, reproduced first, then fixed, then re-verified).
  - `GET /sellers/{ref}/assets` — confirmed `versions_count`, `entitlements_count`, and `revenue`
    are present in the response shape the new listing controller expects.
  - `.env` restored and `diff`-confirmed byte-identical to the pre-test backup both times this
    section's scratch environment was used.
- **i18n integrity**: every literal `t('...')` call and `data-i18n="..."` attribute across every
  file this step created or modified was extracted by script and checked against `en.json` —
  **zero missing keys**. `en.json` was rewritten via Python `json.load`/`json.dump`
  (`ensure_ascii=True`) rather than hand-edited, then re-validated with `file` (reports "JSON
  data", not "binary"/"data") and `json.load()` — the same defensive habit step 05's Unicode-escape
  corruption incidents established, applied here even though this step's content was plain ASCII.

## Follow-ups spawned

- Step 10 (Connect onboarding) should, when it lands, wire the disabled "Set up payouts" button in
  stage 6 to a real route and remove its `disabled` attribute — flagged in-line in
  `AssetPublishWizard.ts`'s `publish()` method.
- A future checkout/order-creation step should treat `PricingBreakdown.ts`'s documented rounding
  rule (fee floored off list price, tax rounded normally, inclusive-mode tax is informational only)
  as the client-preview contract to match, or explicitly document why server behavior differs.
- Chunked-upload resume-after-reload, if ever prioritized, would need a small persisted
  `{assetRef, versionRef, sessionId}` — deliberately not added this step (see "Decisions made").
