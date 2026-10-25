# 02 — Route inventory: what each externals route needs

Part of [00](00-overview.md). Built from `php artisan route:list` (730 routes) and the controllers under `app/Http/Controllers/Externals` (430 routes), 2026-10-09. "Authenticated" below means **the route definition carries an authentication middleware** (`ApiAuthMiddleware`, a site-user session, an admin or signed-request middleware). A route without one may still authorise inside its controller; **phase P2 of this step is reading each of them to find out**, so the figures are an upper bound on what is open.

## 1. The numbers

| Area | public read | public **write** | authenticated read | authenticated write |
|---|---:|---:|---:|---:|
| Analytics | 2 | 1 | 0 | 0 |
| Appointments | 7 | 9 | 12 | 12 |
| Chat | 1 | 1 | 7 | 9 |
| DirectPayments | 14 | 16 | 1 | 0 |
| Documents | 0 | 0 | 3 | 3 |
| Ecommerce | 11 | 19 | 11 | 16 |
| Events | 7 | 9 | 6 | 6 |
| Feedback | 4 | 3 | 0 | 0 |
| Forms | 2 | 3 | 5 | 9 |
| Listings | 4 | 7 | 0 | 0 |
| MailHandler | 1 | 1 | 0 | 0 |
| Notifications | 2 | 4 | 0 | 0 |
| Processor | 16 | 36 | 3 | 1 |
| SiteUsers | 4 | 16 | 26 | 46 |
| Sponsorships | 6 | 9 | 3 | 6 |
| Subscribers | 6 | 9 | 0 | 0 |
| SystemsUsers | 2 | 4 | 4 | 5 |
| **Total** | **89** | **147** | **81** | **113** |

## 2. The tiers

| Tier | Meaning | Credential | Browser rule (step 01) |
|---|---|---|---|
| **SDK** | called by the SDK from a page | none from the visitor; the site comes from the host | allowed origins only; site scope; throttle; nothing believed (Direct Payments rules) |
| **DASH** | administrator action (create, change, delete, refund, configure) | the dashboard login (`ApiAuthMiddleware`) | same-origin only, no CORS |
| **OWN** | a separate guard of its own (site users, systems users, the sponsor portal, chat login) | its own session or token | CORS as SDK; the guard is not part of this plan |
| **HOOK** | provider callback | the provider's signature | none |

## 3. First pass over the 147 public routes that change data

Classified from the names and the SDK's calls; **every row is to be confirmed by reading the controller (P2)**.

| Controller (routes) | Tier by name | Note |
|---|---|---|
| Analytics `events` (1) | SDK | |
| Appointments storefront (9) | SDK | availability, book, cancel |
| Ecommerce cart, shopper lists, orders, returns (19) | SDK | cart and guest checkout |
| Events storefront (9) | SDK | hold, checkin, register |
| Feedback `submit` (1 of 3) | SDK | `forms` and `output` (2) are DASH |
| Forms public `submit`, file `upload` / `delete` (3) | SDK | upload and delete need size and ownership limits |
| Subscribers subscribe (1 of 9) | SDK | articles, categories (8) are DASH |
| MailHandler `send` (1) | SDK | **abuse magnet**: sends e-mail |
| Processor intent / session / order / capture / create-subscription (5 + 1) | SDK | until the invoice-first change they accept an amount from the page |
| Processor customers `create-anonymous`, `find-or-create`, `update-email` (3) | SDK | |
| DirectPayments invoice, complete-zero (+ subscription actions, 4) | SDK | server must price and verify ([Direct Payments TODOS](../direct_payments/TODOS.md)) |
| Notifications create / mark-read / delete (4) | ? | decide who creates notifications |
| DirectPayments `saveDirectPayment`, items, advanced, connect, swap, bindings (≈ 12) | **DASH** | creating or swapping a Direct Payment must need the login |
| Processor admin, billing, discount, plan, retry policy, sync, tax, connect (≈ 26) | **DASH** | processors and money settings |
| Listings save / output (7) | **DASH** | |
| Sponsorships store / update / delete / approve (8) | **DASH** | |
| SiteUsers public (16), SystemsUsers public (4), sponsor-portal login (1), chat login (1) | OWN | login, register, reset, verify |
| Processor webhook (1) | HOOK | signature-checked; confirm |

First reading of the table: roughly **60 of the 147 look like dashboard actions with no authentication in the route definition**. They are the first thing to confirm.

## 4. Work (phase P2)

1. **Read** each controller marked DASH or `?` and record whether it authorises inside (and how). Close the ones that do not.
2. **Declare the tier on every route** with one middleware alias per tier (`externals.public`, `externals.dashboard`, `externals.own`, `externals.hook`) on the route groups, replacing the scattered per-module aliases over time. The step 01 middleware reads the tier.
3. **A test** that walks the route table and fails when an `Externals` route has no declared tier, or when a DASH route lacks the dashboard login. This keeps the inventory true as routes are added.
4. **SDK-tier routes:** site scope on every one (the `*.site.scope` middleware exists for ecommerce, appointments, events and forms; extend to the rest), a throttle on every one (exists for site users, ecommerce, appointments, events, forms; add the rest), body and upload limits, and a CAPTCHA or honeypot decision for the public submit and subscribe routes.
5. **MailHandler `send`:** decide who may send (a site's own e-mail address, a daily quota, a per-recipient limit) before it is exposed to any page.
6. **Order of work:** processors and Direct Payments admin first (money), then mail send, then uploads and downloads, then the rest.

## 5. As built (2026-10-09)

**The table in §1 was the position before this work.** After it (432 routes now served by `Externals` controllers; 732 in all):

| | public (no login) | dashboard login | site-user session |
|---|---:|---:|---:|
| read | 57 | 102 | 12 |
| write | 88 | 145 | 28 |

**91 routes that had no login now carry `externals.dashboard`** (the 236 open routes became 145). The alias is `App\Http\Middleware\ExternalsDashboardLogin`: `ApiAuthMiddleware` (bearer token or the `portal_auth_token` cookie the dashboard sends) in the same off / report / enforce mode as the origin check — in `report` (the shipped default) nothing is refused and a request that would be is logged (`externals.dashboard.would_refuse`, once per path and 10 minutes); in `enforce` it is a 401. The dashboard's own Direct Payments screens call these routes with the cookie and no header, which is why the cookie path matters.

**Decided as administrator actions (88 controller actions, `RoutePolicy::MUST_REQUIRE_LOGIN`)**: analytics list/summary; Direct Payments save, items, destroy, advanced, connect account, swap/bindings, notification sends and mail-handler list; feedback form/output/submissions admin; listings management (not the reads that render a listing); notifications (all six); processors admin, billing amount/date/discount/refund/sync, Connect (all fourteen), discount, plan, retry policy and sync; sponsorship store/update/delete/levels and application list/approve/deny/delete; subscribers admin (not `subscribe`, categories read, or the article page). **Left public on purpose (`RoutePolicy::PUBLIC_WRITES`, 78 actions)**: the SDK flows — analytics create, appointments / events / ecommerce storefront, forms submit and files, feedback submit, subscribe, mail-handler send, processor intent / customer / saved-card / subscription routes, Direct Payments invoice and subscription actions (until the invoice-first work in `../direct_payments/TODOS.md`), the end-user guards (site users, systems users, sponsor portal, chat login), the sponsorship application form, and the provider webhook.

**Guards that keep it true**: `tests/Feature/Externals/RoutePolicyTest` fails if an administrator action loses its login, if a new route that changes data is reachable without a login and is not in `PUBLIC_WRITES`, or if either list names an action no route serves.

**Throttles added (16 routes, `throttle:N,1,prefix`, per client address)**: mail-handler send 10/min; feedback submit and subscribe 30/min; analytics create 600/min; processor customer / intent / session / order / subscription routes 60/min (shared prefix `extpay`); invoice save / zero-complete / status 120/min. Existing per-module throttles (site users, ecommerce, appointments, events, forms) are unchanged.

**Not done**: reading every remaining public controller for authorisation in the method (the 78 listed are decisions, not audits); upload size and type limits on the form file routes (nginx allows 10 G; PHP's limits apply); a CAPTCHA/honeypot on the public submit routes; who may use `mail-handler send` beyond the throttle; S4 (end-user-specific endpoints); the Direct Payments invoice-first changes.
