# 02 — Database and Server — Complete

## Status

BM-P1 and BM-P4 done, and BM-P6 (drop `customer_id`) done **in the same pass**, per direct
instruction: this environment has no live production data, so the phased
add→dual-write→backfill→drop sequence the plan specifies (§2.2, §4.1) buys nothing here — there
is nothing a transition window protects. `customer_id` is gone from the schema outright.
BM-P5 (a live backfill command) was built, then **deleted** once BM-P6 made it dead code with
nothing left to backfill from — the two seed datasets this migration ships against were hand-
corrected instead (see "Seed data" below). BM-P0's own stated prerequisite (direct_payments/05's
SDK payer-resolver interface) does not exist and is not built here — see "Deviation from the
plan," which is why the design differs from §2.3's literal pseudocode regardless of the backfill
question. BM-P2/BM-P3 (the `resources/js/afs/` SDK cutover) are **not built** — see Follow-ups.
A 2026-09-23 follow-on additionally removed `member_reference_num` from the two Direct-Payments-
owned tables it was living on (never actually tenant-generic) — see "Follow-on 2026-09-23" below.

## Summary

`brain_memberships.customer_id` is dropped and replaced by `payer_reference_num` (`char(32)`,
nullable, unique) in one migration. `MembershipController::store()` resolves a payer
server-side — through the exact `PayerService::resolveForCheckout()` call ecommerce/events/
appointments already use for the same problem — instead of trusting a raw provider `customer_id`
the client asserts. The admin `update()` endpoint can no longer touch the identity column. Every
other codebase reference to `brain_memberships.customer_id` (two fallback member-resolution call
sites) was re-derived through the payer model instead. The two seed datasets under
`databases/data-only/v2/` and `databases/production/` were edited by hand to carry
`payer_reference_num` (plus the `laravel_direct_payment_payers`/`_payer_customers` rows that
back it) instead of `customer_id`, so a fresh migrate + import lands in the end state directly.

## Deviation from the plan text — read this before touching `resources/js/afs/`

§2.3's code sketch has the client send `payer_reference_num` (read from a new SDK method,
`getStoredPayerReferenceNum()`) and the server validate it against Direct Payments before
trusting it. That design's own hard prerequisite — BM-P0, "direct_payments/05's SDK
`DirectPaymentsPayerResolver` shape" — **does not exist in this codebase**, confirmed directly
in [`../../www/direct_payments/complete/05-implementer-contract.complete.md`](../../www/direct_payments/complete/05-implementer-contract.complete.md):
*"The TypeScript SDK payer-resolver interface (§5.3)... deliberately not built... a substantial,
separate-domain undertaking."* The same document also confirms `getStoredCustomerId()` — the
method BM2/§3.1 says to remove "SDK-wide" — is **not** AFS-specific; it's called from inside
`api.ts`'s `setupSubscriptionPayment()` (line ~1752), the generic subscription-checkout flow
every tenant's module shares. Removing it would have broken subscription checkout platform-wide,
not just AFS's signup. Nothing in `resources/js/afs/` was touched; it still POSTs a `customer_id`
field exactly as before, and the server now reads that field once (to link the already-created
provider customer) and stores it nowhere.

What that same completion doc also shows is the actual, already-proven way to solve BM1 without
either of those: ecommerce, events and appointments all resolve a payer **server-side**, from an
email they already have, at the point they already know the buyer's identity — never through a
client-side resolver. This implementation does exactly that for `brain_memberships`:

- No SDK changes. No `getStoredPayerReferenceNum()`, no removal of `getStoredCustomerId()` or
  `updateCustomerEmail()`.
- No client trust to validate. `resolveAndValidatePayer()`/`belongsToDirectPayment()` (§2.3.1)
  were never built — there's no client-supplied reference to validate, because the server never
  asks the client for one. `resolveForCheckout()`'s own `website_reference_num` scoping supplies
  the equivalent safety property for free: a payer resolved for AFS's sentinel scope can never
  be confused with, or collide with, any tenant's own payer (proven by
  `BrainMembershipPayerResolutionTest::test_two_afs_signups_never_collide_with_a_tenants_payer_of_the_same_email`).
- `PayerService::resolveForCheckout()` requires a `website_reference_num`, and its own docblock
  says AFS's Direct Payment has none — "not a case this plan defines behaviour for yet." A fixed
  sentinel scope, `Membership::AFS_MEMBERSHIP_PAYER_SCOPE`, stands in for it.

## Seed data — the fresh-import path instead of a live backfill

Per direct instruction: no live backfill command, no phased column drop. Both data-only dumps
this environment imports were corrected directly instead:

- **`databases/production/testdb_appfactorystudio.data-only.sql`** — one `brain_memberships`
  row (the platform admin account), `customer_id` already `NULL`. Column renamed in the INSERT's
  own column list; no data implication.
- **`databases/data-only/v2/testdb_appfactorystudio.data-only.sql`** — three `brain_memberships`
  rows: the admin (as above) and the two real lab test members, `james1`/`james2`, each with a
  real `customer_id` (`cus_…`) against processor `2uDJGCr` (Stripe, sandbox). For these two, the
  fix is the full equivalent of what a live backfill would have produced, computed once and
  written directly as SQL: a `laravel_direct_payment_payers` row (scoped to
  `Membership::AFS_MEMBERSHIP_PAYER_SCOPE`) and a `laravel_direct_payment_payer_customers` row
  recording their existing `cus_…` id against it, then `brain_memberships.payer_reference_num`
  set to match. `james1` → `pay_a1b2c3d4e5f6`; `james2` → `pay_g7h8i9j0k1l2`. Nothing else in
  either file changed.

This is why **BM-P5's backfill command was built, tested, then deleted** rather than left in the
tree: once `customer_id` is dropped in the same migration, there is no column left for a backfill
command to read `WHERE customer_id IS NOT NULL` against. Keeping a command that queries a column
that no longer exists would itself have been the kind of obsolete artifact this pass exists to
remove. If a real (non-fresh) deployment ever needs this migration applied to a database with
live, unmigrated `customer_id` data, the backfill logic is not lost — it is exactly
`PayerService::resolveForCheckout()` (by email, scoped to the AFS sentinel) followed by
`recordExistingCustomer()`, the same two calls `store()` now makes per signup; rebuilding the
command is a small, mechanical task, not a design question.

## Other codebase references to `brain_memberships.customer_id` — found and fixed

Two more call sites did the identical fallback lookup (`Membership::where('customer_id',
$providerCustomerId)->value('reference_num')`), found by grepping the whole `app/` tree rather
than assuming `MembershipController` was the only reader:

- `App\Console\Commands\BillingBackfillDpSubscriptions::resolveMemberReferenceNum()`
- `App\Http\Controllers\Externals\DirectPayments\DirectPaymentSubscriptionController::resolveMemberReferenceNum()`

Both re-derive the same answer through one extra hop: provider customer id →
`DirectPaymentPayerCustomer.provider_customer_id` → `payer_reference_num` →
`Membership.payer_reference_num` → `reference_num`. Everything else `customer_id` matched in a
tree-wide grep (`MoneyController`, `ProcessorBillingController`, `ProcessorWebhookController`,
`NotificationDispatcher`) is a *different* `customer_id` column — `ProcessorCharge`/
`DirectPaymentInvoice`/`ProcessorSubscription`'s own FK to `ProcessorCustomer.id` — confirmed
unrelated and left untouched.

## Files changed

| File | Repo | Change |
|---|---|---|
| `database/migrations/2026_09_22_000002_add_payer_reference_num_to_brain_memberships.php` | www | New. Adds `payer_reference_num`, drops `customer_id` — both in one migration, both `Schema::hasColumn()`-guarded |
| `app/Models/Membership.php` | www | `customer_id` removed from `$fillable`, `payer_reference_num` added; new `public const AFS_MEMBERSHIP_PAYER_SCOPE` |
| `app/Http/Controllers/Portal/Admin/MembershipController.php` | www | `store()`: resolves a payer via `PayerService::resolveForCheckout()`, records the existing provider customer via `recordExistingCustomer()`, writes only `payer_reference_num`, propagates email via `updateEmail()` (BI2). `update()`: `customer_id` dropped from `$allowed` (BM4) |
| `app/Console/Commands/BillingBackfillDpSubscriptions.php` | www | `resolveMemberReferenceNum()` re-derived through the payer model |
| `app/Http/Controllers/Externals/DirectPayments/DirectPaymentSubscriptionController.php` | www | Same fix, same method name, this controller's own fallback resolver |
| `databases/production/testdb_appfactorystudio.data-only.sql` | — | `brain_memberships` column renamed (no data change; already `NULL`) |
| `databases/data-only/v2/testdb_appfactorystudio.data-only.sql` | — | `brain_memberships` column renamed + populated for `james1`/`james2`; 4 new `laravel_direct_payment_payer[_customers]` rows backing them |
| `tests/Feature/Membership/BrainMembershipPayerResolutionTest.php` | www | New. 4 tests proving the resolution sequence in isolation (see Verification) |
| `tests/Feature/Membership/MembershipUpdateIgnoresIdentityFieldsTest.php` | www | New. 1 test, real HTTP, proves BM4 |

## Two real bugs a since-deleted test caught before this shipped

Worth keeping on record even though the command they were written against is gone: while
`BrainMembershipBackfillPayers` briefly existed, its own test suite caught (1) a `--dry-run` that
wasn't actually read-only, because `resolveForCheckout()` creates a payer as a side effect of
resolving one, and (2) an unhandled unique-constraint collision when two members share an email
(`resolveForCheckout()`'s own email-match rule correctly hands back the same payer for both, and
`brain_memberships.payer_reference_num` is `UNIQUE` — assigning it to the second member crashes,
so it must be skipped and counted instead). Both are exactly the kind of thing to remember if
this command is ever rebuilt for a real deployment.

## Verification performed

- `php -l` on every changed/new PHP file — clean.
- New test suites (`tests/Feature/Membership/`, 5 tests, ~15 assertions) — all pass. Notably,
  `MembershipController::store()` itself is **not** exercised end to end by an HTTP test — it
  also calls the pre-existing, untouched `FunctionsController::GetManagedDomain()`/`RunCommandBG()`
  shell-provisioning path, which no existing test in this codebase exercises either (confirmed:
  zero test files reference `MembershipController` before this change) and which this
  environment cannot safely invoke (no real `configurations` table, no `afs-website` binary).
  `BrainMembershipPayerResolutionTest` instead replicates `store()`'s exact sequence —
  `resolveForCheckout()` → `recordExistingCustomer()` → `Membership::create()` → `updateEmail()`
  — line for line, against the same schema `PayerServiceTest` already uses for this service.
- Full `tests/Feature/DirectPayments/` suite (96 tests) — unaffected, still passing.
- Full repository suite: 538 passed, the same 11 pre-existing, unrelated failures as before this
  change (`Appointments`/`Builder`/`Ecommerce`/`Events`/`Forms`, environment-domain assertions).
- **Not run**: the migration itself, or an actual `mysql` import of either corrected `.sql` file,
  against a live database — this session could not reach the vagrant lab's MySQL (connection
  refused). The migration's `up()`/`down()` and both edited SQL files were syntax/structure
  checked by hand (column-list-vs-value-count, no stray `customer_id` left in any
  `brain_memberships` INSERT), not executed. **Run `php artisan migrate` and reimport both
  `.sql` files in the vagrant lab before trusting this end to end**, and confirm
  `james1`/`james2` can still resolve their subscription/tier state afterward.

## Follow-on 2026-09-23 — `member_reference_num` removed from the Direct-Payments-owned tables

The "other codebase references" section above already showed the pattern: two Direct-Payments-
owned tables, `laravel_direct_payment_subscriptions` and `laravel_direct_payment_notification_sends`,
carried a `member_reference_num` column that was generically named but only ever populated on the
Brain — the exact same layering violation §2 of this doc fixed for `brain_memberships.customer_id`,
one layer up. This pass removes it from both tables, replacing it with the same
`payer_reference_num` this migration already introduced, so the Brain-specific bridge lives
entirely in `brain_memberships.payer_reference_num` and the two DP tables stay genuinely
tenant-generic. No backfill, same reasoning as above: this environment has no live data to
migrate, so the column is dropped in the same migration that adds `payer_reference_num` to
`laravel_direct_payment_subscriptions` (it already added `payer_reference_num` to
`laravel_direct_payment_notification_sends` first, then dropped `member_reference_num` from it too).

### Resolution order at each call site

`DirectPaymentSubscriptionController::resolvePayerReferenceNum()` (renamed from
`resolveMemberReferenceNum()`) now stops at the payer rather than walking on to
`Membership.reference_num`, in this order: (1) the invoice's own `payer_reference_num`
(`DirectPaymentInvoice`, populated by `PayerService` for any tenant with a `website_reference_num`),
(2) the Checkpoint bearer's `Membership.payer_reference_num`, (3) the processor customer's
`DirectPaymentPayerCustomer.payer_reference_num`. `BillingBackfillDpSubscriptions::resolvePayerReferenceNum()`
(same rename) does the same third step directly, since a backfill has no request/bearer to resolve from.

### Files changed (this follow-on, all in `www`)

| File | Change |
|---|---|
| `database/migrations/2026_09_23_000001_replace_member_reference_num_with_payer_on_direct_payments.php` | New. Adds `payer_reference_num` + drops `member_reference_num` on both tables, `Schema::hasColumn()`-guarded, full `down()` |
| `app/Models/Externals/DirectPaymentSubscription.php` | `member_reference_num` removed from `$fillable` (`payer_reference_num` was already present) |
| `app/Models/Externals/DirectPaymentNotificationSend.php` | `$fillable`: `member_reference_num` → `payer_reference_num` |
| `app/Http/Controllers/Externals/DirectPayments/DirectPaymentSubscriptionController.php` | `store()`, `update()`, `resolveOwnSubscription()`, `buildSubscriptionResponse()`, `resolveBillingState()` all moved onto `payer_reference_num`; `history()`'s stale `Membership.customer_id` read (a bug this pass caught, introduced by §2's column drop and missed until now) fixed to resolve via the payer's full `DirectPaymentPayerCustomer` set instead of a single raw id; `resolveMemberReferenceNum()` → `resolvePayerReferenceNum()` |
| `app/Http/Controllers/Portal/Admin/MembershipController.php` | The §4.2.4 adoption block (`www/direct_payments/22-subscription-state.md`) now adopts `payer_reference_num`, not `member_reference_num` |
| `app/Services/Billing/MembershipTierResolver.php` | `findGoverningRow()` re-keyed on `payer_reference_num`, with an explicit `if (!$member->payer_reference_num) return null;` guard — `->where('column', null)` silently becomes `whereNull()` in Eloquent, which would have matched an arbitrary unrelated member's unresolved subscription instead of correctly matching nothing |
| `app/Services/Billing/DunningService.php` | `sweepCancellations()` re-keyed the same way, same null-guard pattern |
| `app/Console/Commands/BillingEnforceMembership.php` | `candidates()`'s subquery re-keyed on `payer_reference_num` |
| `app/Console/Commands/BillingBackfillDpSubscriptions.php` | `resolveMemberReferenceNum()` → `resolvePayerReferenceNum()`, now returns the payer directly (no longer walks on to `Membership.reference_num`); dead `use App\Models\Membership;` import removed |
| `app/Console/Commands/BillingSubscriptionMigrate.php` | Its report line now prints `payer %s` instead of `member %s` |
| `app/Externals/DirectPayments/Notifications/NotificationDispatcher.php` | `resolveCustomerEmail()`, `insertPendingLedgerRow()`, `buildVars()` all re-keyed on `payer_reference_num` |
| `tests/Feature/DirectPayments/{BillingSubscriptionMigrateTest,BindingRetirementTest,DirectPaymentSubscriptionUpdateMembershipTest,DirectPaymentSwapControllerTest,SwapServiceTest}.php`, `tests/Feature/SiteUsers/SiteUsersPurchasesTest.php` | Inline `laravel_direct_payment_subscriptions` schemas updated to match (dropped `member_reference_num`, some already had `payer_reference_num` present); `DirectPaymentSubscriptionUpdateMembershipTest` additionally gained a `payer_reference_num` column on its `brain_memberships` schema and its `link()`/`Membership::create()` test data, since `update()`'s §17.3 code now joins through it |
| `databases/production/testdb_appfactorystudio.data-only.sql`, `databases/data-only/v2/testdb_appfactorystudio.data-only.sql` | The one `laravel_direct_payment_subscriptions` row in each (id=1, value already `NULL`) had `member_reference_num` dropped from its INSERT's column list |
| `plans/www/direct_payments/22-subscription-state.md`, `24-notification-scheduler.md`, and `22-subscription-state.complete.md` | Superseded-notes added pointing back here, rather than rewriting their original design record |

Not touched, confirmed genuinely Brain-only and out of scope: `BrainMembershipBillingState`,
`BrainMembershipEvent` (both correctly keyed by `Membership.reference_num`, not a Direct-Payments-
owned table), and the unrelated `member_reference_num` usages in `AfsConnect`/checkpoint-alloc/
marketplace-connect (a different domain's own member concept, not this one).

### Verification performed

- `php -l` on every file in the table above — clean.
- `tests/Feature/DirectPayments/` (95 tests) and `tests/Feature/Membership/` + the touched
  `SiteUsersPurchasesTest` (23 tests) — all pass.
- Full repository suite — see the run logged closest to this note's date; no new failures beyond
  the same pre-existing, unrelated set noted earlier in this document.
- **Not run**: the migration itself against a live database — same environment limitation as §2
  above (no reachable vagrant lab MySQL from this session). Guarded `Schema::hasColumn()` checks
  on both directions of the migration were read by hand, not executed.

- **BM-P2/BM-P3 (`resources/js/afs/` SDK cutover) — not built, and not safely buildable as
  written.** See "Deviation from the plan" above. If the remaining client-side polish (BM3's
  duplicate `updateCustomerEmail()` calls, BM5's three-sources-of-truth for
  `AFS_DIRECT_PAYMENT_ID`, BM6's dead wire field) is still wanted, it can be done independently
  of BM1's fix — but it touches AFS's public, unauthenticated signup bundle directly (§4.2:
  *"a broken deploy here... stops new members from signing up for the product at all"*) and
  deserves its own careful pass.
- **`resolveAndValidatePayer()`/`belongsToDirectPayment()`** — never built, per "Deviation from
  the plan." If a future change *does* let the client supply a payer reference, it needs its own
  validation step; `resolveForCheckout()`'s scope check is sufficient for the current,
  server-only design, not necessarily for a client-trusting one.
- **If this migration is ever run against a database that has real, unmigrated `customer_id`
  data** (i.e., not a fresh lab import), `BrainMembershipBackfillPayers` needs to be rebuilt
  first, and run to completion, **before** the migration drops the column — otherwise that
  data is unrecoverable. Not a concern for this environment; a real concern anywhere else.

## Lab verification — 2026-09-24

Both migrations in this document (`2026_09_22_000002_add_payer_reference_num_to_brain_memberships`,
`2026_09_23_000001_replace_member_reference_num_with_payer_on_direct_payments`) were applied to
the vagrant lab, together with the two `membership_change` migrations that were also pending
(`2026_09_23_000002`, `2026_09_23_000003`), via `php artisan migrate --force` in
`/vagrant/afs-eco/w/www`. All four ran clean, resolving the "migration itself not run" items above.
`migrate:status` beforehand showed exactly those four as `Pending`.

**Still not done:**
- The corrected seed `.sql` files were **not** re-imported. `customer_id` was dropped from the lab's
  existing `brain_memberships` rows by the migration, so `james1`/`james2` have no payer link until
  `databases/data-only/v2/testdb_appfactorystudio.data-only.sql` is re-imported.
- A signup through `store()` succeeding and writing `payer_reference_num` is **not yet confirmed**.
  A first attempt (`james3`) failed at the insert on the then-missing `tier_source` column, before
  reaching the payer columns, so it proved nothing either way about this work.
- See `03-client-sdk-and-flow.complete.md` for the client-side check that is also still open.

### Update 2026-09-24 — first real signup in the lab

A signup (`james3`, synchronous paid path) reached `MembershipController::store()` and committed a
`brain_memberships` row with `payer_reference_num` = `pay_gpddmnvy8zms`, `tier_source` = `paid`, tier 1,
and a real provider subscription id; `customer_id` no longer exists. **This confirms the server-side
identity design (§2, BI2's write path) works against the real schema.** Read back with a read-only
query on the lab database.

Still open: the Stripe customer receiving the email via `PayerService::updateEmail()` (not checked in
the Stripe test dashboard); the Stripe-redirect payment path (only the synchronous path was tried);
`james1`/`james2` still have `payer_reference_num` NULL until the corrected seed
`databases/data-only/v2/testdb_appfactorystudio.data-only.sql` is re-imported; and the seed import
itself has still not been run.

Two lab problems surfaced on the way, neither caused by this plan's code: `web`'s DNS resolver had
reverted (see `AFS-DEPLOYMENT-VAGRANT.md`'s known issue), and the signup returned 500 after the row
had committed because the `afterCommit` `NotifyMembersOfTierChange` dispatch had no queue table (see
`../../membership_change/complete/00_extras.md`). Note the second one means a **successful signup can
still return an error to the user** whenever a post-commit dispatch fails.
