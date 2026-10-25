# 13 — Reporting and Analytics

Closes **V25**, and the reporting half of **V14**.

## 13.1 What exists

Nothing. `WwwEventsProvider::isStub()` returns `true` and `summary()` reports `events_count` — a
count of rows, which cannot distinguish a site with 40 events and no registrations from one with
40 events and 4,000 attendees.

## 13.2 What the numbers are built on

Every figure here comes from tables that already exist in
[04](04-database-schema.md) — orders, registrations, check-ins, activity — rather than from a
parallel statistics store.

| Rule | |
|---|---|
| **Registrations are the truth; counters are a cache** | ([06](06-ticketing-and-capacity.md) §6.5). Reports read the rows |
| **Money comes from orders**, never from ticket prices | A price changed in June must not restate March's revenue |
| **Everything is scoped by occurrence** | An event-level total is a sum of occurrences, and is labelled as one |
| **Attendance counts distinct registrations** | A duplicate offline scan inflates nothing ([09](09-checkin-and-onsite.md) §9.6) |
| **Comps are separated from free** | A £0 allocation is not the same as a free event ([06](06-ticketing-and-capacity.md) §6.7) |

## 13.3 Sales and revenue

Per occurrence, per event, per site, over a date range:

| Metric | |
|---|---|
| Registrations | Total, by status, by ticket type, by tier |
| Gross / net revenue | Net after refunds and fees |
| Refunds | Count and value, with reasons |
| Discounts | Value given, by promo code |
| Fees and tax | Separated — a tenant's net is not their gross |
| Comps and allocations | Count and notional value, **excluded from revenue** |
| Average order value | And tickets per order |
| Sales over time | Cumulative against capacity, which is the shape an organiser reads |
| **Sales by tier** | Did Early Bird sell out, and when |
| Conversion | Views → holds → orders → paid ([11](11-public-surface-and-sdk.md)) |
| **Abandonment** | Holds created and never converted — the number that says whether checkout is working |

The cumulative-sales-against-capacity curve is the single most useful chart an events product
has: it answers "will this sell out, and should I promote it" while there is still time to act.

## 13.4 Attendance

| Metric | |
|---|---|
| Checked in / registered | The attendance rate |
| **No-shows** | Registered, never scanned — and for a free event this is often 30–50 %, which changes catering and room booking |
| Walk-ins | Registered on the day |
| Arrival profile | Check-ins over time; how long the door queue lasted |
| Per-session attendance | Registered vs attended per session ([09](09-checkin-and-onsite.md) §9.7) |
| Utilisation | Attended / capacity, and registered / capacity |
| Conflicts and overrides | ([09](09-checkin-and-onsite.md) §9.6) |
| Repeat attendance | Across a series, by email or account |

No-show rate is the metric a tenant with a free event most needs and least often has: it is what
turns "150 registered" into "expect 95" for the caterer.

## 13.5 Attendee data and exports

The reason most of this data was collected.

| Export | Contents |
|---|---|
| **Attendee list** | Name, email, ticket type, status, checked in, session choices, **custom answers** |
| **Order list** | Purchaser, totals, discounts, payment references |
| **Check-in list** | Times, devices, methods, conflicts |
| **Waitlist** | Positions, offer states |
| **Financial** | Order lines suitable for reconciliation |
| **Badge data** | Formatted for a print house |
| **Catering** | Dietary answers, aggregated and per attendee |

Mechanics, taken from what forms had to learn
([forms/10](../forms/10-submissions-and-data.md) §10.6):

- **CSV as UTF-8 with a BOM** by default — without it Excel mangles every accented name, and it
  is the most common export complaint any product receives.
- XLSX with typed cells and a metadata sheet; JSON for developers.
- Custom answers come from `registrations.custom_fields` — the mirror — so an export does not
  resolve a form version per row ([07](07-registration-and-attendees.md) §7.4).
- **Cross-version answers**: registrations answered against different form versions export as
  the union of questions, with "not asked" distinguishable from "not answered".
- Filters from the list view carry into the export.
- **Queued for large exports**, delivered as an expiring link.
- **Every export writes an activity entry** — who, what, how many rows. Bulk extraction of
  attendee personal data should leave a trace.

## 13.6 The organiser's dashboards

Two, for two moments:

**Before** ([12](12-admin-dashboard.md) §12.3) — sold against capacity, days remaining, sales
velocity, waitlist depth, approvals pending, revenue to date.

**During** ([09](09-checkin-and-onsite.md) §9.10) — checked in against registered, arrival rate,
per-session fill, devices connected.

**After** — attendance, no-shows, revenue, refunds, per-session popularity, feedback response
rate ([10](10-communications.md) §10.6), and a comparison against the previous occurrence in the
series. The series comparison is what makes a recurring event improvable rather than merely
repeated.

## 13.7 afs-connect (V25)

`WwwEventsProvider` stops being a stub:

```php
summary() → [
    'events_count', 'occurrences_upcoming', 'registrations_total',
    'registrations_30d', 'revenue_cents_30d', 'currency',
    'attendance_rate_90d', 'stub' => false,
]
```

`changes()` emits real events — `event.published`, `occurrence.created`,
`registration.created`, `order.paid`, `checkin.recorded`, `occurrence.cancelled` — rather than a
paged dump of the events table ordered by `updated_at`
([10](10-communications.md) §10.8).

Ingest failures are logged and retried and **never affect a registration**.

## 13.8 Privacy and retention

An events module holds more personal data than any other in this ecosystem — names, emails,
phones, dietary and accessibility requirements, attendance records — and dietary and
accessibility answers are special-category data in several jurisdictions.

| Control | |
|---|---|
| **Role-gated exports** | A viewer cannot export personal data ([12](12-admin-dashboard.md) §12.8) |
| **PII columns are opt-in** | IP and user agent excluded from exports by default |
| **Retention policy** | Purge attendee personal data N days after an occurrence, keeping aggregate figures |
| **Erasure** | Purge one attendee across every event — including their form submissions and any uploaded files ([forms/02](../forms/02-security-and-tenancy.md) §2.6) |
| **Aggregates survive** | "42 attended" outlives the 42 names, so history is not destroyed by a deletion request |
| **Access logged** | Exports and bulk views recorded in the activity log |
| **Special-category answers flagged** | Dietary and accessibility questions are marked in the forms attachment so exports can exclude them by default |

The last row is the one that is usually missed: a catering export legitimately needs dietary
answers, and a generic attendee export does not — and shipping every answer in every export by
default is how that data ends up in a shared spreadsheet.

## 13.9 Acceptance

- Revenue comes from orders, and repricing a ticket type does not restate historical revenue.
- Comps are excluded from revenue and counted separately.
- Attendance counts distinct registrations; duplicate offline scans do not inflate it.
- No-show rate is reported per occurrence.
- Sales-against-capacity over time renders for any occurrence.
- Hold abandonment is reported.
- Per-session registered vs attended is reported.
- Attendee CSV opens in Excel with accented names intact.
- An export across two form versions distinguishes "not asked" from "not answered".
- Every export is recorded with actor, scope and row count.
- A viewer role cannot export personal data.
- Purging an attendee removes their registrations, answers and files, and leaves the occurrence's
  attendance count unchanged.
- `WwwEventsProvider::isStub()` returns `false` and `summary()` reports registrations, revenue
  and attendance.
- Series comparison shows this occurrence against the previous one.
