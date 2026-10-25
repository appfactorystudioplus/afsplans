# 00 — Overview

## 0.1 What this module is for

A tenant publishes what they offer, when they are available, and who can deliver it. A customer
finds a time that genuinely works, books it, gets told where to be, is reminded, and can change
or cancel it without emailing anyone. The tenant opens a day sheet and sees who is coming.

Most of that exists as *screens*. Almost none of it exists as *behaviour*
([01](01-current-state-audit.md)).

## 0.2 Goals

| # | Goal | Where |
|---|---|---|
| G1 | No endpoint deletes by omission, and no admin endpoint is reachable without credentials | [02](02-site-users-guard.md) §2.2, [06](06-booking-engine.md) §6.2 |
| G2 | No public response ever contains another customer's details | [11](11-public-surface-and-widget.md) §11.3 |
| G3 | A slot with a capacity of *n* accepts exactly *n* bookings, under load | [06](06-booking-engine.md) §6.3 |
| G4 | Availability reflects schedules, exceptions, buffers, lead times and existing bookings | [05](05-availability-and-scheduling.md) |
| G5 | The stated time is the right time, in both parties' zones, across DST | [05](05-availability-and-scheduling.md) §5.6 |
| G6 | Several staff and several resources can be booked without colliding | [07](07-services-staff-and-resources.md) |
| G7 | A booking can be confirmed, rescheduled, cancelled and marked no-show | [08](08-booking-lifecycle.md) |
| G8 | A customer can do all of that themselves | [08](08-booking-lifecycle.md) §8.6, [02](02-site-users-guard.md) §2.7 |
| G9 | Deposits and prepayment go through Direct Payments and nothing else | [09](09-payments-and-policies.md) |
| G10 | Confirmations, reminders and calendar invites are sent by the server, every time | [10](10-communications-and-calendar.md) |
| G11 | The embeddable widget books against the real API | [11](11-public-surface-and-widget.md) §11.5 |
| G12 | An operator can see today, this week, and who did not turn up | [12](12-admin-dashboard.md), [13](13-reporting-and-analytics.md) |

## 0.3 Non-goals

| Not building | Why |
|---|---|
| A payment integration | [03](03-architecture.md) §3.4. Direct Payments already exists |
| A form builder | Custom booking questions attach a form from the [forms module](../forms/README.md) ([08](08-booking-lifecycle.md) §8.3) |
| An identity system | [Site Users](../site_users/README.md), optional per appointment ([02](02-site-users-guard.md) §2.6) |
| A video platform | Online appointments store a join link with timed release ([07](07-services-staff-and-resources.md) §7.7) |
| A CRM | Customer records, notes and history ([13](13-reporting-and-analytics.md) §13.5); pipelines and campaigns are a different product |
| A payroll or rota system | Staff availability, yes. Shift planning, holiday accrual and timesheets are an HR product |
| Route optimisation for mobile services | Travel time between locations is modelled as a buffer ([05](05-availability-and-scheduling.md) §5.5); optimising a technician's day is its own field |
| A queue / walk-in ticketing system | Appointments are scheduled. Walk-in queueing is adjacent and separable |
| Multi-currency presentment | One currency per tenant, as [ecommerce/03](../ecommerce/03-architecture.md) §3.8 decided |

## 0.4 Glossary

The current vocabulary is one word — "slot" — doing four jobs, which is most of why the module
cannot grow. Fixing that first:

| Term | Meaning here |
|---|---|
| **Service** | What is offered — "30-minute consultation", "haircut", "site survey". Has a duration, a price, buffers and rules. **This is what the current `slot` row mostly is** |
| **Schedule** | A recurring pattern of working hours, belonging to a staff member, a resource or the business |
| **Exception** | A dated override on a schedule — a holiday, a half-day, an extra Saturday |
| **Availability** | The **computed** set of bookable start times for a service, on a date, given schedules, exceptions, buffers, lead time and existing bookings. **Never stored** |
| **Slot** | One computed candidate start time. A transient value, not a row |
| **Hold** | A candidate time reserved for a few minutes while a customer completes the form |
| **Booking** | One confirmed appointment: service, staff, resource, customer, start instant, duration |
| **Staff** | A person who delivers a service, with their own schedule |
| **Resource** | A room, chair, machine or vehicle that a booking consumes |
| **Location** | Where it happens — a site, the customer's address, or online |
| **Capacity** | How many bookings one start time accepts. **This is the current `max_bookings`, which is never read** |

The distinction that does the most work: **`slot` today means "service definition" in the
config screen and "candidate time" in the booking screen.** Splitting those two ideas is what
makes staff, resources, exceptions and group bookings expressible at all.

## 0.5 The five decisions

### D1 — Bulk replace is removed, not authenticated

The obvious P0 is "put `ApiAuthMiddleware` on the routes". That closes **A1** for attackers and
leaves it fully armed for the tenant, because the admin UI is what fires it today (**A16**,
**A17**): every slot edit and every booking sends a whole array rebuilt from a client cache,
and anything missing from that cache is deleted.

Chosen: **the replace endpoints are deleted.** `POST /bookings` creates one booking.
`PUT /slots/{id}` updates one service. `DELETE /slots/{id}` deletes one. Nothing deletes by
omission, ever ([06](06-booking-engine.md) §6.2).

That single change closes the unauthenticated calendar wipe *and* the tenant's own data-loss
path, and it is a smaller change than securing the endpoint would be.

### D2 — Availability is computed, and cached with intent

Rejected: materialising bookable slots into a table. It is fast to read and wrong the moment
anything changes — a staff member takes a morning off and a thousand rows are stale. Every
system that does this grows a reconciler, and the reconciler is where the bugs live.

Chosen: **availability is a pure function** of (service, staff, resource, date range) over
schedules, exceptions, bookings, buffers, lead time and horizon
([05](05-availability-and-scheduling.md) §5.2). It is cached per (service, staff, date) with an
explicit invalidation on every input change, and the cache is an optimisation that can be
dropped without changing an answer.

### D3 — A booking is claimed by a unique index

`max_bookings` is stored and never read (**A10**); there is no unique constraint (**A11**). So
the module's central promise is unimplemented on both sides of the wire.

Chosen: a **partial unique index** on the booking's identity for capacity-1 services, plus a
conditional `UPDATE` against a per-start-time counter for capacity-*n*
([06](06-booking-engine.md) §6.3). Never `count()` then `INSERT` — the pattern
[appointments' own siblings](../events/06-ticketing-and-capacity.md) §6.5 also had to fix.

The database refuses the double-booking. Application checks exist for the *message*, not for the
guarantee.

### D4 — Staff and resources are first-class from the schema

Rejected: adding "staff" later as a string on a booking. Every scheduling product needs it by
its second real tenant — a clinic with two practitioners, a salon with four chairs, a garage
with two ramps — and retrofitting it means recomputing every availability query and every
booking's meaning.

Chosen: `staff`, `resources` and their schedules exist from the first migration
([04](04-database-schema.md) §4.4, §4.5). A single-operator tenant gets **one implicit staff
member** and never sees the concept — the same "always one default" rule that keeps
[ecommerce](../ecommerce/04-catalogue.md) §4.4's variant handling single-pathed.

### D5 — The tenant's calendar is the source of truth for *their* time

A practitioner who blocks two hours in Google Calendar must not be bookable in those two hours.
No amount of in-product schedule editing substitutes for that, because the practitioner will
not do it twice.

Chosen: **two-way calendar sync** ([10](10-communications-and-calendar.md) §10.6) — bookings
push out as events; busy blocks pull in as availability exclusions. Scoped to a later phase,
designed for from the start: `laravel_appointment_busy_blocks` exists in the schema so that
imported busy time is a first-class availability input rather than a bolt-on.

## 0.6 What "comprehensive" means here, concretely

Everything under **A35** — the capability the module has no concept of — with a home:

| Capability | § |
|---|---|
| Services with per-service duration, buffers, price, questions | [07](07-services-staff-and-resources.md) §7.2 |
| Staff, per-staff schedules, per-staff services | [07](07-services-staff-and-resources.md) §7.3 |
| Assignment strategies — any, specific, round-robin, least-booked | [07](07-services-staff-and-resources.md) §7.4 |
| Rooms, equipment and other resources | [07](07-services-staff-and-resources.md) §7.5 |
| Group appointments and classes | [07](07-services-staff-and-resources.md) §7.6 |
| Locations: on-site, customer address, online | [07](07-services-staff-and-resources.md) §7.7 |
| Recurring weekly schedules with date exceptions and holidays | [05](05-availability-and-scheduling.md) §5.3–§5.4 |
| Buffers before and after, and travel time | [05](05-availability-and-scheduling.md) §5.5 |
| Lead time, booking horizon, cutoff | [05](05-availability-and-scheduling.md) §5.5 |
| Time zones and DST, for both parties | [05](05-availability-and-scheduling.md) §5.6 |
| Atomic booking, holds, concurrency | [06](06-booking-engine.md) |
| Approval-required bookings | [08](08-booking-lifecycle.md) §8.4 |
| Reschedule, cancel, no-show, completion | [08](08-booking-lifecycle.md) §8.2 |
| **Recurring bookings** — a weekly appointment for ten weeks | [08](08-booking-lifecycle.md) §8.7 |
| Waitlist for a full day or a preferred time | [08](08-booking-lifecycle.md) §8.8 |
| Custom booking questions via the forms module | [08](08-booking-lifecycle.md) §8.3 |
| Deposits, prepayment, cancellation fees | [09](09-payments-and-policies.md) |
| **Packages and credits** — "a block of six sessions" | [09](09-payments-and-policies.md) §9.6 |
| Confirmations, reminders, ICS, follow-ups | [10](10-communications-and-calendar.md) |
| Two-way calendar sync | [10](10-communications-and-calendar.md) §10.6 |
| A widget that books, builder blocks, a hosted booking page | [11](11-public-surface-and-widget.md) |
| Calendar and day-sheet admin views | [12](12-admin-dashboard.md) §12.4 |
| Utilisation, no-show rate, revenue, retention | [13](13-reporting-and-analytics.md) |

## 0.7 What "solid" means for this module

Six properties, each of which fails today, each with an acceptance test in
[14](14-rollout-and-testing.md):

1. **No request deletes data it did not name.** (**A1**, **A2**, **A16**, **A17**)
2. **No customer's details are visible to anyone but the tenant and that customer.**
   (**A3**, **A29**)
3. **A time offered is a time that can be booked**, and a time booked is not offered again.
   (**A10**, **A11**, **A31**)
4. **A save that fails says so.** (**A15**)
5. **The stated time is the right time**, in both zones, across DST. (**A12**, **A32**)
6. **A booking made is a booking recorded** — and the confirmation the customer sees means it.
   (**A27**, **A30**)

Number six is the one to keep in view. Today a customer can complete the exported widget's
booking flow, see a confirmation screen, and have nothing exist anywhere.
