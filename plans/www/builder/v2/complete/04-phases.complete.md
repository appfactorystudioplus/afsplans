# 04 — Phases (complete)

## Status

**P0–P6 done. 415 builder tests** (was 350 after
[v1 07](../../v1/complete/07-module-handoffs.complete.md)).
`check-modules.mjs`: **10 modules declared**, checks 13–15 armed, check 14
wired into the build gate.

Application code is the **www** repo, uncommitted, on top of the v1 07
tree. That tree is the backend this plan compiles through. It was not
reverted.

The numbered [04](../04-phases.md) remains what we intended. This file is
the order as executed, including where P3–P5 collapsed into one pass and
where verification found two defects the phase checklists would have
called green.

| Phase | Contents | State |
|---|---|---|
| **P0** | `surface`; check 13; three modules marked `'page'` without removing `register()` | ✅ same posture as v1 02 P0 |
| **P1** | Page panel uses `ModuleInstanceProvider`; never `/builder/stores` | ✅ lists honest. Panel is not a registry loop — [02](02-page-level-contract.complete.md) |
| **P2** | Extend `compileRuntime`; refuse typed-but-unbound | ✅ **V2-D10.** Stamp of `data-afs-module` deferred to P3, on purpose |
| **P3** | Ecommerce: five pages on bind; stop adding Commerce blocks; retire every kind including `my-orders` → `orders`; DP on Checkout | ✅ |
| **P4** | Events: List / Detail / Register on bind; remove three blocks | ✅ same helper as P3 |
| **P5** | Appointments: five pages on bind; remove five blocks | ✅ same helper. Check 14 live from here |
| **P6** | Retirement on project open; gate fails if any §1.3 kind has no row | ✅ `retireLoadedProject` on GrapesJS `load`; check 15 |

P3 did not start until P2’s compile tests were green. That order is
V2-D10, not a preference: removing the Commerce palette first republishes
every store page with an empty `js` column — v1 **B2** through the door
this plan opens.

## P0 — a declaration that does not change the editor

`BuilderModule.surface?: 'block' | 'page'`. Default `'block'`, so the
seven modules that stay droppable were untouched. Ecommerce, events and
appointments set `'page'` and widened `pageBinding.subpages` to the full
page lists P3–P5 would create.

Check 13 demands `pageType`, `pageProperty`, and non-empty `subpages` on
`'page'`. Check 14 (`BlockManager.add` prohibition) was **not** armed.
Enabling it here would have failed the build while those three still
registered blocks — the transition V2-D10 exists to keep working.

This is the same posture as v1 02 P0: ship the type without moving a
single block.

## P1 — the panel is the product, so it has to list real instances

Until the Page panel uses `ModuleInstanceProvider.list()`, it cannot be
the only surface. The three dropdowns read three different wrong things
(unscoped builder stores, `componentData.events`,
`componentData.appointments`).

`fillInstanceSelect` is what they share: status suffix, preserve a value
not in the list, `_blank` create link (B29). Checkout’s Direct Payment
select uses the same helper against `directPaymentsInstances`. Blade
gained the Product template, preview slug, DP select, and appointments
service field.

§2.2’s “loop over page-level modules” did not land. Three hand-written
sections, now honest. Recorded in [02](02-page-level-contract.complete.md);
not a reason to call P1 unfinished. The tenant-visible bug was B20 on
the store dropdown, not the absence of a `filter()`.

## P2 — V2-D10, and why the stamp waited

`compileRuntime` already took `PageBindingState` for inheritance. P2
adds: if `surface === 'page'` and `page.pageType` matches, the page
binding is a compile input even when the markup has no
`[data-afs-module]`.

A4 / A13 are tests, not a checklist:

- typed `e-commerce`, store set, empty HTML → non-empty `js`, one
  ecommerce ref, `commerceComponentScript` in the dispatcher
- typed `e-commerce`, store empty → unresolved `pageCommerce`, empty `js`
- `normal` page with no blocks invents nothing
- events / appointments: same unresolved / indexed shape

Strip, CSP, activate were not retouched. There is no second compiler.

§4.1 listed “stamp `data-afs-module` on generated roots” under P2. The
compiler cannot stamp markup it does not write. P3’s
`ensureBoundSubpages` is the writer; `stampPageModuleHtml` lives there.
P2’s job was to make a store page *without* that stamp still emit `js`,
so deleting the blocks in P3 could not reintroduce B2. That is the gate.
The stamp is how a later walker finds the page; it is not the gate.

P3 started after those tests were green.

## P3, P4, P5 — one helper, three `subpages` arrays

The plan sequenced them so ecommerce went first (it already wrote the
canvas) and the others copied. In the tree they are `ensureBoundSubpages`
plus three declarations. That is G3: a fourth page-level module is a
`subpages` array and labels, not a third create-on-bind.

Each `register()` dropped its `add*Blocks` call and kept `addType`.
Check 14 started failing the build if one of those adds came back.
`pageManager.ts` stopped using a hardcoded map that was wrong for events
and appointments.

Ecommerce-specific work in this burst, because it is the only one with
templates and a foreign ref:

- Product and My Orders templates in `ecommerceTemplates.ts`
- DP select on Checkout; missing DP → unresolved `direct_payments`
- `pageCommerceProductSlug` on Product

Appointments-specific: `pageAppointmentService` on Next available.

Palette: A1 is check 14 plus a test that `register()` of every module
adds no id in `PAGE_LEVEL_RETIRED_KINDS`. Forms still adds `module-form`.
That is A8’s control.

## P6 — last because it changes existing projects

`retired.ts` is no longer “`module-commerce` → `module-commerce-grid`.”
Every kind in [01](../01-current-state.md) §1.3 has a `pageTarget`.
`module-commerce-my-orders` → `orders`. Check 15 fails the build if a
kind has no row or its `subpage` is not in that module’s `subpages`.

`retireLoadedProject` runs on GrapesJS `load` in `createEditor.ts`:

1. Scan each page’s HTML for a retired class / `data-gjs-type`.
2. If the page is already typed and disagrees, note and skip — do not
   silently overwrite.
3. Otherwise set `pageType` / `pageSubpage`, copy the instance onto the
   page property when empty, copy extras (slug, DP, service).
4. `ensureBoundSubpages` so the rest of the list exists.
5. Replace the canvas with a stamp for that view.

It does not rewrite live published rows. Old active `html` that never
went through v1 04’s script strip keeps whatever it had. v1 02 §2.6:
do not rewrite underneath the owner.

§2.7 item 4 (stop registering the type) is the half that did not land.
Types stay so this conversion can see them. After conversion the class
is gone from the draft.

Two defects this phase’s tests found, both V2-D8 class, both fixed in
the same pass as these reports:

1. **Check 15 vs stub registries.** Failing “owner not in this array”
   made every consistency unit test report sixteen extra problems. The
   owner/subpage half now only speaks when that owner is present. The
   live-registry test is what pins the three real modules.
2. **Prefix match.** `findRetiredTypeInHtml` used `\bmodule-commerce\b`,
   which matches inside `module-commerce-my-orders`. My Orders would
   have become Products. Longer type names are tried first.

## Acceptance (4.2)

| # | Check | Result |
|---|---|---|
| **A1** | No Commerce / Events / Appointments category; those types do not `BlockManager.add` | ✅ check 14; `register()` stub test. Types still `addType` |
| **A2** | Empty page → E-Commerce → pick store → five children, no block dragged | ✅ stub (`ensureBoundSubpages`). ⚠️ live builder not exercised this session — needs tenant login + Vite rebuild |
| **A3** | Cannot place checkout and product-grid as two module blocks | ✅ those kinds are not in the palette. A historic draft can still contain both until P6 runs |
| **A4** | Typed `e-commerce`, `pageCommerce` empty → refused, page named | ✅ `compileRuntime` unresolved `pageCommerce`. Publish `catch` is v1 07 P8 |
| **A5** | Store set → `js` non-empty via v1 compile; runtime is the commerce script | ✅ A13 test matches `commerceComponentScript`. Stamp gives the walker a mount. ⚠️ not published through PHP in this session |
| **A6** | Delete that store → 409, pages listed | ⚠️ reverse index **includes** the page binding (tested). Live 409 against MySQL not re-hit here; that path is v1 05 and was already green |
| **A7** | Events / appointments same as A2–A6 | ✅ same ensure + compile path. Same live-session caveat as A2 |
| **A8** | Forms still a palette block | ✅ `'block'`; still `BlockManager.add`s `module-form` |
| **A9** | Old project with grid / detail / my-orders opens as Products / Product / My Orders; every §1.3 type has a row; types gone from palette | ✅ mapping + `retireLoadedProject` tests. Palette adds gone. ⚠️ not opened against a saved GrapesJS project on disk |
| **A10** | `check-modules.mjs` fails if a `'page'` module adds a block, lacks `pageBinding`, or a retired kind has no `subpages` target | ✅ checks 13–15; check 14 in the gate |
| **A11** | Product page: listing links with a slug; published page loads that product; editor preview uses the slug field | ⚠️ page exists; slug is a page field; runtime is still `initProductDetail`. Listing hrefs and a published load not verified end-to-end |
| **A12** | Bind an event → List, Detail, Register — not one page with a view dropdown | ✅ `subpages: ['list','detail','register']`; page list reads the registry |
| **A13** | P2 gate: `compileRuntime` emits from the page binding | ✅ tests exist; P3 started after they were green |

A2 and A7 need a real tenant session (`local`). Stub tests cover A1, A4,
A8, A10, A12, A13 and the retirement mapping. That sentence in §4.2 is
still the honest split.

## What the code said that the plan did not

**§4.3’s “first execution slice” was P0+P1+ecommerce-half-of-P2.** The
session that started this folder did that slice, then — when told to
finish every step and write completion as it went — continued through
P6 in one burst. P3–P5 are not three commits. They are one helper.
The plan’s order (compile green before palette empty) was kept. The
plan’s “one module per session” was not.

**§4.1 put the stamp in P2.** See P2 above. The gate is emit-from-binding,
not stamp-on-root.

**`pageTypes.ts` grew.** v1 03 A4 (largest function under 150 lines) was
already missed; this folder added bind-on-create, unbind, DP, product
slug, and three provider fills to the same file. Extracting
`fillInstanceSelect` and `ensureBoundSubpages` is what kept the new
rules testable. The file is still 573 lines.

**Two reverse-index rows when a leftover block names a different store
than the page.** [02](02-page-level-contract.complete.md). P7 still wins
for the block; the page binding is a real use.

## Residue this folder does not take (4.4, still true)

| Item | Stays with |
|---|---|
| B20 / B25 unauthenticated APIs | v1 04 / security. P1 stopped *calling* `/builder/stores`; it did not authenticate that group |
| B13 tenant error surface | unwritten v1 06. Type-change uses `window.confirm`; publish refusal still `alert` |
| B23 no install table | product decision |
| B26 discussions | product decision |
| Version stamp on **block** modules | still v1 02 §2.6 |
| Appointments widget vs builder | appointments/11 |
| Season-pass view | events/12 |
| Cart badge on a normal page | a later v2 addendum, if wanted |

Plus from execution:

| Item | Notes |
|---|---|
| Types still `addType` | §2.7 item 4. Conversion needs the type to exist |
| Page panel not a registry loop | [02](02-page-level-contract.complete.md) |
| Changing instance does not re-render non-empty canvas | same |
| Live Vite bundle | Page panel JS is source; `.test` may still serve the previous build |
| A2 / A7 / A11 live | tenant session |

## Verification

```
cd www
npm run test:builder     # 415 pass, 0 fail
npm run check:modules    # module registry OK — 10 module(s) declared
npm run build            # check:modules OK; tsc --noEmit; vite built in 9m 25s, exit 0
```

First test run after P3–P6: **401 pass, 14 fail.** Thirteen were check 15
firing on stub registries; one was `module-commerce` matching inside
`module-commerce-my-orders`. Both fixed; tests then 415 / 0. `npm run build`
exited 0 afterwards (registry gate, `tsc --noEmit`, vite). Vite's usual
chunk-size and ace-builds `eval` warnings only — no builder errors.

**Added (www)** —
`resources/js/portal/builder/core/modules/pageLevel.ts`,
`core/editor/pageTypes/fillInstanceSelect.ts`,
`core/editor/pageTypes/ensureBoundSubpages.ts`,
`core/editor/pageTypes/retireLoadedProject.ts`,
`tests/fillInstanceSelect.test.ts`,
`tests/pageLevel.test.ts`.

**Changed (www)** — the three page-level `*.module.ts`,
`types.ts`, `consistency.ts`, `retired.ts`, `index.ts`,
`compileRuntime.ts`, `pageTypes.ts`, `createEditor.ts`, `publish.ts`,
`pageManager.ts`, `ecommerceTemplates.ts`,
`resources/views/portal/builder/index.blade.php`,
`tests/{modules,compileRuntime,resolveInstance,handoffs}.test.ts`,
`tests/check-modules.mjs`.

Plans live in `packages/afsserver/plans/www/builder/v2`. No branch, no
commit, unless asked.

## Next

v1 **06** (`06-editor-ux-and-styling.md`) still owns B13 — the type-change
confirm, the publish refusal, and each runtime’s “could not be loaded”
copy. A tenant session on `www.test` after `npm run dev` is what A2 / A7
/ A11 still need. A later pass can delete `addType` for retired kinds
once drafts have been through P6.
