# 02 — Registration and Guarding Events with Site Users

> **Extended by later documents.** This document was written before the full schema
> ([04](04-database-schema.md)) and the event/occurrence split
> ([05](05-event-model-and-scheduling.md)) existed. Its principles all stand — the guest/account
> split (§2.3), snapshots (§2.4), the two ways to reach a registration (§2.5), atomic capacity
> (§2.6), the payment boundary (§2.7) and the per-event modes (§2.8) are unchanged. Three
> details have moved on:
>
> - **A registration hangs off an *occurrence* and a *ticket type***, not an event
>   ([04](04-database-schema.md) §4.11). §2.1's columns are kept; their parents changed.
> - **The one-email rule is keyed per occurrence, not per event**, and is configurable — so one
>   person can attend both the March and April sessions of a class
>   ([04](04-database-schema.md) §4.11.2).
> - **The rollout in §2.9 is superseded** by [14](14-rollout-and-testing.md) §14.1, which
>   sequences the same work across twelve phases.
>
> Capacity (§2.6) is expanded in [06](06-ticketing-and-capacity.md) §6.5 with holds, tiers and
> lock ordering; the builder note (§2.10) in [11](11-public-surface-and-sdk.md) §11.6.


Events has no registration model (**V3**) and no API (**V1**). This document builds both, with
identity designed in rather than retrofitted.

## 2.1 The registration model

**`laravel_event_registrations`** — the table the module is missing:

| Column | Type | Notes |
|---|---|---|
| `reference_num` | `string(32)` unique | `reg_` + 12 |
| `event_reference_num` | `string(20)` index | |
| `ticket_type_id` | null index | §2.6 |
| `site_user_id` | `unsignedBigInteger` null index | **Nullable** — guest registration is supported |
| `access_token` | `string(64)` unique | The emailed link's credential (§2.5) |
| `attendee_name` / `attendee_email` | | **Snapshots**, not references (§2.4) |
| `attendee_phone` | null | |
| `quantity` | default 1 | One registration may cover several attendees |
| `status` | `string(20)` default `confirmed` | `pending` \| `confirmed` \| `waitlisted` \| `cancelled` \| `attended` \| `no_show` |
| `amount_cents` / `currency` | null | Paid events (§2.6) |
| `direct_payment_invoice_ref` | `string(64)` null **unique** | Idempotency, same as an order |
| `custom_fields` | json null | Dietary requirements, t-shirt size |
| `checked_in_at` | null | Door scanning |
| `cancelled_at` / `cancel_reason` | null | |
| `registered_at` | | |
| `timestamps` | | |

```php
$table->unique(['event_reference_num', 'attendee_email'], 'ev_reg_event_email_uq');
```

**That unique index is a decision, not a formality**: one email registers once per event. It
prevents the most common duplicate — someone clicking submit twice — and it is what makes
capacity counting meaningful. A tenant who genuinely needs multiple registrations per email
uses `quantity`.

`laravel_events` gains: `capacity` null, `registration_opens_at` / `registration_closes_at`,
`allow_waitlist`, `requires_approval`, `site_users_mode`, `site_users_id`.

## 2.2 The API, split from the start

The lesson from both siblings (**A1**–**A6**, and ecommerce's **E1**–**E4**) is that their
admin and public APIs were never separated. Events starts split:

| Prefix | Contents | Auth |
|---|---|---|
| `/portal/api/v1/events/*` | **Admin** — CRUD events, ticket types, **list registrations**, check-in, export | `ApiAuthMiddleware` |
| `/api/events/public/*` | **Public read** — event, ticket types, remaining capacity | Open; guardable |
| `/api/events/registrations/*` | **Attendee** — register, view own, cancel | Rate-limited; session-aware |

Three rules carried over from the siblings' findings:

- **Listing registrations is admin-only.** It returns attendee names and emails — appointments
  **A3** is exactly this leak, and it should not be repeated in a module being written now.
- **Registration creates one row.** No bulk-replace endpoint exists, so appointments **A1**
  cannot occur here.
- **The SDK publishes no admin write.** `createEvent` / `updateEvent` (**V6**) do not appear
  on the public surface; the storefront SDK gets `events()`, `event()`, `register()`,
  `myRegistrations()`, `cancelRegistration()`.

**The legacy `/_portal_/admin/core/php/request.php` base URL is removed** (**V2**). It has
never worked against this codebase, so there is nothing to deprecate.

## 2.3 Attribution — the only "integration"

```php
/**
 * AFS_EVENTS_MODIFIER_ADDITION — plans/www/events/02-site-users-guard.md §2.3.
 *
 * The signed-in attendee, or null for a guest. Set by SiteUsersApiGuard
 * (site_users/12 §12.8.5); this module imports nothing and knows nothing about
 * how it got there.
 *
 * NEVER read a user id from the request body.
 */
$user = $request->attributes->get('site_user');
$registration->site_user_id = $user?->id;
```

And the ownership filter:

```php
// GET /api/events/registrations  — "my events"
EventRegistration::where('site_user_id', $user->id)
    ->orderByDesc('registered_at')->get();
```

**Authentication is generic; authorisation is not.** The guard proves someone is signed in;
only this module knows that *this* registration belongs to *that* attendee.

## 2.4 Snapshots

`attendee_name` and `attendee_email` live on the registration even when `site_user_id` is set.

A person may register on behalf of a colleague, correct a typo for one event, or change their
account email later — none of which should rewrite a registration a tenant has already printed
a badge from. Same reasoning as an order line's price snapshot
([ecommerce/08](../ecommerce/08-orders-and-fulfilment.md) §8.3).

A signed-in registrant gets the form **prefilled** from their account, which is the visible
benefit, and the values are still copied onto the row.

## 2.5 Two ways to reach a registration

A guest has no account by definition, so an emailed link has to work — and appointments
(**A9**) shows what happens when that link's credential is a guessable id.

| Reachable by | Rule |
|---|---|
| **`access_token`** | High-entropy, in the emailed link |
| **Owner** | `site_user_id` matches the signed-in caller |

```php
$reg = EventRegistration::where('reference_num', $ref)->first();
if (! $reg) abort(404);

$byToken = hash_equals($reg->access_token, (string) $request->query('t'));
$byOwner = $reg->site_user_id && $reg->site_user_id === $user?->id;

if (! $byToken && ! $byOwner) abort(404);
```

**404, not 403** — a 403 confirms the registration exists.

Because events is new, `access_token` exists from the first migration. There is no backfill and
no invalidated-links problem — the thing appointments §2.5 has to negotiate.

## 2.6 Capacity, waitlists and tickets

Appointments' **A10** — a capacity that is stored and never enforced — is the mistake to not
repeat.

```php
$table->unique(['event_reference_num', 'attendee_email'], 'ev_reg_event_email_uq');
```

Capacity is enforced by a **conditional update**, not a count-then-insert:

```php
// Reserve a place atomically; zero affected rows means the event is full.
$claimed = DB::update(
    'UPDATE laravel_events SET registered_count = registered_count + ?
     WHERE reference_num = ? AND (capacity IS NULL OR registered_count + ? <= capacity)',
    [$qty, $ref, $qty]
);
if (! $claimed) { /* waitlist or refuse */ }
```

A count-then-insert races, and for a popular event it races constantly — that is precisely
when overselling happens.

`registered_count` is a **cached counter on the event**, reconciled against the registrations
table by a scheduled check. Cached because the conditional update needs a single row to lock;
reconciled because a cached counter that nothing verifies is a counter that will drift.

**Waitlist**: when `allow_waitlist` and the event is full, the registration is created with
`status = waitlisted`. A cancellation promotes the oldest waitlisted registration and notifies
them. Promotion happens **in the same transaction as the cancellation**, so a freed place
cannot be claimed twice.

**Ticket types** — `laravel_event_ticket_types`: `event_reference_num`, `name`, `price_cents`,
`capacity`, `sales_start_at` / `sales_end_at`, `direct_payment_item_reference_num`,
`min_per_order` / `max_per_order`.

Early Bird / General / VIP, each with its own capacity and window. Free events have one
implicit free type, so **every registration references a ticket type** — the same
"always one default" rule that keeps ecommerce's variant handling to a single code path
([ecommerce/04](../ecommerce/04-catalogue.md) §4.4).

## 2.7 Paid events (**V4**)

`events.direct_payment_reference_num` becomes real, exactly as
[ecommerce/07](../ecommerce/07-direct-payments-integration.md) §7.2 makes
`stores.direct_payment` real:

- The event names a Direct Payment; the ticket type names a DP item.
- Registration for a paid type builds a DP invoice, `lock()`s it, and confirms
  **server-side** before the registration is `confirmed`.
- `unique(direct_payment_invoice_ref)` makes double-submission idempotent.
- A free event needs no Direct Payment at all.

**Events never talks to a provider** ([ecommerce/03](../ecommerce/03-architecture.md) §3.2).
Refunds are [direct_payments/29](../direct_payments/29-money-and-refunds.md); events records the invoice and
charge references so a refund is visible on the registration.

A paid registration that fails payment stays `pending` and **does not hold a place** — its
capacity claim is released. Holding capacity for an abandoned payment is how a sold-out event
has empty seats.

## 2.8 Modes

Per [site_users/09](../site_users/09-app-integrations.md) §9.2, events declares
`supports: ['guest', 'required']`, with the mode **on the event** — a tenant may run a public
conference and a members-only workshop from one site.

| Mode | Event page | Registration | My events |
|---|---|---|---|
| `off` **(default)** | Public | Guest only | No attribution |
| `guest` | Public | Guest **or** signed in | Attributed when signed in |
| `required` | Public *(or guarded)* | **Signed in only** | Always attributed |

Configured in the **events admin**, on the event, using the panel shape
[ecommerce/01](../ecommerce/01-site-users-guard.md) §1.5.3 establishes.

`required` fits events well — member-only workshops, alumni events and ticket-holder-only
sessions are ordinary event products.

A **members-only event** additionally needs its page route protected and its public read
endpoints guarded ([site_users/12](../site_users/12-builder-integration.md) §12.5, §12.8) —
**both, or neither**. Protecting the page while leaving `/api/events/public/{ref}` readable
leaks the event's existence, date and description.

## 2.9 Rollout

| Phase | Contents | Ships behind |
|---|---|---|
| **P1 — the API** | §2.2: controller, split admin/public/attendee routes, registrations table, SDK rewritten off the legacy URL (**V1**, **V2**, **V3**, **V6**) | New module; nothing to break |
| **P2 — capacity** | §2.6: conditional-update claiming, waitlist, ticket types, reconciler | Capacity null = unlimited, today's behaviour |
| **P3 — builder** | §2.10: event list, detail and registration components (**V5**) | New blocks |
| **P4 — paid events** | §2.7: Direct Payments (**V4**) | No DP = free events only |
| **P5 — accounts** | §2.3–§2.5, §2.8: attribution, my-events, cancel, modes | Per event, default `off` |

Unlike its siblings there is **no P0** — nothing is exposed, because nothing exists
([01](01-current-state-audit.md) §1.3). P5 depends on
[site_users](../site_users/README.md) P2 and P8d; P4 on the
[billing plan](../../billing/README.md).

## 2.10 The builder (**V5**)

`componentData.events` is already fetched and unused. Three components, following the model in
[site_users/12](../site_users/12-builder-integration.md) §12.1 — block → component type →
`script` — and reusing the shared `isEditorMode()` / `waitForPortal()` helpers rather than
adding copies:

| Block | Renders |
|---|---|
| Event List | Upcoming events, filterable by date |
| Event Detail | Description, date, venue, ticket types, remaining capacity |
| Registration Form | Attendee fields, ticket selection, payment when paid |

The registration form prefills from the account when one is signed in (§2.4) and shows the
waitlist state honestly when full — *"This event is full. Join the waitlist"* rather than a
button that fails on submit.

## 2.11 Acceptance

**The module**

- An event can be created, published, listed and registered for, end to end.
- The SDK contains **no** reference to `/_portal_/admin/core/php/request.php` (**V2**).
- The SDK exposes **no** admin write (**V6**).
- `GET /api/events/public/*` returns **no** attendee name or email — the leak appointments
  has (**A3**) does not exist here.

**Capacity**

- An event with `capacity = 50` accepts **exactly 50** places under 200 concurrent
  registrations — enforced by the conditional update (§2.6).
- The 51st is waitlisted when `allow_waitlist`, refused otherwise.
- A cancellation promotes exactly one waitlisted registration, in the same transaction.
- `registered_count` equals the count of non-cancelled registrations — asserted by the
  reconciler.
- A paid registration that fails payment **releases** its place (§2.7).

**Accounts**

- `grep -rn "SiteUser\|site_user" app/Http/Controllers/**/Events*` finds **only**
  `$request->attributes->get('site_user')`.
- A signed-in registration writes `site_user_id`; a guest writes null.
- `GET /registrations` returns **only** the caller's.
- A guest's emailed link works; another attendee's registration returns **404** (§2.5).
- Registering twice with one email is refused by `ev_reg_event_email_uq` (§2.1).
- `required` refuses an unauthenticated registration **from `curl`**.
- A members-only event with the page guarded but the API open is **caught** by a test (§2.8).
- With mode `off` — the default — registration behaves as a plain guest flow.
