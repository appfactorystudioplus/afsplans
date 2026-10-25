# 04 — Database Schema

One table becomes fourteen. Nothing here is a migration of existing data — nothing has ever been
written server-side (**V8**) — so the schema is designed rather than negotiated.

## 4.1 The shape

```
laravel_events                    the recurring identity              (altered)
laravel_event_occurrences         one instance at one time            (new)   05
laravel_event_sessions            sub-items inside an occurrence      (new)   05 §5.4
laravel_venues                    physical / online / hybrid          (new)   05 §5.6
laravel_event_ticket_types        a class of admission                (new)   06
laravel_event_ticket_tiers        a time/quantity-bounded price       (new)   06 §6.3
laravel_event_holds               inventory claimed, not yet paid     (new)   06 §6.6
laravel_event_promo_codes         discounts                           (new)   06 §6.8
laravel_event_orders              one transaction                     (new)   07 §7.2
laravel_event_registrations       one person's place                  (new)   02 §2.1, 07
laravel_event_checkins            an attendance record                (new)   09
laravel_event_waitlist            an ordered queue with offers        (new)   07 §7.7
laravel_event_people              speakers, hosts, sponsors           (new)   05 §5.8
laravel_event_activity            what happened, to what              (new)   §4.13
```

[02](02-site-users-guard.md) §2.1 sketched `laravel_event_registrations` and the columns
`laravel_events` gains. This document is that schema completed: **the registration columns from
§2.1 are kept**, and what changes is where they hang — a registration now points at an
*occurrence* and a *ticket type*, not at an event.

## 4.2 `laravel_events` — altered

The event is the **identity**, not the date. Everything time-bound moves to the occurrence.

```php
laravel_events
    id
    reference_num          string(32) unique          ← was 20; server-generated (V9)
    slug                   string(160) nullable       ← public URL segment
    name                   string
    subtitle               string(255) nullable       ← the UI's "title" (V19)
    description            longText nullable
    summary                string(500) nullable       ← listings and meta description
    status                 enum draft|published|cancelled|archived   default draft
    visibility             enum public|unlisted|private   default public
    category               string(64) nullable
    tags                   json nullable
    timezone               string(64)                 ← IANA, e.g. Europe/London   05 §5.5
    currency               string(3)                  ← stamped onto orders        03 §3.6
    default_venue_id       unsignedBigInteger nullable
    organiser_name         string(255) nullable
    organiser_email        string(320) nullable
    banner_image           string(1024) nullable
    images                 json nullable
    direct_payment_reference_num  string(32) nullable ← V4, made real in 08 §8.4
    registration_form_ref  string(32) nullable        ← default form for the event  07 §7.4
    site_users_mode        enum off|guest|required default off   ← 02 §2.8
    settings               json nullable              ← §4.12
    payload                json nullable              ← kept: presentational extras
    website_domain         string(255) nullable index
    website_reference_num  string(64) nullable index
    created_by / updated_by  unsignedBigInteger nullable
    published_at           timestamp nullable
    deleted_at             timestamp nullable
    timestamps

    index  (website_reference_num, status, deleted_at)
    unique (website_reference_num, slug)
```

**`event_date` is gone.** It is the column that makes a recurring event impossible
([03](03-architecture.md) §3.2), and it moves to `occurrences.starts_at`.

`archived` (tinyint) folds into `status`. Two overlapping lifecycle flags is how a UI ends up
showing an archived event as live.

## 4.3 `laravel_event_occurrences` — new

**The thing a person registers for.**

```php
laravel_event_occurrences
    id
    reference_num          string(32) unique
    event_reference_num    string(32) index
    sequence               unsignedInteger            ← 1, 2, 3 … within the series
    starts_at              timestamp                  ← UTC        05 §5.5
    ends_at                timestamp
    timezone               string(64)                 ← inherited, overridable
    all_day                boolean default false
    doors_open_at          timestamp nullable
    venue_id               unsignedBigInteger nullable   ← overrides the event default
    status                 enum scheduled|cancelled|postponed|completed  default scheduled
    cancellation_reason    string(500) nullable
    capacity               unsignedInteger nullable   ← null = unlimited
    reserved_count         unsignedInteger default 0  ← holds       06 §6.6
    registered_count       unsignedInteger default 0  ← claimed     06 §6.5
    attended_count         unsignedInteger default 0  ← 09
    waitlist_count         unsignedInteger default 0
    registration_opens_at  timestamp nullable
    registration_closes_at timestamp nullable
    allow_waitlist         boolean default false
    requires_approval      boolean default false
    online_url             string(1024) nullable      ← 05 §5.7
    online_url_release     enum immediate|before_start|on_start|manual  default before_start
    online_url_release_minutes  unsignedInteger nullable
    overrides              json nullable              ← which fields diverge from the series  05 §5.3
    title_override         string(255) nullable
    notes                  text nullable              ← internal
    timestamps

    unique (event_reference_num, sequence)
    index  (starts_at, status)
    index  (event_reference_num, starts_at)
```

Three columns deserve explanation.

**`reserved_count` and `registered_count` are separate.** A hold reserves without registering; a
paid order converts one into the other. Collapsing them means an abandoned checkout permanently
consumes a seat, which is [06](06-ticketing-and-capacity.md) §6.6's whole subject.

**`overrides` records divergence.** When an occurrence is edited individually, the changed field
names are recorded here so that a later "edit this and following" does not silently overwrite a
deliberate exception ([05](05-event-model-and-scheduling.md) §5.3).

**The counters are cached and reconciled.** They exist because the conditional `UPDATE` that
claims capacity needs a single row to lock ([06](06-ticketing-and-capacity.md) §6.5). A cached
counter nothing verifies is a counter that drifts, so §4.14 makes reconciliation a scheduled job
with a monitored disagreement count.

## 4.4 `laravel_event_sessions` — new

```php
laravel_event_sessions
    id
    reference_num             string(32) unique
    occurrence_reference_num  string(32) index
    parent_session_ref        string(32) nullable      ← nested tracks
    title                     string(255)
    description               text nullable
    track                     string(64) nullable
    starts_at / ends_at       timestamp                ← UTC, within the occurrence
    room                      string(160) nullable
    venue_id                  unsignedBigInteger nullable
    capacity                  unsignedInteger nullable
    registered_count          unsignedInteger default 0
    attended_count            unsignedInteger default 0
    requires_registration     boolean default false    ← 05 §5.4
    session_type              string(32) nullable      ← talk | workshop | break | keynote
    online_url                string(1024) nullable
    sort_order                unsignedInteger default 0
    timestamps

    index (occurrence_reference_num, starts_at)
```

`requires_registration` is the switch between an agenda item (informational) and a bookable
workshop with its own 30 places. Both are common; conflating them means every break in the
agenda has a capacity.

## 4.5 `laravel_venues` — new

```php
laravel_venues
    id
    reference_num          string(32) unique
    website_reference_num  string(64) index
    name                   string(255)
    type                   enum physical|online|hybrid   default physical
    address_line1 / line2 / city / region / postcode / country
    latitude / longitude   decimal(10,7) nullable
    timezone               string(64) nullable          ← defaults an occurrence's zone
    capacity               unsignedInteger nullable     ← the room's real limit
    directions             text nullable
    accessibility_notes    text nullable
    parking_notes          text nullable
    map_image              string(1024) nullable
    online_platform        string(64) nullable          ← zoom | meet | teams | custom
    timestamps
```

A venue is a reusable record because a tenant runs most of their events in the same handful of
places, and re-typing an address per occurrence is how three spellings of one venue end up in
the export. `venues.capacity` also gives the admin an honest warning when an occurrence's
capacity exceeds the room's.

## 4.6 `laravel_event_ticket_types` — new

```php
laravel_event_ticket_types
    id
    reference_num             string(32) unique
    occurrence_reference_num  string(32) index
    event_reference_num       string(32) index          ← denormalised for series-wide types
    name                      string(160)
    description               text nullable
    kind                      enum paid|free|donation|allocation   default paid
    price_cents               integer default 0         ← V15: the price lives HERE
    currency                  string(3)
    fee_mode                  enum absorb|pass_on default absorb   ← 08 §8.5
    capacity                  unsignedInteger nullable  ← null = share the occurrence pool
    reserved_count            unsignedInteger default 0
    registered_count          unsignedInteger default 0
    sales_start_at            timestamp nullable
    sales_end_at              timestamp nullable
    min_per_order             unsignedInteger default 1
    max_per_order             unsignedInteger default 10
    visibility                enum public|hidden|code_required   default public
    access_code               string(64) nullable       ← 06 §6.8
    requires_approval         boolean default false
    registration_form_ref     string(32) nullable       ← overrides the event's   07 §7.4
    form_scope                enum per_order|per_attendee default per_attendee
    direct_payment_item_reference_num  string(32) nullable   ← the existing UI's mapping
    sort_order                unsignedInteger default 0
    deleted_at                timestamp nullable
    timestamps

    index (occurrence_reference_num, visibility, sales_start_at)
```

`price_cents` on the ticket type is the fix for **V15**: today the price exists only on the
Direct Payment item and the UI discards it, so nothing in the module knows what a ticket costs.
The DP item mapping is **kept** — it is the right linkage — and
[08](08-payments-and-refunds.md) §8.4 specifies which of the two prices wins and how a mismatch
is surfaced.

`kind = allocation` covers comps, press and speaker tickets: never publicly purchasable, issued
by an operator ([06](06-ticketing-and-capacity.md) §6.7).

## 4.7 `laravel_event_ticket_tiers` — new

```php
laravel_event_ticket_tiers
    id
    reference_num            string(32) unique
    ticket_type_reference_num string(32) index
    name                     string(160)              ← "Early Bird"
    price_cents              integer
    starts_at / ends_at      timestamp nullable       ← time-bounded
    quantity                 unsignedInteger nullable ← quantity-bounded
    sold_count               unsignedInteger default 0
    sort_order               unsignedInteger default 0
    timestamps
```

Early Bird until 1 Oct **or** the first 100, whichever ends first — the two bounds are
independent and both are common ([06](06-ticketing-and-capacity.md) §6.3). A type with no tiers
uses `ticket_types.price_cents`, so there is one price-resolution path, never two.

## 4.8 `laravel_event_holds` — new

The table that makes capacity honest.

```php
laravel_event_holds
    id
    reference_num             string(32) unique       ← the client's handle
    occurrence_reference_num  string(32) index
    ticket_type_reference_num string(32) index
    tier_reference_num        string(32) nullable
    quantity                  unsignedInteger
    unit_price_cents          integer                 ← captured AT hold      06 §6.6
    currency                  string(3)
    session_token             string(64) index        ← anonymous holder
    site_user_id              unsignedBigInteger nullable index
    order_reference_num       string(32) nullable index
    status                    enum active|converted|released|expired  default active
    expires_at                timestamp index
    timestamps

    index (occurrence_reference_num, status, expires_at)
```

`unit_price_cents` captured at hold time is what lets the checkout honour the price the
registrant was shown even if a tier rolled over mid-checkout
([03](03-architecture.md) §3.10) — without it, a registrant selects Early Bird and is charged
Standard.

Expiry is enforced by a sweeper **and** checked at conversion, because a job that runs every
minute is not a guarantee at second 59.

## 4.9 `laravel_event_promo_codes` — new

Replaces the client-only `DiscountCode` store (**V21**).

```php
laravel_event_promo_codes
    id
    reference_num          string(32) unique
    website_reference_num  string(64) index
    event_reference_num    string(32) nullable index   ← null = all the tenant's events
    code                   string(64)
    discount_type          enum percent|fixed
    discount_value         integer                     ← percent points, or cents
    applies_to             enum order|ticket_type
    ticket_type_refs       json nullable
    min_quantity           unsignedInteger nullable
    min_subtotal_cents     integer nullable
    max_uses               unsignedInteger nullable
    max_uses_per_email     unsignedInteger nullable
    used_count             unsignedInteger default 0
    starts_at / ends_at    timestamp nullable
    active                 boolean default true
    timestamps

    unique (website_reference_num, code)
```

`used_count` is incremented by the same conditional-update pattern as capacity
([06](06-ticketing-and-capacity.md) §6.8) — a promo code limited to 100 uses that is checked and
then incremented is a promo code that will be used 130 times the day it is posted publicly.

## 4.10 `laravel_event_orders` — new

One transaction. One payment. One or more registrations.

```php
laravel_event_orders
    id
    reference_num             string(32) unique
    occurrence_reference_num  string(32) index
    event_reference_num       string(32) index
    site_user_id              unsignedBigInteger nullable index
    purchaser_name            string(255)
    purchaser_email           string(320) index
    purchaser_phone           string(64) nullable
    status                    enum pending|paid|failed|cancelled|refunded|partially_refunded  default pending
    subtotal_cents            integer default 0
    discount_cents            integer default 0
    fee_cents                 integer default 0
    tax_cents                 integer default 0
    total_cents               integer default 0
    refunded_cents            integer default 0
    currency                  string(3)
    promo_code                string(64) nullable
    promo_code_reference_num  string(32) nullable
    direct_payment_invoice_ref  string(64) nullable unique   ← idempotency   08 §8.6
    direct_payment_charge_ref   string(64) nullable
    order_form_submission_ref string(32) nullable            ← per-order answers  07 §7.4
    ip_address                string(45) nullable
    user_agent                string(512) nullable
    source                    string(64) nullable            ← where the registrant came from
    locale                    string(12) nullable
    idempotency_key           string(64) nullable
    completed_at              timestamp nullable
    cancelled_at              timestamp nullable
    timestamps

    index  (occurrence_reference_num, status, created_at)
    unique (occurrence_reference_num, idempotency_key)
```

**Order and registration are separate** ([00](00-overview.md) §0.4). Four tickets bought at once
is one order and four registrations, which is what makes "transfer one of my four tickets", "one
attendee cancelled", and "each attendee answers their own questions" expressible at all.

`unique(direct_payment_invoice_ref)` is the idempotency guarantee: a retried confirmation cannot
create a second order.

## 4.11 `laravel_event_registrations` — new

Completing [02](02-site-users-guard.md) §2.1. Its columns are kept; what changes is that a
registration hangs off an **occurrence and a ticket type**, and off an **order**.

```php
laravel_event_registrations
    id
    reference_num             string(32) unique
    order_reference_num       string(32) nullable index    ← null for allocations/comps
    occurrence_reference_num  string(32) index
    event_reference_num       string(32) index
    ticket_type_reference_num string(32) index
    tier_reference_num        string(32) nullable
    site_user_id              unsignedBigInteger nullable index    ← guest = null   02 §2.3
    access_token              string(64) unique            ← the emailed link       02 §2.5
    ticket_code               string(64) unique            ← the QR payload         09 §9.3

    attendee_name             string(255)                  ← SNAPSHOTS, not references  02 §2.4
    attendee_email            string(320) index
    attendee_phone            string(64) nullable
    attendee_company          string(255) nullable

    status                    enum pending|confirmed|waitlisted|cancelled|attended|no_show|transferred  default confirmed
    approval_status           enum not_required|pending|approved|rejected  default not_required
    approval_note             string(500) nullable

    ticket_snapshot           json                         ← §4.11.1
    amount_cents              integer default 0
    currency                  string(3)
    refunded_cents            integer default 0

    form_submission_ref       string(32) nullable          ← forms/04 §4.4         07 §7.4
    form_version_id           unsignedBigInteger nullable
    custom_fields             json nullable                ← mirrored answers, for export

    session_refs              json nullable                ← chosen sessions       05 §5.4
    seat_section / seat_table string(64) nullable          ← 06 §6.9

    checked_in_at             timestamp nullable
    checked_in_by             unsignedBigInteger nullable
    transferred_from_ref      string(32) nullable          ← 07 §7.8
    transferred_to_ref        string(32) nullable
    cancelled_at              timestamp nullable
    cancel_reason             string(500) nullable
    registered_at             timestamp
    deleted_at                timestamp nullable
    timestamps

    unique (occurrence_reference_num, attendee_email, deleted_at)   ← §4.11.2
    index  (occurrence_reference_num, status)
    index  (event_reference_num, attendee_email)
    index  (ticket_code)
```

### 4.11.1 `ticket_snapshot`

What was bought, frozen:

```json
{ "event_name": "Annual Developer Conference",
  "occurrence_starts_at": "2026-11-14T09:00:00Z",
  "timezone": "Europe/London",
  "venue": { "name": "Kings Place", "city": "London" },
  "ticket_type_name": "Early Bird — General",
  "unit_price_cents": 15000,
  "currency": "GBP" }
```

Rename the ticket type, move the venue, or change the price, and a registration made last month
still says what it said. This is [forms **F17**](../forms/01-current-state-audit.md) — a record
that becomes unreadable when the thing it referenced changed — answered before it happens.
Badges, tickets and exports read the snapshot; the live record is for operating the event.

### 4.11.2 The one-email-per-occurrence rule

[02](02-site-users-guard.md) §2.1 introduced
`unique(event_reference_num, attendee_email)`. Two corrections now that the schema is complete:

- It is keyed on the **occurrence**, not the event. One person attending both the March and
  April sessions of a class must be able to register twice.
- It includes `deleted_at`, so a cancelled-and-purged registration does not permanently block
  that address from re-registering.

The rule itself stands, and it remains **configurable per event**
(`settings.registration.one_per_email`, §4.12) — a company booking ten places under one billing
address is a real case, and it is served by `quantity` on the order plus distinct attendee
emails, or by turning the rule off.

## 4.12 `settings` — what is not schema

Per-event behaviour that is ours, not the domain's, and must not require a migration to change:

```json
{
  "registration": { "one_per_email": true, "collect_phone": true, "collect_company": false,
                    "allow_transfer": true, "allow_self_cancel": true,
                    "self_cancel_until_hours": 48 },
  "waitlist":     { "offer_ttl_hours": 24, "auto_promote": true, "notify_position": true },
  "holds":        { "ttl_minutes": 15 },
  "checkin":      { "allow_walkins": true, "scan_window_hours_before": 4,
                    "allow_duplicate_scan": false, "badge_template": "default" },
  "notify":       { "confirmation": true, "reminders": [ { "hours_before": 168 },
                                                         { "hours_before": 24 } ],
                    "followup_hours_after": 24, "organiser_on_registration": true },
  "policy":       { "refund": "full_until_hours", "refund_until_hours": 168,
                    "terms_url": null },
  "display":      { "show_remaining": true, "show_remaining_below": 20,
                    "show_attendee_count": false }
}
```

`display.show_remaining_below` is worth naming: showing "412 places left" is noise, showing
"6 places left" is information, and the threshold is a tenant's choice.

## 4.13 `laravel_event_checkins`, `_waitlist`, `_people`, `_activity`

```php
laravel_event_checkins
    id · reference_num · registration_reference_num index
    occurrence_reference_num index · session_reference_num nullable index
    direction enum in|out default in          ← session scanning       09 §9.7
    scanned_at timestamp · synced_at timestamp nullable   ← offline    09 §9.5
    device_id string(64) nullable · scanned_by unsignedBigInteger nullable
    method enum qr|manual|walkin|import default qr
    conflict boolean default false            ← duplicate offline scan  09 §9.6
    timestamps
    unique (registration_reference_num, session_reference_num, direction, scanned_at)

laravel_event_waitlist
    id · reference_num · occurrence_reference_num index
    ticket_type_reference_num nullable · position unsignedInteger
    name · email index · phone nullable · quantity default 1
    site_user_id nullable · access_token string(64) unique
    status enum waiting|offered|converted|expired|declined|cancelled  default waiting
    offered_at nullable · offer_expires_at nullable index
    registration_reference_num nullable        ← set on conversion
    timestamps
    unique (occurrence_reference_num, email)
    index  (occurrence_reference_num, status, position)

laravel_event_people
    id · reference_num · website_reference_num index
    name · role enum speaker|host|performer|sponsor|organiser
    bio text nullable · photo · title · company
    links json nullable                        ← website, social
    timestamps
    -- pivot: event_person (event/occurrence/session ref, person ref, role, sort_order)

laravel_event_activity
    id · subject_type string(32) · subject_reference_num string(32) index
    type string(64)        ← order.created, payment.confirmed, registration.cancelled,
                           ← waitlist.offered, checkin.recorded, occurrence.cancelled,
                           ← email.sent, email.failed, refund.issued, transfer.completed
    actor_type enum system|admin|attendee nullable · actor_id nullable
    payload json nullable · created_at timestamp index
```

`laravel_event_activity` is what makes "was this attendee emailed, and when" answerable — the
question a support desk asks constantly and which
[forms **F56**](../forms/01-current-state-audit.md) documents as unanswerable in its sibling
module. It is written by the effects pipeline ([10](10-communications.md) §10.2) and read by the
registration detail view ([12](12-admin-dashboard.md) §12.6).

## 4.14 Counters, and keeping them honest

Six cached counters exist: `occurrences.reserved_count`, `.registered_count`, `.attended_count`,
`.waitlist_count`, `ticket_types.reserved_count`, `.registered_count`, plus
`tiers.sold_count` and `promo_codes.used_count`.

| Rule | |
|---|---|
| **Every write is a conditional `UPDATE`** | [06](06-ticketing-and-capacity.md) §6.5 |
| **Never `SELECT count(*)` then `INSERT`** | The race is the bug |
| **Reconciled on a schedule** | `events:reconcile-counters`, hourly |
| **Disagreements are a monitored metric** | Not a log line. A counter drifting is an inventory bug, and it should page someone before it oversells |
| **Reconciliation corrects the counter, never the registrations** | The rows are the truth; the counter is the cache |

## 4.15 Integrity

```
events ──1:N──► occurrences ──1:N──► sessions
occurrences ──1:N──► ticket_types ──1:N──► tiers
occurrences ──1:N──► holds
occurrences ──1:N──► orders ──1:N──► registrations
registrations ──1:N──► checkins
occurrences ──1:N──► waitlist
```

Following the codebase's convention, joins are on **string references** rather than ids, and the
new tables keep it. Three exceptions where a real FK earns its place:

- `occurrences.event_reference_num` → `events.reference_num`, `ON DELETE RESTRICT`.
- `registrations.order_reference_num` → `orders.reference_num`, `ON DELETE RESTRICT`.
- `registrations.form_version_id` → `laravel_form_versions.id`, `ON DELETE RESTRICT` — **a form
  version referenced by a registration can never be deleted**
  ([forms/09](../forms/09-publishing-and-versioning.md) §9.4).

Deletion is soft everywhere. Purge is an explicit, audited action that also removes
form submissions and uploaded files, and it is the GDPR-erasure path
([13](13-reporting-and-analytics.md) §13.8).

## 4.16 Migration order

Additive, reversible, one migration per table:

1. `alter laravel_events` — add columns; **`event_date` is dropped last**, after §4.17's import
2. `create laravel_venues`
3. `create laravel_event_occurrences`
4. `create laravel_event_sessions`
5. `create laravel_event_ticket_types`
6. `create laravel_event_ticket_tiers`
7. `create laravel_event_holds`
8. `create laravel_event_promo_codes`
9. `create laravel_event_orders`
10. `create laravel_event_registrations`
11. `create laravel_event_checkins`
12. `create laravel_event_waitlist`
13. `create laravel_event_people` + pivot
14. `create laravel_event_activity`
15. add the three foreign keys
16. `alter laravel_events` — drop `event_date`, `archived`

## 4.17 The only data that exists

`localStorage`, in whatever browsers tenants used (**V8**). There is no server-side data to
migrate — `laravel_events` may hold rows from afs-connect ingest, and nothing else.

So the "migration" is a **one-time import offer** in the admin
([14](14-rollout-and-testing.md) §14.4): on first load of the new events screen, if
`localStorage` holds events, show them and offer to import. Each becomes an event plus one
occurrence, with `startDate`/`startTime`/`endDate`/`endTime` interpreted in the tenant's
configured zone (**V17** — they carry none, so the tenant is asked to confirm) and each ticket
level becoming a ticket type whose `price_cents` is read from the mapped Direct Payment item —
the price the current UI discards (**V15**).

Import is explicit, previewed, and skippable. Silently importing a browser cache into a
tenant's live event catalogue is worse than losing it.

## 4.18 Acceptance

- A weekly class for a year is one event and 52 occurrences, each independently editable.
- Cancelling one occurrence leaves the other 51 scheduled.
- A registration made in March still renders March's ticket name, price and venue after the
  event is renamed and repriced in June.
- `submission_data`-scale answers and a 20-row agenda both persist without truncation.
- Every money column is integer cents; `grep` finds no `decimal` in an events migration.
- An occurrence with `capacity = 50` never has `registered_count > 50`.
- `events:reconcile-counters` reports zero disagreements after a 200-way concurrent
  registration test.
- A promo code with `max_uses = 100` is used exactly 100 times under concurrency.
- A hold that expires releases its `reserved_count` and does not touch `registered_count`.
- Deleting an event with occurrences is refused; archiving it is not.
- A form version referenced by a registration cannot be deleted.
- The `localStorage` import produces events, occurrences and priced ticket types, and is
  skippable.
