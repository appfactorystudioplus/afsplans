# Step 06 — Members: the asset publishing experience

**On completion:** write `complete/06_members_asset_publishing_complete.md`.
**Depends on:** [04](04_internal_cross_api.md), [05](05_asset_storage_and_upload_pipeline.md).
**Blocks:** 17, 19. Soft-depends on [10](10_members_connect_onboarding.md) for the paid-listing gate.

---

## 06.1 What is being replaced

`members/resources/js/controllers/AssetStoreController.ts` — 467 lines, entirely `localStorage`.
The relevant confession is in its own source:

```ts
// Simulate file upload (in production, this would be actual file upload)
const fileName = fileInput.files?.[0]?.name || '';
```

Everything else in the file — the event delegation, the Bootstrap modal handling, the `i18n?.t(...)`
fallback idiom, the render-to-`innerHTML` table pattern — is **good and stays**. This step keeps the
house style and replaces the data layer and the flow.

The markup in `resources/js/templates/views/assetstore.html` is extended, not rewritten. The view
registration at `views.ts:632–640` and the instantiation at `main.ts:118` are unchanged.

---

## 06.2 From a modal to a wizard

The current UI is one modal with five fields. The brief requires license selection, custom license
authoring, a distribution tag, engine metadata, file analysis, price, tax, and a fee disclosure.
That is not a modal.

New: a six-stage wizard, `members/resources/js/market/AssetPublishWizard.ts`, driven by
`AssetStoreController`. Stage state is held in memory and mirrored to the market **draft** after
each stage — so a browser crash at stage 5 does not lose stages 1–4. This is the reason the draft is
created at stage 1 rather than at submit.

### Stage 1 — Details
Title, short description, long description (markdown), category (from market), tags.
Stage 1 completion calls `POST /sellers/{ref}/assets` and receives `as_…`. Every later stage is a
`PUT` against it.

### Stage 2 — Files
Folder picker + drag-drop → `FilenameAnalyzer` (step 05) → the issue dialog → zip → chunked upload
with a real progress bar → `finalize`. Also collects `version` (semver, defaults `1.0.0`) and a
changelog. Server rejections render through the same dialog.

**The upload button is disabled while any blocking issue exists.** This is the brief's hard
requirement and it belongs in the wizard's stage-gate, not only in the dialog.

### Stage 3 — License
Two choices, presented side by side:

- **Default End User License Agreement** — the seeded `is_system` license from step 03, rendered in
  full in a scrollable panel. Not a link, not a checkbox next to a link. The seller is attaching it
  to something they sell; they should have seen it.
- **My own license** — a markdown editor with live preview. Saved as a `market_licenses` row owned
  by the member, named, and reusable. A dropdown lists their previously saved licenses so the second
  asset takes one click.

An explicit choice is required — there is no implicit default. §04.4's publish gate enforces it
server-side too.

Copy shown beneath both options: *"This license is shown to buyers before purchase and is
permanently attached to every copy sold. Editing it later does not change licenses already
sold."* That is the versioning behaviour from step 03, stated in the place where it matters.

### Stage 4 — Distribution
A two-card radio:

- **Joystick Engine** — *"Installable from the Service Assets Market inside the Joystick Engine
  editor."* Reveals: `package_name` (reverse-DNS, live-validated against
  `^[a-z0-9]+(\.[a-z0-9-]+)+$` and checked for global uniqueness against market),
  `engine_min_version`, and a package-layout hint derived from the manifest already uploaded at
  stage 2 (a package with no `Assets/` folder gets a warning, not a block — the engine's importer
  handles a flat package, it is just unusual).
- **Other** — *"Downloadable from the Market website only. Will not appear in the Joystick Engine."*

Stated plainly on the card, because a seller who picks wrong will not find out until a buyer
complains that the asset never showed up in their editor.

### Stage 5 — Pricing
Price, currency (default from config), tax mode (`inclusive` / `exclusive` / `none`), tax rate.

Live breakdown, recomputed on every keystroke:

```
  List price                        $ 40.00
  Tax (exclusive, 8.25%)            $  3.30
  ─────────────────────────────────────────
  Buyer pays                        $ 43.30

  Platform fee (10% of list price)  $  4.00
  ─────────────────────────────────────────
  You receive                       $ 36.00
```

Below it, unmissable — a bordered callout, not fine print:

> **Appfactory Studio retains 10% of each purchase.** On a $40.00 sale you receive $36.00. Tax is
> collected on your behalf and is not part of the platform fee.

Tax is excluded from the fee base and this is shown, because the alternative — charging a
marketplace fee on sales tax — is both wrong and the kind of thing a seller discovers later and does
not forgive.

Setting price to `0` switches the panel to a "Free asset — no platform fee applies" state and skips
the Connect gate.

### Stage 6 — Review and publish
Read-only summary of all five stages with an Edit link each; cover image + gallery upload; then
**Publish**.

Publish calls `POST /assets/{ref}/publish`. A `422` renders **all** gate failures at once, each
deep-linked to the stage that fixes it. If the failure is `charges_enabled = false`, the message
is specific — *"Set up payouts before selling a paid asset"* with a button into step 10's onboarding
— never a generic validation error.

---

## 06.3 Listing management

The existing assets table gains: status badge (draft / pending review / published / unpublished /
rejected), distribution-tag chip, version count with the latest version, lifetime sales, revenue,
and per-row actions Edit / New version / Unpublish / View on Market.

- **New version** re-enters the wizard at stage 2 with stages 1, 3, 4 pre-filled and locked to the
  existing asset. Price and license can change; `package_name` cannot — it is the engine's identity
  for the package and changing it orphans every installed copy.
- **Unpublish** shows the entitlement count first: *"14 buyers own this asset. They keep access and
  can re-download. It will no longer appear on the Market."*
- **Delete** is offered only when the entitlement count is zero, matching the `409` in §04.4.

Header cards (balance, total sales, total assets) come from `GET /sellers/{ref}/dashboard`, not
`localStorage`.

---

## 06.4 Files

> **Tagging (step 01 §01.4) — every file below.** Created files carry
> `MARKET_MODIFIER_ADDITION` in their top doc block; modified files carry it at each changed line or
> region. `grep -rn MARKET_MODIFIER_ADDITION` must return this list in full, or the change index is
> already wrong.


**Created** (all tagged in their header):
`members/resources/js/market/FilenameAnalyzer.ts`,
`members/resources/js/market/FilenameIssueDialog.ts`,
`members/resources/js/market/PackageZipper.ts`,
`members/resources/js/market/ChunkedUploader.ts`,
`members/resources/js/market/MarketApi.ts` (typed client for the proxy routes),
`members/resources/js/market/AssetPublishWizard.ts`,
`members/resources/js/market/LicenseEditor.ts`,
`members/resources/js/market/PricingBreakdown.ts`,
`members/resources/js/templates/views/asset_publish.html`.

**Modified** (line-tagged):
`members/resources/js/controllers/AssetStoreController.ts` (data layer swapped, `localStorage`
methods deleted),
`members/resources/js/templates/views/assetstore.html`,
`members/resources/js/views.ts` (register the wizard view),
`members/resources/js/i18n/en.json` (+ `es.json` if maintained — key parity),
`members/app/Http/Controllers/AssetStoreController.php` (proxy per §04.6),
`members/routes/web.php:88–99` (handlers change, paths do not).

---

## 06.5 Accessibility and error behaviour

- Every `alert()` in the asset-store path is replaced. The existing code uses `alert()` for success,
  validation, and errors alike; the wizard uses inline field errors, a toast for success, and the
  issue dialog for file problems.
- Wizard stages are keyboard-navigable; the stage indicator is an `aria-current` list.
- Upload progress is announced via `aria-live="polite"`, throttled to ~1/sec so a screen reader is
  not flooded.
- Market unreachable → the wizard disables Continue and shows *"The Market is unavailable. Your
  draft is saved and you can resume."* — true only because of the save-per-stage decision in §06.2.

---

## 06.6 Verification

- [ ] `grep -n "localStorage" members/resources/js/controllers/AssetStoreController.ts` → zero hits.
- [ ] Publish an asset end to end as `m1xTHpRmdtH2Y`; the row exists in market's `assets` with the correct `seller_reference_num`, `distribution_tag`, `license_id`, and a `ready` version.
- [ ] The archive exists at `/mnt/node2/market/members/m1xTHpRmdtH2Y/assets/{ref}/versions/1.0.0/package.zip` with a matching sha256.
- [ ] Attempt to publish with no license, no tag, and no version: one response, three errors, each linking to its stage.
- [ ] The 10% disclosure is visible without scrolling on a 1280×800 viewport at stage 5.
- [ ] Publishing a $10 asset with `charges_enabled = false` is refused with the payout-specific message and a working button.
- [ ] Publish a second version; the storefront shows 1.0.1, and a buyer holding 1.0.0 still resolves a download for it.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/06_members_asset_publishing_complete.md` written.
