# 03 — Database Schema

All migrations live in `w/www/database/migrations/` (the shared database rule). Three new
tables, one backfill, one deprecation. Every statement is guarded with `Schema::hasTable` /
`hasColumn` the way every migration in this repo already is, so a partially-applied local
database recovers on the next `migrate`.

## 3.1 Overview

| Table | Owner | Purpose |
|---|---|---|
| **`laravel_site_user_connections`** *(new)* | Site Users | The connection rows — [02](02-architecture.md) §2.2 |
| **`laravel_ecommerce_return_requests`** *(new)* | ecommerce | A shopper's return / cancellation request — [09](09-module-self-service.md) §9.2 |
| **`laravel_site_user_mode_snapshots`** *(new, temporary)* | Site Users | What each instance's legacy `site_users_mode` said before K1 touched anything — the only durable evidence behind the *Needs attention* banner (X29). Dropped with the columns in §3.6 |
| `laravel_ecommerce_stores.site_users_mode`, `.site_users_id` | ecommerce | **Become derived** (written by the connection service, read by nothing new) — §3.5 |
| `laravel_events.site_users_mode`, `laravel_appointments.site_users_mode` | events, appointments | Same |
| `laravel_site_user_modules.link_payment_customers` | Site Users | **Becomes derived** from the `direct_payments` connection — §3.5 |
| `laravel_direct_payment_invoices.user_id` | Direct Payments | Unchanged schema; **stamped** `su:<user reference_num>` at a signed-in checkout — [07](07-direct-payments-connection.md) §7.6 |
| Twelve consumer `site_user_id` columns | each module | **Become `site_user_reference char(12)`** — owned by [`../site_users/13`](../site_users/13-identity-and-email-change.md) §13.3, which carries the five-step migration. This plan writes and reads the new column from K1 onward |

Nothing is dropped in this plan. §3.6 lists what a later cleanup removes.

## 3.2 `laravel_site_user_connections` (K1, K2, K3)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/03-database-schema.md §3.2.
 *
 * One row per (Site Users module ↔ consumer @ instance). `instance_key` is a
 * NOT NULL string so the unique index can enforce "an instance is connected to
 * at most one module" (K3) — MySQL treats NULLs as distinct in a unique index,
 * so a nullable instance column would let two module-wide rows coexist.
 * '*' is the module-wide sentinel (K2).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('laravel_site_user_connections')) {
            return;
        }

        Schema::create('laravel_site_user_connections', function (Blueprint $table) {
            $table->id();

            // → laravel_site_user_modules.reference_num. Indexed: the dashboard asks
            //   "every connection for this module" on every page load.
            $table->string('site_users_id', 32)->index('su_conn_module_idx');

            // A key from config/site_users_consumers.php with `connectable => true`.
            $table->string('module_key', 32);

            // The consumer instance's reference_num, or '*' for module-wide (K2).
            // 64 covers every reference format in the estate (stores 20, events 20,
            // appointments 20, Direct Payments 64).
            $table->string('instance_key', 64)->default('*');

            // The ONE behavioural switch (K4). Default true: connecting must not,
            // by itself, turn away a single guest.
            $table->boolean('guest_checkout')->default(true);

            // Dashboard label override. Null → resolved from the instance (store
            // name, appointment name) or the module label ("Events", "Payments").
            $table->string('label', 120)->nullable();

            // Per-module knobs the connection editor exposes (09 §9.2, §9.3, §9.4):
            //   ecommerce:    {"returns":{"enabled":bool,"window_days":int,"reasons":[…]},
            //                  "cancel":{"enabled":bool,"window_minutes":int}}
            //   events:       {"cancel":{"enabled":bool,"cutoff_hours":int},"show_past":bool}
            //   appointments: {"reschedule":{"enabled":bool},"cancel":{"enabled":bool}}
            //   any:          {"login_path":"/account-login"}   ← overrides the builder-derived path
            $table->json('settings')->nullable();

            // The lifecycle (13 §13.2). `active` behaves fully; `disconnected` is
            // READ-ONLY — the module stops accepting new activity and the user
            // keeps seeing everything they already did. Deleting the row is the
            // third state and the only destructive one.
            //
            // Not a boolean: "inactive" cannot express "still visible, no longer
            // interactive", which is the whole of 13.
            $table->string('status', 20)->default('active');   // active | disconnected

            $table->string('created_by', 64)->nullable();   // portal admin username, audit only
            $table->timestamps();

            // K3 — an instance (or the module-wide slot) connects to ONE Site Users module.
            $table->unique(['module_key', 'instance_key'], 'su_conn_instance_uq');

            // The dashboard's query (visible = active + disconnected) and the
            // enforcement query (active only) both start here.
            $table->index(['site_users_id', 'status'], 'su_conn_module_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laravel_site_user_connections');
    }
};
```

### Why the unique index is on `(module_key, instance_key)` and not on the Site Users side

The asymmetry mirrors the customer-link table (N8): one Site Users module may hold many
connections; one instance belongs to at most one module. A store connected to two modules
would produce two populations of "my orders" for the same store, and attribution would have
to pick a winner. The database refuses to let the question arise.

### Model

```php
<?php

namespace App\Models\Externals\SiteUsers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/03-database-schema.md §3.2.
 */
class SiteUserConnection extends Model
{
    public const WILDCARD = '*';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISCONNECTED = 'disconnected';

    protected $table = 'laravel_site_user_connections';

    protected $fillable = [
        'site_users_id', 'module_key', 'instance_key', 'guest_checkout',
        'label', 'settings', 'status', 'created_by',
    ];

    protected $casts = [
        'guest_checkout' => 'boolean',
        'settings' => 'array',
    ];

    public function isModuleWide(): bool
    {
        return $this->instance_key === self::WILDCARD;
    }

    /** The derived tri-state every consumer already understands (02 §2.4). */
    public function mode(): string
    {
        return $this->guest_checkout ? 'guest' : 'required';
    }

    /** A typed reader for the settings blob — never `$conn->settings['returns']['enabled'] ?? …` at call sites. */
    public function setting(string $dotPath, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $dotPath, $default);
    }

    /** Rows that govern behaviour — enforcement, attribution, public config (13 §13.2). */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    /** Rows the Dashboard renders — active AND disconnected (13 §13.4). */
    public function scopeVisible(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_DISCONNECTED]);
    }

    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'site_users_id' => $this->site_users_id,
            'module_key' => $this->module_key,
            'instance_key' => $this->instance_key,
            'instance_reference_num' => $this->isModuleWide() ? null : $this->instance_key,
            'module_wide' => $this->isModuleWide(),
            'guest_checkout' => $this->guest_checkout,
            'mode' => $this->mode(),
            'label' => $this->label,
            'settings' => $this->settings ?? (object) [],
            'status' => $this->status,
            'read_only' => $this->isReadOnly(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

## 3.3 `laravel_ecommerce_return_requests` (K15)

Owned by ecommerce; created here because its only consumer is the connected dashboard.

```php
/**
 * AFS_ECOMMERCE_MODIFIER_ADDITION — plans/www/connections/03-database-schema.md §3.3,
 * 09-module-self-service.md §9.2.
 *
 * A shopper's request. The operator decides; money moves only through the
 * Money ledger (direct_payments/29). `refund_cents` is recorded AFTER the operator
 * refunds, from the invoice snapshot — never entered by the shopper.
 */
Schema::create('laravel_ecommerce_return_requests', function (Blueprint $table) {
    $table->id();
    $table->string('reference_num', 32)->unique();            // ret_ + 12
    $table->string('order_reference_num', 32)->index('ec_ret_order_idx');
    $table->string('store_reference_num', 32)->index('ec_ret_store_idx');

    // NOT NULL. Returns are a connected-account feature: a guest has an emailed
    // receipt and the merchant's contact details, not a self-service queue.
    //
    // The REFERENCE, not the bigint (02 §2.10, site_users/13 §13.3): this table
    // is new, so it is born on the right identifier and never needs the
    // add-backfill-cut-over dance the twelve existing columns do.
    $table->char('site_user_reference', 12)->index('ec_ret_user_idx');

    // 'return' — items going back; 'cancellation' — the whole unfulfilled order.
    $table->string('kind', 16)->default('return');

    // requested → approved | rejected ; approved → received → refunded ; any open → cancelled (by shopper)
    $table->string('status', 20)->default('requested')->index('ec_ret_status_idx');

    $table->string('reason', 64);                              // from the connection's configured list, or 'other'
    $table->text('note')->nullable();                          // shopper's free text
    $table->json('lines');                                     // [{order_line_id, quantity}] — a subset of the order's lines

    $table->string('resolution', 20)->nullable();              // refund | exchange | store_credit — operator's choice
    $table->text('admin_note')->nullable();
    $table->integer('refund_cents')->nullable();               // recorded after the ledger refund, from InvoiceSnapshot
    $table->string('decided_by', 64)->nullable();
    $table->timestamp('decided_at')->nullable();

    $table->timestamps();
});
```

**One open request per order** is a service rule, not an index: `status IN (requested,
approved, received)` cannot be expressed as a unique key without a generated column, and the
service check is a single query inside the same transaction that inserts.

## 3.4 Backfill — the existing mode columns become connection rows (K16)

Runs in the same migration file as §3.2, after `create`, and is idempotent (re-running finds
the rows already present).

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/03-database-schema.md §3.4.
 *
 * Every instance whose column says guest/required becomes a connection row so
 * the behaviour a tenant configured survives the switch of truth. Instances in
 * 'off' produce no row — 'off' IS "no connection".
 */
private function backfillConnections(): void
{
    $modules = DB::table('laravel_site_user_modules')->where('is_active', true)->pluck('reference_num');
    $soleModule = $modules->count() === 1 ? $modules->first() : null;

    $sources = [
        // [table, module_key, has site_users_id column?]
        ['laravel_ecommerce_stores', 'ecommerce', true],
        ['laravel_events',           'events',    false],
        ['laravel_appointments',     'appointments', false],
    ];

    $skipped = [];

    foreach ($sources as [$tableName, $moduleKey, $hasModuleColumn]) {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'site_users_mode')) {
            continue;
        }

        $rows = DB::table($tableName)
            ->whereIn('site_users_mode', ['guest', 'required'])
            ->get(['reference_num', 'site_users_mode', ...($hasModuleColumn ? ['site_users_id'] : [])]);

        foreach ($rows as $row) {
            // Which module? The store's own column if set and real; else the sole
            // active module; else we cannot guess — and must not.
            $target = null;
            if ($hasModuleColumn && ! empty($row->site_users_id) && $modules->contains($row->site_users_id)) {
                $target = $row->site_users_id;
            } elseif ($soleModule !== null) {
                $target = $soleModule;
            }

            if ($target === null) {
                $skipped[] = ['table' => $tableName, 'reference_num' => $row->reference_num, 'mode' => $row->site_users_mode];
                continue;
            }

            $exists = DB::table('laravel_site_user_connections')
                ->where('module_key', $moduleKey)
                ->where('instance_key', $row->reference_num)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('laravel_site_user_connections')->insert([
                'site_users_id' => $target,
                'module_key' => $moduleKey,
                'instance_key' => $row->reference_num,
                'guest_checkout' => $row->site_users_mode === 'guest',
                'label' => null,
                'settings' => null,
                'status' => 'active',
                'created_by' => 'migration:connections-backfill',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // The Direct Payments connection: modules that had link_payment_customers on
    // get a module-wide direct_payments row (07 §7.8). Two modules cannot both
    // hold '*' (K3) — a deployment with two opted-in modules keeps the first and
    // logs the second for the admin to reconnect per Direct Payment.
    $linked = DB::table('laravel_site_user_modules')->where('link_payment_customers', true)->orderBy('id')->pluck('reference_num');
    foreach ($linked as $i => $ref) {
        if ($i > 0) { $skipped[] = ['table' => 'laravel_site_user_modules', 'reference_num' => $ref, 'mode' => 'link_payment_customers']; continue; }
        DB::table('laravel_site_user_connections')->insertOrIgnore([
            'site_users_id' => $ref, 'module_key' => 'direct_payments', 'instance_key' => '*',
            'guest_checkout' => true, 'status' => 'active',
            'created_by' => 'migration:connections-backfill', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    if ($skipped !== [] && function_exists('logger')) {
        logger()->warning('Connections backfill: instances that could not be assigned a Site Users module', ['skipped' => $skipped]);
    }
}
```

### The snapshot the banner needs (X29)

Before any of the above runs, the migration **records what every legacy column said**, into a
tiny table nothing else writes:

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/03-database-schema.md §3.4 (X29).
 *
 * The pre-migration value of every instance's `site_users_mode`, captured once,
 * in K1, BEFORE the registry starts deriving anything and long before
 * SiteUsersDerivedColumns writes `off` over the columns of every instance that
 * has no connection row (§3.5).
 *
 * Without this the "needs attention" banner (05 §5.4) is self-erasing: it looks
 * for "legacy column non-off AND no connection row", and the first derived sync
 * makes that set permanently empty. The tenant would never learn which
 * instances silently lost `required`, which is the single change of behaviour
 * this whole migration can produce.
 *
 * Dropped with the legacy columns in §3.6, once the banner has been empty for a
 * release.
 */
Schema::create('laravel_site_user_mode_snapshots', function (Blueprint $table) {
    $table->id();
    $table->string('module_key', 32);
    $table->string('instance_key', 64);
    $table->string('legacy_mode', 20);            // the column's value at capture time
    $table->boolean('resolved')->default(false);  // set when the tenant connects it, or dismisses it
    $table->timestamp('captured_at');
    $table->unique(['module_key', 'instance_key'], 'su_mode_snapshot_uq');
});
```

Only rows whose `legacy_mode` was `guest` or `required` are captured — an instance that was
`off` has nothing to lose and would be noise in the banner. Rows the backfill successfully
converts are inserted with `resolved = true`, so the banner shows **only** the instances the
backfill could not place.

**A skipped instance is not silently `off`.** Its column still says `required`; the
registry now ignores the column; so its behaviour *would* change on deploy. Three mitigations,
all required:

1. `php artisan siteusers:connections-audit` (new, [11](11-rollout-and-testing.md) §11.2)
   prints the same skipped list from live data — run it **before** deploying, against
   production, so the operator knows the count is zero or knows what to reconnect.
2. The Connections tab shows a *Needs attention* banner driven by
   `laravel_site_user_mode_snapshots` — **the snapshot, never the live column**
   ([05](05-admin-connections.md) §5.4), for the reason above.
3. The banner clears only by an explicit act: connecting the instance, or dismissing the row
   (`resolved = true`). A sync can never clear it, which is the whole point of the snapshot.

On every deployment seen so far there is one Site Users module, so the skipped list is
expected to be empty. The machinery exists because "expected" is not "guaranteed".

## 3.5 What becomes derived (K16)

After the backfill, three things are written *by* `SiteUsersConnectionService` and read by
nothing this plan touches:

| Derived value | Written when | Formula |
|---|---|---|
| `laravel_ecommerce_stores.site_users_mode`, `.site_users_id` | a connection for `ecommerce@<store>` is saved / removed / deactivated; **and** when a module-wide `ecommerce@*` row changes (every store without its own row is rewritten) | `mode()` of the resolved row or `'off'`; `site_users_id` of the resolved row or `null` |
| `laravel_events.site_users_mode`, `laravel_appointments.site_users_mode` | same, for their modules | same |
| `laravel_site_user_modules.link_payment_customers` | a `direct_payments` connection for the module is saved / removed / deactivated | `true` iff at least one active `direct_payments` row exists for the module |

```php
/**
 * Write-through so a reader this plan missed keeps seeing the right value for
 * one release. `grep -rn "site_users_mode" app/ resources/js` is the gate that
 * lets 3.6 drop the columns: when the only hits are this method and the
 * banner in 05 §5.4, they can go.
 */
private function syncDerivedColumns(string $moduleKey): void
{
    $entry = $this->consumers->get($moduleKey);
    $instanceModel = $entry['instance']['model'] ?? null;      // e.g. \App\Models\Externals\EcommerceStore::class
    if ($instanceModel === null) {
        if ($moduleKey === 'direct_payments') { $this->syncLinkPaymentCustomers(); }
        return;
    }

    $instanceModel::query()->select(['id', 'reference_num'])->chunkById(200, function ($instances) use ($moduleKey, $instanceModel) {
        foreach ($instances as $instance) {
            $conn = $this->registry->resolve($moduleKey, $instance->reference_num);
            $update = ['site_users_mode' => $conn?->mode() ?? 'off'];
            if (Schema::hasColumn((new $instanceModel)->getTable(), 'site_users_id')) {
                $update['site_users_id'] = $conn?->site_users_id;
            }
            $instanceModel::query()->whereKey($instance->id)->update($update);
        }
    });
}
```

Chunked because a module-wide change touches every instance. It runs synchronously in the
admin request — forty events is nothing; a tenant with ten thousand events is not a case
this estate has, and if it becomes one the sync moves to a queued job without changing its
contract.

## 3.6 Later cleanup (not this plan)

Once one release has shipped with the derived write-through and the grep gate in §3.5 is
clean:

- Drop `laravel_ecommerce_stores.site_users_mode`, `.site_users_id`;
  `laravel_events.site_users_mode`; `laravel_appointments.site_users_mode`.
- Drop `laravel_site_user_modules.link_payment_customers`; `linkCustomer()` and
  `SiteUsersLinkCustomers` read `SiteUsersConnectionRegistry::paymentsLinked()` from day one
  of this plan ([07](07-direct-payments-connection.md) §7.8), so the drop is a no-op for them.
- Remove `mode_source` from `config/site_users_consumers.php` (kept only so a rollback of the
  registry change has something to read — [11](11-rollout-and-testing.md) §11.6).

## 3.7 Deleting a user, and what happens to attribution (X20)

`SiteUsersAdminController::deleteUser()` revokes sessions and hard-deletes the row. Nothing
touches the `site_user_reference` values pointing at it — there is no foreign key, only an index —
so after a delete, orders, registrations, bookings, carts, promo redemptions, gift cards and
customer links keep an integer that resolves to nobody.

Today that is invisible. After this plan it is not: `/me/purchases`, the dashboard's badge
counters and [09](09-module-self-service.md) §9.7's claim service all join on it, and a
future account whose auto-increment id lands on a freed value would inherit a stranger's
history. That last one is unlikely (ids are not reused by MySQL after a delete unless the
table is truncated) but it is the kind of unlikely that is unrecoverable, so the rule is
explicit rather than assumed.

### The rule

**Deleting a user nulls the attribution and keeps the record.** Not a cascade — an operator
deleting a spam signup must not delete the tenant's orders — and not a dangling id.

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/03-database-schema.md §3.7 (X20).
 *
 * Detach before delete, in one transaction. The consuming tables are named by
 * the consumer registry's `user_column`, plus the two Site Users owns
 * (customer links, which are deleted outright — a payments identity must not
 * outlive its account).
 *
 * The rows survive with site_user_reference = NULL: they become guest records, which
 * is exactly what they are once the account is gone, and they stay visible and
 * refundable to the tenant.
 */
public function detachAndDelete(SiteUser $user): array   // ['laravel_ecommerce_orders' => 12, …]
```

Tables and their columns come from the registry (`user_column`) plus an explicit list for the
ones no registry entry names (`laravel_ecommerce_carts`,
`laravel_ecommerce_promotion_redemptions`, `laravel_ecommerce_gift_cards`,
`laravel_event_orders`, `laravel_event_pass_redemptions`,
`laravel_ecommerce_return_requests`). The explicit list is a constant in one class with a
test that fails when a migration adds a `site_user_id` column it does not name:

```php
// tests — the guard against a column added later and forgotten
$columns = /* every table in the schema with a site_user_reference column */;
$this->assertEqualsCanonicalizing($columns, SiteUsersUserDeletion::TABLES);
```

`laravel_ecommerce_return_requests.site_user_reference` is `NOT NULL` (§3.3), so its rows are
**deleted** rather than detached — a return request with no requester is not a work item, and
the order it refers to survives with its own history.

### What the admin sees

The delete confirmation names the counts first: *"Dana Mills has 12 orders, 3 registrations
and 1 booking. Deleting the account keeps those records and removes the customer's name from
them. This cannot be undone."* The counts come from the same activity read
([05](05-admin-connections.md) §5.8) that already exists on that screen.

### No self-service delete

The Dashboard gets **no** "delete my account" in this plan. A tenant-facing erasure flow needs
a retention decision the tenant owns (what happens to invoices they are legally required to
keep), an export, and a confirmation path — a piece of work with its own shape.
[11](11-rollout-and-testing.md) §11.7 follow-up 12. What the dashboard does gain is *Sign out
everywhere*, which it already has.

## 3.8 Test schema helper

`tests/Support/CreatesSiteUsersSchema.php` gains `laravel_site_user_connections` (same columns
as §3.2) so every existing SiteUsers feature test keeps its in-memory schema, and a new
`Tests\Support\CreatesConnectionsFixtures` trait provides:

```php
protected function connect(string $module, string $moduleKey, ?string $instance = null, array $overrides = []): SiteUserConnection
{
    return SiteUserConnection::create(array_merge([
        'site_users_id' => $module,
        'module_key' => $moduleKey,
        'instance_key' => $instance ?? SiteUserConnection::WILDCARD,
        'guest_checkout' => true,
        'status' => 'active',
    ], $overrides));
}
```

Ecommerce, events and appointments feature tests that exercise `required` mode today by
setting `site_users_mode = 'required'` on the instance switch to `connect(...,
['guest_checkout' => false])`. The old setter stops having any effect — which is itself the
first assertion of [11](11-rollout-and-testing.md) §11.4's enforcement suite.
