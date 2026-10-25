# 02 — Guarding Appointments with Site Users

> **Extended by later documents.** This was written before the front end was audited and before
> the full schema ([04](04-database-schema.md)) existed. Its principles stand — the split API
> (§2.2), attribution (§2.3), snapshots (§2.4), the two-way booking lookup (§2.5), the modes
> (§2.6) and what accounts unlock (§2.7) are unchanged. Three things have moved on:
>
> - **The bulk-replace endpoints are deleted, not made admin-only.** §2.2 makes them
>   `ApiAuthMiddleware`-protected; [06](06-booking-engine.md) §6.2 removes them, because the
>   tenant's own admin UI fires them on every edit (**A16**, **A17**) and authenticating leaves
>   that fully armed.
> - **A booking hangs off a *service*, and optionally a *staff member* and a *resource***, not
>   off a slot ([04](04-database-schema.md) §4.9). §2.1's columns are kept; their parents
>   changed, and `slot_id` becomes `service_reference_num`.
> - **The rollout in §2.8 is superseded** by [14](14-rollout-and-testing.md) §14.1, which
>   sequences the same work across thirteen phases.
>
> Capacity (§2.6) is expanded in [06](06-booking-engine.md) §6.3 with the unique index, counters
> and holds; cancellation and reschedule (§2.7) in [08](08-booking-lifecycle.md).


Optional customer accounts for bookings. **Guest booking stays the default.**

## 2.1 The four layers

Same division as [ecommerce](../ecommerce/01-site-users-guard.md) §1.2, because the
infrastructure is shared:

| Goal | Enforced by | Appointments' work |
|---|---|---|
| The admin API is not public | `ApiAuthMiddleware` (**A1**–**A6**) | Route change |
| A members-only booking page is not served | Route protection ([site_users/12](../site_users/12-builder-integration.md) §12.5) | **None** |
| The booking API is not readable by strangers | The API guard ([site_users/12](../site_users/12-builder-integration.md) §12.8) | **None** — a config row |
| A customer sees **their own** bookings | Appointments | §2.3 — the only real integration |

## 2.2 Splitting the API (**A1**–**A6**)

One prefix serves two audiences today, which is how it ended up with the wrong middleware.

| Prefix | Contents | Auth |
|---|---|---|
| `/portal/api/v1/appointments/*` | **Admin** — save/delete appointment, save slots, **list bookings** | `ApiAuthMiddleware` |
| `/api/appointments/public/*` | **Public read** — appointment, active slots, **availability** | Open; guardable |
| `/api/appointments/bookings/*` | **Booking** — create, view own, cancel, reschedule | Rate-limited; session-aware |

Three rules that follow from the audit:

- **`listAppointmentBookings` is admin-only.** It returns names, emails and phone numbers
  (**A3**); it never belonged on a public prefix.
- **The bulk-replace endpoints are admin-only and get no deprecation alias** (**A1**, **A2**).
  Leaving an unauthenticated calendar-wipe reachable for a release cycle is the vulnerability,
  not the fix.
- **Booking creation is its own endpoint**, not a bulk save. A customer booking one slot must
  not call an endpoint whose semantics are "replace everything".

`POST /api/appointments/bookings` creates **one** booking, returns **one** booking, and
deletes nothing. That single change removes **A1** even before the middleware does.

The SDK's `create()` and `delete()` (**A6**) are removed from the public surface and replaced
with `book()`, `myBookings()`, `cancelBooking()`, `rescheduleBooking()`.

## 2.3 The only appointments code that changes

```php
$table->unsignedBigInteger('site_user_id')->nullable()->index();   // on bookings
```

Nullable — guest booking is fully supported and existing bookings have no user (**A7**).

```php
/**
 * AFS_APPOINTMENTS_MODIFIER_ADDITION — plans/www/appointments/02-site-users-guard.md §2.3.
 *
 * The signed-in customer, or null for a guest. Set by SiteUsersApiGuard
 * (site_users/12 §12.8.5); this module imports nothing and knows nothing about
 * how it got there.
 *
 * NEVER read a user id from the request body — a client-supplied owner is how
 * one customer's booking is attributed to another's account.
 */
$user = $request->attributes->get('site_user');
$booking->site_user_id = $user?->id;
```

And the ownership filter, which is the part a generic guard cannot do:

```php
// GET /api/appointments/bookings  — "my bookings"
$bookings = AppointmentBooking::where('site_user_id', $user->id)
    ->orderBy('date')->get();
```

**Authentication is generic; authorisation is not.** The guard proves someone is signed in;
only this module knows that *this* booking belongs to *that* customer.

## 2.4 Prefill, which is the real customer win

A signed-in customer's `user_name`, `user_email` and `user_phone` come from their account
rather than a form they retype every time.

The fields stay **on the booking** as snapshots, not as references. A customer may book on
behalf of someone else, may correct a typo for one booking, and may later change their account
email — none of which should rewrite a booking the tenant has already acted on.

Same reasoning as an order line's price snapshot
([ecommerce/08](../ecommerce/08-orders-and-fulfilment.md) §8.3).

## 2.5 The emailed link must keep working (**A9**)

Today the only way back to a booking is a link containing its `booking_id`. Adding accounts
must not break that — a guest has no account by definition, and a customer who booked before
accounts existed still holds the old link.

So a booking is reachable **two** ways:

| Reachable by | Rule |
|---|---|
| **Its token** | A high-entropy `access_token` on the booking, in the emailed link |
| **Its owner** | `site_user_id` matches the signed-in caller |

```php
$booking = AppointmentBooking::where('booking_id', $id)->first();
if (! $booking) abort(404);

$byToken = hash_equals($booking->access_token, (string) $request->query('t'));
$byOwner = $booking->site_user_id && $booking->site_user_id === $user?->id;

if (! $byToken && ! $byOwner) abort(404);
```

**404, not 403** — a 403 confirms the booking exists, and booking ids are guessable in a way
`access_token` is not.

### 2.5.1 There is no migration to do

`access_token` is **new**, and today `booking_id` doubles as both identifier and credential
(**A9**) while appearing in admin lists and afs-connect payloads.

**The application has not been released. There are no bookings.** So `access_token` simply
exists from the first migration: no backfill, no invalidated links, no grace window, no
legacy form to accept.

`booking_id` stays as an identifier and stops being a credential on day one. Nothing accepts
a bare `?id=` lookup, ever.

An earlier draft of this section specified a 90-day legacy-link window with a re-send flow.
It is deleted rather than left as a note — machinery for a migration that will never run is
machinery someone eventually implements.

## 2.6 Modes

Per [site_users/09](../site_users/09-app-integrations.md) §9.2, appointments declares
`supports: ['guest', 'required']`, and the mode lives **on the appointment**, not the module —
a tenant may run public consultations and members-only sessions from one site.

| Mode | Booking page | Booking | Bookings |
|---|---|---|---|
| `off` **(default)** | Public | Guest only | No attribution. **Today's behaviour** |
| `guest` | Public | Guest **or** signed in | Attributed when signed in |
| `required` | Public *(or guarded)* | **Signed in only** | Always attributed |

`required` is refused **in the API**:

```php
if ($mode === 'required' && ! $request->attributes->get('site_user')) {
    return response()->json(['error' => 'Sign in to book.',
                             'code' => 'AP_E_AUTH_REQUIRED'], 401);
}
```

Configured in the **appointments admin**, on the appointment, using the panel shape
[ecommerce/01](../ecommerce/01-site-users-guard.md) §1.5.3 establishes — same three modes,
same module select, so a tenant learns it once.

`required` is more useful here than in a shop: members-only clinics, client-only consultations
and paid-tier office hours are ordinary appointment products, whereas requiring an account to
buy something mostly costs conversions.

## 2.7 What accounts unlock

| Capability | Needs |
|---|---|
| **My bookings** — upcoming and past | §2.3 |
| **Self-service cancel** (**A8**) | §2.3 + a `cancelled` transition |
| **Self-service reschedule** | The same, plus availability |
| Prefilled booking form | §2.4 |
| Booking history across devices | §2.3 |
| Members-only appointment types | §2.6 |

**Cancel is the one that pays for the feature.** Today a customer who cannot attend must email
the tenant, who edits the calendar by hand. With `site_user_id` it is a button — and the freed
slot returns to availability, which is worth more to the tenant than to the customer.

Cancellation needs a window (`cancel_up_to_hours` on the slot), and a cancelled booking keeps
its row with `status = cancelled` rather than being deleted — a tenant needs to know a
no-show was a cancellation.

## 2.8 Rollout

| Phase | Contents | Ships behind |
|---|---|---|
| **P0 — security** | §2.2: split the routes, `ApiAuthMiddleware` on admin, single-booking create, admin-only booking list (**A1**–**A6**) | **Nothing. Not optional** |
| **P1 — capacity** | Enforce `max_bookings`, add `unique(reference_num, slot_id, date, time)`, apply `buffer_time`, store timezone (**A10**–**A13**) | Correctness only |
| **P2 — tokens** | `access_token` on bookings and the two-way lookup (§2.5) | No legacy form is ever accepted (§2.5.1) |
| **P3 — attribution** | `site_user_id`, prefill, my-bookings (§2.3, §2.4) | Nullable; no behaviour change |
| **P4 — self-service** | Cancel and reschedule (§2.7) | Per appointment |
| **P5 — modes** | `off`/`guest`/`required` + the admin panel (§2.6) | Default `off` |

**P0 depends on nothing.** P1 before P3 — accounts make bookings more visible, and a
double-booking discovered by a signed-in customer is worse than one discovered by the tenant.
P3 onward depends on [site_users](../site_users/README.md) P2 (sessions) and P8d (the API
guard).

## 2.9 Acceptance

**P0 — the prerequisite**

- Unauthenticated `POST /portal/api/v1/appointments/{ref}/bookings` → **401** (**A1**).
- The public booking endpoint creates **one** booking and **deletes nothing** — asserted by
  booking twice and confirming both survive.
- Unauthenticated `GET .../bookings` → **401**; no name, email or phone in any public
  response (**A3**).
- Unauthenticated slot save and appointment delete → **401** (**A2**, **A4**).
- An authenticated caller cannot touch another tenant's appointments (**A5**).
- The SDK exposes no `create()` or `delete()` (**A6**).

**Capacity**

- A slot with `max_bookings = 1` accepts **one** booking; the second is refused (**A10**).
- 50 concurrent requests for the same slot yield **one** booking — enforced by the unique
  index, not the check (**A11**).
- `buffer_time = 15` makes the adjacent slot unavailable (**A13**).

**Accounts**

- `grep -rn "SiteUser\|site_user" app/Http/Controllers/Externals/Appointments/AppointmentsController.php`
  finds **only** `$request->attributes->get('site_user')`.
- A signed-in booking writes `site_user_id`; a guest booking writes null.
- `GET /bookings` returns **only** the caller's — verified with two customers.
- A bare `?id=` lookup with no token returns **404** — there is no legacy form (§2.5.1).
- **A guest's emailed link still works** (§2.5), and another customer's booking returns
  **404**, not 403.
- Cancelling returns the slot to availability.
- `required` refuses an unauthenticated booking **from `curl`**, not just in the UI.
- With mode `off` — the default — booking behaves exactly as today.
