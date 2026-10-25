# 04 — Rollout and Testing

> **As built 2026-09-23 — read this before §4.1–§4.6.** The phases below were written for a
> client-trusting design that was not built. Actual outcome: BM-P1/P4/P6 done in one pass
> (no live data, so no phased window); BM-P5 built then deleted; BM-P2 and the BM2 half of
> BM-P3 closed as won't-do; the BM3/BM6 half of BM-P3 done. See
> [complete/02-database-and-server.complete.md](complete/02-database-and-server.complete.md) and
> [complete/03-client-sdk-and-flow.complete.md](complete/03-client-sdk-and-flow.complete.md).
> Where §4.3/§4.4/§4.6 conflict with the tables below, the tables below win.
>
> **Tests that apply (all exist):** `BrainMembershipPayerResolutionTest`,
> `MembershipUpdateIgnoresIdentityFieldsTest`.
> **Tests that do not apply:** `PayerBackfillFromCustomerIdTest` (backfill deleted),
> `MembershipRegisterUsesPayerReferenceTest` and `MembershipRegisterRejectsForeignPayerTest`
> (no client-supplied payer), `StripeRedirectAndSyncPathsAgreeTest` (not written; covered by a
> manual lab check instead).
>
> **Grep gates as built:**
> ```bash
> grep -rn "updateCustomerEmail" resources/js/afs        # expect: no matches
> grep -n "processor_reference_num" resources/js/afs/memberships/index.ts
> # expect: only the discount/tax helpers and AdminMembershipCreationPayload, none in MembershipCreationPayload/registerMembership()
> grep -rn "getStoredCustomerId" resources/js/afs        # expect: exactly the two create.ts call sites (BM2 won't-do)
> grep -n "customer_id" app/Http/Controllers/Portal/Admin/MembershipController.php
> # expect: only store()'s read of the client's customer_id for recordExistingCustomer(); none in update()
> ```
>
> **Definition of done as built:** `customer_id` column gone; `brain_memberships.payer_reference_num`
> populated by `store()`; admin `update()` cannot write identity fields; client no longer
> attaches email itself. **Still open:** the lab signup check (sync + Stripe-redirect paths) and
> running the two migrations/seed imports, neither executed yet.

## 4.1 Phases

| Phase | What ships | Depends on |
|---|---|---|
| **BM-P0** | `direct_payments/02`'s payer table and `PayerService`, `direct_payments/05`'s SDK `DirectPaymentsPayerResolver` shape | Nothing in this plan — this is the hard prerequisite (00 §BI4) |
| **BM-P1** | `payer_reference_num` column + dual-write in `MembershipController::store()` (02 §2.1, §2.4). `customer_id` still written by the (unchanged) client | BM-P0 |
| **BM-P2** | SDK gains `getStoredPayerReferenceNum()`, loses `getStoredCustomerId()`/`updateCustomerEmail()` (03 §3.1) | BM-P0 |
| **BM-P3** | `resources/js/afs/` cutover: `create.ts`, `memberships/index.ts` rewritten (03 §3.2-3.5). Server stops needing `customer_id` from new signups | BM-P1, BM-P2 |
| **BM-P4** | Admin `update()` loses `customer_id` from `$allowed` (02 §2.6) | BM-P3 |
| **BM-P5** | Backfill existing rows (02 §2.5) | BM-P3 |
| **BM-P6** | Drop `customer_id` column | BM-P5, and a full backfill pass with zero unresolved rows or all unresolved rows explicitly triaged |

BM-P1 and BM-P2 can ship in either order or together; BM-P3 is the only phase that touches the
public signup bundle and is the one to roll out carefully (§4.3).

## 4.2 Why the phase order matters here specifically

Unlike a tenant-facing module, `resources/js/afs/` is AFS's **own** signup form — the one every
new paying member for the whole platform goes through. A broken deploy here doesn't disable a
feature for one connected module; it stops new members from signing up for the product at all.
That is the reason BM-P1/BM-P2 (additive, no behavior change for existing signups) are separated
from BM-P3 (the only phase that changes what the browser actually does) rather than bundled into
one release.

## 4.3 Testing

| Test | Asserts |
|---|---|
| `PayerBackfillFromCustomerIdTest` | BM-P5's backfill produces the same `payer_reference_num` for two members who share a `processor` + `customer_id` pair (shouldn't happen, but the resolver must be idempotent, not create two payers) |
| `MembershipRegisterUsesPayerReferenceTest` | POSTing `payer_reference_num` to `/portal/api/v1/memberships/register` after BM-P3 stores it; POSTing `customer_id` alone stores nothing (dropped key, not silently accepted) |
| `MembershipRegisterRejectsForeignPayerTest` | A `payer_reference_num` that resolves to a real payer on a **different** Direct Payment is rejected (02 §2.3.1) — the specific case that makes trusting a client-supplied reference safe |
| `MembershipUpdateIgnoresPayerReferenceTest` | Admin `PATCH` with `payer_reference_num` or `customer_id` in the body leaves the stored value unchanged (02 §2.6) |
| `StripeRedirectAndSyncPathsAgreeTest` | A signup completed via `handleStripeReturn()` and one via `handleSubmit()`'s sync branch both call `PayerService::updateEmail()` exactly once, from the server, never from the browser |
| `NoHardcodedDirectPaymentIdTest` | `grep -rn "vfPSdk2xj" resources/js/afs` returns nothing after BM-P3 (BM5) |

## 4.4 Grep gates

```bash
# BM-P3 done: the retired SDK methods are gone from AFS's own bundle
grep -rn "getStoredCustomerId\|updateCustomerEmail" resources/js/afs
# expect: no matches

# BM-P3 done: the dead wire field is gone
grep -n "processor_reference_num" resources/js/afs/memberships/index.ts
# expect: no matches

# BM-P4 done: admin can no longer touch identity fields
grep -n "customer_id" app/Http/Controllers/Portal/Admin/MembershipController.php
# expect: no matches (after BM-P6 also removes it from store()'s dual-write)

# BM-P6 done: the column itself is gone
php artisan tinker --execute="dd(Schema::hasColumn('brain_memberships', 'customer_id'))"
# expect: false
```

## 4.5 Rollback

| Phase | Rollback |
|---|---|
| BM-P1 | Drop the column. No reads depend on it yet |
| BM-P2 | Revert the SDK; `create.ts` still calls the old methods, still works, because BM-P3 hasn't shipped |
| BM-P3 | Revert `resources/js/afs/`; server still accepts `customer_id` in the dual-write window (BM-P1 hasn't been retired) |
| BM-P4 | Re-add `customer_id` to `$allowed` — no data was lost, just a permission |
| BM-P5 | Backfill is additive and idempotent; re-running it after a rollback is harmless |
| BM-P6 | **Not reversible without a restore.** Confirm BM-P5 shows zero unresolved rows, and hold this phase for a full deploy cycle after BM-P5 before running it — same caution
[`../www/site_users/13-identity-and-email-change.md`](../www/site_users/13-identity-and-email-change.md)'s
reference_num column drop uses |

## 4.6 Definition of done

- Every `brain_memberships` row created after BM-P3 has a `payer_reference_num` and no
  `customer_id`.
- `resources/js/afs/` contains no reference to a raw provider customer id, `getStoredCustomerId`,
  or `updateCustomerEmail`.
- The admin membership API cannot write `payer_reference_num` or `customer_id`.
- All grep gates in §4.4 pass.
- `customer_id` no longer exists as a column (BM-P6), or — if BM-P6 is deliberately deferred past
  this plan's own rollout window — every remaining reference to it is confined to the backfill
  command and the dual-write block, both named and dated in this document.
