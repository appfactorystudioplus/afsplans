# 05 — Availability and Scheduling

Closes **A12**, **A13**, **A32**, **A33**, **A34**, and the scheduling half of **A35**.

The layer the module does not have. Today the browser generates candidate times from four fields
on a slot row and greys out anything appearing in the booking list it downloaded
(**A29**, **A31**).

## 5.1 What availability has to account for

A time is bookable only if **all** of these hold. Today the module checks two of them, in the
browser.

| # | Condition | Today |
|---|---|---|
| 1 | Inside a working-hours block | ✅ client-side, per slot |
| 2 | Not on a dated exception (holiday, half-day) | ❌ no concept |
| 3 | Not inside imported busy time | ❌ no concept |
| 4 | Service duration fits before the block ends | ✅ client-side |
| 5 | Buffers before and after are free | ⚠️ one buffer, client-side only (**A13**) |
| 6 | Staff member free, including buffers | ❌ no staff |
| 7 | Resource free | ❌ no resources |
| 8 | Under the service's capacity at that instant | ❌ **A10** — never checked |
| 9 | Beyond the minimum lead time | ❌ no concept |
| 10 | Within the booking horizon | ❌ no concept |
| 11 | Not already held by another customer | ❌ no holds |
| 12 | The service is active | ❌ **A34** — `active` never filtered |
| 13 | Under the staff member's daily cap | ❌ no concept |

## 5.2 Availability is a function

```
availability(service, from, to, staff?, resource?, customer_timezone?)
    → [ { start_utc, end_utc, staff_ref, resource_ref, remaining } ]
```

Pure, deterministic, and **never materialised** ([00](00-overview.md) §0.5 D2).

### The algorithm

```
for each date in [from, to] ∩ [now + lead, now + horizon]:
    for each candidate staff member (§7.4):

        windows ← schedule blocks for (staff, weekday, date)         §5.3
        windows ← apply exceptions for that date                     §5.4
        windows ← subtract imported busy blocks                      §10.6
        windows ← subtract existing bookings, expanded by buffers    §5.5
        windows ← subtract live holds, expanded by buffers           §6.5

        for each window:
            step through it at `granularity`
            keep t where [t − buffer_before, t + duration + buffer_after] ⊆ window
            require a free resource at t                             §7.5
            require capacity_used(t) < capacity                      §6.3
            require staff daily cap not reached

    merge per-staff results; collapse duplicates by strategy         §7.4
```

Two properties this ordering guarantees:

- **Buffers are subtracted from neighbours, not added to the candidate.** A 30-minute
  appointment with a 15-minute after-buffer occupies 45 minutes of the diary but is still
  offered as, and described as, 30 minutes to the customer.
- **Holds are subtracted like bookings.** Otherwise two customers are shown the same 10:00 and
  one loses it after completing a form.

### Caching

Keyed `(service, staff, date, inputs_version)`, where `inputs_version` bumps whenever a
schedule, exception, busy block, booking, hold or service rule changes for that owner.

**Dropping the entire cache must never change an answer.** That property is what makes the cache
safe, and it is asserted by a test that computes availability with and without the cache and
compares ([14](14-rollout-and-testing.md) §14.5).

### The public shape

Availability is a **computed projection**, and it is the only booking-related thing a public
endpoint ever returns.

```json
{ "date": "2026-09-14",
  "timezone": "Europe/London",
  "slots": [ { "start": "2026-09-14T09:00:00Z", "end": "2026-09-14T09:30:00Z",
               "staff": { "reference_num": "stf_…", "name": "Dr Okafor" },
               "remaining": 1 } ] }
```

No booking rows. No names, no emails, no phone numbers. **A29** — where the public widget
downloads every customer's details to grey out taken times — is closed by this shape rather than
by filtering fields off a booking list.

`staff` is included only when `display.show_staff_names` is on; `remaining` only when
`display.show_remaining` is on ([04](04-database-schema.md) §4.10).

## 5.3 Schedules

`laravel_appointment_schedules` ([04](04-database-schema.md) §4.6) — recurring working hours per
weekday, owned by the business, a staff member or a resource.

| Property | |
|---|---|
| **Wall-clock, not UTC** | "09:00 every Tuesday" must stay 09:00 across DST (§5.6) |
| One row per contiguous block | A lunch break is the gap between two rows, not a "break" concept |
| Owner hierarchy | Business hours are the default; staff hours override; resource hours constrain |
| `effective_from` / `effective_to` | Seasonal patterns without re-entering them |

**Resolution**: a staff member with no schedule rows inherits the business schedule. A staff
member with schedule rows uses only their own — never the union, because a practitioner who
enters Monday and Tuesday means *"I work Monday and Tuesday"*, not *"add these to the shop's
hours"*.

### A33 — the empty-days trap

Today `availableDays.length === 0 || availableDays.includes(dayOfWeek)`
(`appointment-preview.ts:242`) means **unticking every day opens the calendar completely**.

The replacement is unambiguous: **no schedule rows means not bookable.** An operator who has
configured nothing sees "no availability" and a prompt to set hours — not an open diary.

## 5.4 Exceptions

`laravel_appointment_exceptions` ([04](04-database-schema.md) §4.7). Three kinds:

| Kind | Effect |
|---|---|
| `closed` | Removes every window that day for that owner |
| `custom_hours` | Replaces the day's windows |
| `extra_hours` | Adds a window on a normally-closed day |

Precedence: **resource → staff → business**, most specific wins. A clinic closed for a bank
holiday plus one practitioner working anyway is `closed` on the business and `extra_hours` on the
staff member.

**Bulk creation** matters: a tenant sets a year of public holidays once, from a country preset or
an uploaded list, and it applies to every staff member — or to chosen ones.

### Narrowing a schedule with bookings inside it

The case operators hit constantly, and where the wrong behaviour is destructive.

> **Existing bookings always stand.** Narrowing hours or adding a `closed` exception **never**
> cancels or moves a booking. The admin is shown exactly which bookings now fall outside the new
> hours and is offered: leave them, reschedule them individually, or cancel with notification —
> as an explicit action, never a side effect.

Silently cancelling on a schedule edit is the kind of behaviour that costs a tenant a customer,
and it is exactly what a naive "regenerate availability" implementation does.

## 5.5 Buffers, lead time, horizon

| Rule | Column | Purpose |
|---|---|---|
| **Buffer before** | `buffer_before_minutes` | Travel to the customer, room setup |
| **Buffer after** | `buffer_after_minutes` | Notes, cleaning, turnaround |
| **Minimum lead time** | `min_lead_minutes` | "Not within 2 hours of now" |
| **Booking horizon** | `max_horizon_days` | "No further than 60 days ahead" |
| **Granularity** | `granularity_minutes` | Offer :00 and :30, not every minute |
| **Daily cap** | `staff.max_daily_bookings` | "No more than 8 a day", regardless of gaps |

Splitting `buffer_time` into before and after (**A13**) is not pedantry: a mobile service needs
travel time *before*, a treatment needs cleaning *after*, and one number forces the tenant to set
the larger of the two on both sides and lose diary capacity.

**Travel time** for `location_mode: customer` is modelled as a buffer, optionally larger for the
first and last appointment of a day. Route optimisation is out of scope
([00](00-overview.md) §0.3); a fixed travel buffer covers the common case honestly.

**Lead time is evaluated against the customer's request instant**, not against midnight, and it
is re-checked at booking (§6.4) — a candidate list five minutes old can otherwise contain a time
that has since fallen inside the lead window.

## 5.6 Time zones and DST (A12, A32)

The module's deepest correctness gap. `date` is a `date`, `time` is a `varchar`, and there is no
zone anywhere.

### The rules

1. **Every booking stores `starts_at` / `ends_at` as UTC `timestamp`s.**
2. **Every schedule stores local wall-clock `time`s plus an IANA zone.** A schedule stored in UTC
   would drift by an hour twice a year.
3. **Every appointment, staff member and location carries an IANA zone** —
   `Europe/London`, never `GMT`, never `+01:00`.
4. **Availability expands in local time, then converts to UTC** at the last step.
5. **The customer's zone is captured on the booking** (`customer_timezone`) so confirmations,
   reminders and the ICS can show both.

### The cases to get right, and test

| Case | Expectation |
|---|---|
| 09:00 Tuesday across the spring-forward | Still 09:00 local; the UTC instant shifts by an hour |
| A schedule block containing 01:00–02:00 on a spring-forward night | That hour does not exist; no candidates are generated in it |
| A block containing 01:00–02:00 on an autumn night | The hour occurs twice; **candidates are generated once**, and the editor says which |
| Customer in New York, clinic in London | Offered London times, displayed as "14:00 London / 09:00 your time" |
| An overnight block, 22:00–02:00 | Spans midnight; candidates continue into the next date |
| Tenant changes the appointment's zone with bookings existing | **Stored instants do not move.** The admin is warned that displayed local times will change, and by how much |
| Staff member in a different zone from the business | Their schedule uses their zone; availability is the intersection |

The last one is what makes remote and multi-site teams work, and it is free once schedules carry
their own zone.

### A32 — the current date arithmetic

`new Date(booking.date) >= new Date()` (`appointments.ts:335`) parses `YYYY-MM-DD` as **UTC
midnight** and compares it to local now — an off-by-one day in negative-offset zones, and
today's already-past appointments still counted as upcoming.

Every date operation moves behind a single zone-aware helper
([12](12-admin-dashboard.md) §12.9). **No date is produced by string concatenation or by
`new Date(string)` on a bare date**, and that is a lint rule, not a convention.

## 5.7 What the customer sees

Availability has to be legible, and honest about why a time is missing:

| State | Display |
|---|---|
| Times available | The list, in the customer's zone with the business zone beside it |
| None that day | *"No availability on 14 September"* + **the next three dates that do have some** |
| All booked | *"Fully booked"* + waitlist if enabled ([08](08-booking-lifecycle.md) §8.8) |
| Inside lead time | *"Same-day bookings close 2 hours ahead"* — not silently absent |
| Beyond horizon | *"Bookings open 60 days ahead"* with the date they open |
| Closed that day | *"Closed on Sundays"* |
| Holiday | *"Closed — bank holiday"*, using the exception's reason |
| Service inactive | Not listed at all |

**"Next three available dates" is the single highest-value affordance a booking UI has**, and it
costs one extra query. Without it a customer clicks through an empty calendar and leaves.

## 5.8 Acceptance

- Availability is computed from schedules, exceptions, busy blocks, bookings, holds, buffers,
  lead time and horizon — no table of pre-generated slots exists anywhere.
- Dropping the availability cache changes no answer.
- **No schedule rows means no availability** — unticking every day closes the calendar
  (**A33**).
- An inactive service is never offered (**A34**).
- A `closed` exception removes a day; `custom_hours` replaces it; `extra_hours` opens a closed
  day.
- Bank-holiday bulk import closes a year of dates for every staff member in one action.
- Narrowing hours with bookings inside **does not** cancel or move them, and lists them.
- `buffer_before` and `buffer_after` are independent and both are enforced server-side
  (**A13**).
- A 30-minute service with a 15-minute after-buffer blocks 45 minutes of the diary and is
  described to the customer as 30 minutes.
- A booking at the lead-time boundary is refused at booking, not only hidden from the list.
- 09:00 weekly stays 09:00 across both DST transitions.
- No candidates are generated in a local hour that does not exist; the repeated hour generates
  candidates once.
- A New York customer sees both zones.
- A staff member in another zone contributes availability in theirs.
- A public availability response contains **no** booking row, name, email or phone (**A29**).
- An empty day offers the next three available dates.
