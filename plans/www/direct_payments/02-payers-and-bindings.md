# 02 — Payers and Bindings

Two new tables and one new column. Together they move every provider-issued identifier behind
a Direct Payments-issued one.

## 2.1 The shape

```
  MODULE                     DIRECT PAYMENTS                        PROVIDER
  ──────                     ───────────────                        ────────
  order / registration
  / booking
    direct_payment_ref  ───▶  laravel_direct_payments
    payer_reference_num ──┐     reference_num  (never changes)
                          │          │
                          │          └─▶ processor_bindings ──┬─ active   ─▶ processor B ─▶ Stripe acct B
                          │               (§2.4)              └─ retired  ─▶ processor A ─▶ Stripe acct A
                          │
                          └─▶ laravel_direct_payment_payers
                                reference_num  pay_x7k2m9q4bt3a  (never changes)
                                     │
                                     └─▶ payer_customers (§2.3)
                                           (payer, processor B) → cus_9fK2…   active
                                           (payer, processor A) → cus_Nx8f…   superseded
```

Everything to the left of Direct Payments is stable. Everything to the right of it is
disposable. That line is the product.

## 2.2 `laravel_direct_payment_payers` (P2)

```php
<?php

/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/02-payers-and-bindings.md §2.2.
 *
 * A person who pays, as Direct Payments understands them — issued by us, not by
 * Stripe, Square or PayPal, and therefore able to outlive all three.
 *
 * This is the value an implementer stores (05 §5.2). It replaces the raw
 * provider customer id every module holds today (01 D4), which is the single
 * reason a provider change is currently disruptive.
 *
 * A payer holds NO money and NO payment credentials. It is an identity and a
 * join key. What it maps to per processor is §2.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('laravel_direct_payment_payers')) {
            return;
        }

        Schema::create('laravel_direct_payment_payers', function (Blueprint $table) {
            $table->id();

            // `pay_` + 12 lowered alphanumeric, matching every other reference in
            // this estate (ord_, inv_, crt_, evt_). Generated with random_int:
            // not a credential, but a predictable one would let someone enumerate
            // who has bought from a tenant.
            $table->string('reference_num', 32)->unique();

            // P9 — a payer belongs to a WEBSITE, not to a Direct Payment. One
            // person who buys from the store and books an appointment on the same
            // site is one payer, and reuses one provider customer per processor
            // instead of accumulating one per DP.
            $table->string('website_reference_num', 64)->index('dp_payers_site_idx');

            // A snapshot for matching and for operator display. MUTABLE — a site
            // user can change their email (site_users/13 §13.4) and this follows.
            // Never a join key: §2.3's rules exist because email matching is where
            // this goes wrong.
            $table->string('email', 255)->nullable();
            $table->string('name', 255)->nullable();

            // How this payer came to exist, kept forever so a heuristic that turns
            // out wrong has a queryable blast radius (the `linked_by` convention
            // from site_users/08 §8.2).
            $table->string('created_by', 20);      // checkout | site_user | admin | migration

            $table->timestamps();

            // Guest continuity within a site: a returning guest typing the same
            // address reuses their payer. NULL emails are distinct in MySQL, so
            // any number of anonymous payers coexist.
            $table->unique(['website_reference_num', 'email'], 'dp_payers_site_email_uq');
        });
    }
};
```

### How a payer is resolved at checkout

| Caller | Rule |
|---|---|
| **A signed-in site user** | Their payer, through the link in [05](05-implementer-contract.md) §5.5. Created on first checkout with `created_by = 'site_user'`. **Never adopts an existing guest payer**, even on an email match — a card a guest saved must not become reachable by whoever later types that address |
| **A guest with an email** | `firstOrCreate` on `(website, email)`, `created_by = 'checkout'`. A returning guest keeps their provider customer and their cards, which is the same continuity the cart cookie gives them ([`../connections/12`](../connections/12-guest-continuity.md) §12.1) |
| **A guest with no email** | A fresh payer with `email = null` every time. Anonymous, disposable, and never matched to anything |

The asymmetry in row one is deliberate and is the same rule the connections plan already
applies to provider customers ([`../connections/07`](../connections/07-direct-payments-connection.md)
§7.5): guest-to-guest matching on a typed address is acceptable continuity; guest-to-account
matching on a typed address is a way to inherit a stranger's saved card.

## 2.3 `laravel_direct_payment_payer_customers` — the mapping that makes a swap survivable

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/02-payers-and-bindings.md §2.3.
 *
 * One row per (payer, processor): "on THIS account, this person is THAT customer".
 *
 * A swap does not rewrite these. It adds a row for the new processor and marks
 * the old one superseded — which is exactly the requirement "the customer id
 * must be updated, but retain the old ones for history", implemented once here
 * instead of once per implementer.
 */
Schema::create('laravel_direct_payment_payer_customers', function (Blueprint $table) {
    $table->id();

    $table->string('payer_reference_num', 32)->index('dp_payer_cust_payer_idx');
    $table->string('processor_reference_num', 7)->index('dp_payer_cust_proc_idx');
    $table->string('provider', 20);                 // stripe | square | paypal — snapshot
    $table->string('mode', 10);                     // sandbox | live — snapshot
    $table->string('provider_customer_id', 255);

    // active while this is the customer to charge on that processor; superseded
    // once the processor is retired or the provider recreated the customer.
    $table->string('status', 20)->default('active');
    $table->timestamp('first_seen_at');
    $table->timestamp('superseded_at')->nullable();

    // The nullable-unique pattern this estate already uses twice
    // (appointments' active_slot_key, connections/12's active_cart_token):
    // exactly one ACTIVE customer per (payer, processor, mode), and any number
    // of superseded ones.
    $table->string('active_key', 64)->nullable();   // "<processor>:<mode>" while active, NULL once superseded
    $table->unique(['payer_reference_num', 'active_key'], 'dp_payer_cust_active_uq');

    // One provider customer belongs to at most one payer. Getting this backwards
    // is how one shopper's saved card charges another's order — the same
    // asymmetry site_users/08 §8.2 argues for.
    $table->unique(['processor_reference_num', 'provider_customer_id'], 'dp_payer_cust_customer_uq');

    $table->timestamps();
});
```

**Nothing is ever deleted from this table.** A superseded row is how a charge taken three
providers ago still resolves to a person ([04](04-history-refunds-and-webhooks.md) §4.2).

### The resolver

```php
/**
 * The one method the whole plan turns on. Given a payer and the Direct Payment
 * they are paying through, return the provider customer to charge — creating it
 * at the provider on first use.
 *
 * The caller never names a processor. It names a Direct Payment, and this
 * resolves the DP's ACTIVE binding (§2.4). That indirection is what makes a swap
 * invisible: the same payer, the same call, a different account underneath.
 */
public function customerFor(string $payerRef, string $dpRef): PayerCustomer
{
    $binding = $this->bindings->active($dpRef)
        ?? throw new DirectPaymentException('DP_E_NO_ACTIVE_BINDING');

    $existing = PayerCustomer::query()
        ->where('payer_reference_num', $payerRef)
        ->where('active_key', $binding->processor_reference_num . ':' . $binding->mode)
        ->first();

    if ($existing !== null) {
        return $existing;                       // the common path, after the first charge
    }

    // First time this payer has paid on this processor — which is also what a
    // swap makes true for every payer at once. Create at the provider WITH the
    // payer's email when there is one; an anonymous customer is only for a payer
    // that genuinely has no address (connections/07 §7.10's rule).
    $payer = Payer::byReference($payerRef);
    $providerCustomerId = $this->processorCustomers->createFor($binding->processor_reference_num, $payer->email, $payer->name);

    return PayerCustomer::create([
        'payer_reference_num' => $payerRef,
        'processor_reference_num' => $binding->processor_reference_num,
        'provider' => $binding->provider,
        'mode' => $binding->mode,
        'provider_customer_id' => $providerCustomerId,
        'status' => 'active',
        'active_key' => $binding->processor_reference_num . ':' . $binding->mode,
        'first_seen_at' => now(),
    ]);
}
```

**Lazy, not bulk.** A swap does not create 1,204 customers at the new provider on swap day —
it creates each one the first time that payer next pays. Bulk pre-creation would mint
thousands of empty customers at a provider for people who may never return, and would fail
partway with no good resume point.

## 2.4 `laravel_direct_payment_processor_bindings` (P1, P3)

A Direct Payment's processor stops being a column and becomes a history.

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/02-payers-and-bindings.md §2.4.
 *
 * Which processor a Direct Payment charges through, and which ones it used to.
 * `laravel_direct_payments.processor_reference_num` becomes a derived cache of
 * the active row for one release, then is dropped (06 §6.2) — the same
 * add/migrate/deprecate/remove shape the connections plan uses for its mode
 * columns.
 */
Schema::create('laravel_direct_payment_processor_bindings', function (Blueprint $table) {
    $table->id();

    $table->string('direct_payment_reference_num', 128)->index('dp_bind_dp_idx');
    $table->string('processor_reference_num', 7)->index('dp_bind_proc_idx');
    $table->string('provider', 20);
    $table->string('mode', 10);

    // active    — new charges go here. Exactly one per DP.
    // retiring  — no new charges; still reads, refunds, webhooks and existing
    //             subscriptions (03 §3.6). Any number.
    // retired   — closed out; nothing outstanding. Kept for history.
    $table->string('status', 20)->default('active');

    $table->timestamp('bound_at');
    $table->timestamp('retiring_at')->nullable();
    $table->timestamp('retired_at')->nullable();
    $table->string('bound_by', 64)->nullable();          // operator, for the audit trail
    $table->text('note')->nullable();                    // why the tenant swapped

    $table->timestamps();

    // Exactly one ACTIVE binding per DP — the same nullable-unique trick as §2.3.
    $table->string('active_marker', 128)->nullable();    // the DP ref while active, NULL otherwise
    $table->unique('active_marker', 'dp_bind_active_uq');
});
```

`BindingRegistry::active($dpRef)` is the only way any code learns which processor a Direct
Payment charges through. `saveDirectPayment()` stops accepting `processor_reference_num` as an
ordinary field ([03](03-the-swap.md) §3.3).

## 2.5 Every money object snapshots its processor (P4)

Two of four already do. The plan finishes the set.

| Table | Today | Change |
|---|---|---|
| `laravel_direct_payment_invoices` | `processor_reference_num` ✅ | **+ `payer_reference_num`** (`char(32)`, nullable, indexed). `customer_id` keeps the raw provider id as a snapshot — history needs the value the provider knew |
| `laravel_processor_charges` | `processor_reference_num` ✅ | none |
| `laravel_direct_payment_subscriptions` | **nothing** ⚠ **D2** | **+ `processor_reference_num`** (`char(7)`, indexed) and **+ `payer_reference_num`**. Backfilled from each subscription's Direct Payment binding at migration time, which is correct precisely because no DP has been swapped yet |
| `laravel_processor_customers` | is itself per-processor | none — it stays the processor layer's own record. `payer_customers` maps to it by `provider_customer_id` |

**D2's backfill is safe exactly once.** Today every subscription's processor can be inferred
from its Direct Payment, because no Direct Payment has ever been re-pointed. The moment one
is, the inference is wrong forever. So the column is added and backfilled **before** the swap
feature ships — [06](06-rollout-and-testing.md) makes that ordering a hard gate, not a
preference.

## 2.6 What this does not change

- **The façade contract.** A selling module still stores three payment references and no
  money ([`../ecommerce/AUDIT`](../ecommerce/AUDIT-direct-payments-decoupling.md)). One of
  them changes value type; none is added or removed
  ([05](05-implementer-contract.md) §5.2).
- **`PaymentGateway`.** `isPaid()`, `snapshot()`, `snapshots()` and `paidInvoicesAwaiting()`
  keep their signatures. `InvoiceSnapshot` gains `payerReference`.
- **The processor layer.** `laravel_processor_customers`, charges, subscriptions and the
  provider clients are untouched except for D2's new column. The processor layer still knows
  nothing about payers — Direct Payments maps between them, which is the layering
  [`../connections/02`](../connections/02-architecture.md) §2.6 already sets out.
- **Provider behaviour.** No provider is called differently. This plan changes which of our
  identifiers we hand around, not what we ask Stripe to do.
