# 02 — Database and Server

## 2.1 Schema change

```php
/**
 * AFS_MEMBERSHIP_MODIFIER_ADDITION — plans/brain_membership_identity/02-database-and-server.md §2.1 (BM1).
 *
 * payer_reference_num replaces customer_id as the identity binding between a
 * member and Direct Payments. UNIQUE — unlike customer_id, which never had
 * any constraint at all (BM1) — because one payer belongs to exactly one
 * member and one member has exactly one payer (00 §"Where brain_memberships
 * sits" — this is the simple one-column case, not a link table).
 */
Schema::table('brain_memberships', function (Blueprint $table) {
    $table->char('payer_reference_num', 32)->nullable()->unique('brain_memberships_payer_uq')
        ->after('customer_id');
});
```

`customer_id` is **not dropped in this migration.** It is dropped in Phase 4
([04](04-rollout-and-testing.md) §4.1), after every row has a `payer_reference_num` and nothing
reads the old column anymore. Dropping it here would be the exact "seam inconsistency" pattern
this whole plan-set keeps finding elsewhere — a schema change with no window for the code that
still reads the old value to catch up.

`app/Models/Membership.php`'s `$fillable` gains `payer_reference_num` alongside `customer_id`
(both present through the deprecation window).

## 2.2 The migration, in order

| Phase | What | `customer_id` | `payer_reference_num` |
|---|---|---|---|
| 1 | Add column | written | written (dual-write, §2.4) |
| 2 | Client cutover ([03](03-client-sdk-and-flow.md)) | written (server ignores it after this phase) | written, and the only one used |
| 3 | Backfill existing rows | — | backfilled from `customer_id` where possible (§2.5) |
| 4 | Drop `customer_id` | **dropped** | sole source of truth |

Full phase detail, including what "backfilled where possible" means for a member whose provider
customer no longer resolves, is in [04](04-rollout-and-testing.md) §4.1.

## 2.3 `MembershipController::store()` — the write path (BM1, BM6)

```php
/**
 * AFS_MEMBERSHIP_MODIFIER_ADDITION — plans/brain_membership_identity/02-database-and-server.md §2.3 (BM1, BM6).
 *
 * BEFORE: 'customer_id' => $data['customer_id'] ?? null — the raw provider id,
 * straight from the client, no validation it even looks like one.
 *
 * AFTER: payer_reference_num, resolved the same way direct_payments/05 §5.3
 * already resolves it for every other implementer — from the checkout result,
 * never from a client-asserted string alone. The client still SENDS it
 * (03 §3.2) because AFS has no server-side checkout session to re-derive it
 * from at registration time, but it is validated against Direct Payments
 * before being trusted (§2.3.1), which customer_id never was.
 *
 * `processor_reference_num` (BM6 — the field the server always silently
 * dropped) is removed from both sides; it never had a matching column and
 * carried the wrong value besides (01 §1.6).
 */
$payerReferenceNum = $this->resolveAndValidatePayer($data['payer_reference_num'] ?? null, $data['direct_payment'] ?? null);

$membership = Membership::create([
    'reference_num'        => $data['reference_num'] ?? Str::random(12),
    'username'             => $data['username'] ?? null,
    'email'                => $data['email'] ?? null,
    'firstname'            => $data['firstname'] ?? null,
    'lastname'             => $data['lastname'] ?? null,
    'membership'           => $membershipTier,
    'user_id'              => $userId,
    'street'               => $data['street'] ?? null,
    'city'                 => $data['city'] ?? null,
    'state'                => $data['state'] ?? null,
    'zip_code'             => $data['zip_code'] ?? null,
    'country_code'         => $data['country_code'] ?? null,
    'country_name'         => $data['country_name'] ?? null,
    'processor'            => $data['processor'] ?? null,
    'direct_payment'       => $data['direct_payment'] ?? null,
    'payer_reference_num'  => $payerReferenceNum,
    'membership_id'        => $data['membership_id'] ?? null,
    'json_data'            => empty($jsonData) ? null : $jsonData,
    'account_status'       => isset($data['account_status']) ? (int) $data['account_status'] : 1,
]);

/**
 * AFS_MEMBERSHIP_MODIFIER_ADDITION — plans/brain_membership_identity/02-database-and-server.md §2.3 (BI2).
 *
 * The one server-side email write that replaces BOTH client-side
 * updateCustomerEmail() call sites (01 §1.3) — the sync path and the
 * Stripe-redirect path both end up here, at the moment the account's
 * authoritative email is known, so there is exactly one place this can go
 * stale instead of two.
 */
if ($payerReferenceNum !== null && !empty($data['email'])) {
    $fullName = trim(($data['firstname'] ?? '') . ' ' . ($data['lastname'] ?? '')) ?: null;
    try {
        app(PayerService::class)->updateEmail($payerReferenceNum, $data['email'], $fullName);
    } catch (\Throwable $e) {
        // Best-effort, same reasoning as PayerService::updateEmail() itself
        // (direct_payments/05 §5.5) — the signup has already succeeded and
        // already paid; a provider timeout here must not fail it.
        Log::error('[Membership] payer email not propagated', ['payer' => $payerReferenceNum, 'error' => $e->getMessage()]);
    }
}
```

### 2.3.1 Why the payer still needs validating, not just trusting

`payer_reference_num` arrives from the client the same way `customer_id` used to — this is an
unauthenticated public endpoint (01 §1.4's neighbor finding; also the injection history at
`MembershipController.php:60-92`). The difference from before is that it is checked against
something real before being written:

```php
/**
 * AFS_MEMBERSHIP_MODIFIER_ADDITION — plans/brain_membership_identity/02-database-and-server.md §2.3.1.
 *
 * A payer reference this endpoint has never heard of is worse than none —
 * it would let anyone claim any other member's saved cards by reusing their
 * payer_reference_num on a fresh signup. Confirming the payer actually
 * belongs to THIS Direct Payment (AFS's own, never a tenant's) is the one
 * check that makes trusting a client-supplied reference safe.
 */
private function resolveAndValidatePayer(?string $payerReferenceNum, ?string $directPaymentRef): ?string
{
    if ($payerReferenceNum === null) {
        return null; // a genuinely free signup may have no payer at all
    }
    if (!preg_match('/^[a-zA-Z0-9]{32}$/', $payerReferenceNum)) {
        return null;
    }
    $exists = app(PayerService::class)->belongsToDirectPayment($payerReferenceNum, $directPaymentRef ?? self::AFS_DIRECT_PAYMENT_REF);
    return $exists ? $payerReferenceNum : null;
}
```

`belongsToDirectPayment()` is direct_payments/02's own payer table, queried by DP ref + payer ref
— no new method needed there beyond what
[`direct_payments/02-payers-and-bindings.md`](../www/direct_payments/02-payers-and-bindings.md)
already exposes for exactly this kind of check.

## 2.4 Dual-write window (Phase 1-2)

While both columns exist, `store()` writes both:

```php
'customer_id'          => $customerId,          // legacy — read by nothing after Phase 2, still populated for rollback safety
'payer_reference_num'  => $payerReferenceNum,
```

`$customerId` during this window still comes from the client's `getStoredCustomerId()` call,
because the SDK cutover (03) and the schema/dual-write land as separate deploys — this table has
no reason to be the first thing to break if the SDK side needs a hotfix.

## 2.5 Backfilling existing rows (Phase 3)

Every existing `brain_memberships` row has a `customer_id` (a `cus_…` string) and a `processor`
(which PSP). Backfill resolves the payer the same way
[`direct_payments/05-implementer-contract.md`](../www/direct_payments/05-implementer-contract.md)
§5.5's Site Users fold does it — find-or-create a payer for the known provider customer, no
provider call needed because the mapping already exists:

```php
/**
 * AFS_MEMBERSHIP_MODIFIER_ADDITION — plans/brain_membership_identity/02-database-and-server.md §2.5.
 */
Artisan::command('brain-membership:backfill-payers', function () {
    Membership::whereNotNull('customer_id')->whereNull('payer_reference_num')
        ->chunkById(500, function ($rows) {
            foreach ($rows as $membership) {
                $payerRef = app(PayerService::class)->findOrCreateFromProviderCustomer(
                    processorReferenceNum: $membership->processor,
                    providerCustomerId:    $membership->customer_id,
                    email:                 $membership->email,
                    name:                  trim("{$membership->firstname} {$membership->lastname}"),
                );
                $membership->update(['payer_reference_num' => $payerRef]);
            }
        });
});
```

A member whose `processor` row was deleted rather than retired (the same edge case
[`direct_payments/04`](../www/direct_payments/04-history-refunds-and-webhooks.md) §4.3 names for
refunds) is skipped and logged rather than failing the batch — recoverable by restoring the
processor record, same as that document's `DP_E_ORIGIN_PROCESSOR_MISSING`.

## 2.6 `MembershipController::update()` — the admin path (BM4)

```php
// BEFORE
$allowed = $request->only([
    'account_status', 'account_close_date', 'membership', 'level',
    'customer_id', 'firstname', 'lastname',
]);

// AFTER — payer_reference_num joins direct_payment/processor/membership_id in
// the set of fields this endpoint has never let an admin touch, for the same
// reason: it is a payment-provider identity binding, not a profile field.
$allowed = $request->only([
    'account_status', 'account_close_date', 'membership', 'level',
    'firstname', 'lastname',
]);
```

An admin who genuinely needs to re-point a member's payer (a support case, a manual merge) does
it through Direct Payments' own swap/admin tooling
([`direct_payments/03-the-swap.md`](../www/direct_payments/03-the-swap.md)), which has the
audit trail this endpoint does not.

## 2.7 Acceptance

- `grep -n "'customer_id'" app/Http/Controllers/Portal/Admin/MembershipController.php` — after
  Phase 4, no matches.
- A member row created after Phase 2 has a `payer_reference_num` and a **null** `customer_id`.
- `resolveAndValidatePayer()` rejects a payer reference that exists but belongs to a different
  Direct Payment.
- The admin `update()` endpoint returns the field unmodified (silently ignored, not a 422) if
  `payer_reference_num` or `customer_id` is posted — matching how `direct_payment` already
  behaves today.
- Backfill leaves no row with both `customer_id` set and `payer_reference_num` null, except rows
  logged as skipped with a named reason.
