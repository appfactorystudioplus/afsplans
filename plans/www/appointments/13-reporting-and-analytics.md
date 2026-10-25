# 13 — Reporting and Analytics

## 13.1 What exists

Nothing. `WwwAppointmentsProvider` reports a row count, the admin shows five upcoming bookings
(`appointments.ts:338`), and every booking is `pending` forever (**A8**) — so even
"how many appointments happened last month" is unanswerable.

## 13.2 What the numbers are built on

Every figure comes from the tables in [04](04-database-schema.md) — bookings, activity, credits —
never from a parallel statistics store.

| Rule | |
|---|---|
| **Bookings are the truth; counters are a cache** | ([06](06-booking-engine.md) §6.3) |
| **Money comes from what was charged**, not from current prices | Repricing a service must not restate last month's revenue |
| **Completion state comes from `status`** | Which is why the `completed` / `no_show` job matters ([08](08-booking-lifecycle.md) §8.2) |
| **Everything is scoped by appointment, staff and service** | And by date range, in the appointment's zone |
| **Cancelled bookings are counted, not hidden** | A cancellation rate is a metric, not an absence |

## 13.3 The operational numbers

| Metric | Why it matters |
|---|---|
| Bookings by period, service, staff, source | The base count |
| **Utilisation** — booked minutes ÷ available minutes | The number that says whether to hire, and the one only an availability engine can compute ([05](05-availability-and-scheduling.md) §5.2) |
| **No-show rate**, by service and by staff | Directly costs money and is directly reducible by reminders ([10](10-communications-and-calendar.md) §10.5) |
| **Cancellation rate**, and how late | Late cancellations are near-no-shows and should be seen as such |
| **Reschedule rate** | A high rate usually means the availability offered is wrong |
| Lead time distribution | How far ahead people book — drives how far ahead to open |
| **Gaps** — unbookable fragments between bookings | Buffers and durations that do not tile leave dead diary time; this is where a tenant recovers capacity |
| Booking → attendance funnel | Started → held → booked → attended, with drop-off |
| Peak demand | By day and hour, against staffed hours |
| Waitlist depth and conversion | Whether the waitlist is working |
| Time to first available | If it is three weeks, availability is the constraint |

**Utilisation and gap analysis are the two a spreadsheet cannot produce**, because both need the
availability computation. They are the reporting payoff for
[05](05-availability-and-scheduling.md)'s design.

## 13.4 Money

| Metric | |
|---|---|
| Revenue by service, staff, period | From what was charged |
| Deposits taken and outstanding balances | ([09](09-payments-and-policies.md) §9.2) |
| Refunds, with reasons | |
| Late-cancellation and no-show fees charged | And how often waived — the honest number |
| Package sales, redemptions, **unredeemed balances** | An unredeemed balance is a liability, not revenue |
| Average booking value | And revenue per available hour, which is the one that compares services fairly |

**Revenue per available hour** is the metric that tells a tenant a cheap 30-minute service
out-earns an expensive 90-minute one. It needs both money and availability, so again only this
module can compute it.

## 13.5 Customers

A customer view, assembled from bookings by email or `site_user_id`
([02](02-site-users-guard.md) §2.3) — not a CRM ([00](00-overview.md) §0.3):

- Booking history, upcoming and past.
- **No-show and late-cancellation count** — visible on the booking detail
  ([12](12-admin-dashboard.md) §12.5), because it changes how an operator handles a request.
- Lifetime value and visit frequency.
- Preferred staff member and service.
- **Lapsed customers** — no booking in N months, which is the list a tenant actually acts on.
- New vs returning split.
- Credit balances ([09](09-payments-and-policies.md) §9.6).

Assembling by email is the guest case and is weaker than an account: two spellings are two
customers. The admin offers a **merge** action, audited.

## 13.6 Exports

| Export | Contents |
|---|---|
| **Bookings** | Date, time, customer, service, staff, resource, status, payment, source, answers |
| **Day sheet** | Printable, per staff, per day ([12](12-admin-dashboard.md) §12.4) |
| **Financial** | Charges, refunds, fees, package sales, suitable for reconciliation |
| **Customers** | Contact details, history, totals |
| **Utilisation** | Per staff, per service, per period |
| **No-shows** | For follow-up or policy enforcement |

Mechanics, taken from what forms had to learn
([forms/10](../forms/10-submissions-and-data.md) §10.6):

- **CSV as UTF-8 with a BOM** by default, or Excel mangles every accented name.
- XLSX with typed cells and a metadata sheet; JSON for developers.
- Custom answers come from `custom_fields` — the mirror — so an export does not resolve a form
  version per row ([08](08-booking-lifecycle.md) §8.3).
- Cross-version answers export as the union of questions, with "not asked" distinguishable from
  "not answered".
- Filters from the list view carry into the export.
- Queued for large exports, delivered as an expiring link.
- **Every export writes an activity entry** — who, what, how many rows.

## 13.7 Privacy and retention

Appointments hold personal data with unusual sensitivity: names, emails, phones, free-text notes,
and — in clinical, legal or financial settings — whatever the intake form asked.

| Control | |
|---|---|
| **Role-gated exports** | A viewer cannot export personal data ([12](12-admin-dashboard.md) §12.8) |
| **Special-category answers flagged** | Health and accessibility answers marked in the form attachment; excluded from general exports by default ([08](08-booking-lifecycle.md) §8.3) |
| **Internal notes never leave** | Not in exports, not in any customer-facing surface, not in webhooks |
| **Retention policy** | Purge personal data N days after an appointment, keeping aggregate figures |
| **Erasure** | Purge one customer across every appointment, including form submissions, uploaded files and **their events in synced calendars** |
| **Aggregates survive** | "142 appointments in March" outlives the 142 names |
| **Calendar titles not imported** | Unless explicitly enabled ([10](10-communications-and-calendar.md) §10.6) |
| **Access logged** | Exports and bulk views recorded |

Two rows are specific to this module and easy to miss. **Erasure must reach the synced
calendar** — a deleted customer whose name remains in a practitioner's Google Calendar is not
erased. And **internal notes must never appear in a webhook payload**, which is the accidental
route by which "difficult customer, do not rebook" reaches a third-party system.

## 13.8 afs-connect

`WwwAppointmentsProvider` stops reporting a row count:

```php
summary() → [
    'appointments_count', 'services_count', 'staff_count',
    'bookings_upcoming', 'bookings_30d', 'utilisation_30d',
    'no_show_rate_90d', 'revenue_cents_30d', 'currency',
]
```

`changes()` emits real events — `booking.created`, `booking.cancelled`, `booking.completed`,
`booking.no_show` ([10](10-communications-and-calendar.md) §10.8) — rather than a paged dump
ordered by `updated_at`.

Ingest failures are logged and retried and **never affect a booking**.

## 13.9 Acceptance

- Revenue comes from what was charged; repricing a service does not restate history.
- Utilisation is computed against **available** minutes, not against a fixed working day.
- No-show rate is reported per service and per staff member.
- Gap analysis identifies unbookable fragments and quantifies the lost time.
- Revenue per available hour is reported per service.
- A customer's no-show count is visible on their booking detail.
- Lapsed customers are listable.
- Customer merge is available and audited.
- Booking CSV opens in Excel with accented names intact.
- An export across two form versions distinguishes "not asked" from "not answered".
- Internal notes appear in **no** export and **no** webhook payload.
- Special-category answers are excluded from general exports by default.
- Every export is recorded with actor, scope and row count.
- A viewer role cannot export personal data.
- Purging a customer removes their bookings, answers, files **and their events from synced
  calendars**, and leaves aggregate counts unchanged.
- `WwwAppointmentsProvider::summary()` reports bookings, utilisation and no-show rate.
