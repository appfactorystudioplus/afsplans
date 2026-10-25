# 02 — The page-level module contract

v1 already defined how a module is declared ([02](../v1/02-module-registry.md)),
how publish compiles it into the `js` column
([04](../v1/04-publish-and-serve.md)), and how an instance is listed and
protected from delete ([05](../v1/05-admin-module-configuration.md)). This
document does not replace those. It says how a `'page'` module uses them
when there is no dropped block.

The admin-side counterpart is still §5.4 (`ModuleInstanceProvider`). The
publish counterpart is still `compileRuntime` + `stripComponentScripts` +
the PHP activate transaction.

## 2.1 One field on `BuilderModule`

```ts
surface?: 'block' | 'page';   // default 'block'
```

| | `'block'` (today’s default) | `'page'` |
|---|---|---|
| Palette | `register()` adds types and blocks | `register()` must **not** add palette blocks |
| Configuration | traits on the component | Properties → Page |
| Instance | `instanceRefTrait` on the element | `pageBinding.pageProperty` on the GrapesJS page |
| Views | kinds as sibling types | `pageBinding.subpages` / templates |
| Compile input | `[data-afs-module]` in the HTML | page type + page property + current subpage |
| UNRESOLVED | empty trait after inheritance | page typed as this module, instance empty |

`pageBinding` is no longer “what a block inherits.” For a `'page'` module it
**is** the module.

Consistency additions (same gate as `check-modules.mjs`):

1. `surface: 'page'` requires `pageBinding.pageType` and `pageBinding.pageProperty`.
2. `surface: 'page'` forbids a `register()` that calls `BlockManager.add` for
   this module’s types. (A helper `addPageType()` is allowed.)
3. Every `pageBinding.pageType` is still claimed by exactly one module
   (v1 check 10).
4. A `'page'` module still requires `runtime` (v1 D1). `noRuntime()` is not
   available to these three.
5. `pageBinding.subpages` is non-empty and is the page list created on
   bind. Every retired type of that module maps to one of those ids
   (V2-D8). A kind with no row fails the gate.

## 2.2 The Page panel is driven by the registry

Today `#page-type-select` and the three instance `<select>`s are hand-written
in `index.blade.php` and populated in `pageTypes.ts` from three different
sources. v2 makes that a loop over `BUILDER_MODULES.filter(m => m.surface === 'page')`.

For each page-level module the panel shows, in order:

1. **Page type** — already the first control. Options come from the registry
   plus `normal` (and the existing `registration` / `login`, which stay
   hand-owned by site_users until that module is itself reclassified).
2. **Instance** — `instanceProviderFor(module.id).list()`, status badges,
   “create one” → `createUrl()` in a new tab (v1 B29: `_blank`, hash route).
   Never `/portal/api/v1/builder/stores`.
3. **View** — the pages in the page list, from `pageBinding.subpages`.
   Ecommerce: Products, Product, Cart, Checkout, My Orders. Events:
   List, Detail, Register. Appointments: Booking, Services, Staff,
   Next available, My Bookings.
4. **Foreign refs** — Checkout’s Direct Payment, from `foreignRefTraits`.
   Same picker, different module id. Product’s preview slug is a page
   property, not a foreign module.
5. **Auth** — existing radios, unchanged.

Changing page type on a page that already has another type’s subpages is a
confirm: those subpages are unbound, not silently kept. v1 07 P7 already
says a store ref left on a page switched back to `normal` must stop being
inherited. v2 makes that a panel rule, not only a resolve rule.

## 2.3 The page list, then the canvas

Choosing type + instance **ensures the declared subpages exist in the page
list**, then writes each one’s markup. Ecommerce today creates `cart` and
`checkout` only when a template is picked. That hides Product (which has
no picker yet) and makes the store look like a single page until the
tenant hunts the dropdowns.

v2: bind a store → the list shows five children of that page, named and
selectable:

```
<store page>
  Products
  Product
  Cart
  Checkout
  My Orders
```

Bind an event → List, Detail, Register. Bind an appointment → Booking,
Services, Staff, Next available, My Bookings. Same create-on-bind rule.
Picking “views of one page” for events would break G3.

Selecting one is how the tenant “chooses the view.” The Page panel still
holds per-view template pickers (grid vs list on Products, and so on).

Rules for the write:

- Idempotent per (page, view, instance). Changing the instance re-renders.
  Re-selecting the same values does not blow away canvas edits.
- Preview may fetch fixtures or a one-shot catalogue (ecommerce already
  fetches `/portal/api/v1/builder/store-products/:ref`). Live checkout,
  booking and payment do **not** run in the canvas (v1 D5).
- The generated root carries `data-afs-module`, `data-afs-module-version`,
  and the instance ref — v1 02 §2.6’s stamp, which block `register()` never
  called. The existing `findModuleInstances` walker then sees the page.

## 2.4 Compile and the reverse index — same functions, extra input

Do not write a second compiler. `compileRuntime(html, registry, page)`
already takes a `PageBindingState`. Today it only uses that to inherit onto
blocks. v2 also uses it as a source of modules when `surface === 'page'`:

```
if (page.pageType === module.pageBinding.pageType) {
    ref = page.properties[pageBinding.pageProperty]
    if (!ref) unresolved
    else refs += { moduleId, instanceRef: ref }
    emit module.runtime          // once per published page, same dispatcher
}
```

Everything downstream stays the v1 04 path:

- `js` column gets the dispatcher
- `html` has component scripts stripped
- PHP hashes `js` for CSP
- activate is insert-inactive → deactivate-old → activate-new
- `refs` land in `laravel_builder_module_refs` and refuse deletes

Foreign refs on the page (Checkout’s Direct Payment) are recorded under the
owning module, same as v1 05’s `foreignRefTraits` rule.

A page that is `'page'`-typed and also still contains a retired block type
is a migration miss; the compiler should list it, not emit two runtimes.

## 2.5 Publish refusal

v1 D10, restated for the page:

- Page type is one of the three, instance empty → refuse, name the page.
- Ecommerce checkout subpage, Direct Payment empty → refuse (cannot take
  money). Same signal v1 05 P6 already computes (`processor_reference_num`,
  CONNECT exposure).
- Events / appointments: typed, no instance → refuse.

The publish button already gained a `catch` in v1 07 P8 so a refusal is not
an unhandled rejection. Keep that. The message names the **page**, not a
block, because there is no block.

## 2.6 Palette

`addCommerceBlocks` / `addEventsBlocks` / `addAppointmentsBlocks` go away.
The Commerce, Events and Appointments categories disappear with them.

`availability` / `paletteState()` still run for the Modules **admin** index
(tier badge). They no longer hide a block that is not in the palette.

On a page whose type is `e-commerce` / `events` / `appointments`, the
palette may still show **block** modules (forms, subscribers, …). That is a
product choice left open: a store page with a subscribe box is reasonable.
A store page with an Events List is not, and is not something this plan
adds a gate for in P0. If it is needed it is a later `availability` rule
on the page type, not a reason to keep commerce blocks.

## 2.7 Retirement

v1 `modules/retired.ts` already maps `module-commerce` → `module-commerce-grid`.
v2 extends that table so **every** kind listed in [01](01-current-state.md)
§1.3 becomes “this page’s type is X, this instance is the block’s ref,
this subpage is the view.” No kind is “later.” `module-commerce-my-orders`
maps to My Orders (`pageSubpage: 'orders'`). A type that stops
registering with no row is the silent loss V2-D8 exists to prevent, and
A9 would not catch it.

Opening a project:

1. If any retired commerce/events/appointments type is present, set
   `pageType` from the block’s module (unless the page is already typed and
   disagrees — then warn, do not silently overwrite).
2. Copy the instance ref onto `pageCommerce` / `pageEvent` /
   `pageAppointment` when those are empty.
3. Remove the retired type from the canvas (or replace its markup with the
   page-level template for the matching view).
4. Stop registering the type, so a second load cannot re-detect it.

A published page that still contains a retired class is served until the
tenant republishes. The old component `script` in historic `html` blobs is
why v1 04 strips scripts on publish; old active rows that never went
through that path keep whatever they had. v2 does not rewrite live rows
underneath the owner (v1 02 §2.6).
