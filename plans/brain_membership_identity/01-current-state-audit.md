# 01 — Current State Audit

Every finding below is quoted from the actual code as it stands, not summarized from memory.
File:line references are exact as of this audit.

## 1.1 BM1 — `customer_id` is a bare, unindexed, non-unique raw provider id

`database/migrations/2026_05_15_000001_create_brain_tables.php:241`:

```php
// PSP customer ID (e.g. cus_xxx for Stripe)
$table->string('customer_id', 128)->nullable();
```

No index, no unique constraint — contrast with `reference_num`, `username`, `email` on the same
table, all `->unique()`. Two consequences:

- Nothing prevents two members from racing to the same `customer_id`, or an admin overwriting one
  member's value with another's (§1.4).
- There is no fast path from "a webhook/event names this provider customer" back to the member it
  belongs to — a full-table scan is the only option.

This is exactly the shape [`../www/direct_payments/05-implementer-contract.md`](../www/direct_payments/05-implementer-contract.md)
§5.6 forbids for every implementer it covers: *"Store, log or display a `cus_…`... It dies at the
next swap, and a module holding one is a module that will break."* `brain_memberships` was never
in that document's scope (README §"Why this is its own folder") — it does the forbidden thing
anyway, just never got named for it until now.

## 1.2 BM2 — The client reads the exact method 05 §5.3 retires

`resources/js/afs/create.ts:261` and `:384`:

```ts
const customerId = getDirectPayments().getStoredCustomerId() ?? undefined;
```

`getStoredCustomerId()` is the method direct_payments/05 §5.3 removes from the SDK's public
surface for every other caller: *"Neither the page nor the module ever sees a `cus_…` again."*
AFS's own signup flow is the SDK's most direct consumer of the interface that document already
redesigned — it just hadn't been updated to match yet, because nothing in this session's earlier
passes had looked at `resources/js/afs/` at all.

## 1.3 BM3 — Client-side email attachment, twice, for a provider-specific reason

`create.ts:256-265` (after a Stripe Checkout redirect returns):

```ts
// Payment was confirmed by Stripe — now update the anonymous provider customer with email.
...
const fullName = `${firstName} ${lastName}`.trim() || undefined;
const customerId = getDirectPayments().getStoredCustomerId() ?? undefined;

if (email) {
    await getDirectPayments().updateCustomerEmail(email, fullName);
}
```

And `create.ts:425-426`, in the non-redirect path:

```ts
// confirmPayment updates the customer email internally on sync success.
// For redirect (result.url), the update happens in handleStripeReturn.
```

So there are **two** code paths attaching the same information (email + name, both already known
before payment even starts — `handleSubmit()` reads them from the form at lines 381, 395-396) to
the provider customer, purely because Stripe's redirect flow doesn't carry them through the same
way the synchronous flow does. This is provider plumbing leaking into the implementer, which is
the specific thing the façade contract exists to prevent
([`../www/ecommerce/AUDIT-direct-payments-decoupling.md`](../www/ecommerce/AUDIT-direct-payments-decoupling.md)).
Fixed in [03](03-client-sdk-and-flow.md) §3.3 by moving the email write server-side, once, where
both paths already converge (`MembershipController::store()`).

## 1.4 BM4 — The admin API can freely overwrite `customer_id`

`app/Http/Controllers/Portal/Admin/MembershipController.php:373-381`:

```php
$allowed = $request->only([
    'account_status',
    'account_close_date',
    'membership',
    'level',
    'customer_id',
    'firstname',
    'lastname',
]);

$membership->fill($allowed);
$membership->save();
```

`customer_id` sits in the same allow-list as `firstname`/`lastname` — free-text fields with no
consequence if wrong. Note what is **not** in this list: `direct_payment`, `processor`,
`membership_id`. Those are already treated as write-once-by-the-signup-flow, never
admin-editable. `customer_id` should have been in that second group from the start; it wasn't,
because until this plan there was no separate "identity" concept to protect — it looked like just
another string field. Fixed in [02](02-database-and-server.md) §2.3 by dropping it from
`$allowed` entirely, same as its neighbors.

## 1.5 BM5 — Three names for the same Direct Payment, kept in three places

| File | How it names AFS's own Direct Payment |
|---|---|
| `main.ts:16` | `const AFS_DP_ID = import.meta.env.VITE_DIRECT_PAYMENT_ID;` |
| `memberships/index.ts:55` | `const AFS_DIRECT_PAYMENT_ID = 'vfPSdk2xj';` — a hardcoded literal |
| `create.ts:124,128` | `sessionStorage.getItem('afs_selected_direct_payment_id')` — set by whichever of the above ran first via `setDirectPaymentsInstance()` |

Three sources of truth for one value. If the env var (`main.ts`) is ever repointed — a new
membership product, a staging vs. production Direct Payment — the hardcoded literal in
`memberships/index.ts` silently keeps using the old one, because nothing connects them. This is
not a payer-model problem and is not blocking this plan, but it was found auditing the exact
files this plan rewrites and is recorded so it isn't lost. Folded into [03](03-client-sdk-and-flow.md)
§3.4 as a low-risk, do-while-we're-here fix: `memberships/index.ts` reads the same env var
`main.ts` does, and `create.ts`'s session-stored value is only ever seeded from that one source.

## 1.6 BM6 — A wire field the server silently never reads

`memberships/index.ts:456-458` builds:

```ts
processor:          processorId,
processor_reference_num:       dpId || undefined,
direct_payment:     dpRef || undefined,
```

`brain_memberships` has columns `processor` and `direct_payment` — **not** `processor_reference_num`.
`MembershipController::store()`'s `Membership::create([...])` (lines 187-207) never reads
`$data['processor_reference_num']` at all. It is computed, serialized, sent over the wire, and
dropped on the floor server-side, every single signup. Separately, `dpId` here comes from
`getSelectedTierPlan().dpId` — the Direct Payment's own client-facing identifier (BM5's value),
not a processor/PSP account reference — so even if the server did read it, the field name would
be describing the wrong thing. Fixed in [03](03-client-sdk-and-flow.md) §3.5 by deleting the
field from the payload; `direct_payment` (from `getDirectPaymentRef()`) already carries the
correct value and is the only one the server uses.

## 1.7 BM7 — Corroborating evidence for direct_payments' D2, found in production code

`MembershipController.php:161-164`, resolving what tier a completed payment actually paid for:

```php
$link = DirectPaymentSubscription::where('provider_subscription_id', $membershipId)->first();
$resolvedLevel = $link && $link->direct_payment_tier_reference_num
    ? DirectPaymentTier::where('reference_num', $link->direct_payment_tier_reference_num)->value('level')
    : null;
```

Confirmed by reading `app/Models/Externals/DirectPaymentSubscription.php` directly: `laravel_direct_payment_subscriptions`
has `provider_subscription_id`, `direct_payment_tier_reference_num`, `member_reference_num` — and
**no `processor_reference_num` column**, exactly as
[`direct_payments/01-current-state-audit.md`](../www/direct_payments/01-current-state-audit.md)'s
D2 finding states from the billing side. This is not a new finding — D2 already covers it and
[`direct_payments/04`](../www/direct_payments/04-history-refunds-and-webhooks.md) §4.4 already
fixes it — but it's worth recording here that this exact controller, the one this plan also
touches, is a live consumer of the table D2 describes, once as concrete proof the bug is real and
not hypothetical. This plan makes no changes on account of BM7; D2's fix covers it.

## 1.8 Not a finding — `brain_membership_billing_state` already does this right

`brain_membership_billing_state` (same migration, lines 280-303) already carries its own
`processor_reference_num` and `direct_payment_reference_num` per row, alongside
`provider_subscription_id`. That is the D2-shaped fix, already in place, for a table that
predates this plan and predates direct_payments/ itself. Confirmed here only so nobody re-flags
it while reading this table's neighbor.

## 1.9 Summary

| # | Where | What | Fixed in |
|---|---|---|---|
| BM1 | `brain_memberships.customer_id` | Raw PSP id, no index, no unique | [02](02-database-and-server.md) §2.1-2.2 |
| BM2 | `create.ts:261,384` | Reads `getStoredCustomerId()`, the retired method | [03](03-client-sdk-and-flow.md) §3.2 |
| BM3 | `create.ts:264,425` | Client-side email attach, twice, provider-quirk-driven | [03](03-client-sdk-and-flow.md) §3.3 |
| BM4 | `MembershipController::update()` | `customer_id` freely admin-editable | [02](02-database-and-server.md) §2.3 |
| BM5 | `main.ts` / `memberships/index.ts` / `create.ts` | Three sources of truth for one DP id | [03](03-client-sdk-and-flow.md) §3.4 |
| BM6 | `memberships/index.ts:457` | Wire field the server never reads; wrong value anyway | [03](03-client-sdk-and-flow.md) §3.5 |
| BM7 | `MembershipController::store()` | Corroborates D2 — no fix needed here | direct_payments/04 |
