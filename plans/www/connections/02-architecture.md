# 02 — Architecture

## 2.1 The shape

```
 PORTAL ADMIN                                   PUBLISHED SITE / ANY SITE WITH THE SDK
 ────────────                                   ──────────────────────────────────────
 Site Users → <module> → Connections tab        storefront page       dashboard page
   connect / disconnect / guest checkout          │                      │
   label · sign-in page · module settings         │ GET /store           │ GET /me/connections
        │                                         │   → site_users{}     │   → [{module,instance,label,capabilities}]
        ▼                                         ▼                      ▼
 ┌──────────────────────────────┐        ┌────────────────────┐   ┌──────────────────────────────┐
 │ laravel_site_user_connections│◀───────│ SiteUsersConnection│──▶│ Portal.ui.siteUsers          │
 │  su module ↔ consumer@inst   │        │ Registry (server)  │   │  section provider registry   │
 │  guest_checkout · label      │        └─────────┬──────────┘   │  ecommerce / events /        │
 │  settings · status           │                  │              │  appointments / payments     │
 └──────────────────────────────┘                  │              └──────────────────────────────┘
                                                   ▼
                                  SiteUsersConsumerRegistry::modeFor()      ← unchanged callers
                                        │            │            │
                                   ecommerce      events     appointments
                                   begin/complete hold/reg/   hold/book/
                                   checkout       waitlist    waitlist
                                        │
                                        ▼
                     Direct Payments SDK  ← customer resolver (interface) ← Portal.siteUsers instance
                                        │
                                        ▼
                     /me/customers · /me/payment-methods · /me/purchases   (Site Users, reads DOWN
                                        │                                   into processor + DP)
                                        ▼
                     laravel_site_user_customer_links → laravel_processor_customers → provider
```

Three things are new: the **table**, the **server registry** that reads it, and the **client
section registry** that renders it. Everything else is an existing seam being pointed at
them.

## 2.2 A connection is a row — and the row is the only truth (K1)

```
laravel_site_user_connections
  site_users_id           which Site Users module            su-a1b2
  module_key              which consumer                     ecommerce | events | appointments | direct_payments
  instance_key            which instance, or '*'             store-9f3k  |  *
  guest_checkout          the one behavioural switch         true (default)
  label                   what the dashboard calls it        "Main Store" (null → resolved from the instance)
  settings                per-module knobs                   {"returns":{"enabled":true,"window_days":30}}
  status                  active | disconnected (13 §13.1)     active
```

Every question below is answered by reading this table and nothing else:

| Question | Asked by | Answer |
|---|---|---|
| Is this store connected, and to which module? | checkout gate, storefront config, admin | `resolve('ecommerce', 'store-9f3k')` |
| May a guest check out here? | checkout gate | `resolve(...)->guest_checkout ?? true` |
| What does the dashboard show this user? | `/me/connections` | `forDashboard('su-a1b2')` — active **and** disconnected ([13](13-disconnection.md) §13.3) |
| Does this module link payment customers? | `linkCustomer()`, reconciler, DP resolver | `paymentsLinked('su-a1b2')` — **active only**; `paymentsVisible()` is the read-side twin (X35, [13](13-disconnection.md) §13.6) |
| What sign-in page does the checkout send people to? | storefront config | `settings.login_path` ?? the module's `laravel_builder_routes.login_route` |

The old `site_users_mode` / `site_users_id` columns stop being read by anything in this plan's
final state. During the deprecation window they are **written** by the connection service so
a reader this plan missed keeps seeing the right value ([03](03-database-schema.md) §3.5).

## 2.3 Resolution precedence (K2)

```php
/**
 * Instance row beats module-wide row. Inactive rows are invisible.
 *
 *   ('ecommerce', 'store-9f3k')  → row(ecommerce, store-9f3k)  if present and active
 *                                → row(ecommerce, '*')         else, if present and active
 *                                → null                        else  ⇒ mode 'off'
 */
public function resolve(string $moduleKey, ?string $instanceRef): ?SiteUserConnection
```

Two consequences to design around, not against:

- **A module-wide row and an instance row may name different Site Users modules.** That is
  legal and is exactly the wholesale case: `events@*` → module A (the public site), but
  `events@evt-members-only` → module B (a members' portal). Precedence makes it unambiguous.
- **Disconnecting the module-wide row does not disconnect instance rows.** The admin shows
  both and says so ([05](05-admin-connections.md) §5.4).

There is no site-level scoping in the table. `www` is one tenant per deployment; where a
deployment hosts several websites, instances already carry `website_reference_num`, and the
admin's *candidates* list is scoped to the caller's owned sites the way every module admin
already is (`EcommerceAuthorizesSites`, `EventsSiteScope`, `AppointmentsSiteScope`). The
connection row itself does not need to know.

## 2.4 The truth table

The behavioural contract, stated once, tested in [11](11-rollout-and-testing.md) §11.4:

| Connection | `guest_checkout` | Derived mode | Anonymous checkout / register / book | Signed-in user **of the connected module** | Signed-in user of **another** module |
|---|---|---|---|---|---|
| none | *(forced true, not shown)* | `off` | allowed, `site_user_reference = null` | allowed, `site_user_reference = null` — **not attributed**; the module is not connected | same as guest |
| active | `true` | `guest` | allowed, `site_user_reference = null` | allowed, attributed | treated as guest; **not attributed** |
| active | `false` | `required` | **401** `SU_E_AUTH_REQUIRED` | allowed, attributed | **401** `SU_E_AUTH_REQUIRED` with `module: 'su-a1b2'` so the UI signs in to the right module |
| **disconnected** (`status = 'disconnected'`) | — | `off` | as "none" — guest checkout returns | as "none": no new attribution, **but the Dashboard keeps the group read-only and the history stays visible** ([13](13-disconnection.md)) | as "none" |
| removed (row deleted) | — | `off` | as "none" | as "none", and the Dashboard group is gone ([13](13-disconnection.md) §13.7) | as "none" |

The `off` row's "not attributed" cell is a **deliberate change** from today. Today a store
in `off` mode still writes an attribution when the gateway happens to find a session (the
guard sets the attribute regardless). A module that is not connected should not know who the
user is — otherwise disconnecting is not a real off switch. The dashboard would otherwise
show orders from a store the tenant explicitly disconnected, under a group that no longer
exists.

## 2.5 Module isolation (K6)

The session identifies a user **and their module** (`laravel_site_users.site_users_id`).
Isolation is one comparison, done in one place:

```php
// SiteUsersGateway
public function currentUserFor(Request $request, string $siteUsersId): ?SiteUser
{
    $user = $this->currentUser($request);
    if ($user === null || $user->site_users_id !== $siteUsersId) {
        return null;          // a session from another module is a guest here
    }
    return $user;
}
```

`SiteUsersConsumerRegistry` uses `currentUserFor()` with the connection's `site_users_id`
everywhere it used `currentUserId()`. No consumer changes: `EcommerceShopperAuth::siteUserId()`,
`EventRegistrationService`, `AppointmentBookingService` all already go through the registry.

**Why not reject a foreign session outright?** Because in `guest` mode it is harmless and
rejecting it would break a legitimate flow: a user of module B browsing a public store
connected to module A should be able to buy as a guest. Only `required` mode has to say no,
and when it does it names the module it wants.

### The comparison is needed in three places, not one (X27)

`SiteUsersConsumerRegistry` covers attribution and the checkout gate. The two layers that
guard a members-only *surface* each carry a `site_users_id` and neither reads it —
`SiteUsersApiGuard` for the storefront API and `BuilderPublishedController::show()` for the
page. Both are fixed with the same comparison in
[04](04-connection-registry-and-enforcement.md) §4.10, and until they are, "members-only"
means "any account on any Site Users module on this deployment".

## 2.6 Layering — who may import whom (rule three of the README)

```
                 ┌───────────────────────────────────────────┐
                 │  Site Users                               │
                 │   models · services · SiteUsersConnection*│
                 │   /me/connections /me/customers           │
                 │   /me/payment-methods /me/purchases       │
                 └──────┬──────────────┬─────────────┬───────┘
      reads DOWN        │              │             │
   (allowed, by id)     ▼              ▼             ▼
                 ecommerce         events       appointments        Direct Payments      processor layer
                 orders, returns   regs, orders  bookings           PaymentGateway        customers, methods
                       ▲              ▲             ▲                     ▲                    ▲
                       │              │             │                     │                    │
        read UP only through these doors — nothing else:
          1. SiteUsersConsumerRegistry  (modeFor / assertAuthenticated / currentUserFor / publicBlock)
          2. $request->attributes->get('site_user')   (set by the guard / session middleware)
          3. DirectPaymentsCustomerResolver           (an interface the DP SDK accepts; Site Users implements it)
```

Enforceable rules, each with a grep in [11](11-rollout-and-testing.md) §11.5:

| Rule | Grep that must be empty |
|---|---|
| No consumer imports a Site Users model | `grep -rln "Models\\\\SiteUsers" app/Externals/Ecommerce app/Events app/Externals/Appointments app/Externals/DirectPayments app/Http/Controllers/Portal/{Ecommerce,Events,Appointments} app/Http/Controllers/Externals/Processor app/Http/Controllers/Externals/DirectPayments` |
| No consumer reads the connections table | `grep -rn "site_user_connections" app/ --include=*.php \| grep -v "app/SiteUsers\|app/Models/Externals/SiteUsers\|app/Http/Controllers/Externals/SiteUsers\|database/"` |
| The processor layer learns nothing about users | `grep -rn "SiteUser\|site_user" app/Http/Controllers/Externals/Processor app/Models/Externals/Processor app/Externals/Processor` (the last dir is new — [07](07-direct-payments-connection.md) §7.5) |
| The DP SDK never imports Site Users source | `grep -n "site_users" resources/js/external/api/direct_payments/*.ts` — only the type name `DirectPaymentsCustomerResolver` may appear |

The third door is the only new one, and it is an *interface* precisely so the DP SDK stays a
standalone product: a developer with their own account system implements the same three
methods and gets the same behaviour.

## 2.7 Where the module id comes from on a page

The storefront page is published by the builder with `data-store-ref` / `data-event-ref` /
`data-appointment-ref` and, since v2, `data-dp-ref`. It has **no** `data-siteusers-module` — it
is not a Site Users page. So the module id must come from the instance's public config:

```json
GET /portal/api/ecommerce/storefront/store?store=store-9f3k
{
  "reference_num": "store-9f3k",
  "name": "Main Store",
  "site_users_mode": "required",            ← kept for compatibility, derived
  "site_users": {                           ← new, K8
    "connected": true,
    "module": "su-a1b2",
    "guest_checkout": false,
    "login_path": "/account-login",
    "register_path": "/account-register",
    "dashboard_path": "/account-dashboard"
  }
}
```

The same block appears on `GET /events/storefront/events/{ref}` and
`GET /appointments/storefront/appointments/{ref}`, built by one method,
`SiteUsersConsumerRegistry::publicBlock($appKey, $instance)`
([04](04-connection-registry-and-enforcement.md) §4.6). When not connected it is
`{ "connected": false, "guest_checkout": true }` and carries **no** module id — an unconnected
store must not advertise which Site Users module exists.

The Dashboard page is the other direction: it *has* `data-siteusers-module`, and it asks
`/me/connections` which instances point at it.

## 2.8 Failure posture

| Situation | Behaviour |
|---|---|
| Connections table missing or query fails | `resolve()` returns `null` → mode `off` → guest checkout. **Fails open**, same reasoning as N14: this ships onto live storefronts, and a failed read must not stop a sale. Logged at `error` |
| Connected module is inactive (`laravel_site_user_modules.is_active = false`) | Treated as **not connected** — the registry checks the module row. A disabled module must not lock a storefront |
| Instance deleted while connected | Row stays; `resolve()` for a deleted instance is never called (the instance 404s first). Admin lists it under *Orphaned* with a one-click remove ([05](05-admin-connections.md) §5.4) |
| `required` and the user's email is unverified | Allowed. Email verification is a route/guard-level choice (`require_verified_email`), not a checkout one. A tenant who wants it adds a guard row on the checkout paths |
| `/me/connections` fails on the dashboard | Account group renders; module groups show a single retry notice. Never blank the page |
| Customer resolver fails during a signed-in checkout | Log, **proceed without a customer** (the charge still succeeds), **write no link** — [site_users/08](../site_users/08-direct-payments-link.md) §8.4's rule. Never silently fall back to an anonymous customer *and* link it |
| Two admins race to connect the same store to two modules | `unique(module_key, instance_key)` — second write gets `409 SU_E_INSTANCE_CONNECTED_ELSEWHERE` |

## 2.9 What a consuming module has to do — the whole list

The N9 test again: if adopting costs more than this, the seam is wrong.

| Step | ecommerce | events | appointments | direct_payments |
|---|---|---|---|---|
| Registry entry gains `connectable`, `instance` model + label column, `dashboard` capabilities | 1 array edit | 1 array edit | 1 array edit | 1 array edit |
| Public config emits `publicBlock()` | `getStore()` | `serializeEventDetail()` | `serializeDetail()` | — |
| Gate the ungated writes | `completeCheckout()` | `hold()`, `joinWaitlist()` | `hold()`, `joinWaitlist()` | — |
| "Mine" endpoints return `401` to guests, accept `scope=` | `index()` already 401; add `scope` | `myRegistrations()` | `myBookings()` | `/me/purchases` is Site Users' own |
| Self-service actions ([09](09-module-self-service.md)) | shopper cancel, return requests | policy-checked cancel | policy-checked cancel | — |
| Dashboard section provider (client) | `external/ui/ecommerce/dashboard.ts` | `events/dashboard.ts` | `appointments/dashboard.ts` | `direct_payments/dashboard.ts` |
| Admin form shortcut | store form | event form | appointment form | DP form |

Nothing in that table creates, authenticates or mutates a user, and nothing in it imports a
Site Users class.

## 2.10 Identifiers — what a connected module stores about a person

Adopted from [`../site_users/13`](../site_users/13-identity-and-email-change.md) (2026-09-12),
which is the source of truth for the format and the migration. Stated here because every
document in this plan stores or reads one of these.

| Value | Type | Used for |
|---|---|---|
| `reference_num` | `char(12)`, `^u[a-z0-9]{11}$` | **The identifier every connected module stores.** Attribution, ownership filters, the customer link, `/me/purchases`, the guest-claim service |
| `email` | `varchar(255)`, unique per module, **mutable** | What a person sees and types: login, receipts, notifications. Never a stored reference |
| `id` | `bigint` | `laravel_site_users`' primary key, and the join key for its own children only — identities, sessions, verifications. **A connected module never sees it** |

**The rule, in one line:** a module stores the reference and displays the email.

### What that changes in this plan

| Where | Was | Now |
|---|---|---|
| Every consumer column | `site_user_id` bigint | `site_user_reference` char(12) — twelve tables, [`../site_users/13`](../site_users/13-identity-and-email-change.md) §13.3 |
| `SiteUsersConsumerRegistry` | `attributableUserId(): ?int` | `attributableUserReference(): ?string` ([04](04-connection-registry-and-enforcement.md) §4.2) |
| `SiteUsersGateway` | `currentUserId(): ?int` | `currentUserReference(): ?string`; `currentUserFor()` already returns the model |
| `laravel_site_user_customer_links` | `user_id` bigint | `site_user_reference` char(12) ([07](07-direct-payments-connection.md) §7.5) |
| `laravel_ecommerce_return_requests` | `site_user_id` bigint NOT NULL | `site_user_reference` char(12) NOT NULL ([03](03-database-schema.md) §3.3) |
| Invoice `user_id` stamp | `su:<reference_num>` | unchanged in shape, and now a stable opaque value — still **not** a join (X23) |

### Why this plan cares beyond tidiness

- **The email stops being an implicit key.** The guest-claim service
  ([09](09-module-self-service.md) §9.7) and the `email_match` reconciler both match on
  address. Once an address can change, a match made yesterday must not be re-derived
  tomorrow — so the match happens once and writes a reference, which then never moves.
- **X21 is closed rather than deferred.** A user can change their email
  ([`../site_users/13`](../site_users/13-identity-and-email-change.md) §13.4), and every
  linked provider customer is updated to follow it, so receipts keep arriving.
- **The purchase join survives it.** The customer link keys on `provider_customer_id`, not
  email, which is why it is the durable answer to "whose purchase is this"
  ([07](07-direct-payments-connection.md) §7.6).
