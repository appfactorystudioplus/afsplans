# 08 — Dashboard Sections

The Site Users Dashboard page grows a navigation group per connected module. The Site Users
UI never learns what an order is: each module contributes its own group through a registry,
the same shape N9 gave the server (K13).

## 8.1 What the user sees

Today (`buildDashboardMarkup()` in `external/ui/site_users/ui.ts`): a dark sidebar with
**Account** → Profile · Security · Sessions, and a content pane showing one section at a time.

After this plan, for a user whose module has four connections:

```
┌──────────────────────┬──────────────────────────────────────────────────────────┐
│ [A] Account          │  Welcome back, Dana                                      │
│  ┌────────────────┐  │  Manage your profile, security and sessions.             │
│  │ DM Dana Mills  │  │                                                          │
│  │ dana@…         │  │  ┌─Email────┐┌─Status───┐┌─Member…──┐┌─Sessions─┐        │
│  └────────────────┘  │  │ Verified ││ Active   ││ Sep 2026 ││ 2        │        │
│  ACCOUNT             │  └──────────┘└──────────┘└──────────┘└──────────┘        │
│   ▸ Profile          │                                                          │
│   ▸ Security         │  ┌─ Profile details ──────────────────────────────────┐  │
│   ▸ Sessions         │  │ Display name [Dana Mills          ]                │  │
│  MAIN STORE          │  │ Username     [dana                ]                │  │
│   ▸ Orders        3  │  │ [Save changes]                                     │  │
│   ▸ Returns       1  │  └────────────────────────────────────────────────────┘  │
│   ▸ Favourites       │                                                          │
│  EVENTS              │                                                          │
│   ▸ Upcoming      2  │                                                          │
│   ▸ Past             │                                                          │
│  CONSULTATIONS       │                                                          │
│   ▸ Bookings      1  │                                                          │
│  PAYMENTS            │                                                          │
│   ▸ Cards            │                                                          │
│   ▸ Purchases        │                                                          │
│  ─────────────────   │                                                          │
│   ⏻ Sign out         │                                                          │
│   Sign out everywhere│                                                          │
└──────────────────────┴──────────────────────────────────────────────────────────┘
```

Group headings are the connection's `label` — the store's name, `Events`, the calendar's
name, `Payments`. Badge counts are the "needs your attention" number (open orders, upcoming
tickets, upcoming bookings), not totals; a zero badge is not rendered.

**Nothing changes for a user whose module has no connections.** The sidebar is exactly the
Account group it is today, and no `/me/connections` shape reaches the render path that could
alter it.

## 8.2 The client section registry (K13)

```ts
// external/ui/site_users/sections.ts

/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/08-dashboard-sections.md §8.2.
 *
 * How a module contributes a Dashboard group. The Site Users UI holds no module
 * code: it asks the server which connections exist, then asks the registry which
 * provider (if any) claims each `module_key`. A connection whose module has no
 * registered provider renders NOTHING — an SDK bundle older than the server is a
 * missing group, never a broken page.
 *
 * This mirrors config/site_users_consumers.php on the client: one registration
 * per module, and adding a module to the dashboard is that registration plus the
 * server-side contribution in 08 §8.3.
 */
export interface DashboardConnection {
    module_key: string;                    // 'ecommerce' | 'events' | 'appointments' | 'direct_payments'
    instance_reference_num: string | null; // null for a module-wide connection
    label: string;                         // resolved server-side — the store's name, "Events", "Payments"
    capabilities: string[];                // from the registry entry — ALREADY filtered for read-only (13 §13.4)
    settings: Record<string, unknown>;     // the connection's settings blob, module-shaped
    badges: Record<string, number>;        // view key → count, computed server-side (§8.3). Empty when read_only
    /** 13 §13.1 — 'active' | 'disconnected'. A disconnected group still renders; it just cannot act. */
    status: 'active' | 'disconnected';
    read_only: boolean;
    /** Server-supplied copy naming the instance. Render verbatim at the top of every pane. */
    read_only_notice?: string;
}

export interface DashboardView {
    /** Stable within a group. Becomes `data-su-nav="<module_key>:<instance|*>:<key>"`. */
    key: string;
    label: string;
    icon?: string;                         // inline SVG string; falls back to a generic glyph
}

export interface DashboardSectionProvider {
    moduleKey: string;
    /** Which views this connection offers. Filter on `connection.capabilities` — never assume. */
    views(connection: DashboardConnection): DashboardView[];
    /**
     * Paint one view into `host`. Owns its own fetches, its own empty state and its
     * own errors — a throw here is caught by the shell and rendered as a retry
     * notice inside the pane, never as a blank dashboard.
     */
    render(host: HTMLElement, ctx: {
        connection: DashboardConnection;
        view: string;
        su: PortalSiteUsersInstance;
        user: PortalSiteUserPublic;
        /** Re-render this view — e.g. after a cancel. */
        refresh(): Promise<void>;
        /** Jump to another view, possibly in another group (a purchase's `manage` target). */
        goTo(moduleKey: string, instance: string | null, view: string, ref?: string): void;
    }): Promise<void>;
}

const providers = new Map<string, DashboardSectionProvider>();

export function registerSectionProvider(p: DashboardSectionProvider): void {
    providers.set(p.moduleKey, p);
}

export function sectionProvider(moduleKey: string): DashboardSectionProvider | undefined {
    return providers.get(moduleKey);
}
```

Registration happens in each module's own `external/ui/<module>/index.ts`, at import time:

```ts
// external/ui/ecommerce/index.ts
import { registerSectionProvider } from '../site_users/sections';
import { ecommerceDashboardProvider } from './dashboard';
registerSectionProvider(ecommerceDashboardProvider);
```

Ecommerce, events and appointments are already imported by `external/ui/main.ts`, so those
three need no wiring beyond the two lines above. **`direct_payments` is not** — there is no
`external/ui/direct_payments/` today, because Direct Payments has no page-level module and
therefore no `[data-*-kind]` roots to mount. Its package is created here as
**a section provider and markup builders only — no `install()`, no `mount()`** — and
`main.ts` must import it explicitly or the Payments group silently never appears:

```ts
// external/ui/main.ts
import './direct_payments';          // registers the Payments section provider (no install())
```

The four `*Ui.install()` calls are unchanged; `Portal.ui` gains no `directPayments` member,
because there is nothing on a page for it to mount.

**Why a client registry and not server-rendered HTML**: the dashboard is a published static
page that mounts an SDK; there is no server render pass to hook. And the module code that
knows how to draw an order list already lives in that module's UI package, next to the
markup builders the builder canvas imports (K14).

**Every namespace a provider needs is already on the page.** `external/api/sdk.js` is one
bundle carrying `Portal.commerce`, `.events`, `.appointments`, `.siteUsers` and
`.directPayments`, and both SDK script tags are emitted on every published page
([builder/v2/08](../builder/v2/complete/08-site-users-pages.complete.md) "Verified live").
A provider constructs `Portal.commerce.create(storeRef)` directly; no page gains a script tag.

## 8.3 The server side — `GET /me/connections`

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/08-dashboard-sections.md §8.3.
 *
 * What the signed-in user's Dashboard should show. Session-scoped: the module
 * comes from the session, never from the query string. Ordered so the sidebar
 * is stable across loads (module_key, then instance label).
 */
public function connections(Request $request, SiteUsersDashboardService $dashboard): JsonResponse
{
    $user = $request->attributes->get('site_user');

    return response()->json([
        'success' => true,
        'connections' => $dashboard->forUser($user),
    ]);
}
```

`App\SiteUsers\SiteUsersDashboardService::forUser(SiteUser $user): array` —

1. `connections->forDashboard($user->site_users_id)` — the **visible** rows: active and
   disconnected, active first ([13](13-disconnection.md) §13.3). A disconnected connection
   still produces a group; it just cannot act.
2. **Expand** any module-wide row whose registry entry is `dashboard.scope === 'instance'`
   into one entry per instance ([04](04-connection-registry-and-enforcement.md) §4.9, X22).
   A `global`-scoped module stays one entry. This is the only place expansion happens.
3. For each, resolve the **label**: `$row->label` ?? the instance's label column (`store.name`,
   `appointment.name`) ?? the registry's `dashboard.label` (`Events`, `Payments`). An expanded
   entry always takes the instance's name, never the connection's `label` — one label cannot
   name three stores.
4. Attach `capabilities` from the registry entry, filtered by the connection's own settings
   (returns disabled ⇒ drop `returns`; reschedule disabled ⇒ drop `reschedule`).
5. Attach `settings` — the whole blob; the module's provider reads what it understands.
6. Attach `status`, `read_only` and, when read-only, `read_only_notice` — and **filter
   `capabilities` down to the module's `dashboard.read_only_capabilities`**
   ([13](13-disconnection.md) §13.4). Filtering server-side means a provider that forgets to
   check `read_only` still cannot draw a write control, because the capability that gates it
   is gone.
7. Attach `badges` through a per-module counter, **one query each**, all of them cheap —
   **skipped entirely for a read-only connection**, because a badge says "this needs you" and
   nothing in a read-only group does:

| Module | Badge | Query |
|---|---|---|
| ecommerce | `orders` | orders for `(site_user_reference, store)` with `cancelled_at is null` and `fulfilment_status != 'fulfilled'` |
| ecommerce | `returns` | return requests with `status in (requested, approved, received)` |
| events | `upcoming` | registrations for this user whose occurrence `starts_at > now()` and `status in (confirmed, waitlisted)` |
| appointments | `bookings` | bookings for this user with `starts_at > now()` and `status not in (cancelled, no_show)` |
| direct_payments | — | none; a card count is not an action item |

Badge queries are skipped entirely when `?badges=0`, which the client passes on a re-fetch
after an action (it re-renders the group it changed and does not need the others' counts
re-computed).

`SiteUsersDashboardService` is the **one** place Site Users reads a consuming module's
tables, and it does so by table/column names taken from the registry, not by importing the
module's service classes — the direction rule of [02](02-architecture.md) §2.6. The
per-module counters live in one small class each
(`App\SiteUsers\Dashboard\EcommerceContribution`, `EventsContribution`,
`AppointmentsContribution`, `PaymentsContribution`) resolved from a map keyed by
`module_key`, so adding a module is one class plus one map entry.

Response:

```json
{
  "success": true,
  "connections": [
    { "module_key": "ecommerce", "instance_reference_num": "store-9f3k", "label": "Main Store",
      "capabilities": ["orders","order_detail","cancel","returns","favorites","reorder"],
      "settings": { "cancel": {"enabled":true,"window_minutes":60}, "returns": {"enabled":true,"window_days":30,"reasons":["damaged","other"]} },
      "badges": { "orders": 3, "returns": 1 } },
    { "module_key": "events", "instance_reference_num": null, "label": "Events",
      "capabilities": ["upcoming","past","tickets","cancel","calendar"],
      "settings": { "cancel": {"enabled":true,"cutoff_hours":24}, "show_past": true },
      "badges": { "upcoming": 2 } },
    { "module_key": "appointments", "instance_reference_num": "appt-77", "label": "Consultations",
      "capabilities": ["upcoming","past","cancel","reschedule","calendar"],
      "settings": { "cancel": {"enabled":true}, "reschedule": {"enabled":true} },
      "badges": { "bookings": 1 } },
    { "module_key": "direct_payments", "instance_reference_num": null, "label": "Payments",
      "capabilities": ["payment_methods","purchases"], "settings": {}, "badges": {} }
  ]
}
```

## 8.4 The shell changes in `site_users/ui.ts`

`DASH_SECTIONS` stops being a `const` tuple and becomes the Account group of a computed list.

```ts
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/08-dashboard-sections.md §8.4.
 *
 * The sidebar is now: one fixed Account group, then one group per connection
 * that has a registered provider. The nav key is
 * `<module_key>:<instance|*>:<view>` so two stores' "Orders" never collide.
 */
export interface DashboardGroup {
    heading: string;
    /** null for the built-in Account group. */
    connection: DashboardConnection | null;
    views: DashboardView[];
}

export function buildDashboardGroups(connections: DashboardConnection[]): DashboardGroup[] {
    const groups: DashboardGroup[] = [{
        heading: 'Account',
        connection: null,
        views: [
            { key: 'profile',  label: 'Profile',  icon: SU_ICON_USER },
            { key: 'security', label: 'Security', icon: SU_ICON_LOCK },
            { key: 'sessions', label: 'Sessions', icon: SU_ICON_MONITOR },
        ],
    }];

    // Server order is already active-first (13 §13.4); preserved here.
    for (const c of connections) {
        const provider = sectionProvider(c.module_key);
        if (!provider) continue;                       // older bundle: skip, never break
        const views = provider.views(c);               // c.capabilities is pre-filtered when read-only
        if (!views.length) continue;
        groups.push({ heading: c.label, connection: c, views });
    }
    return groups;
}

export function navKey(group: DashboardGroup, view: DashboardView): string {
    if (!group.connection) return 'account:*:' + view.key;
    return group.connection.module_key + ':' + (group.connection.instance_reference_num ?? '*') + ':' + view.key;
}
```

`buildDashboardMarkup(user, groups)` gains the second parameter and renders:

- the sidebar's `[data-su-dash-nav-label]` + `<nav data-su-dash-nav>` **once per group**,
  each button `data-su-nav="<navKey>"` with `<span data-su-badge>` when a count is present;
- the content pane's three Account `<section data-su-section="account:*:profile">` etc.
  exactly as today, plus **one empty** `<section data-su-section="<navKey>" hidden>` per
  module view, which the provider fills on first activation.

Everything else in the existing shell — the mobile drawer, the profile mini card, sign-out —
is untouched. The single-section-visible switcher generalises from the fixed tuple to
`groups.flatMap(g => g.views.map(v => navKey(g, v)))`.

### Lazy rendering and the deep link

A module view is rendered the first time it is activated, then cached in the DOM. The hash
carries the nav key so a link into the dashboard lands on the right pane:

```
/account-dashboard#ecommerce:store-9f3k:orders
/account-dashboard#ecommerce:store-9f3k:order/ord-a1b2      ← view + ref, split on the first '/'
```

`renderDashboard()` reads `location.hash` after painting the shell, falls back to
`account:*:profile`, and writes the hash on every nav click (`history.replaceState`, so the
back button leaves the dashboard rather than walking its panes). A `ref` after the slash is
passed to the provider as `ctx.view === 'order'` plus the ref — this is how a *Purchases* row's
`manage` target and an emailed link both arrive.

### Failure inside a pane

```ts
try {
    await provider.render(host, ctx);
} catch (err) {
    host.innerHTML = buildSectionErrorMarkup(group.heading, view.label);
    host.querySelector('[data-su-retry]')?.addEventListener('click', () => void activate(navKey, { force: true }));
}
```

One broken module never blanks the account.

## 8.5 The four providers, in outline

Each lives in its own module's UI package and is written against that module's existing SDK —
no new client-side data paths except the ones [10](10-api-contracts.md) adds.

The **Account** group's Profile pane gains one field beyond what it has today: the email
address, with a *Change* action and, while a change is pending, a notice naming the address
awaiting confirmation plus *Resend* and *Cancel*
([`../site_users/13`](../site_users/13-identity-and-email-change.md) §13.4.4). It is the one
place in the whole dashboard where a person edits the thing every other pane displays — and
none of those panes change, because they show a snapshot taken at purchase time.

### Read-only groups

A disconnected connection renders its group exactly as an active one, minus every write
control, plus one server-supplied sentence at the top of each pane
([13](13-disconnection.md) §13.4). Providers get this almost for free — `capabilities` arrives
already filtered — and the shell paints the notice, so no provider composes that copy:

```ts
if (ctx.connection.read_only && ctx.connection.read_only_notice) {
    host.insertAdjacentHTML('afterbegin', buildReadOnlyNoticeMarkup(ctx.connection.read_only_notice));
}
```

A provider still checks `read_only` before wiring any handler it draws unconditionally — the
capability filter stops the button being *rendered*, and this stops a stray listener acting.
The server refuses regardless ([13](13-disconnection.md) §13.5).

| Provider | Views | Data |
|---|---|---|
| `external/ui/ecommerce/dashboard.ts` | `orders`, `order` (detail, from a row click or a deep link), `returns`, `favorites` | `shop.myOrders()`, `shop.order(ref)`, `shop.purchase(invoiceRef)` for money, `shop.favorites()`, new `shop.returns()` / `shop.requestReturn()` ([09](09-module-self-service.md) §9.2) |
| `external/ui/events/dashboard.ts` | `upcoming`, `past`, `ticket` (detail with the QR/ticket code) | `api.myRegistrations()` split by occurrence date; `api.cancelRegistration()`; `api.calendarUrl()` |
| `external/ui/appointments/dashboard.ts` | `upcoming`, `past`, `booking` (detail) | `api.myBookings()`; `api.cancelBooking()`; `api.rescheduleBooking()` with the availability picker the booking page already has |
| `external/ui/direct_payments/dashboard.ts` | `cards`, `purchases` | `su.paymentMethods()` / `su.addPaymentMethod()` / `su.removePaymentMethod()` / `su.setDefaultPaymentMethod()`; `su.purchases()` |

The instance a provider talks to comes from the connection: an ecommerce provider for
`store-9f3k` constructs `window.Portal.commerce.create('store-9f3k')`. A module-wide events
connection constructs the events API with no instance, which is what
`myRegistrations()` already wants.

**Two stores connected ⇒ two groups**, each with its own `Portal.commerce` instance and its
own nav keys. This falls out of the design rather than needing a case.

## 8.6 Single source of markup (K14)

Every provider's markup is exported pure builders in that module's `ui.ts` — the file the
builder's canvas preview already imports
([builder/v2/09](../builder/v2/09-single-source-ui.md)):

| Module | Reuses | Adds |
|---|---|---|
| ecommerce | — (no existing orders-list builder; `renderOrders()` is inline today) | `buildOrdersListMarkup(orders)`, `buildOrderDetailMarkup(order, purchase)`, `buildReturnsListMarkup(rows)`, `buildReturnFormMarkup(order, reasons)` — and `renderOrders()` is refactored to call the first, closing that inline-markup gap |
| events | `buildMyRegistrationsMarkup(rows, calendarUrl)` — **already exported** | `buildTicketDetailMarkup(reg)` |
| appointments | `buildMyBookingsMarkup(bookings)` — **already exported** | `buildBookingDetailMarkup(b)`, reuse of `buildSlotButtonsMarkup(slots)` for reschedule |
| direct payments | — | `buildCardsMarkup(methods)`, `buildPurchasesMarkup(rows)` in a new `external/ui/direct_payments/ui.ts` |

The builder's `core/siteUsersTemplates.ts` imports `buildDashboardGroups` and the new
`buildDashboardMarkup(user, groups)` and passes **sample connections** so the canvas preview
shows a representative sidebar (one store group, one events group) rather than a bare Account
column. Sample data lives beside `sampleUser()`, which that file already has.

The chrome (`core/siteUsersChrome.ts`) gains selectors for the new hooks —
`[data-su-badge]`, `[data-su-table]`, `[data-su-row-actions]`, `[data-su-empty]` — inside the
existing `[data-siteusers-kind]` scope, so all six designs style them. The dashboard's shell
stays the fixed dark "app chrome" it is today; only the tenant's accent and font vary.

## 8.7 Cost when nothing is connected

- `/me/connections` returns `{connections: []}` — one indexed query on
  `laravel_site_user_connections`, no badge queries.
- `buildDashboardGroups([])` returns the Account group; the markup is what it is today.
- No module SDK is constructed, so no module fetch is issued.

The dashboard's request count for an unconnected module is therefore unchanged: `me()`,
`sessions()`, plus this one connections call.

## 8.8 Acceptance

- A module with no connections renders exactly today's dashboard (asserted structurally: the
  sidebar has one `[data-su-dash-nav-label]`).
- Connecting a store adds a group headed by the store's name with Orders; disconnecting it
  removes the group on the next load and leaves Account intact.
- Two connected stores produce two groups with distinct nav keys and independent panes.
- A connection whose `module_key` has no registered provider renders no group and logs
  nothing user-visible.
- A provider that throws shows a retry notice inside its pane; Profile still works.
- `#ecommerce:store-9f3k:order/ord-a1b2` opens the order detail directly.
- Badges show only non-zero counts and refresh after a cancel without a full page load.
- The canvas preview and the published page render the same sidebar structure from the same
  functions — asserted by a builder test comparing `buildDashboardGroups` output shape.
- A module-wide **ecommerce** connection with three stores renders three groups; a
  module-wide **events** connection renders one (§8.3 step 2,
  [04](04-connection-registry-and-enforcement.md) §4.9).
- A **disconnected** connection still renders its group, with its history, no write
  controls, no badge, and the server's notice at the top of each pane
  ([13](13-disconnection.md) §13.10).
- The Payments group appears — i.e. `external/ui/main.ts` imports `./direct_payments`.
  Asserted by a test that loads the UI bundle and checks
  `sectionProvider('direct_payments')` is defined.
