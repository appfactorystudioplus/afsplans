# 04 — Database Schema

Three tables become thirteen. The existing three are altered, not replaced, and the migration
path for existing rows is §4.12.

## 4.1 The shape

```
laravel_appointments             the bookable configuration        (altered)
laravel_appointment_services     what is offered                   (new)   ← was "slots"
laravel_appointment_staff        who delivers it                   (new)   07
laravel_appointment_resources    rooms, chairs, equipment          (new)   07
laravel_appointment_schedules    recurring working hours           (new)   05
laravel_appointment_exceptions   dated overrides and holidays      (new)   05
laravel_appointment_busy_blocks  imported calendar busy time       (new)   10
laravel_appointment_holds        a time reserved for minutes       (new)   06
laravel_appointment_bookings     a confirmed appointment           (altered)
laravel_appointment_slot_counters  capacity counters for group services  (new)   06 §6.3
laravel_appointment_series       a recurring booking               (new)   08
laravel_appointment_waitlist     an ordered queue for a full day   (new)   08
laravel_appointment_credits      packages and session balances     (new)   09
laravel_appointment_activity     what happened, to what            (new)   §4.11
```

Plus two pivots: `service_staff` and `service_resource`.

## 4.2 `laravel_appointments` — altered

The container: a bookable configuration belonging to a site. One tenant may run several — "Clinic
bookings" and "Equipment hire" are different configurations with different staff and rules.

```php
laravel_appointments
    id
    reference_num          string(32) unique          ← server-generated (A18)
    slug                   string(160) nullable       ← public URL segment
    name                   string
    title                  string
    description            text nullable
    status                 enum draft|published|paused|archived  default draft
    timezone               string(64)                 ← IANA. Defaults every schedule   05 §5.6
    currency               string(3)
    location_id            unsignedBigInteger nullable
    direct_payment_reference_num  string(32) nullable ← 09 §9.3
    site_users_mode        enum off|guest|required default off   ← 02 §2.6
    settings               json nullable              ← §4.10
    website_domain         string(255) nullable index
    website_reference_num  string(64) nullable index  ← A5: now written AND filtered on
    created_by / updated_by  unsignedBigInteger nullable
    deleted_at             timestamp nullable         ← A4: soft delete
    timestamps

    index  (website_reference_num, status, deleted_at)
    unique (website_reference_num, slug)
```

**`deleted_at` matters more here than elsewhere.** The migration declares
`cascadeOnDelete()` from both child tables, so today an unauthenticated `DELETE` destroys the
configuration, every service and **every customer's booking** (**A4**). Soft delete removes the
cascade from the normal path entirely; hard delete becomes an explicit purge
([13](13-reporting-and-analytics.md) §13.7).

## 4.3 `laravel_appointment_services` — new (was "slots")

The service half of the split ([03](03-architecture.md) §3.3).

```php
laravel_appointment_services
    id
    reference_num             string(32) unique
    appointment_reference_num string(32) index
    name                      string
    description               text nullable
    duration_minutes          unsignedSmallInteger
    buffer_before_minutes     unsignedSmallInteger default 0    ← 05 §5.5
    buffer_after_minutes      unsignedSmallInteger default 0    ← A13, split in two
    granularity_minutes       unsignedSmallInteger nullable     ← candidate-time step
    capacity                  unsignedSmallInteger default 1    ← A10, now enforced   06 §6.3
    price_cents               integer default 0                 ← integer minor units
    deposit_cents             integer nullable                  ← 09 §9.2
    kind                      enum individual|group|class default individual   ← 07 §7.6
    location_mode             enum onsite|customer|online|any   default onsite ← 07 §7.7
    online_url                string(1024) nullable
    staff_mode                enum any|specific|round_robin|least_booked default any  ← 07 §7.4
    min_lead_minutes          unsignedInteger default 0         ← 05 §5.5
    max_horizon_days          unsignedSmallInteger nullable
    cancel_up_to_hours        unsignedSmallInteger nullable     ← 08 §8.6
    reschedule_up_to_hours    unsignedSmallInteger nullable
    requires_approval         boolean default false             ← 08 §8.4
    booking_form_ref          string(32) nullable               ← forms   08 §8.3
    booking_form_version_id   unsignedBigInteger nullable
    color                     string(16) nullable
    active                    boolean default true              ← A34: now filtered on
    sort_order                unsignedInteger default 0
    deleted_at                timestamp nullable
    timestamps

    index (appointment_reference_num, active, sort_order)
```

Four changes from the current `slots` row worth naming:

| | |
|---|---|
| `buffer_time` → `buffer_before` + `buffer_after` | Travel to a site and cleaning a room after are different quantities, and one number cannot express both (**A13**) |
| `max_bookings` → `capacity`, and **enforced** | **A10** |
| `available_days` / `start_time` / `end_time` **removed** | They move to `schedules` (§4.6). A service delivered by three staff has three sets of hours, which one row cannot hold |
| `price_cents`, `deposit_cents` added | The module has no notion of price at all today |

## 4.4 `laravel_appointment_staff` — new

```php
laravel_appointment_staff
    id
    reference_num             string(32) unique
    appointment_reference_num string(32) index
    name                      string
    email                     string(320) nullable
    phone                     string(64) nullable
    title                     string(160) nullable
    bio                       text nullable
    photo                     string(1024) nullable
    timezone                  string(64) nullable        ← overrides the appointment's
    color                     string(16) nullable
    portal_user_id            unsignedBigInteger nullable ← so staff can see their own diary  12 §12.8
    site_user_id              unsignedBigInteger nullable
    calendar_connection_id    unsignedBigInteger nullable ← 10 §10.6
    max_daily_bookings        unsignedSmallInteger nullable
    active                    boolean default true
    sort_order                unsignedInteger default 0
    deleted_at                timestamp nullable
    timestamps
```

A single-operator tenant gets **one implicit staff member**, created with the appointment and
hidden in the UI ([00](00-overview.md) §0.5 D4). One code path, always — the alternative is a
"no staff" branch that quietly skips every per-staff rule.

`portal_user_id` is what lets a practitioner open the admin and see only their own diary
([12](12-admin-dashboard.md) §12.8), which is the first thing a two-person clinic asks for.

## 4.5 `laravel_appointment_resources` — new

```php
laravel_appointment_resources
    id
    reference_num             string(32) unique
    appointment_reference_num string(32) index
    name                      string                    ← "Room 2", "Chair 3", "Van A"
    type                      string(64) nullable       ← room | equipment | vehicle
    capacity                  unsignedSmallInteger default 1
    location_id               unsignedBigInteger nullable
    active                    boolean default true
    timestamps
```

A resource is consumed by a booking and cannot be double-booked. Two practitioners sharing one
treatment room is the case this exists for, and it is invisible in a staff-only model — the
system offers both practitioners at 10:00 and the room has one bed.

Pivots `service_staff` and `service_resource` (`service_ref`, `staff_ref`/`resource_ref`,
`duration_override_minutes` nullable, `price_override_cents` nullable) — because a senior
practitioner may take longer, or charge more, for the same service.

## 4.6 `laravel_appointment_schedules` — new

The schedule half of the split. **Wall-clock times plus a zone**, never UTC
([03](03-architecture.md) §3.7).

```php
laravel_appointment_schedules
    id
    reference_num             string(32) unique
    appointment_reference_num string(32) index
    owner_type                enum business|staff|resource
    owner_reference_num       string(32) nullable index
    name                      string(160) nullable       ← "Summer hours"
    day_of_week               tinyInteger                ← 0–6
    start_time                time                       ← LOCAL wall clock
    end_time                  time
    timezone                  string(64) nullable        ← inherited when null
    effective_from            date nullable              ← seasonal patterns
    effective_to              date nullable
    active                    boolean default true
    timestamps

    index (appointment_reference_num, owner_type, owner_reference_num, day_of_week)
```

One row per contiguous block per day, so a split shift is two rows — 09:00–12:00 and
13:00–17:00 — which is how a lunch break is expressed without inventing a "break" concept.

`effective_from` / `effective_to` give seasonal hours without deleting and re-entering the
pattern each time.

## 4.7 `laravel_appointment_exceptions` — new

Dated overrides. The single most-requested feature a schedule system needs and the one the
current model cannot express at all.

```php
laravel_appointment_exceptions
    id
    reference_num             string(32) unique
    appointment_reference_num string(32) index
    owner_type                enum business|staff|resource
    owner_reference_num       string(32) nullable index
    date                      date index
    kind                      enum closed|custom_hours|extra_hours
    start_time / end_time     time nullable              ← for custom_hours / extra_hours
    reason                    string(255) nullable       ← "Bank holiday", "Training"
    timezone                  string(64) nullable
    timestamps

    unique (appointment_reference_num, owner_type, owner_reference_num, date, start_time)
```

Three kinds cover the real cases: closed all day, working different hours, and working when
normally closed. A tenant importing a public-holiday calendar creates `closed` rows in bulk.

## 4.8 `laravel_appointment_busy_blocks` — new

Imported from an external calendar ([10](10-communications-and-calendar.md) §10.6). Separate
from exceptions because it is **derived, replaceable data** — a sync run rewrites it, and it
must never overwrite something a human entered.

```php
laravel_appointment_busy_blocks
    id
    reference_num             string(32) unique
    appointment_reference_num string(32) index
    staff_reference_num       string(32) nullable index
    resource_reference_num    string(32) nullable index
    starts_at / ends_at       timestamp                  ← UTC
    source                    string(64)                 ← google | outlook | ics | manual
    external_id               string(255) nullable
    external_calendar_id      string(255) nullable
    summary                   string(255) nullable       ← usually NOT stored — §10.6
    synced_at                 timestamp
    timestamps

    index  (staff_reference_num, starts_at, ends_at)
    unique (source, external_calendar_id, external_id)
```

`summary` is nullable and **empty by default**: importing a practitioner's private calendar
titles into the tenant's database is a privacy decision, not a convenience, and it is opt-in
([10](10-communications-and-calendar.md) §10.6).

## 4.9 `laravel_appointment_holds` and `_bookings`

### Holds

```php
laravel_appointment_holds
    id
    reference_num             string(32) unique
    appointment_reference_num string(32) index
    service_reference_num     string(32) index
    staff_reference_num       string(32) nullable
    resource_reference_num    string(32) nullable
    starts_at / ends_at       timestamp                  ← UTC
    session_token             string(64) index
    site_user_id              unsignedBigInteger nullable
    status                    enum active|converted|released|expired default active
    expires_at                timestamp index
    timestamps

    index (service_reference_num, starts_at, status)
```

### Bookings — altered

The existing table, given an instant, an owner, a lifecycle and a credential.

```php
laravel_appointment_bookings
    id
    reference_num             string(32) unique          ← the ROUTE key
    booking_id                string                     ← KEPT for compatibility, no longer a credential (A9)
    appointment_reference_num string(32) index           ← was `reference_num`
    service_reference_num     string(32) index           ← was `slot_id`
    staff_reference_num       string(32) nullable index
    resource_reference_num    string(32) nullable index
    series_reference_num      string(32) nullable index  ← 08 §8.7

    starts_at                 timestamp index            ← UTC (A12)
    ends_at                   timestamp
    timezone                  string(64)                 ← the APPOINTMENT's zone
    customer_timezone         string(64) nullable        ← what the customer saw
    date                      date nullable              ← KEPT, derived, for legacy reads
    time                      string nullable            ← KEPT, derived

    site_user_id              unsignedBigInteger nullable index  ← 02 §2.3
    access_token              string(64) unique          ← 02 §2.5
    user_name                 string                     ← snapshots   02 §2.4
    user_email                string(320) index
    user_phone                string(64) nullable
    notes                     text nullable              ← customer-supplied
    internal_note             text nullable              ← staff-only, never public

    status                    enum pending|confirmed|cancelled|completed|no_show|rescheduled  default pending
    approval_status           enum not_required|pending|approved|rejected default not_required
    service_snapshot          json                       ← §4.9.1
    price_cents               integer default 0
    deposit_cents             integer default 0
    paid_cents                integer default 0
    refunded_cents            integer default 0
    currency                  string(3) nullable
    direct_payment_invoice_ref string(64) nullable unique
    credit_reference_num      string(32) nullable        ← 09 §9.6

    form_submission_ref       string(32) nullable        ← forms   08 §8.3
    form_version_id           unsignedBigInteger nullable
    custom_fields             json nullable              ← mirrored answers

    source                    enum widget|page|admin|phone|api|recurring default widget
    ip_address                string(45) nullable
    idempotency_key           string(64) nullable
    rescheduled_from_ref      string(32) nullable
    cancelled_at              timestamp nullable
    cancel_reason             string(500) nullable
    cancelled_by              enum customer|staff|system nullable
    checked_in_at             timestamp nullable
    completed_at              timestamp nullable
    reminder_sent_at          timestamp nullable
    deleted_at                timestamp nullable
    timestamps

    unique (appointment_reference_num, booking_id)       ← EXISTING, kept
    unique (appointment_reference_num, idempotency_key)
    index  (appointment_reference_num, starts_at, status)
    index  (staff_reference_num, starts_at)
    index  (user_email, starts_at)
```

Plus the constraint the module exists to have — §4.9.2.

### 4.9.1 `service_snapshot`

```json
{ "service_name": "Initial consultation", "duration_minutes": 45,
  "staff_name": "Dr Amara Okafor", "resource_name": "Room 2",
  "location": { "name": "Nyx Clinic", "address": "…" },
  "price_cents": 6000, "currency": "GBP", "timezone": "Europe/London" }
```

Rename the service, reprice it, or move the room, and a booking made last month still says what
it said. The same property [forms **F17**](../forms/01-current-state-audit.md) documents the
absence of, and [events/04](../events/04-database-schema.md) §4.11.1 applies to tickets.

### 4.9.2 The constraint (A11)

For a **capacity-1** service, the database refuses the second booking:

```sql
-- MySQL 8: a generated column plus a unique index, so cancelled rows do not block.
ALTER TABLE laravel_appointment_bookings
  ADD COLUMN active_slot_key VARCHAR(160)
    GENERATED ALWAYS AS (
      CASE WHEN status IN ('cancelled','rescheduled','no_show') THEN NULL
           ELSE CONCAT(service_reference_num, '|',
                       COALESCE(staff_reference_num,'-'), '|',
                       COALESCE(resource_reference_num,'-'), '|',
                       starts_at)
      END) STORED,
  ADD UNIQUE KEY appt_bookings_active_slot_uq (active_slot_key);
```

`NULL` for cancelled rows means a cancelled 10:00 does not block a new 10:00 — which is what
makes cancellation useful, and what the current UI gets wrong in the other direction (**A31**,
where a cancelled booking would block its time forever).

For capacity-*n*, the unique index cannot express the rule, so
[06](06-booking-engine.md) §6.3 uses a conditional `UPDATE` against a counter row.

**Both mechanisms are in the database.** Application-level checks exist for the error message,
never for the guarantee.

### 4.9.3 `laravel_appointment_slot_counters`

A unique index expresses "at most one". It cannot express "at most six", so a capacity-*n*
service claims against a counter row instead ([06](06-booking-engine.md) §6.3).

```php
laravel_appointment_slot_counters
    id
    service_reference_num  string(32)
    staff_reference_num    string(32) nullable
    starts_at              timestamp
    booked                 unsignedSmallInteger default 0
    capacity               unsignedSmallInteger
    timestamps

    unique (service_reference_num, staff_reference_num, starts_at)
```

Rows are created on demand at first claim and are a **cache of the bookings they summarise** —
`appointments:reconcile-counters` corrects the counter, never the bookings, and reports
disagreements as a monitored metric ([06](06-booking-engine.md) §6.3).

Capacity-1 services do **not** use this table; the generated column in §4.9.2 is stronger and
cheaper. Two mechanisms, each for the case it fits, and both in the database.

## 4.10 `settings`

Per-appointment behaviour that is ours, not the domain's:

```json
{
  "booking":   { "require_phone": false, "require_notes": false,
                 "allow_customer_reschedule": true, "allow_customer_cancel": true,
                 "one_active_per_email": false, "max_open_bookings": null },
  "holds":     { "ttl_minutes": 10 },
  "waitlist":  { "enabled": false, "offer_ttl_hours": 4, "auto_promote": true },
  "policy":    { "cancel": "free_until_hours", "cancel_until_hours": 24,
                 "late_cancel_fee_cents": 0, "no_show_fee_cents": 0, "terms_url": null },
  "notify":    { "confirmation": true, "reminders": [ { "hours_before": 24 },
                                                      { "hours_before": 2 } ],
                 "followup_hours_after": 24, "staff_on_booking": true },
  "display":   { "show_staff_names": true, "show_prices": true,
                 "show_remaining": false, "first_available_only": false },
  "sync":      { "push_bookings": true, "pull_busy": true, "import_titles": false },
  "retention": { "purge_after_days": null }
}
```

## 4.11 `_series`, `_waitlist`, `_credits`, `_activity`

```php
laravel_appointment_series                                   ← 08 §8.7
    id · reference_num · appointment_reference_num index
    service_reference_num · staff_reference_num nullable
    rule json                ← { freq, interval, byday, count|until }
    starts_at · timezone · occurrences_created unsignedSmallInteger
    status enum active|completed|cancelled default active
    timestamps

laravel_appointment_waitlist                                 ← 08 §8.8
    id · reference_num · appointment_reference_num index
    service_reference_num · staff_reference_num nullable
    preferred_from · preferred_to timestamp nullable
    preferred_days json nullable · name · email index · phone nullable
    site_user_id nullable · access_token string(64) unique
    position unsignedInteger
    status enum waiting|offered|converted|expired|declined|cancelled default waiting
    offered_at nullable · offer_expires_at nullable index
    booking_reference_num nullable
    timestamps
    index (appointment_reference_num, status, position)

laravel_appointment_credits                                  ← 09 §9.6
    id · reference_num · appointment_reference_num index
    site_user_id nullable index · customer_email string(320) index
    service_reference_num nullable    ← null = usable on any service
    total_sessions · used_sessions unsignedSmallInteger
    amount_paid_cents integer · currency string(3)
    direct_payment_invoice_ref string(64) nullable
    expires_at timestamp nullable index
    timestamps

laravel_appointment_activity
    id · subject_type string(32) · subject_reference_num string(32) index
    type string(64)      ← booking.created, booking.confirmed, booking.rescheduled,
                         ← booking.cancelled, booking.no_show, reminder.sent, mail.failed,
                         ← payment.captured, refund.issued, waitlist.offered, sync.pushed
    actor_type enum system|admin|staff|customer nullable · actor_id nullable
    payload json nullable · created_at timestamp index
```

`laravel_appointment_activity` is what makes *"was this customer reminded, and when"*
answerable — the question a support desk asks constantly, and which this module and both its
siblings currently cannot answer at all.

## 4.12 Migrating what exists

Unlike [events](../events/04-database-schema.md) §4.17, there **is** real server-side data here.
Every step is additive and reversible.

| Step | |
|---|---|
| 1 | Create the new tables; add new columns to `bookings` as nullable |
| 2 | **One implicit staff member per appointment**, named from the appointment |
| 3 | **Each `slots` row → one service.** `name`, `description`, `duration`, `color`, `active` map directly; `max_bookings` → `capacity`; `buffer_time` → `buffer_after_minutes` (its current meaning in `generateTimeSlots`) |
| 4 | **Each `slots` row → schedule rows**, one per entry in `available_days`, using `start_time`/`end_time` and the tenant's zone |
| 5 | **Ask the tenant for the time zone.** Existing rows carry none (**A12**); the migration cannot guess. Default to the site's configured zone and surface it for confirmation |
| 6 | **Bookings: `date` + `time` + zone → `starts_at` / `ends_at` UTC.** `ends_at` from the service duration |
| 7 | `access_token` is generated on every booking from the first migration (**A9**). No backfill — the application is unreleased ([02](02-site-users-guard.md) §2.5.1) |
| 8 | Generate `reference_num` for every booking; **keep `booking_id`** so old emailed links resolve during the deprecation window ([02](02-site-users-guard.md) §2.5) |
| 9 | Backfill `website_reference_num` (**A5**); unattributable rows are listed, never guessed |
| 10 | Add the generated column and unique index (§4.9.2) — **after** de-duplicating any existing collisions, which will exist because nothing prevented them (**A10**) |
| 11 | `laravel_appointment_slots` is retained read-only for one release, then dropped |

**Step 10 will find real duplicates.** A report is produced first; the tenant chooses which to
keep. Adding the index before de-duplicating fails the migration, which is the correct failure —
silently deleting one of two real customer bookings is not.

## 4.13 Integrity

```
appointments ──1:N──► services ──N:M──► staff, resources
appointments ──1:N──► schedules, exceptions, busy_blocks
services     ──1:N──► holds, bookings
bookings     ──N:1──► series, credits
```

The existing `cascadeOnDelete()` foreign keys from slots and bookings to appointments are
**removed from the destructive path** by soft delete (§4.2). Purge is an explicit, audited
action that also removes form submissions and pushes calendar cancellations
([13](13-reporting-and-analytics.md) §13.7).

`bookings.form_version_id` → `laravel_form_versions.id`, `ON DELETE RESTRICT` — a form version a
booking answered can never be deleted
([forms/09](../forms/09-publishing-and-versioning.md) §9.4).

## 4.14 Acceptance

- Every money column is integer cents; `grep` finds no `decimal` in an appointments migration.
- A capacity-1 service cannot hold two active bookings at one start time — enforced by the
  database, proven by concurrent inserts.
- A cancelled booking does **not** block its time.
- A booking's `starts_at` is a UTC instant, and its `service_snapshot` still reads correctly
  after the service is renamed and repriced.
- Deleting an appointment soft-deletes it and destroys no bookings.
- A staff member with future bookings cannot be deleted.
- Split shifts are expressible as two schedule rows.
- A bank holiday closes every staff member's day with one exception row per owner.
- Imported busy blocks are replaced by a re-sync and never overwrite an exception.
- The migration produces one service and *n* schedules per existing slot row, and every existing
  booking gets a UTC instant, an `access_token` and a `reference_num`.
- The migration **refuses to add the unique index** while duplicate active bookings exist, and
  reports them.
