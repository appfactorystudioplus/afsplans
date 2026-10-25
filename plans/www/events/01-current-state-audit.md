# 01 — Current State Audit

Findings are numbered `V#` (events ≈ *venue*; `E#` is taken by
[ecommerce](../ecommerce/02-current-state-audit.md), `F#` by
[forms](../forms/01-current-state-audit.md)).

## 1.1 What exists

| Layer | State | LOC |
|---|---|---|
| Migration | `2026_07_23_000001_create_laravel_events_tables.php` — **one** table | 33 |
| Model | `App\Models\Externals\Event` | 30 |
| Controller | **None** | — |
| Routes | **None** | — |
| **Admin UI** | `resources/js/portal/admin/controllers/events/events.ts` | **708** |
| View registration | `resources/js/portal/admin/views/eventsView.ts` — 6 subviews | 160 |
| Client store | `resources/js/portal/admin/store/DataStore.ts` — events **and discounts** in `localStorage` | — |
| SDK | `resources/js/external/events/api.ts` | 78 |
| SDK contract | `portal.d.ts` — `ExternalEventsAPI` (ambient `declare class`), `PortalEventsAPI` | — |
| Builder | `componentData.events` is fetched and **never registered as a component** | — |
| afs-connect | `app/AfsConnect/Providers/WwwEventsProvider.php` — `isStub() === true` | — |

```php
laravel_events
    id · reference_num (unique, 20) · name · description
    status (default 'draft') · event_date (date, index) · archived (tinyint)
    direct_payment_reference_num · payload JSON
    website_domain · website_reference_num · timestamps
```

One table. **No occurrences, no ticket types, no orders, no registrations, no attendees.**

### The six registered subviews

`events` · `events:create` · `events:detail` · `events:preview` · `events:response` ·
`events-discounts`. All lazily loaded through `makeLazyViewListener`, following the same
pattern as [forms](../forms/README.md) and the rest of the admin.

The UI is further along than the audit's first pass suggested: the create screen has event name
and title, reference, Direct Payment selection, **repeatable ticket levels each mapped to a
Direct Payment item**, start/end date and time, total tickets, age restriction and description.
The detail screen renders all of it. There is an iframe preview and an
`EmailConstructionBuilder` response screen.

**All of it writes to `localStorage`.**

## 1.2 The module has no back end

| # | Finding | Consequence |
|---|---|---|
| **V1** | **There is no events controller and no events routes.** `grep` finds `App\Models\Externals\Event`, an afs-connect provider, and nothing else. The `/events/{ref}` route at `routes/portal.php:474` is `SponsorPortalController::getEvent` — sponsorships, a different feature | There is no server-side events API at all |
| **V2** | **The SDK posts to `/_portal_/admin/core/php/request.php`** (`external/events/api.ts:31`). **`public/_portal_` does not exist in this repository** — the whole legacy tree is absent — and no PHP handler for `admin_events_get_events`, `admin_events_create` or `admin_events_update` exists anywhere in `app/` or `public/`. The only matches are inside `public/assets/api/afsapi.js`, a **built copy of the SDK itself** | `getEvents`, `createEvent` and `updateEvent` have never worked against this codebase. `routes/portal.php` even `include_once`s two files under that missing tree, which fails silently |
| **V3** | **No occurrence, ticket, order, registration or attendee table.** `laravel_events` has `payload` JSON and nothing relational | There is no record of who is coming. An event cannot be registered for, ticketed, or capacity-limited |
| **V4** | `direct_payment_reference_num` exists on the event and is **read by nothing** server-side | Same unfinished linkage as ecommerce's `stores.direct_payment` — the intent was right and there is no checkout to finish it into |
| **V5** | `componentData.events` is fetched by the builder (`main.ts:38`, `:72`) but **no event component or block is registered** | Events cannot be placed on a page |
| **V6** | The SDK's `createEvent` / `updateEvent` are **admin writes on the public storefront SDK** | Same shape as appointments **A6**: were the endpoint to exist, the destructive surface would be published as client API |

## 1.3 The admin UI — what is actually wired

This is the part the first pass did not audit, and it changes the plan's starting point: there
is real UI to keep, and a set of defects that come with it.

| # | Finding | Consequence |
|---|---|---|
| **V7** | **`ExternalEventsAPI` is not a global, but `events.ts:13` constructs one at module scope.** The externals bundle assigns only `window.Portal` (`external/main.ts:113–116`) and exposes the *instance* as `Portal.events`; the class is never attached to `window`, and `webpack.config.cjs` sets no `library` name. The admin's `portal.d.ts:747` declares it `declare class`, which satisfies the compiler and emits nothing | `const eventsApi = new ExternalEventsAPI()` should throw **`ReferenceError`** when the module is dynamically imported — **before any view initialises**. If so, every events screen fails at the lazy loader's error path. **Verify in a browser first** ([14](14-rollout-and-testing.md) §14.2); it changes whether P1 is a repair or a rebuild |
| **V8** | **The module's real store is `localStorage`.** `loadEventsFromServer()` catches the failure with `console.warn('…falling back to local store')` (`:112–120`) and `dataStore` persists via `saveToLocalStorage()` (`DataStore.ts:1064`, `:1080`) | Events exist only in the browser that created them. Another admin, another device or a cleared cache sees nothing. Two admins have two different event lists and neither is wrong |
| **V9** | **Reference numbers are generated in the browser**, as `'e' + Math.random().toString(36).substring(2,8)` truncated to 7 characters (`:16–19`) | Roughly 36⁶ of entropy from a non-cryptographic PRNG, with no server-side uniqueness check. Two admins creating events in the same minute can collide, and the column is `unique(20)` — the insert would fail, if there were an insert |
| **V10** | Price-level ids are generated the same way (`generatePriceLevelId`, `:21–23`) | Same collision surface for the ids that will key ticket types |
| **V11** | **Delete never calls the server.** The handler does `dataStore.deleteEvent(...)` after a `confirm()` and re-renders (`:157–166`) | Deleting appears to work and is undone by a page reload. Once an API exists, this is a silent data-integrity divergence rather than a missing feature |
| **V12** | `saveEventFromCreateForm()` decides create-vs-update on the presence of a hidden `eventId` field (`:435`, `:505–512`), while the edit path is reached with `reference_num` and `populateCreateForm()` is only called when the event is already in the local store (`:281–285`) | Editing an event that is not in this browser's `localStorage` silently creates a second one under a new reference |
| **V13** | The create form is validated only by `alert('Please complete all required fields.')` (`:448–451`), and **no date validation exists** | End before start, an event in the past, a zero-length event and a 400-day event are all saveable |
| **V14** | `totalTickets` is a single number on the event, and `ticketsSold` is hard-coded to `0` on every save (`:477`) | Capacity is one bucket for all ticket levels, and the sold counter is reset by editing the event. Appointments **A10** — a capacity stored and never enforced — with an extra way to corrupt it |
| **V15** | **Ticket levels carry no price.** A level is `{ id, levelName, itemReferenceNum, itemLabel }`; the price lives on the Direct Payment item, and `Event.price` is hard-coded to `0` (`:470`) | Nothing in the module knows what a ticket costs. `getDirectPaymentItems()` reads `item.price` (`:65–75`) and discards it |
| **V16** | Levels with an empty name or item are **silently dropped** at save — `.filter(level => level.levelName && level.itemReferenceNum)` (`:462`) | A half-configured ticket level disappears with no message, and the operator finds out from the detail screen |
| **V17** | `startDate`, `startTime`, `endDate`, `endTime` are stored as **four separate strings** with **no time zone** (`:441–444`) | Concatenated for display as `` `${startDate} ${startTime}` `` (`:600–601`). No instant is representable, so no reminder can be scheduled, no ICS file can be generated, and no cross-zone attendee can be told the right time |
| **V18** | `ageRestriction` is `all` / `18+` / `21+` — a US-centric enum with no enforcement anywhere | Presentational only, and wrong in most jurisdictions the platform serves |
| **V19** | `normalizeServerEvent()` (`:38–59`) invents a reference when one is missing, defaults the name to `'Untitled Event'`, and reads everything from `raw.json` | It is written against the legacy endpoint's shape, not against `laravel_events` — whose columns are `name`, `description`, `status`, `event_date`, `payload`. **The client and the table disagree about the record's shape**, and neither has ever been reconciled |
| **V20** | `initEventPreviewView()` builds an HTML document, wraps it in `URL.createObjectURL(new Blob(...))` and sets it as an iframe `src` (`:636–679`) | The blob URL is **never revoked**, so every preview leaks. More importantly the preview is a hand-written approximation that shares no code with any public renderer — the same divergence [forms **F40**](../forms/01-current-state-audit.md) documents |
| **V21** | The `events-discounts` subview loads `initDiscountsView()` from `views/discounts`, backed by `dataStore`'s `DiscountCode` CRUD (`DataStore.ts:125`, `:1126–1147`) | Discount codes are a **client-side-only** feature with no server, no redemption, no usage limit enforcement and no link to any ticket. It exists in the navigation and cannot work |
| **V22** | `initEventResponseView()` saves mail handlers under `ViewsCategories.EVENTS` (`:681–707`) | The same shape as [forms **F57**](../forms/01-current-state-audit.md): a configuration screen whose output nothing server-side reads, because there is no registration event to send on |
| **V23** | `initEventCreateView()` and `initEventDetailView()` write `target.innerHTML = template()` directly into `dashboard-content` (`:272–275`, `:580–583`), bypassing the `TEMPLATES` registry the other five subviews use | Two template mechanisms in one module. The `body:` registered in `eventsView.ts` is discarded for these views |
| **V24** | No loading states, no error surfaces — failures are `console.warn` (`:118`, `:126`) or `alert()` (`:449`, `:592`, `:514`) | The most common runtime outcome (**V2**: every server call fails) is invisible to the operator, who sees an empty list and no explanation |
| **V25** | `WwwEventsProvider::isStub()` returns `true` and `summary()` reports only `events_count` | afs-connect knows how many event rows exist and nothing about registrations, sales or attendance |
| **V26** | `public/assets/api/afsapi.js` is a **second, checked-in build of the externals SDK**, containing its own copy of the events client | Two SDK builds in the repository. A fix to `external/events/api.ts` does not reach whatever loads `afsapi.js`, and the admin actually loads a **third** copy from `https://{afssdk}.appfactory.studio/sdk.js` (`portal/admin/index.blade.php:25`) |

## 1.4 What is good, and worth keeping

Genuinely — the module's problem is that it stops at the browser, not that it is badly made.

- **`escapeHtml()` is defined once and used consistently** — every interpolation in the table,
  the detail view, the option list and the preview passes through it (`:25–36`). That is
  more discipline than [forms](../forms/01-current-state-audit.md) showed (**F35**, **F36**),
  and the rewrite should not lose it.
- **The ticket-level → Direct Payment item mapping is the right idea.** An event names a Direct
  Payment; each level names an item on it ([08](08-payments-and-refunds.md) §8.4). It is
  precisely the linkage `stores.direct_payment` was reaching for in ecommerce, and here it is
  already in the UI.
- **The six subviews and their lazy registration are the right structure**, and are reused
  ([12](12-admin-dashboard.md) §12.9).
- **The event/registration shape is absent, not wrong.** Nothing has to be unpicked.
- `status` and `archived` already exist for a lifecycle.
- `website_domain` / `website_reference_num` anticipate multi-site scoping — and unlike
  [forms **F3**](../forms/01-current-state-audit.md), no rows exist yet to backfill.
- `payload` JSON is a reasonable place for presentational event data — banner, agenda copy —
  that nothing needs to query. **It is not where registrations go** (**V3**).
- **Nothing is exposed**, so the schema can be designed correctly rather than migrated
  carefully.

## 1.5 What that means for guarding

There is nothing to secure yet, which changes the phase order relative to the other modules:

| Module | Security posture |
|---|---|
| [ecommerce](../ecommerce/README.md) | Unauthenticated destructive writes — **P0 immediately** |
| [appointments](../appointments/README.md) | Unauthenticated calendar wipe + PII leak — **P0 immediately** |
| [forms](../forms/README.md) | Unauthenticated read/write of every tenant's forms — **P0 immediately** |
| **events** | **No API exists.** Nothing to close |

The lesson from all three is the same: **their admin and public APIs were never separated**,
which is how each ended up with the wrong middleware. Events starts with the split
([02](02-site-users-guard.md) §2.2) rather than discovering it later.

## 1.6 Summary

| Group | Findings | Phase |
|---|---|---|
| No back end | **V1**, **V3** | **P1** |
| Broken / duplicated SDK | **V2**, **V6**, **V7**, **V26** | **P1** |
| Client-only persistence and identifiers | **V8**–**V12**, **V21** | **P1** |
| Model defects to fix while rebuilding | **V13**–**V19** | P1–P3 |
| UI quality | **V20**, **V23**, **V24** | P6 |
| Unfinished payment link | **V4** | P4 |
| Builder and reporting absence | **V5**, **V22**, **V25** | P5–P7 |

Two things decide where P1 starts, and both are cheap to establish:

1. **Does the events module load at all?** (**V7**) Open the events view in a browser and read
   the console. A `ReferenceError` means the existing UI has never run in this deployment, and
   P1 is a rebuild against a new API rather than a repair of a working screen.
2. **Is there anything in anyone's `localStorage`?** (**V8**) If tenants have been creating
   events that only exist in their browsers, there is a one-time import to offer
   ([14](14-rollout-and-testing.md) §14.4) — and it is the only migration this module has.
