# 10 — Communications and Calendar

Closes **A30**, and adds the capability that most changes a scheduling product's value: knowing
what the practitioner's own calendar already says.

## 10.1 What exists

`initAppointmentsResponseView()` wires `EmailConstructionBuilder` and saves mail handlers under
`ViewsCategories.APPOINTMENTS` (`AppointmentsController.ts:252–292`).

**No server-side code reads them. No email is sent when a booking is made** (**A30**). The same
shape as [forms **F57**](../forms/01-current-state-audit.md) and
[events **V22**](../events/01-current-state-audit.md): a configuration screen wired to nothing.

For an appointments module this is more consequential than elsewhere — a booking with no
confirmation is a booking the customer is not sure they made, and a no-show rate that a reminder
would have halved.

(The same view's cancel handler navigates to the **events** module — **A22**.)

## 10.2 Everything is sent by the server

> **Nothing that matters happens in the customer's browser.**

[forms **F9**](../forms/01-current-state-audit.md) is the cautionary case: a browser-composed
recipient sent through the tenant's mail provider. Appointments never does this.

```
booking confirmed  ──►  ProcessAppointmentBooking (queued)
                         ├── customer confirmation + ICS      §10.3, §10.4
                         ├── staff notification               §10.7
                         ├── calendar push                    §10.6
                         ├── reminders scheduled              §10.5
                         ├── afs-connect ingest               §10.8
                         └── webhooks                         §10.9
```

| Rule | |
|---|---|
| The booking is **committed** before the job runs | A failing email never costs an appointment |
| Each effect retries independently with backoff | A dead calendar sync does not block the confirmation |
| Each attempt writes to `laravel_appointment_activity` | [04](04-database-schema.md) §4.11 |
| Each effect is **idempotent** on the booking reference | A retried job does not send a second confirmation |
| Recipients come from the **record** | `user_email` on the booking, and the mail-handler configuration |

## 10.3 The messages

| Message | Trigger | To |
|---|---|---|
| **Confirmation + ICS** | Booking confirmed | Customer |
| **Awaiting approval** | Approval-required booking created | Customer |
| **Approved / rejected** | Approval decision ([08](08-booking-lifecycle.md) §8.4) | Customer |
| **Payment required** | Deposit or prepayment outstanding | Customer |
| **Reminder** | Per `settings.notify.reminders` | Customer |
| **Join link released** | Online services ([07](07-services-staff-and-resources.md) §7.7) | Customer |
| **Rescheduled** | By either party, with an **updated ICS** | Both |
| **Cancelled** | By either party | Both |
| **Waitlist offer** | A matching time freed ([08](08-booking-lifecycle.md) §8.8) | Entrant |
| **Waitlist offer expiring** | 1 hour left | Entrant |
| **Follow-up** | N hours after the end | Customer |
| **No-show notice** | Marked no-show, where the policy says so | Customer |
| **Credit expiring** | Before a package lapses ([09](09-payments-and-policies.md) §9.6) | Customer |
| **New booking** | Any booking | Staff member and/or admin |
| **Daily schedule** | Each morning | Staff member |

The **daily schedule** email is the one practitioners actually want, and it costs one scheduled
job: today's bookings, times, names, service, location, notes, and anything flagged.

### Templates

Tokens resolved from the booking's **snapshot** ([04](04-database-schema.md) §4.9.1):

```
{customer_name} · {service_name} · {staff_name} · {starts_at_local} · {starts_at_customer}
{duration} · {location_name} · {location_address} · {directions_url} · {online_url}
{price} · {deposit} · {balance_due} · {cancel_policy}
{manage_url} · {reschedule_url} · {cancel_url} · {calendar_url}
```

`{starts_at_customer}` renders in the customer's captured zone
([05](05-availability-and-scheduling.md) §5.6) — the reason `customer_timezone` is on the
booking.

`{manage_url}` carries the `access_token` ([02](02-site-users-guard.md) §2.5) and is what makes
self-service possible for guests.

Templates are per appointment with tenant defaults, editable through the existing
`EmailConstructionBuilder` — **now actually read by the server**.

### SMS

Appointments is the module where SMS reminders genuinely reduce no-shows. The message set above
is transport-agnostic; SMS is a delivery channel behind the same job, gated on a provider being
configured. Not built in the first pass, and the templates are written so it does not require
rework.

## 10.4 Calendar files

A confirmed appointment that is not in the customer's calendar is a no-show waiting to happen.

| Delivery | |
|---|---|
| **ICS attachment** on the confirmation | The universal path |
| **Add to calendar** links | Google, Outlook, Apple, plus an ICS download |
| Booking page | The same links ([08](08-booking-lifecycle.md) §8.9) |

Correctness, which is more than emitting a start and an end:

- `DTSTART;TZID=Europe/London` with a real `VTIMEZONE` block — not a floating local time, not a
  bare UTC stamp, so the entry survives a DST change in the recipient's calendar.
- A **stable `UID` per booking**, so a re-send updates the entry rather than creating a second.
- `SEQUENCE` incremented on every change — this is what makes a reschedule actually move the
  entry.
- `METHOD:CANCEL` on cancellation, so it disappears.
- `LOCATION` with the full address; `URL` with the manage link; `DESCRIPTION` with the join link
  once released.
- Recurring series: **individual entries**, not an `RRULE` — occurrences diverge
  ([08](08-booking-lifecycle.md) §8.7).

## 10.5 Reminders

`settings.notify.reminders` — a list of offsets, default 24 hours and 2 hours before.

| Rule | |
|---|---|
| Scheduled from `starts_at`, in UTC | Only possible because instants are stored (**A12**) |
| **Rescheduled when the booking moves** | A reminder for the old time is worse than no reminder |
| Cancelled when the booking is | |
| Deduplicated | One per booking per offset, enforced by the activity log |
| Include the manage link | So the reminder is also the cancellation path — which is how a reminder *prevents* a no-show rather than merely predicting one |
| Quiet hours | No SMS or push between configured hours in the **customer's** zone |

The 2-hour reminder with a one-tap cancel link is the single highest-value message in the set:
it converts silent no-shows into cancellations that free the time.

## 10.6 Two-way calendar sync

The decision that most changes what the product is worth ([00](00-overview.md) §0.5 D5). A
practitioner who blocks two hours in their own calendar must not be bookable in those two hours,
and they will not maintain two calendars.

### Outbound — push

Confirmed bookings become events in the staff member's connected calendar: title, time, location,
customer name, and the manage link. Reschedules update; cancellations delete. Idempotent on
`external_id`.

### Inbound — pull

Busy blocks are imported into `laravel_appointment_busy_blocks`
([04](04-database-schema.md) §4.8) and **subtracted from availability**
([05](05-availability-and-scheduling.md) §5.2).

| Rule | |
|---|---|
| **Free/busy only by default** | `import_titles` is off. Importing a practitioner's private calendar titles into the tenant's database is a privacy decision, not a convenience |
| Only `busy` events | Transparent and free events are ignored |
| Which calendars | The staff member chooses; more than one may be selected |
| Refresh | Webhook or push notification where the provider supports it; polling otherwise |
| Replaceable | A sync run rewrites the blocks and **never overwrites an exception a human entered** ([04](04-database-schema.md) §4.8) |
| Loop prevention | Our own pushed events are ignored on the way back in, by `external_id` |

### Failure posture

**Availability degrades loudly, never silently.**

If sync is stale or failing, the module keeps working from in-product schedules — and flags the
degradation to the tenant, because the failure mode is offering a time the practitioner has
already committed. `settings.sync` may additionally set a **conservative mode**: when the last
successful sync is older than a threshold, stop offering times for that staff member rather than
offering possibly-taken ones.

Which of those a tenant wants depends on their business, so it is a setting with the trade-off
stated, not a default chosen for them.

### Connection management

OAuth per staff member, tokens held by the platform's credential store and **never returned by
any admin endpoint**. Disconnecting removes the busy blocks and stops the pushes. An expired
connection is surfaced on the admin overview ([12](12-admin-dashboard.md) §12.3), because a
silently expired token is the same failure as no sync at all.

## 10.7 Staff and operator notifications

- New booking — immediately, or a **daily digest** for a busy diary.
- Cancellation or reschedule — immediately; it changes their day.
- Daily schedule each morning (§10.3).
- Weekly summary: bookings, cancellations, no-shows, utilisation.
- Failures — payments, reconciliation, calendar sync, undelivered mail.

The digest is not a nicety: a clinic taking 40 bookings a day with per-booking emails trains its
staff to filter the channel, and then they miss the cancellation.

## 10.8 afs-connect

`WwwAppointmentsProvider` gains real events — `booking.created`, `booking.cancelled`,
`booking.completed`, `booking.no_show` — and a `summary()` reporting upcoming bookings,
utilisation and no-show rate rather than a row count.

Ingest failures are logged and retried and **never affect a booking**.

## 10.9 Webhooks

Same rules as [forms/11](../forms/11-integrations-and-notifications.md) §11.5 and
[events/10](../events/10-communications.md) §10.9, not restated at length: **HMAC-signed** with a
timestamp, secret held as a reference and never returned, exponential backoff then
disable-with-notification, deliveries logged and replayable, `https` only with private ranges
refused and DNS re-resolved at send time.

Events: `booking.created` · `booking.confirmed` · `booking.rescheduled` · `booking.cancelled` ·
`booking.completed` · `booking.no_show` · `waitlist.offered` · `payment.captured`.

## 10.10 Acceptance

- A booking sends a confirmation with a working ICS — **which it does not today** (**A30**).
- Closing the tab immediately after booking still sends everything.
- A `curl` cannot cause mail to be sent to an address it names.
- A retried job sends exactly one confirmation.
- A failed send retries, then appears on the booking's activity timeline with its error — and
  the booking is unaffected.
- The ICS opens correctly in Google, Outlook and Apple Calendar, at the right local time, either
  side of a DST boundary.
- Re-sending a confirmation **updates** the calendar entry rather than creating a second.
- Rescheduling moves the entry; cancelling removes it.
- Reminders are rescheduled when a booking moves, cancelled when it is cancelled, and never sent
  twice.
- A reminder contains a working one-tap cancel link.
- An online service's join link is absent from the confirmation and released before the start.
- A busy block in a staff member's connected calendar removes that time from availability.
- Event titles are **not** imported unless `import_titles` is explicitly enabled.
- Our own pushed bookings are not re-imported as busy blocks.
- A stale or failing sync is surfaced to the tenant, and conservative mode stops offering times
  when configured.
- Disconnecting a calendar removes its busy blocks.
- No admin endpoint returns a calendar token.
- The daily schedule email lists a staff member's day correctly, in their zone.
- The Response view's cancel returns to **appointments** (**A22**).
