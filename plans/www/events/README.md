# events — Planning Index

Building a real events platform: recurring series, multi-session agendas, ticket types with
their own capacity and sales windows, held inventory, promo codes, group registration,
waitlists that promote themselves, QR check-in, and a registration form the tenant designs.

> ### ⚠ The module has a front end and no back end
>
> `laravel_events` is **one table**. There is **no controller and no routes** (**V1**).
>
> Meanwhile `resources/js/portal/admin/controllers/events/events.ts` is **708 lines** of
> working admin UI — event create/edit with ticket levels, Direct Payment item mapping, a
> detail view, an iframe preview and an email-response builder. It talks to
> `ExternalEventsAPI`, which posts to `/_portal_/admin/core/php/request.php` — **a path that
> does not exist anywhere in this repository** (`public/_portal_` is absent entirely), and for
> which no PHP handler exists (**V2**).
>
> So every server call fails, the `catch` logs *"falling back to local store"*, and the module
> runs entirely on **`localStorage`** (**V8**). Delete does not even attempt the server
> (**V11**).
>
> And the module very likely does not load at all: `events.ts:13` runs
> `new ExternalEventsAPI()` at module scope, but the externals bundle only ever assigns
> `window.Portal` — **the class is never a global** (`external/main.ts:113–116`), so the
> dynamic import should throw `ReferenceError` before any view initialises (**V7**).
>
> **There is still no security emergency** — nothing is exposed because nothing is served,
> which is the opposite of [appointments](../appointments/README.md) and
> [ecommerce](../ecommerce/README.md). See
> [01-current-state-audit.md](01-current-state-audit.md).

Read in this order:

| Step | Document | Contents |
|---|----------|----------|
| — | [README.md](README.md) | This index |
| 00 | [00-overview.md](00-overview.md) | What this is for, goals, non-goals, glossary, and the five decisions |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | Every file and table that exists, and the 26 findings |
| 02 | [02-site-users-guard.md](02-site-users-guard.md) | The registration model, the split API, capacity and waitlists, paid events, optional accounts via [Site Users](../site_users/README.md). **Extended by [`../connections/`](../connections/README.md)**: the event↔module link, gating holds and waitlists, the cancellation window, and the attendee's Upcoming/Past dashboard |
| 03 | [03-architecture.md](03-architecture.md) | The layers, what owns money, what owns identity, and why an occurrence is not an event |
| 04 | [04-database-schema.md](04-database-schema.md) | The full schema — series, occurrences, sessions, venues, ticket types, holds, orders, registrations, attendees, check-ins |
| 05 | [05-event-model-and-scheduling.md](05-event-model-and-scheduling.md) | **Recurrence**, multi-day, sessions and tracks, time zones and DST, venues, online and hybrid |
| 06 | [06-ticketing-and-capacity.md](06-ticketing-and-capacity.md) | Ticket types, tiers, **holds and reservations**, promo codes, allocations, seating |
| 07 | [07-registration-and-attendees.md](07-registration-and-attendees.md) | Group registration, **custom questions via the forms module**, approval, transfers, waitlists |
| 08 | [08-payments-and-refunds.md](08-payments-and-refunds.md) | Direct Payments, money rules, fees, taxes, refunds and cancellation policy |
| 09 | [09-checkin-and-onsite.md](09-checkin-and-onsite.md) | QR tickets, scanning, **offline check-in**, badges, walk-ins, per-session attendance |
| 10 | [10-communications.md](10-communications.md) | Confirmations, reminders, **ICS calendar**, waitlist offers, post-event follow-up |
| 11 | [11-public-surface-and-sdk.md](11-public-surface-and-sdk.md) | The public API, `window.Portal.events`, builder blocks, embeds, SEO |
| 12 | [12-admin-dashboard.md](12-admin-dashboard.md) | The operator surface, and what happens to `events.ts` |
| 13 | [13-reporting-and-analytics.md](13-reporting-and-analytics.md) | Sales, attendance, utilisation, exports |
| 14 | [14-rollout-and-testing.md](14-rollout-and-testing.md) | Phases, the concurrency test matrix, migrating what is in `localStorage` |

## Completion documents

Each numbered document gets a completion document in [`complete/`](complete/) — source
basename plus `.complete.md`. Include status, summary, files changed, verification steps and
follow-up work.

## Code documentation standard

Document all new and changed code thoroughly with block comments — PHPDoc for PHP, JSDoc for
TypeScript. Every file, exported symbol, public function, route handler, migration and
non-trivial type must explain purpose, parameters, return values, side effects and plan
references.

New code carries `AFS_EVENTS_MODIFIER_ADDITION` (see [../../markers.md](../../markers.md));
Site Users work carries `AFS_SITE_USERS_MODIFIER_ADDITION`. Where a file already has a marker,
**add alongside; never replace.**

## Building it with its siblings' mistakes already known

Events is the one module here being written rather than repaired, so it starts with what the
audits of the other modules cost them:

| Sibling's mistake | What events does instead |
|---|---|
| One prefix for admin and public, which is how both ended up with the wrong middleware ([ecommerce **E1**](../ecommerce/02-current-state-audit.md), [appointments **A1**](../appointments/01-current-state-audit.md)) | **Split from the first commit** — admin, public read, attendee ([02](02-site-users-guard.md) §2.2) |
| Listing bookings publicly, leaking every attendee's name, email and phone ([appointments **A3**](../appointments/01-current-state-audit.md)) | Listing registrations is **admin-only**, by construction |
| A bulk-replace save that deletes everything absent from the payload ([appointments **A1**](../appointments/01-current-state-audit.md)) | Registration creates **one** row and deletes nothing |
| A capacity stored and never enforced ([appointments **A10**](../appointments/01-current-state-audit.md)) | Capacity claimed by an **atomic conditional update** ([06](06-ticketing-and-capacity.md) §6.5) |
| Admin writes published on the public SDK ([appointments **A6**](../appointments/01-current-state-audit.md)) | The storefront SDK exposes **no** admin write ([11](11-public-surface-and-sdk.md) §11.4) |
| An emailed link whose credential is a guessable id ([appointments **A9**](../appointments/01-current-state-audit.md)) | `access_token` from the first migration ([02](02-site-users-guard.md) §2.5) |
| Money as `DECIMAL(10,2)` alongside integer cents elsewhere ([ecommerce **E9**](../ecommerce/02-current-state-audit.md)) | **Every amount is `*_cents`**, from the first migration ([08](08-payments-and-refunds.md) §8.2) |
| A submission that cannot be read after the form changed ([forms **F17**](../forms/01-current-state-audit.md)) | A registration **snapshots** what was bought and answered ([07](07-registration-and-attendees.md) §7.5) |

## The five rules this plan is built on

**1. An occurrence is the thing you register for, not the event.**
A weekly class, a three-city tour and a conference that runs twice a year are all one event
with many occurrences. Modelling "event = one date" is the decision that later forces a rewrite,
and the current single `event_date` column is exactly that shape
([05](05-event-model-and-scheduling.md) §5.2).

**2. Capacity is claimed, never counted.**
Every seat is taken by a conditional `UPDATE` that fails when the event is full — never by
`SELECT count(*)` followed by an `INSERT`. A count-then-insert races, and it races hardest at
the moment a popular event opens, which is precisely when overselling is unforgivable
([06](06-ticketing-and-capacity.md) §6.5).

**3. Direct Payments owns money; events owns the event.**
Events never imports a provider SDK, never holds a key, never handles a webhook. It builds an
invoice, hands it over, and records what came back — the same boundary
[ecommerce/03](../ecommerce/03-architecture.md) §3.2 draws, so subscriptions, saved cards and
refunds arrive through a seam that already exists ([08](08-payments-and-refunds.md)).

**4. The registration form is a form.**
Custom questions, conditional logic, validation and multi-language already exist in the
[forms module](../forms/README.md) and are being built properly there. Events attaches a form
to a ticket type rather than growing a second, worse question builder
([07](07-registration-and-attendees.md) §7.4).

**5. Time is stored in UTC and displayed in the event's zone.**
An event has an IANA time zone, and every timestamp is UTC. A recurring 09:00 class must stay
09:00 across a DST boundary, and a naive `datetime` column cannot express that
([05](05-event-model-and-scheduling.md) §5.5).

## One-paragraph summary

The events module today is a well-built admin screen with nothing behind it: one table, no
controller, no routes, and an SDK pointed at a legacy PHP endpoint that is absent from this
repository — so the whole thing runs on `localStorage`, and probably throws a `ReferenceError`
before it renders. That is a better starting position than it sounds, because nothing is
exposed and nothing has to be migrated except a browser cache. This plan builds the module the
existing UI implies and then the platform it does not: **events as series with occurrences**,
multi-session agendas with per-session capacity, ticket types with tiers, sales windows and
held inventory, promo codes, group registration with attendee assignment, waitlists that
promote themselves inside the cancelling transaction, approvals and transfers, **QR check-in
that works with no signal**, ICS calendar files and scheduled reminders, a public surface with
`Event` structured data, and registration questions authored in the forms builder rather than
reinvented. Payment is not rebuilt — a registration order *is* a Direct Payments invoice.
