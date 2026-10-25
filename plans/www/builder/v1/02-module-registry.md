# 02 — The module registry: one declaration per module

> **STUB, FOR AUDIT.** No application code has been written.
>
> **Reconciled 2026-08-31** against three module surfaces that shipped after this was drafted.
> They did not use this document — they could not, it is unbuilt — and they independently
> arrived at the same design ([01](01-current-state-audit.md) §1.9.2). §2.9 is what that
> costs and what it proves.

Closes **B1**, **B2**, **B6**, **B7**, **B14** and **B16**. This is the document the other
`www/` module plans have been writing against without knowing it — and, since 2026-08-31,
the document three of them have been writing by hand, separately.

---

## 2.1 The problem, stated once

A module's builder surface is currently assembled by hand in three unlinked files
([01](01-current-state-audit.md) §1.4). Nothing checks that the three agree, and when they
do not, the failure is silent: **four of six module types have no `script`, so they publish
as inert markup** (**B1**).

The fix is not to remember harder. It is to make the three files one declaration, and make
a mismatch a build failure rather than a dead block on a tenant's homepage.

> afs-connect had this exact problem — eleven registration sites for one source — and solved
> it with a registry plus a CI consistency test
> ([../../afs-connect/12-source-registry.md](../../afs-connect/12-source-registry.md)). The
> same answer applies, and adopting the same shape means one idea to learn rather than two.

## 2.2 One module, one file

```ts
// core/modules/ecommerce.module.ts
export const ecommerceModule: BuilderModule = {
    id:        'ecommerce',
    version:   3,                              // §2.6 — travels with every published instance
    label:     'Store',
    icon:      'fa fa-shopping-cart',
    category:  'Module Components',

    /** Is this module usable on this deployment right now? §2.5 */
    availability: {
        requires:  ['ecommerce'],              // tenant module must be installed + enabled
        tier:      'domain_ecommerce_stores',  // registry resource; absent ⇒ no tier gate
        siteUsers: 'optional',                 // 'required' | 'optional' | 'none'
    },

    /** What the tenant configures in the right-hand panel. */
    traits: [
        { name: 'store_ref', label: 'Store',   type: 'store-select', required: true },
        { name: 'layout',    label: 'Layout',  type: 'select',
          options: ['grid', 'list'], default: 'grid' },
        { name: 'per_page',  label: 'Per page', type: 'number', default: 12, min: 1, max: 60 },
    ],

    /** Markup the editor shows and the page publishes. Pure — no side effects. */
    render(cfg: EcommerceConfig): string { … },

    /**
     * Runtime behaviour. THE FIELD WHOSE ABSENCE IS B1.
     * Required by the type — a module cannot be registered without one.
     * `noRuntime()` is the explicit way to say a module is presentational.
     */
    runtime: ecommerceRuntime,

    /** Editor-only preview, so the canvas never talks to a live store. §2.7 */
    preview: ecommercePreview,
};
```

`blocks/modules.ts`, the `addType` call, and the `module_component_defaults.ts` entry are all
**generated from this**. There is no second place to edit.

### 2.2.1 `runtime` is required, and that is the whole point of the file

```ts
interface BuilderModule {
    …
    /** A module MUST declare its runtime. Presentational modules call noRuntime(). */
    runtime: ModuleRuntime;
}

/** Explicit "this module has no behaviour". Registers nothing; documents the choice. */
export function noRuntime(reason: string): ModuleRuntime;
```

**B1 becomes a type error.** A module without a runtime does not compile, and a module that
genuinely has none says so in a string a reviewer can read. That is the difference between
*decided* and *forgotten*, and today all four inert modules are the second.

## 2.3 The registry

```ts
// core/modules/index.ts — the ONLY place a module is registered
export const BUILDER_MODULES: readonly BuilderModule[] = [
    formsModule, directPaymentsModule, subscribersModule,
    ecommerceModule, eventsModule, appointmentsModule,
    listingsModule, discussionsModule, feedbackModule, siteUsersModule,
];
```

`initEditor()` iterates it. Adding a module is one file plus one line — never an edit inside
a 2,190-line function ([03](03-editor-architecture.md) is what makes that true).

**Ten modules, and most are several blocks.** The three that shipped are 7 + 3 + 3 kinds, not
one block each, so a `BuilderModule` is either declared per kind or carries a `kind` discriminator
— [07](07-module-handoffs.md) §7.3 decides that per module. `feedbackModule` is on the list
because a dynamic type for it already registers today ([01](01-current-state-audit.md) §1.9.6);
it was missing from this list's first draft, which is a small instance of exactly the problem
the registry exists to prevent.

## 2.4 The consistency test

Mirrors `SourceRegistryConsistencyTest`, and fails the build rather than the tenant's page:

```
1. Every module has a unique id, a label, an icon and a category.
2. Every module declares `runtime` — noRuntime() counts, absence does not.   ← B1
3. Every trait has a name, a type, and a default unless required.
4. Every `availability.tier` names a real resource in checkpoint_registry_v4.json.
5. Every `availability.requires` names a real tenant module.
6. render(defaults) returns non-empty markup for every module.
7. No module id collides with a GrapesJS built-in type.
8. Every module's `version` is an integer and never decreases against git HEAD.  ← B14
```

Checks 2 and 4 are the ones that would have caught what is live today: four missing runtimes,
and a tier gate that has to name a resource the registry actually meters.

## 2.5 Availability — one gate, three inputs

A block should not be draggable if the module cannot work. Today the palette shows everything
and the failure appears after publish.

```
canUse(module, ctx) =
      module installed and enabled on this tenant      ← availability.requires
  AND tier permits it                                  ← availability.tier, via alloc_v2
  AND site-users requirement satisfied                 ← availability.siteUsers
```

Three states in the palette, and the third is the one that earns its keep:

| State | Palette |
|---|---|
| Available | draggable |
| Not installed | hidden |
| **Installed, tier does not allow** | **shown, disabled, with the upgrade reason** |

That last row is [../../allocation.md](../../allocation.md) **G7** applied here: *"upgrade to
use this"* and *"you have reached your limit"* are different problems with different actions,
and the builder is where a tenant is most receptive to the first.

> **The tier read is advisory.** It shapes a palette, nothing more.
> [../../allocation.md](../../allocation.md) §7 is explicit that `alloc_v2` is not a security
> boundary, so the module's own server-side endpoints stay responsible for refusing. A
> disabled block is UX; it is not enforcement.

## 2.6 Version travels with the instance

**B14**: a component published last month carries whatever defaults existed then, and is
rendered by whatever ships today, with no version on either side.

Every published instance carries the module version that produced it:

```html
<div data-afs-module="ecommerce" data-afs-module-version="3" data-afs-config="…">
```

The runtime dispatches on it. Bumping `version` means "the config shape or the markup
contract changed", and the runtime keeps handling the versions still live in the wild. The
alternative — reinterpreting old pages under new rules — changes published sites without
anyone publishing anything.

**Migration is explicit and offered, never silent:**

```ts
migrations: {
    2: (cfg) => ({ ...cfg, layout: cfg.list_mode ? 'list' : 'grid' }),
    3: (cfg) => ({ ...cfg, per_page: cfg.per_page ?? 12 }),
}
```

Run when a tenant next opens the page in the editor, shown as *"This block was updated"*, and
applied on save. A published page is never rewritten underneath its owner.

## 2.7 Editor preview is not the runtime

`formsComponentScript` and `ScriptRunner` both open with an `isEditorMode()` check and bail —
so the canvas shows an empty box where the module will be. That works, and it means the
tenant designs against nothing.

The registry separates the two deliberately:

| | Runs in | Talks to | Purpose |
|---|---|---|---|
| `preview` | the canvas | nothing — fixtures only | The tenant sees a realistic block while designing |
| `runtime` | the published page | the module's real API | The block actually works |

`preview` renders representative fixtures — three products, a two-field form, a booking slot —
so layout and styling are designed against something with shape. It must never call a live
API: the canvas is an editing surface, not a customer's session.

## 2.8 What this gives each module plan

**B7**: fifty documents across five plans currently describe integrating with an undeclared
surface. This is the surface. Each plan's builder section becomes one module file plus its
runtime, and the questions those plans keep re-asking are answered once here:

| Question each plan asks | Answered by |
|---|---|
| How does my block reach the palette? | §2.3 — one line |
| What can a tenant configure? | `traits` |
| How does my module behave on a published page? | `runtime` — **required** |
| What if the tenant's plan does not include it? | `availability.tier` (§2.5) |
| What if it needs a signed-in site user? | `availability.siteUsers` |
| What happens to pages published before my change? | `version` + `migrations` (§2.6) |

[../site_users/12-builder-integration.md](../site_users/12-builder-integration.md) is the
largest consumer and the one to reconcile first — its §12.6 visibility trait and §12.9
ecommerce handoff are both this registry's `traits` and `availability` in a module-specific
form.

## 2.9 Reconciliation with what shipped on 2026-08-31

Three modules built their own version of this while it sat unwritten. What they did, and what
this document must change to absorb them.

### 2.9.1 What they validate

`commerce_components.ts`, `events_components.ts` and `site_users_components.ts` are the same
file three times: a kinds array, a placeholder renderer, `addType` with a shared script, traits,
and block registration. **Every structural choice in §2.2 appears in all three**, invented
independently. The registry is an extraction, not a proposal.

What none of them has: `version`, `availability`, `migrations`, `preview` as a separate
concern from the editor placeholder. Those are the fields a module author has no local reason
to invent and every reason to want later — which is the case for declaring the shape centrally
rather than trusting each module to grow it.

### 2.9.2 `instance-select`: the trait type §2.2 assumed

§2.2 sketches `type: 'store-select'`. All three shipped modules instead use free-text:

```ts
{ name: 'data-store-ref',        label: 'Store reference'    }
{ name: 'data-event-ref',        label: 'Event reference'    }
{ name: 'data-siteusers-module', label: 'Site Users module id' }
```

A tenant types a `reference_num` from memory. **One generic trait type replaces all of them**,
driven by the module id:

```ts
{ name: 'store_ref', label: 'Store', type: 'instance-select', module: 'ecommerce', required: true }
```

The provider contract behind it — `list()`, `status`, `adminUrl()` — is
[05](05-admin-module-configuration.md) §5.4, which is where the second half of "configure a
module" lives. **A block cannot be configured against an instance the tenant has no screen to
create**, and for site_users today that is literally the case (**B19**).

### 2.9.3 Modules must be able to contribute traits to other modules

The registry as drafted lets a module register **itself**. `site_users/12` §12.6 needs
something it does not allow: a **visibility trait on every block**, so any component can be
shown or hidden by signed-in state.

```ts
/** Traits this module contributes to EVERY block, when installed. §2.9.3 */
universalTraits?: Trait[];
```

Gated on the same `availability.requires` as the module itself, so an uninstalled site_users
contributes nothing and the trait does not appear. This is the one field the three shipped
modules could not have discovered, because it only exists when modules must compose.

### 2.9.4 Page-type binding is outside this registry, and must be declared

[01](01-current-state-audit.md) §1.9.3 found a third mechanism this document does not model:
`pageType` binds a whole page to a store, event or appointment (`imp.ts:1032–1250`).

A `BuilderModule` therefore declares whether it participates:

```ts
/** Can a whole page bind to one instance of this module? §7.4 */
pageBinding?: { pageType: string; templates?: string[]; subpages?: string[] };
```

Resolution order, inheritance display and the unresolved case are
[07](07-module-handoffs.md) §7.4 — they are page semantics, not registry semantics. What
belongs here is only the declaration, so the consistency test can check that a page-type
naming a module matches a module claiming that page-type.

### 2.9.5 Consistency test additions

§2.4's list gains four checks, one per finding above:

```
 9. Every trait of type `instance-select` names a module with a ModuleInstanceProvider.  ← B19
10. Every `pageBinding.pageType` is claimed by exactly one module.                       ← B15
11. No two modules register the same block type or the same palette category.            ← B18
12. Every `universalTraits` entry has a unique name across all modules.                  ← §2.9.3
```

Check 11 is the one that fails **today**: `module-commerce` and `module-commerce-grid` both
present an ecommerce block, in two categories, one inert.

## 2.10 Phases

Ordered so the registry is proved against modules that already work before anything depends on
it, and so nothing that changes a tenant's palette lands until the shape is settled.

| Phase | Contents | Why here |
|---|---|---|
| **P0** | The types — `BuilderModule`, `ModuleRuntime`, `noRuntime()` — and an empty `BUILDER_MODULES`. Nothing registered | Compiles and ships without changing a single block. The shape can be reviewed before any module depends on it |
| **P1** | Migrate the three that already work: ecommerce (7 kinds), events (3), site_users (3) | §2.9.1 — they invented this design independently, so they are the honest test of it. **The only modules where a regression is detectable**, because they are the only ones that currently do anything |
| **P2** | Migrate `module-form` | The reference implementation, and the fourth working module |
| **P3** | The consistency test, all twelve checks (§2.4, §2.9.5) | Needs real declarations to check. **Check 11 fails on arrival** — `module-commerce` and `module-commerce-grid` both claim ecommerce (**B18**) |
| **P4** | `version` + `migrations` (§2.6) | **B14**. Before any module's config shape can change |
| **P5** | `availability` and the three palette states (§2.5) | First phase a tenant can see. Advisory only — [../../allocation.md](../../allocation.md) §7 |
| **P6** | `pageBinding` declaration (§2.9.4) | Declaration only; resolution is [07](07-module-handoffs.md) §7.4 |
| **P7** | `universalTraits` (§2.9.3) | Needs P0's shape settled, and site_users' visibility trait is its first consumer |
| **P8** | `instance-select` (§2.9.2) | **Last: gated on [05](05-admin-module-configuration.md) §5.4's provider contract.** A picker with nothing to pick from is worse than the text box it replaces |

**P8 is deliberately last and may not land in this step.** §2.9.2's trait type needs
`ModuleInstanceProvider.list()`, which is 05's. [07](07-module-handoffs.md) §7.6 puts 05 §5.5
after this step for exactly that reason — if 05 has not landed when P0–P7 are done, this step
closes with P8 recorded as gated rather than waiting on another document.

## 2.11 Acceptance

| # | Check | Expect |
|---|---|---|
| A1 | A module without `runtime` | **Does not compile** (B1) |
| A2 | A module with `noRuntime('presentational')` | Compiles; registers no script; the reason is greppable |
| A3 | Drag every module onto a page, configure, publish, load it | **Every one behaves** — the check that four modules fail today |
| A4 | `availability.tier` naming a resource not in the registry | Consistency test fails (§2.4 check 4) |
| A5 | A module the tenant's tier excludes | Shown, disabled, with an upgrade reason — not hidden, not draggable |
| A6 | A module not installed on the tenant | Hidden |
| A7 | Open a page whose blocks are one version behind | Migration offered, not applied silently (§2.6) |
| A8 | A published page whose module version is still supported | Renders unchanged after a module upgrade |
| A9 | `preview` during editing | No network call to the module's API (§2.7) |
| A10 | Add a module by adding one file and one registry line | Block, type, traits and defaults all appear — no other file edited (B6) |
| A11 | Two modules registering the same block type | Consistency test fails (§2.9.5 check 11) — fails today |
| A12 | An `instance-select` trait | Renders a picker of real instances with status (§2.9.2) |
| A13 | site_users installed | Its visibility trait appears on **every** block (§2.9.3) |
| A14 | site_users not installed | That trait appears nowhere |
| A15 | A `pageType` no module claims | Consistency test fails (§2.9.5 check 10) |
