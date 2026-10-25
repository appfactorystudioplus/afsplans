# 12 — The Admin Dashboard

`resources/js/portal/admin/controllers/events/events.ts` — 708 lines — and
`views/eventsView.ts`. Closes **V8**–**V16**, **V20**, **V21**, **V23**, **V24**.

## 12.1 What exists, honestly

More than the module's back end deserves, and worth keeping most of.

| View | State |
|---|---|
| `events` | A table with name, title, reference, Direct Payment, level count, status badge, View / Delete |
| `events:create` | Name, title, reference, DP select, **repeatable ticket levels each mapped to a DP item**, start/end date and time, total tickets, age restriction, description |
| `events:detail` | All of the above rendered read-only, plus Configure / Preview / Response |
| `events:preview` | A hand-built HTML document in a blob-URL iframe |
| `events:response` | `EmailConstructionBuilder` |
| `events-discounts` | A `localStorage` discount-code CRUD |

And the defects that come with it: it persists to `localStorage` (**V8**), generates its own
references from `Math.random()` (**V9**), never calls the server to delete (**V11**), can
silently duplicate an event when editing (**V12**), validates with one `alert()` and no date
logic (**V13**), has one capacity bucket that resets on save (**V14**), stores ticket levels
with no price (**V15**), silently drops half-filled levels (**V16**), leaks a blob URL per
preview (**V20**), and reports every failure to the console (**V24**).

**`escapeHtml()` is defined once and used everywhere** (`:25–36`), which is more discipline than
[forms](../forms/01-current-state-audit.md) showed (**F35**, **F36**). The rewrite keeps it.

## 12.2 The shape

Six screens become a module:

```
Events
├── Overview          §12.3   what needs attention today
├── Events list       §12.4   status · next occurrence · sold · revenue
│   └── Event
│       ├── Details           name, description, images, venue, organiser
│       ├── Schedule   §12.4  occurrences, recurrence, sessions
│       ├── Tickets    §12.5  types, tiers, allocations, promo codes
│       ├── Registrations §12.6  list · detail · check-in
│       ├── Questions  §12.5  the attached form
│       ├── Communications    templates, reminders
│       ├── Reports    13     sales, attendance
│       ├── Share             links, embeds, QR
│       └── Settings   §12.7  policy, waitlist, approval, accounts
├── All registrations §12.6   across every event
├── Venues            §12.4   the reusable venue book
├── Speakers          §12.4   the reusable people book
├── Promo codes       §12.5   across events
└── Check-in          09      live view and device management
```

## 12.3 Overview

The screen an organiser opens every morning. *What needs me today*, not a revenue chart.

- **Next occurrences** — the next 7 days, with sold / capacity and a fill bar.
- **Nearly sold out** — above a threshold, linked.
- **Waitlists with entries** — and any offers expiring today
  ([07](07-registration-and-attendees.md) §7.7).
- **Approvals pending** ([07](07-registration-and-attendees.md) §7.6).
- **Unassigned registrations** — places bought and never claimed
  ([07](07-registration-and-attendees.md) §7.2).
- **Failed payments and reconciliation failures**
  ([08](08-payments-and-refunds.md) §8.7).
- **Failed communications** ([10](10-communications.md) §10.2).
- **Counter disagreements** ([06](06-ticketing-and-capacity.md) §6.5) — an inventory bug
  surfacing before it oversells.
- **Setup warnings** — a published paid event with no Direct Payment; a ticket type whose price
  disagrees with its DP item ([08](08-payments-and-refunds.md) §8.4); an occurrence whose
  capacity exceeds its venue's; a published event with no reminder configured.

The setup warnings are what stop a tenant publishing an event that cannot take money — the same
job [ecommerce/11](../ecommerce/11-admin-dashboard.md) §11.3 gives them for a shop.

## 12.4 Events, schedule, venues, people

### The list

Name · status · **next occurrence** · sold / capacity · revenue · site.

"Next occurrence" is the column that makes the list useful, and it is the one a single
`event_date` cannot produce. Filters on status, date range, category, venue and site; search;
sort. Bulk archive, duplicate, export.

**Actions become buttons and a menu**, and Delete confirms with the registration count, requires
typing the name when registrations exist, and — unlike today (**V11**) — **calls the server and
checks the response**.

**Duplicate** copies the event, its ticket types, its questions and its settings into a new
draft, and never its registrations. A tenant's second event is usually their first with three
changes.

### The schedule editor

Where recurrence lives ([05](05-event-model-and-scheduling.md) §5.3):

- A **one-off event shows one date pair** — the recurrence machinery is hidden until asked for
  ([05](05-event-model-and-scheduling.md) §5.2).
- "Repeat…" opens the rule builder — frequency, interval, weekdays, end condition — with a
  **plain-language preview** and a list of the next ten dates. *"Every Tuesday at 18:30 until 30
  June — 34 occurrences."* Rule builders are where operators make expensive mistakes, and a
  sentence they can read back catches more than a form they can tab through.
- Occurrences render as a list or calendar; each shows sold / capacity and status.
- Editing one offers **this / this and following / all**, and an occurrence that has diverged is
  **marked**, with a later series edit reporting what it skipped.
- Cancelling one names the registration count and the refund total before proceeding.
- Occurrences with registrations are visually distinct, because they are the ones that cannot be
  changed casually.

### Sessions

An agenda builder per occurrence — day tabs, a time grid, tracks as columns, drag to reschedule.
Each session carries its capacity, room, speakers and whether it requires registration
([05](05-event-model-and-scheduling.md) §5.4). Overlaps within a track are flagged as they are
created, not at save.

### Venues and speakers

Two small reusable books ([05](05-event-model-and-scheduling.md) §5.6, §5.8). Both exist so
nobody retypes an address or a bio, and both are ordinary CRUD — listed here because their
absence is what produces three spellings of one venue in an export.

## 12.5 Tickets, questions and promo codes

### Ticket types

The screen the current UI gestures at. Per occurrence, or per event for a series
([05](05-event-model-and-scheduling.md) §5.3):

| Column | |
|---|---|
| Name, kind | paid · free · donation · allocation |
| **Price** | Editable here (**V15**) |
| DP item | With a **mismatch warning** when the prices disagree ([08](08-payments-and-refunds.md) §8.4) |
| Capacity | Dedicated, or shared with the occurrence pool |
| Sold / held / remaining | Live |
| Sales window | Start and end |
| Visibility | public · hidden · code-required |
| Tiers | Inline, expandable |

Editable **inline**, in a grid, with bulk edit across selected rows. Six modal dialogs to set six
prices is how ticket types get abandoned — the same lesson
[ecommerce/11](../ecommerce/11-admin-dashboard.md) §11.5 draws about variants.

**A half-filled ticket type is refused with a message**, not silently dropped (**V16**).

Allocations get an **issue** action: name the recipient, optionally email the ticket, record the
reason ([06](06-ticketing-and-capacity.md) §6.7). And a **release** action to return unused
allocation to the public pool.

### Questions

The forms attachment ([07](07-registration-and-attendees.md) §7.4): choose a published form,
choose per-order or per-attendee, preview it inline, and edit it — which opens the forms editor
in place rather than a second question builder.

### Promo codes

Replaces the `localStorage` discounts view (**V21**) with a real screen: code, type, value,
scope, usage against limit, window, status. Bulk generation of unique codes for a mailing, CSV
export, and a **plain-language preview** — *"20 % off General Admission, first 100 uses, until
31 Dec"* — for the same reason the recurrence builder has one.

## 12.6 Registrations

The largest new surface, and the one an organiser lives in during the week before an event.

### The list

Per occurrence, and a cross-event view for *"did anyone register today"*.

- **Columns**: name, email, ticket type, status, registered, checked in, amount, plus **pinned
  question columns** the operator chooses — which is what makes the list about this event's
  content rather than about metadata.
- **Filters**: status, ticket type, session, checked-in, approval state, waitlist, unassigned,
  date range, promo code used.
- **Search** across name, email, reference and answers.
- **Cursor pagination**, not a 200-row cap — the mistake
  [forms **F23**](../forms/01-current-state-audit.md) documents.
- **Bulk**: email selection, check in, cancel with refund, export, print badges, assign tables.

### The detail

| Panel | |
|---|---|
| Attendee | Name, email, phone, company, linked account, past registrations |
| Ticket | Type, tier, **snapshot price**, session choices, seat/table |
| Order | Reference, total, discount, fees, tax, payment status, **link into the Money ledger** |
| Answers | Rendered through the pinned form version ([07](07-registration-and-attendees.md) §7.4) |
| Check-in | When, by whom, per session |
| **Timeline** | Every activity entry, newest first ([04](04-database-schema.md) §4.13) |
| Notes | Internal |

**Actions**: check in, cancel (with or without refund), refund without cancelling, transfer,
edit attendee, resend the confirmation, reissue the ticket, approve or reject, add a note.

**The timeline is the panel that makes support possible.** "Was this person emailed, and when"
is the most common question a registration screen receives, and it is the one both sibling
modules cannot answer ([forms **F56**](../forms/01-current-state-audit.md)).

### Waitlist

Its own tab per occurrence: position, name, email, quantity, joined, offer state and expiry.
Actions: offer now, skip, remove, convert manually. Reordering is possible and **audited**,
because a queue an operator can silently reorder is not a queue.

## 12.7 Settings

Per event, one screen, writing `settings` ([04](04-database-schema.md) §4.12) — and **none of it
creates or changes an occurrence**:

| Section | |
|---|---|
| **General** | Name, slug, category, organiser, currency, time zone |
| **Registration** | One-per-email, fields collected, transfer and self-cancel rules |
| **Approval** | Required, expiry, notification |
| **Waitlist** | Enabled, offer TTL, auto-promote, position visibility |
| **Holds** | TTL |
| **Payments** | Direct Payment, fee mode, tax treatment ([08](08-payments-and-refunds.md)) |
| **Policy** | Refund policy and terms — **shown to registrants before purchase** |
| **Communications** | Templates, reminder offsets, follow-up, organiser digest |
| **Check-in** | Walk-ins, scan window, duplicate-scan behaviour, badge template |
| **Accounts** | Site Users mode ([02](02-site-users-guard.md) §2.8) |
| **Display** | Show remaining, threshold, show attendee count |
| **Danger** | Cancel, postpone, archive, delete, purge |

Payments and Accounts sit together deliberately — they are the two decisions that change what an
event *is*, and a tenant should meet them in one place rather than discovering the second later
([ecommerce/11](../ecommerce/11-admin-dashboard.md) §11.8).

## 12.8 Roles, and what the admin cannot do

### Roles

Events need people who are not administrators:

| Role | Can |
|---|---|
| **Organiser** | Everything for their events |
| **Check-in operator** | Scan, search, walk-in — **and nothing else** ([09](09-checkin-and-onsite.md) §9.4) |
| **Viewer** | Read registrations and reports; no edits, no exports of personal data |

A volunteer with a scanner must not hold a credential that can refund an order.

### Not offered

| | Why |
|---|---|
| Edit a paid order's totals | The order records what happened; corrections are refunds ([ecommerce/11](../ecommerce/11-admin-dashboard.md) §11.9) |
| Delete a registration | Cancel or purge. Deleting destroys the audit trail and the refund obligation |
| Delete an occurrence with registrations | Cancel it |
| Edit capacity counters directly | They are claimed and reconciled ([06](06-ticketing-and-capacity.md) §6.5). A hand-edited counter is an oversell |
| Change an attendee's answers | Amend with a record, never in place |
| See a provider key or a webhook secret | Never in a response |
| Register someone bypassing capacity | An override exists, is explicit, is logged, and still increments the counters |

The last one matters: organisers **will** need to squeeze one more person in. The answer is a
deliberate override that keeps the counters honest, not a way around them.

## 12.9 What happens to `events.ts`

708 lines covering six views, an API client, two template mechanisms and a preview generator.

```
controllers/events/
  api.ts                     the ADMIN client → /portal/api/v1/events/*   §11.4
  views/
    overview.ts              §12.3
    list.ts                  §12.4
    event/
      details.ts · schedule.ts · sessions.ts
      tickets.ts · tiers.ts · promos.ts · questions.ts
      registrations.ts · registrationDetail.ts · waitlist.ts
      communications.ts · reports.ts · share.ts · settings.ts
    venues.ts · people.ts · checkin.ts
  shared/
    recurrence.ts            rule builder + plain-language preview   §12.4
    datetime.ts              zone-aware formatting                    05 §5.5
    escape.ts                keep the existing discipline
```

Rules for the rewrite:

- **No `localStorage` as a data store** (**V8**). It survives only as crash recovery for an
  in-progress edit, keyed by event reference.
- **The server generates every reference** (**V9**, **V10**).
- **Every mutation calls the server and checks the response** (**V11**).
- **Create vs update is decided by the route**, not by a hidden field (**V12**).
- **Validation is inline and server-mirrored** — no `alert()` (**V13**,
  [05](05-event-model-and-scheduling.md) §5.10).
- **All dates go through one zone-aware helper.** No string concatenation (**V17**).
- **Use the `TEMPLATES` registry** the other views use, not `innerHTML = template()` (**V23**).
- **Loading, empty, error and permission states on every view** (**V24**) — the current module's
  most common runtime outcome is total failure, reported to the console.
- **The preview is the public renderer**, not a hand-built HTML document in a leaked blob URL
  (**V20**) — the same divergence [forms **F40**](../forms/01-current-state-audit.md) documents.
  Preview renders the real public component in an iframe pointed at a signed preview URL.

`eventsView.ts`'s lazy registration pattern is good and every new view follows it — including
**destroying its state on teardown**, which the current module does not.

## 12.10 Acceptance

- Nothing in the events admin reads or writes `localStorage` as a source of truth.
- Creating an event on one machine shows it on another.
- Deleting an event calls the server, checks the response, and survives a reload.
- Editing an event not in local state edits it, and never creates a duplicate.
- The list shows the next occurrence, sold/capacity and revenue.
- A one-off event never shows recurrence controls; "Repeat…" reveals them with a plain-language
  preview and the next ten dates.
- Editing one occurrence then editing the series does not overwrite the exception, and the
  series edit reports what it skipped.
- Cancelling an occurrence names the registration count and the refund total first.
- Ticket types are inline-editable with prices, tiers and live sold/remaining.
- A price mismatch with the DP item is visible on the type, the event and the overview.
- A half-filled ticket type is refused with a message, not dropped.
- The registrations list pages, filters, searches and exports, and pinned question columns work.
- The registration timeline shows every email sent, including failures.
- The waitlist tab shows positions and offer expiry, and reordering is audited.
- A check-in operator cannot reach the events admin.
- A capacity override is explicit, logged, and still increments the counters.
- Every view has a loading, empty and error state.
- No date is produced by string concatenation.
- The preview renders the real public component; no blob URL is leaked.
