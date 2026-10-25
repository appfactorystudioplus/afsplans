# appointments — Planning Index

Turning a slot list into a scheduling system: real availability rules, staff and resources,
atomic booking, reschedule and cancellation, deposits, reminders, calendar sync, and a public
widget that actually books.

> ### 🔴 One unauthenticated request deletes a tenant's entire booking calendar
>
> `POST /portal/api/v1/appointments/{ref}/bookings` is a **bulk replace**: it upserts the
> bookings in the payload, then deletes every booking not in it
> (`AppointmentsController.php:213`). Its only wrapper is `portal.cors`, an explicit no-op.
> A `curl` containing one dummy booking wipes the calendar. The same pattern deletes slots
> (**A2**), `GET .../bookings` returns **every customer's name, email, phone and notes**
> without credentials (**A3**), and an unauthenticated `DELETE /appointments/{ref}` cascades
> through the foreign keys to destroy every slot and every booking (**A4**).
>
> ### 🔴 And the admin UI performs that same bulk replace on every edit
>
> Adding one slot, toggling one slot's active flag, or deleting one slot sends the **whole
> array** through `saveSlots()` → `dataStore.saveAppointmentSlots()` → the replace endpoint
> (**A16**). The array is built from a client-side cache. **If the cache is stale or a fetch
> failed, saving deletes everything missing from it** — no attacker required, and no warning.
>
> ### 🔴 And the public booking widget cannot book
>
> `exportWidget()` generates a 1,038-line standalone widget with **no `apiEndpoint`
> configured**, so it reads slots from *the visitor's own* `localStorage` and writes bookings
> there (**A26**). Even when an endpoint is set, it POSTs a **single booking object** to an
> endpoint that reads `bookings[]` — so the payload parses as empty, nothing is written,
> `success: true` comes back, and the customer is shown a confirmation for a booking that does
> not exist (**A27**).
>
> See [01-current-state-audit.md](01-current-state-audit.md). Phase **P0**
> ([02](02-site-users-guard.md) §2.8) closes the security holes and depends on nothing.

Read in this order:

| Step | Document | Contents |
|---|----------|----------|
| — | [README.md](README.md) | This index |
| 00 | [00-overview.md](00-overview.md) | What this is for, goals, non-goals, glossary, the five decisions |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | Every file, table and route that exists, and the 35 findings |
| 02 | [02-site-users-guard.md](02-site-users-guard.md) | The split API, optional customer accounts, the emailed link, booking modes. **Extended by [`../connections/`](../connections/README.md)**: the calendar↔module link, gating holds and waitlists, `policy_snapshot` enforced on self-service cancel and reschedule, and the customer's Bookings dashboard |
| 03 | [03-architecture.md](03-architecture.md) | The layers, and why availability is computed rather than stored |
| 04 | [04-database-schema.md](04-database-schema.md) | The full schema — services, staff, resources, schedules, exceptions, bookings, holds |
| 05 | [05-availability-and-scheduling.md](05-availability-and-scheduling.md) | **Availability rules**, exceptions, buffers, lead times, horizons, time zones and DST |
| 06 | [06-booking-engine.md](06-booking-engine.md) | **Atomic booking**, capacity, the unique index, holds, concurrency |
| 07 | [07-services-staff-and-resources.md](07-services-staff-and-resources.md) | Multiple staff, rooms and equipment, assignment strategies, group and class bookings |
| 08 | [08-booking-lifecycle.md](08-booking-lifecycle.md) | Confirm, reschedule, cancel, no-show, approval, **recurring bookings**, waitlist |
| 09 | [09-payments-and-policies.md](09-payments-and-policies.md) | Deposits, prepayment, cancellation fees, packages and credits |
| 10 | [10-communications-and-calendar.md](10-communications-and-calendar.md) | Confirmations, reminders, ICS, **two-way calendar sync** |
| 11 | [11-public-surface-and-widget.md](11-public-surface-and-widget.md) | The public API, the SDK, the embeddable widget, builder blocks, booking pages |
| 12 | [12-admin-dashboard.md](12-admin-dashboard.md) | The operator surface — calendar, day sheet, and what happens to the three controllers |
| 13 | [13-reporting-and-analytics.md](13-reporting-and-analytics.md) | Utilisation, no-shows, revenue, exports, retention |
| 14 | [14-rollout-and-testing.md](14-rollout-and-testing.md) | Phases, the security gate, the concurrency matrix |

## Completion documents

Each numbered document gets a completion document in [`complete/`](complete/) — source
basename plus `.complete.md`. Include status, summary, files changed, verification steps and
follow-up work.

## Code documentation standard

Document all new and changed code thoroughly with block comments — PHPDoc for PHP, JSDoc for
TypeScript. Every file, exported symbol, public function, route handler, migration and
non-trivial type must explain purpose, parameters, return values, side effects and plan
references.

New code carries `AFS_APPOINTMENTS_MODIFIER_ADDITION` (see
[../../markers.md](../../markers.md)); Site Users work carries
`AFS_SITE_USERS_MODIFIER_ADDITION`. Where a file already has a marker, **add alongside; never
replace.**

## The five rules this plan is built on

**1. Bulk replace is deleted, not secured.**
Authenticating an endpoint whose semantics are *"delete everything absent from this payload"*
leaves a foot-gun aimed at the tenant's own admin UI — which is where **A16** fires today.
Booking creates one row. Slot save updates one slot. Nothing deletes by omission
([06](06-booking-engine.md) §6.2).

**2. A booked slot is claimed by a unique index, not by a check.**
`max_bookings` is stored and never enforced (**A10**), and there is no unique constraint
(**A11**), so double-booking is not a race — it is the default behaviour. The fix is a database
constraint plus a conditional claim, never a `count()` followed by an `INSERT`
([06](06-booking-engine.md) §6.3).

**3. Availability is computed, never stored.**
Materialising every bookable slot for the next year produces a table that is wrong the moment a
staff member takes a morning off. Availability is derived from schedules, exceptions and
existing bookings at query time, and cached with an explicit invalidation
([05](05-availability-and-scheduling.md) §5.2).

**4. Time is an instant with a zone, not a `date` and a string.**
`date` is a `date` and `time` is a `varchar`, with no time zone anywhere (**A12**). Two people
in different zones cannot agree on when "10:00" is, no reminder can be scheduled, and no
calendar file can be emitted ([05](05-availability-and-scheduling.md) §5.6).

**5. Nothing that matters happens in the customer's browser.**
The mail handlers configured in the Response view are read by nothing (**A30**), and the
exported widget carries the tenant's whole booking list into a stranger's `localStorage`
(**A26**). Confirmations, reminders and calendar invites are sent by the server
([10](10-communications-and-calendar.md) §10.2).

## One-paragraph summary

The appointments module is the most dangerous in this ecosystem and the closest to working. It
has a real schema with real foreign keys, a real admin UI across three controllers, a fully
built booking flow in the preview, and a 1,038-line embeddable widget — sitting on eight
unauthenticated routes, two of which delete by omission, one of which publishes every
customer's name, email and phone to anyone who asks. Its central promise, a slot with a
capacity, is not implemented: `max_bookings` is never read, there is no unique constraint, and
the booking UI treats any single booking as filling a slot regardless. This plan closes the
security hole first and **removes bulk replace entirely**, then builds the scheduling system
the slot list implies — availability computed from **staff and resource schedules with
exceptions, buffers, lead times and real time zones**, bookings claimed atomically against a
unique index, reschedule and cancellation with policy, recurring appointments, waitlists,
deposits through Direct Payments, reminders and two-way calendar sync, and a public widget that
posts a single booking to an endpoint that creates one.
