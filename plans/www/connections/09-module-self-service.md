# 09 — What a User Can Manage

The actions inside each dashboard group. This document is the per-module half of
[08](08-dashboard-sections.md): that one builds the shell, this one decides what may be done
inside it, and what must not be.

## 9.1 The rules every action obeys

1. **The session decides who.** No action takes a user id, an owner, or an account reference
   from the client. Every endpoint resolves the actor from the session and filters on it.
2. **Ownership is the module's job.** The guard proves someone is signed in; only ecommerce
   knows this order is theirs ([ecommerce/01](../ecommerce/01-site-users-guard.md) §1.4).
3. **404, not 403**, for someone else's record.
4. **The connection is checked, not just the ownership.** An order belonging to a store that
   has since been disconnected is not actionable from the dashboard —
   [02](02-architecture.md) §2.4.
5. **Policy is read from the snapshot on the record**, never from the instance's current
   settings, wherever a snapshot exists. A tenant tightening their cancellation policy must
   not retroactively trap a customer who booked under the old one
   ([appointments/08](../appointments/08-booking-lifecycle.md) §8.6).
6. **No action moves money** (K15). Cancel and return produce records and operator work
   items; refunds stay in the Money ledger
   ([direct_payments/29](../direct_payments/29-money-and-refunds.md)).
7. **Every action is idempotent or refuses cleanly.** Double-clicking Cancel produces one
   cancellation and one clear "already cancelled" response.

## 9.2 Ecommerce — Orders, Returns, Favourites

### Orders list and detail

Data the module already has: `GET /storefront/orders?store=` (owner-filtered) and
`GET /storefront/orders/{ref}`. Money comes from the invoice via
`EcommerceOrderService::serializeOrder()`, already resolved through `PaymentGateway`.

Two additions to the list endpoint:

```php
/**
 * AFS_ECOMMERCE_MODIFIER_ADDITION — plans/www/connections/09-module-self-service.md §9.2.
 *
 * `scope` lets the dashboard ask for the short list it badges on, without
 * fetching a shopper's whole history. `limit`/`cursor` because "my orders" is
 * currently capped at a hard 100 with no way to see the 101st.
 */
GET /portal/api/ecommerce/storefront/orders?store=&scope=open|all&limit=25&cursor=
```

The detail pane shows the order's lines with the snapshot names, the resolved amounts, the
fulfilment state, tracking where a fulfilment carries it, and the actions below.

### Cancel — the shopper's version

```php
/**
 * POST /portal/api/ecommerce/storefront/orders/{reference_num}/cancel
 *
 * AFS_ECOMMERCE_MODIFIER_ADDITION — plans/www/connections/09-module-self-service.md §9.2.
 *
 * The shopper's cancel. Distinct from the admin's cancelOrder() in three ways
 * it must not blur: ownership is required (not a store-scope check), the
 * connection's window applies, and `actor` is 'customer' on the order event so
 * an operator can tell who did it.
 *
 * Refuses — 409, with the reason named — when the order is already cancelled,
 * has any fulfilment, or is outside `settings.cancel.window_minutes`.
 * A refund is NOT issued (K15): the order lands in the admin's "cancelled,
 * payment captured" state, which is exactly the queue an operator should work.
 */
public function cancel(Request $request, string $referenceNum)
{
    $order = EcommerceOrder::query()->where('reference_num', $referenceNum)->first();
    if ($order === null) { return $this->notFound(); }

    $store = EcommerceStore::query()->where('reference_num', $order->store_reference_num)->first();
    $userRef = $this->shopperAuth->siteUserReference($store, $request);
    if ($userRef === null || $order->site_user_reference !== $userRef) { return $this->notFound(); }   // rule 3

    $conn = $this->registry->connectionFor('ecommerce', $store);
    if ($conn === null || ! $conn->setting('cancel.enabled', true)) {
        return $this->refuse('Orders cannot be cancelled here.', 'EC_E_CANCEL_DISABLED');
    }

    $windowMinutes = (int) $conn->setting('cancel.window_minutes', 60);
    if ($order->placed_at !== null && $order->placed_at->diffInMinutes(now()) > $windowMinutes) {
        return $this->refuse("Orders can only be cancelled within {$windowMinutes} minutes of being placed. Contact us and we'll help.", 'EC_E_CANCEL_WINDOW_PASSED');
    }
    if ($order->fulfilments()->exists()) {
        return $this->refuse('This order has already started shipping.', 'EC_E_CANCEL_FULFILLED');
    }

    $result = $this->fulfilmentService->cancelOrder($order, (string) $request->input('reason', 'customer_cancelled'), true);
    if (isset($result['error'])) { return response()->json(['success' => false, ...$result], 409); }

    // `cancelOrder()` writes actor 'admin'; correct it for this path rather than
    // adding a parameter that every admin caller would have to pass.
    EcommerceOrderEvent::query()->where('order_id', $order->id)->where('event', 'cancelled')
        ->latest('id')->limit(1)->update(['actor' => 'customer']);

    return response()->json(['success' => true, 'order' => $this->orderService->serializeOrder($order->fresh(), false)]);
}
```

`EcommerceFulfilmentService::cancelOrder()` gains an optional `string $actor = 'admin'`
parameter in the same pass, and the event patch above is replaced by passing `'customer'` —
written as two steps here only to show the requirement; **the parameter is the implementation**.

The UI states the outcome honestly: *"Order cancelled. Your payment of $84.50 will be
refunded by the store — you'll get an email when it's processed."* Not "refunded".

### Return requests

The table is [03](03-database-schema.md) §3.3. Endpoints:

| Method | Path | Notes |
|---|---|---|
| `GET` | `/storefront/returns?store=` | The caller's requests, newest first |
| `POST` | `/storefront/orders/{ref}/returns` | `{reason, note?, lines: [{order_line_id, quantity}]}` |
| `POST` | `/storefront/returns/{ref}/cancel` | Only while `status = requested` |

`POST` validates, in this order: ownership; connection exists and `returns.enabled`; the
order is paid (`PaymentGateway::isPaid()`); within `returns.window_days` of `placed_at`; every
`order_line_id` belongs to the order and `quantity <= line.quantity - line.refunded_quantity`;
`reason` is in the connection's configured list or `other`; **no open request exists for the
order** (§3.3's service rule, inside the transaction).

Response codes: `EC_E_RETURNS_DISABLED`, `EC_E_RETURN_WINDOW_PASSED`,
`EC_E_RETURN_LINE_INVALID`, `EC_E_RETURN_ALREADY_OPEN`, `EC_E_ORDER_UNPAID`.

The admin side is small and required, or the queue is invisible: the existing orders admin
gains a **Returns** filter and, on an order with a request, a panel with the lines, the
reason, and Approve / Reject / Mark received / Record refund. *Record refund* links to the
Money ledger's refund action for the order's charge and, on return, writes `refund_cents`
from the invoice snapshot. It does not call a refund API itself.

### Favourites and reorder

`shop.favorites()` / `removeFavorite()` already exist and need no server work — the view is a
grid with a *Move to cart* button calling `addToCart()`.

*Reorder* on a past order adds every still-purchasable line to the cart and reports what it
skipped: *"3 items added. 1 item is no longer available."* Purely client-side over
`shop.product()` + `addToCart()`; no endpoint.

## 9.3 Events — Upcoming, Past, Tickets

`myRegistrations()` returns every registration; the provider splits on the occurrence's
`starts_at` and on `status`:

| View | Rows |
|---|---|
| **Upcoming** | `starts_at >= now()` and `status in (confirmed, waitlisted, pending)` |
| **Past** | `starts_at < now()`, or `status in (attended, no_show, cancelled)` |

"Events the user has been to" is the Past view, and it is more useful with the attendance
fact the module already records: `checked_in_at` on the registration marks *attended*.

**Past and attended are different claims, and the UI must not conflate them.** `checked_in_at`
is set only by door scanning ([events/09](../events/09-checkin-and-onsite.md)), so an event
the tenant never scanned shows every past registration as not attended. The list therefore
labels rows *Attended* only when `checked_in_at` is set, and says nothing at all when it is
not — never *"Did not attend"*, which would be a false accusation on any unscanned event. The
serializer gains `checked_in_at`, `occurrence_starts_at`, `event_name` and `venue_name` so
the list does not need a second fetch per row. Those are already loadable in one
`with(['event', 'occurrence'])`.

### Ticket detail

The `ticket_code` is already on the registration and already returned. The detail pane shows
it as text plus a QR rendered **client-side** from that string (no image endpoint, no new
server surface), the occurrence's date and venue, the ticket type from `ticket_snapshot`, and
an *Add to calendar* link to the existing `.../calendar.ics`.

`access_token` is **not** used by any dashboard path (**X17**) — the owner path is the
session. The serializer stops returning `access_token` on the `mine` list; it stays on the
single-registration response, which is the one an emailed link uses.

### Cancel

`POST /registrations/{ref}/cancel` exists and is token-or-owner. It gains the policy the
connection carries:

```php
/**
 * AFS_EVENTS_MODIFIER_ADDITION — plans/www/connections/09-module-self-service.md §9.3 (X15).
 *
 * A cancellation window, absent today. `cutoff_hours` before the occurrence
 * starts; a registration inside the window is refused with the cutoff named,
 * so the attendee knows to contact the organiser rather than retrying.
 *
 * The token path keeps working (a guest has no account); the policy applies to
 * both, because it is the event's rule, not the account's.
 */
$conn = $this->registry->connectionFor('events', $event);
if ($conn !== null && ! $conn->setting('cancel.enabled', true)) {
    return $this->refuse('Registrations cannot be cancelled online for this event.', 'EV_E_CANCEL_DISABLED');
}
$cutoff = (int) ($conn?->setting('cancel.cutoff_hours', 24) ?? 24);
if ($occurrence->starts_at !== null && now()->diffInHours($occurrence->starts_at, false) < $cutoff) {
    return $this->refuse("Registrations close {$cutoff} hours before the event starts.", 'EV_E_CANCEL_WINDOW_PASSED');
}
```

Cancelling already releases capacity (`EventCapacityService::releaseRegistered()`). What it
must also do, and does not today: **promote the waitlist in the same transaction**
([events/02](../events/02-site-users-guard.md) §2.6's rule — a freed place must not be
claimable twice). `EventWaitlistService` exists; the call is added inside the existing
transaction.

A paid registration cancelled by its attendee is **not refunded** (K15). The pane says so and
the organiser sees it in the registrations list with `cancel_reason = 'user_cancelled'`.

### Not in this plan

Ticket transfer (`transferred_from_ref` / `transferred_to_ref` exist unused) and season-pass
purchase. Both are events' own deferred work; the dashboard has no view for either, and
adding one later is a view in the same provider.

## 9.4 Appointments — Bookings, Reschedule, Cancel

`myBookings()` splits the same way on `starts_at`. The serializer gains `service_name` (from
`service_snapshot`), `staff_name`, `location`, and — this is the one that matters —
`can_cancel` / `can_reschedule` **computed on the server** from the booking's
`policy_snapshot`:

```php
/**
 * AFS_APPOINTMENTS_MODIFIER_ADDITION — plans/www/connections/09-module-self-service.md §9.4.
 *
 * The policy the CUSTOMER agreed to is on the booking (`policy_snapshot`), not
 * on the calendar's current settings (appointments/08 §8.6). Computing the two
 * booleans server-side means the dashboard cannot disagree with the endpoint
 * that enforces them — a button that is shown and then refused is worse than
 * no button.
 */
private function customerPermissions(AppointmentBooking $booking, ?SiteUserConnection $conn): array
{
    $policy = is_array($booking->policy_snapshot) ? $booking->policy_snapshot : [];
    $allow  = (bool) ($policy['allow_customer_cancel'] ?? true);
    $hours  = (int) ($policy['cancel_up_to_hours'] ?? 24);
    $inWindow = $booking->starts_at === null || now()->diffInHours($booking->starts_at, false) >= $hours;
    $active = $booking->isActive();

    return [
        'can_cancel'     => $active && $allow && $inWindow && (bool) ($conn?->setting('cancel.enabled', true) ?? true),
        'can_reschedule' => $active && $allow && $inWindow && (bool) ($conn?->setting('reschedule.enabled', true) ?? true),
        'cancel_up_to_hours' => $hours,
    ];
}
```

`cancelBooking()` and `rescheduleBooking()` enforce the same function's result — the
controller calls it and refuses with `AP_E_CANCEL_WINDOW_PASSED` /
`AP_E_RESCHEDULE_DISABLED` rather than trusting the client not to have shown the button
(**X15** closed).

### The waitlist attributes too (X28)

`laravel_appointment_waitlist.site_user_reference` (`site_user_id` until site_users/13 §13.3 lands) exists, is nullable, and is **never written**:
`AppointmentStorefrontController::joinWaitlist()` hands `$request->all()` to the service and
no user id. Events does this correctly — its controller passes the resolved id as the sixth
argument to `EventWaitlistService::join()`.

```php
// AppointmentStorefrontController::joinWaitlist()
$result = $waitlist->join($request->all() + [
    'site_user_reference' => $this->registry->attributableUserReference('appointments', $appointment, $request),
]);
```

Without it, a signed-in customer who joins a waitlist has an anonymous entry: the offer that
follows a cancellation reaches them by email and never appears in their dashboard, and the
badge count is wrong. `AppointmentWaitlistService::join()` reads the key and writes the
column; nothing else changes, and a guest entry stays null as before.

Events' promotion path needs nothing: `EventWaitlistService::promoteNext()` only marks the
entry `offered` — it does not create a registration — so the offered person re-registers
through the normal path and is attributed there like anyone else.

**Reschedule** reuses the booking page's machinery: the pane fetches availability for the
booking's service (`POST /storefront/availability`), renders it with the **already exported**
`buildSlotButtonsMarkup(slots)`, and posts the chosen start to the existing
`POST /bookings/{ref}/reschedule`. `AppointmentLifecycleService::reschedule()` already writes
the new booking with `rescheduled_from_ref` and marks the old one `rescheduled`, so the pane
re-fetches and shows the new time. No new server surface.

**Cancel** calls the existing endpoint. `AppointmentBookingService::cancel()` already releases
capacity and promotes the waitlist. Late-cancellation fees are **not** charged
([appointments/08](../appointments/08-booking-lifecycle.md) §8.6: never automatic); the pane
states the policy when cancelling inside a fee window so the customer is not surprised by a
later charge.

## 9.5 Payments — Cards and Purchases

Covered by [07](07-direct-payments-connection.md): `cards` is
`GET/POST/DELETE /me/payment-methods` plus the setup element; `purchases` is
`GET /me/purchases`.

Two behaviours worth pinning here because they are user-facing:

- **Removing a card stays allowed even when Direct Payments is disconnected** — the single
  carve-out from rule 8, because the alternative leaves a user unable to remove their own
  payment instrument from a system that can still charge it
  ([13](13-disconnection.md) §13.6). Adding one, and setting a default, refuse with `409`.
- **Removing the last card** is allowed and warned about when the account has an active
  subscription resolvable through the link: *"This is the only card on file. A future
  subscription payment will fail."* The warning is a read, not a block.
- **A purchase row's actions live in the owning module's group** — the row links across via
  `ctx.goTo(...)` using the `manage` field ([07](07-direct-payments-connection.md) §7.6).
  Payments is read-only, so there is one implementation of "cancel this order" and it is in
  the store's group.

## 9.6 Cross-cutting UI rules

| Rule | Why |
|---|---|
| Every destructive action confirms inline, in the pane, naming the consequence and the money position | A modal over a dashboard the tenant themed is the one component that will look wrong on all six designs |
| A refused action shows the server's message verbatim | The windows and policies are the tenant's words; paraphrasing them in the client is how the two disagree |
| An action re-renders its own view and re-fetches `/me/connections?badges=1` for that group only | Badges must move when the thing they count does |
| Empty states name the module, not the concept | *"No orders from Main Store yet"* beats *"No records"* |
| Every list is keyboard-reachable and every row action is a real `<button>` | The shell already renders semantic nav; the panes must not regress it |

## 9.7 Claiming a guest purchase (X24)

The most common real journey is not in the plan so far: **buy as a guest, create an account
afterwards.** Every dashboard pane built above would show that shopper nothing, and the
tenant would field the support email.

Nothing today matches a guest record to an account. The customer reconciler
([site_users/08](../site_users/08-direct-payments-link.md) §8.5) matches *processor
customers*, not orders, registrations or bookings.

**Before** they register, a guest's only route back is the emailed link
([12](12-guest-continuity.md) §12.3) — which is why that document is folded into this plan
rather than left to the module communications plans. Claiming is what happens when they do
register; the receipt is what happens when they never do.

### The rule, borrowed wholesale

The three rules that document already argues for, applied to façade records:

1. **Only a verified account.** `email_verified_at` must be set. An unverified address is a
   claim, not an identity — and here the claim would hand over someone's purchase history.
2. **Only an exact, case-folded match** on the record's own snapshot address
   (`orders.customer_email`, `registrations.attendee_email`, `bookings.user_email`) against
   `users.email`, and only for records whose instance is connected to that user's module.
3. **Provenance is recorded**, so a wrong heuristic is one query to undo.

### Where it happens

**At verification, not at login.** The moment an account's email becomes verified is the only
moment the claim is justified, and it needs no user interaction.

**Including a verification that follows an email change** (X37). `email_verified_at` is set
again when a user confirms a new address
([`../site_users/13`](../site_users/13-identity-and-email-change.md) §13.4.2), so the claim
runs again, against the new address. That is deliberate and not a side effect:

- The justification is identical to the one at registration — confirming a token proves
  control of that inbox, and nothing weaker is accepted here or there.
- It is what a user expects. Someone who bought as a guest at `work@old.example`, then moves
  their account to that address, has done the thing that proves the purchase was theirs.
- It is bounded by the same three rules below: verified, exact and unique on both sides, and
  recorded with provenance — so a claim made after a change is as auditable as one made at
  registration, and the audit row names **which address triggered it**.

The hook is therefore explicit rather than a listener on the column: both
`SiteUsersVerificationService::verifyEmail()` and the email-change confirm call
`claimFor($user)` directly, so a reader of either path can see that it happens.

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/09-module-self-service.md §9.7 (X24).
 *
 * Called from SiteUsersVerificationService when a user's email becomes verified,
 * and from the admin's "claim guest records" action on the per-user screen.
 * Idempotent: a record already carrying a reference is never re-assigned,
 * whoever it belongs to.
 */
class SiteUsersGuestClaimService
{
    public function claimFor(SiteUser $user): array   // ['ecommerce' => 3, 'events' => 1, …]
    {
        if ($user->email === null || $user->email_verified_at === null) {
            return [];
        }

        $claimed = [];
        // X36 — ACTIVE connections only. `forDashboard()` includes disconnected
        // ones (13 §13.3), and claiming writes attribution — a write into a
        // read-only module (13 §13.5). A record belonging to a disconnected
        // instance stays a guest record until the tenant reconnects.
        foreach ($this->connections->activeFor($user->site_users_id) as $conn) {
            $claim = $this->claimers[$conn->module_key] ?? null;      // one small class per module
            if ($claim === null) { continue; }
            $claimed[$conn->module_key] = ($claimed[$conn->module_key] ?? 0)
                + $claim->claim($user, $conn);                        // WHERE site_user_reference IS NULL AND email = ?
        }
        return $claimed;
    }
}
```

Each claimer is four lines — a scoped `update` with `whereNull('site_user_reference')` — and writes
an audit row (`laravel_ecommerce_order_events` already exists for ecommerce; events and
appointments use their activity logs).

### What is deliberately *not* claimed

- **Payment methods and provider customers.** Those stay with
  [site_users/08](../site_users/08-direct-payments-link.md) §8.5's reconciler, which is
  admin-run and unscheduled *on purpose* — attaching payment identities automatically is the
  one place this heuristic is not worth the risk. A claimed order will show its amount
  (the invoice is read by reference) without the card following the account.
- **Records on disconnected instances.** Same rule as everywhere else.
- **Anything already attributed.** Never re-assigned, even if the emails match.

### The admin's version

The per-user screen ([05](05-admin-connections.md) §5.8) gains a **Claim guest records**
action showing the counts a claim *would* make before making it — the `--dry-run` shape, in a
UI, for the case where a user registered with a different address than they checked out with
and the operator is doing it on purpose.

### Guest checkout with an existing account's email

The mirror case: a guest checks out using an address that already belongs to a **verified**
account. The order is *not* silently attributed — the checkout was anonymous and attributing
it would let anyone put a purchase in a stranger's history by typing their address. It stays
a guest order, and the claim never runs for it because claiming only fires at verification,
which has already happened for that account. The operator's action above is the way in.

## 9.8 Acceptance

- Every write in this document returns `409 SU_E_CONNECTION_DISCONNECTED` when its
  connection is read-only, from `curl`, as the owner — except removing a saved card, which
  succeeds ([13](13-disconnection.md) §13.10).
- A shopper cancels an unfulfilled order within the window: the order is `cancelled`, stock
  is restored, the event's actor is `customer`, no refund call is made, and the pane says a
  refund is coming from the store.
- The same call one minute after the window → `409 EC_E_CANCEL_WINDOW_PASSED`; after a
  fulfilment exists → `409 EC_E_CANCEL_FULFILLED`.
- A return request for another shopper's order → `404`. A second open request for the same
  order → `409 EC_E_RETURN_ALREADY_OPEN`. A line quantity above what remains → `422`.
- The operator sees the request in the orders admin and can approve it; `refund_cents` is
  populated only after the ledger refund.
- Past events show `attended` for a registration with `checked_in_at`, and the `mine` list
  no longer returns `access_token`.
- Cancelling a registration inside `cutoff_hours` → `409` with the cutoff named; outside it,
  capacity is released **and** the oldest waitlisted registration is promoted in the same
  transaction.
- `can_cancel` on a booking is computed from `policy_snapshot`, and a `POST` that contradicts
  it is refused with the same rule — verified by flipping the calendar's current settings
  after booking and asserting the booking's own policy still governs.
- Reschedule uses `buildSlotButtonsMarkup` — one definition, asserted by grep.
- Removing the only card with an active subscription warns and succeeds.
