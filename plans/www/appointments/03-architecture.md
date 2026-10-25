# 03 — Architecture

## 3.1 The layers

```
    ┌────────────────────────────────────────────────────────────────┐
    │  PUBLIC   booking page · widget · builder blocks · SDK          │  11
    └─────────────────────────────┬──────────────────────────────────┘
                                  │  ask: when can I book X?
    ┌─────────────────────────────▼──────────────────────────────────┐
    │  AVAILABILITY   a pure function, never a table                  │  05
    │    schedules · exceptions · busy blocks · buffers · lead time   │
    │    minus existing bookings and holds                            │
    └─────────────────────────────┬──────────────────────────────────┘
                                  │  hold a candidate time
    ┌─────────────────────────────▼──────────────────────────────────┐
    │  BOOKING ENGINE   atomic claim · unique index · capacity        │  06
    └─────────────────────────────┬──────────────────────────────────┘
                                  │  optional
    ┌─────────────────────────────▼──────────────────────────────────┐
    │  DIRECT PAYMENTS   deposit · prepayment · refund                │  09
    └─────────────────────────────┬──────────────────────────────────┘
                                  │  confirmed
    ┌─────────────────────────────▼──────────────────────────────────┐
    │  BOOKING   lifecycle · reschedule · cancel · no-show · recurring│  08
    └─────────────────────────────┬──────────────────────────────────┘
                                  │
    ┌─────────────────────────────▼──────────────────────────────────┐
    │  EFFECTS (queued, server-side)  mail · ICS · reminders · sync   │  10
    └────────────────────────────────────────────────────────────────┘

    resources (any layer):  services · staff · rooms · locations      07
    identity (optional, per appointment):  Site Users  → ../site_users/
    questions (optional, per service):     Forms       → ../forms/
```

The **availability layer** is the one this module does not have. Today the browser generates
candidate times from a slot's `startTime`/`endTime`/`duration`/`bufferTime` and greys out
anything that appears in the booking list it downloaded (**A29**, **A31**). Everything in
[05](05-availability-and-scheduling.md) is that computation moved server-side and given the
inputs it needs.

## 3.2 Availability is a function, not a table

The central architectural commitment ([00](00-overview.md) §0.5 D2).

```
availability(service, staff?, resource?, from, to, customer_timezone?)
    → [ { start_utc, end_utc, staff_ref, resource_ref, remaining } ]
```

Inputs, all of which can change at any moment:

| Input | Source |
|---|---|
| Working hours | `laravel_appointment_schedules` — per staff, resource or business |
| Dated overrides | `laravel_appointment_exceptions` — holidays, half-days, extra hours |
| Imported busy time | `laravel_appointment_busy_blocks` — calendar sync ([10](10-communications-and-calendar.md) §10.6) |
| Existing bookings | `laravel_appointment_bookings`, non-cancelled |
| Live holds | `laravel_appointment_holds` ([06](06-booking-engine.md) §6.5) |
| Service rules | Duration, buffers, capacity, lead time, horizon, granularity |

Materialising that into rows means every one of those six inputs needs an invalidation path, and
missing one produces a bookable time that is not bookable — the worst failure this module has,
because the customer finds out at the moment of booking.

**Caching is allowed and is an optimisation only.** Keyed on
`(service, staff, date, inputs_version)`, invalidated whenever any input changes, and dropping
the entire cache must never change an answer — which is the property that makes it safe.

## 3.3 The vocabulary split

[00](00-overview.md) §0.4 defines the terms. Architecturally, one row is being split into three
concepts:

```
                          ┌─── SERVICE   what is offered, how long, what it costs
laravel_appointment_slots ┼─── SCHEDULE  when it is offered
                          └─── (slot)    a computed candidate time — no longer a row
```

`laravel_appointment_slots` today carries `name`, `description`, `duration`, `color`, `price`
(none), `max_bookings` — which are **service** properties — alongside `available_days`,
`start_time`, `end_time` — which are **schedule** properties. Bundling them means one service
can have exactly one weekly pattern, delivered by nobody in particular, in no particular room.

Splitting them is what makes staff, resources, exceptions, per-staff hours and group bookings
expressible ([04](04-database-schema.md) §4.3).

**The existing rows migrate cleanly**: each becomes one service plus one schedule
([04](04-database-schema.md) §4.12), and a single-operator tenant never sees the difference.

## 3.4 Direct Payments owns money

The same boundary [ecommerce/03](../ecommerce/03-architecture.md) §3.2 draws and
[events/03](../events/03-architecture.md) §3.3 repeats.

| Appointments owns | Direct Payments owns |
|---|---|
| What a service costs, what deposit it requires | Taking the money |
| Cancellation policy and fee **eligibility** | Executing the charge and the refund |
| Package and credit balances | The payment element, the provider, the customer record |
| The booking and its lifecycle | The charge and its status |

Appointments never imports a provider SDK, never holds a key, never handles a webhook. It builds
an invoice, hands it over, records what came back
([09](09-payments-and-policies.md) §9.3).

Money is **integer minor units, everywhere, from the first migration** — the mistake
[ecommerce **E9**](../ecommerce/02-current-state-audit.md) paid for once already.

## 3.5 Appointments owns the questions; forms owns asking them

The same reuse [events/03](../events/03-architecture.md) §3.4 makes.

`user_name`, `user_email` and `user_phone` are **native columns** — they appear in every day
sheet, reminder, export and confirmation, and making them form answers would mean resolving a
form version to render a day sheet.

Everything else custom — "what is this appointment about", "have you been before", intake
questionnaires, consent — attaches a **published form version** to a service
([08](08-booking-lifecycle.md) §8.3). Editing the form later never changes what a past booking
answered, which is the property forms' versioning exists to provide
([forms/09](../forms/09-publishing-and-versioning.md) §9.3).

A service with no custom questions never touches the forms module.

## 3.6 Identifiers

| Identifier | Layer | Example |
|---|---|---|
| `appointment.reference_num` | The bookable configuration | `apt_a1b2c3d4e5f6` |
| `service.reference_num` | A service | `svc_…` |
| `staff.reference_num` / `resource.reference_num` | Who / what | `stf_…` / `res_…` |
| `booking.reference_num` | A booking | `bkg_…` |
| `booking.access_token` | The emailed link's credential | high-entropy, [02](02-site-users-guard.md) §2.5 |

Two rules:

- **The server generates every reference.** Today the browser generates them from
  `Math.random()`, 7 characters, unchecked (**A18**, **A19**) — and because `saveAppointment()`
  is `updateOrCreate` on a client-supplied reference (**A14**), a collision silently overwrites
  somebody else's record instead of failing.
- **`access_token` is a credential, never an identifier.** `booking_id` currently serves as both
  (**A9**), so it appears in admin lists and afs-connect payloads while also being the only
  thing protecting a booking.

## 3.7 Time

Fully specified in [05](05-availability-and-scheduling.md) §5.6. The commitment:

- Every booking stores `starts_at` and `ends_at` as **UTC `timestamp`** columns.
- Every appointment, staff member and location carries an **IANA time zone**.
- A schedule's hours are **local wall-clock times** plus a zone — because "09:00 every Tuesday"
  must stay 09:00 across a DST boundary, which a stored UTC time cannot do.
- The customer's zone is captured on the booking, so a confirmation can show both.

The current model — `date` as a `date`, `time` as a `varchar`, no zone (**A12**) — cannot
represent an instant, which is why no reminder can be scheduled and no ICS file can be emitted.

## 3.8 Tenancy

`website_domain` and `website_reference_num` are on `laravel_appointments` and are **never
written and never filtered on** (**A5**).

- An appointment configuration belongs to one site; a tenant may own several.
- **Every admin query is scoped by the caller's site set**, through a model scope.
- The tenant is resolved from the **authenticated principal**, never from request input — the
  trap [forms/02](../forms/02-security-and-tenancy.md) §2.3 documents.
- Existing rows need a backfill, and rows that cannot be attributed surface in an "unassigned"
  list rather than being guessed at ([14](14-rollout-and-testing.md) §14.4).

## 3.9 Where state lives

The current answer is a `localStorage` mirror written fire-and-forget (**A15**), plus a second
unused key pair (**A25**), plus the exported widget's own third store (**A26**).

| State | Home |
|---|---|
| Services, schedules, exceptions, bookings | Server, authoritative |
| Availability | **Computed**, cached server-side (§3.2) |
| A customer's in-progress selection | A server-side **hold** with a TTL ([06](06-booking-engine.md) §6.5) |
| Admin editor draft | Server; `localStorage` only as crash recovery, keyed by reference |
| Widget state | Ephemeral, in memory. **Never `localStorage`** — a widget on a third-party site must not persist a tenant's calendar in a visitor's browser (**A26**) |

The hold matters as much here as in ticketing: without it, two customers can be shown the same
09:00 and one of them loses it after filling in a form.

## 3.10 Failure posture

| Situation | Behaviour |
|---|---|
| Requested time no longer available at hold | Refuse, return fresh availability, **name the next three options** |
| Hold expires during form completion | Attempt re-claim; if gone, refuse **before** any charge, and offer alternatives |
| Two customers claim the same capacity-1 time | The unique index refuses the second. One booking exists ([06](06-booking-engine.md) §6.3) |
| Capacity-*n* time claimed concurrently | Conditional `UPDATE`; the *n+1*th is refused |
| Payment fails | Booking stays `pending`, hold released, no booking confirmed ([09](09-payments-and-policies.md) §9.4) |
| **Payment succeeds, booking write fails** | Reconciled from the DP invoice ([09](09-payments-and-policies.md) §9.5). Money taken with no appointment is the one unacceptable outcome |
| Staff member deleted with future bookings | Refused. Deactivate instead; existing bookings keep their staff snapshot |
| Schedule narrowed with bookings outside the new hours | **Existing bookings stand.** The admin is warned and shown which ones ([05](05-availability-and-scheduling.md) §5.4) |
| Calendar sync unavailable | Availability falls back to in-product schedules and **flags the degradation** — never silently offers time the practitioner has blocked ([10](10-communications-and-calendar.md) §10.6) |
| Reminder job fails | Retried; visible on the booking's timeline, never silent |
| A save fails | **The UI says so** (**A15**) |

The two rows that encode the module's values: **never confirm a booking that was not written**,
and **never offer time the tenant has already committed elsewhere**. The second is why calendar
sync degrades loudly rather than quietly.

## 3.11 What this plan deliberately does not build

| Not building | Why |
|---|---|
| A payment integration | §3.4 |
| A question builder | §3.5 — [forms](../forms/README.md) |
| An identity system | [site_users](../site_users/README.md) |
| A rota / shift-planning system | Staff availability, yes; HR scheduling is a product ([00](00-overview.md) §0.3) |
| Route optimisation | Travel time as a buffer ([05](05-availability-and-scheduling.md) §5.5) |
| A queue / walk-in system | Adjacent and separable |
| Video hosting | A join link with timed release ([07](07-services-staff-and-resources.md) §7.7) |
| A full CRM | Customer history ([13](13-reporting-and-analytics.md) §13.5), not pipelines |
| Multi-currency presentment | §3.4 |
