# 10 — The Preview Endpoint — Complete

## Status

Done for both resources with an engine (`storage_files_size_kb`, `domain_email_accounts`).
`RetentionEngine::preview()` — which existed and computed correctly but had no caller
anywhere (§10.2) — now has three: a member-facing storage route, a member-facing
multi-domain email route, and the signed `www.internal` email route, plus the actual D10
close: `BillingApp.ts`'s plan-change confirm dialog now shows what would retire before the
member confirms.

## Summary

`RetentionEngine::preview()` gained two optional parameters (`$scopeType`, `$scopeKey`) and
now calls `cascadeRetirement()` to populate `cascade` whenever bands actually retire,
instead of always returning `[]`. `RetentionService::previewChange()` and
`EmailRetentionService::previewChange()` gather that engine's inputs from real data — the
member's current plan (fail-closed on a missing membership row, same rule `planFor()`
already uses), tier capacities in the resource's own unit, and every band's current state
via new `bandStatesFor()` methods (all five bands unconditionally, unlike `summaryFor()`,
which only reads bands already in trouble).

Three new routes expose this:

- `GET /api/v1/storage/preview?to_plan=N` (members, browser-auth) — `StorageBucketController::preview()`.
- `GET /api/v1/email/preview?to_plan=N` (members, browser-auth) — new `EmailPreviewController`, aggregating every domain the member is known to hold (from `EmailBandUsage`, the same bootstrapping-gap-aware enumeration `TierChangeController` and `EmailRetentionSweep` already use).
- `POST /api/internal/www/v1/email/preview` (members, signed `www.internal`) — new `EmailReconcileController::preview()`, for whenever `www`'s own code needs the same call; `www`'s `MembersEmailClient::previewEmail()` is its client.

`BillingApp.ts`'s `selectPlan()` now calls both member-facing preview routes before
building the confirm-dialog message, and appends a plain-language warning when either
reports bands retiring — the actual D10 close (`00-overview.md` §5: "the member sees the
consequence before the change, with a real number").

## Files changed

| File | Repo | Change |
|---|---|---|
| `app/Checkpoint/RetentionEngine.php` | members | `preview()` gains `$scopeType`/`$scopeKey`, calls `cascadeRetirement()` when bands retire |
| `app/Checkpoint/RegistryResourcesClient.php` | members | New. Registry-wide `scope` reader (every resource, not one resource's tiers) — `cascadeRetirement()`'s missing input |
| `app/Checkpoint/Storage/RetentionService.php` | members | New `bandStatesFor()`, `previewChange()` |
| `app/Checkpoint/Email/EmailRegistryClient.php` | members | New. Email's own tier reader (accounts, not bytes) — the sibling `RegistryClient` this plan's §10.2 didn't name but `previewChange()`'s headroom math needed |
| `app/Checkpoint/Email/EmailRetentionService.php` | members | New `bandStatesFor()`, `previewChange()`, `accountCountInBand()` |
| `app/Http/Controllers/Storage/StorageBucketController.php` | members | New `preview()` action |
| `app/Http/Controllers/Email/EmailPreviewController.php` | members | New. Browser-facing, multi-domain |
| `app/Http/Controllers/Www/EmailReconcileController.php` | members | New `preview()` action, signed channel |
| `routes/web.php` | members | Two new browser routes |
| `routes/www_internal.php` | members | One new signed route |
| `app/Services/Members/MembersEmailClient.php` | www | New `previewEmail()`, sibling of `reconcileEmail()` |
| `resources/js/storage/ApiClient.ts` | members | New `PreviewResult` interface, `preview()` method |
| `resources/js/email/ApiClient.ts` | members | New file. Thin wrapper over the new email preview route |
| `resources/js/controllers/BillingApp.ts` | members | `Subscription.level` field; `selectPlan()` calls `previewRetentionConsequences()` before confirming |
| `tests/Feature/Storage/StoragePreviewTest.php` | members | New. 5 tests |
| `tests/Feature/Email/EmailPreviewTest.php` | members | New. 3 tests |
| `tests/Feature/Www/EmailReconcileControllerPreviewTest.php` | members | New. 2 tests |
| `tests/Feature/Members/MembersEmailClientPreviewTest.php` | www | New. 3 tests |

No new database table, no new signing mechanism, no engine redesign — matches §10's own
closing line exactly.

## Design decisions not fully spelled out in the plan text

- **A registry-wide resource-scope reader (`RegistryResourcesClient`) had to be built —
  not listed in §10.6's file table.** `cascadeRetirement($retiredScopeType, $retiredScopeKey,
  $registryResources)` needs to see every resource's `scope`, not one resource's tiers.
  The only registry client that existed (`App\Services\Storage\RegistryClient`) is
  deliberately narrow — its own docblock scopes it to `storage_files_size_kb.tiers` and
  its one caller is `QuotaService`. Reusing or widening it would have made a
  storage-specific class implicitly registry-wide; a small sibling class, same cache
  pattern, same served file, keeps that boundary intact. Same reasoning produced
  `EmailRegistryClient` — email's `previewChange()` needs tier *values* (for evacuation
  headroom), and nothing before this needed email's tiers at all (`reconcile()` only ever
  needed `min(4, plan)`).

- **Storage's tier values are converted to bytes via `QuotaService::bucketCapacityBytes()`
  before reaching the engine, not via a second raw `RegistryClient` call.** The registry
  stores storage's tiers in KB; `BucketUsage.used_bytes` is bytes. Passing raw KB values
  into `RetentionEngine::preview()`'s free-space math (which compares `tiers[band]['value']`
  directly against `used`/`reserved`) would have been a silent unit-mismatch bug — the
  same class of bug §10.2's unit note foresaw ("bytes vs KB" is exactly the trap
  `QuotaService`'s own conversion step already exists to avoid elsewhere in this codebase).
  `bucketCapacityBytes()` already does this conversion correctly for `profile()`; reusing
  it here was both the safe choice and the one requiring no new dependency.

- **Cascade-scope lookup degrades gracefully; the tier-registry lookup does not.**
  `previewChange()` on both services catches a `RegistryResourcesClient` failure and
  passes `[]` (cascade comes back `{direct: [], children: []}`, not an error) — cascade is
  supplementary to the number D10 actually needs. A failed `RegistryClient`/
  `EmailRegistryClient` call is allowed to throw, same fail-closed rule storage's own
  quota math already applies (an unreachable registry must never read as "unlimited room
  to evacuate into").

- **OQ-P (cascade recursion depth) and OQ-Q (`messages[]` generation) left open, exactly
  as the plan flagged.** `cascade` is wired and returns the honest, correct answer for
  both shipping resources today (`{direct: [<the resource itself>], children: []}` for
  storage's `member` scope, `{direct: [], children: []}` for email's `per_domain` scope
  until a `per_store` resource is registered) — there is still no real case to test a
  deeper recursion against, per the plan's own note. `messages[]` stays engine-empty;
  `BillingApp.ts` builds its own plain-language text client-side rather than the engine
  hand-writing per-resource copy — this document didn't need to decide between OQ-Q's two
  options to ship, so it didn't.

- **`BillingApp.ts` needed a field it didn't have: the numeric plan a `DirectPaymentTier`
  resolves to.** `initializeSubscriptions()` mapped tier data into a `Subscription` shape
  with no `level` field, even though `laravel_direct_payment_tiers.level` was already
  being fetched in the raw `tier` object and simply dropped. Calling `.../preview?to_plan=N`
  needs that number client-side; added `level` to both the interface and the mapping.

- **A preview failure in `previewRetentionConsequences()` is caught and silently
  skipped, not surfaced as an error.** This is a courtesy warning appended to a confirm
  dialog, not the mechanism that actually enforces retention (that's still server-side,
  unconditional, on the write itself). Blocking a member's plan change because a
  best-effort "here's what you'd lose" call timed out would make the dialog's own
  degrade-safety worse than not showing a warning at all.

## Verification performed

- `php -l` on every new/changed PHP file — clean.
- New test suites (13 tests total across `members` and `www`) — all pass:
  - `StoragePreviewTest` (5): downgrade retires the correct bucket with the correct
    cascade `direct` list, upgrade restores with empty `bands_retired`, a same-plan
    preview skips the cascade lookup entirely (`{direct: [], children: []}` without
    touching the registry-resources cache), missing `to_plan` is 422, unauthenticated is
    401.
  - `EmailPreviewTest` (3): a downgrade reports the retiring band for the member's one
    known domain, a member with no known domains gets `{"domains": {}}` (not a bare
    empty response), missing `to_plan` is 422.
  - `EmailReconcileControllerPreviewTest` (2): the signed route computes the same
    preview, an unsigned request is 401.
  - `MembersEmailClientPreviewTest` (3, `www`): success returns the parsed body, a
    non-2xx and an unconfigured client both return `null` rather than throwing.
- `members`' full test suite (`php artisan test`, no path filter) — 64 passed, 0 failures
  (up from 54 before this pass — the 10 new tests, no regressions in the 54 that already
  existed, including every storage/email retention suite that now depends on the
  widened `RetentionService`/`EmailRetentionService` constructors).
- `www`'s full test suite — 565 passed, 11 failed (up from 562/11 before this pass — the
  3 new tests, same 11 pre-existing `Appointments`/`Builder`/`Ecommerce`/`Events`/`Forms`
  environment-domain failures as plan 09's own verification found, unrelated to this
  change).
- `npx tsc --noEmit` against a temporary copy of `tsconfig.json` with its broken
  `ignoreDeprecations: "6.0"` value removed (the real `tsconfig.json` fails to even start
  under the installed TypeScript 5.9.3 — a pre-existing project issue, not something this
  pass introduced or fixed) — the same ~65 pre-existing errors the unmodified project
  already has appeared, none in any file this pass touched or added
  (`BillingApp.ts`, `storage/ApiClient.ts`, `email/ApiClient.ts`) confirmed clean.
- `npm run build` (Vite) — succeeded, exit 0, `public/build` regenerated with the new
  modules bundled in.
- **Not** exercised in a real browser against a running `members` instance — this
  environment (`DEVELOPMENT_MODE.md` = `vagrant`) has the lab genuinely reachable, but
  clicking through a real plan-change confirm dialog needs a logged-in member session and
  real Direct Payment tier data this pass did not set up. The dialog's new code path is
  proven by unit-level type-checking and the server-side contract it calls being proven
  correct by the feature tests above — not by a live click-through.

## Follow-ups

- **§10.4's aggregate `preview(toPlan)` across all resources** — the `dunning_final`
  email's hand-maintained "what you lose" list — remains unbuilt, exactly as scoped. It
  would today only be able to honestly cover 2 of 35 resources; building it before more
  resources have engines would recreate the exact "confident-looking wrong answer" D10
  exists to prevent.
- **§10.5's OQ-P/OQ-Q** remain open, as above — flagged for whichever resource next
  needs cascade recursion or a decision on `messages[]`'s shape.
- **A live click-through of the updated confirm dialog** is the one piece of this pass
  not verified end-to-end; worth a manual pass in the vagrant lab with a real Direct
  Payment tier and a member holding both storage and email data before calling this
  fully proven in production.
