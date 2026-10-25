# 03 — Architecture

## 3.1 The layers

```
    ┌───────────────────────────────────────────────────────────────┐
    │  PUBLIC   builder blocks · window.Portal.events · embeds       │  11
    └────────────────────────────┬──────────────────────────────────┘
                                 │
    ┌────────────────────────────▼──────────────────────────────────┐
    │  CATALOGUE   event · occurrence · session · venue · agenda     │  05
    └────────────────────────────┬──────────────────────────────────┘
                                 │
    ┌────────────────────────────▼──────────────────────────────────┐
    │  INVENTORY   ticket types · tiers · holds · allocations        │  06
    │              capacity claimed atomically                       │
    └────────────────────────────┬──────────────────────────────────┘
                                 │  hold → order
    ┌────────────────────────────▼──────────────────────────────────┐
    │  ORDER       lines · attendees · answers · promo codes         │  07
    └────────────────────────────┬──────────────────────────────────┘
                                 │  lock + hand over
    ┌────────────────────────────▼──────────────────────────────────┐
    │  DIRECT PAYMENTS   invoice · payment element · charge          │  08
    └────────────────────────────┬──────────────────────────────────┘
                                 │  confirmed
    ┌────────────────────────────▼──────────────────────────────────┐
    │  REGISTRATION  ticket · QR · transfers · cancellation          │  07 · 08
    └────────────────────────────┬──────────────────────────────────┘
                                 │
    ┌────────────────────────────▼──────────────────────────────────┐
    │  ON-SITE     check-in · session scans · badges                 │  09
    └───────────────────────────────────────────────────────────────┘

    effects (queued, server-side):  mail · ICS · reminders · webhooks   10
    identity (optional, per event): Site Users        →  ../site_users/
    questions (optional, per type): Forms             →  ../forms/
```

Two edges carry most of the design weight: **hold → order**
([06](06-ticketing-and-capacity.md) §6.6), and **confirmed → registration**
([08](08-payments-and-refunds.md) §8.7). Both are places where money and inventory can
disagree, and both are specified as transactions rather than sequences.

## 3.2 Event, occurrence, session

The vocabulary from [00](00-overview.md) §0.4, as a hierarchy:

```
Event  "Annual Developer Conference"          ← identity, description, organiser, images
 └── Occurrence  2026-11-14 09:00 → 18:00 Europe/London    ← WHAT YOU REGISTER FOR
      ├── Ticket type  "General"   £150   capacity 400
      ├── Ticket type  "Student"   £40    capacity 50   requires access code
      ├── Ticket type  "Speaker"   £0     allocation only
      └── Session  "Rust workshop"  14:00–16:00  Room B  capacity 30
           └── registration per session, optional             05 §5.4
```

**Registration attaches to an occurrence and a ticket type**, never to an event. That is the
single structural decision the current schema forecloses with one `event_date` column, and
retrofitting it after registrations exist means rewriting every one of them.

A one-off event is an event with exactly one occurrence. **There is no separate "simple event"
path** — one code path, always, because two paths diverge and the simple one is the one that
grows a capacity bug.

## 3.3 Direct Payments owns money; events owns the event

The same boundary [ecommerce/03](../ecommerce/03-architecture.md) §3.2 draws, and it decides how
much of [08](08-payments-and-refunds.md) is new work.

| Events owns | Direct Payments owns |
|---|---|
| What is on sale, at what price, how many are left | Taking the money |
| Ticket types, tiers, holds, promo rules | The payment element, the provider, the customer record |
| Order composition and totalling **inputs** | Saved cards, subscriptions, splits |
| The registration, its ticket, its lifecycle | The charge and its status |
| Refund **eligibility** (the cancellation policy) | Executing the refund |

**Events never imports a provider SDK, never sees a secret key, never handles a webhook.** It
builds an invoice, hands it over, and records what came back.

The one thing that crosses the line is `events.direct_payment_reference_num` — which Direct
Payment an event charges through (**V4**). Events points *down* at a DP reference; the Direct
Payments layer knows nothing about events. Same direction as everywhere else in this ecosystem.

The existing UI already has this shape: an event selects a Direct Payment, and each ticket level
names an item on it (**V15** notes the price is then discarded, which
[08](08-payments-and-refunds.md) §8.4 fixes).

## 3.4 Events owns the questions it asks; forms owns asking them

The reuse decision ([00](00-overview.md) §0.6 D4), as a boundary:

| Events owns | Forms owns |
|---|---|
| That a ticket type asks questions | The question definitions, logic, validation, translations |
| Which form, and per-attendee vs per-order | Rendering and validating answers |
| The **native** attendee fields — name, email, phone | Everything custom |
| Mirroring answers onto the registration for export | Storing the submission and its version |

Events attaches a **published form version** to a ticket type
([forms/09](../forms/09-publishing-and-versioning.md) §9.3). Editing the form afterwards does
not change what an existing registration answered — which is exactly the property forms'
versioning exists to provide, and the reason to depend on it rather than store a JSON blob of
questions.

**The three fields every event needs are native columns**, not form answers
([07](07-registration-and-attendees.md) §7.3). Name, email and phone appear in every list,
every export, every badge and every email; making them form answers means every one of those
has to resolve a form version first.

## 3.5 Identifiers

| Identifier | Layer | Example |
|---|---|---|
| `event.reference_num` | Event | `evt_a1b2c3d4e5f6` |
| `occurrence.reference_num` | Occurrence | `occ_…` |
| `ticket_type.reference_num` | Inventory | `tkt_…` |
| `order.reference_num` | Order | `ord_…` |
| `registration.reference_num` | Registration | `reg_…` |
| `registration.ticket_code` | The **QR payload** | signed, [09](09-checkin-and-onsite.md) §9.3 |
| `registration.access_token` | The emailed link's credential | [02](02-site-users-guard.md) §2.5 |
| `direct_payment_item_reference_num` | Direct Payments | `itm_…` |

Three rules:

- **The server generates every reference.** The browser currently generates the event reference
  from `Math.random()` and truncates it to 7 characters (**V9**, **V10**).
- **`ticket_code` and `access_token` are credentials, not identifiers.** High-entropy,
  never enumerable, never logged, and never the same value
  ([09](09-checkin-and-onsite.md) §9.3).
- **Events names a DP item and stops there.** Reaching past Direct Payments to a provider price
  is the boundary violation to watch for in review
  ([ecommerce/07](../ecommerce/07-direct-payments-integration.md) §7.4).

## 3.6 Money

**Integer minor units, everywhere, from the first migration.** Every column ends `_cents`.

This is not a preference; it is [ecommerce **E9**](../ecommerce/02-current-state-audit.md)
already paid for once. Mixing `DECIMAL(10,2)` with the integer cents the processor layer uses
guarantees a rounding disagreement between the number a registrant saw and the number they were
charged — and that surfaces as a chargeback, not a test failure.

Currency is **per event**, stamped onto every order at creation, and never re-derived. An event's
currency may change; a completed order's cannot.

## 3.7 Time

Fully specified in [05](05-event-model-and-scheduling.md) §5.5. The architectural commitment:

- Every instant is stored **UTC**, in a `timestamp` column.
- Every occurrence carries an **IANA time zone** (`Europe/London`, not `GMT` or `+01:00`).
- Display, ICS generation, reminder scheduling and "is registration open" all resolve through
  that zone.

The current four-loose-strings model (**V17**) cannot express an instant at all, which is why
nothing in the module can schedule a reminder or emit a calendar file.

## 3.8 Multi-site and tenancy

`website_domain` and `website_reference_num` are already on `laravel_events` and — unlike
[forms **F3**](../forms/01-current-state-audit.md) — there are no existing rows to backfill,
because nothing has ever been written server-side.

So the rule is enforceable from the first commit:

- An event belongs to one site; a tenant may own several.
- **Every admin query is scoped by the caller's site set**, through a model scope
  ([02](02-site-users-guard.md) §2.2).
- The tenant is resolved from the **authenticated principal**, never from request input — the
  trap [forms/02](../forms/02-security-and-tenancy.md) §2.3 documents.
- Public reads are scoped by the event's own reference.

## 3.9 Where state lives

The module's current answer is `localStorage` (**V8**). The target:

| State | Home |
|---|---|
| Events, occurrences, ticket types | Server, authoritative |
| Capacity counters | Server, on the row, **reconciled** ([06](06-ticketing-and-capacity.md) §6.5) |
| A registrant's in-progress selection | A server-side **hold** with a TTL, not a client cart ([06](06-ticketing-and-capacity.md) §6.6) |
| Admin editor draft | Server, with dirty tracking; `localStorage` only as crash recovery, keyed by reference |
| Check-in scanner state | **Client**, deliberately — the offline manifest and queue ([09](09-checkin-and-onsite.md) §9.5) |

The hold is the important one. A selection held in the browser cannot reserve inventory, so
either the seat is not reserved (and the registrant loses it at payment) or it is reserved by a
count the client controls. Server-side holds with an expiry are the only shape that is both
honest to the registrant and safe under load.

## 3.10 Failure posture

| Situation | Behaviour |
|---|---|
| Occurrence full at hold | Refuse, offer the waitlist, state the remaining count |
| Occurrence full at checkout | The hold already reserved it — **this cannot happen**, and a test asserts it ([06](06-ticketing-and-capacity.md) §6.6) |
| Hold expires during payment | Attempt to re-claim; if unavailable, fail the order **before** charging and say so plainly |
| Payment fails | Order stays `pending`, the hold is **released**, no registration is created ([08](08-payments-and-refunds.md) §8.6) |
| **Payment succeeds, registration write fails** | Reconciled from the DP invoice ([08](08-payments-and-refunds.md) §8.7). Money taken with no ticket is the one unacceptable outcome |
| Direct Payment unreachable | Fail the checkout; the hold survives to its TTL |
| Ticket price changed between hold and checkout | Honour the **held** price; the hold captured it ([06](06-ticketing-and-capacity.md) §6.6) |
| Occurrence cancelled with registrations | Refunds per policy, everyone notified, registrations marked `cancelled` — never deleted ([08](08-payments-and-refunds.md) §8.8) |
| Scanner offline at the door | Local validation against the signed manifest, queued sync ([09](09-checkin-and-onsite.md) §9.5) |
| Same ticket scanned twice offline on two devices | Both admitted, conflict surfaced at sync — **admitting a real attendee beats blocking one** ([09](09-checkin-and-onsite.md) §9.6) |
| Reminder job fails | Retried; the failure is visible on the occurrence's timeline, never silent |
| Form version referenced by a registration is missing | Answers render raw with a banner. **A registration is always readable** |

Two rows encode the module's values. **Never charge without a place**, and **never turn away
someone holding a valid ticket because our scanner cannot reach the network.** Everything else
is negotiable.

## 3.11 What this plan deliberately does not build

| Not building | Why |
|---|---|
| A payment integration | §3.3 |
| A question builder | §3.4 — [forms](../forms/README.md) |
| An identity system | [site_users](../site_users/README.md) |
| A graphical seat-map designer | Section/table allocation is in scope ([06](06-ticketing-and-capacity.md) §6.9); per-seat holds with a visual editor is a product |
| Streaming or video hosting | A join link with timed release ([05](05-event-model-and-scheduling.md) §5.7) |
| A secondary ticket market | Direct transfer is in scope ([07](07-registration-and-attendees.md) §7.8) |
| Dynamic / demand-based pricing | Tiers by date and quantity ([06](06-ticketing-and-capacity.md) §6.3) cover the real cases |
| A native mobile app | An offline-capable PWA ([09](09-checkin-and-onsite.md) §9.5) |
| Multi-currency presentment | §3.6 |
| Marketing automation | Scheduled reminders and follow-ups ([10](10-communications.md)) |

Each is a defensible product, and each is a reason this plan ends where it does.
