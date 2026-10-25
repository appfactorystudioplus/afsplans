# 07 — Registration, Attendees and Questions

Builds on [02](02-site-users-guard.md), which established the registration row, the
guest/account split, the `access_token` rule and the capacity principle. This document is the
flow around it: orders, group registration, custom questions, approval, waitlists and transfers.

## 7.1 The flow

```
  browse occurrence
        │
  select quantities per ticket type
        │  ── HOLD claimed (06 §6.6), countdown starts
  purchaser details
        │
  per-attendee details + custom questions (07 §7.4)
        │
  promo code (06 §8)
        │
  ┌─────┴─────┐
  │ free/comp │ paid ──► Direct Payments invoice ──► payment element ──► confirmed (08)
  └─────┬─────┘                                                             │
        └──────────────────────┬──────────────────────────────────────────┘
                               │  ONE transaction
                    order paid · hold → registrations · counters moved
                               │
                    queued effects: confirmation · ICS · organiser notice (10)
```

Two properties this flow is built to guarantee: **a place is reserved before details are
collected**, and **the hold-to-registration conversion is one transaction**
([06](06-ticketing-and-capacity.md) §6.5).

## 7.2 Order and registration

One purchase of four tickets is **one order and four registrations**
([04](04-database-schema.md) §4.10, §4.11).

| Order | Registration |
|---|---|
| The purchaser | The attendee |
| One payment, one invoice, one total | One place, one ticket, one QR |
| Promo code, discount, fees, tax | Ticket type, tier, price snapshot |
| Cancelled as a whole | Cancelled, transferred or refunded individually |

Conflating them — which the current module does by having neither — makes "transfer one of my
four tickets", "one attendee cancelled", "each attendee answers their own questions" and "who
actually turned up" all inexpressible.

### Group registration

Four modes, and a tenant needs at least three of them:

| Mode | Behaviour |
|---|---|
| **Full details now** | The purchaser enters every attendee's name and email up front |
| **Assign later** | Registrations created as placeholders with a claim link each; the purchaser distributes them ([§7.3](#73-attendee-identity)) |
| **Self-registration link** | One link, N uses, each registrant fills their own details |
| **Purchaser only** | One person, N places, no per-attendee data (a table of 10 at a dinner) |

"Assign later" is the one that gets skipped and is the one a company booking ten places actually
needs — nobody has ten colleagues' details to hand at the moment they pay.

An unassigned registration is a **first-class state**: it holds a place, it appears in counts, it
has a claim token, and it is visible in the admin as unassigned so an organiser can chase it.

## 7.3 Attendee identity

Three fields are **native columns**, not form answers ([03](03-architecture.md) §3.4):

`attendee_name` · `attendee_email` · `attendee_phone` (+ optional `attendee_company`)

They appear in every list, export, badge, ticket and email. Making them form answers would mean
every one of those resolves a form version first, for data every event collects.

**They are snapshots** ([02](02-site-users-guard.md) §2.4). A person may register a colleague,
correct a typo for one event, or change their account email later — none of which should rewrite
a registration a badge has been printed from.

A signed-in registrant gets the form **prefilled** from their account, and the values are still
copied onto the row.

## 7.4 Custom questions — the forms module

The reuse decision ([00](00-overview.md) §0.6 D4). Events does not grow a question builder; it
attaches one.

### The attachment

| Level | Field |
|---|---|
| Event | `registration_form_ref` — the default |
| Ticket type | `registration_form_ref` — overrides it |
| Ticket type | `form_scope` — `per_order` or `per_attendee` |

`per_attendee` for dietary requirements and t-shirt size; `per_order` for the invoice address
and how you heard about us. Both on one event is normal, which is why the scope is per form
attachment rather than per event.

### What is stored

- The answers are a **forms submission**
  ([forms/04](../forms/04-database-schema.md) §4.4), referenced from the registration
  (`form_submission_ref`) or the order (`order_form_submission_ref`).
- The **published form version** is pinned (`form_version_id`), so editing the form later never
  changes what an existing registration answered
  ([forms/09](../forms/09-publishing-and-versioning.md) §9.3).
- Answers are **mirrored** into `registrations.custom_fields` as flat key/value pairs, so an
  export, a badge and an attendee list do not each have to resolve a form version
  ([13](13-reporting-and-analytics.md) §13.5). The submission stays authoritative; the mirror is
  an index, the same relationship forms draws between its document and its answer rows
  ([forms/00](../forms/00-overview.md) §0.6 D3).

### What events gets for free

Conditional questions (*"if attending the dinner, ask dietary requirements"*), validation,
multi-language registration, required-question enforcement server-side, and a question editor
that already exists — all specified in
[forms/06](../forms/06-logic-and-validation.md) and [forms/08](../forms/08-multi-language.md).

### The bounded dependency

- An event with no custom questions **never touches the forms module**. No form, no submission,
  no version.
- Events reads the forms module's public contract, not its internals.
- If the form is missing or its version was deleted, the registration still renders — answers
  raw, with a banner ([03](03-architecture.md) §3.10). **A registration is always readable.**
- Deleting a form version referenced by a registration is refused
  ([04](04-database-schema.md) §4.15).

### Session selection

Which workshops an attendee picked is **not** a form question — it is inventory
([06](06-ticketing-and-capacity.md) §6.5). It has capacity, it can sell out, and it must be
claimed atomically. It renders in the same step for the registrant, and it is stored in
`session_refs` with its own counters ([05](05-event-model-and-scheduling.md) §5.4).

Modelling session choice as a form dropdown is the mistake to avoid: a form answer cannot be
sold out.

## 7.5 Snapshots

`ticket_snapshot` ([04](04-database-schema.md) §4.11.1) freezes the event name, occurrence time
and zone, venue, ticket type name, price and currency at the moment of registration.

Tickets, badges, confirmations and exports read the snapshot. The live records are for operating
the event. Rename a ticket type in June and a March registration still says what it said — which
is the property [forms **F17**](../forms/01-current-state-audit.md) documents the absence of, and
the reason it appears here as schema rather than as a later fix.

## 7.6 Approval

`requires_approval` on the occurrence or the ticket type — for member-vetted events, applications
and anything where the organiser chooses who comes.

| Step | |
|---|---|
| Register | Registration created `pending` / `approval_status = pending` |
| Capacity | **Held, not registered.** A pending application reserves a place with a longer TTL, so approving does not fail because the event filled |
| Payment | **Not taken.** Charging before approval means refunding every rejection |
| Approve | Place converted, payment requested by emailed link (paid) or confirmed immediately (free) |
| Reject | Place released, applicant notified with an optional reason |
| Expiry | An unapproved application expires per setting and releases its place |

The "hold, do not charge" rule is the whole design. The alternative — charge and refund on
rejection — costs the tenant fees on every rejection and looks, to the applicant, like being
charged for being turned down.

## 7.7 Waitlists

`laravel_event_waitlist` ([04](04-database-schema.md) §4.13). Completes
[02](02-site-users-guard.md) §2.6.

| Property | |
|---|---|
| Joining | When the occurrence or a specific ticket type is full and `allow_waitlist` |
| Ordering | `position`, first-come. Never reordered by an operator without an audit entry |
| Visibility | The entrant is told their position when `notify_position` |
| Promotion | On any cancellation, expiry or capacity increase |
| **Offer, not a place** | Promotion sends a time-limited claim link, `offer_ttl_hours` (default 24) |
| Offer expiry | The offer lapses, the next entry is offered, and the place is never left unclaimed |
| Conversion | The claim link runs the normal registration flow, with the place already held |

### The transaction rule

Promotion happens **in the same transaction as the cancellation that freed the place**
([02](02-site-users-guard.md) §2.6). Otherwise two cancellations promote the same entry, or a
freed place is claimed by a passer-by refreshing the public page while the waitlist waits.

### Offers, not automatic registration

Automatically registering a waitlisted person — and charging them — for an event they enquired
about three weeks ago is not acceptable for a paid event, and is presumptuous for a free one.
The offer is a claim link with a deadline. When the event is free and
`settings.waitlist.auto_promote` is explicitly enabled by the tenant, direct promotion is
allowed, and the entrant is told.

**Held during an offer.** The place is reserved for the offer's duration
([06](06-ticketing-and-capacity.md) §6.6), so someone accepting a two-hour-old offer is not told
the event is full.

## 7.8 Transfers, changes and cancellation

The lifecycle after registration, which is where most support load lands.

### Name change

Editing the attendee on a registration. Allowed until `settings.registration.allow_transfer` says
otherwise, or until a cutoff before the event. Recorded in the activity log; a new ticket is
issued and **the old `ticket_code` is invalidated** — otherwise the original holder still has a
scannable ticket.

### Transfer to another person

A first-class action, not a name change: the registration is marked `transferred`, a new
registration is created for the recipient with `transferred_from_ref` set, and the ticket is
reissued.

Distinguishing the two matters for reporting — a transfer is one person replacing another, and
the audit trail should say so.

### Transfer to another occurrence

Moving from the March session to April. Requires:

- a place claimed on the target occurrence (**atomically**, §6.5) **before** the source is
  released,
- a price difference handled — refund, additional charge, or nothing per policy
  ([08](08-payments-and-refunds.md) §8.9),
- both occurrences' counters updated in one transaction, and waitlist promotion triggered on the
  source.

Doing this in the wrong order — release then claim — loses the attendee's place when the target
is full.

### Self-service cancellation

`settings.registration.allow_self_cancel` and `self_cancel_until_hours`. Reachable by
`access_token` or as the signed-in owner ([02](02-site-users-guard.md) §2.5). Refund per policy
([08](08-payments-and-refunds.md) §8.8). Releases the place and promotes the waitlist in the same
transaction.

A registrant who cannot cancel emails the organiser, who cancels manually — so the feature's
absence does not remove the work, it moves it to a human.

### Organiser actions

Cancel with or without refund, refund without cancelling (a goodwill gesture), reissue a ticket,
resend a confirmation, mark attended, add an internal note. All logged with the actor
([04](04-database-schema.md) §4.13).

## 7.9 What the attendee can see and do

The attendee page, reachable by `access_token` (guest) or as the signed-in owner
([02](02-site-users-guard.md) §2.5) — **404 for anyone else, never 403**:

- The ticket, with its QR ([09](09-checkin-and-onsite.md) §9.3).
- Event details, venue, directions, accessibility notes.
- The online join link, once released ([05](05-event-model-and-scheduling.md) §5.7).
- Add to calendar ([10](10-communications.md) §10.4).
- Their answers, and — where the tenant allows — the ability to amend them before a cutoff.
- Their session choices, changeable while capacity allows.
- Transfer, cancel, download an invoice.

With Site Users on, the same content appears under "my events" without a token
([02](02-site-users-guard.md) §2.3), and survives a lost email — which is the clearest benefit
an account offers an attendee and the honest way to sell it.

## 7.10 Duplicate and abuse control

The public registration endpoint accepts anonymous writes, so it needs the same posture as any
other ([forms/02](../forms/02-security-and-tenancy.md) §2.8):

| Control | |
|---|---|
| Throttle | Per IP and per occurrence |
| Idempotency key | Generated at hold, resent on retry — collapses double-submits ([04](04-database-schema.md) §4.10) |
| One hold per session per occurrence | Stops one visitor exhausting inventory by reloading |
| One email per occurrence | Configurable ([04](04-database-schema.md) §4.11.2) |
| Honeypot + minimum time | Flag, never silently reject |
| Captcha | Optional, per event, off by default |
| Email verification | Optional; for free events, the main defence against a fake waitlist |

**Free events are the abuse target**, not paid ones — payment is its own filter. An event whose
capacity can be exhausted by a script with no card is the case these controls exist for.

## 7.11 Acceptance

- Four tickets bought at once produce one order and four registrations.
- One of those four can be cancelled without affecting the other three.
- "Assign later" produces four unassigned registrations, each with a working claim link.
- An unassigned registration holds a place and is visible as unassigned in the admin.
- A ticket type with a per-attendee form asks each attendee separately; a per-order form asks
  once.
- Editing the form after registration does **not** change what an existing registration
  answered.
- An event with no custom questions creates no form submission.
- A registration whose form version is missing still renders, with a banner.
- Session choices are claimed atomically and a full workshop refuses the 31st attendee.
- An approval-required registration **holds** a place and is **not charged** until approved.
- Rejecting an application releases the place and notifies the applicant.
- A cancellation promotes exactly one waitlist entry, in the same transaction.
- A waitlist offer holds the place for its TTL, then passes to the next entry.
- An expired offer never leaves a place unclaimed.
- Transferring to another occurrence claims the target **before** releasing the source, and
  fails safely when the target is full.
- A transferred ticket's original `ticket_code` no longer scans.
- Self-cancellation past the cutoff is refused with the policy stated.
- An attendee page is reachable by token and by owner, and **404s** for anyone else.
- The same idempotency key submitted twice creates one order.
