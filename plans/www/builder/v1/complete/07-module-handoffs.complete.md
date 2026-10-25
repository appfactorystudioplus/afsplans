# 07 — Module handoffs: every module, draggable and configurable (complete)

## Status

**All nine phases done. 350 builder tests** (was 296 after
[05](05-admin-module-configuration.complete.md)).

**Ten of ten modules are declared, and nine of ten have a real runtime.** §7.1's sentence — *"every
module a tenant has installed appears in the palette, can be dragged onto a page, configured
against a real instance, published, and behaves on the published page"* — was true of four when
this document was written.

| Phase | Contents | State |
|---|---|---|
| **P0** | The registry *registers*, rather than only describing | ✅ forms moved to its own file; all four working modules now register from their declaration |
| **P1** | Direct Payments runtime + declaration | ✅ **B1**'s worst case — a Stripe-styled mock that could not charge |
| **P2** | Subscribers runtime + declaration | ✅ |
| **P3** | Appointments blocks, runtime, declaration | ✅ **B17 closed** — five blocks, §11.6's list |
| **P4** | Listings and feedback runtimes; discussions resolved | ✅ **B26** found |
| **P5** | The site_users visibility trait, on every block | ✅ **A8** — it had been declared and wired to nothing |
| **P6** | Retire `module-commerce` with a migration | ✅ **B18 closed** |
| **P7** | Page-type → block instance inheritance | ✅ **B15**'s missing rule |
| **P8** | UNRESOLVED — editor warning and publish refusal | ✅ |

Three new findings, **B25**–**B27**, in [01](../01-current-state-audit.md) §1.12. One is Critical.

## §7.2 was out of date before it was executed, and §7.8 says how

The table was read on 2026-08-31, before 02–05 landed. Every "instance picker" cell was already
closed by 05 P3, and site_users' admin UI by 05 P5. Two rows were wrong about what the work
*is* — see **B26** below, and §7.5.3's block list.

**§7.8.2 also changed the risk of P6.** §7.5.1 warns that deleting `module-commerce` without a
migration blanks pages published with it. Across all 37 published rows on this deployment:
**zero** contain it, and it only ever registered when `laravel_builder_output` had a commerce
row, which it does not. The migration is still written, because that is a fact about one
database.

## P0 — the registry had been describing, not registering

Three of the four working modules registered through `BUILDER_MODULES`. Forms did not:
`forms.module.ts` was declared by 02 P2 **without a `register()`**, because its `addType` sat in
`components.ts` among five other types.

So the registry knew about forms, `check-modules.mjs` checked it, the publish compiler read it,
the instance picker used it — and nothing in the editor read it to *create* the block. That is
**B6** and **B16** still open at the point they were supposed to be closed.

`forms_components.ts` now owns it. The test that pinned the exception is inverted rather than
deleted:

```
- assert.deepEqual(withoutRegister, ['forms']);
+ assert.deepEqual(withoutRegister, []);
```

This phase is what makes every phase after it one file and one line, which is **A10**.

## P1 — the most convincing instance of B1 in the codebase

`module-payment` had no `script`. Its trait chose a "Checkout Style" and inserted one of three
**static HTML mocks** from `module_component_defaults.ts` — a Stripe-styled panel with a plan
name, a price, a card field and a Pay button, wired to nothing.

> It rendered *convincingly*, which is what makes it worse than the other five inert blocks: a
> mock that looks like Stripe is indistinguishable from Stripe until a customer tries to pay.

The runtime uses `window.Portal.directPayments` — the same SDK the commerce checkout block
already uses — to create an invoice against the tenant's configured items, mount the card form,
and confirm. The mocks are not kept as "design starting points", because keeping them preserves
the exact defect.

**Declaring the module is what forced the runtime to exist.** §2.2.1 makes `runtime` required
and `noRuntime()` refuses a throwaway reason, so B1 stopped being a finding and became a
compile error.

## The category rule contradicted its own example

Declaring a second module into `Module Components` failed check 11 — *"no two modules claim the
same palette category"*. But §2.4's **own example declaration** uses `category: 'Module
Components'`, which is the estate's catch-all: forms, direct payments, subscribers, listings and
feedback all live in it.

The rule and the example could not both be right. What survives is that sharing must be
*declared*: `sharedCategory` is the opt-in, and two modules colliding on `Commerce` by accident
still fails because neither would have set it.

## P3 — B17, and why §7.5.3's own item 5 mattered

Appointments had fourteen completion documents, nine models, a storefront controller, an admin
UI, a repaired embeddable widget — and no block.

§7.5.3 proposed four kinds and said, as item 5: *"Reconcile with
`../appointments/11-public-surface-and-widget.md` … **Item 5 first.**"* Doing that first was
right. `11-public-surface-and-widget.complete.md` lists **"Builder blocks (§11.6)"** under
*Deferred* — they were specified by the team that built the API and handed here, and §11.6's
list is not §7.5.3's:

| §7.5.3 proposed | §11.6 specifies | Built |
|---|---|---|
| Booking Widget | Booking widget | ✅ |
| Service List | Service list | ✅ |
| Staff Picker | Staff list | ✅ |
| — | **Next available** — *"the highest-converting block, and one query"* | ✅ |
| — | Book-now button | as a trait, not a sixth type |
| My Bookings | — | ✅ kept from §7.5.3 |

Following §7.5.3's list alone would have shipped without the block its own module calls the
highest-converting one.

The booking runtime **holds a slot before the visitor fills in their details** (06 §6.5).
Without that, two visitors complete the same form and the second is refused after typing
everything.

## Correction — B21 was overstated, and P3's loader had a bug of its own

> **Added 2026-09-01, after a tenant reported console errors from the running builder.**

Two things this step got wrong, both in the forms work:

**B21 was overstated.** 05 recorded that the Forms block *"offered only demo forms"* because a
search for `addSurveyFormModel` and `refreshSurveyFormOptions` found no callers. The function
actually used is **`createSurveyFormModel()`**, and `builder/main.ts`'s `registerFormModels()`
called it for every `laravel_builder_output` forms row at editor boot — so real forms did reach
the dropdown. The finding is narrowed in [01](../01-current-state-audit.md) §1.11.2: the picker
was fed by a **side-table** rather than the forms API, which is a smaller and different defect.

Replacing it with an `instance-select` over `/portal/api/v1/forms` remains right. The
justification was not.

**P3's `loadSurveyModel()` read the wrong field.** `json_data` is an envelope —
`{ schema_version, form_data, form_style, … }` — and the SurveyJS model is `form_data`. The
first version passed the whole envelope to `new Survey.Model()`, which renders a survey with no
questions: a block that looks configured and displays nothing.

`registerFormModels()` had always unwrapped it correctly. The difference was invisible because
the only form on this deployment 404s before reaching the render (**B24**) — so the bug was
masked by a *different* bug, which is why no test caught it.

Both are fixed, and `registerFormModels()` is now removed: 07 P3's picker reads the forms API
directly and `loadSurveyModel()` fetches on demand, so the prefetch warmed a cache nothing read
and fired a 404 on every editor load.

## P4 — B26: discussions is not a module

§7.5.7 groups subscribers, listings, discussions and feedback as sharing one shape and asks for
a runtime for each. Discussions does not share it:

```
routes matching /discussion/   : NONE
app/Http/Controllers/*discuss* : none
app/Models/*discuss*           : none
```

There is a block, a nav item and an admin view id. There is no backend. `noRuntime()` is the
honest declaration and its reason says what the block actually is — content the tenant composed
in the admin — rather than claiming it is presentational.

Listings and feedback both needed runtimes and got them. Listings is the more interesting one:
the block was not doing *nothing*, it was inserting a **snapshot** of the listing as it was when
the tenant last saved. Editing an item afterwards changed no published page, and nothing said
so. §7.5.7 calls it *"a listing grid that lists nothing"*; it listed a copy.

§7.5.7 also asked what should become of the row-count registration gate. Absorbed into
`availability.requires`, which — unlike a row count — can say *why* a module is absent.

## B27 — a build gate this plan added found a drift it was not looking for

Declaring the three content modules tripped `check-modules.mjs`:

```
Module tier gates name unknown resources:
  - module "listings" gates on tier resource "domain_listings", which is not in
    AllocV2Resources — the gate would silently never fire
```

`domain_listings` **is** in `checkpoint_registry_v4.json`. It was missing from
`resources/js/checkpoint/resources.ts`. Diffing the two found five missing and none extra —
a one-way drift, resources added to the registry and never transcribed.

The gate was added in 05 to catch **B22**. It caught a different class of the same problem two
steps later.

## P5 — a mechanism built, declared, and wired to nothing

02 P7 built `universalTraitsFor()`. `site_users.module.ts` declared
`data-siteusers-visible-to`. **No code called either.** A8 was false in the way that is hardest
to notice — everything looked implemented.

Two halves were missing:

- **the editor** — `installUniversalTraits()` decorates `DomComponents.addType` and back-fills
  types already registered
- **the published page** — the visibility prelude, emitted by `compileRuntime()`

The prelude changed a rule from 04: *"a page with no modules gets an empty string, not a
dispatcher with nothing to dispatch."* The visibility trait goes on **every** block, including
plain text on a page that uses no modules at all — so the question became "does this page need
any script", not "does it use a module".

> ### The ordering trap that would have made P5 silently do nothing
>
> The first draft was an `EditorPhase`. Phases mount in `initEditor()` **after**
> `createEditor()` has already registered every module — so the decorator would have been
> installed for types registered later, which is none of the ones it exists for. It is called
> from inside `createEditor()`, immediately before the registration loop.
>
> Only the `getTypes()` back-fill would have run, and the tests would still have passed against
> a stub editor. Recorded in the file, because nothing about the wrong version looks wrong.

**A hidden block is not a protected one.** The markup is in the page source either way.
Protection is `laravel_builder_routes` and the API guard, and the runtime says so where it is
implemented.

## P7 — B15's rule, and why it is not cosmetic

```
resolveInstance(block, page) =
    block.traits[refField]      ← explicit on the block, wins
    ?? page.binding[module]     ← the page-type binding
    ?? UNRESOLVED
```

One pure function, three callers that share no context: the editor, the publish compiler, and
P8's refusal. Three implementations of one rule would be **B15** one level down.

The part worth stating plainly: **before P7, a Product Grid on a store page contributed nothing
to 05 §5.5's reverse index.** It named no store of its own, so the delete-refusal would have
reported that nothing used `st_page` — and let the tenant delete the store their page depends
on. P7 is what makes P1 of step 05 correct for the commonest arrangement in the product.

A binding on the **wrong page type** is not inherited. A store reference left behind on a page
switched back to `normal` must stop being inherited, or a block silently keeps using a binding
the tenant believes they removed.

## P8 — the refusal, and the error surface it needed

A block that resolves to nothing is now reported by the compiler, and `collectPagesForPublish()`
refuses, listing every page and block across the whole site so a tenant fixes three at once
rather than discovering them one publish at a time.

**The publish button's handler had `try`/`finally` and no `catch`.** The refusal would have
become an unhandled rejection: the button re-enables, nothing publishes, the tenant is told
nothing — **B13** exactly, introduced by the feature meant to prevent a silent failure. A
`catch` now surfaces the message.

## Verification

| Check | Result |
|---|---|
| `npm run build` | ✅ exit 0 |
| `tsc --noEmit` | ✅ clean |
| Builder tests | **350 pass, 0 fail** (was 296) |
| `check-modules.mjs` | ✅ **10 modules declared** — and it caught B27 on the way |
| Registry drives registration | ✅ every module registers ≥1 type and ≥1 block, none colliding |

## Acceptance

| # | Check | Result |
|---|---|---|
| A1 | Every installed module has a palette block | ✅ **ten of ten** (was four) |
| A2 | Drag each, configure, publish, load — every one behaves | ⚠️ **nine of ten.** Discussions has no backend (**B26**); the other nine have runtimes. Not verified by a tenant end-to-end — see Residue |
| A3 | Every reference trait is an instance picker | ✅ except discussions, which has nothing to list |
| A4 | Block on a bound page, trait empty → inherits, shown as inherited | ✅ |
| A5 | Block whose instance does not resolve → editor warning, publish refused | ✅ |
| A6 | `module-commerce` gone, published pages migrated | ✅ — and zero pages needed migrating here |
| A7 | The migrated modules' published output is byte-identical | ⚠️ **not proven.** P0 moved forms' `addType` between files without changing it, and the tests assert the same type and block ids — but no byte-comparison of published output was run |
| A8 | The visibility trait on every block | ✅ |
| A9 | A module with no rows → hidden, not shown-and-broken | ✅ via `availability.requires` |
| A10 | Add the eleventh module: one file, one registry line | ✅ — P1 through P4 each did exactly that |

## Residue

| # | Item | Where it belongs |
|---|---|---|
| 1 | **B25** — subscribers, listings and feedback expose unauthenticated CRUD including deletes | With **B20**. The fix is a `storefront` split, not authenticating the group: two of them hold genuinely public endpoints |
| 2 | **B20** — the builder API group still has no `ApiAuthMiddleware` | [04](04-publish-and-serve.complete.md). Still the largest open item |
| 3 | A2/A7 not verified by a real tenant session | Needs a login, which is not something this session can do. Every runtime is tested against a stubbed API, not against the live one |
| 4 | Kind-level `availability` | Appointments' My Bookings needs a signed-in visitor; the module does not, and availability is declared per module. Belongs in [02](02-module-registry.complete.md)'s `BuilderModule` |
| 5 | **B26** — discussions has no backend | A product decision: build one, or remove the block and its nav entry |
| 6 | **B27** — `resources.ts` drifts from the registry one-way | Needs a check that reads the registry JSON, which lives in the auth repo |
| 7 | §11.5's "preview and widget become one component" | appointments/11's deferred list. The builder block and `appointment-export.ts` are still two implementations of one booking flow |
| 8 | Version stamping still not wired into block registration | [02](02-module-registry.complete.md) §2.6 — no published page carries `data-afs-module`, which is why 05's backfill reads legacy markup |

## Next

The builder folder's numbered documents are now complete except **06**
(`06-editor-ux-and-styling.md`), which is listed in [README](../README.md) as *to write* and
owns **B13** — the tenant-facing error surface. This step added three places that need one: the
publish refusal's `alert`, the unresolved-block warning on a trait, and each runtime's
"could not be loaded" message. All three currently speak through whatever surface they are
standing on.
