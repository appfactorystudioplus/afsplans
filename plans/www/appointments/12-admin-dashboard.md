# 12 — The Admin Dashboard

`controllers/appointments/` — three files, 1,467 lines — plus `utils/appointment-export.ts`.
Closes **A15**, **A16**, **A18**–**A25**, **A32**.

## 12.1 What exists, honestly

| View | State |
|---|---|
| `appointments` | A table of appointment objects; Configure / Preview / Duplicate / Response. **No delete** (**A21**) |
| `appointments-configure` | A slot grid with name, hours, days, capacity/buffer, active badge; add/edit/deactivate/delete via a modal; an "export widget" action |
| `appointment-preview` | A complete booking flow — slot cards, month calendar, time grid, form, confirmation |
| `appointments:response` | `EmailConstructionBuilder`, wired to nothing (**A30**), whose cancel button navigates to **events** (**A22**) |

The UI is competent and the flows are the right ones. What is wrong sits underneath: every slot
mutation is a whole-array replace built from a client cache (**A16**), writes are
fire-and-forget so a failure is invisible (**A15**), references are generated in the browser from
`Math.random()` (**A18**, **A19**), duplication clones real customers' bookings (**A20**), and
there are two `escapeHtml` implementations and a wall of inline `onclick` handlers bound to
`window` (**A23**, **A24**).

**`escapeHtml` is applied to every user-supplied string in both files**, which is more discipline
than [forms](../forms/01-current-state-audit.md) showed (**F35**, **F36**). Keep that.

## 12.2 The shape

Four screens become a module:

```
Appointments
├── Overview            §12.3   today, tomorrow, what needs attention
├── Calendar            §12.4   day · week · month · staff columns
├── Day sheet           §12.4   the printable list a practitioner works from
├── Bookings            §12.5   list · detail · lifecycle actions
├── Configurations      §12.6
│   └── Appointment
│       ├── Services            duration, buffers, price, capacity, questions
│       ├── Staff               profiles, services, caps, calendar connections
│       ├── Resources           rooms, equipment
│       ├── Schedules           weekly hours per owner
│       ├── Exceptions          holidays, closures, extra hours
│       ├── Waitlist            entries and offers
│       ├── Communications      templates, reminders
│       ├── Share       §12.7   links, embeds, QR
│       └── Settings    §12.8   policy, holds, accounts, sync
├── Customers           13 §13.5
└── Reports             13
```

## 12.3 Overview

*What needs me today*, not a chart.

- **Today's bookings** — count, next one, and any unconfirmed.
- **Tomorrow**, so an operator can act on gaps while there is time.
- **Pending approvals** ([08](08-booking-lifecycle.md) §8.4).
- **Awaiting payment** — deposits or prepayments outstanding.
- **Waitlist offers expiring today**.
- **Yesterday's unmarked bookings** — not yet `completed` or `no_show`
  ([08](08-booking-lifecycle.md) §8.2).
- **Failures** — payments, reconciliation, undelivered mail, **stale or expired calendar
  connections** ([10](10-communications-and-calendar.md) §10.6).
- **Counter disagreements** ([06](06-booking-engine.md) §6.3) — an inventory bug surfacing
  before it double-books.
- **Setup warnings** — a published priced service with no Direct Payment; a service with no
  staff assigned (so it is never bookable); a staff member with no schedule; a service whose
  price disagrees with its DP item ([09](09-payments-and-policies.md) §9.3); no reminders
  configured.

"A service with no staff assigned" is the warning that matters most: it is silently unbookable,
and the tenant's first sign is an empty diary.

## 12.4 Calendar and day sheet

The two views a scheduling product is used through, and neither exists.

### Calendar

- **Day, week, month**, plus a **staff-column day view** — the layout a multi-practitioner
  business works from.
- Bookings as blocks, coloured by service, showing customer, service and time.
- **Buffers rendered as hatched extensions**, so an operator can see why 10:30 is unavailable
  ([05](05-availability-and-scheduling.md) §5.5).
- **Busy blocks from synced calendars shown distinctly** — the practitioner needs to see that
  their own calendar is why a time is blocked.
- Exceptions and closures shaded.
- **Drag to reschedule**, with a confirmation naming the customer, and running the same
  claim-then-release transaction as any reschedule ([06](06-booking-engine.md) §6.8) — never a
  direct write.
- Click an empty time to create a booking (§12.5).
- Filters: staff, service, resource, location, status.

### Day sheet

The printable, one-screen list a practitioner actually uses: time, customer, service, duration,
location, phone, notes, flags, payment status, and whether they are new or returning. Printable
and emailable each morning ([10](10-communications-and-calendar.md) §10.3).

Unglamorous, and the thing a receptionist opens forty times a day.

## 12.5 Bookings

### The list

- **Columns**: date/time, customer, service, staff, status, payment, source, booked-at.
- **Filters**: date range, status, service, staff, resource, location, payment status, source,
  new vs returning.
- **Search** by name, email, phone, reference.
- **Cursor pagination** — not the "5 upcoming" the current UI shows
  (`appointments.ts:338`).
- **Bulk**: export, mark completed, mark no-show, send a message.

### Manual booking

Staff booking on a customer's behalf — phone bookings, walk-ins, and the operator override.

- Runs **the same booking transaction** as the public path
  ([06](06-booking-engine.md) §6.4), so capacity, buffers and resources are honoured.
- **Override is explicit** — an operator may book outside availability, and it is flagged,
  logged and still claims capacity. Operators will always need to squeeze someone in; the answer
  is a deliberate override, not a path around the rules.
- Optionally skips the confirmation email, for a booking taken while the customer is on the
  phone.

### The detail

| Panel | |
|---|---|
| Booking | Service, staff, resource, **snapshot** time and price, location |
| Customer | Name, email, phone, **history and no-show count** ([13](13-reporting-and-analytics.md) §13.5) |
| Answers | Rendered through the pinned form version ([08](08-booking-lifecycle.md) §8.3) |
| Payment | Paid, outstanding, deposit, refunds, **link into the Money ledger** |
| **Timeline** | Every activity entry, newest first ([04](04-database-schema.md) §4.11) |
| Notes | Customer-supplied and internal, **visually distinct** |

**Actions**: confirm, reschedule, cancel with/without fee, mark completed, mark no-show, resend
the confirmation, take payment, refund, add a note.

**The timeline is what makes support possible.** *"Was this customer reminded, and when"* is the
most common question a booking screen receives, and neither this module nor either sibling can
answer it today.

## 12.6 Configuration

Services, staff, resources, schedules and exceptions each get a real screen
([07](07-services-staff-and-resources.md), [05](05-availability-and-scheduling.md)). Three
things worth specifying because they are where operators make expensive mistakes:

- **The schedule editor is a weekly grid**, not a form. Drag to set hours, split shifts by
  leaving a gap, copy a day to other days, copy one staff member's week to another.
- **Exceptions have a bulk path** — import a country's public holidays, or select a date range
  and close it for chosen staff.
- **Narrowing hours with bookings inside shows exactly which bookings are affected** and offers
  leave / reschedule / cancel as an explicit choice — never a side effect
  ([05](05-availability-and-scheduling.md) §5.4).

A **live availability preview** sits beside the editor: change the hours, see next week's
bookable times change. Availability is derived from five inputs
([05](05-availability-and-scheduling.md) §5.2), and without a preview an operator cannot tell
which one is closing their diary.

### Delete, duplicate, and the two current defects

- **Delete exists** (**A21**): soft-deletes the appointment, destroys no bookings
  ([04](04-database-schema.md) §4.2), confirms with the future-booking count, and requires typing
  the name when bookings exist.
- **Duplicate copies configuration only** — services, staff, resources, schedules, exceptions,
  settings. **Never bookings** (**A20**). Cloning real customers' names, emails and phone
  numbers into a second record is a privacy defect, not a feature.

## 12.7 Share

Publishing should produce something usable immediately: the hosted booking page link, the inline
and iframe embed snippets, per-service direct links, the "Book now" button, and a QR code
([11](11-public-surface-and-widget.md) §11.5) — each with a copy button and a live preview.

This is what the current `exportWidget()` gestures at, and it is where **A26** is fixed: the
generator cannot produce a widget without an endpoint.

## 12.8 Roles and what the admin cannot do

### Roles

| Role | Can |
|---|---|
| **Owner / admin** | Everything |
| **Staff** | Their **own** diary, their bookings, their availability and exceptions — via `portal_user_id` ([04](04-database-schema.md) §4.4) |
| **Receptionist** | Book, reschedule, cancel across all staff; no configuration, no reports, no refunds |
| **Viewer** | Read-only; no exports of personal data |

A practitioner seeing only their own diary is the first thing a two-person clinic asks for, and
a receptionist who can take a booking but not issue a refund is the second.

### Not offered

| | Why |
|---|---|
| Delete a booking | Cancel or purge. Deleting destroys the audit trail and the refund obligation |
| Edit a past booking's price | The booking records what happened; corrections are refunds |
| Delete a staff member with future bookings | Deactivate ([07](07-services-staff-and-resources.md) §7.3) |
| Edit capacity counters directly | They are claimed and reconciled ([06](06-booking-engine.md) §6.3). A hand-edited counter is a double-booking |
| Edit a customer's answers | Amend with a record, never in place |
| See a calendar token or a provider key | Never in a response |
| Book past capacity without flagging it | The override is explicit and logged (§12.5) |

## 12.9 What happens to the three controllers

```
controllers/appointments/
  api.ts                    the ADMIN client → /portal/api/v1/appointments/*
  views/
    overview.ts             §12.3
    calendar.ts             §12.4
    daySheet.ts             §12.4
    bookings.ts · bookingDetail.ts
    config/
      services.ts · staff.ts · resources.ts
      schedules.ts · exceptions.ts · waitlist.ts
      communications.ts · share.ts · settings.ts
    customers.ts · reports.ts
  shared/
    datetime.ts             zone-aware formatting — the ONLY date path   05 §5.6
    availabilityPreview.ts  §12.6
    escape.ts               one implementation                            A23
```

Rules for the rewrite:

- **One mutation per request** (**A16**). `handleSlotSubmit`, `toggleSlotActive` and
  `confirmDeleteSlot` each send **one** service and check the response.
- **No fire-and-forget writes** (**A15**). Every save awaits, surfaces errors, and shows a
  pending state. A failed save must never look like a successful one.
- **The server generates every reference** (**A18**, **A19**).
- **`localStorage` is not a data store.** It survives only as crash recovery for an in-progress
  edit, keyed by reference. The dead `SLOTS_STORAGE_KEY` / `BOOKINGS_STORAGE_KEY` constants go
  (**A25**).
- **One `escapeHtml`**, shared (**A23**), and no record interpolated into `innerHTML` — DOM
  construction with `textContent`.
- **No inline `onclick`, no `window` globals** (**A24**). Delegated listeners with `data-`
  attributes.
- **All dates through `shared/datetime.ts`** (**A32**). No `new Date(dateString)` on a bare date,
  no string concatenation — enforced by a lint rule
  ([14](14-rollout-and-testing.md) §14.6).
- **The preview renders the real public component** ([11](11-public-surface-and-widget.md) §11.5),
  not a second implementation.
- **Loading, empty, error and permission states on every view.**
- The Response view's cancel returns to appointments (**A22**).

The lazy view-registration pattern is good and every new view follows it — including destroying
its state on teardown.

## 12.10 Acceptance

- Editing one service sends one service, and a failed save shows an error (**A15**, **A16**).
- Nothing in the appointments admin treats `localStorage` as a source of truth.
- Duplicating an appointment copies configuration and **no bookings** (**A20**).
- An appointment can be deleted from the UI; it soft-deletes and destroys no bookings (**A21**,
  **A4**).
- The calendar shows day, week, month and staff columns, with buffers and synced busy blocks
  visible.
- Dragging a booking runs the reschedule transaction and confirms first.
- The day sheet prints one practitioner's day correctly.
- A manual booking honours capacity, buffers and resources; an override is flagged and logged.
- The bookings list pages, filters and searches; the "5 upcoming" cap is gone.
- The booking timeline shows every message sent, including failures.
- The schedule editor is a weekly grid with copy-to-day and copy-to-staff.
- Narrowing hours lists affected bookings and changes none of them without an explicit choice.
- The availability preview updates as the schedule changes.
- Public holidays import in one action.
- A staff user sees only their own diary; a receptionist cannot refund.
- No date in the module is produced by string concatenation or `new Date(bareDateString)`.
- One `escapeHtml` implementation; no inline `onclick`; no `window` globals.
- The Response view's cancel returns to appointments (**A22**).
- Every view has a loading, empty and error state.
