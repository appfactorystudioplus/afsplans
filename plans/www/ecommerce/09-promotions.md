# 09 — Promotions and Discounts

Discount codes, automatic promotions and gift cards (**E18**). None exist today.

## 9.1 Two kinds, one engine

| | Triggered by | Example |
|---|---|---|
| **Code** | The shopper typing it | `WELCOME10` |
| **Automatic** | Conditions being met | "Free shipping over $50" |

Same rules, same evaluator, same stacking policy. Splitting them into two systems is how
"free shipping over $50" and `FREESHIP` end up computing shipping differently.

Distinct from **sales** ([05](05-pricing-tax-shipping.md) §5.4), which change a *price*.
A promotion changes a *cart total*. Sales are visible on the product page; promotions appear
at the cart.

## 9.2 The schema

**`laravel_ecommerce_promotions`**

| Column | Notes |
|---|---|
| `reference_num` unique | |
| `store_reference_num` index | |
| `name` | Operator-facing |
| `trigger` | `code` \| `automatic` |
| `code` null, unique per store | Uppercased, trimmed |
| `type` | `percentage` \| `fixed_amount` \| `free_shipping` \| `buy_x_get_y` |
| `value` | Percent, or amount in **cents** |
| `applies_to` | `order` \| `products` \| `collection` \| `category` \| `shipping` |
| `applies_to_ids` json null | |
| `min_subtotal_cents` null | |
| `min_quantity` null | |
| `customer_group_id` null | Group-restricted ([05](05-pricing-tax-shipping.md) §5.3) |
| `requires_account` bool | Forces sign-in ([01](01-site-users-guard.md)) |
| `usage_limit` null | Total redemptions |
| `usage_limit_per_customer` null | |
| `usage_count` default 0 | Derived; see §9.6 |
| `stackable` bool default `false` | §9.3 |
| `priority` int | Evaluation order |
| `starts_at` / `ends_at` null | |
| `is_active` | |

**`laravel_ecommerce_promotion_redemptions`** — `promotion_id`, `order_id`, `cart_id`,
`site_user_id` null, `email` null, `discount_cents`, `redeemed_at`.

Redemptions are rows, not a counter, so "who used this and when" is answerable and
`usage_limit_per_customer` is enforceable for guests by email as well as for accounts.

## 9.3 Stacking

**Default: not stackable.** One promotion per cart, the best one wins.

That is the safe default and the one most shops want. `stackable = true` opts a promotion into
combining, and combining is evaluated in `priority` order with two hard rules:

- **Percentages never compound.** Two stackable 20 % promotions give 40 % off the subtotal,
  not 36 %. Compounding is not what anyone means and not what any shopper expects.
- **The total discount never exceeds the subtotal.** A cart total floors at zero before
  shipping and tax — a negative subtotal is a refund, not a sale.

When a code is applied and a better automatic promotion exists, the shopper is told which one
was used and why. Silently applying the larger one makes a code look broken.

## 9.4 `buy_x_get_y`

The type that is always harder than it looks. Stored as `buy_quantity`, `get_quantity`,
`get_discount_percent` (100 = free), and a scope.

**The discount always applies to the cheapest qualifying items.** Buy-2-get-1-free on three
items priced 30/20/10 discounts the 10. Any other choice is a shop losing money on a rule it
thought it understood, and it is the specific behaviour every platform converged on.

Repeats are capped by `max_applications` per cart.

## 9.5 How a discount reaches the charge

A promotion changes the **cart total**, and the cart is projected onto a Direct Payments
invoice ([06](06-cart-and-checkout.md) §6.3). So a discount must exist on the invoice, not
just in our arithmetic.

**As a negative-adjustment line item**, not by reducing each line's price:

```
addItem({ item_ref: 'itm_tshirt',  quantity: 2 })          //  5000
addItem({ item_ref: 'itm_discount', amount: -500 })        //  −500   WELCOME10
invoice.getTotal()                                          //  4500
```

Reducing each line instead would lose the promotion's identity — the order could no longer
say *why* it was cheaper, refunds could not be reasoned about proportionally, and reporting
"revenue lost to discounts" would be impossible.

This requires the store's Direct Payment to carry a **discount adjustment item**, created
automatically when a store's first promotion is created
([07](07-direct-payments-integration.md) §7.4).

**Tax is calculated on the discounted subtotal**, so the discount line is added before tax is
derived. The order records `discount_cents` and `discount_code` separately
([08](08-orders-and-fulfilment.md) §8.2).

## 9.6 Usage limits and the race

`usage_limit` is where promotions get abused. A code limited to 100 uses, posted publicly,
receives a thousand simultaneous attempts.

- The redemption row is inserted **inside the order-creation transaction**
  ([08](08-orders-and-fulfilment.md) §8.2), not when the code is applied to a cart.
- `usage_count` is derived from redemptions, cached on the promotion for display, and
  **never incremented by hand**.
- The limit is enforced by a conditional update — `UPDATE … SET usage_count = usage_count + 1
  WHERE usage_count < usage_limit` — and zero affected rows fails the checkout.

**Applying a code to a cart reserves nothing.** Ten thousand carts may hold a
hundred-use code; the hundred-and-first to *pay* is refused, with a clear message. Reserving
at apply-time means one abandoned cart burns a redemption forever.

## 9.7 Gift cards

Related but a different thing: a promotion reduces a total; a gift card is **stored value**
that pays part of it.

**`laravel_ecommerce_gift_cards`** — `code` (unique, high-entropy), `initial_balance_cents`,
`balance_cents`, `currency`, `site_user_id` null, `expires_at` null, `is_active`,
`issued_order_id` null.

**`laravel_ecommerce_gift_card_transactions`** — `gift_card_id`, `order_id`, `amount_cents`
(negative = spend, positive = refund), `balance_after_cents`.

Balance is the **sum of transactions**, cached on the card. Same discipline as
`refunded_cents` ([08](08-orders-and-fulfilment.md) §8.2) — a hand-maintained balance is a
balance that will eventually be wrong, and this one is money.

Applied at checkout as a payment method, **not a discount**: the cart total is unchanged and
the amount charged to the card is reduced by the redemption. On the invoice that is a separate
negative line with its own item, so an order shows "paid $30 by card, $20 by gift card" rather
than a mysteriously smaller total.

Partial redemption leaves a balance. A refund to a gift-card-paid order returns value to the
card, not to a payment method that was never charged.

Gift cards are **off by default** — they are stored value with real accounting implications,
and a tenant should turn them on deliberately.

## 9.8 Acceptance

- A non-stackable cart takes the **best** single promotion, and says which (§9.3).
- Two stackable 20 % promotions give 40 % off, not 36 % (§9.3).
- Discounts floor the subtotal at zero; no negative total reaches the invoice.
- `buy_x_get_y` discounts the **cheapest** qualifying items (§9.4).
- The charged total equals `invoice.getTotal()` with the discount line present (§9.5).
- Tax is computed on the **discounted** subtotal.
- An order records `discount_code` and `discount_cents` separately.
- A 100-use code under 1000 concurrent checkouts yields **exactly 100** redemptions (§9.6).
- Applying a code to a cart and abandoning it burns **no** redemption.
- `usage_limit_per_customer` is enforced for a guest by email, and for an account by user.
- An expired or inactive code is refused with a distinct message from an invalid one.
- A gift card partially redeemed retains its balance; the order shows both payment parts.
- A refund on a gift-card-paid order returns value to the card (§9.7).
- Gift card balance always equals the sum of its transactions — asserted after a refund.
