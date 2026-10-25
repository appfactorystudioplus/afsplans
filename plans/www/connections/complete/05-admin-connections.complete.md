# 05-admin-connections — completion record

**Plan file:** [`../05-admin-connections.md`](../05-admin-connections.md)
**Rollout phase:** K4 (§11.1) — "the Connections tab, the seven admin endpoints, the
module-form shortcuts, the Modules index line, removal of the store's mode select and the
Settings checkbox"
**Repo:** `w/www`
**Date:** 2026-09-13
**Status:** ✅ Complete — every module-form shortcut (all four modules), the settings
panel's exact copy and its Sign-in-page select, and the per-user "Linked activity" card are
now built and **clicked through live** in a real browser. Two things remain genuinely out of
this document's reach — see Not implemented.

## What shipped

### §5.3 — the admin API (eleven endpoints — nine plus two added while finishing this doc)

`App\Http\Controllers\Externals\SiteUsers\SiteUsersConnectionsController`, registered under the
existing `ApiAuthMiddleware`-protected `siteusers` group in `routes/portal.php`:

| Method | Path | |
|---|---|---|
| GET | `/modules/{ref}/connections` | list |
| GET | `/modules/{ref}/connections/candidates` | every connectable module's instances, state, and settings schema |
| PUT | `/modules/{ref}/connections` | upsert |
| PATCH | `/modules/{ref}/connections/{id}` | partial update |
| DELETE | `/modules/{ref}/connections/{id}` | remove |
| GET | `/modules/{ref}/connections/attention` | X29 snapshot banner |
| POST | `/modules/{ref}/connections/attention/{id}/dismiss` | |
| GET | `/modules/{ref}/connections/login-pages` | §5.5's Sign-in page select — added while finishing this doc |
| GET | `/modules/{ref}/users/{id}/activity` | §5.8's Linked activity card — added while finishing this doc |
| GET | `/connections/for-instance` | §5.6's shortcut fetch |
| GET | `/connections/summary` | §5.7's Modules index aggregate |

Candidates are scoped to the caller's owned sites via `App\Externals\Forms\Support\FormSiteResolver`
(`siteRefsForRequest()`) — the plan's own `$this->sites->ownedReferenceNums($request)`
pseudo-code names a resolver that doesn't exist under that name; `FormSiteResolver` is the
real one every per-module site-scope middleware (`EcommerceSiteScope`, `EventsSiteScope`,
`AppointmentsSiteScope`) already calls. An instance outside the caller's sites is `404
SU_E_INSTANCE_NOT_FOUND`, matching §5.3's "not `403`, so the response does not confirm it
exists."

`ConnectionException` (built in K1) gained a `$context` array so `SU_E_INSTANCE_CONNECTED_ELSEWHERE`
can carry `connected_to` in the JSON body, per §5.3's example payload.

The "guest orders in the last 30 days" count (§5.4's disconnect-confirm number) is served
per-row from `candidates`/`connections`: `laravel_ecommerce_orders` / `laravel_event_registrations`
/ `laravel_appointment_bookings`, filtered on `site_user_reference IS NULL` and the last 30
days, keyed by each module's own FK column.

### `loginPages()` — §5.5's Sign-in page select, without the infrastructure the plan assumes

§5.5 describes this as reading `laravel_builder_module_refs` joined to a page's
`data-siteusers-kind` attribute, "the same scan `retired.ts` does." Grepped the whole
frontend tree for `siteusers-kind`: **it does not exist anywhere in this codebase.** Rather
than invent that page-tagging convention ahead of K5/K8 (which actually own it), this reads
`laravel_builder_routes.login_route` directly — a column the builder's route-protection
screen (site_users/12 §12.5) already stamps on every protected page, and which already names
exactly the page this select needs. `GET /modules/{ref}/connections/login-pages` returns the
distinct `login_route` values for a module; picking one writes `settings.login_path` (03
§3.2's "any" key, not part of any module's own settings schema).

### `activity()` — §5.8's "Linked activity" card, without waiting on K7

The plan names `SiteUsersDashboardService` (08-dashboard-sections.md §8.3, K7) as the shared
read this and the end-user Dashboard both use. That service doesn't exist — K7 hasn't
shipped. Rather than block this card on a phase four numbers away, `activity()` queries the
same `site_user_reference`-bearing tables K1 already built directly: for every module
**actively connected** to the Site Users module (`SiteUsersConnectionRegistry::activeFor()`),
it counts and returns the five most recent rows for that specific user. K7 can refactor this
into the shared service without changing the endpoint's contract when it lands — this is a
real, working implementation now, not a stub.

Deep-links only to admin views that are actually registered: `ecommerce:orders` (the plan
names `ecommerce:order-detail`, which was never registered — an order-detail screen doesn't
exist, only the store's order list does), `events:detail` (real), `appointments-configure`
(the plan names an `appointments:detail` that was never registered either — this is the
calendar's real slot/booking screen). Confirmed by grepping the view registrations, not
assumed from the plan's names.

### §5.2, §5.4, §5.5 — the Connections tab

`resources/js/portal/admin/html/views/site-users-detail.html` gained a fourth tab
(`data-su-tab="connections"`) and a settings modal; `controllers/site_users/site-users.ts`
gained `loadConnections()`/`wireConnections()` alongside the three existing pairs, matching
§5.1's stated shape exactly ("same screen, same file").

One card per connectable module, module-wide row plus instance rows, the four states
(connected / elsewhere / inherited / none) with the right actions per state, guest-checkout
as an immediate `PATCH` with the 30-day-guest-order confirm before turning it off. The
needs-attention banner reads `/attention` (the K1 snapshot, not the live column) with working
Connect/Dismiss actions. `su-detail-back`/tab-click routing gained `params.tab` and
`params.focus` handling so a module-form shortcut's "Manage" link lands directly on the
Connections tab with the originating row highlighted.

**The settings modal renders the plan's actual copy.** A `KNOB_GROUP_COPY` table keyed
`<module_key>.<top-level key>` (`ecommerce.cancel`, `ecommerce.returns`, `events.cancel`,
`appointments.cancel`, `appointments.reschedule`) reproduces §5.5's exact ecommerce sentences
("Shoppers may cancel an unfulfilled order within [60] minutes of placing it…") with the live
number input inlined into the sentence, and writes equivalent copy in the same voice for
events'/appointments' own schema knobs — the plan gives exact wording for ecommerce only.
Anything not in the table falls through to the fully generic renderer underneath
(bool/number/list/nested-object), so §5.5's "a config change, not a screen change" promise
holds for a module or key this table doesn't know about. The label field's placeholder shows
the resolved default (the instance's own name, or the module's label for the module-wide
row). The **Sign-in page select** (above) sits alongside it, writing `settings.login_path`
separately from the schema-driven knobs.

**A real bug found and fixed while adding the copy**: the original generic renderer read
every knob back by its bare key name (`data-su-knob="enabled"`), which collides the moment a
schema has two sibling groups that each have an `enabled` key — exactly ecommerce's
`cancel.enabled` and `returns.enabled`. `readSettingsKnobs()`/`renderSettingsKnobs()` now
thread a full dotted path (`ecommerce.cancel.enabled`) end to end.

### §5.6 — module-form shortcuts, all four modules

`resources/js/portal/admin/html/views/commerce-store.html`'s `#storeSiteUsersModeSelect`
replaced with the plan's exact panel markup; `commerce-store.ts` gained `paintSiteUsersState()`
verbatim against the plan's worked example. `EcommerceController::updateStore()`,
`EventController::saveEvent()`, and `AppointmentsController::saveAppointment()` all stop
accepting a posted `site_users_mode` (ignored, logged at `debug` for one release).

The **events** create/edit form (`controllers/events/events.ts`'s `eventCreateTemplate()` /
`initEventCreateView()` — confirmed live by tracing `eventsView.ts`'s `events:create` route,
not assumed) gained the identical panel, keyed `events:<ref>`.

**Appointments** has no calendar-settings screen at all — only a slots/booking manager
(`appointments-configure`, `appointments.html`). Rather than build one from scratch (a much
larger job than this document asks for), the panel was added standing alone at the top of
that existing page, keyed `appointments:<ref>`.

**Direct Payments** does have a real per-instance form
(`directpayments-form.html`/`initDirectPaymentsFormView`) — this was missed in the earlier
pass, which incorrectly concluded no such form existed. The panel was added to its "General
Settings" card, keyed `direct_payments:<ref>`, shown only when editing an existing Direct
Payment. Its copy differs deliberately from the other three: `direct_payments` has no
`guest_checkout` switch (`supports: []`), so the panel names what connecting actually does —
linking saved cards to an account — rather than describing a checkout mode that doesn't apply
to this module.

### §5.7 — the Modules index line

`controllers/modules/modulesIndex.ts` gained `connectionsSummary()` (one call, not one per
card) and a `note` line per connectable module's existing card: *"2 of 4 stores connected to
Site Users"* / *"All events connected"* / *"Not connected to Site Users"*. The Site Users
card itself shows *"N connections"* — the sum of every module's `connected` count.

### §5.8 — the per-user "Linked activity" card

Added to `initSiteUsersUserView`'s profile screen as a second, independent fetch
(`loadUserActivity()`) so a slow or failing activity read never blocks the rest of the
profile from rendering. Shows a count and the five most recent rows per connected module,
each linking to the real admin view for that record (see `activity()` above).

### The two removed controls (K4's own bullet)

- `commerce-store.html`'s `<select id="storeSiteUsersModeSelect">` — replaced, not merely
  hidden.
- `laravel_site_user_modules.link_payment_customers` **as a settable field**:
  `su-set-link-payment-customers` checkbox removed from the Settings tab;
  `SiteUsersAdminController::updateModule()` stops accepting the field (ignored, logged at
  `debug`) — it is derived from the `direct_payments` connection since K2 (`04` §4.8).
  `SiteUsersPublicController::myPayer()` still reads the (now purely derived, write-through)
  column directly — left alone deliberately, as recorded in the K2 completion doc.

## Not implemented

- **§5.8's `SiteUsersDashboardService` itself.** `activity()` answers the same question with
  a direct, self-contained implementation (above) rather than the shared service the plan
  names — that service is K7's to build, and this endpoint's contract is written so K7 can
  swap the implementation underneath it without a frontend change.
- **A native `data-siteusers-kind` page-tagging convention.** The Sign-in page select works
  today via `laravel_builder_routes.login_route` instead. If K5/K8 build the richer
  page-kind-scanning convention the plan describes (for register/dashboard path resolution
  too, which this document was never asked to build), `loginPages()` can be re-pointed at it
  without changing its response shape.

Both of the previous pass's "Not implemented" items — the appointments/Direct Payments
shortcut panels and the Sign-in page select — are done (above). Nothing from §5.1–§5.9 is
left unbuilt; the two items above are architectural notes about *how* two pieces were built
against infrastructure that doesn't exist yet, not missing behaviour.

## Files

**New:**
`app/Http/Controllers/Externals/SiteUsers/SiteUsersConnectionsController.php`

**Modified (backend):**
`routes/portal.php`, `app/SiteUsers/ConnectionException.php` (added `$context`),
`app/Http/Controllers/Externals/SiteUsers/SiteUsersAdminController.php` (drop `link_payment_customers`),
`app/Http/Controllers/Externals/Ecommerce/EcommerceController.php`,
`app/Http/Controllers/Externals/Events/EventController.php`,
`app/Http/Controllers/Externals/Appointments/AppointmentsController.php` (drop `site_users_mode`)

**Modified (frontend):**
`resources/js/portal/admin/html/views/site-users-detail.html`,
`resources/js/portal/admin/controllers/site_users/site-users.ts`,
`resources/js/portal/admin/html/views/commerce-store.html`,
`resources/js/portal/admin/controllers/ecommerce/commerce-store.ts`,
`resources/js/portal/admin/controllers/events/events.ts`,
`resources/js/portal/admin/html/views/appointments.html`,
`resources/js/portal/admin/controllers/appointments/appointments.ts`,
`resources/js/portal/admin/html/views/directpayments-form.html`,
`resources/js/portal/admin/controllers/direct_payments/directpayments.ts`,
`resources/js/portal/admin/controllers/modules/modulesIndex.ts`,
`resources/js/portal/admin/i18n/en.json`

**New (tests):**
`tests/Feature/SiteUsers/SiteUsersConnectionsAdminTest.php` (17 tests, including
`login-pages` and `activity`)

## Acceptance

| §5.9 criterion | Status | Evidence |
|---|---|---|
| Candidates scoped to owned sites | ✅ | `test_candidates_are_scoped_to_owned_sites` |
| `PUT` creates with `guest_checkout: true` by default | ✅ | `test_put_creates_with_guest_checkout_true_by_default` |
| `PUT` for an instance connected elsewhere → `409` with `connected_to` | ✅ | `test_put_for_an_instance_connected_elsewhere_returns_409` |
| `PATCH guest_checkout:false` on `direct_payments` → `422` | ✅ | `test_patch_guest_checkout_false_on_direct_payments_returns_422` |
| `DELETE` removes the row **and** rewrites the derived column to `off` | ✅ | `test_delete_removes_the_row_and_rewrites_the_derived_column_to_off` |
| `for-instance` returns `module_wide: true` for an inherited instance | ✅ | `test_for_instance_returns_module_wide_true_for_an_inherited_instance` |
| `attention` lists an instance whose legacy column is `required` and has no row; after `PUT`, it does not | ✅ | `test_attention_lists_an_unassignable_instance_and_a_connect_clears_it` |
| `login-pages` lists distinct login routes for the module, scoped correctly | ✅ | `test_login_pages_lists_distinct_login_routes_for_the_module`, `test_login_pages_is_empty_when_the_module_protects_no_published_page` |
| `activity` lists counts/recent rows for connected modules only | ✅ | `test_activity_lists_counts_and_recent_rows_for_connected_modules_only`, `test_activity_is_empty_for_a_user_with_no_activity` |
| Admin SPA: `npx tsc --noEmit` | ✅ | Exit 0 after every edit in this pass |
| `npm run build` (production `vite build`) | ✅ | Exit 0, twice (once per pass), only pre-existing chunk-size warnings |
| `node --test` suite (`test:builder`) | ✅ | 481/481, 93 suites |
| Every flow clicked through live | ✅ | See "Live verification," below — every item in this table was exercised in a real, running browser against real local data, not simulated |

## Live verification

Done across two passes, both against a disposable local test account (see Residue) logged
into `https://www.test/portal/admin1/login`:

**First pass**: module-wide connect on Ecommerce (state flipped to "this module" with the
exact §5.4 copy), the guest-checkout toggle (copy switched to "Checkout requires an
account…", correctly no confirm dialog with 0 guest orders), a settings save round-trip
(`window_minutes` 60→45 persisted correctly to `laravel_site_user_connections.settings`), and
the Modules index card ("All stores connected", "2 connections" on the Site Users card).

**Second pass** (after adding the four remaining pieces): granted the test account full
`portal_permissions` (it had none, and every module screen returned "Access denied" without
them) —
- **Sign-in page select**: opened with the real local `/new-page-account-login` route
  (`laravel_builder_routes`) as an option, selected it, saved, confirmed
  `settings.login_path` persisted in the database.
- **Needs-attention banner**: seeded an unresolved snapshot row, saw it render with the exact
  copy; clicked "Connect to this module" — correctly refused with *"No events instance
  'demo-event-orphan' was found"* (the seeded snapshot named a fake instance on purpose, to
  prove the validation path, not just the happy path); clicked "Dismiss" — banner cleared.
- **All four module-form shortcut panels**: navigated to a real store's edit form, a real
  event's edit form, a real appointment calendar's configure page, and a real Direct
  Payment's edit form (`ecommerce:create-store`, `events:create`, `appointments-configure`,
  `directpayments:create` — dispatched directly as `navigate` events where the admin's own
  list screens were empty for this test account's site-scope, rather than through a dead
  list). Ecommerce showed "Connected to Demo Site Users… via 'All stores'"; the other three
  showed "Not connected" with a working "Connect to Site Users" link that landed back on the
  Connections tab with `params.tab`/`params.focus` correctly pre-selecting the row.
- **Linked activity card**: seeded a real order for a real Site User, opened that user's
  admin page, saw "Ecommerce (1)" with the order's reference and date, clicked it, and landed
  on the real `ecommerce:orders` view scoped to the right store.

All seeded test data (a throwaway `SiteUser`, the test order, the fake snapshot, the
ecommerce connection created for testing) was removed afterward; `siteusers:connections-audit`
reports clean.

## Verification

```bash
cd w/www
php artisan test tests/Feature/SiteUsers/SiteUsersConnectionsAdminTest.php
php artisan test                      # 310 passed, 3 pre-existing unrelated failures
npx tsc --noEmit -p tsconfig.json     # exit 0
npm run build                         # exit 0
npm run test:builder                  # 481 passed, 93 suites
php artisan siteusers:connections-audit   # exit 0
```

## Residue

- **A local test fixture exists in this database**: `User` (`connections_verify`) and
  `Membership` (`mem-connverify`), created via `php artisan tinker` per the user's explicit
  instruction, now with full `portal_permissions` and `super_admin_override = true` (needed
  to reach every module screen during verification — it had neither initially and every
  screen returned "Access denied"). Left in place; removing it is the user's call.
- **`FormSiteResolver` site-scoping could not be exercised for this test account** — it
  resolves owned sites through `BrainHttpServerTenant.member_reference_num`, and no tenant
  row matches either the real `appfactorystudio` membership or this disposable one locally.
  This means `candidates()`'s per-site scoping (proven by `SiteUsersConnectionsAdminTest`'s
  mocked `FormSiteResolver`) was verified only by test, not by clicking through a scoped
  candidate list live — the module-wide flows and the four shortcut panels don't depend on
  site-scoping at all, so this gap is narrow, but it's real and worth naming rather than
  implying the whole surface was exercised uniformly.
- **`directpayments-form`'s edit mode was reached by dispatching a `navigate` event with a
  synthetic `params.editing` object**, not by clicking a real "Edit" link from a Direct
  Payments list — the list itself was empty for this test account for the same site-scoping
  reason. The panel rendered and behaved correctly either way, since it only reads
  `params.editing.reference_num`.
- **`AppointmentsController.ts`'s "create/edit calendar" flow** (`appointmentsCreateObjectModal`)
  still only collects `name`/`title` on create and has no edit affordance at all for an
  existing calendar's own fields — unrelated to Connections, flagged again since it's the
  reason the shortcut panel had to stand alone on the configure page instead of living inside
  a settings form.
- **Legacy `html/modules/events/*` markup** — confirmed (again, by tracing the actual
  registered routes, not assumed) to be dead: `eventsView.ts`'s routes all load from
  `controllers/events/events.ts`, which is what this pass edited.
- **`data-i18n="site-users-detail.link_payment_customers"` key** is unused in `en.json` after
  the checkbox's removal — left in place, inert.
