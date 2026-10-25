# 31 — Code layout consolidation — completion

**Done 2026-09-25**, in `www`, as uncommitted working-tree changes (the renames are staged by `git mv`;
nothing is committed). Plan: [`../31-code-layout-consolidation.md`](../31-code-layout-consolidation.md).

## What was built

### Moves (27 files, `git mv`, namespace lines rewritten)

| From (`App\Services\Billing\…`) | To |
|---|---|
| `SubscriptionSyncService` | `App\Externals\DirectPayments\Subscriptions\SubscriptionSyncService` |
| `Providers\*` (17: readers, actions, retry configs, exceptions) | `App\Externals\DirectPayments\Subscriptions\Providers\*` |
| `NotificationDispatcher` | `App\Externals\DirectPayments\Notifications\NotificationDispatcher` |
| `BillingMailer`, `SendResult` | `App\Mail\BillingMailer`, `App\Mail\SendResult` |
| `Exceptions\MailHandlerMissingException`, `MailIntegrationMissingException` | `App\Mail\Exceptions\…` |
| `RefundService` | `App\Externals\DirectPayments\Money\RefundService` |
| `DunningPolicy`, `DunningPolicyResolver`, `AdvancedConfigGate` | `App\Externals\DirectPayments\Dunning\…` |

`App\Services\Billing` now holds only appfactory-specific code: `BrainGuard`, `DunningService`,
`MembershipTierResolver`, `Exceptions\DryRunRollback`, and the new `ApplyTierOnPlanChange`.

### The wrong-way dependency (§31.4)

`DirectPaymentSubscriptionController` no longer knows about `MembershipTierResolver`. After a plan change
it raises `App\Externals\DirectPayments\Events\SubscriptionPlanChanged`; `App\Services\Billing\ApplyTierOnPlanChange`
listens (registered in `AppServiceProvider::boot()`), applies the tier synchronously and reports a failure
on the event's `warning`, which the controller returns as the response `warning` exactly as before. With no
listener registered nothing happens.

### Command prefix

All Direct Payments commands now use `direct_payments:` — `subscription-notify`,
`backfill-subscriptions` (was `billing:backfill-dp-subscriptions`), `subscription-migrate`,
`backfill-processor-bindings`, `backfill-tier-bindings`, `import-customer-map`. `billing:enforce-membership`,
`processor:*` and `money:backfill-charges` are unchanged. Class names were **not** renamed.

### Guard

`tests/Unit/DirectPayments/LayeringTest.php` fails if anything under `App\Externals\DirectPayments` or
`Http\Controllers\DirectPayments` imports `App\Services\Billing`, and if a moved class reappears under the
old directory.

## Decisions taken (the plan's open questions)

| Question | Decision |
|---|---|
| `BillingMailer` home | `App\Mail`, beside `MailHandlerSender` — it is a mail seam used by both the generic notifier and appfactory code |
| Rename classes too | No — names kept, namespaces changed |
| Before or after the `members` enforcement move | Before |
| Which `DunningPolicyResolver` is authoritative | The `www` one (`App\Externals\DirectPayments\Dunning`) |
| Command prefix spelling | `direct_payments:` |

## Verification

| Check | Result |
|---|---|
| `php -l` on every changed file | clean |
| `composer dump-autoload`; `artisan list`; `artisan route:list` (608 routes) | succeed; the renamed commands are registered |
| `tests/Feature/DirectPayments` + `tests/Unit/DirectPayments` (run with `APP_URL=https://www.test`) | **93 passed** (91 existing + 2 layering), same 91 as before the move |
| Whole suite | 584 tests; 3 failures, none touching these classes: `FormsExternalSdkStaticTest` (missing `resources/js/external/forms/renderer.ts`), `BuilderModuleRefsBackfillTest`, `ExampleTest` (`laravel_builder_published` table absent in the test DB). **Not compared against a pre-move baseline**, so "pre-existing" is inferred from what they cover |
| Leftover `App\Services\Billing` references in `app/ tests/ routes/ config/` | only the classes that stayed, plus `BillingEnforceMembership`'s own imports |

## Verified afterwards (vagrant lab, 2026-09-25)

- `artisan schedule:list` in the lab shows the renamed `direct_payments:subscription-notify`.
- All six `direct_payments:*` commands and `billing:enforce-membership` run with `--dry-run` against the lab database.
- 19 moved or touched classes resolve from the container, and one listener is registered for `SubscriptionPlanChanged`.
- The 93 Direct Payments tests pass inside the lab VM (PHP 8.4.25) as well as on the host.
- `tsc --noEmit` and a full `vite build` of `www` succeed; the built admin bundle contains `app/Mail/variables.json`
  (checked by a key from it).
- With `laravel-cron.sh`, scheduler entries for `www` and `members` were installed for the lab user `vagrant`; cron then ran
  `schedule:run` every minute and a scheduled command (`ecommerce:expire-holds`) completed.

## Not verified

- Serialized class names in `jobs` / `failed_jobs` — not inspected; drain the queue before deploying.
- Cron entries or scripts on real servers that call a renamed command by its old name. Nothing in the repository does
  (`commands.sh` only calls `--update-cron-task`), but installed crontabs were not inspected.
- Pre-existing uncommitted `www` work touches some of the same files (`DirectPaymentSubscriptionController`,
  `NotificationDispatcher`, `DunningService`, `MembershipTierResolver`, `AppServiceProvider`, three commands,
  `MembershipController`, `DirectPaymentNotificationSend`), so the refactor cannot be committed on its own without
  taking that work with it.
## Documentation

The plan documents were rewritten to describe the new layout (paths, namespaces, command names, the
guard and dunning-policy locations) — see [`../31-code-layout-consolidation.md`](../31-code-layout-consolidation.md) §31.10.
