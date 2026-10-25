# 08 — Brain Enforcement — Complete

## Status

Done. `billing:enforce-membership` is scheduled in `members/routes/console.php` and run on the Brain
(see [`08-enforcement-in-members.complete.md`](08-enforcement-in-members.complete.md)); it is BRAIN ONLY via
`config('brain.is_brain')`, which replaced the original `BrainGuard`.
`DunningPolicyResolver`/`DunningPolicy` — nominally step 12's file list (§8.6 →
[15](../../www/direct_payments/26-advanced-policy.md) §12.6) — were built here too, in the
degraded-but-correct shape 11-rollout-and-testing.md §11.1 requires: "P6b may land before
or after P6 — until P6 exists nothing reads the policy, and once it does the resolver's
inert fallback keeps an unconfigured DP harmless." Step 1 of the resolver
(`laravel_direct_payment_advanced`) is `Schema::hasTable()`-guarded and reads through the
query builder rather than an Eloquent model, since that table, its model, its validation
and its admin UI remain step 12's own work — not built here.

## Summary

Built the full ladder: `BrainGuard` (§8.2, verbatim to the plan's own code sample except
routed through `config('billing.enforcement_enabled')` instead of a bare `env()` call, for
consistency with every other setting in `config/billing.php` and safety under
`config:cache`), the three migrations/models (§8.5/§8.6), `MembershipTierResolver` (§8.3's
`tier_paid` resolution), `DunningPolicyResolver`/`DunningPolicy` (§12.6, built ahead of
schedule as explained above), `DunningService` (§8.4's state machine plus §8.4.2's
cancellation sweep), and the `BillingEnforceMembership` command tying it together with
`--dry-run`/`--member=`/`--explain` and the §8.10 summary line.

## Files changed

### Migrations
| File | Contents |
|---|---|
| `www/database/migrations/2026_08_25_000007_create_brain_membership_billing_state_table.php` | New. §8.5 |
| `www/database/migrations/2026_08_25_000008_create_brain_membership_events_table.php` | New. §8.5's audit log |
| `www/database/migrations/2026_08_25_000009_create_brain_billing_settings_table.php` | New. §8.6, seeded with one row (`enabled = false`), columns already named `default_*` — see the "column naming" decision below |

### Models
| File | Contents |
|---|---|
| `www/app/Models/BrainMembershipBillingState.php` | New |
| `www/app/Models/BrainMembershipEvent.php` | New. `UPDATED_AT = null` — append-only |
| `www/app/Models/BrainBillingSettings.php` | New. Singleton — `::first()`, never `::create()` |
| `www/app/Models/Membership.php` | Added `billingState(): HasOne` — no existing marker on this file, so `AFS_BILLING_MODIFIER_ADDITION` on its own |

### Services
| File | Contents |
|---|---|
| `www/app/Services/Billing/BrainGuard.php` | New. §8.2 — since deleted; the check is `config('brain.is_brain')` in `www/config/brain.php` |
| `www/app/Externals/DirectPayments/Dunning/DunningPolicy.php` | New. Readonly value object, 12 §12.6 |
| `www/app/Externals/DirectPayments/Dunning/DunningPolicyResolver.php` | New. 12 §12.6, built here for P6/P6b independence (see Status) |
| `www/app/Services/Billing/MembershipTierResolver.php` | New. §8.3 — `tierPaid()`/`governingSubscription()`/`governingLink()` |
| `www/app/Services/Billing/DunningService.php` | New. §8.4's ladder + §8.4.2's cancellation sweep |
| `www/app/Services/Billing/Exceptions/DryRunRollback.php` | New. Forces the dry-run transaction to roll back — see below |

### Command / config
| File | Change |
|---|---|
| `www/app/Console/Commands/BillingEnforceMembership.php` | New |
| `www/config/billing.php` | Added `enforcement_enabled` (the `.env` half of §8.2's kill switch) |
| `www/routes/console.php` | Already registered `Schedule::command('billing:enforce-membership')` from an earlier step — no change needed |

## Design decisions not fully spelled out in the plan text

- **`config()` instead of the plan's inline `env()` in `BrainGuard`.** The plan's own code
  sample calls `env('BILLING_ENFORCEMENT_ENABLED', true)` directly inside the guard; every
  other setting in this billing plan (`sync_limit_per_run`, `sends_per_run`, etc.) is read
  through `config('billing.*')`, with the `env()` call confined to `config/billing.php`.
  Followed the codebase's own established pattern instead of the plan's literal sample —
  `env()` outside `config/` breaks under `config:cache`.

- **`DunningPolicyResolver`/`DunningPolicy` built here, not deferred to step 12.**
  11-rollout-and-testing.md §11.1 explicitly allows P6 (this step) to ship before P6b (step
  12), with "the resolver's inert fallback" as the mechanism that keeps that safe — which
  only works if the resolver already exists when P6 ships. Built exactly the shape 12 §12.6
  specifies (resolution order, `DunningPolicy`'s fields, the caching note), reading
  `laravel_direct_payment_advanced` through `Schema::hasTable()` + the query builder rather
  than an Eloquent model. **Not built here:** `DirectPaymentAdvanced` model, its migration,
  validation, `AdvancedConfigGate`, the admin routes/UI — all still step 12's file list.
  Until 12 ships, every DP resolves through step 2 (`brain_billing_settings`) or step 3
  (inert); once 12 ships, this resolver starts reading real per-DP rows with no change to
  this file.

- **`brain_billing_settings` created with `default_*` column names from the start**, rather
  than bare (`grace_days`/`reminder_days`/`downgrade_to_tier`) and renamed later by 12
  §12.9's adjust migration. §8.6's own table already documents the `default_*` names as the
  intended shape; creating them directly means 12's adjust migration (guarded with
  `hasColumn()`, per its own text) finds nothing to do and simply no-ops, regardless of
  which of P6/P6b lands first.

- **Rule 3's "active or trialing" reading.** §8.3 rule 3 says only rows whose joined
  processor status is `active`/`trialing` count toward `tier_paid`. Read literally, a member
  the instant they go `past_due` would have an unresolvable `tier_paid` — and §8.7's own
  loop skips a member with unresolved `tier_paid` *before* ever calling `DunningService`,
  which would make it impossible for the ladder to ever observe a `past_due` transition.
  `MembershipTierResolver` therefore treats `past_due` as a third governing status alongside
  `active`/`trialing` (documented at the top of that file) — the subscription has not been
  cancelled, so it still reflects what the member paid for, just at risk. `canceled` and
  `incomplete` remain excluded.

- **`--dry-run` via a rolled-back transaction, not a parallel read path.** Unlike
  `direct_payments:subscription-notify`'s dry-run (which builds a separate preview table),
  `enforce-membership`'s ladder has too much branching state (which rung a member is on,
  whether a reminder is already sent, whether the target equals the current tier) to safely
  duplicate into a read-only shadow evaluation without the two paths drifting. Instead the
  whole run — ladder loop plus cancellation sweep — executes inside one `DB::transaction()`
  that a deliberately-thrown `DryRunRollback` (caught immediately by the command) forces to
  roll back at the end. Outbound mail is skipped outright when `$dryRun` is true regardless
  of the transaction, since a sent email cannot be rolled back.

- **A `downgraded` member whose subscription still reports `past_due`.** Caught during
  self-review: the first draft of `DunningService::advancePastDue()` restarted the ladder
  from T+0 whenever `$state->state !== 'past_due'`, which incorrectly included the
  `downgraded` state — a member already at the floor, whose provider subscription simply
  never recovered or was never cancelled, would get a fresh `dunning_started` email and a
  new grace period on every tick forever. Fixed with an explicit early return for
  `state === 'downgraded'`: nothing happens until the subscription genuinely returns to
  `active`/`trialing` and `restore()` fires.

- **`nonpayment_action = 'none'` vs. "target equals current tier".** §8.4.1 gives these two
  cases different endings — `'none'` "notifications still go out; the tier is untouched",
  while an equal target is "nothing, and no event row". Implemented as genuinely distinct:
  `'none'` still writes a `membership_downgraded` event (`tier_before === tier_after`,
  documenting *why* nothing changed) and still emails; an already-at-target member gets
  neither. The ladder still marks state `downgraded` in both cases so neither re-evaluates
  every 15 minutes forever.

- **One mail handler, many ladder steps.** §12.3 gives each Direct Payment exactly one
  `dunning_mail_handler_reference_num` — not one per event, unlike
  `laravel_direct_payment_notifications`. Every ladder email (`dunning_started` through
  `membership_downgraded`/`membership_restored`) goes through that same handler; the emitted
  vars (`days_until_downgrade`, `downgrade_date`, `current_tier_label`, `target_tier_label`)
  are what let one template read differently at each rung.

- **Cancellation sweep ignores `nonpayment_action`, respects `dunning_enabled`.** §8.4.2
  reuses the ladder's `tier_before_downgrade`/restore machinery but is explicitly "not the
  same mechanism" as dunning. Implemented so an inert policy (DP not configured, or
  `brain_billing_settings.enabled = false`) suppresses cancellation handling too — consistent
  with "an unresolvable policy is inert" everywhere else — but does not check
  `nonpayment_action`, since that field's `'none'` value is documented as a **non-payment**
  behaviour and §8.4.2's own pseudocode drops straight "to the policy target" unconditionally.

## Verification performed

- `php -l` on every new/changed file — all clean.
- Manually traced §8.11's acceptance list against the code:
  - Non-Brain deployment → `BrainGuard::isBrain()` false on the domain/subdomain check →
    `self::SUCCESS` before any query. `--explain` names the specific failing check
    (`reason()` re-derives each one in order). ✅
  - `type.txt` deleted → `GetManagedDomain()`'s `access` key is false → `reason()`'s second
    check fires first, reporting exactly that. ✅
  - First observed `past_due` → `advancePastDue()`'s T+0 branch sets `state='past_due'`,
    `past_due_since`/`downgrade_after`, and returns without touching `member->membership`. ✅
  - 14 days later, still `past_due` → grace-elapsed branch fires exactly once
    (`downgrade()`), moving state to `downgraded`; the next tick hits the
    `state === 'downgraded'` early return — a no-op. ✅
  - Paying at T+13 → subscription reads `active`/`trialing`, state is `past_due` →
    `restore()` fires within that tick, writes `membership`, logs `membership_restored`. ✅
  - Unmappable `plan_id` → `MembershipTierResolver::tierPaid()` returns null →
    `recordSkip()` (counted in the run's `Skipped` line), `member->membership` never
    touched. ✅
  - 96 stable-population runs → healthy members hit the "already current" no-op branch,
    already-downgraded-but-still-past_due members hit the new early return, both save mirror
    fields only and log no event. `recordSkip()` also de-duplicates by reason so a
    permanently broken mapping logs one `evaluation_skipped` row, not one per tick. ✅ by
    construction.
- **Not** run against a live database or a real `schedule:run` tick — `php artisan` cannot
  boot at all in this checkout (`routes/portal.php` requires
  `public/_portal_/admin/core/php/includes/functions.php`, absent here) — the same
  pre-existing environment gap steps 04/05/06 already noted, confirmed again here.

## Follow-ups for later steps

- Step 12 (`laravel_direct_payment_advanced`, the `DirectPaymentAdvanced` model,
  `AdvancedConfigGate`, the admin card) is unchanged by this step and remains fully
  outstanding. Once it ships, `DunningPolicyResolver::resolveFromAdvancedTable()` starts
  returning real per-DP policies with no code change here — only new data.
- No `--restore` admin flag was added, even though
  11-rollout-and-testing.md §11.1's rollback row for P6 mentions one ("an admin path worth
  building alongside P6") — §8.1's own signature only lists `--dry-run`/`--member=`/`--explain`,
  and the normal restore path (subscription returns to `active`/`trialing`) already covers
  the documented acceptance case. Worth its own follow-up if an operator needs to force a
  restore independent of subscription state.
- §8.10's summary line is extended with a third "Cancellations processed/Transitioned" line
  not in the plan's literal sample output — §8.4.2's sweep needed *some* visibility and the
  plan doesn't specify a separate format for it.
