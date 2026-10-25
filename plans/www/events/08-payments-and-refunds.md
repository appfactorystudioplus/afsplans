# 08 — Payments, Money and Refunds

Closes **V4** and **V15**. Completes [02](02-site-users-guard.md) §2.7.

## 8.1 The boundary, restated

**Direct Payments owns money; events owns the event** ([03](03-architecture.md) §3.3).

Events never imports a provider SDK, never sees a secret key, never creates a provider customer,
never handles a webhook. It builds an invoice, hands it over, and records what came back.

That is not tidiness — it is what makes the following arrive rather than being rebuilt:

| Capability | Where it already exists |
|---|---|
| Saved cards across visits | Direct Payments + [site_users/08](../site_users/08-direct-payments-link.md) |
| Provider-agnostic checkout | The processors layer's normalisation |
| Marketplace splits | `invoice.addItem({ seller_ref })` |
| Refunds | [direct_payments/29](../direct_payments/29-money-and-refunds.md) |
| Subscriptions (season passes, memberships) | `dp.subscription.*` |

An events module with its own Stripe integration would rebuild every row of that table, badly,
and diverge the moment a provider changed.

## 8.2 Money rules

**Integer minor units, everywhere, from the first migration.** Every column ends `_cents`
([03](03-architecture.md) §3.6).

This is [ecommerce **E9**](../ecommerce/02-current-state-audit.md) paid for once already: mixing
`DECIMAL(10,2)` with the integer cents the processor layer uses guarantees a rounding
disagreement between what a registrant saw and what they were charged, and that surfaces as a
chargeback rather than a test failure.

| Rule | |
|---|---|
| Currency is **per event**, stamped onto each order at creation | An event's currency may change; a completed order's cannot |
| A total never mixes currencies | One event, one currency |
| Rounding happens **once**, at the order total | Rounding each line then summing produces a total that disagrees with the lines |
| Every amount on a registration is a **snapshot** | [07](07-registration-and-attendees.md) §7.5 |

## 8.3 An order is an invoice

The same decision [ecommerce/06](../ecommerce/06-cart-and-checkout.md) §6.3 made for carts.

```
order paid
  ├── dp.createInvoice({ direct_payment_ref, currency, customer })
  ├── addItem({ item_ref, quantity })      ← one per ticket type × quantity
  ├── addItem(discount / fee lines)        ← §6.8, §8.5
  ├── lock()                                ← totals frozen
  ├── render the payment element
  └── on confirmation → order.paid, holds converted, registrations created   §8.6
```

The DP invoice already models what a ticket order is — priceable items, quantities, a lock, a
total. Building a parallel totalling is how the number a registrant saw and the number they were
charged come apart.

**Events computes the inputs; Direct Payments computes and takes the money.** The order's
`subtotal_cents`, `discount_cents`, `fee_cents`, `tax_cents` and `total_cents`
([04](04-database-schema.md) §4.10) are recorded for reporting, and the **locked invoice is
authoritative** if they ever disagree — with the disagreement itself surfaced as a defect, not
reconciled silently.

## 8.4 Which Direct Payment, and which item (V4, V15)

`events.direct_payment_reference_num` becomes real, exactly as
[ecommerce/07](../ecommerce/07-direct-payments-integration.md) §7.2 makes
`stores.direct_payment` real.

The current UI already has the right shape (**V15** is that it discards half of it):

- **The event names a Direct Payment.** A first-class, required setting for a paid event.
- **Each ticket type names a DP item** (`direct_payment_item_reference_num`) — the existing
  ticket-level mapping, kept.
- **The ticket type also carries `price_cents`** ([04](04-database-schema.md) §4.6), which the
  current model does not, so nothing in the module knows what a ticket costs.

### Which price wins

Two prices now exist for one ticket: the type's `price_cents` and the DP item's price. They must
not silently disagree.

| Rule | |
|---|---|
| **The DP item's price is authoritative at checkout** | It is what the invoice will charge |
| **The type's `price_cents` is what events displays and reports** | And what tier resolution overrides ([06](06-ticketing-and-capacity.md) §6.3) |
| A mismatch is **surfaced, not resolved** | On the ticket type, on the event, and on the admin overview ([12](12-admin-dashboard.md) §12.3) |
| Publishing with a mismatch | Warns, naming both numbers |
| A tier price differing from its DP item | Requires a distinct DP item per tier, or the tier is applied as a discount line — the tenant chooses, and the editor explains the difference |

Ecommerce hit exactly this and named it a mismatch warning rather than an auto-sync
([ecommerce/11](../ecommerce/11-admin-dashboard.md) §11.3). Auto-syncing means one screen
silently changes what another charges.

**A free event needs no Direct Payment at all**, and publishing one without is fine. Publishing a
*paid* event without one is refused ([05](05-event-model-and-scheduling.md) §5.10).

## 8.5 Fees and taxes

### Fees

A tenant may add a booking fee — fixed, percentage, or both — per order or per ticket:

| `fee_mode` | Behaviour |
|---|---|
| `absorb` (default) | The advertised price includes it; the tenant nets less |
| `pass_on` | Added at checkout as its own line, shown before payment |

`pass_on` must be **visible on the ticket type before selection**, not revealed at the payment
step. A price that grows during checkout is the most reliable source of abandonment in ticketing.

### Tax

The processor layer already handles tax — `POST /portal/api/v1/processors/tax` exists and is used
by signup ([ecommerce/05](../ecommerce/05-pricing-tax-shipping.md) §5.5). Events uses it rather
than computing tax itself.

| Rule | |
|---|---|
| Inclusive or exclusive, per event | Different markets expect different defaults |
| Tax is computed on the **discounted** subtotal | Discount then tax, always |
| A tax rate applies per ticket type | A conference ticket and a dinner may differ |
| **Tax service unreachable → fail the checkout** | Never charge with tax silently zeroed ([ecommerce/03](../ecommerce/03-architecture.md) §3.7) |
| The rate and amount are snapshotted on the order | A rate change must not alter a historical order |

Events does **not** build a tax engine — nexus, VAT MOSS, filing. Rates and a computation seam,
yes; a compliance product is out of scope ([00](00-overview.md) §0.3).

## 8.6 The checkout transaction

The critical path, in order:

```
1. re-validate the hold           ← inside the transaction, expires_at checked   06 §6.6
2. re-resolve prices from the hold's captured unit_price_cents
3. apply promo code (conditional UPDATE on used_count)                            06 §6.8
4. compute tax                                                                     §8.5
5. create the order (pending) + DP invoice, lock it
6. render the payment element
   ──── registrant pays ────
7. on confirmation:
     BEGIN
       order.status = paid, charge ref recorded
       holds → registrations  (reserved_count → registered_count, one statement)   06 §6.5
       ticket_code + access_token generated per registration                       09 §9.3
       activity: payment.confirmed, registration.created
     COMMIT
8. queue effects: confirmation, ICS, organiser notice                              10
```

Step 7 is one transaction. A partial application — money taken, some registrations created — is
the outcome §8.7 exists to prevent and §8.7's reconciler exists to repair.

`unique(direct_payment_invoice_ref)` on the order ([04](04-database-schema.md) §4.10) makes a
retried confirmation idempotent: the second attempt finds the order already paid and returns it
rather than creating a second.

### Free and comp orders

Skip steps 4–6 entirely: create the order, mark it `paid` with `total_cents = 0`, convert the
holds. **The same code path**, with the payment steps absent — never a separate free-registration
function, because the separate one is the one that misses the atomic conversion.

## 8.7 Payment confirmed, registration not written

The one unacceptable outcome, and the reason ecommerce specifies a reconciler
([ecommerce/08](../ecommerce/08-orders-and-fulfilment.md) §8.6).

If step 7 fails after the charge:

- The order stays `pending` with a `direct_payment_invoice_ref` recorded.
- `events:reconcile-payments` runs on a schedule, finds paid DP invoices whose order is not
  `paid`, and completes step 7 idempotently.
- Failures that cannot be reconciled are raised, with the invoice reference, to an operator —
  not logged and left.

**Money taken with no ticket is unrecoverable without a reconciler**, and it is the failure a
registrant notices immediately and loudly.

## 8.8 Refunds and cancellation policy

### The policy

`settings.policy` ([04](04-database-schema.md) §4.12):

| Policy | Meaning |
|---|---|
| `none` | No refunds |
| `full_until_hours` | Full refund until N hours before the start |
| `partial_until_hours` | A percentage until N hours before |
| `always_full` | Always refundable |
| `manual` | Organiser decides each time |

The policy is **shown before purchase** and snapshotted onto the order. Changing the policy must
not retroactively alter what an existing registrant was promised.

### Execution

- **Events decides eligibility; Direct Payments executes**
  ([direct_payments/29](../direct_payments/29-money-and-refunds.md)).
- A refund records `refunded_cents` on the order and the registration, and appears in the
  activity log and the Money ledger.
- Partial refunds are supported — one attendee of four, or a percentage.
- Booking fees are refundable or not, per setting, and the answer is stated in the policy text
  rather than discovered.
- A refunded registration is `cancelled`, its place released, and the waitlist promoted **in the
  same transaction** ([07](07-registration-and-attendees.md) §7.7).

### Cancelling an occurrence

The operator action with the largest blast radius ([05](05-event-model-and-scheduling.md) §5.9):

1. Confirmation naming the registration count and the total to be refunded.
2. Occurrence → `cancelled`; every registration → `cancelled`. **Never deleted.**
3. Refunds issued per policy — or in full, which is the default the dialog offers, because an
   organiser cancelling should not be applying a late-cancellation penalty to attendees.
4. Everyone notified, including waitlist entries ([10](10-communications.md) §10.5).
5. Activity recorded, with the actor.

**Postponement is not cancellation** ([05](05-event-model-and-scheduling.md) §5.9): registrations
survive, nothing is refunded, everyone is told, and a new date follows. Attendees who cannot make
the new date cancel individually under the policy — with the policy's cutoff measured from the
**new** date.

## 8.9 Transfers with a price difference

Moving between occurrences or ticket types ([07](07-registration-and-attendees.md) §7.8):

| Case | Handling |
|---|---|
| Same price | No money moves |
| Target dearer | Additional charge through a new DP invoice, before the transfer commits |
| Target cheaper | Refund the difference, or nothing, per `settings.policy.transfer_refund` |
| Free → paid | Payment required first |

The place on the target is claimed atomically **before** the source is released, and before any
charge. Charging for a transfer that then fails on capacity is the sequence to avoid.

## 8.10 Season passes and memberships

A registrant buying access to every occurrence in a series — a term of classes, a season.

Deliberately **not** built as a new mechanism: Direct Payments already has subscriptions
([direct_payments/28](../direct_payments/28-subscription-actions.md)), and a pass is a subscription that
grants registrations.

The events-side shape, when it is built:

- A **pass** names an event (the series) and a ticket type.
- Holding a pass creates registrations for occurrences automatically, or entitles the holder to
  claim them — the tenant chooses, because a weekly class wants automatic and a season of
  concerts wants claim.
- **Capacity is still claimed atomically** ([06](06-ticketing-and-capacity.md) §6.5). A pass is
  not an exemption from the room's size.
- Expiry, renewal and dunning are Direct Payments' concerns and are not reimplemented.

Scoped to a later phase ([14](14-rollout-and-testing.md) §14.1), and listed here so the schema
does not foreclose it — which is why registrations carry a nullable `order_reference_num`
([04](04-database-schema.md) §4.11): a pass-granted registration has no order of its own.

## 8.11 Acceptance

- `grep` finds no `decimal` money column in any events migration.
- A paid event cannot be published without a Direct Payment.
- A ticket type whose `price_cents` differs from its DP item's price is **warned about**, on the
  type, the event and the overview — and is not silently synced.
- An order's total equals the locked DP invoice's total, always; a disagreement fails the
  checkout.
- Discount is applied before tax.
- A `pass_on` fee is visible on the ticket type before selection.
- The tax service being unreachable **fails** the checkout; no order is charged with tax zeroed.
- A free registration follows the same conversion path as a paid one.
- A retried payment confirmation creates exactly one order.
- Killing the process between the charge and the registration write leaves an order that
  `events:reconcile-payments` completes — with no duplicate registration.
- A refund updates the order and the registration, releases the place, and promotes the
  waitlist in one transaction.
- The refund policy shown before purchase is the policy applied afterwards, even if the tenant
  changed it since.
- Cancelling an occurrence with 120 registrations refunds all 120, notifies all 120, and deletes
  none.
- Postponing refunds nothing and keeps every registration.
- A transfer to a dearer ticket charges the difference **after** the target place is claimed.
