# 02 — The page-level module contract (complete)

## Status

**The contract is in the tree. 415 builder tests** (was 350 after
[v1 07](../../v1/complete/07-module-handoffs.complete.md)). `check-modules.mjs`
still reports **10 modules declared**, and now also refuses a `'page'` module
that calls `BlockManager.add`.

This file is what [02](../02-page-level-contract.md) became when it met the
code. The phase order is [04](04-phases.complete.md). What each of the three
modules offers a tenant is [03](03-the-three-modules.complete.md).

Application code is in the **www** repo, uncommitted, on top of the v1 07
tree. That tree is the compile loop this contract publishes through. It was
not reverted.

| § | What 02 asked | What shipped |
|---|---|---|
| **2.1** | `surface?: 'block' \| 'page'` plus consistency additions | ✅ `BuilderModule.surface`; checks **13**, **14**, **15**. Default remains `'block'` |
| **2.2** | Page panel as a loop over `BUILDER_MODULES.filter(m => m.surface === 'page')` | ⚠️ **not a loop.** Three hand-written sections, now fed by `ModuleInstanceProvider` — see below |
| **2.3** | Bind → create declared subpages; stamp `data-afs-module` on generated roots | ✅ `ensureBoundSubpages` + `stampPageModuleHtml`. Idempotent. Does **not** blow away a non-empty canvas when the instance changes |
| **2.4** | Extend `compileRuntime`; do not write a second compiler | ✅ page binding is a compile input. Same dispatcher, same `js` column, same refs table |
| **2.5** | Typed page, empty instance → refuse, name the page. Checkout DP empty → refuse | ✅ unresolved on `pageCommerce` / `pageEvent` / `pageAppointment`; checkout also unresolved on `pageCommerceDpRef` |
| **2.6** | `addCommerceBlocks` / `addEventsBlocks` / `addAppointmentsBlocks` go away | ✅ those three no longer call `BlockManager.add`. Types still `addType` — see §2.7 |
| **2.7** | Every §1.3 kind has a `retired.ts` row; open converts; stop registering the type | ⚠️ mapping and on-open conversion shipped. **Types still register** so a draft can open until conversion runs |

## §2.1 held: one field, and the gate can demand the rest

`surface` is optional and defaults to `'block'`. The seven modules that stay
droppable did not have to change. Ecommerce, events and appointments set
`surface: 'page'` and keep a real `runtime` — v1 D1 is not relaxed. `noRuntime()`
is still the wrong answer for a store that takes no money.

Three checks were added to the same function v1 02 already runs from the
build:

| Check | Demand |
|---|---|
| **13** | `'page'` requires `pageBinding.pageType`, `pageBinding.pageProperty`, and a non-empty `subpages` |
| **14** | `'page'` `register()` must not call `BlockManager.add`. Runs `register()` against a stub editor — it cannot live as a file parse inside `checkModuleRegistry` |
| **15** | Every id in `PAGE_LEVEL_RETIRED_KINDS` has a `RETIRED_TYPES` row, and that row’s `pageTarget.subpage` is in the owning module’s `subpages` |

Check 10 (one `pageType` per module) was already there. Check 14 is wired in
`check-modules.mjs` next to `assertModuleRegistry`. A page-level module that
grows a palette block again fails the build, which is A10.

**Check 14 was deliberately not armed at P0.** Marking the three modules
`'page'` without removing their `BlockManager.add` is what V2-D10 asked P0
to do — a declaration that does not change the tenant’s editor. Arming 14
then would have failed the build for the transition the slice exists to
keep working. It fires from P3.

## The Page panel is honest about instances, and still hand-written

§2.2 said the panel becomes a loop over page-level modules. It did not.

`index.blade.php` still has three sections (`#commerce-select-section`,
`#events-select-section`, `#appointments-select-section`) plus the
registration / login types site_users owns. `pageTypes.ts` still shows and
hides those by `pageType`. What changed is **where the `<select>`s get their
options**:

| Control | Used to read | Now reads |
|---|---|---|
| Store | `/portal/api/v1/builder/stores` (unscoped, **B20**) | `ecommerceInstances.list()` |
| Event | `componentData.events` (builder_output rows) | `eventsInstances.list()` |
| Appointment | `componentData.appointments` | `appointmentsInstances.list()` |
| Checkout Direct Payment | not on the panel | `directPaymentsInstances.list()` |

That is P1, and it is the load-bearing half of §2.2. A picker that lists
every store on the server, or lists `builder_output` rows that are not
instances, cannot be the only surface. Status suffixes (`— draft`,
`— disabled`) are the same ones the trait picker already used. A selected
ref that is not in the list is kept as an extra option rather than dropped
— the page still names that store if this user cannot see it. “Create one”
goes to `createUrl()` with `_blank` and `noopener` (v1 **B29**): the builder
is a view in the same SPA, and navigating the document would discard the
unsaved page.

The helper is `fillInstanceSelect.ts`, extracted because `pageTypes.ts` is
already past the 150-line ceiling v1 03 A4 set (it is **573 lines** after
this work). jsdom 15, which this repo already pulls in, has no
`replaceChildren`; the helper clears with `while (firstChild) removeChild`.
That is not a style choice. A test that called `replaceChildren` would have
passed in a browser and failed in the suite.

Changing page type on a page that already has another type’s children
confirms, then `unbindPageLevelChildren` clears the three instance
properties and sets the children back to `normal`. The confirm is
`window.confirm`, not the shared dialog in `ui/`. v1 06 still owns
tenant-facing copy.

## Bind writes the page list, then stamps a root the compiler can see

`ensureBoundSubpages` is the create-on-bind rule. Given a parent page, a
page-level module, and an instance ref, it:

1. Sets `pageType` and the instance property on the parent.
2. For each id in `pageBinding.subpages`, reuses the child with that
   `pageSubpage` or adds one named `{parent}-{subpage}`.
3. Copies the instance (and any extras — DP ref, product slug) onto every
   child.
4. Writes markup only when the child’s `component` is empty.
5. Defaults My Orders and My Bookings to `pageAuth = 'page'`.

Re-selecting the same values does not wipe canvas edits. Changing the
instance **updates the property on every child** and does **not** re-render
a non-empty canvas. §2.3 said “Changing the instance re-renders.” The
implementation chose the half that does not blow away tenant styling, which
is the same reason the empty-check exists. A tenant who binds store B after
designing store A’s Products page keeps A’s markup with B’s ref. That is a
real gap, recorded rather than papered over.

The generated root is `stampPageModuleHtml`: `data-afs-module`,
`data-afs-module-version`, the instance ref on `refField`, and the kind
attribute the existing runtime already branches on
(`data-commerce-kind` / `data-events-kind` / `data-appointments-kind`).
v1 02 §2.6’s stamp, which block `register()` never called. `compileRuntime`’s
walker then sees the page the same way it sees a dropped block.

`pageLevel.ts` is pure. It must not import `BUILDER_MODULES` from `index.ts`
— that is a cycle (`index` re-exports `pageLevel`). Callers pass the
registry in.

## Compile is the same function with a second source

Do not write a second compiler. `compileRuntime(html, registry, page)`
already took a `PageBindingState`. It used it to inherit onto blocks (v1 07
P7). It now also walks `'page'` modules whose `pageType` matches:

- instance property set → `refs` + runtime emit, same dispatcher
- instance property empty → `unresolved`, trait named as the page property
  (`pageCommerce`, not `data-store-ref`)
- checkout subpage, `pageCommerceDpRef` empty → unresolved `direct_payments`

A page-level module on its own page type that still contains a
`[data-afs-module]` block does not list the block’s empty trait as a second
unresolved miss. The page binding owns that miss.

Everything downstream is the v1 04 path: `js` column, script strip, PHP CSP
hash, transactional activate, `laravel_builder_module_refs`. Publish already
gained a `catch` in v1 07 P8; refusals still go through it. The message
names the **page**, because there is no block.

`PageBindingState` grew `pageSubpage`. `publish.ts` now passes
`pageSubpage`, `pageCommerceDpRef`, `pageCommerceProductSlug`, and
`pageAppointmentService` into compile. Without `pageSubpage` the checkout
DP refuse cannot see that this page is checkout.

## What the code said that the plan did not

**§2.2’s loop is a sketch of a panel that already existed.** Driving three
instance lists from providers is the product change. Generating the DOM
from the registry would have rewritten Blade and `pageTypes.ts` in the same
pass as making the lists honest, for no tenant-visible difference in P1.
The foreign-ref and template controls are still ecommerce-shaped; a loop
would have had to special-case Checkout’s DP and Product’s slug anyway.
Recorded as residue, not as “P1 incomplete.”

**The stamp in §2.4 was listed under P2 and landed with P3.** P2 made the
compiler see the page binding so a store page with no `[data-afs-module]`
still emits `js` — that is V2-D10, and it is the half that must precede
deleting the palette. Wrapping generated markup is what P3’s
`ensureBoundSubpages` does when it writes a child. Doing it in P2 would
have stamped nothing, because P2 does not write pages.

**`register()` still `addType`s the retired kinds.** §2.7 item 4 said stop
registering the type so a second load cannot re-detect it. P6 converts on
GrapesJS `load` and replaces the markup with a stamp. If the type is
unknown, GrapesJS will not round-trip a draft that still contains it.
Keeping `addType` and dropping `BlockManager.add` is the split that lets
conversion run. After conversion the class is gone, so a second load has
nothing to detect. Check 14 only sees `BlockManager.add`. A later pass that
deletes `addType` needs a one-shot migrator that has already rewritten
every draft, which this session did not run against live projects.

**A block that names store A on a page bound to store B contributes two
reverse-index rows.** v1 P7: the block’s own trait wins for *that block*.
v2 P2: the page binding is itself a use. Deleting either store must 409.
The test that used to assert one ref was restated. This is the composition
G1 exists to make unrepresentable from the palette; it is still
representable in a historic draft until P6 replaces the block.

**Checkout’s Direct Payment refuse is hardcoded** in `compileRuntime` for
`mod.id === 'ecommerce' && page.pageSubpage === 'checkout'`. §2.4 said
foreign refs on the page are recorded under the owning module, same as
`foreignRefTraits`. There is no `foreignPageProperties` on `pageBinding`.
A fourth page-level module that needed a foreign ref would copy the
`if`. That is the same class of special case the registry was built to
prevent, and it is still cheaper than inventing a second foreign-ref
shape in this pass.

**Check 15 cannot treat a stub registry as “ecommerce is missing.”** The
first draft failed every owner not present in the array passed in, so
every unit test that pins checks 2–13 against a one-module fixture also
failed sixteen times for missing `pageTarget.moduleId`. The kind→row half
is a fact about `retired.ts` and always runs. The owner/subpage half only
speaks when that owner is in *this* registry. The live-registry test
(`checkModuleRegistry(BUILDER_MODULES) === []`) is what pins the three
real modules. A registry that omitted ecommerce entirely would not fail
check 15 for orphaned pageTargets — only the live test would, and it
would fail because ecommerce *is* declared.

**`findRetiredTypeInHtml` matched `module-commerce` inside
`module-commerce-my-orders`.** `\b` treats a hyphen as a boundary, and
`RETIRED_TYPES` lists the short name first. Opening a My Orders block
would have retired it onto Products — the silent loss V2-D8 exists to
prevent, arriving through the scanner rather than a missing row. The
scanner now tries longer type names first. Found by the test that asked
for `module-commerce-my-orders` and got `module-commerce`.

**`pageManager.ts`’s `SUBPAGES` map was hardcoded and wrong for two of the
three modules** (events listed `event` / `cart`; appointments listed
`confirmation` / `cart`). The page list now asks
`subpagesForPageType` / `subpageLabel`. Without that, bind-on-create
could write the right GrapesJS pages and the left-hand list would still
show the wrong names.

## Tests

The suite is **415**, up from 350. New files:
`fillInstanceSelect.test.ts`, `pageLevel.test.ts`. Extended:
`modules.test.ts` (surface, check 13, page modules must not add blocks),
`compileRuntime.test.ts` (A4 / A13, events and appointments the same
shape), `resolveInstance.test.ts` (two refs), `handoffs.test.ts`
(retirement mapping, no palette add).

| Group | Pins |
|---|---|
| P0 surface | only ecommerce / events / appointments are `'page'`; each declares the full subpage list |
| check 13 | missing binding, empty `subpages`, block modules exempt |
| check 14 / 15 | live registry; `register()` on a stub adds no blocks |
| fillInstanceSelect | status suffix, preserve unknown value, B29 create link |
| compileRuntime P2 | typed + store, no block → non-empty `js` and a ref; typed + empty → unresolved; normal page invents nothing; events / appointments same |
| stamp | `data-afs-module` + kind; does not double-stamp |
| ensureBoundSubpages | five ecommerce children; events 3; appointments 5; second bind does not duplicate |
| retireLoadedProject | grid on `normal` → Products; disagreeing pageType is not overwritten |
| findRetiredTypeInHtml | `my-orders` is not `module-commerce`; grid and the inert parent still match |

## Residue

| # | Item | Where it belongs |
|---|---|---|
| 1 | Page panel is not a registry loop | a later v2 pass, if a fourth page-level module appears |
| 2 | Types still `addType` | after drafts have been through P6; then delete `registerCommerceComponents`’s retired kinds |
| 3 | Changing instance does not re-render non-empty markup | product: warn, or a “reset this view” control. Not silent overwrite |
| 4 | Checkout DP is a special case in `compileRuntime` | `foreignPageProperties` on `pageBinding`, if a second one appears |
| 5 | `window.confirm` for type change | v1 06 |
| 6 | `pageTypes.ts` is 573 lines | v1 03 A4, already open. Bind/unbind grew it further |
| 7 | Live builder JS not rebuilt in this session | Vite `public/hot` / `npm run dev`. Page panel providers are in source; the built bundle the `.test` vhost serves may still be the previous one |
| 8 | A2 / A7 not exercised with a tenant session | needs a login on this machine. Stub-tested via `ensureBoundSubpages` |

## Verification

```
cd www
npm run test:builder     # 415 pass, 0 fail
npm run check:modules    # module registry OK — 10 module(s) declared
npm run build            # check:modules OK; tsc --noEmit; vite built in 9m 25s, exit 0
```

The two defects above (check 15 vs stubs, prefix match on retired kinds)
failed the test run, were fixed in `consistency.ts` and `retired.ts`, and
tests then exited 0. `npm run build` (which runs `check:modules` then
`tsc --noEmit && vite build`) exited 0 afterwards. Vite's usual chunk-size
and ace-builds `eval` warnings only — no builder errors.

**Added** — `core/modules/pageLevel.ts`;
`core/editor/pageTypes/{fillInstanceSelect,ensureBoundSubpages,retireLoadedProject}.ts`;
`tests/{fillInstanceSelect,pageLevel}.test.ts`.

**Changed** — `core/modules/{types,consistency,retired,index}.ts` and the
three page-level `*.module.ts`; `core/publish/compileRuntime.ts`;
`core/editor/pageTypes/pageTypes.ts`; `core/editor/createEditor.ts` (load →
`retireLoadedProject`); `core/editor/publish/publish.ts`;
`core/pageManager.ts`; `tests/check-modules.mjs` (check 14).

## Next

[03](03-the-three-modules.complete.md) for what each module’s pages actually
are. [04](04-phases.complete.md) for the order this landed in, and A1–A13.
