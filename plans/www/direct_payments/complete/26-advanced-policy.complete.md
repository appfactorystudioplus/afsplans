# 12 — Direct Payments Advanced — Complete

## Status

Done. `DunningPolicyResolver`/`DunningPolicy` (§12.6) were already built in
[08-brain-enforcement.md](../../../billing/complete/08-brain-enforcement.complete.md) —
degraded-but-correct, per `billing/11-rollout-and-testing.md` §11.1's "P6b may land before
or after P6" — and needed **no
changes** here: they already read `laravel_direct_payment_advanced` through
`Schema::hasTable()` against exactly this schema. This step supplies everything else: the
table/model, validation, the write gate, the routes, and the admin UI card.

## Summary

Built `laravel_direct_payment_advanced` (one row per Direct Payment), the
`DirectPaymentAdvanced` model, `AdvancedConfigGate` (the site-owner-level identity check,
verbatim to §12.8's own code sample), `DirectPaymentAdvancedWriteMiddleware` (registered as
the `directpayments.advanced` alias), `DirectPaymentAdvancedController` with `show()`
(unrestricted read, resolved-policy response) and a shared `validateAndSync()` used by both
`store()` and `DirectPaymentsController::saveDirectPayment()`'s `advanced` key. Added the
`#dpAdvancedSection` card to the Direct Payments admin form, gated by `app.window.member.level
== 99` client-side and `AdvancedConfigGate` server-side, following the exact separation §12.7
and §12.8 describe.

## Files changed

### Backend
| File | Contents |
|---|---|
| `www/database/migrations/2026_08_25_000005_create_direct_payment_advanced_table.php` | New. §12.3 |
| `www/database/migrations/2026_08_25_000010_adjust_brain_billing_settings_defaults.php` | New. §12.9's adjust migration — a genuine no-op against this deployment's schema, guarded with `hasColumn()`; see design decisions below |
| `www/app/Models/Externals/DirectPaymentAdvanced.php` | New |
| `www/app/Externals/DirectPayments/Dunning/AdvancedConfigGate.php` | New. §12.8, verbatim to the plan's own code sample |
| `www/app/Http/Middleware/DirectPaymentAdvancedWriteMiddleware.php` | New. The entire write restriction (§12.2 rule 4) |
| `www/bootstrap/app.php` | Registered the `directpayments.advanced` middleware alias |
| `www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentAdvancedController.php` | New. `show()`/`store()`/`validateAndSync()` |
| `www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php` | `saveDirectPayment()` gained the `advanced` key handling — gate check, then `validateAndSync()`, placed AFTER the tier rebuild (see below) |
| `www/routes/portal.php` | `GET /{dpRef}/advanced` (open) and `POST /{dpRef}/advanced` (`directpayments.advanced`-gated), plus the controller import |

### Frontend
| File | Contents |
|---|---|
| `www/resources/js/portal/admin/html/views/directpayments-form.html` | New `#dpAdvancedSection` card — dunning toggle, grace days, reminder days, nonpayment action, downgrade-to-level, restore-on-payment, cancellation policy, mail handler |
| `www/resources/js/portal/admin/controllers/direct_payments/directpayments.ts` | `applyAdvancedVisibility()`, `refreshDowngradeToLevelOptions()`, `loadAdvancedConfig()`, `applyAdvancedMailHandlerOptions()`, the grace-days-under-3 warning, and the `dpObj.advanced` payload assembly (omitted entirely when hidden/not editable) |

## Design decisions not fully spelled out in the plan text

- **The admin form reads via the dedicated `GET /{dpRef}/advanced` endpoint, not an embedded
  field.** `getDirectPayments()`/`saveDirectPayment()` embed `.connect`/`.notifications`
  directly on each DP row, and I considered mirroring that for `.advanced`. Didn't: §12.8's
  `show()` is the only place `editable` comes from, and the form needs that value (not just
  the stored fields) to decide whether to lock the fields it just showed. Embedding would
  either duplicate that resolution logic at the list endpoint too, or leave the UI blind to
  a case where the client's stale `app.window.member.level` disagrees with the server's
  live read. One fetch, one source of truth for both the values and the permission.

- **Advanced-config sync runs AFTER the item/tier rebuild in `saveDirectPayment()`, not
  alongside `syncConnectConfig()`/`syncNotificationConfigs()`.** `validateAndSync()` checks
  `downgrade_to_level` against this DP's *current* tier levels — if it ran before the tier
  rebuild (where connect/notifications sync happens), an operator changing tiers and
  advanced config in the same save would be validated against the tiers about to be
  replaced, not the ones they just submitted. Caught by reasoning through the request
  lifecycle, not by a test.

- **`DirectPaymentAdvancedWriteMiddleware` uses constructor injection for `AdvancedConfigGate`,
  not a `handle()` method parameter.** The first draft wrote
  `handle(Request $request, Closure $next, AdvancedConfigGate $gate = null)`, which is wrong
  for Laravel middleware — the third-plus `handle()` parameters are bound to
  colon-separated string arguments in the route's `middleware:arg1,arg2` syntax, not
  resolved from the container. `$gate` would always have been `null`, immediately null-safe'd
  away with `??=`, hiding the bug rather than surfacing it — but calling `$gate->allows()`
  after `$gate ??= app(...)` still worked, so this would have shipped silently miscoded and
  only accidentally correct. Fixed to constructor injection during self-review before it
  landed.

- **The grace-period warning covers only `grace_days < 3` (§12.5's own explicit rule), not
  a comparison against the processor's actual retry window (§12.5's other, larger warning).**
  The second one needs `13-provider-retry-policy.md`'s `ProviderRetryProfile` to resolve a
  real number from, and that step does not exist yet. Built the warning I have real data
  for; left a comment naming the missing one rather than fabricating a number or silently
  dropping the requirement.

- **`2026_08_25_000010_adjust_brain_billing_settings_defaults.php` is a genuine no-op on
  this deployment.** 08-brain-enforcement.md's own migration
  (`..._000009_create_brain_billing_settings_table.php`) already created
  `brain_billing_settings` with the `default_*` column names directly, anticipating this
  step rather than requiring a rename after the fact — see that migration's own note. This
  migration still exists, per §12.9's file list, guarded with `hasColumn()` so it does the
  right thing on either schema shape.

- **§12.10's literal acceptance grep** (`brain_\|Membership\|member_reference_num` across
  `DirectPaymentAdvanced.php`, `DirectPaymentAdvancedController.php`,
  `DunningPolicyResolver.php` and the migration) **is not empty** — see Verification below
  for exactly why, and why that is not a rule 1–3 violation.

## Verification performed

- `php -l` on every changed/new PHP file — all clean.
- `tsc --noEmit -p tsconfig.json` on the full `www` project — zero errors, both before and
  after the tier-refresh fix described below.
- §12.10's decoupling grep, run for real:
  ```
  grep -rn "brain_\|Membership\|member_reference_num" \
    app/Models/Externals/DirectPaymentAdvanced.php \
    app/Http/Controllers/Externals/DirectPayments/DirectPaymentAdvancedController.php \
    database/migrations/2026_08_25_000005_create_direct_payment_advanced_table.php \
    app/Externals/DirectPayments/Dunning/DunningPolicyResolver.php
  ```
  `DirectPaymentAdvanced.php`, `DirectPaymentAdvancedController.php`, and the migration:
  **zero matches** — the actual table/model/controller trio the decoupling contract is
  about carries no `brain_` reference, no member reference, nothing. `DunningPolicyResolver.php`:
  matches, all either doc comments explaining the rule, or its own step-2 read of
  `brain_billing_settings` — which §12.6 itself specifies as this resolver's job ("the
  deployment default (`brain_billing_settings` on the Brain...)"). Zero matches for
  `Membership`/`member_reference_num` even there. The plan's own acceptance line names
  `DunningPolicyResolver.php` in the same breath as the DP-only files, but that resolver's
  documented job spans both tables by design — the grep as literally written cannot pass for
  any implementation of §12.6, including the plan's own. Recorded here rather than silently
  claimed clean.
- Traced §12.10's other acceptance lines:
  - `GET /{dpRef}/advanced` with no row → `show()`'s second branch resolves
    `deployment_default` (if `brain_billing_settings.enabled`) or `inert`, `editable` from
    the same `AdvancedConfigGate` regardless. ✅
  - `POST /{dpRef}/advanced` reaches the controller only past
    `DirectPaymentAdvancedWriteMiddleware` → `AdvancedConfigGate::allows()` → `403` for any
    level ≠ 99, including a missing `brain_memberships` row entirely (the tenant case —
    `resolveOwnerLevel()` returns null on both the local miss and an unresolved Brain call). ✅
  - `saveDirectPayment()`'s `advanced` key runs the identical gate check before
    `validateAndSync()` — a forged `app.window.member.level = 99` reveals the card
    client-side but the server resolves the SITE OWNER's real level independently, so the
    save still 403s. ✅ (traced, not exercised against a live app — see below)
  - `reminder_days: [3, 20]` with `grace_days: 14` → the per-entry check
    `(int) $day >= $graceDays` catches `20 >= 14` → `DP_E_INVALID_REMINDER_DAYS`. ✅
  - `downgrade_to_level: 3` on a DP with tiers 0–2 → not in `$dpTierLevels` →
    `DP_E_INVALID_DOWNGRADE_LEVEL`. ✅
  - `grace_days: 1` → passes validation (0–365 allows it); the UI's warning fires for
    `< 3` and does not block the save. ✅
- **Caught and fixed during self-review, before any of the above:**
  1. The constructor-injection bug in `DirectPaymentAdvancedWriteMiddleware` (see design
     decisions).
  2. The mail-handler `<select>` race: `loadAdvancedConfig()`'s fetch and the pre-existing
     mail-handler-list fetch are independently timed, and setting `.value` on a `<select>`
     with no matching `<option>` yet silently fails. Fixed with a `pendingAdvancedMailHandlerRef`
     variable applied by `applyAdvancedMailHandlerOptions()`, called from whichever of the
     two fetches resolves last.
  3. The downgrade-to-level `<select>` staying empty for an existing subscription DP:
     `populateSubscriptionTiers()` pre-checks existing tier checkboxes by setting
     `.checked = true` directly, which does not fire `'change'`, so the delegated listener
     that was supposed to refresh the options never did. Fixed by calling
     `refreshDowngradeToLevelOptions()` explicitly once tier population finishes.
- **Not** run against a live server or database — same pre-existing `php artisan` boot gap
  every prior step in this plan has noted (`routes/portal.php` requires
  `public/_portal_/admin/...`, absent from this checkout). The "graduation drill" §12.10
  asks to run "once for real in a scratch branch" was **not** performed for the same reason;
  the three deletions it would require are named precisely instead: remove
  `->middleware('directpayments.advanced')` from the `POST /{dpRef}/advanced` route, remove
  the `$request->has('advanced')` gate block in `saveDirectPayment()`, and delete the
  `canEditAdvanced` check in `directpayments.ts` (§12.2's own "what graduation actually
  costs" list — unchanged by this step, confirmed by inspection of what was actually built).

## Follow-ups for later steps

- The processor-retry-window half of the grace-period warning (§12.5) needs
  `13-provider-retry-policy.md`'s `ProviderRetryProfile` — not built yet.
- No Brain-side read-only panel showing "which policy governs this member and where it came
  from" (§12.7's own "worth doing, not required" note) — not built, as the plan itself frames
  it as optional.
- The graduation drill itself (§12.10) is worth actually running in a scratch branch once
  there is an environment where `php artisan` boots, to confirm the three-deletion cost
  claimed above is complete and nothing else silently depends on the restriction.
