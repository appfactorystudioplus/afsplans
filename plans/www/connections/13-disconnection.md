# 13 — Disconnection

What happens to a user's account when a tenant disconnects a module.

**The rule: disconnection is read-only, not erasure.** A disconnected module keeps its group
in the Dashboard and shows everything the user ever did, and the user can do nothing new with
it. Their history is theirs; the tenant's decision governs the future, not the past.

> **This supersedes** the earlier disconnect semantics in
> [05](05-admin-connections.md) §5.4 ("*Disconnect* deletes the row … it disappears from
> users' Dashboards") and the `is_active` boolean in [03](03-database-schema.md) §3.2. Both
> are re-specified here.

## 13.1 The three states

```
                 connect                    disconnect
    (no row)  ─────────────▶   active   ──────────────────▶  disconnected
        ▲                        ▲                                │
        │                        └──────── reconnect ─────────────┘
        │                                                         │
        └───────────────────  remove  ────────────────────────────┘
                        (destructive, separate, warned)
```

| State | Row | Checkout / register / book | New attribution | Dashboard group | Existing history |
|---|---|---|---|---|---|
| **no row** | absent | guest checkout, as today | none | absent | n/a |
| **active** | `status = 'active'` | per `guest_checkout` | yes | full, interactive | visible, actionable |
| **disconnected** | `status = 'disconnected'` | **guest checkout, as if never connected** | **none** | **present, read-only** | **visible, not actionable** |
| **removed** | deleted | guest checkout | none | **absent** | invisible to the user; intact in the database and the admin |

Two things follow that are worth stating before the mechanics:

- **Disconnected is indistinguishable from never-connected to a visitor who is not signed
  in.** The storefront's public config says `connected: false`, guest checkout is on, and no
  module id is advertised. The state is visible only to a signed-in user who *has* history,
  and to the admin.
- **Remove is the only way to reassign an instance.** The `unique(module_key, instance_key)`
  index (K3) does not care about status, so a disconnected row still holds the slot. That is
  deliberate: the history belongs to the module it was made under, and letting a second module
  claim the instance while the first still shows those orders would give two accounts a claim
  on the same purchase.

## 13.2 Schema — `is_active` becomes `status`

[03](03-database-schema.md) §3.2's boolean cannot express three states, and "inactive"
described the wrong behaviour anyway.

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/13-disconnection.md §13.2.
 *
 * Replaces the `is_active` boolean. `disconnected` is NOT "off" — it is
 * read-only: the module stops accepting new activity and the user keeps seeing
 * everything they already did (§13.1).
 *
 * A boolean would force the dashboard to choose between "gone" and "working",
 * and the whole point of this document is that the answer is neither.
 */
$table->string('status', 20)->default('active')->index('su_conn_status_idx');   // active | disconnected

// The dashboard's query, and the enforcement query, want different subsets:
$table->index(['site_users_id', 'status'], 'su_conn_module_status_idx');
```

```php
class SiteUserConnection extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISCONNECTED = 'disconnected';

    public function isActive(): bool       { return $this->status === self::STATUS_ACTIVE; }
    public function isReadOnly(): bool     { return $this->status === self::STATUS_DISCONNECTED; }

    /**
     * X40 — the one sentence a read-only surface shows, defined ONCE.
     *
     * Both the API refusal (§13.5) and the Dashboard notice (§13.4) render this,
     * so a user who clicks a stale button and a user reading the pane are told
     * the same thing in the same words. `resolvedLabel()` is the connection's
     * label, or the instance's name, or the module's — the same resolution the
     * dashboard uses, so the sentence always names something the tenant chose.
     */
    public function readOnlyNotice(): string
    {
        return $this->resolvedLabel() . ' is no longer connected to your account. '
             . 'You can still see your history here.';
    }

    /** Rows that govern behaviour — enforcement, attribution, public config. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    /** Rows the Dashboard renders — active AND disconnected (§13.4). */
    public function scopeVisible(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_DISCONNECTED]);
    }
}
```

`scopeActive()` keeps its name and its meaning, so every enforcement path in
[04](04-connection-registry-and-enforcement.md) is unchanged by this document. Only the
dashboard and the read paths learn about `visible`.

Existing plan text that says `is_active = false` now means `status = 'disconnected'`; the
`PATCH …/connections/{id}` payload takes `status` in place of `is_active`
([10](10-api-contracts.md) §10.2).

## 13.3 Two readers, one registry

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/13-disconnection.md §13.3.
 *
 * resolve()      ACTIVE only. Everything that decides what may HAPPEN reads this:
 *                modeFor(), attributableUserReference(), assertAuthenticated(),
 *                publicBlock(), the derived columns. A disconnected instance
 *                resolves to null, so a storefront behaves exactly as it did
 *                before it was ever connected — which is the requirement.
 *
 * resolveAny()   ACTIVE or DISCONNECTED. Everything that decides what may be
 *                SEEN, and everything that needs to explain a refusal honestly,
 *                reads this. Without it a read-only action would be refused with
 *                "not found", and a user would be told their order does not
 *                exist because the tenant flipped a switch.
 */
public function resolve(string $moduleKey, ?string $instanceRef): ?SiteUserConnection;
public function resolveAny(string $moduleKey, ?string $instanceRef): ?SiteUserConnection;

/** The Dashboard's query: every visible connection for this module (08 §8.3). */
public function forDashboard(string $siteUsersId): Collection;   // was forModule(), active-only
```

Precedence inside `resolveAny()` is the same as `resolve()` — instance row, then module-wide
row — with one addition: **an active instance row beats a disconnected module-wide row, and
vice versa.** Specificity wins, not status. A tenant who disconnects *all events* but leaves
one event connected has said exactly that.

## 13.4 The Dashboard keeps the group

`/me/connections` returns visible connections, each carrying its state:

```json
{ "module_key": "ecommerce", "instance_reference_num": "store-9f3k", "label": "Main Store",
  "status": "disconnected",
  "read_only": true,
  "read_only_notice": "Main Store is no longer connected to your account. You can still see your past orders here.",
  "capabilities": ["orders", "order_detail"],
  "settings": { … },
  "badges": {} }
```

Four rules the server applies before the client sees it:

| Rule | Why |
|---|---|
| **`capabilities` is filtered to the read-only subset** — the module's registry entry gains `dashboard.read_only_capabilities` | The provider decides what to render *from capabilities*. Filtering server-side means a provider that forgets to check `read_only` still cannot draw a Cancel button, because the capability that gates it is gone |
| **`badges` is empty** | A badge says "this needs you". Nothing in a read-only group does |
| **`read_only_notice` is server-supplied copy** naming the instance | The client must not compose this sentence — the tenant's label is in it, the phrasing has to stay identical across four providers, and the API refusal in §13.5 returns the *same string* from `readOnlyNotice()` (§13.2), so a stale button and a rendered pane never disagree |
| **Groups sort active-first**, disconnected last | A live store belongs above an archived one |

Per module, the read-only capability subset:

| Module | Active | Read-only |
|---|---|---|
| ecommerce | `orders, order_detail, cancel, returns, favorites, reorder` | `orders, order_detail` |
| events | `upcoming, past, tickets, cancel, calendar` | `upcoming, past, tickets, calendar` |
| appointments | `upcoming, past, cancel, reschedule, calendar` | `upcoming, past, calendar` |
| direct_payments | `payment_methods, purchases` | `payment_methods, purchases` — **see §13.6** |

`calendar` survives because downloading an `.ics` for something you already booked changes
nothing. `tickets` survives for the same reason: an attendee who paid keeps their ticket code.
`favorites` and `reorder` do not — both write.

### What the pane looks like

The group renders with its heading unchanged, a one-line notice at the top of every pane, and
no action controls:

```
MAIN STORE                    ┌────────────────────────────────────────────────┐
 ▸ Orders                     │ ⓘ Main Store is no longer connected to your    │
                              │   account. You can still see your past orders  │
                              │   here.                                        │
                              ├────────────────────────────────────────────────┤
                              │ #1042   12 Aug 2026   $84.50   Delivered       │
                              │ #1017   03 Jul 2026   $22.00   Delivered       │
                              └────────────────────────────────────────────────┘
```

Not greyed-out buttons that fail on click. **Absent** buttons, and one sentence saying why.

## 13.5 The server refuses, the UI merely reflects (K7's rule again)

Hiding the control is presentation. Every self-service endpoint re-checks, because a client
holding an older bundle — or a `curl` — must get the same answer:

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/13-disconnection.md §13.5.
 *
 * The guard every action in 09 gains, before its own policy checks. Distinct
 * from "never connected" so the message can be true: a user whose store was
 * disconnected is not looking at a missing order, and telling them so would
 * send them to support with the wrong question.
 *
 * Ownership is checked FIRST, so this never reveals whether an order exists to
 * someone who does not own it.
 */
$conn = $this->registry->resolveAny($appKey, $instance);
if ($conn !== null && $conn->isReadOnly()) {
    return response()->json(['success' => false, 'error' => [
        'message' => $conn->readOnlyNotice(),
        'code' => 'SU_E_CONNECTION_DISCONNECTED',
    ]], 409);
}
```

Applied to: shopper cancel, return request, return cancel, registration cancel, booking
cancel, booking reschedule, favourites write, reorder-to-cart
([09](09-module-self-service.md)). `409`, not `403` — the request is well-formed and the
caller is entitled; the state of the world refuses it.

The **storefront** needs no equivalent, because a disconnected instance already resolves to
`null` in `resolve()`: checkout, registration and booking behave exactly as they do for an
instance that was never connected, and new activity is attributed to nobody
([02](02-architecture.md) §2.4's `off` row).

### One consequence to accept

A checkout begun while connected and completed after the tenant disconnects produces a
**guest** order: `complete()` sees mode `off`, clears the cart's attribution
([04](04-connection-registry-and-enforcement.md) §4.5), and the order never reaches the user's
history. This is correct — the purchase completed against an unconnected store — and it is
rare enough not to warrant a grace window, which would be a second set of rules to reason
about. The admin's disconnect confirmation names the number of carts currently mid-checkout
so a tenant can wait a few minutes if they care.

### The guest's emailed link too (X41)

[12](12-guest-continuity.md) §12.6's `?t=` view is the other surface that offers actions on a
record, and it was written before this document. It gets the same treatment, from the same
source of truth:

```ts
// external/ui/events/ui.ts — the token path, 12 §12.6
const record = await api.registration(ref, token);
root.innerHTML = buildSingleRegistrationMarkup(record);
if (record.read_only) {
    root.insertAdjacentHTML('afterbegin', buildReadOnlyNoticeMarkup(record.read_only_notice));
} else {
    wireGuestActions(root, ref, token);      // cancel, calendar
}
```

So the single-record serializers — `registration()`, `booking()`, `order()` — gain
`read_only` and `read_only_notice` from `resolveAny()`, exactly as `/me/connections` does.
A guest whose event was disconnected sees their ticket and an explanation, not a Cancel button
that returns `409`.

**A guest is affected by disconnection the same way a signed-in user is**, which is worth
stating because the rest of this document is written in terms of the Dashboard: the rule is
about the *instance*, not about who is asking.

## 13.6 Payments — the one carve-out

`payment_methods` stays in the read-only capability set, and **removing a card stays
allowed**, uniquely among every write in this document.

The reasoning is not consistency; it is that the alternative is indefensible. A user's card is
stored at a payment provider under a customer that belongs to their account. If the tenant
disconnects Direct Payments, the link still exists, an active subscription may still bill it,
and the user would have **no way to remove their own payment instrument from a system that can
still charge it.** No product decision by a tenant should be able to produce that.

So, when `direct_payments` is disconnected:

| Action | Allowed |
|---|---|
| List saved cards | ✅ |
| **Remove a saved card** | ✅ — always |
| Set a card as default | ❌ `409` |
| Add a card | ❌ `409` |
| View purchases | ✅ |

The card list carries its own notice: *"Payments are no longer connected to your account. You
can still remove a saved card."*

## 13.7 What the tenant sees

[05](05-admin-connections.md) §5.4's confirmation is rewritten, and the second action changes
meaning:

```
Disconnect Main Store?

  • Guest checkout turns back on. New orders are not attributed to accounts.
  • Customers keep seeing their past orders in their account, and can no longer
    cancel, return or reorder from there.
  • 2 carts are in checkout right now. Orders they complete after this will be
    guest orders.
  • You can reconnect at any time and everything resumes.

            [ Cancel ]   [ Disconnect ]
```

```
Remove Main Store's connection?

  • Everything Disconnect does, and:
  • 41 customers lose the Main Store section from their account entirely,
    including their order history.
  • Your admin keeps every order and every customer link. Nothing is deleted.
  • Only after removing can Main Store be connected to a different Site Users
    module.

            [ Cancel ]   [ Remove ]
```

The Connections tab shows a disconnected row in its own state — *Disconnected · read-only*,
with **Reconnect** and **Remove** — rather than looking unconnected, so a tenant can tell the
difference between "I never connected this" and "I turned this off".

## 13.8 Reconnection

Setting `status` back to `active` restores everything, and there is nothing to migrate: the
row kept its label and settings, and the history kept its references. New activity attributes
again from the next request.

The one thing that does *not* come back is attribution for anything bought while disconnected
— those rows were written as guest rows and stay that way. The guest-claim service
([09](09-module-self-service.md) §9.7) does not sweep them up either, because it only fires at
email verification and the user is already verified. An admin can run *Claim guest records*
([05](05-admin-connections.md) §5.8) if a tenant asks.

## 13.9 Everything else this touches

| Document | Change |
|---|---|
| [02](02-architecture.md) §2.4 | The truth table gains the `disconnected` row; `inactive` is renamed |
| [03](03-database-schema.md) §3.2 | `is_active` → `status` (§13.2) |
| [04](04-connection-registry-and-enforcement.md) §4.2, §4.4 | `resolveAny()`, `forDashboard()`; the "mine" endpoints **stop excluding** disconnected instances and start including them |
| [05](05-admin-connections.md) §5.4 | The confirmations above; the disconnected row state |
| [07](07-direct-payments-connection.md) §7.6 | `/me/purchases` includes disconnected instances; §13.6's carve-out |
| [08](08-dashboard-sections.md) §8.3, §8.5 | `read_only`, filtered capabilities, empty badges, active-first ordering, the notice |
| [09](09-module-self-service.md) | Every action gains §13.5's guard |
| [10](10-api-contracts.md) | `SU_E_CONNECTION_DISCONNECTED`; `status` on the PATCH payload |

The inversion in [04](04-connection-registry-and-enforcement.md) §4.4 is the one to get right,
because it is a straight reversal of what that section says today:

```php
// BEFORE — 04 §4.4: hide history for a disconnected event
->filter(fn ($r) => $this->connections->isConnected($user->site_users_id, 'events', $r->event_reference_num))

// AFTER — 13 §13.4: show it, and let the pane mark it read-only.
//   Excluded only when the connection row was REMOVED, which is the tenant
//   saying "this history should no longer be in their account" (§13.7).
->filter(fn ($r) => $this->connections->resolveAny('events', $r->event_reference_num)?->site_users_id
                    === $user->site_users_id)
```

## 13.10 Acceptance

- Disconnecting a store: the storefront offers guest checkout, `curl` to `/checkout/begin`
  with no session succeeds, and a signed-in checkout writes **no** attribution.
- The same user's Dashboard **still shows** the store's group, with their orders, and with no
  Cancel, Return, Reorder or Favourites controls.
- `POST /storefront/orders/{ref}/cancel` from `curl`, as the owner, → `409`
  `SU_E_CONNECTION_DISCONNECTED`, with a message naming the store.
- `GET /me/connections` returns the group with `read_only: true`, empty `badges`, and
  capabilities reduced to the read-only subset.
- Events and appointments: `registrations/mine` and `bookings/mine` **include** rows for
  disconnected instances (the reversal in §13.9) and exclude rows whose connection was removed.
- `/me/purchases` still lists purchases made through a disconnected instance.
- **Removing a saved card works while Direct Payments is disconnected**; adding one and
  setting a default return `409` (§13.6).
- **Removing** the connection hides the group entirely; the orders keep their reference and
  remain in the admin's list.
- An instance cannot be connected to a second module while disconnected — `409
  SU_E_INSTANCE_CONNECTED_ELSEWHERE` — and can after removal.
- Reconnecting restores interaction with no data change; a purchase made while disconnected
  stays a guest purchase.
- A tenant looking at the Connections tab can tell *disconnected* from *never connected*.
- A **guest** following an emailed link to a registration on a disconnected event sees the
  record and the notice, and no Cancel control; the endpoint refuses it anyway (X41).
- The refusal message and the pane notice are the **same string** — both come from
  `readOnlyNotice()` (X40).
