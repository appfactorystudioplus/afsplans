# 10 — Communications and Calendar

Closes **V22**.

## 10.1 What exists

`initEventResponseView()` wires `EmailConstructionBuilder` and saves mail handlers under
`ViewsCategories.EVENTS` (`events.ts:681–707`).

Nothing reads them, because there is no registration to send on. It is the same shape as
[forms **F57**](../forms/01-current-state-audit.md): a configuration screen whose output no
server-side code consumes.

Events therefore inherits the rule forms had to introduce — and, being new, gets it right the
first time.

## 10.2 Everything is sent by the server

> **Nothing that matters happens in the registrant's browser.**

[forms **F9**](../forms/01-current-state-audit.md) is the cautionary case: the forms renderer
calls `mailHandlerApi.send()` from the respondent's browser with a browser-chosen recipient, so
closing the tab loses the email and a `curl` relays mail through the tenant's provider.

Events never does this. All effects run in a queued job:

```
order paid  ──►  ProcessEventRegistration (queued)
                  ├── attendee confirmation + ticket + ICS      §10.3, §10.4
                  ├── purchaser receipt (if different)
                  ├── organiser notification                    §10.7
                  ├── afs-connect ingest                        §10.8
                  ├── analytics                                 §10.8
                  └── webhooks                                  §10.9
```

| Rule | |
|---|---|
| The order is **committed** before the job runs | A failing email never costs a registration |
| Each effect retries independently with backoff | A dead webhook does not block the ticket |
| Each attempt writes to `laravel_event_activity` | [04](04-database-schema.md) §4.13 |
| Each effect is **idempotent** on the order reference | A retried job does not send a second ticket |
| Recipients come from the **record**, never the payload | The address on the registration, and the mail-handler configuration |

## 10.3 The messages

| Message | Trigger | To |
|---|---|---|
| **Confirmation + ticket** | Registration confirmed | Attendee |
| **Receipt** | Order paid | Purchaser, when different |
| **Assign-your-ticket** | Unassigned registration ([07](07-registration-and-attendees.md) §7.2) | Purchaser |
| **Claim link** | Unassigned place distributed | Recipient |
| **Approval decision** | Approved or rejected ([07](07-registration-and-attendees.md) §7.6) | Applicant |
| **Waitlist joined** | Added, with position | Entrant |
| **Waitlist offer** | Place freed ([07](07-registration-and-attendees.md) §7.7) | Entrant |
| **Waitlist offer expiring** | 2 hours left | Entrant |
| **Reminder** | Per `settings.notify.reminders` | Attendee |
| **Join link released** | Online events ([05](05-event-model-and-scheduling.md) §5.7) | Attendee |
| **Event changed** | Time, venue or date changed | All attendees |
| **Occurrence cancelled** | Cancellation ([08](08-payments-and-refunds.md) §8.8) | All attendees **and waitlist** |
| **Postponed** | Postponement | All attendees |
| **Refund issued** | Refund | Attendee |
| **Transfer confirmation** | Transfer completed | Both parties |
| **Follow-up** | N hours after the end | Attendees, optionally split by attended / no-show |
| **New registration** | Any registration | Organiser |
| **Nearly full / sold out** | Capacity thresholds | Organiser |

The **waitlist offer expiring** message is the one usually missing, and it is what converts a
waitlist: an offer that lapses silently at hour 24 loses a sale that a reminder at hour 22
would have made.

### Templates

Each message is a template with tokens resolved from the **snapshot**
([04](04-database-schema.md) §4.11.1):

```
{attendee_name} · {event_name} · {occurrence_start} · {occurrence_start_local}
{venue_name} · {venue_address} · {directions_url} · {ticket_type} · {amount}
{ticket_url} · {qr_image} · {calendar_url} · {online_url} · {my_events_url}
{sessions} · {organiser_name} · {refund_policy}
```

`{occurrence_start_local}` renders in the **attendee's** zone where known and the event's
otherwise ([05](05-event-model-and-scheduling.md) §5.5) — an online event's attendee in another
country needs their own time, and a physical event's attendee needs the venue's.

Templates are per event with tenant-level defaults, editable through the existing
`EmailConstructionBuilder`, and multi-language where the event is
([forms/08](../forms/08-multi-language.md)).

**The join link is never in the confirmation by default**
([05](05-event-model-and-scheduling.md) §5.7).

## 10.4 Calendar

A ticket that cannot be added to a calendar produces a no-show.

| Delivery | |
|---|---|
| **ICS attachment** on the confirmation | The universal path |
| **Add to calendar** links | Google, Outlook, Apple, plus an ICS download |
| Attendee page | Same links ([07](07-registration-and-attendees.md) §7.9) |
| Subscription feed | An ICS feed URL per event, so a series appears and updates in a subscriber's calendar |

The ICS must be correct, which is more than emitting a start and end:

- `DTSTART;TZID=Europe/London` with a real `VTIMEZONE` block — **not** a floating local time and
  not a bare UTC stamp, so the event survives a DST change in the recipient's calendar.
- A stable `UID` per registration, so a re-send **updates** the calendar entry rather than
  creating a second.
- `SEQUENCE` incremented on every change, which is what makes an update actually apply.
- `METHOD:CANCEL` on cancellation, so the entry disappears from the attendee's calendar.
- `LOCATION` with the full address, `URL` with the event page, `DESCRIPTION` with the join link
  once released.
- Recurring series: **individual events**, not an `RRULE` — occurrences diverge
  ([05](05-event-model-and-scheduling.md) §5.3), and a recurrence rule in a calendar entry cannot
  express a cancelled 23 December.

## 10.5 Change notifications

An event whose time or venue changes must reach everyone holding a ticket.

| Change | Notification |
|---|---|
| Time or date | **Mandatory**, with the old and new times, plus an updated ICS (`SEQUENCE` bumped) |
| Venue | Mandatory, with the new address and directions |
| Cancelled | Mandatory, to attendees **and the waitlist**, with refund information |
| Postponed | Mandatory, stating that the registration stands |
| Online link changed | Mandatory to attendees, once released |
| Description or images | Not notified |

Changing a time or venue on an occurrence with registrations **requires confirmation naming the
count** ([05](05-event-model-and-scheduling.md) §5.3), and the notification is part of the
action, not a separate step an organiser might forget. The organiser may add a note explaining
the change, which is the difference between a helpful message and an alarming one.

## 10.6 Reminders and follow-ups

`settings.notify.reminders` — a list of offsets, default 7 days and 24 hours before.

| Rule | |
|---|---|
| Scheduled from `starts_at`, in UTC | Which is only possible because instants are stored (**V17**) |
| Rescheduled when the occurrence moves | A reminder for the old time is worse than none |
| Skipped for cancelled registrations | |
| Include the ticket, the venue, and the join link if released | A reminder is the message people actually act on |
| Deduplicated | One reminder per registration per offset, enforced by the activity log |

**Follow-up** at `followup_hours_after`: thanks, materials, a feedback form
([forms](../forms/README.md) again — a post-event survey is a form), and optionally a different
message for no-shows.

## 10.7 The organiser

Organisers need to be told things too, and drowning them is how they stop reading:

- New registration — immediately, or a **daily digest** for a busy event.
- Threshold alerts — 80 % full, sold out, first waitlist entry.
- Failed payments and reconciliation failures ([08](08-payments-and-refunds.md) §8.7).
- Daily summary in the week before: registrations, revenue, waitlist.
- Post-event summary: attendance, no-shows, revenue.

The digest is not a nicety. A conference receiving 300 registrations a day with per-registration
emails trains its organiser to filter the channel, and then they miss the payment failure.

## 10.8 afs-connect and analytics (V25)

`WwwEventsProvider::isStub()` returns `true` and reports only `events_count`.

Add dispatches for `event.published`, `occurrence.created`, `registration.created`,
`order.paid`, `checkin.recorded`, and a `summary()` reporting registrations, revenue and
attendance — so a site with 40 events and no registrations is distinguishable from one with 40
events and 4,000 attendees.

Analytics events are emitted **server-side** — `event.viewed` is a page view and stays
client-side, but `registration.completed` is a stored order and must not be inflatable by the
page hosting the widget
([forms/11](../forms/11-integrations-and-notifications.md) §11.7).

## 10.9 Webhooks

The seam that lets a tenant route registrations into a CRM without a per-integration build.

Same rules as [forms/11](../forms/11-integrations-and-notifications.md) §11.5, and they are not
restated at length: **HMAC-signed** with a timestamp, the secret held as a reference and never
returned by an admin endpoint, exponential backoff then disable-with-notification, deliveries
logged and replayable, `https` only with private ranges refused and DNS re-resolved at send time.

Events: `registration.created` · `registration.cancelled` · `registration.transferred` ·
`order.paid` · `order.refunded` · `checkin.recorded` · `occurrence.cancelled` ·
`waitlist.promoted`.

## 10.10 Acceptance

- Closing the tab immediately after paying still delivers the confirmation, ticket and ICS.
- A `curl` cannot cause mail to be sent to an address it names.
- A retried job sends exactly one confirmation.
- A failed send retries, then appears on the registration's activity timeline with its error —
  and the registration is unaffected.
- The ICS opens correctly in Google Calendar, Outlook and Apple Calendar, at the right local
  time, either side of a DST boundary.
- Re-sending a confirmation **updates** the existing calendar entry rather than creating a
  second.
- Cancelling an occurrence removes the entry from an attendee's calendar.
- Changing an occurrence's time reschedules its reminders and notifies every attendee with old
  and new times.
- A reminder is never sent for a cancelled registration, and never twice.
- The join link is absent from the confirmation and present in the release message and on the
  attendee page.
- A waitlist offer is followed by an expiry warning before it lapses.
- An organiser digest replaces per-registration emails when enabled.
- afs-connect receives registration and check-in events, and `summary()` reports more than a row
  count.
- `analytics.registration.completed` is emitted by the server.
- A webhook is signed, retried, logged and replayable; `http://` and private addresses are
  refused.
