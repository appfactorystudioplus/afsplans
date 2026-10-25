# 00 — Overview

## 0.1 What this module is for

A tenant creates an event, sells or allocates places on it, puts it on their website, collects
who is coming and what they need, tells them where to be, checks them in at the door, and reads
the numbers afterwards.

Almost none of that exists. What exists is the create-and-edit screen for the first step, built
against an API that was never written ([01](01-current-state-audit.md)).

## 0.2 Goals

| # | Goal | Where |
|---|---|---|
| G1 | Events, ticket types and registrations are persisted server-side, through a real API | [02](02-site-users-guard.md) §2.2, [04](04-database-schema.md) |
| G2 | Admin, public-read and attendee surfaces are separate from the first commit | [02](02-site-users-guard.md) §2.2 |
| G3 | One event can recur, span days, and contain sessions with their own capacity | [05](05-event-model-and-scheduling.md) |
| G4 | A 50-seat event sells exactly 50 seats under load | [06](06-ticketing-and-capacity.md) §6.5 |
| G5 | Tickets have types, tiers, sales windows, limits and discounts | [06](06-ticketing-and-capacity.md) |
| G6 | A registration can cover several attendees, each with their own answers | [07](07-registration-and-attendees.md) §7.2 |
| G7 | Custom questions reuse the forms module rather than a second builder | [07](07-registration-and-attendees.md) §7.4 |
| G8 | Paid events go through Direct Payments and nothing else | [08](08-payments-and-refunds.md) |
| G9 | Attendees can be checked in at the door, **with no network** | [09](09-checkin-and-onsite.md) §9.5 |
| G10 | Confirmations, reminders and calendar invites are sent by the server | [10](10-communications.md) |
| G11 | An event can be placed on a site, embedded, and found by search engines | [11](11-public-surface-and-sdk.md) |
| G12 | An operator can answer "how many, how much, who came" | [13](13-reporting-and-analytics.md) |

## 0.3 Non-goals

| Not building | Why |
|---|---|
| A payment integration | [03](03-architecture.md) §3.3. Direct Payments already exists and already handles provider differences |
| A form builder | The [forms module](../forms/README.md) is being built properly; events attaches a form ([07](07-registration-and-attendees.md) §7.4) |
| An identity system | [Site Users](../site_users/README.md), optional per event ([02](02-site-users-guard.md) §2.8) |
| A video platform | Online events store a join link and control when it is released ([05](05-event-model-and-scheduling.md) §5.7). Hosting the stream is a product |
| Reserved seating with a seat-map designer | Section/table allocation is in scope ([06](06-ticketing-and-capacity.md) §6.9); a graphical seat-map editor with holds per seat is its own product |
| A native check-in app | A PWA with offline scanning covers the case ([09](09-checkin-and-onsite.md) §9.5). App-store distribution is a separate project |
| Marketing automation | Scheduled reminders and a post-event follow-up ([10](10-communications.md)); drip campaigns and segmentation belong to a marketing module |
| A ticket resale / transfer marketplace | Person-to-person transfer is in scope ([07](07-registration-and-attendees.md) §7.8); a secondary market is not |
| Multi-currency presentment | One currency per event, as [ecommerce/03](../ecommerce/03-architecture.md) §3.8 decided for stores |

## 0.4 Glossary

The vocabulary is most of the design, so it is worth fixing before the schema.

| Term | Meaning here |
|---|---|
| **Event** | The recurring identity — "Tuesday Yoga", "Annual Conference". Holds the description, venue defaults, images, organiser |
| **Occurrence** | One instance of an event at one time. **This is what a person registers for** ([05](05-event-model-and-scheduling.md) §5.2) |
| **Session** | A sub-item inside an occurrence — a talk, a workshop, a track. May have its own capacity and its own registration |
| **Ticket type** | A priced (or free) class of admission to an occurrence — General, VIP, Member, Student |
| **Tier** | A time- or quantity-bounded price for a ticket type — Early Bird, then Standard |
| **Hold** | Inventory claimed but not yet paid for, with a TTL ([06](06-ticketing-and-capacity.md) §6.6) |
| **Allocation** | Inventory reserved off the public pool — press, sponsors, comps |
| **Order** | One transaction. Covers one or more registrations, has one payment |
| **Registration** | One person's place at one occurrence, of one ticket type |
| **Attendee** | The person a registration is for. May differ from the purchaser |
| **Check-in** | A registration marked present, at an occurrence or a session |
| **Waitlist** | An ordered queue for a full occurrence or ticket type, with expiring offers |
| **Organiser** | The tenant, as displayed publicly — name, logo, contact |

Two distinctions do most of the work:

**Event vs occurrence.** The current table has a single `event_date` (**V3** context), which
makes a weekly class either 52 duplicated events or one event that lies about its date.

**Order vs registration.** One purchase of four tickets is one order and four registrations. The
current UI has no concept of either; conflating them is how "transfer one of my four tickets"
becomes impossible ([07](07-registration-and-attendees.md) §7.8).

## 0.5 The five decisions

### D1 — Occurrences are rows, not rules

Rejected: storing an RRULE and computing occurrences on read. Elegant until a single week is
cancelled, a venue changes for one date, or 30 people register for the 14th — at which point
there is nowhere to put that information, and every query has to expand the rule first.

Chosen: **a recurrence rule generates rows**, materialised into `laravel_event_occurrences`, and
each row is independently editable ([05](05-event-model-and-scheduling.md) §5.3). Editing the
series offers "this occurrence" or "this and following", and an occurrence that has diverged is
marked so a later series edit does not silently overwrite it.

Materialising is bounded — a rolling horizon (default 12 months), extended by a scheduled job —
so "every Tuesday forever" does not mean an infinite table.

### D2 — Capacity is claimed by conditional update

The one correctness property an events system is judged on. Specified in
[06](06-ticketing-and-capacity.md) §6.5, and it is why counters are cached on the row: the
conditional `UPDATE` needs a single row to lock.

Cached counters drift, so they are **reconciled on a schedule** against the registrations they
summarise, and the reconciler's disagreement count is a monitored number rather than a log line.

### D3 — An order is a Direct Payments invoice

The same decision [ecommerce/06](../ecommerce/06-cart-and-checkout.md) §6.3 made for carts, for
the same reason: `createInvoice()`, `addItem({ item_ref, quantity })`, `lock()`, `getTotal()`
already models what a ticket order is. Building a parallel totalling is how the number a
registrant saw and the number they were charged come apart
([08](08-payments-and-refunds.md) §8.3).

### D4 — Registration questions are a form

Events needs custom questions — dietary requirements, t-shirt size, accessibility needs, company
name, session choices. The [forms module](../forms/README.md) is getting conditional logic,
validation, multi-language and versioned definitions.

Chosen: **a ticket type may name a form**, answered per attendee, stored as a form submission
and mirrored onto the registration ([07](07-registration-and-attendees.md) §7.4). Events gains a
question builder it does not have to write, and forms gains a consumer that proves its
versioning ([forms/09](../forms/09-publishing-and-versioning.md)).

The cost is a real dependency between modules, and it is bounded: an event with no custom
questions never touches the forms module, and the fields every event needs — name, email, phone
— are native columns, not form answers ([07](07-registration-and-attendees.md) §7.3).

### D5 — The door works offline

A check-in flow that requires connectivity fails in exactly the place events happen: a basement
conference room, a marquee, a village hall. The scanner holds a signed manifest, validates
tickets locally, queues check-ins, and syncs when it can
([09](09-checkin-and-onsite.md) §9.5) — with an explicit, stated answer for the duplicate-scan
race that offline creates.

## 0.6 What "comprehensive" means here, concretely

The features that separate an events platform from an event table, each with a home:

| Capability | § |
|---|---|
| Recurring series, per-occurrence overrides, cancel one date | [05](05-event-model-and-scheduling.md) §5.3 |
| Multi-day events, sessions, tracks, per-session capacity | [05](05-event-model-and-scheduling.md) §5.4 |
| Time zones and DST correctness | [05](05-event-model-and-scheduling.md) §5.5 |
| Venues; online and hybrid; timed release of join links | [05](05-event-model-and-scheduling.md) §5.6–§5.7 |
| Speakers, agenda, sponsors as content | [05](05-event-model-and-scheduling.md) §5.8 |
| Ticket types, tiers, sales windows, per-order limits | [06](06-ticketing-and-capacity.md) §6.2–§6.4 |
| Atomic capacity, holds with TTL, allocations, comps | [06](06-ticketing-and-capacity.md) §6.5–§6.7 |
| Promo codes and access-code tickets | [06](06-ticketing-and-capacity.md) §6.8 |
| Section and table assignment | [06](06-ticketing-and-capacity.md) §6.9 |
| Group registration, attendee assignment, per-attendee answers | [07](07-registration-and-attendees.md) §7.2–§7.4 |
| Approval workflows | [07](07-registration-and-attendees.md) §7.6 |
| Waitlists with expiring offers and auto-promotion | [07](07-registration-and-attendees.md) §7.7 |
| Transfers, name changes, cancellations | [07](07-registration-and-attendees.md) §7.8 |
| Direct Payments, fees, tax, refunds, cancellation policy | [08](08-payments-and-refunds.md) |
| QR tickets, offline scanning, badges, walk-ins, session scanning | [09](09-checkin-and-onsite.md) |
| Confirmations, reminders, ICS, waitlist offers, follow-ups | [10](10-communications.md) |
| Public pages, builder blocks, embeds, `Event` structured data | [11](11-public-surface-and-sdk.md) |
| Sales, attendance, utilisation, exports | [13](13-reporting-and-analytics.md) |

## 0.7 What "solid" means for this module

Six properties, each with an acceptance test in [14](14-rollout-and-testing.md):

1. **A place is never sold twice.** Under concurrency, across holds, waitlist promotion and
   payment failure (**D2**).
2. **A registration survives everything that changes afterwards** — the price, the ticket
   name, the form, the venue (**D4**, [07](07-registration-and-attendees.md) §7.5).
3. **Money taken always corresponds to a registration**, and a failed write is reconciled
   rather than lost ([08](08-payments-and-refunds.md) §8.7).
4. **The stated time is the right time**, across zones and DST (**D5**,
   [05](05-event-model-and-scheduling.md) §5.5).
5. **No attendee's details are readable by anyone but the tenant and that attendee**
   ([02](02-site-users-guard.md) §2.5).
6. **The door works** when the network does not (**D5**).
