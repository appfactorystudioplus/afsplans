# 06 — Ticketing, Capacity and Inventory

Closes **V14**, **V15**, **V16**, **V21**, and completes
[02](02-site-users-guard.md) §2.6.

This is the document the module is judged on. Everything else can be imperfect and recoverable;
selling the same seat twice is neither.

## 6.1 What the current model says

One `totalTickets` number on the event, a `ticketsSold` that is reset to `0` on every save
(**V14**), and ticket "levels" that carry a name and a Direct Payment item reference but **no
price** (**V15**). Levels with an empty field are silently dropped at save (**V16**). Discount
codes exist as a client-only `localStorage` CRUD with no redemption path (**V21**).

The shape is right — an event, several classes of admission, each mapped to a priceable DP item.
What is missing is everything that makes it inventory.

## 6.2 Ticket types

`laravel_event_ticket_types` ([04](04-database-schema.md) §4.6). A type is a class of admission
to an occurrence.

| Property | Purpose |
|---|---|
| `kind` | `paid` · `free` · `donation` · `allocation` |
| `price_cents` | **The price lives here** (**V15**) |
| `capacity` | Its own pool, or null to share the occurrence's |
| `sales_start_at` / `sales_end_at` | Per-type sales window |
| `min_per_order` / `max_per_order` | 1 and 10 by default |
| `visibility` | `public` · `hidden` (link only) · `code_required` (§6.8) |
| `requires_approval` | §7.6 |
| `registration_form_ref` + `form_scope` | §7.4 |
| `direct_payment_item_reference_num` | The existing UI's mapping, kept |

**Every registration references a ticket type.** A free event gets one implicit free type, so
there is exactly one code path — the same "always one default" rule that keeps ecommerce's
variant handling single-pathed ([ecommerce/04](../ecommerce/04-catalogue.md) §4.4). Special-casing
"free event with no types" is how the free path drifts from the paid one and only the paid one
gets the capacity fix.

### Shared vs dedicated capacity

| Configuration | Meaning |
|---|---|
| `ticket_type.capacity = null` | Draws from the occurrence's pool. General + VIP compete for 200 places |
| `ticket_type.capacity = 50` | 50 reserved for this type, **and** it still consumes the occurrence pool |

Both counters are claimed in one transaction (§6.5). The admin warns when the sum of dedicated
capacities exceeds the occurrence capacity, because that configuration means some dedicated
places can never be sold.

### Hybrid events

An in-person type and an online type on one occurrence, with different capacities
([05](05-event-model-and-scheduling.md) §5.7) — the in-person one bounded by the room, the
online one usually unbounded. Types carry an `attendance_mode` flag for display and for
deciding whether a badge or a join link is issued.

### Donation tickets

`kind = donation` — a "pay what you like" place with an optional minimum. The registrant enters
an amount; it becomes the invoice line. Common for community and charity events and awkward to
retrofit, which is why it is a `kind` rather than a special case.

## 6.3 Tiers

`laravel_event_ticket_tiers` ([04](04-database-schema.md) §4.7) — a time- or quantity-bounded
price for a type.

```
General Admission
  ├── Early Bird   £100   until 1 Oct   OR first 100
  ├── Standard     £150   1 Oct – 10 Nov
  └── Late         £180   from 11 Nov
```

Both bounds are independent and both are common: *"Early Bird until 1 October or the first 100,
whichever ends first."*

Resolution, at any moment:

1. The first tier whose window contains now **and** whose `quantity` is not exhausted.
2. If none, `ticket_types.price_cents`.

**One resolution path**, used by the public page, the hold, the order and the admin preview. Two
implementations of "what does this cost right now" is how a page advertises Early Bird and the
checkout charges Standard.

The public page shows the current tier, and — when `display.show_remaining` is on — how close it
is to ending: *"Early Bird — 12 left"*. A tier ending is the strongest honest urgency an events
product has, and it needs no invention.

`sold_count` is incremented by conditional update (§6.5), because a tier of 100 that is checked
then incremented sells 130.

## 6.4 Limits and rules

| Rule | Where |
|---|---|
| Min/max per order | `min_per_order`, `max_per_order` |
| Max per email per occurrence | `settings.registration.one_per_email` ([04](04-database-schema.md) §4.11.2) |
| Sales window | `sales_start_at` / `sales_end_at`, and the occurrence's registration window |
| Requires another type | *"VIP dinner requires a conference ticket"* — a dependency on the type |
| Mutually exclusive types | Cannot hold both Member and Non-member |

All are enforced **server-side at hold and again at order**. Client-side enforcement is a
convenience; the public endpoint has to assume the client is `curl`.

## 6.5 Capacity — the atomic claim

The one rule ([00](00-overview.md) §0.6 D2), and the mistake appointments made
([**A10**](../appointments/01-current-state-audit.md)).

### Never this

```php
$count = Registration::where('occurrence_reference_num', $ref)->count();   // ← race
if ($count + $qty <= $capacity) {
    Registration::create([...]);                                            // ← starts here
}
```

Two requests interleave between the `count` and the `create`. This is not a rare race: it races
hardest the moment a popular event opens registration, which is exactly when overselling is
unforgivable.

### Always this

```php
// AFS_EVENTS_MODIFIER_ADDITION — plans/www/events/06-ticketing-and-capacity.md §6.5
// Claim capacity atomically. Zero affected rows means there was not enough.
$claimed = DB::update(
    'UPDATE laravel_event_occurrences
        SET reserved_count = reserved_count + ?
      WHERE reference_num = ?
        AND (capacity IS NULL OR registered_count + reserved_count + ? <= capacity)',
    [$qty, $occurrenceRef, $qty]
);

if ($claimed === 0) {
    // Full. Waitlist (§7.7) or refuse — never proceed.
}
```

The predicate is inside the `UPDATE`. The database serialises it on the row. There is no window.

### Both counters, one transaction

A dedicated ticket type has its own pool, so a claim touches two rows and both must succeed:

```
BEGIN
  claim on occurrence          → 0 rows? ROLLBACK, refuse
  claim on ticket type         → 0 rows? ROLLBACK, refuse
  claim on tier (if bounded)   → 0 rows? ROLLBACK, refuse
  insert hold
COMMIT
```

**Consistent lock ordering — occurrence, then type, then tier — everywhere in the codebase.**
Two code paths locking in opposite orders deadlock under exactly the load this is designed for.

### The counters

`reserved_count` (held) and `registered_count` (confirmed) are separate
([04](04-database-schema.md) §4.3). Available = `capacity - registered_count - reserved_count`.

Conversion moves a quantity from reserved to registered **in one statement**:

```sql
UPDATE laravel_event_occurrences
   SET reserved_count = reserved_count - ?, registered_count = registered_count + ?
 WHERE reference_num = ? AND reserved_count >= ?
```

Release decrements `reserved_count` only. Cancellation decrements `registered_count` and
immediately triggers waitlist promotion **in the same transaction** (§7.7).

### Reconciliation

Cached counters drift — a crashed process, a missed release, a bug. `events:reconcile-counters`
runs hourly, compares each counter to the rows it summarises, corrects **the counter**, and
records the disagreement.

**The disagreement count is a monitored metric, not a log line.** A drifting counter is an
inventory bug that will eventually oversell, and it should page someone before it does.

## 6.6 Holds

A registrant selecting three tickets and entering their details needs those three places to
exist when they reach the payment step. The alternatives are both bad: reserve nothing and lose
them at payment, or reserve on a count the client controls.

`laravel_event_holds` ([04](04-database-schema.md) §4.8):

| Property | |
|---|---|
| Created | When the registrant selects quantities, **before** the form |
| Claims | `reserved_count` on occurrence, type and tier (§6.5) |
| TTL | `settings.holds.ttl_minutes`, default 15 |
| Captures | `unit_price_cents` **at hold time** |
| Converts | To registrations when payment confirms |
| Releases | On expiry, abandonment, or explicit cancel |

### Why the price is captured

A tier rolling over mid-checkout must not change the price the registrant was shown. The hold
records it; the order honours it ([03](03-architecture.md) §3.10). Without this, a registrant
selects Early Bird at 23:58 and is charged Standard at 00:01 — technically defensible and
commercially indefensible.

### Expiry

- A sweeper (`events:expire-holds`) runs every minute and releases expired holds.
- **Conversion re-checks `expires_at` inside the transaction**, because a job that runs every
  minute is not a guarantee at second 59.
- The registrant sees a countdown, and a warning at two minutes.
- An expired hold at checkout attempts a **re-claim**; if inventory is gone, the order fails
  **before any charge** with a plain message.

That ordering matters: fail before charging, never after. Charging and then discovering the seat
is gone is the failure that requires a refund, an apology and a support ticket.

### Anonymous holders

A hold belongs to a `session_token` (an httpOnly cookie) or a `site_user_id`. A registrant may
have one active hold per occurrence; requesting another replaces it. Otherwise a single visitor
can exhaust an event's inventory by reloading.

## 6.7 Allocations and comps

`kind = allocation` — inventory reserved off the public pool: press, sponsors, speakers, comps.

- Never publicly purchasable, and never shown in the public ticket list.
- Issued by an operator, who names the attendee and optionally sends the ticket
  ([12](12-admin-dashboard.md) §12.5).
- Counts against the occurrence's capacity like any other place, because the room does not care
  whether a seat was paid for.
- `amount_cents = 0` on the registration, with the reason recorded — so a comp is
  distinguishable from a free event in revenue reporting ([13](13-reporting-and-analytics.md)
  §13.3).

Operators also need to **release** an allocation back to the public pool when the press does not
come, which is one action and is otherwise done by editing capacity — badly.

## 6.8 Promo codes and access codes

Two different things, often confused.

### Promo codes — a discount

`laravel_event_promo_codes` ([04](04-database-schema.md) §4.9). Replaces the client-only
`localStorage` discount store (**V21**), which has no redemption, no limit enforcement and no
link to any ticket.

| Capability | |
|---|---|
| Percent or fixed amount | `discount_type`, `discount_value` (points or cents) |
| Scope | The whole order, or named ticket types |
| Conditions | Minimum quantity, minimum subtotal, date window |
| Limits | Total uses, uses per email |
| Reach | One event, or every event on the site |

**`used_count` is incremented by conditional update**, same as capacity:

```sql
UPDATE laravel_event_promo_codes
   SET used_count = used_count + 1
 WHERE reference_num = ? AND (max_uses IS NULL OR used_count < max_uses)
```

A code limited to 100 that is checked-then-incremented is used 130 times the day it is posted to
a forum. This is the same class of bug as overselling and deserves the same fix.

The discount is applied to the **order**, and the resulting amount is what goes to the Direct
Payments invoice ([08](08-payments-and-refunds.md) §8.3). It is never recomputed client-side.

### Access codes — a key

`visibility = code_required` on a ticket type with an `access_code`. The type is invisible until
the code is entered, then purchasable at its normal price. Member rates, staff tickets,
partner allocations.

**A promo code discounts something you can already buy; an access code reveals something you
cannot.** Tenants ask for both and conflating them produces a discount that leaks a hidden
ticket type into the public page.

## 6.9 Sections, tables and general assignment

Not a seat-map designer ([00](00-overview.md) §0.3) — the level below it, which covers most
tenants:

| Capability | |
|---|---|
| **Sections** | A ticket type is bound to a named section with its own capacity — Stalls, Balcony |
| **Tables** | An order is assigned to a table; the registration records `seat_table` |
| **Auto-assign** | Fill tables in order, keeping one order's attendees together |
| **Manual reassign** | Drag a registration between tables in the admin |
| **Unassigned** | A first-class state, visible and countable |

Sections are ticket types with a name (`seat_section`), so they inherit capacity, pricing and
sales windows without new machinery. Tables are an assignment applied after registration, which
is when a tenant actually does the seating plan — usually the week before, from a spreadsheet
they would rather not use.

## 6.10 What the registrant sees

Inventory state has to be legible, and honest:

| State | Display |
|---|---|
| Plenty left | The price, no count (`display.show_remaining_below`, [04](04-database-schema.md) §4.12) |
| Few left | *"Only 6 left"* |
| Tier ending | *"Early Bird — 12 left"* or *"Early Bird ends in 2 days"* |
| Type sold out | Struck through, **shown**, not hidden — a sold-out VIP tier is information |
| Occurrence sold out | *"This event is full"* + waitlist if enabled (§7.7) |
| Sales not open | *"Registration opens 1 October"* with the date in the event's zone |
| Sales closed | *"Registration closed"* |
| Code-required type | Invisible until the code is entered |

**A button that fails on submit is worse than a disabled button with a reason.** Every refusal
above is displayed before the registrant invests time, and repeated server-side because the
client is not trusted.

## 6.11 Acceptance

- An occurrence with `capacity = 50` accepts **exactly 50** places under 200 concurrent
  registrations.
- A ticket type with `capacity = 20` inside an occurrence of 50 sells exactly 20.
- Concurrent claims against occurrence and type never deadlock — asserted by a load test with
  mixed types.
- The 51st registrant is waitlisted when enabled, refused with a clear message otherwise.
- A hold reserves inventory; the same inventory is not offered to another registrant.
- An expired hold releases its reservation and does not touch `registered_count`.
- A hold expiring mid-checkout fails the order **before** any charge.
- A tier rolling over during a live hold does not change the price charged.
- Early Bird limited to 100 sells exactly 100 under concurrency.
- A promo code with `max_uses = 100` is redeemed exactly 100 times under concurrency.
- A per-email-limited promo code is refused on the second use by that email.
- An access-code ticket type is absent from the public payload until the code is supplied —
  verified against the raw API response, not the rendered page.
- `events:reconcile-counters` reports zero disagreements after the full concurrency suite.
- A cancellation returns a place and promotes a waitlist entry in the same transaction.
- A comp registration has `amount_cents = 0`, counts against capacity, and is distinguishable
  from a free-event registration in reporting.
- A ticket level with a missing name is **refused with a message**, not silently dropped
  (**V16**).
- Every ticket type has a price the module knows (**V15**), independent of its DP item.
