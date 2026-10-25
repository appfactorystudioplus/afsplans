# 05 — The Portal Admin: Connections

Where a tenant connects, disconnects, and decides on guest checkout. One write surface
(K9), reached from three places.

## 5.1 Where it lives

```
Portal Admin
└ Site Users                                   /site-users
   └ Main (su-a1b2)                            /site-users/detail?reference_num=su-a1b2
       Settings │ SSO │ Users │ ★ Connections  ← new tab, same screen, same file
```

`resources/js/portal/admin/html/views/site-users-detail.html` gains a fourth
`<li class="nav-item"><a data-su-tab="connections">` and a `#su-tab-connections` panel;
`controllers/site_users/site-users.ts` gains `loadConnections(ref)` / `wireConnections(ref)`
alongside the three existing pairs. No new view id, no new route.

Three entry points, one editor:

| Entry | What the tenant sees | Opens |
|---|---|---|
| **Connections tab** | Every connectable module and instance, with state | the editor inline (a card per module) |
| **Module form shortcut** (store / event / appointment / Direct Payment form) | *"Customer accounts — Connected to Main · Guest checkout on · Manage"* or *"Not connected · Connect"* | `navigate('site-users:detail', { reference_num, tab: 'connections', focus: 'ecommerce:store-9f3k' })` |
| **Modules index card** | badge *"Connected to Site Users"* / *"3 of 5 connected"* | the tab |

## 5.2 The tab

```
Connections
Connect your modules to this Site Users module. A connected module recognises signed-in
users and appears in their Dashboard. Guest checkout stays on unless you switch it off.

┌─ Ecommerce ─────────────────────────────────────────────────────────────────────────────┐
│  Store             Connected to    Guest checkout   Dashboard label     Actions          │
│  Main Store        ● this module   [on ●]           Main Store          Settings · Disconnect │
│  Wholesale         ● this module   [off ○]          Wholesale           Settings · Disconnect │
│  Outlet            ○ not connected  —               —                   Connect          │
│  Archive Shop      ◐ su-b7c2       —                —                   (connected elsewhere) │
│  ─ All stores (module-wide) ─   ○ not connected                         Connect all      │
└──────────────────────────────────────────────────────────────────────────────────────────┘

┌─ Events ────────────────────────────────────────────────────────────────────────────────┐
│  ─ All events (module-wide) ─   ● this module   [on ●]     Events        Settings · Disconnect │
│  Members Workshop  ● this module (override)  [off ○]        —            Settings · Disconnect │
│  Spring Fair       ○ inherits "All events"   inherits       —            Override          │
└──────────────────────────────────────────────────────────────────────────────────────────┘

┌─ Appointments ──────────────────────────────────────────────────────────────────────────┐
│  Consultations     ● this module   [on ●]           Consultations       Settings · Disconnect │
│  ─ All calendars ─ ○ not connected                                      Connect all      │
└──────────────────────────────────────────────────────────────────────────────────────────┘

┌─ Direct Payments ───────────────────────────────────────────────────────────────────────┐
│  When connected, signed-in checkouts charge a customer that belongs to the account, so   │
│  saved cards follow the user, and the Dashboard gains a Payments section.                │
│  ─ All Direct Payments ─   ● this module                  Payments      Disconnect         │
│  (Guest checkout does not apply here.)                                                    │
└──────────────────────────────────────────────────────────────────────────────────────────┘

⚠ Needs attention (2)
  laravel_events · evt-88a1 "Alumni Night" was set to "required" before Connections existed
  and could not be assigned a module automatically.  [Connect to this module]
```

Rules the layout encodes:

- **Every instance row shows one of four states**: connected to *this* module; connected to
  *another* module (read-only, named); inherits the module-wide row; not connected.
- **The guest-checkout switch is rendered only on a row that is connected to this module**
  (K4). Unconnected rows show `—`, never a disabled switch that suggests a value.
- **The module-wide row is a row like the others**, labelled *All stores / All events / All
  calendars*, and instance rows that inherit from it say so. *Override* on an inheriting row
  creates an instance row pre-filled from the module-wide one.
- **Direct Payments hides the switch** — its entry has `supports: []`.
- **Needs attention** reads `laravel_site_user_mode_snapshots`, captured in K1 before
  anything wrote to the legacy columns — **not** the live column, which the derived sync
  overwrites with `off` and would therefore erase the evidence (X29,
  [03](03-database-schema.md) §3.4).

## 5.3 The admin API

All under the existing `Route::prefix('siteusers')` admin group (`ApiAuthMiddleware`,
`routes/portal.php` ~line 267). Controller: `App\Http\Controllers\Externals\SiteUsers\SiteUsersConnectionsController`.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/modules/{ref}/connections` | This module's rows, `toAdminArray()` + resolved `label_resolved`, `instance_name`, `usage` (published-page count from `laravel_builder_module_refs`) |
| `GET` | `/modules/{ref}/connections/candidates` | Every connectable module → its instances **for the caller's sites** with current state |
| `PUT` | `/modules/{ref}/connections` | Upsert one row (§4.7) |
| `PATCH` | `/modules/{ref}/connections/{id}` | Partial update: `guest_checkout`, `label`, `settings`, `is_active` |
| `DELETE` | `/modules/{ref}/connections/{id}` | Disconnect |
| `GET` | `/modules/{ref}/connections/attention` | Unresolved `laravel_site_user_mode_snapshots` rows with no connection (§5.4) |
| `POST` | `/modules/{ref}/connections/attention/{id}/dismiss` | Mark one snapshot row resolved without connecting |

### `candidates` response

```json
{
  "success": true,
  "data": [
    {
      "module_key": "ecommerce",
      "label": "Ecommerce",
      "noun": "store",
      "supports_required": true,
      "module_wide": { "state": "none" },
      "instances": [
        { "reference_num": "store-9f3k", "name": "Main Store", "status": "active",
          "state": "connected", "connection_id": 12, "guest_checkout": true, "label": null },
        { "reference_num": "store-2ab8", "name": "Archive Shop", "status": "active",
          "state": "elsewhere", "connected_to": "su-b7c2" },
        { "reference_num": "store-77qq", "name": "Outlet", "status": "draft",
          "state": "none" }
      ]
    },
    {
      "module_key": "events", "label": "Events", "noun": "event", "supports_required": true,
      "module_wide": { "state": "connected", "connection_id": 15, "guest_checkout": true, "label": null },
      "instances": [
        { "reference_num": "evt-11", "name": "Members Workshop", "state": "connected", "connection_id": 16, "guest_checkout": false },
        { "reference_num": "evt-12", "name": "Spring Fair", "state": "inherited" }
      ]
    },
    { "module_key": "direct_payments", "label": "Direct Payments", "noun": "Direct Payment", "supports_required": false,
      "module_wide": { "state": "connected", "connection_id": 20 }, "instances": [ … ] }
  ]
}
```

`state ∈ none | connected | inherited | elsewhere`. `status` is the instance's own status
(draft / active / published) so a tenant connecting a draft store is told so, rather than
finding out when the storefront serves nothing.

### Scoping the candidates to the caller's sites

```php
/**
 * Instances are listed per the caller's owned sites, exactly as each module's
 * own admin does (EcommerceAuthorizesSites, EventsSiteScope, AppointmentsSiteScope).
 * The connection row itself is not site-scoped (02 §2.3); the LIST is, so an
 * operator with access to one site cannot connect another site's store.
 */
private function instancesFor(array $entry, Request $request): Collection
{
    $model = $entry['instance']['model'];
    $scopeColumn = $entry['instance']['site_column'] ?? null;
    $query = $model::query()->select(['reference_num', $entry['instance']['label'] . ' as name', 'status']);
    if ($scopeColumn !== null) {
        $query->whereIn($scopeColumn, $this->sites->ownedReferenceNums($request));   // the same resolver the module admins use
    }
    return $query->orderBy('name')->get();
}
```

`PUT` validates the instance the same way — an instance outside the caller's sites is
`404 SU_E_INSTANCE_NOT_FOUND`, not `403`, so the response does not confirm it exists.

### Payloads

```http
PUT /portal/api/siteusers/modules/su-a1b2/connections
{ "module_key": "ecommerce", "instance_reference_num": "store-9f3k",
  "guest_checkout": false, "label": "Main Store",
  "settings": { "cancel": { "enabled": true, "window_minutes": 60 },
                "returns": { "enabled": true, "window_days": 30 } } }
→ 200 { "success": true, "data": { …toAdminArray… } }
→ 409 { "success": false, "code": "SU_E_INSTANCE_CONNECTED_ELSEWHERE", "message": "Main Store is connected to su-b7c2. Disconnect it there first.", "connected_to": "su-b7c2" }
→ 422 { "success": false, "code": "SU_E_GUEST_CHECKOUT_NOT_SUPPORTED", "message": "Direct Payments has no checkout of its own; guest checkout does not apply." }
```

`instance_reference_num: null` (or omitted) is the module-wide row.

## 5.4 Editor behaviours that must be right

**Connect** — one click creates the row with `guest_checkout: true`. No modal. The switch and
*Settings* appear in place. Connecting must never, by itself, turn a guest away.

**Guest checkout switch** — an immediate `PATCH`. The label under the switch changes with the
value and says what it means in words:

- on → *"Guests can check out; signed-in users get order history."*
- off → *"Checkout requires an account. Guests are asked to sign in or register."*

Turning it **off** on a store that has taken guest orders in the last 30 days shows a
one-line confirm: *"This store had 41 guest orders in the last 30 days. Require sign-in
anyway?"* — the number is the point, and it comes from a count on
`laravel_ecommerce_orders where site_user_reference is null`. Events and appointments do the same
against their tables.

**Settings** — a small inline panel rendered from the registry's `settings` schema (§5.5):
label, sign-in page, and the module's knobs. Saved with one `PATCH`.

**Disconnect** — a confirm naming the consequence: *"Guest checkout turns back on for Main
Store, and it disappears from users' Dashboards. Past orders keep their owner and stay in
your order list."* **Superseded by [13](13-disconnection.md) §13.7**, which replaces this
with two differently-shaped actions: **Disconnect** (`status: 'disconnected'` — read-only, the
customer keeps seeing their history) and **Remove** (deletes the row and the group). Both are
one request; both carry the confirmation copy in that section.

**Module-wide vs instance** — disconnecting the module-wide row while instance overrides
exist shows: *"2 events have their own connection and are not affected."*

**Orphaned** — a row whose instance no longer exists (store deleted) is listed under the
module with the reference and a *Remove* action. The server marks it `state: 'orphaned'` in
the `GET` response by checking the instance model.

**Needs attention** — the banner from [03](03-database-schema.md) §3.4, reading the K1
snapshot, with a one-click *Connect to this module* that `PUT`s with the snapshot's
`guest_checkout` mapping (`guest` → true, `required` → false) and marks the snapshot row
`resolved`. A second action, *Dismiss*, marks it resolved without connecting — for the
instance a tenant looks at and decides should genuinely be public now. Both are explicit;
nothing clears this banner on the tenant's behalf.

## 5.5 The settings panel

Rendered from `config/site_users_consumers.php` → `settings`, so adding a knob to a module is
a config change, not a screen change. The renderer maps types:

| Schema value | Control |
|---|---|
| `bool` | switch |
| `int` | number input with the key's unit in the label (`window_minutes` → "minutes", `window_days` → "days", `cutoff_hours` → "hours") |
| `list<string>` | tag input |
| nested object | a titled group |

Plus two fields every module gets, above the knobs:

- **Dashboard label** — placeholder shows the resolved default (the store's name, or
  "Events").
- **Sign-in page** — a select of published pages of type `site-users` bound to this module
  whose subpage is `login`, read from `laravel_builder_module_refs` joined to the published
  row's `data-siteusers-kind` (the same scan `retired.ts` does — `findRetiredTypeInHtml`'s
  regex family), with *(auto — from the Dashboard page's protection row)* as the first option.
  Picking one writes `settings.login_path`.

The panel's copy for ecommerce's knobs:

```
Cancellation      [on ●]  Shoppers may cancel an unfulfilled order within [60] minutes of placing it.
                          A refund is not automatic — cancelled orders appear in Orders → Needs refund.
Returns           [on ●]  Shoppers may request a return within [30] days of the order.
  Reasons         [damaged ×] [wrong item ×] [not as described ×] [no longer needed ×] [other ×]
```

## 5.6 The module-form shortcuts (K9)

Each module's existing form stops carrying its own control and shows the state with a link.

### Store form — `html/views/commerce-store.html`, `commerce-store.ts`

The `<select id="storeSiteUsersModeSelect">` block is **replaced** by:

```html
<div class="mb-3" id="storeSiteUsersPanel" data-i18n-scope="commerce-store.site_users">
    <label class="form-label">Customer accounts</label>
    <div class="form-control-plaintext" id="storeSiteUsersState">Not connected — anyone can buy as a guest.</div>
    <a href="#" class="btn btn-sm btn-outline-primary" id="storeSiteUsersManage">Connect to Site Users</a>
    <div class="form-hint">Managed in Site Users → Connections.</div>
</div>
```

```ts
// commerce-store.ts — after the store is loaded / created
async function paintSiteUsersState(storeRef: string): Promise<void> {
    const body = await getJson(`/portal/api/siteusers/connections/for-instance?module_key=ecommerce&instance=${encodeURIComponent(storeRef)}`);
    const state = document.getElementById('storeSiteUsersState')!;
    const manage = document.getElementById('storeSiteUsersManage') as HTMLAnchorElement;
    if (!body?.data?.connected) {
        state.textContent = 'Not connected — anyone can buy as a guest.';
        manage.textContent = 'Connect to Site Users';
        manage.onclick = (e) => { e.preventDefault(); navigate('site-users:detail', { reference_num: body?.data?.suggested_module ?? '', tab: 'connections', focus: `ecommerce:${storeRef}` }); };
        return;
    }
    const c = body.data;
    state.textContent = `Connected to ${c.module_name} (${c.module}) — guest checkout ${c.guest_checkout ? 'on' : 'off'}${c.module_wide ? ' (via "All stores")' : ''}.`;
    manage.textContent = 'Manage';
    manage.onclick = (e) => { e.preventDefault(); navigate('site-users:detail', { reference_num: c.module, tab: 'connections', focus: `ecommerce:${storeRef}` }); };
}
```

`GET /portal/api/siteusers/connections/for-instance?module_key=&instance=` is a fifth small
admin endpoint returning the resolved state for one instance (`connected`, `module`,
`module_name`, `guest_checkout`, `module_wide`, and `suggested_module` = the sole active
module when there is exactly one). `EcommerceController::createStore()/updateStore()` stop
accepting `site_users_mode` from the request — the field is derived now; a posted value is
ignored and logged at `debug` for one release.

**When no Site Users module exists**, the shortcut reads *Set up Site Users* and navigates to
`/site-users` — the "empty list is a first-run state" rule.

### Event, appointment and Direct Payment forms

Same panel, same fetch, keyed `events:<ref>`, `appointments:<ref>`,
`direct_payments:<ref>`. `EventController::update()` and `AppointmentsController::update()`
stop reading `site_users_mode`.

## 5.7 The Modules index

`controllers/modules/modulesIndex.ts` — the card for a connectable module gains a line from
one aggregate call, `GET /portal/api/siteusers/connections/summary`:

```json
{ "ecommerce": { "instances": 4, "connected": 2, "module_wide": false },
  "events": { "instances": 12, "connected": 12, "module_wide": true },
  "appointments": { "instances": 1, "connected": 0, "module_wide": false },
  "direct_payments": { "instances": 3, "connected": 3, "module_wide": true } }
```

Rendered as *"2 of 4 stores connected to Site Users"* / *"All events connected"* /
*"Not connected to Site Users"*, linking to the tab. The Site Users card itself shows
*"5 connections"*.

## 5.8 The per-user admin screen

`initSiteUsersUserView` (`/site-users/user`) already shows identities, sessions and
`customer_links`. It gains a **Linked activity** card fed by the same reads the dashboard
uses, server-side ([08](08-dashboard-sections.md) §8.3's `SiteUsersDashboardService`) but
through an admin route: `GET /modules/{ref}/users/{id}/activity` → per connected module,
counts and the five most recent rows with deep links into that module's admin
(`ecommerce:order-detail`, `events:detail`, `appointments`). Read-only. This is what makes
"a customer emailed about their order" answerable from the user's page.

## 5.9 i18n and tests

- New keys under `site-users-detail.connections.*` in `i18n/en.json` only (the other four
  locales fall back, per the existing practice noted in
  [site_users/10 complete](../site_users/complete/10-admin-dashboard.complete.md)).
- `tests/Feature/SiteUsers/SiteUsersConnectionsAdminTest.php`:
  - candidates are scoped to owned sites;
  - `PUT` creates with `guest_checkout: true` by default;
  - `PUT` for an instance connected elsewhere → `409` with `connected_to`;
  - `PATCH guest_checkout:false` on `direct_payments` → `422`;
  - `DELETE` removes the row **and** rewrites the derived column to `off`;
  - `for-instance` returns `module_wide: true` for an inherited instance;
  - `attention` lists an instance whose legacy column is `required` and has no row; after
    `PUT`, it does not.
- Admin SPA: `npx tsc --noEmit`; the `modulesIndexLinks` test still passes (no new view ids).

## 5.10 Acceptance

- A tenant can connect a store, flip guest checkout off, and see the same state on the store
  form's panel and the Modules index card, without a page reload cycle beyond navigation.
- The switch is not rendered for an unconnected instance or for Direct Payments.
- Disconnecting names the consequence and offers Pause.
- An instance connected to another module is visible, named, and not editable here.
- Every write goes through `SiteUsersConnectionService` — `grep -rn "SiteUserConnection::" app/Http/Controllers` finds only reads.
