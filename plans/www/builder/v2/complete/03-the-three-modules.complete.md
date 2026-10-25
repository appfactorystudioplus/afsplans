# 03 — Ecommerce, events, appointments (complete)

## Status

**All three modules are page-level, on the same contract.** Bind an instance
and the page list is the views — not a palette category. The runtimes are
the v1 scripts (`commerceComponentScript`, `eventsComponentScript`,
`appointmentsComponentScript`), compiled the v1 way.

This file is what [03](../03-the-three-modules.md) became. The contract
those pages sit on is [02](02-page-level-contract.complete.md). The order
of work is [04](04-phases.complete.md).

**415 builder tests.** Application code is www, uncommitted, on the v1 07
tree.

| Module | `pageType` | Instance property | Pages created on bind | Palette |
|---|---|---|---|---|
| ecommerce | `e-commerce` | `pageCommerce` | Products, Product, Cart, Checkout, My Orders | gone |
| events | `events` | `pageEvent` | List, Detail, Register | gone |
| appointments | `appointments` | `pageAppointment` | Booking, Services, Staff, Next available, My Bookings | gone |

G3 is the point of the table: events is not “views of one page.” A
different shape in P4 would have cost the shared `ensureBoundSubpages`
path.

## 3.1 Ecommerce

`ecommerce.module.ts` used to declare `subpages: ['cart', 'checkout']` —
the two children template-picking already created lazily. It now declares
the five §3.1 named:

```
['products', 'product', 'cart', 'checkout', 'orders']
```

`register()` still calls `registerCommerceComponents` (types, so a draft
with `module-commerce-grid` can open). It does not call `addCommerceBlocks`.
The Commerce category disappears with the adds.

### The page list

Bind a store → `ensureBoundSubpages` creates or reuses five children of
that page, all sharing `pageCommerce`. The parent stays a folder in
`pageManager.ts`; the children are the store. Products is not “the page,
plus hidden dropdowns.”

`ecommerceTemplates.ts` gained two templates the listing did not have:

| `pageSubpage` | Template id | What the canvas gets |
|---|---|---|
| `products` | existing store templates (`grid` / `list` / `minimal` / `banner`) | today’s catalogue markup |
| `product` | `product` | **new.** One-product layout; editor preview slug is `pageCommerceProductSlug` |
| `cart` | existing cart templates | today’s cart markup |
| `checkout` | existing checkout templates | today’s checkout markup |
| `orders` | `orders` | **new.** “Sign in to see your history.” `pageAuth` defaults to `page` |

Selecting a store still runs the existing `renderStoreTemplate` path for
the Products view when a template is picked. Children that are new or
empty get `stampPageModuleHtml` around a placeholder (or the template
HTML when one is chosen). Live checkout does not run in the canvas (v1
D5).

### Page panel

| Control | Shown on | Source |
|---|---|---|
| Page Type `e-commerce` | parent | existing `<select>` |
| Store | all five | `ecommerceInstances` — **not** `/portal/api/v1/builder/stores` |
| Products / Cart / Checkout / Footer templates | matching view | existing selects |
| Product template | Product | **new** `#page-commerce-product-template` |
| Preview product slug | Product | **new** `#page-commerce-product-slug` |
| Direct Payment | Checkout | **new** `#page-commerce-dp-select` from `directPaymentsInstances` |
| Auth | all five | existing radios. My Orders defaults to `page` |

### Runtime

Still `commerceComponentScript`. The stamp sets `data-commerce-kind` from
the subpage (`products` → `product-grid`, `product` → `product-detail`,
`orders` → `my-orders`, …). The published page does not grow a second
script. Confirmation stays on Checkout via `?order=`, which is why
`module-commerce-confirmation` retires onto `checkout` rather than a
sixth page.

Cart badge is not a page and not a block. §0.5 left a header badge on a
*normal* marketing page out of scope. `module-commerce-badge` retires onto
Cart so an old project does not lose the block silently; it does not
become a droppable chrome widget.

### Retirement (ecommerce)

| Retired type | `pageSubpage` | Extra |
|---|---|---|
| `module-commerce` / `module-commerce-grid` | `products` | `store_id` → `data-store-ref` on the inert parent |
| `module-commerce-detail` | `product` | `data-product-slug` → `pageCommerceProductSlug` |
| `module-commerce-cart` / `module-commerce-badge` | `cart` | |
| `module-commerce-checkout` / `module-commerce-confirmation` | `checkout` | `data-dp-ref` → `pageCommerceDpRef` |
| `module-commerce-my-orders` | `orders` | **required.** V2-D8 |

## 3.2 Events

Today the Page type set `pageEvent` from `componentData.events` and did
not touch the canvas. Three palette blocks were the working surface.

Settled in the plan, built as specified: List / Detail / Register are
three pages, created on bind.

```
['list', 'detail', 'register']
```

> **Extended 2026-09-08** — a fourth bound page, **My Registrations**
> (`my-registrations`, `pageAuth = 'page'`), was added, and the stub
> `eventsComponentScript` replaced with a real four-view runtime + chrome
> (`eventsChrome.ts`) + `pageEventDpRef` sync + a `compileRuntime` events
> branch that files the event's Direct Payment under `direct_payments`.
> No `retired.ts` row — there was never a `module-events-mine` block, and
> check 15 only validates the rows that exist (ecommerce `favorites` has none
> either). `subpages` is now `['list', 'detail', 'register', 'my-registrations']`.
> See `events/complete/00_extras.md` E2 (payments) and E3 (builder).

`register()` calls `registerEventsComponents` only. No `addEventsBlocks`.
The picker is `eventsInstances` — the events API, not `builder_output`.
`data-site-ref` on the old blocks was a website id. The published site
*is* the website; the page-level module does not ask for one.

Season passes have no page. `events/complete/p12` stays out of scope. If
a fourth page is added it needs a `retired.ts` row the same day — check
15 will not know to demand one until `PAGE_LEVEL_RETIRED_KINDS` grows.

Runtime is `eventsComponentScript`, kind from `pageSubpage`
(`data-events-kind`). Detail still resolves the occurrence from the URL
the same way Product resolves a slug.

| Retired type | Becomes |
|---|---|
| `module-events-list` | List |
| `module-events-detail` | Detail |
| `module-events-register` | Register |

## 3.3 Appointments

v1 07 built five blocks because appointments/11 §11.6 listed them under
“builder blocks (deferred).” V2-D1 reads that list as **views**. Same
correction 07 made when it followed §11.6 instead of its own first guess,
applied one level up.

```
['booking', 'services', 'staff', 'next-available', 'my-bookings']
```

Five pages on bind. Picker is `appointmentsInstances`. Next available
keeps the per-service field as `pageAppointmentService` (07 already
treated this as a trait, not a sixth type) — Blade
`#page-appointment-service`. My Bookings defaults to `pageAuth = 'page'`.

Hosted `/book/{slug}` routes remain the module’s own storefront. A
builder appointments page is the tenant putting a booking surface on
*their* site at a slug they choose. This plan does not replace those
routes.

Runtime is `appointmentsComponentScript`, including the slot hold before
the visitor finishes the form (06 §6.5). The admin preview widget
(`appointment-export.ts`) is still a second implementation of the same
flow — v1 07 residue #7, still appointments/11’s deferred item.

| Retired type | Becomes |
|---|---|
| `module-appointments-booking` | Booking |
| `module-appointments-services` | Services |
| `module-appointments-staff` | Staff |
| `module-appointments-next` | Next available (`data-service-ref` → `pageAppointmentService`) |
| `module-appointments-mine` | My Bookings |

## 3.4 Cross-cutting: pickers that already exist

Do not invent a fourth instance list. §3.4’s table is now true of the
panel, not only of `instances.ts`:

| Module | Provider | Was (wrong) | Now |
|---|---|---|---|
| ecommerce | `ecommerceInstances` → `/portal/api/ecommerce/stores` | `/portal/api/v1/builder/stores` | provider |
| events | `eventsInstances` → `/portal/api/v1/events` | `componentData.events` | provider |
| appointments | `appointmentsInstances` → `/portal/api/v1/appointments` | `componentData.appointments` | provider |
| direct payments | `directPaymentsInstances` | not on the Page panel | Checkout field |

Status is already on `ModuleInstance`. A draft store on a live page is
the mistake §5.4 put `status` on the list to surface; it now shows in
the Page panel.

## 3.5 What stays a block

Unchanged, and the control tests still pin it:

- Forms, site users (login / register / menu), direct payments as a
  **standalone** payment block
- Subscribers, listings, feedback
- Discussions (`noRuntime`, B26)

A Direct Payment **page** (dropping `module-payment` on a normal page to
sell one item) is a different product from ecommerce checkout. Checkout
*references* one; it does not become one. Check 14 would fail if
ecommerce’s `register()` added that block.

## What the code said that the plan did not

**`pageManager.ts` was the first place G3 would have been visibly false.**
The left-hand list had a hardcoded `SUBPAGES` map. Ecommerce happened to
be close. Events and appointments were not — they showed names that are
not in either module’s `subpages`. Bind-on-create writing GrapesJS pages
the list cannot name is how a tenant would have concluded events was
still “views of one page.” The list now reads the registry.

**Kind attributes on the stamp are how v1 scripts keep working without a
second compiler.** §3.1 said drive `commerceComponentScript` from
`pageSubpage` instead of `data-commerce-kind` on a dropped type. The
runtime still reads the kind attribute. The stamp writes it from the
subpage. Changing the script to read `pageSubpage` off `window` or a
data attribute on `<body>` would have been a second compiler in
disguise. The existing walker already looks for `data-afs-module`.

**Empty children get a placeholder, not always the store template.**
§2.3 / §3.1 “writes each one’s markup” is true. The markup for a newly
created Cart is a stamped dashed placeholder until the tenant picks a
cart template (or until `renderStoreTemplate` runs for Products). That
is less than “the builder builds the module onto that page” in 00 §0.7,
and more than a blank canvas. Live behaviour is the published runtime,
not the placeholder.

**Appointments’ service field is a text input**, not an instance picker.
07 stored a service ref as a trait. The Page panel kept that shape.
There is no `ModuleInstanceProvider` for services; inventing one would
have been a fourth list §3.4 forbids.

**P3, P4 and P5 share one helper.** The plan sequenced them so ecommerce
went first (it already wrote the canvas) and the other two copied the
contract. In the tree they are one function and three `subpages` arrays.
That is G3 working. It is also why a bug in `ensureBoundSubpages` hits
all three at once.

## Residue

| # | Item | Where it belongs |
|---|---|---|
| 1 | Cart badge on a normal page | later v2 addendum, if wanted. Not `module-commerce-badge` |
| 2 | Season-pass view | events/12. Needs a `retired.ts` row the day it ships |
| 3 | Appointments widget vs builder | appointments/11 deferred |
| 4 | Product listing → Product page links with a slug | A11. Templates still need the listing to emit those hrefs; not verified against a published store in this session |
| 5 | Placeholder children vs full template HTML on bind | product: bind could call `renderStoreTemplate` for every view. Today Products can; the others wait for a template pick |
| 6 | Live `.test` builder not rebuilt | [02](02-page-level-contract.complete.md) residue #7 |

## Verification

Stub-tested: five ecommerce children, three events, five appointments;
retirement mapping for every §1.3 kind including `my-orders` → `orders`;
checkout without a DP is unresolved; forms still a block module.

Not verified with a tenant session: A2 (empty page → type → pick store →
five names in the list), A7 (same for events / appointments), A11
(listing links to Product with a slug on a published page). Those need a
login and a Vite rebuild so the running builder is this JS.

## Next

[04](04-phases.complete.md) — the constrained order, and A1–A13 against
what actually ran.
