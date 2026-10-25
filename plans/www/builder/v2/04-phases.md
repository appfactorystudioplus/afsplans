# 04 — Phases

Constrained, not preferred. v1’s compile / registry / providers are
already in the tree — including the uncommitted 07 work — and are the
prerequisite, not a rewrite target. The contract lands before any palette
is emptied. Ecommerce goes first because it is the only page type that
already writes the canvas.

## 4.1 Order

| Phase | Contents | Why here |
|---|---|---|
| **P0** | `surface: 'page' \| 'block'` on `BuilderModule`; consistency checks from [02](02-page-level-contract.md) §2.1; mark the three modules `'page'` **without** removing their `register()` yet | A declaration that does not change the tenant’s editor. Same posture as v1 02 P0 |
| **P1** | Page panel driven by the registry + `ModuleInstanceProvider`. Replace the three ad-hoc lists (including `/builder/stores`) | The panel is the product. Until it uses the real providers it cannot be the only surface |
| **P2** | Extend `compileRuntime` (do not replace it): emit from the page binding; stamp `data-afs-module` on generated roots; refuse typed-but-unbound. Strip / CSP / activate stay as 04 shipped them | **V2-D10 — hard gate.** P3–P5 do not start until this is green |
| **P3** | Ecommerce page list is Products / Product / Cart / Checkout / My Orders (all five created on bind). Stop registering the seven blocks; retire **every** kind, including `module-commerce-my-orders` → `orders`; wire templates to `commerceComponentScript`; DP field on Checkout | First module that is actually page-level end to end |
| **P4** | Events: List / Detail / Register created on bind (not views of one page). Provider-backed picker, remove three blocks, page runtime | Same contract as P3. G3 |
| **P5** | Appointments: five pages created on bind. Provider-backed picker, remove five blocks, page runtime | Same contract. Largest block surface added in v1 07 |
| **P6** | Retirement on project open; gate fails if any §1.3 kind has no row; tests that a mixed canvas cannot be constructed from the palette | Last because it changes existing projects |

P0 is safe to land on top of the uncommitted v1 07 tree. That tree *is*
the backend this plan compiles through. P3–P5 each delete a `register()`’s
`BlockManager.add` and are the visible product change.

**Do not start P3, P4 or P5 until P2 is green.** A store page with no
blocks and no page-level compile input publishes an empty `js` column —
v1 **B2**, reintroduced through the door this plan opens. That order is
V2-D10, not a preference.

## 4.2 Acceptance

| # | Check | Expect |
|---|---|---|
| A1 | Palette | No Commerce, Events or Appointments category; those types do not `BlockManager.add` |
| A2 | New ecommerce page | Empty page → Page type E-Commerce → pick store → page list shows Products, Product, Cart, Checkout, My Orders. No block was dragged |
| A3 | Same canvas | Cannot place checkout markup and product-grid markup as two module blocks. They are different pages in the list |
| A4 | Publish, no store | Typed `e-commerce`, `pageCommerce` empty → refused, page named |
| A5 | Publish, store set | `js` column non-empty via v1 compile; runtime calls `Portal.commerce.create(page’s store)` |
| A6 | Delete that store | 409, pages listed (reverse index from the page binding, v1 05) |
| A7 | Events / appointments | Same A2–A6 against their instance and runtime |
| A8 | Forms (control) | Still a palette block; drop, pick form, publish, behaves — proves v1 block compile is untouched |
| A9 | Old project with `module-commerce-grid` / `-detail` / `-my-orders` | Opens as Products / Product / My Orders; **every** §1.3 type has a row; types gone from palette |
| A10 | `check-modules.mjs` | Fails if a `'page'` module still adds a block, lacks `pageBinding`, or a retired kind has no `subpages` target |
| A11 | Product page | Listing links to it with a slug; published page loads that product; editor preview uses `pageCommerceProductSlug` or the first catalogue item |
| A12 | Events | Bind an event → page list is List, Detail, Register — not one page with a view dropdown |
| A13 | P2 gate | A test (or the phase checklist) refuses P3 if `compileRuntime` still has no page-level emit |

A2 and A7 need a real tenant session on this machine (`local`). Stub tests
cover A1, A4, A8, A10 and the retirement mapping.

## 4.3 Suggested first execution slice

If the work is cut to one session: **P0 + P1 + the ecommerce half of P2**.
That makes the Page panel honest (scoped stores, status, create-link) and
proves the v1 compiler can see a page binding, without yet deleting the
blocks. P3 (five-page list including My Orders, Product page, remove
Commerce blocks) is the next session, on top of a compile path that
already works. It is not the first session.

## 4.4 Residue this folder does not take

| Item | Stays with |
|---|---|
| B20 / B25 unauthenticated APIs | v1 04 / security |
| B13 tenant error surface | unwritten v1 06 |
| B23 no install table | product decision |
| B26 discussions | product decision |
| Version stamp on **block** modules | still v1 02 §2.6; P2 stamps page-level roots |
| Appointments widget vs builder (two implementations) | appointments/11 |
| Season-pass view | events/12 |
| Cart badge on a normal page | a later v2 addendum, if wanted |

## 4.5 Repo

All of this is the **www** repo. Plans live here
(`packages/afsserver/plans/www/builder/v2`). Do not branch or commit unless
asked. The v1 07 working tree is already uncommitted; **keep it**. The
registry, `commerceComponentScript`, providers, reverse index and
`compileRuntime` are what Product / Cart / Checkout publish through. This
plan lands on that tree. It does not revert it.
