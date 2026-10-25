# 06 — The Booking Engine

Closes **A1**, **A2**, **A10**, **A11**, **A16**, **A17**, **A27**, **A31**. The correctness
core of the module.

## 6.1 What booking does today

`saveBooking()` in the preview reads the cached booking array, pushes one, and sends **all of
them** to `POST /appointments/{ref}/bookings`, which upserts what it received and deletes
everything else (**A17**, **A1**).

So a booking is a whole-calendar replace, built from a client-side cache, over an
unauthenticated endpoint, with no capacity check anywhere (**A10**) and no unique constraint to
fall back on (**A11**).

Meanwhile the exported public widget posts a **single object** to that same endpoint, which
reads `bookings[]`, finds nothing, writes nothing, and returns `success: true` — so the customer
sees a confirmation for a booking that does not exist (**A27**).

## 6.2 Bulk replace is removed

The first decision ([00](00-overview.md) §0.5 D1), and the one that closes the most findings at
once.

| Gone | Replaced by |
|---|---|
| `POST /appointments/{ref}/bookings` (replace) | `POST /api/appointments/book` — creates **one** booking |
| `POST /appointments/{ref}/slots` (replace) | `POST` / `PUT` / `DELETE /portal/api/v1/appointments/{ref}/services/{id}` |

**No deprecation alias.** Leaving an unauthenticated calendar-wipe reachable for a release cycle
is the vulnerability, not the fix ([02](02-site-users-guard.md) §2.2).

Deleting them also closes **A16** and **A17** — the tenant's own UI cannot fire an operation
that no longer exists — and it is a smaller change than authenticating the endpoint would have
been.

The admin UI changes accordingly: `handleSlotSubmit`, `toggleSlotActive` and `confirmDeleteSlot`
each send **one** service, and check the response (**A15**,
[12](12-admin-dashboard.md) §12.9).

## 6.3 Capacity — enforced by the database

`max_bookings` is stored and never read (**A10**). There is no unique constraint (**A11**). Both
are fixed in the schema, not in a handler.

### Capacity 1 — the common case

The generated column plus unique index from [04](04-database-schema.md) §4.9.2:

```
active_slot_key = service | staff | resource | starts_at     (NULL when cancelled)
UNIQUE (active_slot_key)
```

The insert either succeeds or violates the constraint. There is no window, no `count()`, and
nothing for two concurrent requests to interleave between.

```php
// AFS_APPOINTMENTS_MODIFIER_ADDITION — plans/www/appointments/06-booking-engine.md §6.3
try {
    $booking = AppointmentBooking::create([...]);
} catch (QueryException $e) {
    if ($this->isUniqueViolation($e, 'appt_bookings_active_slot_uq')) {
        // Taken between availability and booking. Refuse and re-offer.
        return $this->slotTakenResponse($service, $startsAt);   // §6.6
    }
    throw $e;
}
```

**The application check exists for the message, never for the guarantee.** A pre-insert
`exists()` query is still worth running — it produces a better error most of the time — but it
is not what makes the system correct.

### Capacity *n* — group services

A unique index cannot express "at most 6". A counter row plus a conditional `UPDATE` can, the
same pattern [events/06](../events/06-ticketing-and-capacity.md) §6.5 uses:

```sql
INSERT INTO laravel_appointment_slot_counters
       (service_reference_num, staff_reference_num, starts_at, booked, capacity)
VALUES (?, ?, ?, 0, ?)
ON DUPLICATE KEY UPDATE capacity = VALUES(capacity);

UPDATE laravel_appointment_slot_counters
   SET booked = booked + ?
 WHERE service_reference_num = ? AND staff_reference_num <=> ? AND starts_at = ?
   AND booked + ? <= capacity;
-- 0 rows affected → full
```

The predicate is inside the `UPDATE`; the database serialises it on the row.

Counters are a cache of the bookings they summarise, so `appointments:reconcile-counters` runs on
a schedule, corrects **the counter** (never the bookings), and **reports disagreements as a
monitored metric** — a drifting counter is a double-booking waiting to happen.

### A31 — the client's version of the same bug

`bookedTimes.includes(time)` (`appointment-preview.ts:290`) treats one booking as filling a slot
regardless of `maxBookings`, and does not exclude cancelled bookings.

Both disappear once availability is computed server-side with `remaining`
([05](05-availability-and-scheduling.md) §5.2): the client stops deciding, and cancelled
bookings are excluded by the generated column being `NULL`
([04](04-database-schema.md) §4.9.2).

## 6.4 The booking transaction

```
POST /api/appointments/book
  1. throttle · payload cap · honeypot · origin                     02 §2.2
  2. resolve service; refuse if inactive                            A34
  3. re-validate the requested instant against availability          05 §5.2
       └ lead time, horizon, schedule, exception, busy, buffers
  4. validate the hold, if one was supplied                          §6.5
  5. BEGIN
       claim capacity  (unique index, or conditional UPDATE)         §6.3
       insert booking (status per approval / payment rules)
       convert the hold
       generate reference_num, access_token                          03 §3.6
       write activity: booking.created
     COMMIT
  6. payment, if the service requires it                             09 §9.4
  7. queue effects: confirmation, ICS, staff notice, calendar push   10
  8. 201 { reference_num, starts_at, ends_at, timezone, status }
```

Step 3 is not redundant with the availability list the customer saw. That list may be minutes
old; a schedule may have changed; a hold may have expired. **Re-validating at booking is what
makes the offered list advisory and the booking authoritative.**

The response carries no other customer's data, and no `id` — the autoincrement is never public
([11](11-public-surface-and-widget.md) §11.3).

### Idempotency

`unique(appointment_reference_num, idempotency_key)`
([04](04-database-schema.md) §4.9). A key is generated at hold and resent on retry, so a
double-click, a retried request after a timeout, or a widget's own retry produce **one** booking
and **one** confirmation email.

## 6.5 Holds

Without holds, two customers are shown 10:00, both fill in a form, and one is refused after
investing the effort — the most common complaint a booking product receives.

`laravel_appointment_holds` ([04](04-database-schema.md) §4.9):

| Property | |
|---|---|
| Created | When the customer picks a time, **before** the form |
| Subtracted | From availability, exactly like a booking ([05](05-availability-and-scheduling.md) §5.2) |
| TTL | `settings.holds.ttl_minutes`, default 10 |
| Scope | One active hold per session per service; requesting another replaces it |
| Expiry | A sweeper every minute, **and** re-checked inside the booking transaction |
| Visible | A countdown, with a warning at two minutes |

An expired hold at booking triggers a re-claim attempt; if the time is gone, the booking is
refused **before any charge** with alternatives offered (§6.6).

For a **capacity-1** service, a hold is also protection against the unique-index refusal being
the customer's first news that the time went.

## 6.6 Refusals

Every refusal names its reason and offers a way forward. A booking form that fails with "unable
to book" loses the customer.

| Reason | Response |
|---|---|
| Time taken since the list was generated | 409 + **the next three available times**, same staff first |
| Hold expired and the time is gone | 409 + alternatives, and no charge attempted |
| Inside the lead window | 422 naming the lead time |
| Beyond the horizon | 422 naming the date bookings open |
| Service inactive or deleted | 404 |
| Staff unavailable | 409 + other staff who can deliver it ([07](07-services-staff-and-resources.md) §7.4) |
| Resource unavailable | 409 + other times |
| Capacity full (group) | 409 + waitlist if enabled ([08](08-booking-lifecycle.md) §8.8) |
| Duplicate per policy | 409 naming the existing booking |
| Approval required | 202 — created `pending`, not confirmed ([08](08-booking-lifecycle.md) §8.4) |

"The next three available times" is one query and it converts refusals into bookings.

## 6.7 Abuse control on the public endpoint

The booking endpoint accepts anonymous writes, so it needs the same posture as forms'
([forms/02](../forms/02-security-and-tenancy.md) §2.8):

| Control | |
|---|---|
| Throttle | Per IP and per appointment |
| Hold limit | One per session per service — otherwise one visitor can hold a whole day |
| Idempotency key | §6.4 |
| Honeypot + minimum completion time | Flag, never silently reject |
| Email verification | Optional per appointment; the main defence for free services |
| Duplicate policy | `one_active_per_email`, `max_open_bookings` ([04](04-database-schema.md) §4.10) |
| Origin check | Log-only first, enforcing later with per-appointment opt-out |

**Free appointments are the abuse target.** A diary that can be filled by a script with no card
is the case these exist for, and the hold limit is the one that actually stops it.

## 6.8 Rescheduling and cancellation, from the engine's view

The lifecycle is [08](08-booking-lifecycle.md); the concurrency is here.

**Reschedule is claim-then-release, never release-then-claim:**

```
BEGIN
  claim the NEW instant   (unique index / counter)   ← may fail: nothing lost
  release the OLD instant
  update the booking, set rescheduled_from_ref
  write activity
COMMIT
```

Releasing first means a failure leaves the customer with no appointment at all — the same
ordering rule [events/07](../events/07-registration-and-attendees.md) §7.8 applies to transfers.

**Cancellation releases and promotes in one transaction**: the counter decrements (or the
generated column goes `NULL`), and the waitlist is offered the freed time inside the same
transaction ([08](08-booking-lifecycle.md) §8.8) — otherwise two cancellations promote the same
entry, or a passer-by takes the time while the waitlist waits.

## 6.9 Acceptance

- No endpoint anywhere deletes a row it did not name (**A1**, **A2**, **A16**, **A17**).
- 50 concurrent requests for one capacity-1 time produce **exactly one** booking — enforced by
  the unique index, verified by removing the application check and re-running.
- 50 concurrent requests for a capacity-6 service produce exactly 6.
- `appointments:reconcile-counters` reports zero disagreements after the concurrency suite.
- A cancelled booking's time becomes available again immediately.
- A cancelled booking never blocks its old time (**A31**).
- A hold reserves a time; a second customer is not offered it.
- An expired hold releases the time and does not touch confirmed bookings.
- A booking attempt on an expired hold whose time is gone is refused **before** any charge.
- Every refusal names its reason; "time taken" returns three alternatives.
- The same idempotency key twice produces one booking and one email.
- One session cannot hold more than one time per service.
- A booking made through the public widget **exists in the database** and the confirmation
  reflects it (**A27**).
- Rescheduling to an unavailable time leaves the original booking intact.
- A save that fails surfaces an error in the admin (**A15**).
