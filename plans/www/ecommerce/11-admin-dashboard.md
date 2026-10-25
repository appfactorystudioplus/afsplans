# 11 — The Admin Dashboard

`resources/js/portal/admin/controllers/ecommerce/` — today: store, products, categories.

## 11.1 What exists

Three screens backed by nine CRUD endpoints — the ones **E1** shows are unauthenticated. The
UI is fine; what it manages is about to grow substantially, and one screen it needs does not
exist at all.

## 11.2 The shape

```
Ecommerce
├── Overview           §11.3   sales, orders needing action, low stock
├── Orders             §11.4   the screen that does not exist today
│   └── Order detail           lines, fulfilment, refunds, events
├── Products           §11.5   list · detail · variants · media · inventory
├── Categories                 nested, with drag ordering
├── Collections        §11.5   manual and rule-based
├── Customers          §11.6   requires Site Users
├── Discounts          §11.7   promotions and gift cards
└── Settings           §11.8   payments, tax, shipping, notifications, accounts
```

## 11.3 Overview

The screen an operator opens every morning. It answers *"what needs me today?"* rather than
showing a revenue chart nobody acts on.

- Orders **awaiting fulfilment** — count, linked
- Orders with **failed payment**
- **Low stock** variants ([04](04-catalogue.md) §4.6)
- **Price mismatches** between variants and their DP items
  ([07](07-direct-payments-integration.md) §7.4)
- Revenue today / 7d / 30d, and units sold
- **Setup warnings** — no Direct Payment configured, no shipping zones, no tax rates

The setup warnings are what stop a tenant publishing a shop that cannot take money
([07](07-direct-payments-integration.md) §7.3).

## 11.4 Orders — the missing screen

There is no order management today because there are no orders (**E5**). This is the largest
new surface.

**List** — number, date, customer, total, payment status, fulfilment status. Filters on all
of those plus date range and store; search by number, email or tracking number.

**Detail**:

| Panel | Contents |
|---|---|
| Summary | Number, dates, all three statuses ([08](08-orders-and-fulfilment.md) §8.2) |
| Customer | Email, phone, linked account if any, past order count |
| Lines | Snapshotted name, variant, SKU, price, qty, fulfilled and refunded counts |
| Totals | Subtotal, discount (**with the code**), shipping, tax, total, refunded |
| Payment | DP invoice ref, charge id, **link into the Money ledger** ([direct_payments/29](../direct_payments/29-money-and-refunds.md)) |
| Fulfilments | Each with carrier, tracking, items, status |
| Addresses | Billing and shipping snapshots |
| Notes | Customer note (read-only) and internal note |
| **Timeline** | Every order event, newest first ([08](08-orders-and-fulfilment.md) §8.4) |

**Actions**: create a fulfilment (select lines and quantities), mark shipped with tracking,
cancel, refund (into the Money ledger), resend a notification, edit the internal note.

The **timeline** is the panel that makes support possible. "When did this ship and was the
customer told" is currently unanswerable, and it is the most common question an order screen
receives.

### Fulfilment

Selecting lines and quantities, entering carrier and tracking, choosing whether to notify.
Partial fulfilment is the default assumption, not an edge case — the form starts with the
unfulfilled quantities pre-filled and lets the operator reduce them.

## 11.5 Products

The product form grows from six fields to a real editor:

| Tab | Contents |
|---|---|
| **Details** | Name, slug, descriptions, status, type, SEO |
| **Media** | Drag-ordered gallery, alt text, primary flag, per-variant images ([04](04-catalogue.md) §4.5) |
| **Pricing** | Price, compare-at, cost (**with computed margin**), tax class |
| **Variants** | Options builder, then the generated variant grid |
| **Inventory** | Per-variant stock, policy, low-stock threshold, SKU, barcode |
| **Organisation** | Categories, collections, tags, vendor |
| **Payments** | DP item mapping, with the mismatch warning ([07](07-direct-payments-integration.md) §7.4) |

### The variant grid

Defining `Size [S,M,L]` × `Colour [Black,White]` generates six rows, editable **inline** —
price, SKU, stock, image — with bulk edit across selected rows.

That inline grid is the difference between a usable variant editor and one where a tenant with
24 variants gives up. Six modal dialogs to set six prices is how variants get abandoned.

Blank variant price means *inherit* ([04](04-catalogue.md) §4.4) and the grid shows the
inherited value greyed, so "empty" never looks like "free".

**Bulk actions** on the product list: publish, archive, assign category or collection,
adjust price by percent, adjust stock. Bulk price changes require confirmation naming the
number of products affected.

## 11.6 Customers

Only when Site Users is on for the store ([01](01-site-users-guard.md) §1.5).

List with email, order count, lifetime value, group, account status. Detail with order
history, addresses, group assignment, linked payment customers
([site_users/08](../site_users/08-direct-payments-link.md)).

**No password or session management here** — that is the Site Users admin
([site_users/10](../site_users/10-admin-dashboard.md)). Two screens managing credentials is
how they diverge.

## 11.7 Discounts

Promotions list with code, type, usage against limit, window, status. The editor covers
[09](09-promotions.md)'s rule set with a **plain-language preview**:

> *20 % off orders over $50, for Wholesale customers, until 31 Dec. Not combinable.*

Rule builders are where operators make expensive mistakes, and a sentence they can read back
catches more than a form they can tab through.

Gift cards get their own list — code (masked), balance against initial, expiry — with a
transaction history per card. Issuing is manual or via a product purchase.

## 11.8 Settings

One screen, per store, and the place a tenant decides how the shop behaves:

| Section | |
|---|---|
| **General** | Name, currency, timezone |
| **Payments** | **Direct Payment select** ([07](07-direct-payments-integration.md) §7.2), subscription DP, item mode |
| **Tax** | Inclusive/exclusive, classes, zones, rates, processor tax toggle |
| **Shipping** | Zones, methods, rates |
| **Customer accounts** | Off / Optional / Required ([01](01-site-users-guard.md) §1.5.3) |
| **Notifications** | Per event, each choosing a mail handler ([08](08-orders-and-fulfilment.md) §8.7) |
| **Checkout** | Abandoned-cart recovery, reservation window, guest checkout |

The Payments and Customer accounts sections sit together deliberately — they are the two
decisions that change what a shop *is*, and a tenant should meet them in one place rather than
discovering the second one later.

## 11.9 What the admin cannot do

| Not here | Why |
|---|---|
| See a provider secret key | Processor admin, and never in a response ([direct_payments/27](../direct_payments/27-provider-retry-policy.md) §13.4) |
| Edit an order's line prices after payment | The order is a record of what happened. Corrections are refunds |
| Delete a product with orders | Refused ([04](04-catalogue.md) §4.3) — archive instead |
| Set a customer's password | Site Users admin |
| Adjust a gift card balance directly | Only via a transaction, so the balance always equals the sum ([09](09-promotions.md) §9.7) |

The second row is the one operators will ask for. Editing a paid order's totals makes the
order disagree with the charge, the ledger and the customer's receipt — and there is no way to
tell afterwards which was right.

## 11.10 Acceptance

- Every admin endpoint requires authentication and is scoped to the caller's stores
  (**E1**–**E4**).
- The order list filters and searches by number, email and tracking number.
- Partial fulfilment is achievable in one form, with unfulfilled quantities pre-filled.
- The order timeline shows every event including notifications sent.
- A refund issued from the order appears in the Money ledger and updates the order.
- Defining two options with 3 and 2 values generates exactly six inline-editable variants.
- A blank variant price displays the inherited value greyed, never `0.00`.
- A DP item price mismatch is visible on the product and on the overview.
- Bulk price change confirms with the affected count before applying.
- The Customers screen is absent when Site Users is off for the store.
- A promotion's plain-language preview matches its configured rules.
- Editing a paid order's line price is **not offered**.
- Setup warnings appear until a Direct Payment is configured.
