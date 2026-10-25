# connections — Planning Index

**Connections** wires the three selling modules — **ecommerce**, **events**, **appointments** —
and the payment engine — **Direct Payments** — to a tenant's **Site Users** module, so that a
signed-in end user has one account that remembers their orders, tickets, bookings and saved
cards, and so that a tenant can decide, per module, whether a purchase requires that account
or may still be completed as a guest.

Every connection is **optional**. A module that is not connected behaves exactly as it does
today: guest checkout, no account, no dashboard entry. Connecting is a decision a tenant makes
in the portal admin, and **disconnecting is read-only rather than erasure** — new activity
stops, the user keeps seeing everything they already did ([13](13-disconnection.md)).

> ### Status (2026-09-12, revised after a review pass the same day)
>
> **Planning only. No code has been written for this plan.** *(As of 2026-09-15: no longer
> true — every phase K0–K12 has shipped; see [11](11-rollout-and-testing.md)'s own completion
> record for the full phase table. Left as written below since it's an accurate record of
> where this document stood before implementation began.)*
>

> Everything this plan builds on is already landed and tested: Site Users sessions, the
> consumer registry, the API guard, the customer-link table, the page-level Site Users module
> with its Dashboard page, and each selling module's `site_user_id` attribution, "mine"
> endpoints and `required`-mode gate. [01](01-current-state-audit.md) is the exact inventory,
> and the **forty-two** gaps it lists (**X1–X42**) are what this plan closes.
>
> **One item ships before anything else here:** the processor layer's payment-method
> endpoints are unauthenticated and accept a *guessable local customer id* (**X1**). That is
> not a Site Users dependency; it is a live exposure, and [11](11-rollout-and-testing.md)
> phases it first, alone.
>
> **X18–X26 came from reviewing the plan against the code after it was written**, and each
> changed a document rather than being filed as a caveat:
>
> | # | What the review found | Where it landed |
> |---|---|---|
> | X18 | `SiteUsersPublicThrottle` matches no `/me/*` path, so the new payment endpoints — which fan out to Stripe/Square/PayPal per linked customer — would have been unlimited | [07](07-direct-payments-connection.md) §7.9, [10](10-api-contracts.md) §10.14 |
> | X19 | `su_session` is `SameSite=Lax` and `secure=false`, so cookie mode cannot work on a site this deployment does not serve — the standalone story needed stating, and `SameSite=None` is blocked on there being no CSRF verification on `routes/portal.php` at all | [06](06-checkout-guard.md) §6.8 |
> | X20 | Deleting a site user leaves dangling `site_user_id` values that this plan's joins would read | [03](03-database-schema.md) §3.7 |
> | X21 | A site user cannot change their email, so the provider customer keeps the registration address forever | [07](07-direct-payments-connection.md) §7.10 |
> | X22 | `GET /storefront/orders` requires `?store=`, so a module-wide **ecommerce** connection would have produced a dashboard group with nothing to query | [04](04-connection-registry-and-enforcement.md) §4.9, [08](08-dashboard-sections.md) §8.3 |
> | X23 | `invoices.user_id` is client-settable, so the first draft of `/me/purchases` would have let anyone inject a row into a stranger's purchase list | [07](07-direct-payments-connection.md) §7.6 |
> | X24 | Nothing connects a guest purchase to an account created afterwards — the most common real journey, and it would have shown an empty Orders pane | [09](09-module-self-service.md) §9.7 |
> | X25 | Two stores on one site share one cart cookie, and visiting the second destroys the first store's guest cart — the retail-plus-wholesale pairing this plan encourages | [12](12-guest-continuity.md) §12.2 |
> | X26 | The guest's access token is generated and never delivered: both confirmation services are log stubs and ecommerce sends nothing, so a guest who closes the tab cannot reach their record at all | [12](12-guest-continuity.md) §12.3 |
> 
> A **second** review pass then found eight more, X27–X34. Three matter:
> 
> | # | What the second pass found | Where it landed |
> |---|---|---|
> | X27 | **Neither protection layer enforces its own `site_users_id`.** The API guard and the published-page guard both carry the column and never read it, so "members-only" means "any account on any Site Users module" — and this plan is what makes a second module easy to create | [04](04-connection-registry-and-enforcement.md) §4.10 |
> | X29 | **The derived-column sync erases the evidence the "needs attention" banner reads**, so a tenant would never learn which instances silently lost `required`. Fixed by snapshotting the legacy mode in K1, before anything writes to it | [03](03-database-schema.md) §3.4 |
> | X31 | **`AppServiceProvider` binds nothing**, so the registry's "request-scoped cache" would never hit and a partial binding would pin a stale one under Octane | [04](04-connection-registry-and-enforcement.md) §4.2.1 |
> 
> The other five: the appointments waitlist never attributes (X28), the derived sync ran inside
> the write transaction (X30), `instance.scope` and `dashboard.scope` meant two different things
> in one array (X32), forms attribution is declared and never written (X33), and the
> caller-migration list omitted the waitlist paths (X34). X30, X32 and X34 were defects in this
> plan rather than in the code.
> 
> A **third** pass then found seven more, X35–X41 — all but one of them defects this plan
> introduced, and all of them at the seams between the three documents added late
> ([12](12-guest-continuity.md), [13](13-disconnection.md) and
> [`../site_users/13`](../site_users/13-identity-and-email-change.md)). The three that mattered:
>
> A **fourth** pass then found one more, the largest single fix of any round: [07](07-direct-payments-connection.md)
> still implemented the entire pre-payer model — raw provider customer ids, `adopt()`,
> `SiteUserCustomerLink` — under two pointer notes that correctly described what should
> replace them and roughly 500 lines of code that did not. [`../direct_payments/`](../direct_payments/README.md)
> was written after this document and re-pointed its headers without rewriting its body. Fixed
> by rewriting §7.3 through §7.11 against the payer model throughout — not by adding a third
> note (**X42**).
> 
> | # | What the third pass found | Where it landed |
> |---|---|---|
> | X35 | Renaming `forModule()` to `forDashboard()` swept `paymentsLinked()` along with it, so a **disconnected** payments connection would still have authorised adding cards and writing links | [04](04-connection-registry-and-enforcement.md) §4.2 — split into an active-only write gate and a visible read gate |
> | X36 | The guest-claim service called a method that no longer exists and, once renamed, would have written attribution into a read-only module | [09](09-module-self-service.md) §9.7 — `activeFor()` |
> | X38 | The email-change rate limit could never fire: `/me/email` matches the generic `/me` branch first in an `if/elseif` chain, so three-per-hour silently became 240-per-five-minutes | [07](07-direct-payments-connection.md) §7.9 — branch order made explicit |
> 
> The rest: an email change silently re-fired the guest claim with nothing saying so (X37),
> the customer-link index still named the bigint (X39), `readOnlyNotice()` was called and
> never defined (X40), and a guest's emailed link still offered actions on a disconnected
> instance (X41).

Read in this order:

| Step | Document | Contents |
|---|---|---|
| — | [README.md](README.md) | This index |
| 00 | [00-overview.md](00-overview.md) | What a connection is, goals, non-goals, glossary, the decisions **K1–K16** |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | Everything that exists today, and the gaps **X1–X42** |
| 02 | [02-architecture.md](02-architecture.md) | The connection model, resolution precedence, module isolation, layering rules, failure posture |
| 03 | [03-database-schema.md](03-database-schema.md) | `laravel_site_user_connections`, `laravel_ecommerce_return_requests`, the backfill, what becomes derived |
| 04 | [04-connection-registry-and-enforcement.md](04-connection-registry-and-enforcement.md) | The registry that replaces the per-instance mode column, and every server enforcement point |
| 05 | [05-admin-connections.md](05-admin-connections.md) | The portal admin: the Connections tab, the guest-checkout toggle, per-module shortcuts, the admin API |
| 06 | [06-checkout-guard.md](06-checkout-guard.md) | Guarding checkout, registration and booking behind sign-in — with guest checkout as a per-connection choice |
| 07 | [07-direct-payments-connection.md](07-direct-payments-connection.md) | Saved payment methods that follow the account, customer reuse at checkout, the purchases view |
| 08 | [08-dashboard-sections.md](08-dashboard-sections.md) | The Site Users Dashboard grows a section per connected module — through a registry, not bespoke code |
| 09 | [09-module-self-service.md](09-module-self-service.md) | What a user can *do* in each section: orders/returns/cancel, tickets/cancel/attended, bookings/reschedule/cancel |
| 10 | [10-api-contracts.md](10-api-contracts.md) | Every new and changed endpoint, payload, SDK method and `portal.d.ts` declaration. Its own audit found no saved card ever showing as *the* default, for any provider — fixed as [`direct_payments/08`](../direct_payments/08-saved-card-defaults.md), a Processor/Direct Payments concern even though this audit is what found it |
| 11 | [11-rollout-and-testing.md](11-rollout-and-testing.md) | Phases **K0–K12**, dependencies, the test matrix, acceptance, rollback |
| 12 | [12-guest-continuity.md](12-guest-continuity.md) | What tracks a guest, the shared cart cookie two stores fight over, and the receipt that is never sent |
| 13 | [13-disconnection.md](13-disconnection.md) | **Disconnection is read-only, not erasure** — the group stays, the history stays, the writes stop |
| — | [`../site_users/13`](../site_users/13-identity-and-email-change.md) | **Read alongside 02 §2.10 and 07 §7.10.** The user identifier every connected module stores, and the email change it makes safe |

## What this plan supersedes and what it keeps

| Earlier decision | State after this plan |
|---|---|
| [site_users/09](../site_users/09-app-integrations.md) §9.2 — mode `off` / `guest` / `required` **stored on the instance row** (`stores.site_users_mode`, `events.site_users_mode`, `appointments.site_users_mode`) | **Kept as a concept, moved as a fact.** The tri-state is now *derived* from a connection row: not connected → `off`; connected with guest checkout → `guest`; connected without → `required`. The columns become write-through caches for one release, then go ([03](03-database-schema.md) §3.5) |
| [site_users/09](../site_users/09-app-integrations.md) §9.2 — configured "in the module's own admin section, not a central Site Users screen" | **Changed, by request.** The Connections tab in the Site Users module screen is the one write surface. Each module's own form keeps a *shortcut* that opens the same editor, so the discoverability argument still holds ([05](05-admin-connections.md) §5.6) |
| [site_users/08](../site_users/08-direct-payments-link.md) §8.6 — `modules.link_payment_customers` checkbox | **Replaced by the Direct Payments connection.** The flag stays as a derived value the existing `linkCustomer()` and reconciler read; the checkbox leaves the Settings tab ([07](07-direct-payments-connection.md) §7.8) |
| `SiteUsersConsumerRegistry::modeFor()` reading `mode_source` | Reads the connection registry. Same signature, same callers, one new rule: **the signed-in user must belong to the connected module** ([04](04-connection-registry-and-enforcement.md) §4.3) |
| Dashboard page with Profile / Security / Sessions | Unchanged, plus a group per connection, contributed through `Portal.ui.siteUsers.registerSectionProvider()` ([08](08-dashboard-sections.md)) |
| A site user identified by `laravel_site_users.id` (bigint) on twelve consumer columns | **`reference_num`**, `char(12)`, `^u[a-z0-9]{11}$` — owned by [`../site_users/13`](../site_users/13-identity-and-email-change.md), consumed everywhere here ([02](02-architecture.md) §2.10). A module stores the reference and displays the email |
| A site user's email is immutable | **Changeable**, verify-before-switch, unique within the module ([`../site_users/13`](../site_users/13-identity-and-email-change.md) §13.4). Every linked provider customer follows it; purchase-time email snapshots do not ([07](07-direct-payments-connection.md) §7.10) |
| A purchase joins to a person through a **provider** customer id, per processor | Through a Direct Payments **payer** reference that survives a provider change ([`../direct_payments/`](../direct_payments/README.md)). `laravel_site_user_customer_links` folds into `laravel_site_user_payers`, and Site Users stops holding any provider identifier |

Nothing that shipped under those plans is deleted in this one. Every change is additive or a
re-routing of an existing read.

## Completion documents

Each numbered document gets a completion document in `complete/` when its work lands —
source basename plus `.complete.md`, e.g. `05-admin-connections.complete.md`. Include status,
summary, files changed, verification steps and follow-up work. The convention is the one every
sibling folder under `plans/www/` already follows.

## Code documentation standard

Document all new and changed code thoroughly using block comments — PHPDoc for PHP, JSDoc for
TypeScript. Every file, exported symbol, public function, non-obvious private helper, route
handler, migration and non-trivial type must explain purpose, parameters, return values, side
effects and plan references. Inline `//` comments are for brief local clarification only.

### Markers

Code owned by Site Users carries `AFS_SITE_USERS_MODIFIER_ADDITION` (see
[../../markers.md](../../markers.md)) followed by the plan reference, e.g.
`AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/04-connection-registry-and-enforcement.md §4.3`.

Code inside a consuming module carries **that module's** marker with the same reference —
`AFS_ECOMMERCE_MODIFIER_ADDITION`, `AFS_EVENTS_MODIFIER_ADDITION`,
`AFS_APPOINTMENTS_MODIFIER_ADDITION`, `AFS_BILLING_MODIFIER_ADDITION` for the processor and
Direct Payments layers. Where a file already carries a marker from an earlier effort, **add
alongside; never replace.**

## Four rules that shape everything here

**A connection is a row, and the row is the only source of truth.** Not a column on a store,
not a checkbox on the module, not a constant in a component script. One table, one service,
one registry that every reader — the checkout gate, the admin screen, the storefront's public
config, the dashboard — asks ([02](02-architecture.md) §2.2).

**Guest checkout is a per-connection choice with a forced answer when there is no
connection.** Not connected ⇒ guest checkout is on and the toggle is not shown. Connected ⇒
the toggle is shown and defaults to on. The server enforces it; the UI only reflects it
([06](06-checkout-guard.md) §6.2).

**Site Users reads down; nothing reads up.** Site Users may read ecommerce orders, event
registrations, bookings, processor customers and payment methods to build a dashboard. No file
in ecommerce, events, appointments, Direct Payments or the processor layer imports a Site
Users model. Modules talk to Site Users through exactly two doors that already exist —
`SiteUsersConsumerRegistry` and the `site_user` request attribute — plus one new one, the
customer-resolver interface the Direct Payments SDK accepts ([02](02-architecture.md) §2.6).

**`portal.d.ts` is updated in the same pass as any SDK change.** Consumers copy it; an
undeclared method is invisible to every consumer's build ([site_users/07](../site_users/07-externals-api.md) §7.6).

## One-paragraph summary

A tenant opens their Site Users module in the portal admin, sees a Connections tab listing
their stores, event calendars, appointment calendars and Direct Payments, and connects the
ones they want. Each connected store, calendar or event set gets a "Guest checkout" toggle,
on by default; turning it off makes the API refuse an anonymous checkout, registration or
booking, and the storefront shows an inline sign-in with "Create account" — and "Continue as
guest" only when the toggle allows it. A connected Direct Payment means a signed-in checkout
charges a provider customer that belongs to the account, so saved cards appear on the next
device. The Dashboard page that Site Users already publishes grows a navigation group per
connection — the store's name with orders and returns, "Events" with upcoming tickets and
attended history, the calendar's name with bookings to reschedule or cancel, "Payments" with
saved cards and every purchase — each contributed by its module through a small registry, so
the Site Users UI never learns what an order is. Disconnecting re-opens guest checkout and turns the group
read-only — the user still sees every order, ticket and booking they made, and can no longer
act on them; only an explicit Remove takes the group away. And
because most instances will never connect anything, the guest path is repaired on the way
past: one cart token per browser rather than one per site, so two stores stop overwriting
each other's carts, and a receipt that actually carries the link a guest needs to reach what
they bought.
