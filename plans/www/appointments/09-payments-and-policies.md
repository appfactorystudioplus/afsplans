# 09 — Payments, Deposits and Policies

The module has no notion of price at all: `laravel_appointment_slots` has no price column, and
`direct_payment_reference_num` does not exist on `laravel_appointments`. Every appointment is
implicitly free.

## 9.1 The boundary

**Direct Payments owns money; appointments owns the appointment**
([03](03-architecture.md) §3.4) — the same boundary
[ecommerce/03](../ecommerce/03-architecture.md) §3.2 draws and
[events/08](../events/08-payments-and-refunds.md) §8.1 repeats.

Appointments never imports a provider SDK, never holds a key, never handles a webhook. It builds
an invoice, hands it over, and records what came back. Saved cards, provider-agnostic checkout
and refunds arrive through a seam that already exists.

**Money is integer minor units, everywhere, from the first migration**
([04](04-database-schema.md) §4.3) — [ecommerce **E9**](../ecommerce/02-current-state-audit.md)
paid for once already. Currency is per appointment, stamped onto every booking, never
re-derived.

## 9.2 The four payment modes

`services.price_cents` and `deposit_cents` produce four behaviours, and a tenant needs at least
three of them:

| Mode | Configuration | Booking status |
|---|---|---|
| **Free** | `price_cents = 0` | `confirmed` immediately |
| **Pay on the day** | Price set, `deposit_cents = 0`, prepayment off | `confirmed`; the amount is shown and recorded as outstanding |
| **Deposit** | `deposit_cents > 0` | `pending` until the deposit clears, then `confirmed` |
| **Prepay in full** | Price set, prepayment on | `pending` until paid, then `confirmed` |

"Pay on the day" is the common case for most service businesses and is easy to forget: the
booking must still record what is owed, so the day sheet and the receipt are right
([12](12-admin-dashboard.md) §12.4).

**A card on file without an immediate charge** is the fourth shape worth supporting — it is what
makes a no-show fee collectable (§9.5), and Direct Payments already does it
([site_users/08](../site_users/08-direct-payments-link.md)).

## 9.3 Which Direct Payment

`appointments.direct_payment_reference_num` ([04](04-database-schema.md) §4.2), exactly as
[ecommerce/07](../ecommerce/07-direct-payments-integration.md) §7.2 makes
`stores.direct_payment` real and [events/08](../events/08-payments-and-refunds.md) §8.4 makes
the event's.

- The appointment names a Direct Payment. **Required before publishing a priced service**;
  refused otherwise.
- A service may name a DP item; where it does, the item's price is authoritative at checkout and
  the service's `price_cents` is what the module displays and reports.
- **A mismatch is surfaced, not resolved** — on the service, and on the admin overview
  ([12](12-admin-dashboard.md) §12.3). Auto-syncing means one screen silently changes what
  another charges.
- A free appointment needs no Direct Payment at all.

## 9.4 The payment transaction

```
book (06 §6.4)
  ├── claim capacity + insert booking (pending)
  ├── dp.createInvoice() · addItem(service or deposit) · lock()
  ├── render the payment element
  │     ── customer pays ──
  └── on confirmation:
        BEGIN
          booking.status = confirmed
          paid_cents, direct_payment_charge_ref recorded
          activity: payment.captured
        COMMIT
        queue: confirmation · ICS · staff notice · calendar push   (10)
```

Ordering rules:

- **The time is claimed before payment is attempted.** Paying for a slot that is then taken is
  the failure that requires a refund and an apology.
- **A failed or abandoned payment releases the time** and leaves the booking `pending` until an
  expiry job cancels it. Holding a slot for an abandoned checkout is how a fully booked diary has
  empty chairs.
- `unique(direct_payment_invoice_ref)` ([04](04-database-schema.md) §4.9) makes a retried
  confirmation idempotent.
- **Payment succeeded, booking write failed** → reconciled from the DP invoice by
  `appointments:reconcile-payments`. Money taken with no appointment is the one unacceptable
  outcome, and it is unrecoverable without a reconciler
  ([ecommerce/08](../ecommerce/08-orders-and-fulfilment.md) §8.6).

Free bookings follow **the same path** with the payment steps absent — never a separate
free-booking function, because the separate one is the one that misses the atomic claim.

## 9.5 Refunds, fees and differences

### Cancellation policy

`settings.policy` ([04](04-database-schema.md) §4.10):

| Policy | Meaning |
|---|---|
| `none` | No refunds |
| `free_until_hours` | Full refund until N hours before (default 24) |
| `partial_until_hours` | A percentage until N hours before |
| `always_full` | Always refundable |
| `manual` | The operator decides each time |

**Shown before booking and snapshotted onto the booking.** Changing the policy must not
retroactively alter what an existing customer agreed to.

### Late-cancellation and no-show fees

Charged against a card on file, through Direct Payments, and **never automatically**
([08](08-booking-lifecycle.md) §8.6). The operator is offered the action with the policy quoted;
the judgement is theirs.

A charged fee writes `activity: fee.charged` with the reason, so a disputed charge has a record.

### Reschedule with a price difference

Where a reschedule changes service or staff and the price differs:

| Case | Handling |
|---|---|
| Same price | No money moves |
| New is dearer | Additional charge, **after** the new time is claimed ([06](06-booking-engine.md) §6.8) |
| New is cheaper | Refund the difference, or nothing, per policy |

Claim first, charge second — charging for a reschedule that then fails on availability is the
sequence to avoid.

## 9.6 Packages and credits

`laravel_appointment_credits` ([04](04-database-schema.md) §4.11). "A block of six sessions",
"a 10-class pass" — how most repeat-service businesses actually sell.

| Property | |
|---|---|
| Purchase | One Direct Payments invoice; credits issued on confirmation |
| Scope | A specific service, or usable across any |
| Redemption | A booking consumes one credit instead of a payment |
| Balance | `total_sessions - used_sessions`, shown to the customer |
| Expiry | `expires_at`, optional, and **warned about** before it lapses |
| Refunds | Unused credits refundable per policy; partially used blocks pro-rated |
| Cancellation | A cancelled booking within policy **returns its credit** |

That last row is the one implementations get wrong: a customer who cancels in good time and
loses a session from their block will not buy another block.

Credits attach to a `site_user_id` where accounts are on, and to `customer_email` otherwise — so
they work for guests, with the weaker guarantee that an email is the identity
([02](02-site-users-guard.md) §2.3).

**Subscriptions** — "unlimited classes for £40 a month" — are Direct Payments subscriptions
([direct_payments/28](../direct_payments/28-subscription-actions.md)), not a new mechanism. Scoped to a later
phase and named here so the schema does not foreclose it: a booking's nullable
`credit_reference_num` and nullable `direct_payment_invoice_ref` already allow a booking with no
payment of its own.

## 9.7 What the customer sees

| Moment | Shown |
|---|---|
| Service list | Price, or "Free", or "from £X" where staff pricing varies |
| Selecting a time | The price, and the deposit if any |
| Before paying | Total, deposit, balance due on the day, **and the cancellation policy** |
| Confirmation | What was paid, what remains, the policy, and how to cancel |
| Booking page | The same, plus what a late cancellation would cost |

Stating the late-cancellation cost **before** it applies is what makes charging it defensible.

## 9.8 Acceptance

- `grep` finds no `decimal` money column in an appointments migration.
- A priced service cannot be published without a Direct Payment.
- A service whose `price_cents` differs from its DP item's price is **warned about**, not
  silently synced.
- The time is claimed before payment is attempted.
- An abandoned payment releases the time and the booking expires.
- A retried payment confirmation produces one booking and one confirmation email.
- Killing the process between charge and write leaves a booking that
  `appointments:reconcile-payments` completes, with no duplicate.
- A free booking follows the same claim path as a paid one.
- The policy shown before booking is the policy applied afterwards, even if the tenant changed
  it since.
- A no-show fee is never charged without an operator action, and is recorded with its reason.
- A reschedule to a dearer service charges the difference **after** claiming the new time.
- A six-session package issues six credits; booking consumes one; cancelling within policy
  returns it.
- An expiring credit balance is warned about before it lapses.
- A guest's credits are usable without an account.
