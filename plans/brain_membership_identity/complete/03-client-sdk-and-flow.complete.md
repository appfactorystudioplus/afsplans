# 03 — Client SDK and Flow — Complete (partial: BM3, BM5, BM6 only)

## Status

Built 2026-09-23, in `w/www/resources/js/afs/`. Only the parts of 03 that stay valid after
[02's completion doc](02-database-and-server.complete.md)'s server-only design landed:

| Finding | Result |
|---|---|
| BM2 (`getStoredCustomerId()` → `getStoredPayerReferenceNum()`, §3.1/§3.2) | **Not built, deliberately.** The SDK method has nothing to read from (direct_payments/05's payer resolver was never built), and `getStoredCustomerId()` is shared SDK internals used by `setupSubscriptionPayment()` for every tenant. `create.ts` still reads it and POSTs `customer_id`; `store()` reads it once to link via `recordExistingCustomer()` and stores it nowhere. |
| BM3 (client `updateCustomerEmail()`, §3.2/§3.3) | **Done.** The call and its `email`/`firstName`/`lastName`/`fullName` locals are gone from `handleStripeReturn()`, and the stale "confirmPayment updates the customer email internally" comment from `handleSubmit()`. `MembershipController::store()` already calls `PayerService::updateEmail()` once for both paths (02's completion doc). |
| BM5 (three sources for the DP id, §3.4) | **Already done** before this pass by `membership_change/07-signup-flow.md` §S15 — `memberships/index.ts` reads `VITE_DIRECT_PAYMENT_ID`. No change needed. |
| BM6 (dead `processor_reference_num` wire field, §3.5) | **Done.** Removed from `MembershipCreationPayload`, `registerMembership()`'s payload, and the `dpId`/`getSelectedTierPlan()` local that only fed it. Confirmed `MembershipController` never reads that key. `AdminMembershipCreationPayload` and the discount/tax helpers keep their own `processor_reference_num` — different endpoints, unrelated. |

## Verification

- Grep of `resources/js/afs`: `updateCustomerEmail` and `vfPSdk2xj` (as code) no longer appear;
  `getStoredCustomerId` remains at the two `create.ts` call sites by design (BM2).
- Root `tsc --noEmit` in `w/www` (its `tsconfig.json` includes `resources/js/**`, so it covers
  `resources/js/afs`) — clean. `resources/js/afs/tsconfig.afs.json` is stale (its `include`
  points at the deleted `../checkpoint/`) and is not what checks this folder.
- **Not run:** `vite build --config vite.config.afs.js` (writes to `public/external/afs`) or a
  real signup. **Do a sync-path signup and a Stripe-redirect signup in the lab and
  confirm the Stripe customer ends up with the email** before trusting this — the redirect path
  now relies solely on the server-side `updateEmail()`.

## Consequence for 04's grep gates

`grep getStoredCustomerId` will never come back empty under the current design; that gate (and
`MembershipRegisterUsesPayerReferenceTest`/`RejectsForeignPayerTest`, which assume a
client-supplied payer) is not applicable unless BM2 is revisited.

## Lab note — 2026-09-24

The lab's four pending migrations were applied (see `02-database-and-server.complete.md`). A first
signup attempt reached `createMemberAllocation()` and `registerMembership()`, so the client-side
changes here did not break the flow up to the `store()` insert; the remaining open item is a
signup completing end to end and the Stripe customer receiving the email.

### Update 2026-09-24

A synchronous-path signup completed through the edited `create.ts`/`memberships/index.ts` and produced
a row with `payer_reference_num` populated (see `02-database-and-server.complete.md`), so BM3/BM6 did
not break the flow. Still unchecked: the Stripe-redirect path, and that Stripe received the email now
that the client no longer sends it.
