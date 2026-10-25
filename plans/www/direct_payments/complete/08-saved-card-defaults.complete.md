# 08-saved-card-defaults — completion record

**Plan file:** [`../08-saved-card-defaults.md`](../08-saved-card-defaults.md)
**Repo:** `w/www`
**Date:** 2026-09-16
**Status:** ✅ Shipped in full, exactly per plan. Migrated against the real local MySQL database
(`testdb_appfactorystudio`), not just sqlite.

Found auditing [connections/10-api-contracts.md](../../connections/10-api-contracts.md); planned
and shipped here rather than there — the feature is Direct Payments/Processor domain (this
service, this model, this admin screen), not a connections concern, whatever surfaced the gap.

## What shipped

**`laravel_direct_payment_default_methods`** — one row per (processor, provider customer),
pointing at the provider's own method id. Never card data. New `DirectPaymentDefaultMethod`
model.

**`app/Models/Externals/Processor/Processor.php`** — `defaultMethodOnAdd(): 'auto'|'manual'` (default
`'manual'`) and `defaultMethodOnDelete(): 'auto_promote'|'none'` (default `'auto_promote'`),
reading `extra_config['saved_card_default_on_add']`/`['saved_card_default_on_delete']`.

**`ProcessorPaymentMethodService`** — three new private methods, each deliberately curl-free
(operates on already-fetched data or a local row, never makes its own provider call):

- `attachDefaultFlag()` — marks the stored default among a method list; clears a stale pointer
  (card removed directly at the provider) rather than reporting it; a lone remaining method is
  trivially default with no row needed.
- `maybeSetDefaultOnAdd()` — sets a new method as default only on `'auto'` AND when nothing is
  currently set; never steals default status from an existing choice.
- `handleDefaultOnDelete()` — on `'auto_promote'`, re-fetches (via the same `list()` the cache
  already keeps warm) and promotes the first remaining method; on `'none'`, just clears.

Wired in: `list()` (outside the cache closure, so it's always current), `store()` (using
Square's real returned card id, not the source nonce passed in — a real distinction found while
wiring this: Square's `source_id` and the card's actual stored id are different values),
`delete()`, `setDefault()` (now writes the local row for all three providers; the pre-existing
Stripe-only API call is kept, purely cosmetic), and `confirmPayPalVaultSetup()` (PayPal never
goes through `store()` — this exchange step is the only place that ever learns the new vault
token's real id, so it needed its own `maybeSetDefaultOnAdd()` call, not named as a separate
wiring point in the plan text but required by the same logic).

**Admin UI** — two new fields in the existing per-processor settings screen
(`processor-details.html` + `ProcessorDetailView.ts`), provider-agnostic, defaulting to "payer
sets it explicitly" / "auto-promote another card." **The `extra_config`-wipe bug fix these
depend on** also shipped: `buildSettingsPayload()` previously rebuilt `extra_config` from `{}`
on every save, silently dropping any key the active provider's own branch didn't repopulate
(`extra_config.connect`, marketplace data, included) — now seeded from the currently-loaded
processor's own config first.

**Dashboard UI** — `buildCardsMarkup()` (`direct_payments/ui.ts`, the connections-08-built
Dashboard section — still Direct Payments' own file, just rendered inside the Site Users shell)
now shows a "Default" badge on the flagged row and hides its (redundant) "Make default" button,
closing the actual user-facing gap this whole plan started from: no card ever visually showed
as the default, for any provider.

**SDK** — `PortalDirectPaymentsSavedMethod.is_default?: boolean` in `portal.d.ts`.

## Files

**New:** `database/migrations/2026_09_16_000000_create_direct_payment_default_methods_table.php`,
`app/Models/Externals/DirectPaymentDefaultMethod.php`,
`tests/Feature/Processor/ProcessorPaymentMethodServiceDefaultTest.php`

**Modified:** `app/Models/Externals/Processor/Processor.php`,
`app/Externals/Processor/ProcessorPaymentMethodService.php`,
`resources/js/portal/admin/html/views/processor-details.html`,
`resources/js/portal/admin/controllers/processors/ProcessorDetailView.ts`,
`resources/js/external/ui/direct_payments/ui.ts`, `resources/js/external/api/portal.d.ts`,
`resources/js/external/ui/tests/direct_payments.test.ts`

## Deviation from the plan text

- **Doc location**: shipped as `direct_payments/08-saved-card-defaults.md`, not
  `connections/14-saved-card-defaults.md` as originally written and first committed. The feature
  is Direct Payments/Processor domain code end to end; only the audit that found the gap
  happened in `connections/10`. Moved (plan text, completion record, every in-code
  `AFS_BILLING_MODIFIER_ADDITION` doc reference, both folders' `README.md`) rather than left
  cross-referenced from the wrong folder.
- **Test location**: `tests/Feature/Processor/ProcessorPaymentMethodServiceDefaultTest.php`, not
  the plan's own suggested `tests/Unit/Processor/...` — matches the existing sibling
  `tests/Feature/Processor/ProcessorPaymentMethodSecurityTest.php`, the closest real precedent
  for testing this exact service, found while writing it rather than assumed from the plan.
- **A real MySQL identifier-length hit, found running the migration for real, not caught by
  sqlite**: the plan's own illustrative `->index()` call on `processor_reference_num` produced
  an auto-generated name over MySQL's 64-character cap. Explicitly naming it revealed a second,
  smaller finding: MySQL doesn't materialize a redundant single-column index when the same
  leading column is already covered by the table's own composite unique index — so the column
  now carries no index declaration of its own, documented in the migration rather than left as
  a silently-dead statement.
- **`confirmPayPalVaultSetup()`'s `maybeSetDefaultOnAdd()` call** — the plan's §8.3 named
  `store()` as the on-add wiring point; PayPal never reaches `store()` at all (its own comment:
  "PayPal vaulting is handled via the frontend SDK"), so the equivalent call had to go in its
  actual add-a-card completion step instead. Same logic, correct wiring point, not explicitly
  spelled out in the plan text.
- **The Dashboard markup change** (`buildCardsMarkup()`'s badge/button) wasn't named as its own
  line item in §8.3–§8.5, only implied by the acceptance criteria in §8.6. Built anyway — the
  SDK type change alone would have left the feature invisible, which was the original problem
  this whole plan exists to fix.

## Verification

```bash
cd w/www
php artisan migrate --force
# 2026_09_16_000000_create_direct_payment_default_methods_table — ran clean against the real
# local MySQL database after the index-name fix; DESCRIBE + SHOW CREATE TABLE both confirmed.

php artisan test tests/Feature/Processor/ tests/Feature/SiteUsers/ tests/Feature/DirectPayments/
# 284 passed

php artisan test
# 477 passed, 3 pre-existing unrelated failures (the scaffold ExampleTest, a builder
# module-refs drift test, a missing forms file — none related to this work)

npx tsc --noEmit -p resources/js/external/api/tsconfig.json      # exit 0
npx tsc --noEmit -p resources/js/external/ui/tsconfig.json       # exit 0
npx tsc --noEmit -p tsconfig.json                                 # exit 0 (root, covers portal/admin)
npm run build-external-webpack                                    # exit 0
npm run build-external-ui-webpack                                 # exit 0
npm run build                                                     # exit 0 (root vite, 2m23s)
npm run test:external-ui
# 81 passed (78 prior + 3 new buildCardsMarkup() tests)
```

## Residue

- **No live Stripe/Square/PayPal sandbox verification** — same disclosed limit every "add a
  card" piece of work in this codebase carries (connections/08's own completion doc). The new
  logic is deliberately curl-free and directly tested as such; the curl-dependent branches it's
  wired into (`storeStripe()`, `storeSquare()`, the PayPal vault exchange) were already
  unverified live before this pass and remain so.
- **No admin-UI screenshot/live-click verification** of the two new processor settings fields —
  `tsc` + the root build both pass, but nobody has opened the actual admin screen and saved a
  processor with these fields set.
