# 01 — Current state: two surfaces that fight

Read from the code on 2026-09-01, including the uncommitted v1 07 working tree
in `www`.

## 1.1 The Page panel already exists

`resources/views/portal/builder/index.blade.php` `#pagePanel`, wired by
`core/editor/pageTypes/pageTypes.ts`.

A non-index page can be typed `e-commerce`, `events` or `appointments`. Each
reveals an instance `<select>`. Ecommerce then reveals template pickers and
**creates GrapesJS subpages** (`products`, `cart`, `checkout`) that inherit
`pageCommerce` from the parent. There is **no Product page** in that list —
single-product view exists only as the droppable `module-commerce-detail`
block with a `data-product-slug` trait. That is the gap §3.1 closes.

That is the product this folder is describing. It is already the start of the
tenant flow. It is incomplete, and it is not the only surface.

## 1.2 What each page type actually does today

| | Ecommerce | Events | Appointments |
|---|---|---|---|
| Page type in the dropdown | ✅ | ✅ | ✅ |
| Instance select | ✅ stores, from **`/portal/api/v1/builder/stores`** (unscoped, B20) | ⚠️ `componentData.events` (builder_output rows, not the events API) | ⚠️ `componentData.appointments` (same) |
| Templates written onto the canvas | ✅ `ecommerceTemplates.ts` — static HTML, **no `Portal.commerce`** | ❌ sets `pageEvent` only | ❌ sets `pageAppointment` only |
| Subpages | ✅ `products` / `cart` / `checkout` — **no `product`** | ❌ | ❌ |
| Auth radios | ✅ shown | ✅ shown | ✅ shown |
| Palette blocks | ✅ 7 kinds, Commerce category | ✅ 3 kinds, Events category | ✅ 5 kinds, Appointments category (uncommitted 07) |
| Published runtime | block script reads `data-store-ref` on the **element** | block script | block script |
| Page-level compile | ❌ `compileRuntime` does not read `pageCommerce` | ❌ | ❌ |

Ecommerce is furthest along on the page-level path and is also the one with
the most complete *block* path. Events and appointments have a Page dropdown
that stores a ref and then does nothing to the canvas.

## 1.3 The droppable surface

Registered from each module’s `register()`:

```
ecommerce     module-commerce-grid | -detail | -cart | -badge | -checkout | -confirmation | -my-orders
events        module-events-list | -detail | -register
appointments  module-appointments-booking | -services | -staff | -next | -mine
```

One shared script per module. Editor: dashed placeholder. Published: branch
on `data-*-kind` and call `window.Portal.*`.

v1 07 added inheritance so an empty `data-store-ref` on an `e-commerce` page
resolves to `pageCommerce` for the picker, the reverse index and publish
refusal. The published script was never updated:

```ts
const storeRef = root.getAttribute('data-store-ref') || '';
if (!storeRef) {
    root.innerHTML = 'Configure store reference in the builder panel.';
    return;
}
```

So the editor can believe a grid is bound to the page’s store, the reverse
index can refuse deleting that store, and the visitor still sees an empty
configuration message. That is the inheritance rule failing at the only step
a customer sees — and it is an argument against keeping two surfaces, not an
argument for finishing the inheritance.

## 1.4 Why the two surfaces cannot be “finished” into one product

v1 §7.4.1’s defence of blocks was the cart badge in a header. That is a real
layout want. It is not worth the states the palette also permits:

1. Product Grid + Checkout on one canvas (the mismatch this plan exists to
   prevent).
2. A checkout block naming Direct Payment X on a page bound to store Y that
   charges through payment Z.
3. Seven kinds that each have to be told the same store the page already
   named.
4. `compileRuntime` looking for `data-afs-module`, which no commerce
   `register()` stamps — so a page of seven working-looking blocks can
   compile to an empty `js` column unless something else adds the attribute.

The page type already answers “this page is the store.” The blocks answer
“put any subset of a store on any page.” Those are different products. This
folder picks the first.

## 1.5 What is good and is not being replaced

From v1 [01](../v1/01-current-state-audit.md) §1.8 and the page-type code:

- GrapesJS pages, snapshots, publish history
- The Page tab itself — type, instance, ecommerce templates and the
  products / cart / checkout subpages (Product is the one to add)
- Index locked to `normal`
- `ModuleInstanceProvider` (list / status / adminUrl / createUrl)
- `laravel_builder_module_refs` and delete refusal
- Compile into the `js` column, CSP hashes, transactional activate
- Block modules (forms, site users, …)
- `window.Portal.commerce` / `.events` / `.appointments` — the runtimes
  still call these; they just mount from the page, not from a dropped type

`ecommerceTemplates.ts` is the markup the page type already injects. v1
ecommerce/10 called it “good markup with no behaviour” (**E7**). Wiring that
markup — not replacing it with seven palette blocks — is the original
storefront plan. v2 returns to it.

## 1.6 Published pages on this deployment

v1 07 §7.8.2, still the last count: 37 published rows, 2 active. Active
content is a form page, not a store. `module-commerce` appears in **zero**
rows. The seven commerce kinds were not separately counted; assume some
drafts exist in GrapesJS project JSON even if they never published.

Retirement still needs a migration (V2-D8). This database being empty of
those types is a fact about one machine.
