# 05 — The Event Model and Scheduling

Closes **V13**, **V17**, **V18**, and the modelling half of **V3**.

## 5.1 What the current model says

```
name · title · description · startDate · startTime · endDate · endTime
totalTickets · ageRestriction · directPaymentReferenceNum · priceLevels[]
```

Four loose date/time strings with no zone (**V17**), one capacity bucket (**V14**), one date per
event, and no venue, no sessions, no recurrence. It describes a single-occurrence, single-room,
single-capacity event and nothing else — which is a reasonable first screen and a poor
foundation.

## 5.2 Event → occurrence

[03](03-architecture.md) §3.2 states the hierarchy; this is what it costs and buys.

**Every event has at least one occurrence.** A one-off event is an event with exactly one — there
is no separate simple path, because two paths diverge and the simple one grows the capacity bug.

| Lives on the **event** | Lives on the **occurrence** |
|---|---|
| Name, subtitle, description, images | Start, end, doors open |
| Category, tags, organiser | Venue (overriding the event default) |
| Default venue, default time zone | Capacity and counters |
| Direct Payment, default form | Ticket types, sessions |
| Site Users mode, settings | Registration window, waitlist, approval |
| Slug and SEO | Status — scheduled, cancelled, postponed |

The admin hides this for a one-off event: the create form shows one date pair and writes the
event and its single occurrence together ([12](12-admin-dashboard.md) §12.4). Making the
distinction visible only when a tenant asks for recurrence is what keeps a simple event simple
without making the schema lie.

## 5.3 Recurrence

### The rule

An event may carry a recurrence rule (RFC 5545 `RRULE` semantics, stored as structured JSON
rather than a raw string so the editor can round-trip it):

```json
{ "freq": "WEEKLY", "interval": 1, "byday": ["TU"],
  "start_time": "18:30", "duration_minutes": 60,
  "timezone": "Europe/London",
  "until": "2027-06-30", "count": null,
  "exceptions": ["2026-12-23", "2026-12-30"] }
```

Supported: daily, weekly (with weekdays), monthly (by day-of-month **or** by weekday ordinal —
"the third Thursday"), yearly, and every-N-of-those. Plus explicit exception dates.

### Rows, not rules

**The rule generates occurrence rows** ([00](00-overview.md) §0.6 D1). Computing occurrences on
read is elegant until one week is cancelled, one venue changes, or thirty people register for
the 14th — and then there is nowhere to put that.

| Concern | Handling |
|---|---|
| **Horizon** | Materialise 12 months ahead (configurable). A nightly job extends the window |
| **Unbounded rules** | "Every Tuesday forever" is legal; only the horizon is ever materialised |
| **Editing the series** | Offers *this occurrence* · *this and following* · *all* |
| **Divergence** | An individually edited occurrence records the changed fields in `overrides` ([04](04-database-schema.md) §4.3) |
| **Series edit vs divergence** | A later series edit **skips** fields listed in `overrides`, and reports how many occurrences it skipped and why |
| **Occurrences with registrations** | Never silently rescheduled. Changing their time requires confirmation naming the registrant count, and notifies everyone ([10](10-communications.md) §10.5) |
| **Deleting from the series** | Cancels rather than deletes when registrations exist. A cancelled occurrence keeps its registrations and its refund obligations |
| **Rule changed** | Future occurrences with no registrations are regenerated; those with registrations are kept and flagged for review |

That last row is the one that decides whether recurrence is usable. Regenerating everything is
easy and destroys bookings; regenerating nothing means the rule is decorative. Regenerating only
the empty future is the behaviour a tenant expects and rarely gets.

### Series-level ticket types

Defining ticket types per occurrence for a 52-week class is not viable. A ticket type may be
defined at the **event** level (`ticket_types.event_reference_num` with a null occurrence,
[04](04-database-schema.md) §4.6) and is then inherited by every occurrence, with per-occurrence
capacity tracked separately.

An occurrence may override a type — a bank-holiday class at a different price — and the override
records itself in `overrides` like any other divergence.

## 5.4 Multi-day events, sessions and tracks

An occurrence spanning 14–16 November is **one occurrence** with `starts_at` on the 14th and
`ends_at` on the 16th. Splitting a conference into three occurrences would mean three separate
registrations for one delegate.

Inside it, `laravel_event_sessions` ([04](04-database-schema.md) §4.4) carries the agenda.

| Session shape | `requires_registration` | Capacity |
|---|---|---|
| Keynote, break, lunch | `false` | none — informational agenda |
| Workshop, breakout, lab | `true` | its own, enforced like any other ([06](06-ticketing-and-capacity.md) §6.5) |

Sessions support:

- **Tracks** — parallel streams, rendered as columns in the agenda.
- **Nesting** — a session with a `parent_session_ref`, for a track containing talks.
- **Per-session rooms and venues** — a satellite workshop across the road.
- **Per-session online URLs** — a hybrid conference where one track is remote.
- **Clash detection** — the agenda editor warns when two registrable sessions in the same
  track overlap, and refuses to let one attendee register for two overlapping sessions.
- **Speaker assignment** — via `laravel_event_people` (§5.8).

Session choices are stored on the registration as `session_refs` and mirrored into
`laravel_event_registrations` so a badge and a personal agenda can be generated
([09](09-checkin-and-onsite.md) §9.8).

**Session registration is separate from occurrence registration**: a delegate registers for the
conference, then picks workshops. Trying to do both in one step is how a registration form
becomes twelve pages.

## 5.5 Time zones and DST

The module's largest correctness gap (**V17**), and the one that is invisible until it is not.

### The rules

1. **Every instant is stored UTC** in a `timestamp` column.
2. **Every occurrence carries an IANA zone** — `Europe/London`, never `GMT`, never `+01:00`. A
   fixed offset cannot express DST, which is the entire problem.
3. **Recurrence expands in the event's local time, then converts.** A weekly 18:30 class stays
   18:30 across the March and October transitions; the stored UTC instants shift by an hour, as
   they must.
4. **Display resolves through the occurrence's zone by default**, with an option to show the
   viewer's zone alongside — which is what an online event needs and a physical one does not.
5. **Registration windows, reminder scheduling and "is it over" all compare UTC instants**, never
   local strings.

### The cases to get right, and test

| Case | Expectation |
|---|---|
| Weekly 18:30 across the DST spring-forward | Every occurrence is 18:30 local; UTC shifts by an hour |
| An occurrence at 01:30 on a spring-forward night — a local time that **does not exist** | Rejected at authoring with an explanation, not silently shifted |
| An occurrence at 01:30 on an autumn night — a local time that happens **twice** | The editor asks which, and stores the answer |
| Attendee in New York, event in London | Shown London time by default, with "02:00 your time" beside it |
| An all-day event | `all_day = true`; no time is displayed and no zone conversion is applied |
| An event that crosses midnight | `ends_at` is the next day; duration is computed from instants |
| Tenant changes the event's zone after registrations exist | The stored instants **do not move**; the tenant is warned that the displayed local time will change and by how much |

The last row is the one that trips implementations: changing a zone must not silently reschedule
an event that fifty people have in their calendars.

### Practically

- Store `timezone` on the event, copied to each occurrence at creation and overridable there —
  a tour visits several zones.
- Never do date arithmetic on strings. The current
  `` `${startDate} ${startTime}` `` display (**V17**) is the shape to remove entirely.
- ICS files carry `DTSTART;TZID=` with the IANA zone, plus `VTIMEZONE`
  ([10](10-communications.md) §10.4).

## 5.6 Venues

`laravel_venues` ([04](04-database-schema.md) §4.5) is a reusable record, not a text field,
because a tenant runs most of their events in the same few places and free-text produces three
spellings of one address in the export.

- An event names a default venue; an occurrence may override it.
- A venue carries directions, parking, accessibility notes and a map image — all of which belong
  in the confirmation email and on the event page, and none of which anyone types twice.
- `venues.capacity` gives the admin an honest warning when an occurrence's capacity exceeds the
  room's.
- The venue's zone defaults the occurrence's, which is right nearly always and the sort of
  default that prevents the mistakes in §5.5.

**Accessibility notes are a first-class field**, not a line in the description, because they need
to be reliably present on the event page and in the confirmation email — and a description is
not reliably anything.

## 5.7 Online and hybrid events

`venues.type` is `physical` · `online` · `hybrid`.

| Type | Behaviour |
|---|---|
| `physical` | Address, map, directions |
| `online` | `occurrences.online_url`, plus platform metadata. No address |
| `hybrid` | Both, and ticket types are marked in-person or online ([06](06-ticketing-and-capacity.md) §6.2) |

**The join link is released on a schedule**, not embedded in the confirmation email
(`online_url_release`, [04](04-database-schema.md) §4.3):

| Mode | Release |
|---|---|
| `immediate` | In the confirmation |
| `before_start` (default) | N minutes before, by email and on the attendee page |
| `on_start` | At the start time |
| `manual` | When an operator releases it |

The default is deliberate. A join link mailed three weeks ahead is a link forwarded to people who
did not register — and for a paid online event, that is the whole product given away. The
attendee page (reachable by `access_token`, [02](02-site-users-guard.md) §2.5) shows the link
once released, so a lost email is not a lost ticket.

**Hybrid capacity is two pools.** In-person places are bounded by the room; online places usually
are not. Modelling that as one capacity means the room's limit caps online attendance, and
[06](06-ticketing-and-capacity.md) §6.2 makes them separate ticket types with separate
capacities, sharing nothing but the occurrence.

## 5.8 Speakers, hosts and sponsors

`laravel_event_people` plus a pivot ([04](04-database-schema.md) §4.13), attachable to an event,
an occurrence or a session.

- A speaker record is reused across events — a regular host is entered once.
- Sessions list their speakers; speakers list their sessions. The agenda page and the speaker
  page are the same data read two ways.
- Sponsors are the same table with a different `role`, rendered as a logo wall.
- Public exposure is per-person: a speaker's bio and photo are public; their email is not, and
  the public projection never carries it ([11](11-public-surface-and-sdk.md) §11.3).

## 5.9 Event lifecycle

`status`: `draft` → `published` → `cancelled` | `archived`, on the event;
`scheduled` → `cancelled` | `postponed` | `completed`, on the occurrence.

| Transition | Effect |
|---|---|
| Publish | The event becomes publicly readable; occurrences with an open registration window accept registrations |
| Occurrence completed | Set by a job after `ends_at`. Triggers the follow-up ([10](10-communications.md) §10.6) and freezes attendance reporting |
| Occurrence cancelled | Registrations marked `cancelled`, refunds per policy, everyone notified ([08](08-payments-and-refunds.md) §8.8). **Never deleted** |
| Occurrence postponed | Registrations **kept**, everyone notified, new date set later. Distinct from cancelled precisely because the registrations survive |
| Event archived | Disappears from the admin's default list; public 404; data retained |

**Postponed is a real state, not a cancellation with a note.** It is what happened to a great
many events, and a system that can only cancel forces a tenant to refund and re-sell an event
that is still going ahead.

## 5.10 Validation (V13)

The current form validates with one `alert()` and no date logic. What must be enforced,
server-side and surfaced in the editor:

| Rule | |
|---|---|
| `ends_at` > `starts_at` | And a warning above 24 hours unless multi-day is explicit |
| `doors_open_at` ≤ `starts_at` | |
| `registration_opens_at` < `registration_closes_at` ≤ `ends_at` | Closing after the event ends is almost always a mistake |
| Sessions fall within their occurrence | Warn, do not block — a fringe event before doors is real |
| Registrable sessions in one track do not overlap | Refuse |
| Occurrence capacity ≤ venue capacity | Warn |
| Sum of ticket-type capacities ≤ occurrence capacity | Warn, unless types share the pool |
| A local time that does not exist (DST) | Refuse, explain |
| Publishing an event with no occurrence | Refuse |
| Publishing a paid event with no Direct Payment | Refuse ([08](08-payments-and-refunds.md) §8.4) |
| An occurrence in the past | Warn; allow — back-filling a past event to record attendance is legitimate |

**`ageRestriction` is removed** (**V18**). `all` / `18+` / `21+` is US-centric, unenforced and
presentational. It becomes a free-text `restrictions` note on the occurrence, shown on the event
page and in the confirmation — which is what it always was.

## 5.11 Acceptance

- A weekly Tuesday 18:30 class for a year generates 52 occurrences, all at 18:30 local, with UTC
  instants that shift across both DST boundaries.
- Cancelling the 23 December occurrence leaves the other 51 scheduled.
- Editing one occurrence's venue, then editing the series' venue, **does not** overwrite the
  exception — and the series edit reports that it skipped one.
- Changing the series time when occurrence 12 has registrations requires confirmation naming the
  count, and notifies those registrants.
- A three-day conference is one occurrence, and a delegate registers once.
- A workshop with 30 places refuses the 31st registration.
- Two overlapping registrable sessions in one track cannot both be chosen by one attendee.
- An attendee in New York sees London time with their own time beside it.
- An occurrence authored at a non-existent local time is refused with an explanation.
- Changing an event's time zone after registrations exist does not move the stored instants, and
  warns.
- An online event's join link is absent from the confirmation email and appears N minutes before
  the start, on the attendee page and by email.
- A hybrid event sells 200 in-person and unlimited online places from one occurrence.
- A postponed occurrence keeps every registration.
- A published event with no occurrence, or a paid event with no Direct Payment, is refused.
