# 04 — Subscription State

Making the database the answer to "when is this member charged next, how much, and is the
subscription healthy" — for all three providers, without the caller knowing which one.

## 4.1 Why this is step one

Findings **B1–B6**: today a Direct Payment cannot reach its own subscriptions, a
subscription cannot reach its member, and `current_period_end` is null unless a webhook
happened to fire. Every later document in this plan queries this state. Nothing else can
be built first.

## 4.2 The layering rule this step must not break

**Direct Payments encapsulates the processor. The processor knows only about itself.**

`laravel_direct_payments.processor_reference_num` points *down* at a processor. Nothing in
the processor layer points *up*. That is why the SDK's own call chain has no DP in it:

```
api.ts  →  ProcessorClient.createSubscription()
        →  POST /portal/api/v1/processors/{provider}/create-subscription
           { processor_reference_num, email, customer_id, plan_id }
```

No Direct Payment reference is sent, and none should be. `ProcessorSubscriptionController`
resolves a processor, a customer and a plan, talks to Stripe/Square/PayPal, and writes what
the provider told it. It has no business knowing which Direct Payment — or which member —
sits above it, and giving it one would mean every future consumer of the processor layer
inherits a dependency on Direct Payments.

So the two link fields go in a **Direct-Payments-owned table** (§4.2.2), and
`laravel_processor_subscriptions` gains only fields that are facts *about the provider's own
subscription object*.

### 4.2.1 Columns added to `laravel_processor_subscriptions`

Migration `2026_08_25_000001_add_billing_fields_to_processor_subscriptions_table.php`,
purely additive, all nullable — an existing row stays valid and is backfilled by the first
sync sweep (§4.7).

Every column below is something the provider told us. Nothing here names a Direct Payment,
a member, a tier or a website.

| Column | Type | Purpose |
|---|---|---|
| `next_charge_at` | `timestamp` null, index | **D5.** The normalised next charge instant. **The only column any consumer reads** |
| `next_charge_amount_cents` | `integer` null | **B5.** So the email can state an amount without a fragile join |
| `currency` | `string(10)` null | Renders the amount |
| `cancel_at_period_end` | `boolean` default `false` | A period end that is a termination, not a charge |
| `current_period_start` | `timestamp` null | Bounds the period the send ledger keys on |
| `last_synced_at` | `timestamp` null, index | **B6.** Lets the sweep bound itself to stale rows |
| `last_sync_error` | `string(500)` null | Diagnostics; cleared on a successful sync |
| `provider_status` | `string(50)` null | The provider's raw status string, unmapped. `status` stays the normalised one |

Composite index for the scheduler's hot query:

```php
$table->index(['status', 'next_charge_at'], 'processor_subs_status_next_charge_idx');
```

`App\Models\Externals\Processor\ProcessorSubscription` gains all of the above to `$fillable`, and to
`$casts`: `next_charge_at`/`current_period_start`/`last_synced_at` → `datetime`,
`cancel_at_period_end` → `boolean`, `next_charge_amount_cents` → `integer`.

**It gains no relation to `DirectPayment`, `DirectPaymentNotification` or `Membership`.**
A `belongsTo` in this direction is the same violation as the column, one layer up.

> **Superseded 2026-09-23** — `member_reference_num` on this table (§4.2.2) and on
> `laravel_direct_payment_notification_sends` (step 05) was dropped and replaced by a
> generic `payer_reference_num`, pointing at `laravel_direct_payment_payers.reference_num`
> rather than `brain_memberships.reference_num`. The column was never actually
> tenant-generic — it was populated only on the Brain — which is exactly the "Direct
> Payments encapsulates the processor" layering violation this document itself warns
> against in §4.2, one level up: a DP-owned table naming a Brain-only concept. The
> Brain-specific bridge now lives entirely in `brain_memberships.payer_reference_num`.
> See `plans/brain_membership_identity/02-database-and-server.md` §2.1 (BM1) and its
> `complete/02-database-and-server.complete.md` for the full design. Every
> `member_reference_num` reference below (§4.2.2's table, §4.2.3, §4.2.4, §4.7 point 3)
> describes the original, now-replaced column — kept here as the historical record of
> this step's own design, not the current schema.

### 4.2.2 `laravel_direct_payment_subscriptions` — the link, owned by the DP layer

One row per (Direct Payment, provider subscription). This is the table that answers
**B1** and **B2**, and it lives beside `laravel_direct_payment_items`, `_tiers` and
`_connect` because it is the same kind of thing: a Direct Payments fact.

| Column | Type | Purpose |
|---|---|---|
| `id` | `id` | |
| `reference_num` | `string(32)` unique | `Str::random(12)` |
| `direct_payment_reference_num` | `string(128)` index | → `laravel_direct_payments.reference_num` |
| `provider_subscription_id` | `string(255)` index | The provider's own id — the join key into `laravel_processor_subscriptions.provider_subscription_id` |
| `member_reference_num` | `string(64)` null, index | → `brain_memberships.reference_num`. Populated on the Brain; **null on a tenant**, which has no members |
| `direct_payment_tier_reference_num` | `string(32)` null | → `laravel_direct_payment_tiers.reference_num`. Which tier was bought — the input to [08](../../billing/08-brain-enforcement.md) §8.3 |
| `canceled_at` | `timestamp` null | When the member cancelled. Billing stopped at the provider at this moment ([28](28-subscription-actions.md) §14.3) |
| `access_ends_at` | `timestamp` null, index | When their **benefits** stop — `canceled_at` for an `immediately` policy, else the `next_charge_at` they had already paid through. Read by [08](../../billing/08-brain-enforcement.md) §8.4.2 |
| `invoice_reference_num` | `string(64)` null | → `laravel_direct_payment_invoices`, when the flow made one |
| `timestamps` | | |

```php
$table->unique(['direct_payment_reference_num', 'provider_subscription_id'], 'dp_subscriptions_uq');
```

`canceled_at` and `access_ends_at` are **not** duplicates of provider state: the provider's
subscription is simply `canceled`, and has no concept of the entitlement window this
application grants afterwards. They are Direct Payments facts, which is why they live here.

**Note what is not here.** No `status`, no `next_charge_at`, no amount. Those live on the
processor row and are read through the join. Copying them up would create a second source
of truth that goes stale the moment a webhook lands — the same mistake in the opposite
direction.

The join every consumer uses:

```php
DirectPaymentSubscription::query()
    ->where('direct_payment_reference_num', $dpRef)
    ->join('laravel_processor_subscriptions as ps',
           'ps.provider_subscription_id', '=',
           'laravel_direct_payment_subscriptions.provider_subscription_id')
```

Reads go **downward** — DP → processor. Never the reverse.

### 4.2.3 Who writes the link row

The DP layer, because the DP layer is the only party that knows both halves. It already
holds `subResult` from `ProcessorClient.createSubscription()`, which carries the provider
subscription id.

```
api.ts  ─1─▶ ProcessorClient.createSubscription()   → provider_subscription_id
        ─2─▶ POST /portal/api/v1/directpayments/{dpRef}/subscriptions
             { provider_subscription_id, tier_reference_num?, invoice_reference_num? }
```

New endpoint on `DirectPaymentsController` (or a `DirectPaymentSubscriptionController`),
called from `api.ts` immediately after `subResult.success` in both
`_confirmSubscriptionPaymentInternal()` and the `charge()` subscription branch.

`member_reference_num` is **resolved server-side**, never taken from the request body: on
the Brain, from the Checkpoint bearer when present, else by matching the processor
customer's `provider_customer_id` against `brain_memberships.customer_id`. The ecosystem's
standing rule ([../../AFS-ECOSYSTEM-ARCHITECTURE-AND-DESIGN.md](../../AFS-ECOSYSTEM-ARCHITECTURE-AND-DESIGN.md)
§10), and the same rule [25](25-direct-payments-api.md) §7.3 applies to reads.

Step 2 failing must not fail the subscription — the money has already moved. Log it, return
success, and let §4.7's reconciler adopt the orphan.

### 4.2.4 Signup — where the member does not exist yet

§4.2.3's resolution assumes a member exists to resolve. At **signup** none does: the
subscription is created and confirmed before `registerMembership()` ever runs
([20](20-current-state-audit.md) §2.5d, **B40**). So the link row written by step 2 is born
with `member_reference_num = null`, and something has to adopt it.

**`MembershipController::store()` adopts it**, in the same transaction that creates the
member — it is the first moment both halves exist, and it already receives every field it
needs:

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/22-subscription-state.md §4.2.4.
 *
 * Signup creates the subscription BEFORE the member (afs/create.ts calls
 * confirmPayment() and only then registerMembership()), so the DP link row
 * written at §4.2.3 has no member to point at. This is the first moment both
 * exist. Keyed on the provider subscription id the client just passed as
 * `membership_id`.
 *
 * Adoption is best-effort and must never fail the signup — the member has
 * already paid. An unadopted row is reported by
 * `direct_payments:backfill-subscriptions` (§4.7) and adopted on its next run.
 */
if (!empty($data['membership_id'])) {
    DirectPaymentSubscription::where('provider_subscription_id', $data['membership_id'])
        ->whereNull('member_reference_num')
        ->update(['member_reference_num' => $membership->reference_num]);
}
```

### 4.2.5 The tier a member claims is not the tier they bought

**B38**: `store()` currently writes `'membership' => (int) ($data['membership'] ?? 0)`
straight from the request body, and the client sources that from
`sessionStorage.afs_selected_membership`. Anyone can post any tier, and the free path never
reaches a payment at all.

The register endpoint must **derive** the tier from what was actually paid for, not accept
it:

1. If `membership_id` (the provider subscription id) is present, resolve the tier the same
   way [08](../../billing/08-brain-enforcement.md) §8.3 does — link row → `direct_payment_tier_reference_num`
   → `laravel_direct_payment_tiers.level` — and use **that**.
2. If it resolves and disagrees with the posted `membership`, **use the resolved value and
   log the discrepancy.** Do not 422: the member has paid, and failing their signup over a
   mismatched hint is the wrong trade.
3. If there is no `membership_id` at all, the signup is a free one. Write **0**, whatever was
   posted.

Point 3 is the whole fix in one line: **no subscription means tier 0.** A paid tier must be
backed by a subscription this application can see, or it is not a paid tier.

This closes the write side of the forgeable-tier problem. It does **not** close the read side
— `alloc_v2` still takes a caller-supplied tier ([09](../../billing/09-entitlement-integration.md) §9.4) —
but it stops a forgery from being *persisted*, which is what makes the read side dangerous.

For it to work, the link row must exist by the time `store()` runs. §4.2.3's step 2 writes it
immediately after `createSubscription()`, so at signup it does. If step 2 failed, step 3's
fallback applies and the member lands on tier 0 with a `subscription_unlinked` log line —
recoverable by the backfill and by re-running the tier resolution, and **not** a silent
upgrade.

## 4.3 The normalisation rule — one column, three providers

This is decision **D5** and it is the crux of the document.

| Provider | Source field | Rule |
|---|---|---|
| **Stripe** | `subscription.current_period_end` (unix) | `next_charge_at = current_period_end`, **unless** `cancel_at_period_end === true` or `status ∈ {canceled, incomplete_expired, unpaid}` → `null`. During a trial, `trial_end` and `current_period_end` coincide; use `current_period_end` and let `trial_ends_at` carry the trial fact |
| **Square** | `subscription.charged_through_date` (`YYYY-MM-DD`), else `paid_until_date` | `next_charge_at = charged_through_date` at `00:00:00 UTC`. Square bills *on* that date. `canceled_date` present → `null` |
| **PayPal** | `subscription.billing_info.next_billing_time` (ISO-8601) | Direct. `status ∈ {CANCELLED, EXPIRED, SUSPENDED}` → `null`. PayPal gives this field natively, which is why it is the model the other two are normalised *to* |

And for the amount:

| Provider | Source |
|---|---|
| Stripe | `items.data[0].price.unit_amount × quantity`, or `latest_invoice.amount_due` when present (it already includes proration and discounts) |
| Square | `subscription.price_override_money.amount`, else the catalog plan's phase amount |
| PayPal | `billing_info.last_payment.amount.value` × 100, else the plan's `billing_cycles[].pricing_scheme.fixed_price` |

Status normalisation into the existing `status` column:

| Normalised | Stripe | Square | PayPal |
|---|---|---|---|
| `active` | `active` | `ACTIVE` | `ACTIVE` |
| `trialing` | `trialing` | `PENDING` (with a future start) | `APPROVAL_PENDING`/trial |
| `past_due` | `past_due`, `unpaid` | `DEACTIVATED` w/ unpaid invoice | `SUSPENDED` |
| `canceled` | `canceled`, `incomplete_expired` | `CANCELED` | `CANCELLED`, `EXPIRED` |
| `incomplete` | `incomplete` | `PENDING` | `APPROVAL_PENDING` |

`provider_status` keeps the raw string so a mapping mistake is diagnosable rather than
lost.

> **Rule to hold everywhere downstream:** a consumer reads `next_charge_at` and `status`
> and **never** branches on `provider`. If a consumer needs to know the provider, the
> normalisation is incomplete — fix it here.

## 4.4 `SubscriptionSyncService`

`www/app/Externals/DirectPayments/Subscriptions/SubscriptionSyncService.php`, with one reader per provider
implementing:

```php
interface SubscriptionReader
{
    /**
     * Fetch one subscription from the provider and return it in NORMALISED form.
     *
     * @return array{
     *   status: string, provider_status: ?string, next_charge_at: ?CarbonInterface,
     *   next_charge_amount_cents: ?int, currency: ?string, cancel_at_period_end: bool,
     *   current_period_start: ?CarbonInterface, current_period_end: ?CarbonInterface,
     *   trial_ends_at: ?CarbonInterface
     * }
     * @throws SubscriptionGoneException  provider returned 404 — the subscription
     *                                    no longer exists (see §4.5)
     * @throws SubscriptionUnavailableException  transport/auth failure — the caller
     *                                    must KEEP the stored values (§1.5)
     */
    public function read(Processor $processor, string $providerSubscriptionId): array;
}
```

Readers reuse the existing credential handling — `Processor::getSecretKey()` (decrypting
`secret_key_encrypted`) and the same base-URL/mode logic `ProcessorSyncController` already
uses for its dry-run catalog reads. No new key storage.

### Candidate selection — what gets synced on a tick

Unbounded syncing would hit provider rate limits on a large tenant. The sweep takes only:

```php
ProcessorSubscription::query()
    ->whereIn('status', ['active', 'trialing', 'past_due', 'incomplete'])
    ->where(function ($q) use ($horizon) {
        $q->whereNull('next_charge_at')                          // B3 backfill
          ->orWhere('next_charge_at', '<=', $horizon)            // inside a notice window
          ->orWhereNull('last_synced_at')
          ->orWhere('last_synced_at', '<=', now()->subHours(24)); // daily freshness floor
    })
    ->orderByRaw('last_synced_at IS NULL DESC')                  // never-synced first
    ->orderBy('last_synced_at')
    ->limit(config('billing.sync_limit_per_run', 200))
    ->get();
```

`$horizon = now()->addDays($maxDaysBefore + 1)` where `$maxDaysBefore` is the largest
`days_before` across all active notification configs — so a deployment that only notifies
1 day ahead does not sync a subscription that renews in six days, and a deployment with no
`upcoming_charge` config at all syncs only on the 24-hour freshness floor.

At 200/run × 96 runs, that is up to 19 200 syncs a day — comfortably inside Stripe's
100 req/s and PayPal's per-app limits, and the cap is configurable per deployment.

## 4.5 Handling a subscription that is gone

`SubscriptionGoneException` (provider 404) → set `status = 'canceled'`,
`provider_status = 'gone'`, `next_charge_at = null`, `last_synced_at = now()`. **Never
delete the row.** The enforcement ladder ([08](../../billing/08-brain-enforcement.md)) needs the history,
and the send ledger has a foreign-key-by-convention reference to
`provider_subscription_id`.

`SubscriptionUnavailableException` (timeout, 5xx, auth failure) → write **only**
`last_sync_error` and leave every value field alone. This is the fail-safe rule from
[01](../../billing/01-architecture.md) §1.5: a provider outage must never be read as "no next charge",
because "no next charge" would suppress a notification the member is owed and, on the
Brain, look like a cancelled subscription to the dunning ladder.

## 4.6 Fixing the creation and webhook paths

Migrations alone leave the columns null for new subscriptions. Three code changes:

1. **`ProcessorSubscriptionController::createStripeSubscription()`** (line 280),
   `createSquareSubscription()` (433), `createPayPalSubscription()` (498) — extend the
   `ProcessorSubscription::create([...])` array with the **normalised provider fields**
   read straight off the creation response (Stripe's create response already contains
   `current_period_end` and, with the existing `expand[]=latest_invoice.payment_intent`,
   the amount). **B3.**

   > **These three methods gain no new request fields.** No `direct_payment_reference_num`,
   > no `member_reference_num`, no DP model import. Their signature and their knowledge stay
   > exactly what they are today (§4.2). The link is written afterwards, by the DP layer,
   > through its own endpoint (§4.2.3).

2. **`ProcessorWebhookController::logStripeSubscription()`** (634) /
   `logSquareSubscription()` (925) — route the payload through the same normaliser rather
   than hand-mapping `current_period_end`, so push and pull can never disagree.

3. **`ProcessorWebhookController::logPayPalSubscription()`** (1440) — change the bare
   `->update()` to `updateOrCreate()` keyed on `provider_subscription_id`, matching the
   Stripe and Square handlers. **B4.**

## 4.7 Backfill

Two commands, one per layer, because the two halves belong to different owners and fail
independently.

**`php artisan processor:backfill-subscriptions`** — processor layer, DP-agnostic:

1. For every `laravel_processor_subscriptions` row with a `provider_subscription_id`, run
   the reader. Populates `next_charge_at` and friends. **B3.**

**`php artisan direct_payments:backfill-subscriptions`** — DP layer, builds the §4.2.2 link
rows for subscriptions that predate the endpoint:

2. Resolve the Direct Payment from `laravel_direct_payment_invoices` where a subscription
   invoice exists, else from the DP whose `processor_reference_num` matches **and** whose
   `pay_type = 'SUBSCRIPTION'` — and where that is ambiguous, **write nothing** and report
   the count. A wrong DP link would send the wrong deployment's email to the wrong
   customer.
3. Resolve `member_reference_num` on the Brain by joining
   `laravel_processor_customers.provider_customer_id` → `brain_memberships.customer_id`.
4. Resolve `direct_payment_tier_reference_num` from the DP's tiers by
   `processor_tier_id` = the subscription's plan's `provider_plan_id`, where it matches
   exactly one.

The same command is the **orphan reconciler** for §4.2.3's step-2 failure, so it is worth
running periodically rather than once. It is not scheduled in this plan — add it to
`routes/console.php` daily if orphans turn out to be common.

Run both with `--dry-run` first; they print per-provider counts and the ambiguous set.

## 4.8 Migrations in this step

| File | Contents |
|---|---|
| `2026_08_25_000001_add_billing_fields_to_processor_subscriptions_table.php` | §4.2.1 columns + composite index. `down()` drops them |
| `2026_08_25_000004_create_direct_payment_subscriptions_table.php` | §4.2.2, the DP-owned link table |

All guarded with `Schema::hasColumn()` so a partially-migrated tenant is safe to re-run —
the same defensive style as `2026_05_21_000001_create_processor_tables.php`'s
`Schema::hasTable()` guards.

## 4.9 Acceptance

- Every `active`/`trialing` subscription has a non-null `next_charge_at` within 24 hours of
  the backfill.
- Turning webhooks **off** and waiting 24 h still yields a fresh `next_charge_at`.
- Killing provider network access for one tick leaves every `next_charge_at` unchanged and
  populates `last_sync_error`.
- A consumer can render the next charge for a Stripe, a Square and a PayPal subscription
  with identical code.
- **`grep -rn "direct_payment\|DirectPayment\|brain_membership\|Membership" app/Http/Controllers/Externals/Processor/ app/Models/Externals/Processor/` returns nothing this plan added.** The
  processor layer's knowledge is unchanged. Make this a CI check, not a review habit — it
  is the kind of boundary that erodes one convenient import at a time.
- Creating a subscription with the DP link endpoint unreachable still succeeds, and
  `direct_payments:backfill-subscriptions` adopts the orphan afterwards.
