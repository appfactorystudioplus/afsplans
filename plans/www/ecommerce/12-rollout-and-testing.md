# 12 — Rollout and Testing

## 12.1 Build order

| Phase | Contents | Ships behind |
|---|---|---|
| **P0 — security** | [01](01-site-users-guard.md) §1.6: split admin/storefront routes, `ApiAuthMiddleware` on writes, ownership scoping (**E1**–**E4**) | **Nothing. Not optional, not schedulable** |
| **P1 — money and identifiers** | [04](04-catalogue.md) §4.2–§4.3: `price_cents`, unique non-null `reference_num`, `status`, soft delete (**E9**, **E12**–**E14**) | Additive + one verified conversion |
| **P2 — catalogue** | [04](04-catalogue.md) §4.4–§4.8: variants, options, media, inventory, categories, collections, digital (**E10**, **E11**, **E15**, **E16**) | Default variant per product; nothing visible changes |
| **P3 — pricing, tax, shipping** | [05](05-pricing-tax-shipping.md) (**E17**) | Zero rates until configured |
| **P4 — cart** | [06](06-cart-and-checkout.md) §6.2–§6.3: carts, lines, merging | No checkout yet |
| **P5 — Direct Payments + checkout** | [07](07-direct-payments-integration.md), [06](06-cart-and-checkout.md) §6.4: store DP config, variant→item mapping, `begin`/`confirm` (**E6**, **E8**) | Store `direct_payment` null = catalogue only |
| **P6 — orders** | [08](08-orders-and-fulfilment.md): order, lines, events, fulfilment, reconciler (**E5**) | |
| **P7 — promotions** | [09](09-promotions.md) (**E18**) | None exist until created |
| **P8 — storefront** | [10](10-storefront-and-sdk.md): working builder components, SDK, `portal.d.ts` | Old static templates keep rendering |
| **P9 — accounts** | [01](01-site-users-guard.md) §1.4–§1.5: attribution, my-orders, modes | Per store, default `off` |
| **P10 — admin** | [11](11-admin-dashboard.md) | |

**P0 depends on nothing and ships immediately.** P1 must precede P2–P7 — building a cart on a
`DECIMAL` price column means migrating money underneath live orders later.

P5 depends on the [billing plan](../../billing/README.md)'s Direct Payments work being usable;
P9 depends on [site_users](../site_users/README.md) P2 (sessions) and P8d (the API guard).

## 12.2 The security gate

Before anything past P0:

- [ ] Unauthenticated `DELETE /portal/api/ecommerce/store/{ref}` → **401** (**E1**).
- [ ] Same for `POST /product`, `DELETE /product/{ref}`, `POST /store`, `POST /category`,
      `DELETE /category/{ref}`.
- [ ] An authenticated caller cannot touch another tenant's rows (**E4**).
- [ ] `getStores()` returns only the caller's stores (**E2**).
- [ ] **Verified by hand against a running instance**, unauthenticated, before and after — a
      test asserting a route is absent does not prove the deployed router agrees.

The write paths get **no deprecation alias**; reads do
([01](01-site-users-guard.md) §1.3). Leaving a deprecated unauthenticated
`DELETE /store/{ref}` for a release cycle is the vulnerability, not the fix.

## 12.3 The money migration

`price DECIMAL(10,2)` → `price_cents INTEGER` is the one irreversible-feeling step.

1. Add `price_cents`, backfill with `ROUND(price * 100)` — **`ROUND`, not a cast**
   ([04](04-catalogue.md) §4.2).
2. **Verify in the same migration**: `SELECT COUNT(*) WHERE ROUND(price*100) <> price_cents`
   must be **0**, or the migration fails.
3. Ship. Both columns live; only `price_cents` is read.
4. Drop `price` a release later.

`(int)(19.99 * 100)` is **1998**. If the verification in step 2 is skipped, that under-charge
ships across a whole catalogue and is found by a customer.

## 12.4 Test matrix

### Security — P0

| Case | Expected |
|---|---|
| Unauthenticated delete store / product / category | 401 |
| Unauthenticated create/update product or store | 401 |
| Authenticated, another tenant's store | 403 or 404, never a delete |
| `getStores()` | Only the caller's |
| Published storefront page | Still loads products via the aliased read path |

### Catalogue — P1/P2

| Case | Expected |
|---|---|
| `19.99` | Charges **1999** |
| Migration verification | Fails the migration if any row mismatches |
| Options-less product | Has exactly one hidden default variant |
| Variant with null `price_cents` | Sells at the product's price |
| Duplicate option combination | Rejected by `ec_variants_combo_uq` |
| Add to cart | Reserves **nothing** |
| Begin checkout | Reserves for 15 min |
| Expired reservation | Does not block a sale |
| Last unit bought | Next add-to-cart refused, with the available quantity |
| `stock_policy = continue` | Goes negative |
| Archive a product | Gone from storefront, historical orders intact |
| Delete a product with orders | **Refused** |
| Category with 3000 products | One indexed query |
| Digital purchase | Signed expiring URL, never before payment |

### Cart and checkout — P4/P5

| Case | Expected |
|---|---|
| Cart survives browser restart, and device change when signed in | |
| Guest cart + user cart | **Merged**, nothing discarded |
| `price` field in `POST /cart/lines` | **Ignored** |
| Price changed between add and begin | `changes[]`, re-confirmation required |
| Stock gone between add and begin | Refused, line named, quantity **not** silently reduced |
| Charged total | Equals `invoice.getTotal()` — asserted against the processor charge |
| Double-click Pay | **One** order, under concurrent requests |
| Forged client-side "paid" | **No** order |
| Tax service down | Checkout fails; **no** charge with zeroed tax |
| Store with no `direct_payment` | Browsable; `EC_E_STORE_NOT_PAYABLE` at checkout |

### Direct Payments — P5

| Case | Expected |
|---|---|
| Repoint a store to a different DP | Processor switches, **no product changes** |
| `SUBSCRIPTION` DP as primary | `EC_E_DP_WRONG_TYPE` |
| Variant price ≠ DP item price (`explicit`) | Flagged in admin; charge matches the invoice |
| Unmapped variant saved | **Offers** DP item creation, does not create silently |
| `dynamic` amount outside min/max | Refused by the DP layer |
| Subscription + one-off in one cart | `EC_E_MIXED_CART` |
| Vendor product | Charges with `seller_ref`; `hasSplit()` true |
| Vendor on a non-`CONNECT` DP | Refused at save |
| Signed-in second purchase | Same provider customer; saved cards on another device |
| Customer-link failure in production | Logs, proceeds anonymously, writes **no** link |
| **Provider-name grep** in ecommerce controllers | **Empty** |

### Orders — P6

| Case | Expected |
|---|---|
| Order created | Only after server-side charge verification |
| Line snapshots | Survive a product rename and a product delete |
| Partial fulfilment | `partial`, then `fulfilled` — derived |
| Cancel | Restores stock; does **not** refund |
| Refund in the Money ledger | Updates `refunded_cents`, `payment_status`, order events |
| `ecommerce:reconcile-orders` | Creates the missing order; no-op on rerun |
| `order_number` | Sequential per store; two stores may both have `#1001` |
| `internal_note` | Never in a storefront response |
| Retried confirm | **One** confirmation email |

## 12.5 Rollback

| Phase | Rollback |
|---|---|
| P0 | **None.** Restoring an unauthenticated destructive API is not a rollback |
| P1 | `price` still present until a release later — revert reads to it |
| P2 | New tables unread by the old code; storefront unaffected |
| P3 | Zero rates = current behaviour |
| P4 | Drop carts; nothing consumed them |
| P5 | Clear `direct_payment`; the store reverts to a catalogue |
| P6 | Not clean — orders exist. **Roll forward** |
| P7 | Deactivate promotions |
| P8 | Old static templates still render |
| P9 | Per store `off` |
| P10 | Admin revert |

## 12.6 What to watch after launch

| Signal | Means |
|---|---|
| `ecommerce:reconcile-orders` non-zero | **Incident.** The confirm path failed after money moved ([08](08-orders-and-fulfilment.md) §8.6) |
| Price-mismatch warnings rising | The DP item mapping is drifting ([07](07-direct-payments-integration.md) §7.4) |
| `changes[]` frequent at checkout | Prices or stock are moving faster than shoppers check out |
| Reservations expiring in volume | Checkout is too slow, or 15 minutes is wrong for this shop |
| Negative stock without `continue` | A decrement path is racing — investigate immediately |

The last one is the canary: it should be impossible, so if it happens the reservation
mechanism has a hole.
