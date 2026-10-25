# 07 — Services, Staff and Resources

Closes the largest part of **A35** — the capability the module has no concept of.

## 7.1 What the module models today

One anonymous calendar with weekly opening hours. A `slot` row is simultaneously the service
definition and the schedule ([03](03-architecture.md) §3.3), delivered by nobody in particular,
in no particular room, at one price (none), with one capacity (unenforced).

That is enough for a single practitioner with one offering. It stops working at the second
practitioner, the second treatment room, or the first service that takes an hour instead of
thirty minutes.

## 7.2 Services

`laravel_appointment_services` ([04](04-database-schema.md) §4.3). What is offered.

| Property | Purpose |
|---|---|
| `duration_minutes` | Per service — a consultation and a follow-up differ |
| `buffer_before` / `buffer_after` | Travel in, turnaround out ([05](05-availability-and-scheduling.md) §5.5) |
| `granularity_minutes` | Offer :00 and :30 rather than every minute |
| `capacity` | 1 for individual, *n* for group (§7.6) |
| `price_cents`, `deposit_cents` | [09](09-payments-and-policies.md) |
| `min_lead_minutes`, `max_horizon_days` | When it can be booked |
| `cancel_up_to_hours`, `reschedule_up_to_hours` | [08](08-booking-lifecycle.md) §8.6 |
| `requires_approval` | [08](08-booking-lifecycle.md) §8.4 |
| `booking_form_ref` | Custom questions ([08](08-booking-lifecycle.md) §8.3) |
| `staff_mode` | §7.4 |
| `location_mode` | §7.7 |
| `active` | **Now actually filtered on** (**A34**) |

### Service categories and variants

- **Categories** group services on a long booking page — "Consultations", "Treatments",
  "Assessments". One string on the service; no new table.
- **Per-staff overrides** live on the `service_staff` pivot
  ([04](04-database-schema.md) §4.5): a senior practitioner may take 60 minutes where a junior
  takes 45, and may charge differently. Without the override, a tenant duplicates the service per
  staff member and the booking page doubles in length.

### Intake requirements

`min_lead_minutes` covers "not within 2 hours". Services needing preparation — a form completed
first, a deposit paid, a document uploaded — express it as `requires_approval` plus a booking
form ([08](08-booking-lifecycle.md) §8.3), rather than a bespoke prerequisite system.

## 7.3 Staff

`laravel_appointment_staff` ([04](04-database-schema.md) §4.4).

| Capability | |
|---|---|
| **Own schedule** | Rows in `schedules` owned by the staff member; falls back to business hours ([05](05-availability-and-scheduling.md) §5.3) |
| **Own exceptions** | Holidays, training, half-days ([05](05-availability-and-scheduling.md) §5.4) |
| **Own time zone** | Remote and multi-site teams ([05](05-availability-and-scheduling.md) §5.6) |
| **Own services** | Via `service_staff` — not everyone delivers everything |
| **Daily cap** | `max_daily_bookings` — eight a day regardless of gaps |
| **Own calendar** | Two-way sync per staff member ([10](10-communications-and-calendar.md) §10.6) |
| **Own diary view** | `portal_user_id` scopes the admin to their own bookings ([12](12-admin-dashboard.md) §12.8) |
| **Public profile** | Name, title, photo, bio — shown when `display.show_staff_names` |

**A single-operator tenant gets one implicit staff member**, created with the appointment and
hidden throughout the UI ([00](00-overview.md) §0.5 D4). One code path, always: the alternative
is a "no staff" branch that skips per-staff schedules, caps and calendar sync, and then has to
grow them back when the tenant hires someone.

Deactivating a staff member removes them from availability immediately. **Deleting one with
future bookings is refused** ([03](03-architecture.md) §3.10) — those bookings would lose their
meaning, and their `service_snapshot` already records the name for the historical record
([04](04-database-schema.md) §4.9.1).

## 7.4 Assignment strategies

`services.staff_mode` — how a booking gets a staff member.

| Mode | Behaviour | Fits |
|---|---|---|
| `any` | The customer is not asked; the system picks | A clinic where any nurse can take bloods |
| `specific` | The customer chooses; availability is per staff | A salon where clients have a stylist |
| `round_robin` | Rotate fairly across eligible staff | A sales team sharing enquiries |
| `least_booked` | Whoever has fewest bookings that day/week | Load balancing |

Plus a common hybrid the UI should offer explicitly: **"choose a person, or let us pick"** —
`specific` with a "no preference" option that falls through to `least_booked`. Most customers
take it, and it flattens the diary.

Assignment happens **inside the booking transaction** ([06](06-booking-engine.md) §6.4), against
live availability. Assigning before the transaction means the chosen person may be taken by the
time the insert runs.

When a specific staff member is unavailable, the refusal offers **others who can deliver the same
service at that time** ([06](06-booking-engine.md) §6.6) — one query, and it recovers a large
share of failed bookings.

## 7.5 Resources

`laravel_appointment_resources` ([04](04-database-schema.md) §4.5). A room, chair, machine or
vehicle that a booking consumes.

The case that makes it necessary: **two practitioners, one treatment room.** A staff-only model
offers both at 10:00; the room has one bed. No amount of schedule editing fixes that, because the
constraint is not on either person.

| Rule | |
|---|---|
| A service may require a resource **type** or a specific one | `service_resource` pivot |
| A resource is subtracted from availability like a staff member | [05](05-availability-and-scheduling.md) §5.2 |
| A resource may have its own schedule and exceptions | A room unavailable Wednesdays for deep cleaning |
| A resource with `capacity > 1` accepts concurrent bookings | A waiting area, a class studio |
| Assignment is automatic — first free | Manual override in the admin |

**Resource conflicts are checked in the same transaction as staff and capacity**
([06](06-booking-engine.md) §6.4), with a consistent lock ordering — service, staff, resource —
so concurrent bookings cannot deadlock.

## 7.6 Group appointments and classes

`services.kind` — `individual` · `group` · `class`.

| Kind | Meaning |
|---|---|
| `individual` | Capacity 1. One customer, one time |
| `group` | Capacity *n* at one start time; each customer books independently. A yoga class, a workshop |
| `class` | A `group` service with a **fixed schedule** rather than open availability — Tuesdays at 18:30, not "any free time" |

Group booking uses the counter mechanism ([06](06-booking-engine.md) §6.3), and the public
surface shows `remaining` when the tenant enables it
([04](04-database-schema.md) §4.10) — *"3 places left"* being information and *"47 places left"*
being noise.

A customer may book **several places** on a group service (`quantity` on the booking), each
consuming capacity, with attendee names collected or not per
`settings.booking`. Where a tenant needs per-attendee data, ticketing semantics start to apply
and [events](../events/README.md) is the better fit — that boundary is worth stating so the two
modules do not converge.

## 7.7 Locations

`services.location_mode`:

| Mode | Behaviour |
|---|---|
| `onsite` | At the business address. The venue detail goes in the confirmation and the ICS |
| `customer` | At the customer's address — collected at booking, travel buffer applied ([05](05-availability-and-scheduling.md) §5.5) |
| `online` | A join link, **released on a schedule** |
| `any` | The customer chooses; the choice may change the duration or price via the pivot |

**Online links are released on a schedule**, not embedded in the confirmation — the same rule
[events/05](../events/05-event-model-and-scheduling.md) §5.7 applies: immediate, N minutes
before, at start, or manual. Default is *before start*. The link also appears on the booking page
reachable by `access_token` ([02](02-site-users-guard.md) §2.5), so a lost email is not a lost
appointment.

For `customer` mode, the address is **required at booking**, validated, and included in the
staff member's day sheet and calendar push — a mobile service whose diary does not carry
addresses is not usable.

## 7.8 Multi-location businesses

A tenant with three branches:

- Locations are records with an address and a **time zone**.
- Staff and resources belong to locations.
- A service may be offered at some locations and not others.
- The customer picks a location first; availability is scoped to it.
- Each location can have its own hours and its own exceptions.

This falls out of the schema rather than needing new machinery: a location is an owner of
schedules and exceptions, exactly as staff and resources are.

## 7.9 Acceptance

- A single-operator tenant never sees the word "staff", and one implicit staff member exists.
- Two practitioners sharing one room are never both offered the same time (**resource
  conflict**).
- A senior practitioner's 60-minute duration override is honoured in availability and on the
  booking.
- `staff_mode: specific` shows per-staff availability; `any` never asks.
- `round_robin` distributes evenly across eligible staff over 100 bookings.
- `least_booked` picks the emptiest diary.
- "No preference" falls through to `least_booked`.
- A specific staff member being unavailable offers others who can deliver the service.
- Assignment happens inside the booking transaction — a race cannot assign a taken person.
- Deactivating a staff member removes them from availability; deleting one with future bookings
  is refused.
- A service not linked to any staff member is never bookable.
- A group service with capacity 6 accepts exactly 6 at one start time.
- A class service offers only its fixed schedule.
- An online service's join link is absent from the confirmation and released before the start.
- A `customer`-location booking requires and validates an address, and it reaches the day sheet.
- A three-location tenant scopes availability by chosen location, with per-location zones.
