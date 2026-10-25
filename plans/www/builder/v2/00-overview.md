# 00 — Overview

> **P0–P6 executed 2026-09-01.** This file is what we intended. What
> shipped is [02](complete/02-page-level-contract.complete.md),
> [03](complete/03-the-three-modules.complete.md),
> [04](complete/04-phases.complete.md).

## 0.1 The decision

**Ecommerce, events and appointments are page-level modules.**

A tenant creates a new page (empty). In the right-hand Properties sidebar they
open the **Page** tab and set the page type. That reveals the configuration for
that module — which store, which event, which appointment, which template,
which subpages. The builder then builds the module onto that page.

They do not open the palette and drop a Product Grid, then a Checkout, then a
Cart Badge onto the same canvas. That is how a store gets mismatched blocks and
a page that cannot be a coherent storefront.

v1 [07](../v1/07-module-handoffs.md) §7.4 recorded both mechanisms as
legitimate and kept both. That was a correct *description of what had been
built*. It is the wrong *product*. This folder reverses that decision for these
three modules only.

## 0.2 The tenant flow

```
  new page (empty, pageType = normal)
       │
       ▼
  Properties → Page
       │
       ├─ Page Type: E-Commerce | Events | Appointments
       │
       ├─ bind the INSTANCE          (store / event / appointment)
       │     from ModuleInstanceProvider.list() — the same contract as v1 05
       │
       ├─ choose the VIEW            (a page in the page list, not a block)
       │     ecommerce:    Products · Product · Cart · Checkout · My Orders
       │     events:       List · Detail · Register
       │     appointments: Booking · Services · Staff · Next available · My Bookings
       │
       └─ the builder writes that page
             canvas gets the module’s markup for that view
             v1 publish compiles the module runtime into the js column
             visitor hits the slug; window.Portal.* runs
```

The index page stays locked to `normal`. That is already in
`pageTypes.ts` and is not changed.

## 0.3 Why this, and not blocks plus inheritance

v1’s inheritance rule (`block trait ?? page binding ?? UNRESOLVED`) was written
to let a Product Grid on a store page pick up `pageCommerce` when its own
trait was empty. That is the *symptom* of having two surfaces.

The failure that actually matters is compositional:

- Checkout and Product Grid on one canvas
- A cart badge that names store A on a page bound to store B
- A published runtime that only reads `data-store-ref` on the block, so
  inheritance in the editor does not reach the visitor
  (this is the state of `commerceComponentScript` today)

A page-level module makes those states unrepresentable. One store, five
pages in the list — Products, Product, Cart, Checkout, My Orders — each
a view, none a block dropped onto another. Cart is not a sibling of the
grid on one canvas. Events and appointments use the same contract:
declared pages, created when the instance is bound.

## 0.4 Goals

| # | Goal |
|---|---|
| **G1** | A tenant cannot put two incompatible views of the same module on one canvas |
| **G2** | Configuring the module happens in Properties → Page, before the canvas is filled |
| **G3** | The three modules share one page-level contract (declaration, picker, compile, refuse) |
| **G4** | Publish is v1’s compile step. The compiler already writes `js`, strips component scripts, hashes CSP and records refs. v2 adds the page binding as an input so a page-level module with no dropped blocks still produces that payload |
| **G5** | A page typed `e-commerce` / `events` / `appointments` with no instance cannot be published |
| **G6** | Existing dropped blocks on published pages are migrated or retired, not left as a second live surface |
| **G7** | Block modules are untouched |

## 0.5 Non-goals

- **Not a GrapesJS rewrite.** The editor, pages, publish history and block
  modules stay.
- **Not rebuilding module admin.** D9 still holds. Stores, events and
  appointments are created in their existing admin screens. The Page panel
  *selects* an instance.
- **Not a cart-badge-on-every-header.** A header badge on a *normal* marketing
  page is a later product decision. In v2 the cart lives on the store’s cart
  subpage. If a badge is needed it is part of the storefront chrome the page
  type writes, not a palette block.
- **Not Registration / Login page types.** Those already exist in the Page
  dropdown (`registration`, `login`) and belong to site_users. Out of scope.
- **Not 06 (editor UX).** Tenant-facing error language for a missing store
  still belongs there; this folder only requires that the refusal *exists*.
- **Not B20 / B25.** The Page panel must not grow a new caller of the
  unauthenticated `/portal/api/v1/builder/stores` list. It should use the
  scoped `ModuleInstanceProvider` from v1 05. Fixing the builder API group
  itself is still 04 residue.

## 0.6 Decisions

| # | Decision | Why |
|---|---|---|
| **V2-D1** | Ecommerce, events, appointments have **no palette blocks** | G1. The Page panel is the surface |
| **V2-D2** | `BuilderModule.surface` is `'page'` or `'block'` (default `'block'`) | One registry, two shapes. The consistency check can demand a `pageBinding` on `'page'` and a `register()` that adds blocks on `'block'` |
| **V2-D3** | One page ↔ one instance of that module | The page *is* the store / event / appointment. A second instance is a second page |
| **V2-D4** | Views of a page-level module are **pages in the page list**, not sibling blocks | **G3: the same contract for all three.** Ecommerce: Products / Product / Cart / Checkout / My Orders. Events: List / Detail / Register. Appointments: Booking / Services / Staff / Next available / My Bookings. Created when the page is typed and the instance is bound, not lazily, not as “views of one page” |
| **V2-D5** | Publish is v1 04. The compiler is extended, not replaced | `compileRuntime`, `stripComponentScripts`, server-side `js_csp_hash`, transactional activate, `laravel_builder_module_refs` stay. The new input is `pageType` + instance property + current subpage, so a page with no `[data-afs-module]` blocks still emits `js` |
| **V2-D6** | A typed page with no instance is UNRESOLVED — editor error, publish refused | v1 D10, asked of the page instead of a block. Same `catch` on the publish button (07 P8) |
| **V2-D7** | Direct Payment for checkout is a **page-level field on the Checkout page** | Still a `foreignRefTrait` so the reverse index and delete-refusal from v1 05 keep working |
| **V2-D8** | Retired types absorb **every** dropped kind in [01](01-current-state.md) §1.3 | Same `retired.ts` mechanism as v1 07 P6. A kind with no row is the failure this decision exists to prevent — an old project loses the block silently. `module-commerce-my-orders` maps to My Orders |
| **V2-D9** | Product is its own page, not a slug trait on the listing | The listing links to it. The published Product page resolves which item from the URL (and an optional preview slug in the editor). One template serves every product |
| **V2-D10** | P2 is a hard gate on P3–P5 | Extending `compileRuntime` to emit from the page binding lands **before** any palette category is removed. The other order republishes every store page with an empty `js` column — v1 B2 through the door this plan opens |

## 0.7 What “the builder builds the module” means

It does not mean the tenant is locked out of the canvas. After the Page panel
has chosen type + instance + view, the canvas holds the module’s markup and
the tenant can still style, rearrange copy, drop a **block module** (a form, a
subscribe box) if that is allowed on that page type.

It does mean the *module’s own views* are not in the palette. Products,
Product, Cart, Checkout and My Orders are pages because the Page panel
said so, not because someone dragged `module-commerce-grid` or
`module-commerce-detail`.

## 0.8 What v1 already deployed, and is not redesigned

This is the loop v2 publishes through. Read the `complete/` records, not
this paragraph, for how each piece behaves.

| Piece | v1 | v2 use |
|---|---|---|
| `BuilderModule.runtime` required | 02 | Page-level modules keep a runtime. The function is the same commerce / events / appointments script, driven by the page instead of `data-*-kind` on a dropped type |
| `compileRuntime` → `js` column | 04 | Same dispatcher, same `Function.prototype.toString()`, same per-instance try/catch. Page binding is an extra source of “this page uses module X @ version N” |
| Strip scripts from `html` | 04 | Unchanged. Generated markup must not rely on GrapesJS serialising a component `script` |
| CSP hash computed in PHP | 04 | Unchanged |
| Activate in one transaction | 04 | Unchanged |
| `ModuleInstanceProvider` | 05 | The Page panel’s instance list. Stop using `/portal/api/v1/builder/stores` |
| `laravel_builder_module_refs` + 409 delete | 05 | Written from the page binding (and checkout’s DP), not from a block trait |
| `versionAttributes` / `data-afs-module` | 02 §2.6, never stamped on blocks | Stamp them on the generated page root so the existing walker finds the page |
| Editor seams (`pageTypes/`, `publish/`) | 03 | Where the panel and compile call sites already live |

v1 D1, D3, D4, D5, D6, D9 stay. D2’s “generated into blocks” is what `'page'`
opts out of. D10 stays as a refuse, asked of the page.
