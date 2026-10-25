# builder v2 — Page-level modules

> **STATUS: BUILT.** P0–P6 executed 2026-09-01. What actually landed is
> the numbered `complete/` counterparts — [02](complete/02-page-level-contract.complete.md),
> [03](complete/03-the-three-modules.complete.md),
> [04](complete/04-phases.complete.md) — including where they diverged.
> Off-plan adjustments (canvas CSS, UI SDK, serve JS, hydrate, cart
> icon, DP-owned checkout, published chrome, paginated DP items,
> publish-prices, storefront throttle) live in
> [complete/00_extras.md](complete/00_extras.md).
> The numbered files in this folder remain what we intended.
>
> **[v1](../v1/README.md) is LEGACY** and is not to be referenced by new plans.
> Its `complete/` documents remain the historical record of the compile loop,
> the instance providers and the reverse index this plan publishes through —
> read them as history; cite **this** folder for how they work today.

v1’s **backend is the one v2 ships**. The registry, `compileRuntime`, the
`js` column, script-stripping, CSP hashes, transactional publish, instance
providers, reverse index, delete refusal, version attributes and the
consistency gate are sound. v2 does not replace that loop. It changes
**where a page-level module is configured** — Properties → Page, not a
palette block — and feeds the same compile step from the page binding.

The only v1 product decision this folder reverses is [07](../v1/07-module-handoffs.md)
§7.4: ecommerce, events and appointments are not droppable kinds that
inherit a page binding. They are the page. Dropping checkout next to a
product grid is the incompatible state that section left representable.

## What this folder keeps vs supersedes

| v1 | v2 |
|---|---|
| [02](../v1/02-module-registry.md) registry, `runtime` required, `noRuntime()`, consistency gate, `version` | **Kept.** `'page'` modules still declare a runtime. `register()` no longer adds palette blocks for them |
| [03](../v1/03-editor-architecture.md) editor phases | **Kept.** Page-panel work lands in `pageTypes/` |
| [04](../v1/04-publish-and-serve.md) compile → `js`, strip scripts from `html`, CSP, transactional activate | **Kept as the publish path.** Compiler gains the page binding as an input so a page with no dropped blocks still emits `js` |
| [05](../v1/05-admin-module-configuration.md) `ModuleInstanceProvider`, reverse index, delete refusal, instance-select | **Kept.** The picker moves from a block trait to the Page panel. `laravel_builder_module_refs` is still written at publish |
| [07](../v1/07-module-handoffs.md) §7.4 “neither replaces the other” | **Superseded** for these three. Page type is the only surface |
| [07](../v1/07-module-handoffs.md) §7.5.1–§7.5.3 palette kinds | **Superseded.** Those kinds become pages in the page list (G3). Their scripts become the page runtime the compiler already knows how to emit |

## Settled before execution

Three gaps the first review found, now closed in the numbered docs:

1. **Events is not undecided.** List / Detail / Register are three pages created on bind — same contract as ecommerce (G3). Appointments uses the same shape.
2. **`module-commerce-my-orders` has a retirement target.** It becomes the My Orders page (`orders`). V2-D8: every §1.3 kind has a row, or the consistency gate fails.
3. **P2 is a hard gate on P3–P5** (V2-D10). The compiler must emit from the page binding before any palette category is removed.

## Documents

| # | Intended | Built |
|---|---|---|
| 00 | [00-overview.md](00-overview.md) — the decision, the tenant flow, what is in and out of scope | [00_extras.md](complete/00_extras.md) — off-plan adjustments (living) |
| 01 | [01-current-state.md](01-current-state.md) — the two surfaces that exist today, and why they fight | — (audit; no complete) |
| 02 | [02-page-level-contract.md](02-page-level-contract.md) — how a page-level module is declared, configured, compiled and refused | [complete](complete/02-page-level-contract.complete.md) |
| 03 | [03-the-three-modules.md](03-the-three-modules.md) — ecommerce, events, appointments — each one’s pages, subpages, runtime | [complete](complete/03-the-three-modules.complete.md) |
| 04 | [04-phases.md](04-phases.md) — constrained order of work | [complete](complete/04-phases.complete.md) |
| 05 | [05-events-parity.md](05-events-parity.md) — events reaches the ecommerce surface: façade orders, four-view runtime, chrome, `my-registrations` | [complete](complete/05-events-parity.complete.md) |
| 06 | [06-design-themes.md](06-design-themes.md) — module-wide Design picker (4 themes), tenant brand accent, events editor preview | [complete](complete/06-design-themes.complete.md) |
| 07 | [07-externals-ui-layer.md](07-externals-ui-layer.md) — `external/api` goes DOM-free; `external/ui` becomes the reference runtime for events, appointments and (08) site users; builder component scripts for those three deleted outright | [complete](complete/07-externals-ui-layer.complete.md) |
| 08 | [08-site-users-pages.md](08-site-users-pages.md) — site_users becomes a page-level module (login/register/dashboard/forgot/reset), the six-theme Design picker, a demo seeder | [complete](complete/08-site-users-pages.complete.md) |
| 09 | [09-single-source-ui.md](09-single-source-ui.md) — the builder's canvas-preview markup for events/appointments/site_users stops being a second hand-written copy; it imports the pure markup builders `external/ui/*/ui.ts` already has | [complete](complete/09-single-source-ui.complete.md) |
| 10 | [10-inhouse-forms-plugin.md](10-inhouse-forms-plugin.md) — replace the third-party `grapesjs-plugin-forms` (whose `'button'` type claims every `<button>` on the page and can destructively overwrite icon/label content) with an in-house plugin that only claims its own blocks, plus a richer trait/styling set | [complete](complete/10-inhouse-forms-plugin.complete.md) |

## The other six modules

Forms, direct payments, subscribers, listings, feedback and discussions stay
**block modules**. v2 does not change how they are dropped or configured.
Direct Payments is still referenced from an ecommerce **checkout subpage**, as
a field on that page, not as a block on a mixed canvas.

**[08](08-site-users-pages.md) moved site users off this list** — it is now a
page-level module like ecommerce/events/appointments (login, register,
dashboard, forgot-password, reset-password), not a block module. The old
Login/Register/User Menu blocks are retired; `retired.ts` migrates a page
still carrying one onto the matching page view.
