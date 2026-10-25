# 03 — Ecommerce, events, appointments

Each section is what the Page panel must offer, what the canvas receives, and
what the published page runs. Instance creation stays in admin (D9).

## 3.1 Ecommerce

The Page panel for this module is already sketched in
`index.blade.php` and `pageTypes.ts`. v2 finishes that sketch, adds the
missing Product page, and deletes the palette. Behaviour on the published
page is the v1 commerce runtime, compiled the v1 way.

### The page list

When the tenant sets Page Type to E-Commerce and picks a store, the
builder creates (or reuses) **five** child pages. They appear in the page
list and share `pageCommerce`:

| Page | `pageSubpage` | What it is |
|---|---|---|
| **Products** | `products` | Catalogue / grid. Today’s store template lands here |
| **Product** | `product` | **New.** One product. Not a block on the listing |
| **Cart** | `cart` | Already exists, created lazily today |
| **Checkout** | `checkout` | Already exists, created lazily today |
| **My Orders** | `orders` | **New as a page.** Today’s `module-commerce-my-orders`. Site Users–gated via the existing auth radios (default `page`) |

All five are created up front so the list is the store, not a single page
plus hidden dropdowns. Parent/child stays `subpageOf` / `pageSubpage`. A
Products page and a Cart page that named different stores is unrepresentable.

`ecommerce.module.ts` currently declares `subpages: ['cart', 'checkout']`.
P3 widens that to `['products', 'product', 'cart', 'checkout', 'orders']`.

### Page panel

| Control | Stores | Shown on | Notes |
|---|---|---|---|
| Page Type | `e-commerce` | parent | Index cannot be this |
| Store | `pageCommerce` | all five | `ecommerceInstances.list()` — scoped. Replace `/portal/api/v1/builder/stores` |
| Products template | `pageCommerceTemplate` | Products | `grid` / `list` / `minimal` / `banner` — already in the dropdown |
| Product template | `pageCommerceProductTemplate` | Product | New. Starting point is the existing `ecom-product` / detail markup in `ecommerceTemplates.ts` |
| Preview product | `pageCommerceProductSlug` | Product | Optional. Editor preview only. Published page prefers the slug in the URL |
| Cart template | `pageCommerceCartTemplate` | Cart | existing |
| Checkout template | `pageCommerceCheckoutTemplate` | Checkout | existing |
| Footer | `pageCommerceFooterTemplate` | all five | shared, already inherited |
| Direct Payment | checkout DP ref | Checkout | `foreignRefTraits`: `direct_payments`. Missing → draft + publish refuse |
| Auth | `pageAuth` | all five | existing radios. My Orders defaults to `page` — a signed-in visitor |

### Product page

This is the view `module-commerce-detail` was pretending to be.

- **One template, every product.** The tenant designs Product once. The
  listing on Products links to this page with the product’s slug
  (`/…/product?product=my-slug` or a nested path the serve loop already
  allows — v1 B8 was withdrawn). The runtime is today’s
  `initProductDetail`: variants, out-of-stock disabled, add to cart.
- **Editor preview.** `pageCommerceProductSlug` (or the first catalogue
  product) fills the canvas so the tenant is not designing against an
  empty “set slug” box. Changing it does not change what visitors see
  unless they hit a URL with no slug, in which case the preview slug is
  a reasonable fallback for a “featured product” page.
- **Not a page per SKU.** Creating one GrapesJS page for every product
  is the mismatch this model exists to avoid — the catalogue is data,
  the Product page is the view.

`module-commerce-detail` retires onto this page (slug copied to
`pageCommerceProductSlug` when empty).

### Runtime (v1 script, page-driven)

`ecommerceTemplates.ts` already renders products / cart / checkout HTML.
Buttons have no handlers (**E7**). The seven block scripts were behaviour
beside the templates. v2 keeps `commerceComponentScript` (v1 07 / ecommerce
10) and drives it from `pageSubpage` instead of `data-commerce-kind` on a
dropped type. `compileRuntime` serialises that function into `js` the
same way it does today.

| Page | Runtime branch |
|---|---|
| Products | today’s `product-grid` — catalogue, add to cart, links to Product |
| Product | today’s `product-detail` — slug from URL, then `pageCommerceProductSlug` |
| Cart | today’s `cart` — lines, discount, totals |
| Checkout | today’s `checkout` — `beginCheckout` + `Portal.directPayments` from the page’s DP ref. `?order=` confirmation stays on this page |
| My Orders | today’s `my-orders` — `shop.myOrders()`. Empty copy is “Sign in to see your history.” Auth default `page` |

Cart badge, if present, is chrome the storefront template writes,
subscribed to `cart:updated`. It is not `module-commerce-badge`.

### What is deleted

`addCommerceBlocks`, the seven `module-commerce-*` types, and the Commerce
palette category. `retired.ts` maps each kind onto the matching page:

| Retired type | Becomes |
|---|---|
| `module-commerce` / `module-commerce-grid` | Products |
| `module-commerce-detail` | Product (`data-product-slug` → `pageCommerceProductSlug`) |
| `module-commerce-cart` / `module-commerce-badge` | Cart |
| `module-commerce-checkout` / `module-commerce-confirmation` | Checkout (`data-dp-ref` stays the page DP) |
| `module-commerce-my-orders` | My Orders (`orders`). **Required.** No mapping is the silent loss V2-D8 exists to prevent |

## 3.2 Events

Today: Page type `events` sets `pageEvent` from `componentData.events` and
does not touch the canvas. Three palette blocks (list / detail / register)
are the only working surface.

**Settled:** Events uses the ecommerce contract, not “views of one page.”
List, Detail and Register are three pages, created when the page is typed
and an event is bound. That is G3. A different shape in P4 would cost the
shared contract.

### The page list

```
<event page>
  List
  Detail
  Register
```

| Page | `pageSubpage` | What it is |
|---|---|---|
| **List** | `list` | today’s `module-events-list` |
| **Detail** | `detail` | today’s `module-events-detail`. Slug from the URL, same as Product |
| **Register** | `register` | today’s `module-events-register` |

### Page panel

| Control | Stores | Shown on | Notes |
|---|---|---|---|
| Page Type | `events` | parent | |
| Event | `pageEvent` | all three | `eventsInstances.list()` — events API, not builder_output |
| Auth | `pageAuth` | all three | existing radios |

`data-site-ref` on the current blocks is a website id, not an event. The
published site *is* the website. A page-level events module does not ask
the tenant to type a site ref.

Season passes have no block and no page today (`events/complete/p12`).
Out of scope unless a fourth page is added later — and if it is, it gets
a `retired.ts` row on the same day.

### Canvas / runtime

Choosing Event writes the three pages. The runtime is today’s
`eventsComponentScript`, driven by `pageEvent` + `pageSubpage`, compiled
the v1 way. Detail resolves which event occurrence from the URL the same
way Product resolves a slug.

### What is deleted

The Events palette category and `module-events-*`. Retirement:

| Retired type | Becomes |
|---|---|
| `module-events-list` | List |
| `module-events-detail` | Detail (`data-event-ref` → `pageEvent` when empty) |
| `module-events-register` | Register |

## 3.3 Appointments

v1 07 built five blocks because appointments/11 §11.6 listed them under
“builder blocks (deferred).” The module already had the page type. Given
V2-D1, §11.6’s list is a list of **views**, not palette entries.

That is the same correction 07 itself made when it followed §11.6 instead
of its own first guess — applied one level up.

**Settled:** same contract. Five pages, created on bind. §11.6’s list is
the page list, not palette entries.

### The page list

```
<appointment page>
  Booking
  Services
  Staff
  Next available
  My Bookings
```

### Page panel

| Control | Stores | Shown on | Notes |
|---|---|---|---|
| Page Type | `appointments` | parent | |
| Appointment | `pageAppointment` | all five | `appointmentsInstances.list()` — not builder_output |
| Service | `pageAppointmentService` | Next available | the one view that is per-service (07 already treated this as a trait, not a sixth type) |
| Auth | `pageAuth` | all five | My Bookings defaults to `page` |

Hosted routes in appointments/11 (`/book/{slug}`) are the module’s own
storefront, outside the builder. This plan does not replace them. A builder
appointments **page** is the tenant putting a booking surface on *their*
site at a slug they choose.

### Canvas / runtime

`appointmentsComponentScript` already branches on kind and holds a slot
before the visitor finishes the form (06 §6.5). Keep that. Drive it from
the page binding.

The admin preview widget (`appointment-export.ts`) remains a second
implementation of the same flow — v1 07 residue #7. Unifying them is still
appointments/11’s deferred item, not a prerequisite for removing the
blocks.

### What is deleted

The Appointments palette category and `module-appointments-*`.

## 3.4 Cross-cutting: pickers that already exist

Do not invent a fourth instance list.

| Module | Provider already in `instances.ts` | Current Page panel source (wrong) |
|---|---|---|
| ecommerce | `ecommerceInstances` → `/portal/api/ecommerce/stores` | `/portal/api/v1/builder/stores` |
| events | `eventsInstances` → `/portal/api/v1/events` | `componentData.events` |
| appointments | `appointmentsInstances` → `/portal/api/v1/appointments` | `componentData.appointments` |
| direct payments | `directPaymentsInstances` | not on the Page panel at all |

Status (`active` / `draft` / `disabled`) is already computed. A draft store
on a live page is the mistake §5.4 put `status` on the list to surface —
it belongs on the Page panel more than it ever belonged on a block trait.

## 3.5 What stays a block

Unchanged by this folder:

- Forms, site users (login/register/menu), direct payments (standalone
  payment page — still a block, also a field on ecommerce checkout)
- Subscribers, listings, feedback
- Discussions (`noRuntime`, B26)

A Direct Payment **page** (someone dropping `module-payment` on a normal
page to sell a single item) is a different product from ecommerce
checkout. It stays a block module. The checkout subpage *references* one;
it does not become one.
