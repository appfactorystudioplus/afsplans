# 03 — Client SDK and the `resources/js/afs/` Rewrite

> **Status 2026-09-23 — partly built; the rest is closed as won't-do.** See
> [complete/03-client-sdk-and-flow.complete.md](complete/03-client-sdk-and-flow.complete.md).
>
> | Section | Outcome |
> |---|---|
> | §3.2 / §3.3 (BM3, client `updateCustomerEmail()`) | **Built** |
> | §3.4 (BM5, one DP id source) | **Already done** by `membership_change/07-signup-flow.md` §S15 |
> | §3.5 (BM6, dead wire field) | **Built** — only the `processor_reference_num` removal; the `payer_reference_num` payload field in the BEFORE/AFTER below was **not** added |
> | §3.1 / §3.2 (BM2, `getStoredPayerReferenceNum()`, removing `getStoredCustomerId()`) | **Won't do under the current design.** The SDK has no payer resolver to read from (direct_payments/05 §5.3 was never built), and `getStoredCustomerId()` is shared SDK internals used by every tenant's subscription checkout. `create.ts` keeps sending `customer_id`; the server links it once via `recordExistingCustomer()` and stores nothing. Reopen only if that payer resolver is built |
>
> The code sketches below are the original design, kept as the record; where they conflict
> with the table above, the table wins.

## 3.1 The SDK surface AFS uses

Everything AFS calls already exists as a documented method on `PortalDirectPaymentsAPI`
(01 summary table, direct_payments/05 §5.3). This plan adds exactly one method and removes one:

| Method | Change |
|---|---|
| `getStoredCustomerId()` | **Removed.** The exact method direct_payments/05 §5.3 retires SDK-wide (BM2) |
| `getStoredPayerReferenceNum()` | **Added.** Same shape as its siblings `getStoredSubscriptionId()` / `getStoredProcessorId()` — returns the payer reference Direct Payments minted for this checkout, cached client-side after `confirmPayment()` / `setupSubscriptionPayment()` succeeds |
| `updateCustomerEmail(email, name)` | **Removed.** Replaced by the server-side `PayerService::updateEmail()` call in [02](02-database-and-server.md) §2.3 (BM3, BI2) |

No other method AFS calls (`createInvoice`, `confirmPayment`, `setupSubscriptionPayment`,
`getSelectedTierPlan`, `getStoredSubscriptionId`, `getStoredProcessorId`, `getDirectPaymentRef`,
`resolveDiscount`, `estimateTax`, `getSubscriptionTierAmountCents`, `clearSession`) changes shape.

## 3.2 `create.ts` — the two `getStoredCustomerId()` call sites (BM2)

`handleStripeReturn()`, currently lines 256-275:

```ts
// BEFORE
const customerId = getDirectPayments().getStoredCustomerId() ?? undefined;
if (email) {
    await getDirectPayments().updateCustomerEmail(email, fullName);
}
try {
    await createMemberAllocation(memberRef);
    const subscriptionId = getDirectPayments().getStoredSubscriptionId()
        ?? urlParams.get('subscription_id')
        ?? undefined;
    await registerMembership(customerId, subscriptionId, memberRef);
    ...

// AFTER
/**
 * AFS_MEMBERSHIP_MODIFIER_ADDITION — plans/brain_membership_identity/03-client-sdk-and-flow.md §3.2 (BM2, BM3).
 *
 * No email attach here anymore — MembershipController::store() does it once,
 * server-side, for both this path and handleSubmit()'s (02 §2.3, BI2). This
 * function's whole job at this point is reduced to reading back what the
 * completed checkout already produced.
 */
const payerReferenceNum = getDirectPayments().getStoredPayerReferenceNum() ?? undefined;
try {
    await createMemberAllocation(memberRef);
    const subscriptionId = getDirectPayments().getStoredSubscriptionId()
        ?? urlParams.get('subscription_id')
        ?? undefined;
    await registerMembership(payerReferenceNum, subscriptionId, memberRef);
    ...
```

The `email`/`fullName` locals at lines 257-260 stay — `registerMembership()`'s payload already
sends `email`/`firstname`/`lastname` independently (`memberships/index.ts:437-455`), which is what
the server now uses for `PayerService::updateEmail()`. Nothing downstream of this function needed
them for the removed call alone.

`handleSubmit()`, currently line 380-384 and 434-440:

```ts
// BEFORE
const customerId = getDirectPayments().getStoredCustomerId() ?? undefined;
...
await registerMembership(customerId, subscriptionId, memberRef);

// AFTER
const payerReferenceNum = getDirectPayments().getStoredPayerReferenceNum() ?? undefined;
...
await registerMembership(payerReferenceNum, subscriptionId, memberRef);
```

The comment at line 425-426 ("confirmPayment updates the customer email internally on sync
success...") is deleted along with the behavior it describes — email attachment is no longer a
client-side concern on either path (BI2).

## 3.3 Why removing `updateCustomerEmail()` from the client is a strict improvement, not just a rename

Before this plan, the browser held a method that could rewrite the email attached to a payment
provider's record of a person, callable any time `getDirectPayments()` returns an instance —
which is most of `create.ts`'s lifetime, including before the corresponding `brain_memberships`
row exists. After this plan, that write only happens from `MembershipController::store()`,
authenticated by nothing more than the same unauthenticated-but-validated path every signup
already goes through (02 §2.3.1) — but it happens exactly once, in one place, for a payer the
server has already confirmed belongs to AFS's own Direct Payment. This mirrors
[`direct_payments/05`](../www/direct_payments/05-implementer-contract.md) §5.5's own reasoning for
why Site Users' email change is "one call... not a loop [the caller] runs itself."

## 3.4 `memberships/index.ts` — collapsing three sources of truth into one (BM5)

```ts
// BEFORE
const AFS_DIRECT_PAYMENT_ID = 'vfPSdk2xj';

// AFTER
/**
 * AFS_MEMBERSHIP_MODIFIER_ADDITION — plans/brain_membership_identity/03-client-sdk-and-flow.md §3.4 (BM5).
 *
 * The same env var main.ts already reads. Before this, a repointed
 * VITE_DIRECT_PAYMENT_ID changed which Direct Payment the pricing page used
 * without changing which one this file's discount/tax helpers queried —
 * silently wrong for the entire lifetime of any deploy that touched it.
 */
const AFS_DIRECT_PAYMENT_ID = import.meta.env.VITE_DIRECT_PAYMENT_ID;
```

`create.ts`'s `SELECTED_DP_ID_KEY` sessionStorage value is unaffected by this change — it is
still seeded once, by whichever entry point (`main.ts`'s pricing page, or a direct `/create` load)
calls `setDirectPaymentsInstance(dpId)` first, and that `dpId` now always traces back to the same
env var no matter which entry point ran.

## 3.5 `memberships/index.ts` — the payload (BM1, BM6)

```ts
// BEFORE
export interface MembershipCreationPayload {
    ...
    processor?: string;
    processor_reference_num?: string;
    direct_payment?: string;
    discount_code?: string;
    customer_id?: string;
    ...
}

// AFTER
export interface MembershipCreationPayload {
    ...
    processor?: string;
    direct_payment?: string;
    discount_code?: string;
    payer_reference_num?: string;
    ...
}
```

`registerMembership()`'s signature and body:

```ts
// BEFORE
export async function registerMembership(customerId?: string, subscriptionId?: string, referenceNum?: string): Promise<void> {
    ...
    processor:          processorId,
    processor_reference_num:       dpId || undefined,
    direct_payment:     dpRef || undefined,
    discount_code:      _getInput('field_discount_code')?.value?.trim() || undefined,
    customer_id:        customerId || undefined,
    ...

// AFTER
/**
 * AFS_MEMBERSHIP_MODIFIER_ADDITION — plans/brain_membership_identity/03-client-sdk-and-flow.md §3.5 (BM1, BM6).
 *
 * `dpId` (from getSelectedTierPlan()) was being sent as processor_reference_num
 * — a field brain_memberships doesn't have and MembershipController::store()
 * never read (01 §1.6). Deleted rather than renamed: direct_payment (dpRef,
 * from getDirectPaymentRef()) already carries the value the server actually
 * uses, and processor (processorId) already carries which PSP account —
 * nothing was missing, the field was purely dead weight.
 */
export async function registerMembership(payerReferenceNum?: string, subscriptionId?: string, referenceNum?: string): Promise<void> {
    let processorId: string | undefined;
    let dpRef: string | undefined;

    try {
        processorId = getMembershipDirectPayments().getStoredProcessorId() ?? undefined;
        dpRef = getMembershipDirectPayments().getDirectPaymentRef() ?? undefined;
    } catch {
        // Safe fallback
    }

    ...
    const payload: MembershipCreationPayload = {
        ...
        processor:            processorId,
        direct_payment:       dpRef || undefined,
        discount_code:        _getInput('field_discount_code')?.value?.trim() || undefined,
        payer_reference_num:  payerReferenceNum || undefined,
        source:               'client',
    };
    ...
```

The `dpId`/`getSelectedTierPlan()` local is deleted along with the field it only ever fed.

## 3.6 What does not change

- `getOrGenerateMemberReferenceNum()` — untouched; `reference_num` generation is
  `membership_change/07-signup-flow.md`'s concern, not this plan's.
- `createMemberAllocation()` — untouched; the alloc_v2 ledger has no payer concept.
- Every discount/tax helper (`resolveDiscountForUI`, `estimateTaxForUI`) — they never touched a
  customer id and don't start now.

## 3.7 Acceptance

- `grep -rn "getStoredCustomerId\|updateCustomerEmail" resources/js/afs` — empty after this
  phase ships.
- `grep -n "processor_reference_num" resources/js/afs/memberships/index.ts` — empty.
- A signup completed via the synchronous (non-redirect) path and one completed via the
  Stripe-redirect path both produce a `brain_memberships` row with the same non-null
  `payer_reference_num` shape (both went through the one server-side write in 02 §2.3).
- `memberships/index.ts` and `main.ts` read `AFS_DIRECT_PAYMENT_ID` from the same
  `import.meta.env.VITE_DIRECT_PAYMENT_ID` — a single grep for the literal `'vfPSdk2xj'` across
  `resources/js/afs/` returns nothing.
