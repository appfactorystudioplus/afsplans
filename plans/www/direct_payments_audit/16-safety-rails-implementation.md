# 16 — Safety Rails: Implementation (PA0)

> **Status (2026-10-16): all eight steps approved by the owner.** Not yet implemented.

> **For review. No code has been changed.** Every block of code in this folder is the exact code proposed, written
> against the files as they are on 2026-10-16. Paths are relative to `w/www/`. Each change carries
> `AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §<n>`.

PA0 removes every way to destroy payment history and every way to charge a processor that should not be charged, before
any new feature exists. It changes no happy path: a tenant who never archives, swaps or deletes anything sees no
difference.

## What PA0 delivers

| Step | Document | Findings fixed | Owner decisions applied |
|---|---|---|---|
| 1 | step 1 — columns, two new tables, foreign keys, straight into the create migrations | A2 (schema side) | — |
| 2 | step 2 — archive / unarchive, no delete, one lookup with the precedence fix, webhook-removal guard | A1, A4, A5, A9 | OQ1 |
| 3 | step 3 — DP archive, `saveDirectPayment()` fixes, items and tiers archived not deleted | A10, A11, A16, A17, A18 | OQ2 |
| 4 | step 4 — one compatibility check for both paths, atomic swap, revert, Go live | A12, A13, A14, A15, A19 | OQ5, OQ6 |
| 5 | step 5 — stable subscription reference, tier verification, provider-ended subscriptions, webhook candidates, orphans | A32, A33, A36, A37 (partly), A55, A56 | — |
| 6 | step 6 — disputes stored for all three providers; retirement and archive read them | A58 | — |
| 7 | step 7 — the explanations on the processor and Direct Payment forms, archive in the processor list | — | OQ5 |
| 8 | step 8 — shared test schema, new tests, commands to run | — | — |

## Order of work

`1 → 2 → 3 → 4 → 5 → 6 → 7 → 8`. Steps 2–6 each compile and pass tests on their own once step 1 and step 8's shared
test schema exist; they can be reviewed and merged one at a time.

## Not in PA0 (and where it is)

- Locking provider / mode / account on update — PA1 (needs the account fingerprint).
- Server-side routing of new payments, `binding_version` — PA2. PA0 makes archived and paused processors refuse new
  payments, but the browser still names the processor.
- Scheduled changes, the Change-processor wizard, the Go live button — PA2 / PA8. PA0 ships the Go live **API** and the
  explanations; the buttons come with the wizard.
- Renaming billing/member vocabulary — PA7. New PA0 code already follows [13](13-naming-and-separation.md).

## Schema changes go straight into the create migrations

Owner decision (2026-10-16): the app is still in development and no real data exists, so every schema change is made
directly in the two existing create migrations and the development databases are rebuilt. No upgrade migrations, no
back-fill commands ( §1.3).

## Files touched

**New:** `app/Externals/Processor/ProcessorLookup.php`, `app/Externals/Processor/ProcessorUnavailable.php`,
`app/Externals/Processor/ProcessorArchiver.php`, `app/Externals/Processor/ProcessorArchiveRefused.php`,
`app/Externals/Processor/ProcessorDeletionForbidden.php`,
`app/Externals/Processor/Disputes/DisputeRecorder.php`, `app/Externals/DirectPayments/DirectPaymentArchiver.php`,
`app/Externals/DirectPayments/SwapCompatibility.php`, `app/Externals/DirectPayments/GoLiveService.php`,
`app/Externals/DirectPayments/Support/OperatorIdentity.php`,
`app/Externals/DirectPayments/Subscriptions/SubscriptionEndSync.php`,
`app/Externals/DirectPayments/Subscriptions/ProcessorSubscriptionObserver.php`,
`app/Models/Externals/DirectPaymentAuditLog.php`, `app/Models/Externals/Processor/ProcessorDispute.php`,
`tests/Support/CreatesPaymentsSchema.php` and the new tests in step 8.

**Changed:** both payment create-migrations; `Processor`, `DirectPayment`, `DirectPaymentItem`, `DirectPaymentTier`,
`DirectPaymentProcessorBinding` models; `ProcessorAdminController`, `ProcessorIntentController`,
`ProcessorSubscriptionController`, `ProcessorClientController`, `ProcessorSquarePaymentController`,
`ProcessorCustomerController`, `ProcessorSyncController`, `ProcessorWebhookAdminController`,
`ProcessorWebhookController`; `DirectPaymentsController`, `DirectPaymentSwapController`,
`DirectPaymentSubscriptionController`; `BindingRegistry`, `SwapService`, `Support\DirectPaymentReference`;
`AppServiceProvider`; `routes/portal.php`; `processor-details.html`,
`directpayments-form.html`, `ProcessorsAdminView.ts`, `ProcessorsService.ts`, the five admin i18n files; the 19 test
files that hand-build payment tables.

---

## Step 1 — Schema

### 1.1 Direct Payments create file

`database/migrations/2026_04_03_160000_create_laravel_direct_payment_tables.php` — additions only.

**`laravel_direct_payments`** — after `website_domain`:

```php
                // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §1.1 (A10, OQ2).
                // A Direct Payment that has ever taken money is archived, never deleted: it stops taking new payments,
                // its history, refunds and live subscriptions keep working. Set only by DirectPaymentArchiver.
                $table->timestamp('archived_at')->nullable();
                $table->string('archived_by', 191)->nullable();
                $table->string('archive_reason', 500)->nullable();
```

**`laravel_direct_payment_items`** — after `max_amount`:

```php
                // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §1.1 (A17). Removing an item from a Direct
                // Payment archives it; invoices and modules that reference it by reference_num keep resolving.
                $table->timestamp('archived_at')->nullable();
```

**`laravel_direct_payment_tiers`** — after `level`:

```php
                // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §1.1 (A18). Removing a tier archives it;
                // subscriptions pinned to it keep resolving their tier and level.
                $table->timestamp('archived_at')->nullable();
```

**`laravel_direct_payment_processor_bindings`** — after `note`:

```php
                // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §1.1 (OQ5). How this binding came to be:
                // initial (first processor of the DP) | change (processor change) | go_live (sandbox → live) |
                // revert (a retiring binding made active again — the row is reused, the revert is in the audit log).
                $table->string('kind', 20)->default('change');
```

**New table — after the processor bindings block:**

```php
        /**
         * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §1.1.
         *
         * Who archived, unarchived, changed the processor of, or took Go live on a processor or a Direct Payment, and
         * why. Append-only: rows are never updated or deleted. `actor` is OperatorIdentity::from($request)
         * ("user:12 <a@b.c>" or "unauthenticated" while the dashboard login is not enforced).
         */
        if (!Schema::hasTable('laravel_direct_payment_audit_log')) {
            Schema::create('laravel_direct_payment_audit_log', function (Blueprint $table) {
                $table->id();
                $table->string('subject_type', 20);                 // processor | direct_payment
                $table->string('subject_reference_num', 128);
                $table->string('action', 40);                       // archive | unarchive | change | go_live | revert | retire
                $table->string('actor', 191);
                $table->string('reason', 500)->nullable();
                $table->json('details')->nullable();                // e.g. {from, to, warnings, ended_subscriptions}
                $table->timestamp('created_at')->useCurrent();

                $table->index(['subject_type', 'subject_reference_num'], 'dp_audit_subject_idx');
            });
        }
```

`down()` gains `Schema::dropIfExists('laravel_direct_payment_audit_log');` before the bindings drop.

### 1.2 Processor create file

`database/migrations/2026_05_21_000001_create_processor_tables.php`.

**`laravel_processors`** — after `extra_config`:

```php
                // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §1.2 (A1, A5).
                // A processor is never deleted. Archived = closed to new payments, still answers refunds, live
                // subscriptions, disputes and webhooks, forever. is_active keeps its one meaning: a temporary pause.
                $table->timestamp('archived_at')->nullable();
                $table->string('archived_by', 191)->nullable();
                $table->string('archive_reason', 500)->nullable();
```

**The three cascading foreign keys (A2)** — `laravel_processor_connected_accounts` (line 207–210),
`laravel_processor_transfer_intents` (241–244), `laravel_processor_webhook_endpoints` (373–376): replace
`->onDelete('cascade')` with `->restrictOnDelete()` in each, and add above each the comment:

```php
                // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §1.2 (A2). Was onDelete('cascade'): deleting a
                // processor erased its Connect accounts / pending transfers / webhook registration. Processors are never
                // deleted now; RESTRICT makes the database refuse it too.
```

**New foreign keys onto `laravel_processors` from the Direct Payments tables** — appended after the
`laravel_processors` create block. They live here, not in the Direct Payments file, because that file runs first and a
foreign key can only point at a table that already exists (consolidation rule 6):

```php
        /**
         * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §1.2 (A1).
         *
         * RESTRICT foreign keys from every Direct Payments table that names a processor, so the database itself refuses
         * to delete a processor anything still refers to. Declared here because laravel_processors is created by this
         * file, after the Direct Payments tables (2026_04_03_160000).
         */
        foreach ([
            ['laravel_direct_payment_processor_bindings', 'dp_bind_processor_fk'],
            ['laravel_direct_payment_payer_customers', 'dp_payer_cust_processor_fk'],
            ['laravel_direct_payment_tier_bindings', 'dp_tier_bind_processor_fk'],
        ] as [$tableName, $fkName]) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'processor_reference_num')) {
                Schema::table($tableName, function (Blueprint $table) use ($fkName) {
                    $table->foreign('processor_reference_num', $fkName)
                        ->references('reference_num')->on('laravel_processors')
                        ->restrictOnDelete();
                });
            }
        }
```

`down()` drops these three foreign keys first (`$table->dropForeign($fkName)`, guarded by `hasTable`).

**New table — disputes (step 6):**

```php
        /**
         * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §1.2 (A58).
         *
         * Card disputes / chargebacks for every provider, written by the webhook handlers (they only logged before).
         * Read by BindingRegistry::canRetire() and the archive warnings: a processor with an open dispute is not
         * finished. Not the marketplace "order dispute" of plans/market step 15.
         */
        if (!Schema::hasTable('laravel_processor_disputes')) {
            Schema::create('laravel_processor_disputes', function (Blueprint $table) {
                $table->id();
                $table->string('processor_reference_num', 7)->index('processor_disputes_refnum_idx');
                $table->string('provider', 20);
                $table->string('provider_dispute_id', 255);
                $table->string('provider_charge_id', 255)->nullable()->index('processor_disputes_charge_idx');
                $table->unsignedBigInteger('invoice_id')->nullable()->index('processor_disputes_invoice_idx'); // laravel_direct_payment_invoices.id
                $table->unsignedInteger('amount_cents')->nullable();
                $table->string('currency', 10)->nullable();
                $table->string('reason', 100)->nullable();
                $table->string('status', 20);                 // open | won | lost | closed
                $table->string('provider_status', 60)->nullable();
                $table->timestamp('evidence_due_at')->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->unique(['processor_reference_num', 'provider_dispute_id'], 'processor_disputes_uq');
                $table->foreign('processor_reference_num', 'processor_disputes_refnum_fk')
                    ->references('reference_num')->on('laravel_processors')->restrictOnDelete();
            });
        }
```

`down()` drops it before `laravel_processors`.

### 1.3 Applying it

**Owner decision (2026-10-16): the app is still in development and no real data exists**, so there is no upgrade
migration and no back-fill. The columns, tables and foreign keys above go straight into the two create files, and the
development databases are rebuilt from them (`php artisan migrate:fresh` on a local database; on the dev server only with
the owner's go-ahead, as the consolidation plan already requires).

**Collation.** The new foreign keys need `processor_reference_num` and `laravel_processors.reference_num` to share
charset and collation. The processor tables declare `utf8mb4_unicode_ci`; the Direct Payments tables use the connection
default, `DB_COLLATION`, whose default is also `utf8mb4_unicode_ci` (`config/database.php:56`). If a database is ever
created with a different `DB_COLLATION`, `migrate:fresh` fails on the first foreign key with a clear MySQL error — that
is the check.

---

## Step 2 — Processor Archive and One Lookup

Fixes **A1, A4, A5, A9**; applies **OQ1** (unarchive allowed, audited).

### 2.1 `Processor` model

`app/Models/Externals/Processor/Processor.php`.

`archived_*` are **not** added to `$fillable` — `update()`/`fill()` from a request can never set them; only
`ProcessorArchiver` (§2.4) writes them with `forceFill()`.

```php
    protected $casts = [
        'is_active'   => 'boolean',
        'use_hosted'  => 'boolean',
        'extra_config' => 'array',
        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §2.1.
        'archived_at' => 'datetime',
    ];

    /**
     * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §2.1 (A1).
     *
     * A processor row is never deleted: every past charge, refund, dispute, subscription and payer mapping resolves
     * through it. This stops Eloquent deletes (including relation deletes); the RESTRICT foreign keys of
     * 16-safety-rails-implementation.md stop raw SQL. Archive instead (ProcessorArchiver).
     */
    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new \App\Externals\Processor\ProcessorDeletionForbidden();
        });
    }

    /** Archived: closed to new payments for good (until unarchived). Existing objects keep working. */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** May this processor take a NEW payment, customer, saved card or subscription right now? */
    public function acceptsNewBusiness(): bool
    {
        return $this->is_active && ! $this->isArchived();
    }

    /** active | paused | archived — the label the admin shows (03-processor-lock-in-and-archive.md §3.1). */
    public function lifecycleStatus(): string
    {
        return match (true) {
            $this->isArchived() => 'archived',
            ! $this->is_active => 'paused',
            default => 'active',
        };
    }
```

`toAdminArray()` gains, after `'is_active'`:

```php
            // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §2.1.
            'status'                => $this->lifecycleStatus(),
            'archived_at'           => $this->archived_at?->toIso8601String(),
            'archived_by'           => $this->archived_by,
            'archive_reason'        => $this->archive_reason,
```

`app/Externals/Processor/ProcessorDeletionForbidden.php` — new:

```php
<?php

namespace App\Externals\Processor;

/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §2.1.
 * Thrown by Processor::deleting. Processors are archived, never deleted.
 */
final class ProcessorDeletionForbidden extends \LogicException
{
    public function __construct()
    {
        parent::__construct('Processors are never deleted. Archive it instead (PROC_E_DELETE_FORBIDDEN).');
    }
}
```

### 2.2 One lookup — `ProcessorLookup` (A4, A9)

`app/Externals/Processor/ProcessorLookup.php` — new. Every public controller lookup goes through it; the hand-written
`where(reference)->orWhere(id)->where(provider)->where(is_active)` (which compiled to
`reference = x OR (id = x AND provider AND active)`) disappears.

```php
<?php

namespace App\Externals\Processor;

use App\Models\Externals\Processor\Processor;
use Illuminate\Support\Facades\Log;

/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §2.2 (A4, A9).
 *
 * The two questions a caller can ask about a processor, and nothing else:
 *
 *   forNewBusiness() — "may I start something NEW on this processor?" (intent, session, order, subscription, customer,
 *                       setup intent, public config). Refuses unknown, wrong-provider, archived and paused processors.
 *   forExisting()    — "which processor holds this EXISTING thing?" (capture, status read, refund, cancel). Never
 *                       refuses archived or paused: answering for old money is what archiving keeps alive.
 *
 * PA2 adds ProcessorRouter on top (route by Direct Payment instead of by a processor the browser names); it calls
 * this class.
 */
final class ProcessorLookup
{
    /**
     * By public reference. A numeric primary key is still accepted for one release (old admin builds and any caller
     * that sent `id`), logged each time so the remaining callers can be found and fixed (A9).
     */
    public function byReference(string $reference): ?Processor
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        $processor = Processor::query()->where('reference_num', $reference)->first();
        if ($processor === null && ctype_digit($reference)) {
            $processor = Processor::query()->whereKey((int) $reference)->first();
            if ($processor !== null) {
                Log::notice('[DirectPayments][ProcessorLookup] processor looked up by numeric id', [
                    'id' => $reference,
                    'reference_num' => $processor->reference_num,
                ]);
            }
        }

        return $processor;
    }

    /** @throws ProcessorUnavailable */
    public function forNewBusiness(string $reference, ?string $provider = null): Processor
    {
        $processor = $this->byReference($reference);

        if ($processor === null || ($provider !== null && $processor->provider !== $provider)) {
            throw ProcessorUnavailable::notFound();
        }
        if ($processor->isArchived()) {
            throw ProcessorUnavailable::archived($processor);
        }
        if (! $processor->is_active) {
            throw ProcessorUnavailable::paused($processor);
        }

        return $processor;
    }

    /** @throws ProcessorUnavailable only when the processor does not exist (or is another provider) */
    public function forExisting(string $reference, ?string $provider = null): Processor
    {
        $processor = $this->byReference($reference);
        if ($processor === null || ($provider !== null && $processor->provider !== $provider)) {
            throw ProcessorUnavailable::notFound();
        }

        return $processor;
    }
}
```

`app/Externals/Processor/ProcessorUnavailable.php` — new:

```php
<?php

namespace App\Externals\Processor;

use App\Models\Externals\Processor\Processor;
use Illuminate\Http\JsonResponse;

/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §2.2.
 * Why ProcessorLookup refused. toResponse() keeps each endpoint's existing "not found" message and status for the
 * not-found case, so current callers see no change; archived/paused get their own code.
 */
final class ProcessorUnavailable extends \RuntimeException
{
    private function __construct(public readonly string $errorCode, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('PROC_E_NOT_FOUND', 'Processor not found.', 404);
    }

    public static function archived(Processor $processor): self
    {
        return new self('PROC_E_ARCHIVED', "Processor '{$processor->name}' is archived and takes no new payments.", 409);
    }

    public static function paused(Processor $processor): self
    {
        return new self('PROC_E_PAUSED', "Processor '{$processor->name}' is paused and takes no new payments right now.", 409);
    }

    /** @param string $notFoundMessage the endpoint's own historical message, e.g. 'Stripe processor not found.' */
    public function toResponse(string $notFoundMessage): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->errorCode === 'PROC_E_NOT_FOUND' ? $notFoundMessage : $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->status);
    }
}
```

### Call sites

Each controller gets `ProcessorLookup` as `$this->processors`. `ProcessorIntentController` and
`ProcessorCustomerController` already have constructors and gain the parameter; `ProcessorAdminController`,
`ProcessorClientController`, `ProcessorSubscriptionController`, `ProcessorSquarePaymentController` and
`ProcessorSyncController` get one: `public function __construct(private readonly ProcessorLookup $processors) {}`.
Every replacement has this shape:

```php
        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §2.2 (A4).
        try {
            $processor = $this->processors->forNewBusiness($data['processor_reference_num'], 'stripe');
        } catch (ProcessorUnavailable $e) {
            return $e->toResponse('Stripe processor not found.');
        }
```

| File : line | Method | Becomes |
|---|---|---|
| `ProcessorIntentController.php:55–59` | `createSetupIntent` | `forNewBusiness(ref, 'stripe')`, message `Stripe processor not found.` |
| `:155–158` | `createIntent` | `forNewBusiness(ref, 'stripe')` |
| `:342–346` | `getPaymentIntentStatus` | **`forExisting(ref, 'stripe')`** — a status read must work for an archived processor |
| `:409–412` | `createSession` | `forNewBusiness(ref, 'stripe')` |
| `:487–491` | `createPayPalOrder` | `forNewBusiness(ref, 'paypal')`, message `PayPal processor not found.` |
| `:603–607` | `capturePayPalOrder` | **`forExisting(ref, 'paypal')`** — an order the buyer already approved must capture even if the processor was archived a minute ago |
| `ProcessorSubscriptionController.php:39–42` | `store` | `forNewBusiness(ref, $provider)`, message `Active processor not found.` |
| `ProcessorSquarePaymentController.php:40–44` | `store` | `forNewBusiness(ref, 'square')` (this one was already correct; moved onto the lookup so archived is refused too) |
| `ProcessorClientController.php:52–54` | `show` (public config) | `forNewBusiness($processorId)`, message `Processor not found or inactive.` |
| `ProcessorCustomerController.php` `findOrCreate`, `createAnonymous` | | `forNewBusiness($processorId)`, message `Processor not found.` |
| `ProcessorCustomerController.php` `updateEmail` | | `forExisting($processorId)` |
| `ProcessorAdminController.php:129, 176, 197` | `update`, `show`, `destroy` | `byReference($id) ?? abort(404)` |
| `ProcessorSyncController.php:22, 85` | `dryRun`, `execute` | `byReference($id) ?? abort(404)` |

Out of PA0, noted for PA3: `ProcessorBillingController::resolveProcessorAndCustomer()` (`where('reference_num')` only —
no precedence bug) is left as it is; PA3 replaces those routes.

### 2.3 Who did it — `OperatorIdentity`

`app/Externals/DirectPayments/Support/OperatorIdentity.php` — new. Used for `archived_by`, binding `bound_by` (A19) and
the audit log.

```php
<?php

namespace App\Externals\DirectPayments\Support;

use Illuminate\Http\Request;

/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §2.3 (A19).
 *
 * The operator behind an admin request, taken from the dashboard login (ApiAuthMiddleware sets `auth_user`), never from
 * the request body. While the externals.dashboard middleware is in `off` mode no login is attached, and the honest
 * answer is "unauthenticated" — recorded as such rather than invented.
 */
final class OperatorIdentity
{
    public static function from(Request $request): string
    {
        $user = $request->attributes->get('auth_user');
        if ($user === null) {
            return 'unauthenticated';
        }

        $email = (string) ($user->email ?? '');

        return 'user:' . $user->getKey() . ($email !== '' ? ' <' . $email . '>' : '');
    }
}
```

### 2.4 Archive and unarchive

`app/Externals/Processor/ProcessorArchiver.php` — new.

```php
<?php

namespace App\Externals\Processor;

use App\Models\Externals\DirectPaymentAuditLog;
use App\Models\Externals\DirectPaymentInvoice;
use App\Models\Externals\DirectPaymentProcessorBinding;
use App\Models\Externals\Processor\Processor;
use App\Models\Externals\Processor\ProcessorDispute;
use App\Models\Externals\Processor\ProcessorSubscription;
use Illuminate\Support\Facades\DB;

/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §2.4
 * (A1, A5, OQ1). plans/www/direct_payments_audit/03-processor-lock-in-and-archive.md §3.2.
 *
 * The only writer of laravel_processors.archived_*. Archiving closes a processor to new payments; it never touches
 * the provider, its webhook registration, its customers, cards, subscriptions or Connect accounts.
 */
final class ProcessorArchiver
{
    /**
     * @return array{processor: Processor, warnings: string[]}
     * @throws ProcessorArchiveRefused when the processor is still the active processor of a Direct Payment
     */
    public function archive(Processor $processor, string $reason, string $actor): array
    {
        return DB::transaction(function () use ($processor, $reason, $actor) {
            $locked = Processor::query()->whereKey($processor->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isArchived()) {
                return ['processor' => $locked, 'warnings' => []];
            }

            $activeFor = DirectPaymentProcessorBinding::query()
                ->where('processor_reference_num', $locked->reference_num)
                ->where('status', 'active')
                ->pluck('direct_payment_reference_num')
                ->all();
            if ($activeFor !== []) {
                throw new ProcessorArchiveRefused($activeFor);
            }

            $warnings = $this->warnings($locked);

            $locked->forceFill([
                'archived_at' => now(),
                'archived_by' => $actor,
                'archive_reason' => $reason,
            ])->save();

            DirectPaymentAuditLog::record('processor', $locked->reference_num, 'archive', $actor, $reason, ['warnings' => $warnings]);

            return ['processor' => $locked, 'warnings' => $warnings];
        });
    }

    public function unarchive(Processor $processor, string $reason, string $actor): Processor
    {
        return DB::transaction(function () use ($processor, $reason, $actor) {
            $locked = Processor::query()->whereKey($processor->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isArchived()) {
                return $locked;
            }

            $locked->forceFill(['archived_at' => null, 'archived_by' => null, 'archive_reason' => null])->save();
            DirectPaymentAuditLog::record('processor', $locked->reference_num, 'unarchive', $actor, $reason);

            return $locked;
        });
    }

    /**
     * Obligations an archived processor keeps answering — shown, never blocking (03 §3.2).
     *
     * @return string[]
     */
    public function warnings(Processor $processor): array
    {
        $ref = $processor->reference_num;
        $out = [];

        $retiring = DirectPaymentProcessorBinding::where('processor_reference_num', $ref)->where('status', 'retiring')->count();
        if ($retiring > 0) {
            $out[] = "{$retiring} Direct Payment(s) still have this processor as a retiring processor.";
        }

        $live = ProcessorSubscription::where('processor_reference_num', $ref)
            ->whereNotIn('status', ['canceled', 'incomplete_expired'])->count();
        if ($live > 0) {
            $out[] = "{$live} subscription(s) still renew on this processor. They keep working after archiving.";
        }

        $disputes = ProcessorDispute::where('processor_reference_num', $ref)->where('status', 'open')->count();
        if ($disputes > 0) {
            $out[] = "{$disputes} open dispute(s). Notifications for them keep arriving after archiving.";
        }

        $recent = DirectPaymentInvoice::where('processor_reference_num', $ref)
            ->whereIn('status', DirectPaymentInvoice::PAID_STATUSES)
            ->where('charged_at', '>=', now()->subDays(\App\Externals\DirectPayments\BindingRegistry::DEFAULT_REFUND_WINDOW_DAYS))
            ->count();
        if ($recent > 0) {
            $out[] = "{$recent} payment(s) in the last 180 days can still be refunded or disputed.";
        }

        $accounts = $processor->connectedAccounts()->whereNull('closed_at')->count();
        if ($accounts > 0) {
            $out[] = "{$accounts} Connect account(s) stay on this processor; their payouts and transfers keep working.";
        }

        return $out;
    }
}
```

`app/Externals/Processor/ProcessorArchiveRefused.php` — new (carries the list of Direct Payments):

```php
<?php

namespace App\Externals\Processor;

/** AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §2.4. */
final class ProcessorArchiveRefused extends \RuntimeException
{
    /** @param string[] $directPaymentRefs */
    public function __construct(public readonly array $directPaymentRefs)
    {
        parent::__construct('This processor is the active processor of ' . count($directPaymentRefs)
            . ' Direct Payment(s). Change their processor first.');
    }
}
```

`app/Models/Externals/DirectPaymentAuditLog.php` — new:

```php
<?php

namespace App\Models\Externals;

use Illuminate\Database\Eloquent\Model;

/** AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §1.1. Append-only. */
class DirectPaymentAuditLog extends Model
{
    protected $table = 'laravel_direct_payment_audit_log';
    public $timestamps = false;
    protected $fillable = ['subject_type', 'subject_reference_num', 'action', 'actor', 'reason', 'details', 'created_at'];
    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    public static function record(string $subjectType, string $subjectRef, string $action, string $actor, ?string $reason = null, array $details = []): self
    {
        return static::create([
            'subject_type' => $subjectType,
            'subject_reference_num' => $subjectRef,
            'action' => $action,
            'actor' => $actor,
            'reason' => $reason,
            'details' => $details === [] ? null : $details,
            'created_at' => now(),
        ]);
    }
}
```

### Controller

`ProcessorAdminController.php` — `destroy()` replaced; `archive()`/`unarchive()` added; `index()` filters.

```php
    /**
     * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §2.4 (A1).
     * Kept as a route so an old admin build gets a clear answer instead of a silent delete.
     */
    public function destroy(string $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Processors are never deleted. Archive it instead — past payments stay refundable and live subscriptions keep working.',
            'code' => 'PROC_E_DELETE_FORBIDDEN',
        ], 405);
    }

    /** POST /portal/api/v1/processors/{ref}/archive  {reason} */
    public function archive(Request $request, string $ref, ProcessorArchiver $archiver): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:500']);
        $processor = $this->processors->byReference($ref) ?? abort(404);

        try {
            $result = $archiver->archive($processor, $validated['reason'], OperatorIdentity::from($request));
        } catch (ProcessorArchiveRefused $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'PROC_E_ARCHIVE_ACTIVE_BINDING',
                'direct_payments' => $e->directPaymentRefs,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'data' => $result['processor']->toAdminArray(),
            'warnings' => $result['warnings'],
        ]);
    }

    /** POST /portal/api/v1/processors/{ref}/unarchive  {reason} — OQ1: allowed, audited. */
    public function unarchive(Request $request, string $ref, ProcessorArchiver $archiver): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:500']);
        $processor = $this->processors->byReference($ref) ?? abort(404);

        return response()->json([
            'success' => true,
            'data' => $archiver->unarchive($processor, $validated['reason'], OperatorIdentity::from($request))->toAdminArray(),
        ]);
    }
```

`index()`: `?status=active|paused|archived|all`, default `all` (the admin list filters client-side so nothing
disappears from an existing screen without the UI change of step 7). `store()` stops accepting a client
`reference_num` (A6) — **deferred to PA1** to keep PA0 free of contract changes for processor creation.

`ProcessorAdminController::update()` — one addition so an archived processor cannot be quietly un-paused into taking
payments; archive state changes only through the archive endpoints:

```php
        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §2.4. is_active on an archived processor would read as
        // "active" to an operator but changes nothing (archived wins); refuse it so the UI never shows a misleading state.
        if ($processor->isArchived() && array_key_exists('is_active', $validated) && $validated['is_active']) {
            return response()->json([
                'success' => false,
                'message' => 'This processor is archived. Unarchive it to take new payments again.',
                'code' => 'PROC_E_ARCHIVED',
            ], 409);
        }
```

### Routes

`routes/portal.php`, in the `api/v1/processors` group next to `Route::post('/', …store)`:

```php
        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §2.4.
        Route::post('/{ref}/archive',   [ProcessorAdminController::class, 'archive'])->middleware('externals.dashboard');
        Route::post('/{ref}/unarchive', [ProcessorAdminController::class, 'unarchive'])->middleware('externals.dashboard');
```

### 2.5 Archived processors cannot be bound

`BindingRegistry::ensureActive()` and `swap()` refuse an archived processor (a new DP, or a swap target):

```php
        if ($processor->isArchived()) {
            throw new DirectPaymentException('DP_E_PROCESSOR_ARCHIVED');
        }
```

(`swap()`'s full rewrite is in  §4.3.) In `saveDirectPayment()` the shared compatibility check
refuses an archived first processor before `ensureActive()` runs (`422 SWAP_E_TARGET_ARCHIVED`,
 §3.4(d)); the `ensureActive()` refusal is the data-layer backstop, turned into
`409 DP_E_PROCESSOR_ARCHIVED` instead of today's silent logged warning (§3.4(e)).

### 2.6 Webhook registration cannot be removed while still needed

`ProcessorWebhookAdminController::destroy()`, before calling the registrar:

```php
        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §2.6 (03 §3.5).
        // Removing the provider-side webhook on a processor that still has money in flight drops refunds, disputes
        // and renewals on the floor. The same obligations ProcessorArchiver warns about decide it here.
        $obligations = app(ProcessorArchiver::class)->warnings($processor);
        $activeBinding = \App\Models\Externals\DirectPaymentProcessorBinding::where('processor_reference_num', $processor->reference_num)
            ->where('status', 'active')->exists();
        if ($activeBinding || $obligations !== []) {
            return response()->json([
                'success' => false,
                'message' => 'This processor still needs its webhook: ' . implode(' ', $activeBinding ? array_merge(['It is a Direct Payment\'s active processor.'], $obligations) : $obligations),
                'code' => 'PROC_E_WEBHOOK_STILL_NEEDED',
            ], 409);
        }
```

### 2.7 Acceptance

- `DELETE /processors/{id}` → 405 `PROC_E_DELETE_FORBIDDEN`, row unchanged. `Processor::first()->delete()` throws
  `ProcessorDeletionForbidden`.
- Archive a DP's active processor → 409 `PROC_E_ARCHIVE_ACTIVE_BINDING` listing the DP. Archive a retiring one → 200
  with warnings; `archived_*` set; audit row written; second archive call is a no-op.
- After archive: `create-intent`, `create-setup-intent`, `create-session`, `paypal/create-order`,
  `{provider}/create-subscription`, `square/create-payment`, `customers/create-anonymous`, `customers/find-or-create`,
  `processors/public/{ref}` → 409 `PROC_E_ARCHIVED`. `payment-intent/{id}/status` and `paypal/capture-order` still work.
- The A4 regression: an **inactive** processor named by `reference_num` can no longer create an intent (it could before).
- Unarchive → processor takes payments again; audit row written.
- Webhook removal on a processor with live subscriptions → 409 `PROC_E_WEBHOOK_STILL_NEEDED`.

---

## Step 3 — Direct Payment Archive and Save Fixes

Fixes **A10, A11, A16, A17, A18**; applies **OQ2** (archive once used; a never-used DP may still be deleted).

### 3.1 Models

`app/Models/Externals/DirectPayment.php`:

```php
    // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §3.1.
    // archived_* are not fillable: only DirectPaymentArchiver writes them.
    protected $casts = ['archived_at' => 'datetime'];

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Items currently offered. Archived items stay resolvable by reference (invoices, modules) but are not listed. */
    public function items()
    {
        return $this->hasMany(DirectPaymentItem::class, 'direct_payment_reference_num', 'reference_num')->whereNull('archived_at');
    }

    /** Tiers currently offered. Archived tiers stay resolvable by reference (subscriptions pinned to them). */
    public function tiers()
    {
        return $this->hasMany(DirectPaymentTier::class, 'direct_payment_reference_num', 'reference_num')->whereNull('archived_at');
    }
```

The only callers of `items()` / `tiers()` are in `DirectPaymentsController` (`getDirectPayments`, `saveDirectPayment`'s
response, `destroy`), checked by grep. Point lookups by item or tier reference elsewhere (appointments
`AppointmentPaymentService.php:49`, events `EventPaymentService.php:39`, ecommerce `EcommerceController.php:171`,
`DirectPaymentSubscriptionController`, staff tools) deliberately still find archived rows — history must resolve.

`DirectPaymentItem` and `DirectPaymentTier`: add `'archived_at'` to `$fillable` (the save path sets it back to null when
a tenant re-adds an archived reference) and `protected $casts = ['archived_at' => 'datetime'];`.

`app/Externals/DirectPayments/Support/DirectPaymentReference.php` gains the item reference the controller already mints
inline (`DirectPaymentsController.php:648`), so both paths issue the same format:

```php
    /** AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §3.4 (A17). 'x' + 10 lowercase, as storeItem() already issues. */
    public static function item(): string
    {
        return 'x' . Str::lower(Str::random(10));
    }
```

### 3.2 `DirectPaymentArchiver`

`app/Externals/DirectPayments/DirectPaymentArchiver.php` — new.

```php
<?php

namespace App\Externals\DirectPayments;

use App\Models\Externals\DirectPayment;
use App\Models\Externals\DirectPaymentAuditLog;
use App\Models\Externals\DirectPaymentInvoice;
use App\Models\Externals\DirectPaymentProcessorBinding;
use App\Models\Externals\DirectPaymentSubscription;
use Illuminate\Support\Facades\DB;

/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §3.2
 * (A10, OQ2).
 *
 * A Direct Payment that has ever had an invoice, a subscription or a processor binding is part of payment history:
 * modules, invoices and subscriptions hold its reference forever. It is archived, never deleted. A Direct Payment with
 * none of those was never used and may still be deleted, exactly as before.
 */
final class DirectPaymentArchiver
{
    public function isUsed(string $dpRef): bool
    {
        return DirectPaymentInvoice::where('direct_payment_reference_num', $dpRef)->exists()
            || DirectPaymentSubscription::where('direct_payment_reference_num', $dpRef)->exists()
            || DirectPaymentProcessorBinding::where('direct_payment_reference_num', $dpRef)->exists();
    }

    /** Money history only — used by the pay_type lock (a binding alone does not make a DP's kind permanent). */
    public function hasMoneyHistory(string $dpRef): bool
    {
        return DirectPaymentInvoice::where('direct_payment_reference_num', $dpRef)->exists()
            || DirectPaymentSubscription::where('direct_payment_reference_num', $dpRef)->exists();
    }

    public function archive(DirectPayment $dp, ?string $reason, string $actor): DirectPayment
    {
        return DB::transaction(function () use ($dp, $reason, $actor) {
            $locked = DirectPayment::query()->whereKey($dp->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isArchived()) {
                $locked->forceFill(['archived_at' => now(), 'archived_by' => $actor, 'archive_reason' => $reason])->save();
                DirectPaymentAuditLog::record('direct_payment', $locked->reference_num, 'archive', $actor, $reason);
            }
            return $locked;
        });
    }

    public function unarchive(DirectPayment $dp, ?string $reason, string $actor): DirectPayment
    {
        return DB::transaction(function () use ($dp, $reason, $actor) {
            $locked = DirectPayment::query()->whereKey($dp->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isArchived()) {
                $locked->forceFill(['archived_at' => null, 'archived_by' => null, 'archive_reason' => null])->save();
                DirectPaymentAuditLog::record('direct_payment', $locked->reference_num, 'unarchive', $actor, $reason);
            }
            return $locked;
        });
    }
}
```

Archiving a DP does not change its binding: the processor stays bound (and therefore cannot itself be archived) so
refunds, renewals and webhooks for the DP's money keep working. An archived DP refuses new invoices (§3.5) and saves
(§3.4).

### 3.3 `destroy()` and a new `unarchive()`

`DirectPaymentsController::destroy()` (:308–330) becomes:

```php
    public function destroy(Request $request, string $reference_num)
    {
        $directPayment = DirectPayment::where('reference_num', $reference_num)->firstOrFail();

        $inUse = $this->publishedRefs->blockIfPublished($request, 'direct_payments', $reference_num, 'payment');
        if ($inUse !== null) {
            return $inUse;
        }

        /**
         * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §3.3 (A10, OQ2).
         * Used before (an invoice, subscription or binding exists) → archived, never deleted. Never used → deleted as
         * before. The response says which, so the admin can label it.
         */
        if ($this->archiver->isUsed($reference_num)) {
            $archived = $this->archiver->archive($directPayment, $request->input('reason'), OperatorIdentity::from($request));

            return response()->json(['success' => true, 'archived' => true, 'data' => $archived]);
        }

        // items()/tiers() now hide archived rows; a never-used DP is removed completely, so delete by column.
        DirectPaymentItem::where('direct_payment_reference_num', $reference_num)->delete();
        DirectPaymentTier::where('direct_payment_reference_num', $reference_num)->delete();
        DirectPaymentConnect::where('direct_payment_reference_num', $reference_num)->delete();
        DirectPaymentNotification::where('direct_payment_reference_num', $reference_num)->delete();
        $directPayment->delete();

        return response()->json(['success' => true, 'archived' => false]);
    }

    /** POST /portal/api/v1/directpayments/{reference_num}/unarchive — OQ2, audited. */
    public function unarchive(Request $request, string $reference_num)
    {
        $directPayment = DirectPayment::where('reference_num', $reference_num)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->archiver->unarchive($directPayment, $request->input('reason'), OperatorIdentity::from($request)),
        ]);
    }
```

The allocation release `plans/billing/16-allocation.md §16.4` mentioned in the old docblock is an appfactory concern and
does not run here today (the method has no release call); PA0 does not add one. Noted in
[14](14-appfactory-studio-modifications.md) for the owner: whether an **archived** DP should count against a domain's
Direct Payment allowance is appfactory's decision.

Route (`routes/portal.php`, `api/v1/directpayments` group, before the `/{reference_num}` delete):

```php
        Route::post('/{reference_num}/unarchive', [DirectPaymentsController::class, 'unarchive'])->middleware('externals.dashboard');
```

`getDirectPayments()` excludes archived DPs unless `?include_archived=1`, and returns `archived_at` on each row:

```php
        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §3.3. An archived DP takes no new payments; checkout pages
        // asking for it by reference still get it (so a stale page shows "unavailable", not a 404), lists do not.
        if (! $directPaymentRef && ! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }
```

### 3.4 `saveDirectPayment()` — the fixes

All changes below are inside `DirectPaymentsController::saveDirectPayment()` (:332–614), in order. The controller's
constructor gains `DirectPaymentArchiver $archiver` and `SwapCompatibility $compatibility` alongside its existing
dependencies.

**(a) Archived DPs are read-only** — right after `firstOrNew`:

```php
        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §3.4(a).
        if ($directPayment->exists && $directPayment->isArchived()) {
            return response()->json([
                'error' => 'This Direct Payment is archived. Unarchive it to change it.',
                'code' => 'DP_E_ARCHIVED',
            ], 409);
        }
```

**(b) `pay_type` cannot change once money has moved (A16)** — after the pay_type validation:

```php
        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §3.4(b) (A16). Switching SUBSCRIPTION ↔ SINGLE_PAY ↔ CONNECT on a
        // DP with invoices or subscriptions orphaned its items or tiers. Create a new Direct Payment for a different kind
        // of selling.
        if ($directPayment->exists && $directPayment->pay_type !== $payType && $this->archiver->hasMoneyHistory($ref)) {
            return response()->json([
                'error' => "This Direct Payment has payments as {$directPayment->pay_type}; its type cannot change. Create a new Direct Payment instead.",
                'code' => 'DP_E_PAY_TYPE_LOCKED',
            ], 409);
        }
```

**(c) The processor column is never blanked (A11)** — replaces line 382
(`$directPayment->processor_reference_num = $procRef;`):

```php
        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §3.4(c) (A11). A save that names no processor used to write ''
        // here, and the SDK — which reads this column — stopped charging. guardProcessorChange() above has already
        // refused a DIFFERENT processor, so a non-empty value is the current one or a first one.
        if ($procRef !== '') {
            $directPayment->processor_reference_num = $procRef;
        } elseif (! $directPayment->exists) {
            $directPayment->processor_reference_num = '';
        }
```

**(d) A new DP's first processor is checked like a swap target** — the `guardConnectPayType()` call (:375–379) is
replaced by the shared compatibility check of  §4.1, run only when this save sets a
DP's **first** processor:

```php
        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §3.4(d) (A12). One rule set for the first processor and for a
        // swap: archived / paused targets and the Connect rules are refused the same way on both paths.
        if ($procRef !== '' && $this->bindings->active($ref) === null) {
            $target = Processor::where('reference_num', $procRef)->first();
            if ($target === null) {
                return response()->json(['error' => 'The selected processor could not be found.', 'code' => 'DP_E_PROCESSOR_NOT_FOUND'], 422);
            }
            // $payType is the type being saved now (the model still holds the old one on an edit).
            $blocker = $this->compatibility->firstBlocker($ref, $payType, null, $target, 'initial');
            if ($blocker !== null) {
                return response()->json(['error' => $blocker['message'], 'code' => $blocker['code']], 422);
            }
        }
```

`guardConnectPayType()` is deleted; its four codes (`DP_E_PROCESSOR_NOT_FOUND`, `DP_E_CONNECT_PROVIDER_UNSUPPORTED`,
`DP_E_CONNECT_TARGET_NOT_ENABLED`, `DP_E_CONNECT_PROVIDER_CHANGE`, `DP_E_CONNECT_ACCOUNTS_PRESENT`) are kept, emitted by
`SwapCompatibility`, so the admin form and existing tests see the same codes.

**(e) Binding failure is no longer silent for an archived processor** — the `ensureActive()` catch (:400–408):

```php
            try {
                $this->bindings->ensureActive($ref, $procRef, OperatorIdentity::from($request), 'initial');
            } catch (\App\Externals\DirectPayments\DirectPaymentException $e) {
                if ($e->getMessage() === 'DP_E_PROCESSOR_ARCHIVED') {
                    return response()->json(['error' => 'The selected processor is archived.', 'code' => 'DP_E_PROCESSOR_ARCHIVED'], 409);
                }
                Log::warning('[DirectPayments][Binding] Could not bind processor', [ /* unchanged */ ]);
            } catch (\Throwable $e) {
                Log::warning('[DirectPayments][Binding] Could not bind processor', [ /* unchanged */ ]);
            }
```

(Unreachable in practice after (d), kept as the data-layer backstop.)

**(f) Items are upserted and archived, never deleted (A17)** — replaces :430–447:

```php
        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §3.4(f) (A17).
        if ($payType !== 'SINGLE_PAY') {
            // Only reachable for a DP with no money history — (b) refuses a type change otherwise.
            DirectPaymentItem::where('direct_payment_reference_num', $ref)->whereNull('archived_at')->update(['archived_at' => now()]);
        } elseif ($request->has('items')) {
            if ($error = DB::transaction(fn () => $this->syncItems($ref, (array) $items))) {
                return $error;
            }
        }
```

```php
    /**
     * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §3.4(f) (A17).
     *
     * Was: delete every item and recreate them from the request — every item got a new id on every save, and a second
     * blank reference_num violated the unique index after the delete had already run. Now: upsert by reference_num
     * (issued by us when the client sends none), refuse a reference that belongs to another Direct Payment, and archive
     * the items the payload no longer lists. Field values are taken exactly as before.
     */
    private function syncItems(string $dpRef, array $items): ?\Illuminate\Http\JsonResponse
    {
        $kept = [];

        foreach ($items as $item) {
            $itemRef = trim((string) ($item['reference_num'] ?? ''));

            if ($itemRef === '') {
                $itemRef = DirectPaymentReference::item();
            } else {
                $owner = DirectPaymentItem::where('reference_num', $itemRef)->value('direct_payment_reference_num');
                if ($owner !== null && $owner !== $dpRef) {
                    return response()->json([
                        'error' => "Item {$itemRef} belongs to another Direct Payment.",
                        'code' => 'DP_E_ITEM_REFERENCE_TAKEN',
                    ], 422);
                }
            }

            DirectPaymentItem::updateOrCreate(
                ['reference_num' => $itemRef],
                [
                    'direct_payment_reference_num' => $dpRef,
                    'name'       => $item['name'] ?? '',
                    'price'      => $item['price'] ?? 0,
                    'tax'        => $item['tax'] ?? 0,
                    'sku'        => $item['sku'] ?? null,
                    'price_type' => $item['price_type'] ?? 'FIXED',
                    'min_amount' => isset($item['min_amount']) && $item['min_amount'] !== '' ? $item['min_amount'] : null,
                    'max_amount' => isset($item['max_amount']) && $item['max_amount'] !== '' ? $item['max_amount'] : null,
                    'archived_at' => null,
                ]
            );
            $kept[] = $itemRef;
        }

        DirectPaymentItem::where('direct_payment_reference_num', $dpRef)
            ->whereNull('archived_at')
            ->when($kept !== [], fn ($q) => $q->whereNotIn('reference_num', $kept))
            ->update(['archived_at' => now()]);

        return null;
    }
```

**(g) Tiers are archived, not deleted, and a save without `tiers` leaves them alone (A18)** — the tier block (:468–531):

1. Wrap the whole block in `if ($request->has('tiers')) { … }`. Today a save that omits `tiers` (e.g. an items-only
   save of a SUBSCRIPTION DP) has `$tiers = []`, and `whereNotIn('reference_num', [])` deletes **every** tier.
2. Replace the delete (:488–490):

```php
            // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §3.4(g) (A18). A tier dropped from the payload is archived:
            // subscriptions pinned to it keep resolving their tier and level, and update() keeps working for them.
            DirectPaymentTier::where('direct_payment_reference_num', $ref)
                ->whereNull('archived_at')
                ->whereNotIn('reference_num', $incomingRefs)
                ->update(['archived_at' => now()]);
```

3. In the `updateOrCreate` values add `'archived_at' => null,` — re-sending an archived tier's reference restores it.
   The existing `DP_E_UNKNOWN_TIER_REFERENCE` check already accepts archived references because it reads every tier row
   of the DP.

**(h) Transaction.** Items and tiers are each written in `DB::transaction()` (items above; tiers by wrapping the block), so
a validation failure half-way leaves the previous catalogue intact.

### 3.5 Item endpoints and invoices on an archived DP

| Endpoint | Change |
|---|---|
| `storeItem()` | archived DP → `409 DP_E_ARCHIVED`; a client `reference_num` that already exists (on any DP) → `422 DP_E_ITEM_REFERENCE_TAKEN` (today the unique index throws a 500); blank → `DirectPaymentReference::item()` |
| `updateItem()` | archived DP or archived item → `409` (`DP_E_ARCHIVED` / `DP_E_ITEM_ARCHIVED`) |
| `indexItems()` | `->whereNull('archived_at')` unless `?include_archived=1` |
| `showItem()` | unchanged (returns archived items, with `archived_at`) — modules resolve history through it |
| `saveInvoice()` | the invoice's DP is archived → `409 DP_E_ARCHIVED` (no new invoices). Re-saving an **existing** invoice (status updates during a checkout that started before the archive) is still accepted |

### 3.6 Acceptance

- Delete a never-used DP → deleted, `archived: false`. Delete a DP with one invoice → `archived: true`, row present,
  `archived_*` set, audit row; its invoices still read and refund.
- An archived DP refuses `saveDirectPayment`, new invoices, `storeItem`; `unarchive` restores it.
- Change `pay_type` on a DP with an invoice → `409 DP_E_PAY_TYPE_LOCKED`; on a DP with none → allowed.
- Save with no `processor_reference_num` on a bound DP → column unchanged (A11 regression).
- Save items twice with the same payload → same item ids and references; an item dropped from the payload → archived,
  still found by `showItem` and by appointments/events lookups; two blank references → two distinct issued references.
- Item reference of another DP → `422`, nothing written.
- Save without a `tiers` key → tiers unchanged; tier dropped from the payload → archived, subscriptions on it still
  `update()`; re-sending its reference → restored.
- A new DP with an archived processor → `422 SWAP_E_TARGET_ARCHIVED`; with a PayPal processor and `pay_type = CONNECT` →
  `422 DP_E_CONNECT_PROVIDER_UNSUPPORTED` (same code as today).

---

## Step 4 — Swap Safety and Go Live

Fixes **A12, A13, A14, A15, A19**; applies **OQ5** (live → sandbox refused; sandbox → live only through Go live) and
**OQ6** (strict Connect rule).

### 4.1 `SwapCompatibility` — one rule set for a first processor, a change, and Go live

`app/Externals/DirectPayments/SwapCompatibility.php` — new. Called by `saveDirectPayment()` for a DP's first processor
( §3.4(d)) and by `SwapService` for a change or Go live. `guardConnectPayType()`
and `SwapService::blockersFor()` are deleted; their codes and messages live here.

```php
<?php

namespace App\Externals\DirectPayments;

use App\Models\Externals\DirectPaymentInvoice;
use App\Models\Externals\Processor\Processor;
use App\Models\Externals\Processor\ProcessorMigration;
use App\Services\Marketplace\ConnectProfileService;

/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §4.1
 * (A12, A14, A15, OQ5, OQ6). plans/www/direct_payments_audit/06-swap-engine-v2.md §6.4.
 *
 * Whether a Direct Payment may be put on $target. One place, so the first-processor path and the swap path can never
 * disagree again (A12 was exactly that disagreement).
 *
 * $kind: 'initial' (a DP's first processor) | 'change' (processor change) | 'go_live' (sandbox → live).
 * PA1 replaces the publishable-key comparison with the account fingerprint; PA4 adds provisioning and portability.
 */
final class SwapCompatibility
{
    public function __construct(private readonly ConnectProfileService $connectProfiles)
    {
    }

    /** @return list<array{code: string, message: string}> */
    public function blockers(string $dpRef, string $payType, ?Processor $current, Processor $target, string $kind): array
    {
        $out = [];
        $add = function (string $code, string $message) use (&$out): void {
            $out[] = ['code' => $code, 'message' => $message];
        };

        // ── Target state ────────────────────────────────────────────────────────────────────────────────────
        if ($target->isArchived()) {
            $add('SWAP_E_TARGET_ARCHIVED', "'{$target->name}' is archived. Unarchive it first, or choose another processor.");
        } elseif (! $target->is_active) {
            $add('SWAP_E_TARGET_PAUSED', "'{$target->name}' is paused.");
        }

        if ($kind !== 'initial') {
            if ($current === null) {
                $add('DP_E_NO_ACTIVE_BINDING', 'This Direct Payment has no processor to change from.');
                return $out;
            }
            if ($current->reference_num === $target->reference_num) {
                $add('SWAP_E_ALREADY_ACTIVE', "'{$target->name}' is already this Direct Payment's processor.");
            }
            if (! $target->getSecretKey()) {
                $add('SWAP_E_TARGET_UNVERIFIED', "'{$target->name}' has no secret key, so it cannot take payments.");
            }

            // ── Same account (key rotation, not a change) — publishable-key heuristic until PA1 ──────────────
            if ($kind === 'change' && $current->provider === $target->provider
                && $current->publishable_key !== '' && $current->publishable_key === $target->publishable_key) {
                $add('SWAP_E_SAME_ACCOUNT', 'This is the same account with different keys. Update the keys on the current processor instead of changing processor.');
            }

            // ── Mode (OQ5) ───────────────────────────────────────────────────────────────────────────────────
            if ($kind === 'change' && $current->mode !== $target->mode) {
                $current->mode === 'live'
                    ? $add('SWAP_E_MODE_LIVE_TO_SANDBOX', 'A live Direct Payment can\'t be moved to a sandbox processor — it would stop taking real payments. Create a separate Direct Payment for testing.')
                    : $add('SWAP_E_MODE_USE_GO_LIVE', 'This Direct Payment uses a sandbox processor. Use Go live to switch it to a live processor.');
            }
            if ($kind === 'go_live' && ! ($current->mode === 'sandbox' && $target->mode === 'live')) {
                $add('SWAP_E_GO_LIVE_NOT_APPLICABLE', 'Go live moves a Direct Payment from a sandbox processor to a live one.');
            }

            // ── Webhooks (A15) ───────────────────────────────────────────────────────────────────────────────
            if (! $this->webhookReady($target)) {
                $add('SWAP_E_WEBHOOK_NOT_READY', "'{$target->name}' has no webhook set up. Without it, payments and renewals on it are never recorded. Register its webhook first.");
            }

            // ── Currency (moved unchanged from SwapService::blockersFor) ─────────────────────────────────────
            $from = $this->currency($current);
            $to = $this->currency($target);
            if ($from !== '' && $to !== '' && $from !== $to) {
                $add('SWAP_E_CURRENCY', "Currency mismatch: this Direct Payment is priced in {$from}, '{$target->name}' is configured for {$to}.");
            }
        }

        // ── Connect (OQ6 — strict) ───────────────────────────────────────────────────────────────────────────
        foreach ($this->connectBlockers($dpRef, $payType, $current, $target, $kind) as $b) {
            $out[] = $b;
        }

        return $out;
    }

    /** @return array{code: string, message: string}|null */
    public function firstBlocker(string $dpRef, string $payType, ?Processor $current, Processor $target, string $kind): ?array
    {
        return $this->blockers($dpRef, $payType, $current, $target, $kind)[0] ?? null;
    }

    /** Shown, not blocking. @return string[] */
    public function warnings(string $dpRef, string $payType, ?Processor $current, Processor $target, string $kind): array
    {
        $out = [];
        if ($kind === 'initial' && ! $this->webhookReady($target)) {
            $out[] = "'{$target->name}' has no webhook set up yet; register it before taking payments.";
        }
        if ($kind === 'go_live' && $current !== null && $current->provider === 'stripe') {
            $accounts = $current->connectedAccounts()->whereNull('closed_at')->count();
            if ($accounts > 0) {
                $out[] = "{$accounts} sandbox Connect account(s) stay on the sandbox processor; sellers onboard again on the live one.";
            }
        }
        return $out;
    }

    /**
     * Strict rule (OQ6): a DP is Stripe-only when it sells through Connect, has split payments, or sits on a processor
     * with Connect enabled. Messages and codes are guardConnectPayType()'s, unchanged.
     *
     * @return list<array{code: string, message: string}>
     */
    private function connectBlockers(string $dpRef, string $payType, ?Processor $current, Processor $target, string $kind): array
    {
        $dpUsesConnect = $payType === 'CONNECT'
            || DirectPaymentInvoice::where('direct_payment_reference_num', $dpRef)->where('is_split', true)->exists();
        $processorHasConnect = $current !== null && $current->provider === 'stripe' && $this->connectProfiles->for($current)->enabled;

        if (! $dpUsesConnect && ! $processorHasConnect) {
            return [];
        }

        $out = [];

        // Provider first, so extra_config cannot fake Connect on PayPal or Square (A7).
        if ($target->provider !== 'stripe') {
            $out[] = $current !== null && $current->provider === 'stripe'
                ? ['code' => 'DP_E_CONNECT_PROVIDER_CHANGE', 'message' => "This Direct Payment uses Stripe Connect, which exists only on Stripe. It can only move to another Stripe processor, not {$target->provider}."]
                : ['code' => 'DP_E_CONNECT_PROVIDER_UNSUPPORTED', 'message' => "Connect requires a Stripe processor; '{$target->name}' uses {$target->provider}."];
            return $out;
        }

        if ($dpUsesConnect && ! $this->connectProfiles->for($target)->enabled) {
            $out[] = ['code' => 'DP_E_CONNECT_TARGET_NOT_ENABLED', 'message' => "Connect is not enabled on processor '{$target->name}'. Enable it in that processor's Connect Settings first."];
        }

        // Sellers' Connect accounts exist only on the Stripe platform that created them.
        if ($dpUsesConnect && $kind === 'change' && $current !== null && $current->provider === 'stripe') {
            $accounts = $current->connectedAccounts()->whereNull('closed_at')->count();
            $migrated = ProcessorMigration::where('from_processor_reference_num', $current->reference_num)
                ->where('to_processor_reference_num', $target->reference_num)
                ->whereNotNull('completed_at')   // the migration tables (market step 08 §08.5.8) were never wired; no status vocabulary exists yet, completed_at is the only completion signal
                ->whereNull('aborted_at')
                ->exists();
            if ($accounts > 0 && ! $migrated) {
                $out[] = ['code' => 'DP_E_CONNECT_ACCOUNTS_PRESENT', 'message' => "{$accounts} connected account(s) exist on the current processor; moving them to another Stripe platform is not yet supported."];
            }
        }

        return $out;
    }

    /**
     * Ready = the webhook handler can verify this processor's events: Stripe and Square by the signing secret,
     * PayPal by the webhook id (ProcessorWebhookController::authenticate*Webhook read exactly these).
     */
    private function webhookReady(Processor $processor): bool
    {
        return match ($processor->provider) {
            'paypal' => ! empty(($processor->extra_config ?? [])['webhook_id'] ?? null),
            default => ! empty($processor->getWebhookSecret()),
        };
    }

    private function currency(Processor $processor): string
    {
        $config = is_array($processor->extra_config) ? $processor->extra_config : [];
        foreach (['currency', 'default_currency'] as $key) {
            if (! empty($config[$key]) && is_string($config[$key])) {
                return strtolower(trim($config[$key]));
            }
        }
        return '';
    }
}
```

### 4.2 `SwapService` — uses the check, runs atomically, records who

`app/Externals/DirectPayments/SwapService.php`:

- Constructor gains `SwapCompatibility $compatibility` (keeps `BindingRegistry`; `ConnectProfileService` moves into
  `SwapCompatibility`).
- `preview(DirectPayment $dp, string $targetRef, string $changeKind = 'change')`:
  - archived DP → `['error' => ['message' => 'This Direct Payment is archived.', 'code' => 'DP_E_ARCHIVED']]`;
  - `$blockers = $this->compatibility->blockers($dp->reference_num, $dp->pay_type, $current, $target, $changeKind)`;
  - the response keeps `'blocking'` as a list of **messages** (today's shape — existing tests and any caller keep
    working) and adds `'blocking_codes'`, `'warnings'` and `'change'` (`change` | `go_live`);
  - `impact_hash` additionally includes `$changeKind`;
  - `kindOf()` and `processorCurrency()` stay (the latter now private in `SwapCompatibility`; `kindOf` is still the
    preview's provider-kind label).
- `consequences()` gains a Go live branch:

```php
        if ($changeKind === 'go_live') {
            return array_values(array_filter([
                'Live payments start immediately after you confirm. Your website code and references stay the same.',
                $impact['live_subscriptions'] > 0
                    ? "{$impact['live_subscriptions']} test subscription(s) on the sandbox processor will be ended. They are test data."
                    : null,
                'Test payments stay in the sandbox history and are not carried over.',
            ]));
        }
```

- `execute()` becomes:

```php
    /**
     * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §4.2 (A13, A19).
     * One transaction, with the Direct Payment row locked: two operators changing the same DP at once serialise, and
     * the preview the hash is checked against is the one computed under the lock.
     */
    public function execute(DirectPayment $dp, string $targetProcessorRef, string $acknowledgedHash, ?string $note, string $actor, string $changeKind = 'change'): array
    {
        return DB::transaction(function () use ($dp, $targetProcessorRef, $acknowledgedHash, $note, $actor, $changeKind) {
            $locked = DirectPayment::query()->whereKey($dp->getKey())->lockForUpdate()->firstOrFail();

            $result = $this->preview($locked, $targetProcessorRef, $changeKind);
            if (isset($result['error'])) {
                return $result;
            }
            $preview = $result['preview'];

            if (! empty($preview['blocking'])) {
                return ['error' => [
                    'message' => 'This change cannot proceed: ' . implode(' ', $preview['blocking']),
                    'code' => 'DP_E_SWAP_BLOCKED',
                    'blocking_codes' => $preview['blocking_codes'],
                ]];
            }
            if (! hash_equals($preview['impact_hash'], $acknowledgedHash)) {
                return ['error' => [
                    'message' => 'What this change affects has changed since you last saw it. Review the new preview before confirming.',
                    'code' => 'DP_E_IMPACT_CHANGED',
                    'preview' => $preview,
                ]];
            }

            try {
                $binding = $this->bindings->swap($locked->reference_num, $targetProcessorRef, $actor, $note, $changeKind);
            } catch (DirectPaymentException $e) {
                return ['error' => ['message' => 'This change cannot proceed.', 'code' => $e->getMessage()]];
            }

            DirectPaymentAuditLog::record('direct_payment', $locked->reference_num,
                $binding->wasReactivated ? 'revert' : $changeKind, $actor, $note, [
                    'from' => $preview['from']['processor_reference_num'] ?? null,
                    'to' => $targetProcessorRef,
                    'impact' => $preview['impact'],
                ]);

            return ['binding' => $binding, 'preview' => $preview];
        });
    }
```

### 4.3 `BindingRegistry` — validate first, then move; reuse a retiring row on revert

`app/Externals/DirectPayments/BindingRegistry.php`:

```php
    /**
     * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §4.3 (A13).
     *
     * Was: retire the current binding FIRST, then look up the target and throw if missing — leaving the DP with no
     * active binding (every checkout failing). Now: the target is validated before anything is written, and the whole
     * move is one transaction (nested safely inside SwapService::execute()'s).
     *
     * Revert (06-swap-engine-v2.md §6.7): when the target already has a RETIRING binding on this DP, that row becomes
     * active again instead of a duplicate row being created. $binding->wasReactivated tells the caller.
     */
    public function swap(string $directPaymentReferenceNum, string $newProcessorReferenceNum, ?string $boundBy = null, ?string $note = null, string $kind = 'change'): DirectPaymentProcessorBinding
    {
        return DB::transaction(function () use ($directPaymentReferenceNum, $newProcessorReferenceNum, $boundBy, $note, $kind) {
            $processor = Processor::where('reference_num', $newProcessorReferenceNum)->first();
            if ($processor === null) {
                throw new DirectPaymentException('DP_E_PROCESSOR_NOT_FOUND');
            }
            if ($processor->isArchived()) {
                throw new DirectPaymentException('DP_E_PROCESSOR_ARCHIVED');
            }

            $current = DirectPaymentProcessorBinding::where('active_marker', $directPaymentReferenceNum)->lockForUpdate()->first();
            if ($current !== null && $current->processor_reference_num === $newProcessorReferenceNum) {
                throw new DirectPaymentException('SWAP_E_ALREADY_ACTIVE');
            }

            $returning = DirectPaymentProcessorBinding::where('direct_payment_reference_num', $directPaymentReferenceNum)
                ->where('processor_reference_num', $newProcessorReferenceNum)
                ->where('status', 'retiring')
                ->lockForUpdate()
                ->first();

            // Clear the marker first: active_marker is unique, so the new/returning row can only take it afterwards.
            $current?->update(['status' => 'retiring', 'retiring_at' => now(), 'active_marker' => null]);

            if ($returning !== null) {
                $returning->update(['status' => 'active', 'retiring_at' => null, 'active_marker' => $directPaymentReferenceNum]);
                $binding = $returning;
                $binding->wasReactivated = true;
            } else {
                $binding = DirectPaymentProcessorBinding::create([
                    'direct_payment_reference_num' => $directPaymentReferenceNum,
                    'processor_reference_num' => $newProcessorReferenceNum,
                    'provider' => $processor->provider,
                    'mode' => $processor->mode,
                    'status' => 'active',
                    'kind' => $kind,
                    'bound_at' => now(),
                    'bound_by' => $boundBy,
                    'note' => $note,
                    'active_marker' => $directPaymentReferenceNum,
                ]);
                $binding->wasReactivated = false;
            }

            DirectPayment::where('reference_num', $directPaymentReferenceNum)
                ->update(['processor_reference_num' => $newProcessorReferenceNum]);

            return $binding;
        });
    }
```

`ensureActive()` gains `string $kind = 'initial'` (written to the new row) and the archived refusal of
 §2.5. `DirectPaymentProcessorBinding` gains `'kind'` in `$fillable` and a
non-persisted `public bool $wasReactivated = false;`.

`canRetire()` gains the open-disputes blocker ( §6.4).

### 4.4 Controller and routes

`DirectPaymentSwapController::swap()` — the actor comes from the login, not the body (A19):

```php
        $result = $this->swaps->execute(
            $dp,
            $targetRef,
            $acknowledged,
            $request->input('note'),
            OperatorIdentity::from($request),   // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §4.4 (A19). Was $request->input('bound_by').
        );
```

The status mapping adds `'DP_E_ARCHIVED' => 409`, `'DP_E_PROCESSOR_ARCHIVED' => 409`, `'SWAP_E_ALREADY_ACTIVE' => 409`.

Two Go live actions, same shape as preview/swap:

```php
    /** POST /portal/api/v1/directpayments/{dpRef}/go-live/preview  {processor_reference_num} */
    public function goLivePreview(Request $request, string $dpRef): JsonResponse
    {
        $dp = DirectPayment::where('reference_num', $dpRef)->first();
        if ($dp === null) {
            return response()->json(['success' => false, 'message' => 'Direct Payment not found.'], 404);
        }
        $result = $this->swaps->preview($dp, (string) $request->input('processor_reference_num', ''), 'go_live');

        return isset($result['error'])
            ? response()->json(['success' => false, 'error' => $result['error']], 422)
            : response()->json(['success' => true, 'data' => $result['preview']]);
    }

    /** POST /portal/api/v1/directpayments/{dpRef}/go-live  {processor_reference_num, acknowledged, note?} */
    public function goLive(Request $request, string $dpRef, GoLiveService $goLive): JsonResponse
    {
        $dp = DirectPayment::where('reference_num', $dpRef)->first();
        if ($dp === null) {
            return response()->json(['success' => false, 'message' => 'Direct Payment not found.'], 404);
        }
        $result = $goLive->execute($dp, (string) $request->input('processor_reference_num', ''),
            (string) $request->input('acknowledged', ''), $request->input('note'), OperatorIdentity::from($request));

        return isset($result['error'])
            ? response()->json(['success' => false, 'error' => $result['error']], $result['error']['code'] === 'DP_E_IMPACT_CHANGED' ? 409 : 422)
            : response()->json(['success' => true, 'data' => $result]);
    }
```

`routes/portal.php`, beside the swap routes:

```php
        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §4.4 (OQ5).
        Route::post('/{dpRef}/go-live/preview', [DirectPaymentSwapController::class, 'goLivePreview'])->middleware('externals.dashboard');
        Route::post('/{dpRef}/go-live', [DirectPaymentSwapController::class, 'goLive'])->middleware('externals.dashboard');
```

### 4.5 `GoLiveService`

`app/Externals/DirectPayments/GoLiveService.php` — new.

```php
<?php

namespace App\Externals\DirectPayments;

use App\Externals\DirectPayments\Subscriptions\SubscriptionActionsFactory;
use App\Models\Externals\DirectPayment;
use App\Models\Externals\DirectPaymentAuditLog;
use App\Models\Externals\DirectPaymentProcessorBinding;
use App\Models\Externals\DirectPaymentSubscription;
use App\Models\Externals\Processor\Processor;
use App\Models\Externals\Processor\ProcessorSubscription;
use Illuminate\Support\Facades\Log;

/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §4.5 (OQ5).
 *
 * Sandbox → live on the same Direct Payment reference, so the website changes nothing. It is a processor change with
 * kind 'go_live' (the same checks, the same atomic move), followed by ending the sandbox subscriptions — they are test
 * data, and a test subscriber must not look entitled on the live site. Ending them is best-effort and reported: the
 * sandbox provider failing to cancel never undoes Go live.
 */
final class GoLiveService
{
    public function __construct(
        private readonly SwapService $swaps,
        private readonly SubscriptionActionsFactory $actions,
    ) {
    }

    public function execute(DirectPayment $dp, string $liveProcessorRef, string $acknowledgedHash, ?string $note, string $actor): array
    {
        $sandboxRef = DirectPaymentProcessorBinding::where('active_marker', $dp->reference_num)->value('processor_reference_num');

        $result = $this->swaps->execute($dp, $liveProcessorRef, $acknowledgedHash, $note, $actor, 'go_live');
        if (isset($result['error'])) {
            return $result;
        }

        $ended = $this->endSandboxSubscriptions($dp->reference_num, (string) $sandboxRef);
        DirectPaymentAuditLog::record('direct_payment', $dp->reference_num, 'go_live_subscriptions_ended', $actor, null, $ended);

        return ['binding' => $result['binding'], 'ended_test_subscriptions' => $ended];
    }

    /** @return array{ended: int, provider_failures: list<string>} */
    private function endSandboxSubscriptions(string $dpRef, string $sandboxRef): array
    {
        $sandbox = Processor::where('reference_num', $sandboxRef)->first();
        $onlyEverSandbox = DirectPaymentProcessorBinding::where('direct_payment_reference_num', $dpRef)->count() === 2;

        $links = DirectPaymentSubscription::where('direct_payment_reference_num', $dpRef)
            ->whereNull('canceled_at')
            ->where(function ($q) use ($sandboxRef, $onlyEverSandbox) {
                $q->where('processor_reference_num', $sandboxRef);
                if ($onlyEverSandbox) {
                    // Rows written before subscriptions recorded their processor: on a DP whose only previous
                    // processor was this sandbox one, they can only be sandbox subscriptions.
                    $q->orWhereNull('processor_reference_num');
                }
            })
            ->get();

        $failures = [];
        foreach ($links as $link) {
            $subscription = ProcessorSubscription::where('processor_reference_num', $sandboxRef)
                ->where('provider_subscription_id', $link->provider_subscription_id)
                ->first();
            if ($sandbox !== null && $subscription !== null && $subscription->status !== 'canceled') {
                try {
                    $this->actions->for($sandbox->provider)->cancel($sandbox, $subscription);
                } catch (\Throwable $e) {
                    $failures[] = (string) $link->reference_num;
                    Log::warning('[DirectPayments][GoLive] Sandbox subscription not cancelled at the provider', [
                        'direct_payment_reference_num' => $dpRef,
                        'subscription_reference_num' => $link->reference_num,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $link->update(['canceled_at' => now(), 'access_ends_at' => now()]);
        }

        return ['ended' => $links->count(), 'provider_failures' => $failures];
    }
}
```

`endSandboxSubscriptions` runs after the swap transaction committed — a slow sandbox API never holds the lock.

### 4.6 `AbstractWebhookRegistrar` — nothing needed

The readiness check reads exactly what the webhook handler verifies with (§4.1 `webhookReady()`), so the registrar
interface does not change in PA0.

### 4.7 Acceptance

- Both paths refuse the same things: a CONNECT DP onto PayPal (`saveDirectPayment` → `DP_E_CONNECT_PROVIDER_UNSUPPORTED`;
  swap → `DP_E_CONNECT_PROVIDER_CHANGE`); a DP with live connected accounts onto another Stripe platform
  (`DP_E_CONNECT_ACCOUNTS_PRESENT`) unless a completed processor migration exists; a PayPal processor with
  `extra_config.connect.enabled = true` gets no further than the provider check.
- A non-Connect DP on a Stripe processor with Connect enabled cannot move to PayPal or Square (OQ6 strict).
- Live → sandbox refused (`SWAP_E_MODE_LIVE_TO_SANDBOX`); sandbox → live by `swap` refused with
  `SWAP_E_MODE_USE_GO_LIVE`; by `go-live` succeeds, binding `kind = go_live`, test subscriptions ended, DP reference
  unchanged.
- Target without a webhook secret / PayPal webhook id → `SWAP_E_WEBHOOK_NOT_READY`.
- A swap whose target is archived is refused before any write; a forced exception inside `swap()` leaves the DP's
  active binding untouched (A13).
- A → B → A: the second move reactivates A's row (`wasReactivated`, audit action `revert`); the DP has exactly two
  binding rows.
- `bound_by` and the audit actor are the logged-in operator, whatever the body says.

---

## Step 5 — Subscriptions and Webhooks

Fixes **A32, A33, A36, A55, A56**, and **A37** where PA0 touches a lookup.

### 5.1 The subscription link endpoint (A32, A33)

`DirectPaymentSubscriptionController::store()` (:79–131). Today it runs
`updateOrCreate([dp, provider_subscription_id], ['reference_num' => Str::random(12), 'direct_payment_tier_reference_num' => request, …])`
— every call **regenerates the subscription's reference** and **overwrites its tier with whatever the caller sent**, on
an endpoint with no authentication.

The endpoint keeps its contract (always `success: true` once money has moved; a problem is a `warning`, adopted later
by `direct_payments:backfill-subscriptions`). What changes is what it is allowed to write. The `try { … }` body becomes:

```php
            /**
             * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §5.1
             * (A32, A33, A37).
             *
             * 1. The provider subscription is looked up only on processors this Direct Payment has been bound to (A37) —
             *    a caller cannot attach a subscription that belongs to another processor.
             * 2. An existing link is never re-keyed (A32) and its tier is never replaced (A33); blanks may be filled.
             * 3. A tier is accepted only when the subscription's actual plan is that tier's price on that processor.
             */
            $processorRefs = DirectPaymentProcessorBinding::where('direct_payment_reference_num', $dpRef)
                ->pluck('processor_reference_num')
                ->push($directPayment->processor_reference_num)
                ->filter()->unique()->values();

            $processorSubscription = ProcessorSubscription::where('provider_subscription_id', $providerSubscriptionId)
                ->whereIn('processor_reference_num', $processorRefs)
                ->first();

            if ($processorSubscription === null) {
                // Not (yet) known on this DP's processors — the backfill adopts it once the provider row exists.
                return response()->json(['success' => true, 'warning' => 'subscription_unlinked', 'code' => 'DP_E_SUBSCRIPTION_UNKNOWN']);
            }

            $tierRef = (string) $request->input('tier_reference_num', '');
            $tierVerified = $tierRef === '' ? null : $this->tierMatchesSubscription($dpRef, $tierRef, $processorSubscription);
            if ($tierVerified === false) {
                Log::warning('[DirectPayments][Subscription] Tier does not match the subscription\'s plan; not recorded', [
                    'direct_payment_reference_num' => $dpRef,
                    'provider_subscription_id' => $providerSubscriptionId,
                    'tier_reference_num' => $tierRef,
                ]);
            }

            $payerReferenceNum = $this->resolvePayerReferenceNum($request, $providerSubscriptionId, $identityResolver);

            $link = DirectPaymentSubscription::where('direct_payment_reference_num', $dpRef)
                ->where('provider_subscription_id', $providerSubscriptionId)
                ->first();

            if ($link === null) {
                try {
                    $link = DirectPaymentSubscription::create([
                        'reference_num' => Str::random(12),
                        'direct_payment_reference_num' => $dpRef,
                        'provider_subscription_id' => $providerSubscriptionId,
                        'direct_payment_tier_reference_num' => $tierVerified === true ? $tierRef : null,
                        'invoice_reference_num' => $request->input('invoice_reference_num'),
                        'processor_reference_num' => $processorSubscription->processor_reference_num,
                        'payer_reference_num' => $payerReferenceNum,
                    ]);
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    // Two calls raced; the other one created it. Fall through to the fill-blanks path.
                    $link = DirectPaymentSubscription::where('direct_payment_reference_num', $dpRef)
                        ->where('provider_subscription_id', $providerSubscriptionId)->firstOrFail();
                }
            }

            // Fill blanks only — never replace a value already recorded.
            $fill = array_filter([
                'direct_payment_tier_reference_num' => $link->direct_payment_tier_reference_num === null && $tierVerified === true ? $tierRef : null,
                'invoice_reference_num' => $link->invoice_reference_num === null ? $request->input('invoice_reference_num') : null,
                'processor_reference_num' => $link->processor_reference_num === null ? $processorSubscription->processor_reference_num : null,
                'payer_reference_num' => $link->payer_reference_num === null ? $payerReferenceNum : null,
            ], fn ($v) => $v !== null && $v !== '');
            if ($fill !== []) {
                $link->update($fill);
            }

            \App\Externals\DirectPayments\SubscriptionInvoiceLinker::adoptOrphans($link);

            $response = ['success' => true, 'data' => $link->fresh()];
            if ($tierVerified === false) {
                $response['warning'] = 'tier_not_recorded';
                $response['code'] = 'DP_E_TIER_PRICE_MISMATCH';
            }

            return response()->json($response);
```

New imports in that controller: `App\Models\Externals\DirectPaymentProcessorBinding`,
`App\Models\Externals\DirectPaymentTierBinding`, `App\Models\Externals\Processor\ProcessorPlan`.

The tier check:

```php
    /**
     * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §5.1 (A33).
     *
     * True when the provider subscription's plan is one of this tier's prices on that processor — any of its tier
     * bindings, active or retired (a subscriber can be on an older price), or, for a tier saved before tier bindings
     * existed, the tier's own processor_tier_id. A tier may be matched by ProcessorPlan id or by the provider's plan id,
     * the two forms ProcessorSubscriptionController::store() accepts as plan_id. Null plan → false (cannot verify).
     */
    private function tierMatchesSubscription(string $dpRef, string $tierRef, ProcessorSubscription $subscription): bool
    {
        $tier = DirectPaymentTier::where('reference_num', $tierRef)->where('direct_payment_reference_num', $dpRef)->first();
        $plan = $subscription->plan_id ? ProcessorPlan::find($subscription->plan_id) : null;
        if ($tier === null || $plan === null) {
            return false;
        }

        $planIds = array_filter([(string) $plan->id, (string) $plan->provider_plan_id]);

        $bound = DirectPaymentTierBinding::where('tier_reference_num', $tierRef)
            ->where('processor_reference_num', $subscription->processor_reference_num)
            ->pluck('processor_tier_id')
            ->push($tier->processor_tier_id)
            ->filter()
            ->map(fn ($v) => (string) $v);

        return $bound->intersect($planIds)->isNotEmpty();
    }
```

**Effect on a real checkout:** none. `ProcessorSubscriptionController::store()` writes the provider row with its
`plan_id` before the SDK calls this endpoint, and the SDK sends the tier the checkout priced from
(`_resolveCheckoutPlanId()` → `checkout-price` → the same tier binding). Only a mismatched or replayed call loses its
tier, and it is logged.

Consumers that read the tier from the link (e.g. appfactory's tier resolver) are unaffected for real checkouts; a forged
call can no longer raise a tier. No appfactory code changes.

### 5.2 A subscription ended at the provider ends here too (A36)

Every path that learns a provider subscription ended already writes `laravel_processor_subscriptions`: the Stripe,
Square and PayPal webhook handlers and `SubscriptionSyncService`. Instead of editing five call sites, one model observer
watches that table.

`app/Externals/DirectPayments/Subscriptions/ProcessorSubscriptionObserver.php` — new:

```php
<?php

namespace App\Externals\DirectPayments\Subscriptions;

use App\Models\Externals\Processor\ProcessorSubscription;

/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §5.2 (A36).
 *
 * When a provider subscription's status becomes ended — through any webhook or the sync sweep — mirror it onto the
 * Direct Payments subscription. Before this, only the app's own cancel button set canceled_at, so a subscription
 * cancelled in the provider's dashboard or ended by failed payments stayed "live" forever and blocked its processor
 * from ever being retired.
 */
final class ProcessorSubscriptionObserver
{
    public function __construct(private readonly SubscriptionEndSync $sync)
    {
    }

    public function saved(ProcessorSubscription $subscription): void
    {
        if (($subscription->wasRecentlyCreated || $subscription->wasChanged('status')) && SubscriptionEndSync::isEnded($subscription)) {
            $this->sync->providerEnded($subscription);
        }
    }
}
```

`app/Externals/DirectPayments/Subscriptions/SubscriptionEndSync.php` — new:

```php
<?php

namespace App\Externals\DirectPayments\Subscriptions;

use App\Externals\DirectPayments\Dunning\DunningPolicyResolver;
use App\Models\Externals\DirectPaymentSubscription;
use App\Models\Externals\Processor\ProcessorSubscription;
use Illuminate\Support\Facades\Log;

/** AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §5.2 (A36). */
final class SubscriptionEndSync
{
    /**
     * Normalised 'canceled' (Stripe, Square readers), or PayPal's raw terminal statuses — logPayPalSubscription() stores
     * PayPal's own string ('CANCELLED', 'EXPIRED') without normalising it.
     */
    public static function isEnded(ProcessorSubscription $subscription): bool
    {
        return in_array(strtolower((string) $subscription->status), ['canceled', 'cancelled', 'expired', 'incomplete_expired'], true);
    }

    public function __construct(private readonly DunningPolicyResolver $policy)
    {
    }

    public function providerEnded(ProcessorSubscription $subscription): void
    {
        $links = DirectPaymentSubscription::where('provider_subscription_id', $subscription->provider_subscription_id)
            ->where(function ($q) use ($subscription) {
                $q->where('processor_reference_num', $subscription->processor_reference_num)
                  ->orWhereNull('processor_reference_num');
            })
            ->whereNull('canceled_at')
            ->get();

        foreach ($links as $link) {
            // Same rule the app's own cancel uses (DirectPaymentSubscriptionController::cancel()): the DP's
            // cancellation-access policy decides; otherwise access runs to the end of the paid period.
            $accessEnds = $this->policy->cancellationAccessEnds($link->direct_payment_reference_num) === 'immediately'
                ? now()
                : ($subscription->current_period_end ?? $subscription->next_charge_at ?? now());

            $link->update([
                'canceled_at' => now(),
                'access_ends_at' => $link->access_ends_at ?? $accessEnds,
            ]);

            Log::info('[DirectPayments][Subscription] Ended at the provider; recorded', [
                'subscription_reference_num' => $link->reference_num,
                'provider_subscription_id' => $subscription->provider_subscription_id,
                'provider_status' => $subscription->provider_status ?? $subscription->status,
            ]);
        }
    }
}
```

Registration — `app/Providers/AppServiceProvider.php` `boot()`, next to the existing `Event::listen(SubscriptionPlanChanged …)`:

```php
        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §5.2 (A36).
        \App\Models\Externals\Processor\ProcessorSubscription::observe(
            \App\Externals\DirectPayments\Subscriptions\ProcessorSubscriptionObserver::class
        );
```

Notes:

- `SubscriptionSyncService` updates through `$subscription->update([...])` (Eloquent), so the observer fires for the
  sweep too. Any future write through the query builder would bypass it — gate G9 in step 8.
- Idempotent: links already cancelled (by the app's cancel, Brain staff tools, or a previous event) are skipped, and an
  `access_ends_at` already set is kept.
- Square sets `canceled_date` when a cancellation is *scheduled*; the reader then normalises to `canceled` while the
  period is still paid. The link records the cancellation at once and `access_ends_at` = the period end, which is
  correct: no further charges will happen.

### 5.3 Webhook candidates include every processor (A55)

`ProcessorWebhookController::webhookCandidateProcessorIds()` (:370–382):

```php
    /**
     * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §5.3 (A55).
     *
     * Every processor of this provider is a candidate — active, paused, archived, bound or not. Was: active ones plus
     * those bound by a non-retired binding, so a dispute or late refund on a processor whose binding had been retired
     * and which was then deactivated was dropped as an orphan. Authentication is unchanged: each candidate is still
     * verified against its OWN secret (or PayPal webhook id), and one with none is skipped by the callers.
     */
    private function webhookCandidateProcessorIds(string $provider): \Illuminate\Support\Collection
    {
        return Processor::where('provider', $provider)->pluck('id');
    }
```

`webhookCandidateProcessors()` is unchanged. `DirectPaymentProcessorBinding` is no longer imported here.

### 5.4 Orphans are always counted (A56)

`recordRejectedWebhook()` (:396–436) — the orphan row moves **above** the rate-limiter so a burst (exactly what a
mis-retired processor produces) is still counted; only the security-noise rejection row stays rate-limited:

```php
    private function recordRejectedWebhook(string $provider, Request $request, ?string $claimedEventType, string $reason, string $rawBody): void
    {
        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §5.4 (A56). Before the limiter:
        // the "did we miss money" count must never be throttled.
        if ($reason === 'bad_signature') {
            ProcessorWebhookOrphan::create([
                'provider' => $provider,
                'payload_hash' => hash('sha256', $rawBody),
                'received_at' => now(),
            ]);
        }

        $ip = $request->ip() ?? 'unknown';
        $key = "processor_webhook_rejection:{$ip}";
        if (RateLimiter::tooManyAttempts($key, 20)) {
            return;
        }
        RateLimiter::hit($key, 3600);

        ProcessorWebhookRejection::create([ /* unchanged */ ]);
    }
```

Orphans are now unthrottled writes from an unauthenticated endpoint. The row is 3 small columns and only written for a
signature failure *when real candidates exist*; a flood from one source is still bounded by the route's normal request
limits. If that is not enough, the follow-up is a daily per-payload-hash dedupe (`unique(provider, payload_hash)` with
`insertOrIgnore`) — recommended but not in PA0, to keep the "count every one" semantics reviewable first.

### 5.5 Acceptance

- Re-posting `POST /{dpRef}/subscriptions` for an existing link with a different `tier_reference_num` → tier unchanged,
  `reference_num` unchanged (A32/A33 regressions).
- A first link with a tier whose binding does not match the subscription's plan → link written with no tier, warning
  `tier_not_recorded`.
- A `provider_subscription_id` that exists only on a processor never bound to this DP → `subscription_unlinked`.
- Stripe `customer.subscription.deleted`, Square `subscription.updated` with `canceled_date`, PayPal
  `BILLING.SUBSCRIPTION.CANCELLED` and a sync sweep that reads `canceled` each set the link's `canceled_at` and
  `access_ends_at` once; `BindingRegistry::canRetire()` then no longer reports live subscriptions for it.
- A webhook signed by an archived, unbound processor's secret is processed.
- 50 bad-signature webhooks from one IP → 50 orphan rows, ≤ 20 rejection rows.

---

## Step 6 — Disputes Are Stored

Fixes **A58**. Today all three providers' dispute webhooks only write a log line
(`logStripeChargeback()` :807, `logSquareDispute()` :1285, `logPayPalDispute()` :2055), so nothing can tell whether a
processor still has a dispute open — and a dispute can arrive months after the charge.

### 6.1 Model

`app/Models/Externals/Processor/ProcessorDispute.php` — new (table in  §1.2):

```php
<?php

namespace App\Models\Externals\Processor;

use Illuminate\Database\Eloquent\Model;

/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §6.1 (A58).
 * One card dispute / chargeback at a provider, kept current by the webhook handlers. status: open | won | lost | closed.
 */
class ProcessorDispute extends Model
{
    public const OPEN = 'open';

    protected $table = 'laravel_processor_disputes';

    protected $fillable = [
        'processor_reference_num', 'provider', 'provider_dispute_id', 'provider_charge_id', 'invoice_id',
        'amount_cents', 'currency', 'reason', 'status', 'provider_status', 'evidence_due_at', 'opened_at', 'closed_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'evidence_due_at' => 'datetime',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}
```

### 6.2 Recorder

`app/Externals/Processor/Disputes/DisputeRecorder.php` — new. One upsert keyed on `(processor, provider_dispute_id)`, so
created / updated / closed events for the same dispute converge on one row in any order.

```php
<?php

namespace App\Externals\Processor\Disputes;

use App\Models\Externals\DirectPaymentInvoice;
use App\Models\Externals\Processor\ProcessorDispute;
use Illuminate\Support\Carbon;

/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §6.2 (A58).
 * Normalises each provider's dispute payload into one row. Pure mapping + one upsert; no provider calls.
 */
final class DisputeRecorder
{
    public function stripe(string $processorRef, array $dispute): ?ProcessorDispute
    {
        $status = (string) ($dispute['status'] ?? '');

        return $this->upsert($processorRef, 'stripe', $dispute['id'] ?? null, [
            'provider_charge_id' => $dispute['charge'] ?? null,
            'amount_cents' => isset($dispute['amount']) ? (int) $dispute['amount'] : null,
            'currency' => isset($dispute['currency']) ? strtolower($dispute['currency']) : null,
            'reason' => $dispute['reason'] ?? null,
            'provider_status' => $status,
            'status' => match ($status) {
                'won' => 'won',
                'lost' => 'lost',
                'warning_closed' => 'closed',
                default => ProcessorDispute::OPEN,   // warning_needs_response, warning_under_review, needs_response, under_review
            },
            'evidence_due_at' => isset($dispute['evidence_details']['due_by']) ? Carbon::createFromTimestampUTC($dispute['evidence_details']['due_by']) : null,
            'opened_at' => isset($dispute['created']) ? Carbon::createFromTimestampUTC($dispute['created']) : null,
        ]);
    }

    public function square(string $processorRef, array $dispute): ?ProcessorDispute
    {
        $state = (string) ($dispute['state'] ?? '');

        return $this->upsert($processorRef, 'square', $dispute['id'] ?? $dispute['dispute_id'] ?? null, [
            'provider_charge_id' => $dispute['disputed_payment']['payment_id'] ?? null,
            'amount_cents' => isset($dispute['amount_money']['amount']) ? (int) $dispute['amount_money']['amount'] : null,
            'currency' => isset($dispute['amount_money']['currency']) ? strtolower($dispute['amount_money']['currency']) : null,
            'reason' => $dispute['reason'] ?? null,
            'provider_status' => $state,
            'status' => match ($state) {
                'WON' => 'won',
                'LOST', 'ACCEPTED' => 'lost',
                'INQUIRY_CLOSED' => 'closed',
                default => ProcessorDispute::OPEN,   // INQUIRY_EVIDENCE_REQUIRED, INQUIRY_PROCESSING, EVIDENCE_REQUIRED, PROCESSING
            },
            'evidence_due_at' => isset($dispute['due_at']) ? Carbon::parse($dispute['due_at']) : null,
            'opened_at' => isset($dispute['created_at']) ? Carbon::parse($dispute['created_at']) : null,
        ]);
    }

    public function paypal(string $processorRef, array $dispute): ?ProcessorDispute
    {
        $status = (string) ($dispute['status'] ?? '');
        $outcome = (string) ($dispute['dispute_outcome']['outcome_code'] ?? '');

        return $this->upsert($processorRef, 'paypal', $dispute['dispute_id'] ?? null, [
            'provider_charge_id' => $dispute['disputed_transactions'][0]['seller_transaction_id'] ?? null,
            'amount_cents' => isset($dispute['dispute_amount']['value']) ? (int) round(((float) $dispute['dispute_amount']['value']) * 100) : null,
            'currency' => isset($dispute['dispute_amount']['currency_code']) ? strtolower($dispute['dispute_amount']['currency_code']) : null,
            'reason' => $dispute['reason'] ?? null,
            'provider_status' => $status . ($outcome !== '' ? ":{$outcome}" : ''),
            'status' => match (true) {
                $outcome === 'RESOLVED_SELLER_FAVOUR' => 'won',
                $outcome === 'RESOLVED_BUYER_FAVOUR' => 'lost',
                $status === 'RESOLVED' => 'closed',
                default => ProcessorDispute::OPEN,
            },
            'evidence_due_at' => isset($dispute['seller_response_due_date']) ? Carbon::parse($dispute['seller_response_due_date']) : null,
            'opened_at' => isset($dispute['create_time']) ? Carbon::parse($dispute['create_time']) : null,
        ]);
    }

    private function upsert(string $processorRef, string $provider, ?string $disputeId, array $values): ?ProcessorDispute
    {
        if ($disputeId === null || $disputeId === '') {
            return null;
        }

        $chargeId = $values['provider_charge_id'] ?? null;
        $values['invoice_id'] = $chargeId
            ? DirectPaymentInvoice::where('processor_reference_num', $processorRef)->where('charge_id', $chargeId)->value('id')
            : null;
        if ($values['status'] !== ProcessorDispute::OPEN) {
            $values['closed_at'] = now();
        }
        $values['provider'] = $provider;

        return ProcessorDispute::updateOrCreate(
            ['processor_reference_num' => $processorRef, 'provider_dispute_id' => $disputeId],
            array_filter($values, fn ($v) => $v !== null)   // a later event without a field never blanks an earlier value
        );
    }
}
```

The `invoice_id` join uses `charge_id` as each recorder writes it today: Stripe `ch_…`
(`recordStripeCharge`), Square payment id, PayPal capture id. A dispute whose charge cannot be matched is still stored
(`invoice_id` null) — the processor's open count is what retirement and archive need.

### 6.3 Webhook handlers

Each of the three existing methods keeps its log line and adds one call before `return true;`:

```php
        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §6.3 (A58).
        app(\App\Externals\Processor\Disputes\DisputeRecorder::class)->stripe($processorId, $obj);
```

```php
        app(\App\Externals\Processor\Disputes\DisputeRecorder::class)->square($processorId, $obj);   // $obj = data.object.dispute
```

```php
        app(\App\Externals\Processor\Disputes\DisputeRecorder::class)->paypal($processorId, $resource);
```

### 6.4 Readers

`BindingRegistry::canRetire()` adds the blocker its own docblock says is missing:

```php
            $this->hasOpenDisputes($binding) ? 'open disputes' : null,
```

```php
    /** AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §6.4 (A58). */
    private function hasOpenDisputes(DirectPaymentProcessorBinding $binding): bool
    {
        return \App\Models\Externals\Processor\ProcessorDispute::query()
            ->where('processor_reference_num', $binding->processor_reference_num)
            ->where('status', \App\Models\Externals\Processor\ProcessorDispute::OPEN)
            ->where(function ($q) use ($binding) {
                // Scoped to this DP's money where the dispute could be matched to an invoice; an unmatched dispute on
                // the processor blocks every binding on it — the safe direction.
                $q->whereNull('invoice_id')
                  ->orWhereIn('invoice_id', \App\Models\Externals\DirectPaymentInvoice::where('direct_payment_reference_num', $binding->direct_payment_reference_num)->select('id'));
            })
            ->exists();
    }
```

`ProcessorArchiver::warnings()` ( §2.4) already counts open disputes.

### 6.5 Acceptance

- `charge.dispute.created` then `charge.dispute.closed` (won) → one row, `open` then `won`, `closed_at` set.
- Square `dispute.state.changed` to `LOST` → `lost`; PayPal `CUSTOMER.DISPUTE.RESOLVED` with buyer outcome → `lost`.
- An open dispute on a retiring binding's processor → `canRetire()` lists "open disputes".
- Replayed or out-of-order events never create a second row or blank a field.

---

## Step 7 — Admin: Explanations, Archive Instead of Delete

Applies **OQ5** (short explanations when creating a processor and a Direct Payment). The Change-processor wizard and
the Go live button are PA8; PA0 ships the text that explains the rules, and replaces the processor Delete button with
Archive. Paths relative to `w/www/resources/js/portal/admin/`.

### 7.1 Processor form — Environment hint

`html/views/processor-details.html`, after the `proc-mode` `<select>` (line 79–82):

```html
                            <!-- AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §7.1 (OQ5). -->
                            <small class="form-hint" data-i18n="processor-details.mode_hint">Sandbox takes test payments only; no real money moves. Live takes real payments. A processor's mode, provider and account can't be changed after it is first used — to go live, add your live keys as a new processor, then use Go live on your Direct Payment. Your website keeps working with no code changes.</small>
```

(The "can't be changed after first use" part is enforced in PA1; the text is accurate as guidance from PA0.)

### 7.2 Direct Payment form — Processor hint and edit lock

`html/views/directpayments-form.html`, after the `dpProcessor` `<select>` (line 33–35):

```html
                            <!-- AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §7.2 (OQ5). -->
                            <small class="form-hint" id="dpProcessorHint" data-i18n="directpayments-form.processor_hint">This is the account that takes payments for this Direct Payment. You can change it later — including to a different provider — without changing your website. If you're testing, pick a sandbox processor; when you're ready, use Go live to switch to a live one.</small>
                            <small class="form-hint d-none" id="dpProcessorLockedHint" data-i18n="directpayments-form.processor_locked_hint">The processor of an existing Direct Payment is changed with Change processor (or Go live, for sandbox), which shows what the change affects before it happens.</small>
```

`controllers/direct_payments/directpayments.ts`, in the edit branch that selects the current processor (lines 336–338
and 376–378 — both places):

```ts
                    if (isEditing && existingDp.processor_reference_num) {
                        processorSelect.value = existingDp.processor_reference_num;
                        // AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §7.2. The server refuses a change
                        // here (DP_E_PROCESSOR_CHANGE_REQUIRES_SWAP); don't offer one the save will reject.
                        processorSelect.disabled = true;
                        document.getElementById('dpProcessorHint')?.classList.add('d-none');
                        document.getElementById('dpProcessorLockedHint')?.classList.remove('d-none');
                    }
```

The save reads `processorSelect.value`, which is still readable when the element is disabled, so the payload is
unchanged. (`?.` is used here because this file already uses it throughout; new standalone TypeScript in later phases
follows the no-optional-chaining rule.)

The processor dropdown lists only processors that can take a new Direct Payment: the existing
`fetch('/portal/api/v1/processors')` result is filtered client-side with `p.status === 'active'` (the field added in
 §2.1).

### 7.3 Processor list — Archive / Unarchive replaces Delete

`Processors/ProcessorsService.ts` — `deleteProcessor()` is replaced:

```ts
/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §7.3.
 * Processors are never deleted. Archiving closes one to new payments; refunds, live subscriptions and its
 * webhooks keep working. Addressed by reference_num, not the numeric id.
 */
export async function archiveProcessor(referenceNum: string, reason: string): Promise<{ warnings: string[] }> {
    return apiFetch<{ warnings: string[] }>(`${BASE}/${encodeURIComponent(referenceNum)}/archive`, {
        method: 'POST',
        body: JSON.stringify({ reason }),
    });
}

export async function unarchiveProcessor(referenceNum: string, reason: string): Promise<void> {
    await apiFetch<void>(`${BASE}/${encodeURIComponent(referenceNum)}/unarchive`, {
        method: 'POST',
        body: JSON.stringify({ reason }),
    });
}
```

`Processors/ProcessorsAdminView.ts` — the card (lines 122–170):

- the `is_active` badge becomes the lifecycle badge:

```ts
    const status: string = proc.status || (proc.is_active ? 'active' : 'paused');
    const statusColor = status === 'active' ? 'success' : (status === 'paused' ? 'warning' : 'secondary');
    const statusLabel = status === 'active' ? 'Active' : (status === 'paused' ? 'Paused' : 'Archived');
```

- the trash-icon `proc-delete-btn` is replaced by a text button, `proc-archive-btn` with
  `data-ref="${escHtml(proc.reference_num)}"`, labelled **Archive** (or **Unarchive** with class
  `proc-unarchive-btn` when `status === 'archived'`);
- the grid hides archived processors behind a "Show archived" toggle (default off), so the everyday list is the
  processors that can take payments.

The confirm modal (`html/views/processors.html` line ~239) is reused with new text and a required reason field:

```html
                <!-- AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — 16-safety-rails-implementation.md §7.3. -->
                <div data-i18n="processors.archive_confirm">Archiving closes this processor to new payments. It is never deleted: past payments stay refundable, live subscriptions keep working, and its notifications keep arriving.</div>
                <div class="mt-2"><strong id="proc-delete-name"></strong></div>
                <textarea class="form-control mt-3" id="proc-archive-reason" rows="2" maxlength="500" required
                    data-i18n="processors.archive_reason_placeholder" placeholder="Why are you archiving it?"></textarea>
                <ul class="mt-2 small text-warning d-none" id="proc-archive-warnings"></ul>
```

`handleConfirmDelete()` becomes `handleConfirmArchive()`: calls `archiveProcessor(ref, reason)`; on
`PROC_E_ARCHIVE_ACTIVE_BINDING` shows *"This processor is the active processor of {n} Direct Payment(s). Change their
processor first."* with the list; on success shows the returned `warnings` (if any) in `#proc-archive-warnings` before
closing, and re-renders the card as Archived. Button text: **Archive** / **Archiving…**.

### 7.4 i18n keys

Added to `i18n/en.json`, and — with the same English text, flagged for translation — to `fr.json`, `de.json`,
`es.json`, `zh.json` (the loader replaces an element's text with `i18n.t(key)`, so a key missing from a locale would
display the key itself):

```json
    "processor-details.mode_hint": "Sandbox takes test payments only; no real money moves. Live takes real payments. A processor's mode, provider and account can't be changed after it is first used — to go live, add your live keys as a new processor, then use Go live on your Direct Payment. Your website keeps working with no code changes.",
    "directpayments-form.processor_hint": "This is the account that takes payments for this Direct Payment. You can change it later — including to a different provider — without changing your website. If you're testing, pick a sandbox processor; when you're ready, use Go live to switch to a live one.",
    "directpayments-form.processor_locked_hint": "The processor of an existing Direct Payment is changed with Change processor (or Go live, for sandbox), which shows what the change affects before it happens.",
    "processors.archive_confirm": "Archiving closes this processor to new payments. It is never deleted: past payments stay refundable, live subscriptions keep working, and its notifications keep arriving.",
    "processors.archive_reason_placeholder": "Why are you archiving it?"
```

The swap and Go live refusals (`SWAP_E_MODE_LIVE_TO_SANDBOX`, `SWAP_E_MODE_USE_GO_LIVE`, Connect codes) carry their
explanation in the API `message` ( §4.1), worded as in
[03-processor-lock-in-and-archive.md](03-processor-lock-in-and-archive.md) §3.7, so the PA8 wizard shows them as-is.

### 7.5 Build

The admin bundle is rebuilt with the project's existing frontend build (`npm run build` in `w/www`). No new
dependencies.

---

## Step 8 — Tests and Verification

### 8.1 Shared test schema

Today 19 test files in `tests/Feature/DirectPayments/` (and siblings) hand-build `laravel_processors` and the Direct
Payments tables with `Schema::create(...)` copies. PA0 adds columns that code now reads (`archived_at`, `kind`), so every
copy would need editing — and would drift again next phase.

`tests/Support/CreatesPaymentsSchema.php` — new trait that builds the payment tables **from the real migration files**:

```php
<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/16-safety-rails-implementation.md §8.1.
 *
 * Creates the Direct Payments and processor tables exactly as production does, by running the two consolidated
 * migration files. Replaces the hand-copied Schema::create blocks so
 * a test can never pass against a table shape production does not have.
 */
trait CreatesPaymentsSchema
{
    protected function createPaymentsSchema(): void
    {
        if (Schema::hasTable('laravel_processors')) {
            return;
        }

        foreach ([
            'database/migrations/2026_04_03_160000_create_laravel_direct_payment_tables.php',
            'database/migrations/2026_05_21_000001_create_processor_tables.php',
        ] as $path) {
            (require base_path($path))->up();
        }
    }
}
```

(The migrations are invoked directly rather than through `artisan migrate --path` so no `migrations` table bookkeeping is
needed and each test controls its own database state exactly as it does now.)

Each of the 19 files replaces its hand-built blocks for tables these two files own with `$this->createPaymentsSchema();`
and keeps hand-building only tables outside them (ecommerce, site users, …). Mechanical; listed per file in the
completion record. Where a test deliberately built a *partial* table to simulate an old schema, it keeps that block and
says so in a comment.

### 8.2 New tests

All in `tests/Feature/DirectPayments/`. Provider HTTP is never called: processors are created without secret keys
where a test must stop before a provider call (the pattern `RefundResolvesFromChargeTest` already uses), and
`Http::fake()` / a fake `SubscriptionActionsProvider` where a call must be observed.

| Test | Step | Asserts |
|---|---|---|
| `ProcessorArchiveTest` | 2 | `DELETE` → 405 and row present; `->delete()` throws; archive refused for an active binding (409, DP listed); archive of a retiring one → warnings, `archived_*`, audit row; idempotent; unarchive restores and audits; `update(is_active=true)` on archived → 409 |
| `ProcessorLookupPrecedenceTest` | 2 | **the A4 regression**: an inactive processor named by `reference_num` → `create-intent` 409 `PROC_E_PAUSED` (was: proceeded); archived → `PROC_E_ARCHIVED` on every new-business route; `payment-intent/{id}/status` and `paypal/capture-order` still resolve an archived processor; a numeric id still resolves and logs |
| `ProcessorForeignKeyTest` | 1 | a raw `DB::table('laravel_processors')->delete()` of a bound processor fails; connected accounts / webhook endpoints / transfer intents block it too (no cascade) |
| `DirectPaymentArchiveTest` | 3 | unused DP deleted; used DP archived, audited, readable, refunds unaffected; archived DP refuses save, new invoice, `storeItem`; existing invoice re-save accepted; unarchive |
| `SaveDirectPaymentSafetyTest` | 3 | pay_type locked with money history; column not blanked (A11); items upsert keeps ids, archives dropped ones, issues distinct refs for blanks, refuses another DP's ref; save without `tiers` keeps tiers; dropped tier archived and restorable; new DP on archived processor → 409 |
| `SwapCompatibilityTest` | 4 | the matrix of  §4.7: Connect on both paths, strict processor-level Connect, accounts present vs completed migration, mode rules, webhook readiness per provider, archived/paused/no-secret targets, same-account heuristic |
| `SwapAtomicityTest` | 4 | a target that becomes archived between preview and execute → refused, active binding untouched; an exception injected in `swap()` after the retire step → rolled back; concurrent executes on one DP serialise (second sees `SWAP_E_ALREADY_ACTIVE` or a changed hash) |
| `SwapRevertTest` | 4 | A → B → A reactivates A's row, two rows total, audit `revert`; payer customers on A untouched |
| `GoLiveTest` | 4 | sandbox → live via `go-live` succeeds, `kind = go_live`, DP reference unchanged, sandbox subscriptions ended (fake provider records the cancel), provider failure reported not fatal; via `swap` → `SWAP_E_MODE_USE_GO_LIVE` |
| `SubscriptionLinkIntegrityTest` | 5 | reference and tier never overwritten (A32/A33); mismatched tier not recorded; subscription on a foreign processor → `subscription_unlinked`; race → one row |
| `ProviderEndedSubscriptionTest` | 5 | Stripe deleted / Square canceled_date / PayPal `CANCELLED` (raw) / sync sweep each set `canceled_at` + `access_ends_at` once; `canRetire()` unblocked; access policy `immediately` honoured |
| `WebhookCandidateTest` | 5 | archived, unbound processor's signed webhook processed; orphans counted past the rejection limit |
| `DisputeRecorderTest` | 6 | per-provider mapping; created → closed converges on one row; out-of-order events don't blank fields; `canRetire()` blocked by an open dispute |

Existing tests that must stay green unchanged in their assertions: `SwapServiceTest` (the `blocking` list keeps its
string shape), `SaveDirectPaymentTest` (Connect codes unchanged), `BindingRetirementTest`, `DirectPaymentSwapControllerTest`
(except `bound_by`, which now comes from the login — that one assertion is updated and noted),
`WebhookOrphanRecordingTest`, `RefundResolvesFromChargeTest`.

### 8.3 Grep gates added in PA0

```bash
cd w/www

# G2 — no hand-written public processor lookups outside ProcessorLookup (A4)
grep -rn "orWhere('id'" app/Http/Controllers/Externals/Processor app/Http/Controllers/Externals/DirectPayments

# G3 — no processor deletes (I1)
grep -rnE "\\\$processor->delete\(\)|Processor::[a-zA-Z]+\(.*\)->delete\(\)" app

# G9 — laravel_processor_subscriptions written only through Eloquent models (so the end-sync observer always fires)
grep -rn "DB::table('laravel_processor_subscriptions')" app | grep -v "->select\|->where.*->get\|->value\|->count"
```

All three are expected empty after PA0.

### 8.4 Verification

```bash
cd w/www
php artisan test tests/Feature/DirectPayments
php artisan migrate:fresh --pretend    # local database: shows the full schema SQL
```

On the **dev** environment (real, sandbox processors only), with the owner present — nothing is run on a server without
the owner's go-ahead:

1. Rebuild the dev database from the create migrations (`php artisan migrate:fresh`), then recreate the sandbox
   processors and a test Direct Payment.
2. In the admin: archive a sandbox processor that is no DP's active processor → card shows Archived; try to pay with it
   from the playground's single-charge sample by forcing its reference → refused `PROC_E_ARCHIVED`.
3. Try to archive a DP's active processor → refused, DP named.
4. `POST …/swap/preview` live → sandbox → `SWAP_E_MODE_LIVE_TO_SANDBOX`; `go-live/preview` on a sandbox DP → impact and
   the Go live consequences.
5. Cancel a test subscription in the Stripe sandbox dashboard → the DP subscription shows `canceled_at` within the
   webhook's delivery time.
6. Replay a stored webhook for the archived processor from the Stripe dashboard → processed.

### 8.5 Rollback

Code revert restores the previous behaviour. The schema changes are in the create migrations, so reverting them and running `migrate:fresh` returns the
database to the previous shape (development only — no data to keep).

### 8.6 Completion record

`plans/www/direct_payments_audit/complete/16-safety-rails-implementation.complete.md` — what shipped per step, files, the per-file list of test
schema conversions, acceptance table (✅ / ⚠️ / ❌ with evidence), verification output, residue.
