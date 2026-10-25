# 00 — Overview

## 1. What this is for

Site Users landed as a complete account system — sessions, SSO, verification, a published
Login/Register/Dashboard page set — and each selling module landed the *plumbing* to use it:
a nullable user column, a "mine" endpoint, and a `required`-mode gate read through the
consumer registry. What is missing is the **connection** between the two that a tenant can see
and operate, and the **payoff** a user can see and operate.

Today, from a tenant's chair:

- A store has a "Customer accounts" select buried in its form; events and appointments have
  the column and no control at all. There is no place that says *which* Site Users module a
  store is talking to, and nothing stops a user signed in to module B from being attributed
  on a store meant for module A.
- The Dashboard page a tenant publishes shows Profile, Security and Sessions. A shopper who
  just bought something finds no orders there; an attendee finds no tickets; nobody finds
  their saved card.
- A signed-in checkout still mints an anonymous provider customer per session
  ([site_users/08](../site_users/08-direct-payments-link.md) §8.1's problem, still open on the
  read side), so saved cards do not follow the account.

**Connections is the missing middle.** It is deliberately small in concept — one table, one
registry, one admin tab, one dashboard registry — and deliberately careful in enforcement,
because it decides who may spend money on a tenant's site and who may see what they spent.

## 2. Goals

1. **A tenant connects a module to a Site Users module in the portal admin**, per instance
   (this store, this calendar) or module-wide (every event), and disconnects just as easily
   ([05](05-admin-connections.md)).
2. **Guest checkout is a per-connection switch**: shown and defaulting to *on* when connected,
   forced *on* and hidden when not. The server enforces the *off* state for ecommerce checkout,
   event registration and appointment booking — from `curl`, not just in the UI
   ([06](06-checkout-guard.md)).
3. **The Site Users Dashboard shows a group per connected module** under the connected
   instance's name — the store's name, "Events", the calendar's name, "Payments" — with the
   things a single user can manage there ([08](08-dashboard-sections.md), [09](09-module-self-service.md)).
4. **Direct Payments connects too**: a signed-in checkout reuses the account's provider
   customer, saved payment methods are listable/addable/removable from the dashboard through
   ownership-checked endpoints, and every purchase across modules is visible in one place
   ([07](07-direct-payments-connection.md)).
5. **A signed-in user is only recognised by modules connected to *their* Site Users module**
   — multi-module isolation that the current registry does not enforce
   ([04](04-connection-registry-and-enforcement.md) §4.3).
6. **Nothing changes for a tenant who connects nothing.** Guest checkout, anonymous bookings,
   no dashboard groups, no extra queries.

## 3. Non-goals

- **Not members-only catalogues.** Hiding a *page* or a *read API* behind sign-in is route
  protection and the API guard ([site_users/12](../site_users/12-builder-integration.md) §12.5,
  §12.8) and stays a separate switch. This plan governs the *transaction*, not the browse.
- **Not refunds.** Money moving back to a card is the operator's act through the Money ledger
  ([direct_payments/29](../direct_payments/29-money-and-refunds.md)). This plan lets a user *request* a
  return or cancel an order/booking/registration, and puts the request in front of the
  operator. It never calls a refund endpoint on a user's behalf.
- **Not roles.** A connection does not give a site user permissions inside a module. A user
  sees and manages *their own* rows; the ownership filter stays inside each module
  ([ecommerce/01](../ecommerce/01-site-users-guard.md) §1.4's "authorisation is not generic").
- **Not a customers admin screen.** The admin sees a user's linked orders in the existing
  per-user screen's *Linked* panel ([05](05-admin-connections.md) §5.8); a full CRM view is
  ecommerce's deferred item 13 in [TODOS](../../TODOS.md).
- **Not forms or discussions.** Forms is guest-only by design; discussions is unbuilt. The
  registry keeps their entries; they are simply not `connectable` ([04](04-connection-registry-and-enforcement.md) §4.1).
- **Not the builder's page-settings panel UI** for route protection — still
  [site_users/12](../site_users/12-builder-integration.md)'s deferred item #1.

## 4. Glossary

| Term | Meaning |
|---|---|
| **Site Users module** | One deployment of Site Users, `laravel_site_user_modules.reference_num` (`su-XXXX`). A tenant may have more than one |
| **Consumer** / **consuming module** | ecommerce, events, appointments — a module that reads identity and never issues it ([site_users/03](../site_users/03-architecture.md) §3.5) |
| **Instance** | The thing a consumer configures: a *store*, an *event*, an *appointment calendar*, a *Direct Payment*. Identified by that model's `reference_num` |
| **Connection** | One row in `laravel_site_user_connections`: *this Site Users module* ↔ *this consumer* at *this instance* (or *all instances*), with a guest-checkout choice and a dashboard label |
| **Module-wide connection** | A connection whose `instance_key` is `*` — applies to every instance of that consumer that has no instance-specific row |
| **Guest checkout** | The per-connection boolean. `true` ⇒ mode `guest`; `false` ⇒ mode `required`. Absent connection ⇒ mode `off`, guest checkout forced `true` |
| **Mode** | The `off` / `guest` / `required` tri-state every consumer already reads through `SiteUsersConsumerRegistry::modeFor()`. Now *derived*, never stored as truth |
| **Section** / **section provider** | What a consuming module contributes to the Dashboard page through `Portal.ui.siteUsers.registerSectionProvider()` |
| **Customer link** | A row in `laravel_site_user_customer_links` — site user ↔ processor customer ([site_users/08](../site_users/08-direct-payments-link.md)) |
| **Customer resolver** | The small interface the Direct Payments SDK accepts so a signed-in checkout can be handed the right provider customer without the SDK importing Site Users ([07](07-direct-payments-connection.md) §7.3) |
| **Façade record** | An order / event order / booking — the module's own row that stores three payment references and no money ([ecommerce/AUDIT](../ecommerce/AUDIT-direct-payments-decoupling.md)) |

## 5. The decisions

| # | Decision | Why | Detail |
|---|---|---|---|
| **K1** | **The connection is its own table, owned by Site Users** — not a column on the store/event/appointment | The store already has two columns for this and events/appointments have one, and none of them can answer "which module" consistently, carry a label, carry settings, or express module-wide. Three tables × two columns is the half-adopted state N9 exists to prevent | [03](03-database-schema.md) §3.2 |
| **K2** | **Instance-specific rows win over a module-wide row** (`instance_key = '*'`) | A tenant with one public store and one wholesale store needs different answers; a tenant with forty events needs one click. Precedence gives both from one table | [02](02-architecture.md) §2.3 |
| **K3** | **An instance connects to at most one Site Users module** — `unique(module_key, instance_key)` | Two modules on one store means two populations with two "my orders" — a support nightmare and an attribution bug. The database refuses it | [03](03-database-schema.md) §3.2 |
| **K4** | **Guest checkout is a boolean on the connection, default `true`; not connected ⇒ forced `true`** | This is the requirement, and it is also the safe default: requiring an account costs conversions, and a tenant should reach `required` on purpose | [06](06-checkout-guard.md) §6.2 |
| **K5** | **`modeFor()` keeps its signature and is re-pointed at the connection registry** | Every consumer already calls it. Re-pointing one resolver changes three modules at once and proves the N9 seam was right | [04](04-connection-registry-and-enforcement.md) §4.2 |
| **K6** | **The signed-in user must belong to the connected module, or they are a guest** | A session from module B must not be attributed on a store connected to module A. Today nothing checks. In `required` mode the response names the right module so the UI can sign the user in to it | [04](04-connection-registry-and-enforcement.md) §4.3 |
| **K7** | **Enforcement stays in the API, at every anonymous-sensitive write** — begin *and* complete checkout, hold, register, waitlist, book | A hidden button is not a mode ([site_users/12](../site_users/12-builder-integration.md) §12.6). Holds and waitlists are new gaps: a `required` event today accepts anonymous holds | [04](04-connection-registry-and-enforcement.md) §4.5 |
| **K8** | **The instance's public config carries a `site_users` block**; the storefront never guesses the module id | The checkout page needs `Portal.siteUsers.create(<which module>)`. Today `getStore()` returns a mode and no module — the UI cannot act on it | [06](06-checkout-guard.md) §6.3 |
| **K9** | **One write surface for connections: the Connections tab. Per-module forms carry a shortcut to the same editor** | Two editors for one row drift. The shortcut keeps [site_users/09](../site_users/09-app-integrations.md)'s discoverability point without a second implementation | [05](05-admin-connections.md) §5.1, §5.6 |
| **K10** | **Direct Payments is a connectable module; its connection *is* the customer-link opt-in** | One concept ("connected") instead of a connection plus a checkbox that must agree. `link_payment_customers` becomes derived | [07](07-direct-payments-connection.md) §7.8 |
| **K11** | **A signed-in checkout resolves its provider customer server-side, from the session, before any charge** | A client-supplied customer id is how one account's card charges another's order. The resolver returns the link's customer or creates one *with the user's email* and links it — never an anonymous customer for a signed-in user | [07](07-direct-payments-connection.md) §7.4 |
| **K12** | **Saved payment methods are read and written only through Site Users' `/me/payment-methods`, which checks the link before touching the processor** | The processor endpoints are unauthenticated and keyed by customer id (**X1**). A dashboard must not be built on them | [07](07-direct-payments-connection.md) §7.5 |
| **K13** | **The Dashboard learns its sections from `GET /me/connections` and a client-side provider registry; the Site Users UI holds no module code** | Same shape as N9 on the server. Adding a module to the dashboard is one provider registration plus one server contribution | [08](08-dashboard-sections.md) §8.2 |
| **K14** | **Every dashboard section is rendered from exported pure markup builders**, so the builder canvas preview imports the same functions | [builder/v2/09](../builder/v2/09-single-source-ui.md)'s rule. A second hand-written copy of the orders list is a defect on day one | [08](08-dashboard-sections.md) §8.6 |
| **K15** | **Self-service actions that move money are *requests*, not refunds** — a return request, a cancellation with "refund pending" | Refunds are operator acts ([direct_payments/29](../direct_payments/29-money-and-refunds.md)). A user-triggered refund path would need its own fraud model; a request queue does not | [09](09-module-self-service.md) §9.2 |
| **K16** | **Existing mode columns are backfilled into connection rows, then written through as derived caches for one release, then dropped** | Add → migrate → deprecate → remove, the same shape every rename here has used. No reader breaks on deploy; stale readers are found by grep, not by outage | [03](03-database-schema.md) §3.5 |

## 6. What "done" looks like

A tenant with a store, an event calendar, an appointment calendar and one Direct Payment
opens **Site Users → Main → Connections** and sees four cards. They connect the store and
switch *Guest checkout* off; connect Events module-wide and leave guest checkout on; connect
the appointment calendar; connect the Direct Payment.

On the published site:

- A visitor with a full cart reaches the checkout page and sees *"Sign in to continue"* with
  the login form inline, a *Create account* link, and **no** *Continue as guest* button. A
  `curl` to `POST /checkout/begin` without a session returns `401 EC_E_AUTH_REQUIRED`.
- The same visitor registering for an event sees the registration form with an optional
  *Sign in for faster checkout* — guest checkout is on there.
- A signed-in shopper's card form offers their saved Visa from last month, on a different
  laptop, because the charge went to the provider customer linked to their account.
- The Dashboard page's sidebar reads **Account** (Profile · Security · Sessions),
  **Main Store** (Orders · Returns · Favourites), **Events** (Upcoming · Past),
  **Consultations** (Bookings), **Payments** (Cards · Purchases).
- The tenant disconnects the store. The checkout page offers guest checkout again, the
  **Main Store** group disappears from the dashboard, and every historical order keeps its
  `site_user_reference` and stays on the admin's order list.
