# 07 — Module handoffs: every module, draggable and configurable

> **Written as a stub for audit; now the step being executed.**
> [02](02-module-registry.md)–[05](05-admin-module-configuration.md) have landed, which closed
> several rows of §7.2 and changed two others. **§7.8 is the re-audit and supersedes §7.2 where
> they disagree**; §7.9 is the phase table.

Closes **B7**, **B15**, **B16**, **B17**, **B18**. Reconciles each module plan's builder
section against [02](02-module-registry.md), and states — per module — what stands between
today and *"drag it on, configure it, publish it, it works"*.

---

## 7.1 The goal, stated as one sentence

**Every module a tenant has installed appears in the palette, can be dragged onto a page,
configured against a real instance, published, and behaves on the published page.**

That is ten modules. Today it is true of **four** — forms, ecommerce, events and site_users
— and each of those four arrived by a different route.

## 7.2 Where every module stands

Read from the code on 2026-08-31, not from the module plans.

| Module | Palette block | Runtime | Instance picker | Page-type | Gap to close |
|---|---|---|---|---|---|
| **forms** | ✅ `module-form` | ✅ `formsComponentScript` | text trait | — | picker only |
| **ecommerce** | ✅ 7 Commerce blocks | ✅ `commerceComponentScript` | text trait | ✅ `e-commerce` | picker; **retire inert `module-commerce` (B18)**; block↔page-type rule (§7.4) |
| **events** | ✅ 3 Events blocks | ✅ `eventsComponentScript` | text trait | ✅ `events` | picker; block↔page-type rule |
| **site_users** | ✅ 3 blocks | ✅ `siteUsersComponentScript` | text trait | auth section | picker; **admin UI (B19)**; visibility trait; account/reset blocks |
| **direct payments** | ⚠️ `module-payment` | ❌ **inert** | text trait on *another* module's block | — | **runtime**; picker; [05](05-admin-module-configuration.md) §5.7 |
| **subscribers** | ⚠️ `module-subscriber` | ❌ **inert** | — | — | **runtime**; block declaration |
| **appointments** | ❌ **none** | ❌ | — | ✅ `appointments` | **everything except the page-type path (B17)** |
| **listings** | ⚠️ dynamic type | ❌ **inert** | — | — | **runtime**; block declaration |
| **discussions** | ⚠️ dynamic type | ❌ **inert** | — | — | **runtime**; block declaration |
| **feedback** | ⚠️ dynamic type | ❌ **inert** | — | — | **runtime**; block declaration |

⚠️ = block reaches the palette and produces a page that does nothing.

**Six of ten are inert or absent.** Five of those six are one missing field
([02](02-module-registry.md) §2.2.1 makes it a compile error); the sixth, appointments, has no
declaration at all.

## 7.3 The three that work are the template

Ecommerce, events and site_users each independently wrote the same file
([01](01-current-state-audit.md) §1.9.2). Migrating them to the registry is mechanical, and it
is the migration that proves the registry before the other seven depend on it:

| Their code | Registry field |
|---|---|
| `const KINDS = [{ type, kind, label, icon }]` | one `BuilderModule` per kind, or one with a `kind` trait |
| `function placeholder(label, icon, ref)` | `preview` (§2.7) |
| `domc.addType(type, { … script })` | `runtime` (§2.2.1) |
| `traits: [{ name: 'data-…-ref' }]` | `traits` (§2.2) |
| `bm.add(type, { category })` | `category` (§2.2) |
| *(nothing)* | `version`, `availability`, `migrations` |

The last row is the whole argument. Their blocks work; they are also unversioned, ungated by
tier, and unguarded against a tenant referencing an instance that does not exist. The registry
is not replacing what they built — it is supplying the three fields they had no reason to
invent on their own.

**Migrate these three first, before writing any new module file.** Together with forms they
are the only modules where a regression is detectable, because they are the only ones that
currently do anything.

## 7.4 Blocks and page-types: the rule

**B15**: three mechanisms put a module on a page and nothing states their relationship. This
section is that statement.

### 7.4.1 They answer different questions

| | Page-type binding | Block |
|---|---|---|
| Question | *"This page **is** the store"* | *"Put a cart badge in the header"* |
| Granularity | whole page | one component |
| Set in | page settings (`imp.ts:1032–1250`) | traits |
| Modules | ecommerce, events, appointments | all |

Both are legitimate. A storefront page with routing, templates and subpages (`cart`,
`checkout`) is badly expressed as a pile of blocks; a cart badge in a header is badly expressed
as a page type. **Neither replaces the other, and the plan keeps both.**

### 7.4.2 The page binding is the default; a block may override it

```
resolveInstance(block, page) =
    block.traits[refField]                    ← explicit on the block, wins
    ?? page.binding[module]                   ← the page-type binding
    ?? UNRESOLVED                             ← §7.4.3
```

So on an `e-commerce` page bound to store `st_abc`, a Product Grid with no `data-store-ref`
shows `st_abc` — which is what a tenant means by dropping a grid onto their store page. Setting
the trait explicitly to `st_xyz` shows `st_xyz`, which is how a "featured products from our
outlet store" block is built.

**The inheritance must be visible in the editor**, or it is indistinguishable from a bug: the
trait shows the inherited value greyed with *"from page: Main Store"*, and clearing it returns
to inheriting rather than to empty.

### 7.4.3 UNRESOLVED is an editor error, not a published one

A block that resolves to no instance must fail in the builder, where the tenant can fix it —
never silently on the published page, which is what happens today.

- **Editor**: the block renders an error state naming what is missing (**B13**'s surface).
- **Publish**: refused, listing the blocks. [04](04-publish-and-serve.md) §4.2's compile step
  is where this check belongs — it already walks every component.
- **Published page**: unreachable by construction.

> Refusing a publish is a strong action and it is the right one here. The alternative is a live
> page with a block that renders and does nothing, which is **B1**'s failure mode arriving
> through configuration instead of code — and this plan should not close one door and leave
> the other open.

## 7.5 Per-module handoffs

What each module plan must supply, and what it gets from this one. Each becomes one file plus
one registry line ([02](02-module-registry.md) §2.3).

### 7.5.1 ecommerce

Owns: `commerce_components.ts` (7 kinds), `commerceComponentScript`, page-type `e-commerce`
with template and subpage pickers.

| # | Work |
|---|---|
| 1 | Migrate 7 kinds to registry declarations (§7.3) |
| 2 | **Retire `module-commerce`** (`components.ts:603`) — **B18**. Deprecate in the palette, migrate existing instances to `module-commerce-grid`, then delete |
| 3 | `store_ref` → `instance-select` ([05](05-admin-module-configuration.md) §5.6) |
| 4 | Declare the page-type binding per §7.4.2 |
| 5 | `availability.tier` → `domain_ecommerce_stores` |

Item 2 is the only destructive one and needs [02](02-module-registry.md) §2.6 migrations: pages
published with `module-commerce` exist, and deleting the type without a migration blanks them.

### 7.5.2 events

Owns: `events_components.ts` (List, Detail, Registration), page-type `events`.

Same five items as ecommerce, minus the retirement. `data-event-ref` and the `data-site-ref`
"Website reference" trait both become instance-selects. Season passes
(`../events/complete/p12-season-passes.complete.md`) have no block — decide whether pass
purchase is a fourth kind or a mode of Registration.

### 7.5.3 appointments — the largest gap

**B17.** Fourteen completion documents, nine models, a storefront controller, an admin UI, and
no block. Only the page-type path exists.

| # | Work |
|---|---|
| 1 | `appointments_components.ts` — proposed kinds: **Booking Widget**, **Service List**, **Staff Picker**, **My Bookings** |
| 2 | `appointmentsComponentScript` against `AppointmentStorefrontController` |
| 3 | `instance-select` over appointments |
| 4 | `availability.siteUsers: 'required'` for My Bookings — a booking history needs a signed-in site user |
| 5 | Reconcile with `../appointments/11-public-surface-and-widget.md`, which specifies a widget this plan has no record of being built |

Item 5 first. That document may already specify most of item 1, and writing a second design for
the same widget is exactly the duplication **B7** describes.

### 7.5.4 direct payments

Fully specified in [05](05-admin-module-configuration.md) §5.7. The one module where an inert
block costs revenue rather than layout, and the one whose admin configuration is richest and
least connected.

### 7.5.5 forms

The module that already works end to end, and the reference implementation for everything here.
Two items only: migrate to a registry declaration, and turn its form reference into an
instance-select. `../forms/complete/` has 11 documents; none needs revisiting.

### 7.5.6 site_users

Owns 3 blocks, plus route protection (`laravel_builder_routes`) and the API guard, which are
**not** module blocks and stay where they are.

| # | Work | Source |
|---|---|---|
| 1 | Migrate 3 kinds; add Account and Password Reset | `../site_users/complete/12-builder-integration.complete.md` "Deferred" |
| 2 | **Visibility trait** — show/hide any block by signed-in state | `../site_users/12-builder-integration.md` §12.6 |
| 3 | Admin UI | **B19**, [05](05-admin-module-configuration.md) §5.3 |
| 4 | Route settings panel in the builder | 12's deferred list |
| 5 | Ecommerce handoff — customer identity across both | `../site_users/12-builder-integration.md` §12.9 |

**Item 2 is not a site_users block; it is a trait on every block.** It belongs in
[02](02-module-registry.md) as a universal trait, contributed by site_users when installed —
and it is the clearest case in this plan of a module needing to add something to the registry
rather than register within it. [02](02-module-registry.md) does not currently allow that.

> Two dangling references while reconciling this module:
> `../site_users/complete/11-rollout-status.complete.md` cites `10-admin-dashboard.complete.md`
> and `02-the-rename.complete.md`; neither exists. P7 Admin is marked done against a record
> that was never written, which is consistent with **B19** finding no admin UI in the code.

### 7.5.7 subscribers, listings, discussions, feedback

Grouped because they share one shape: a `builder_output` row, an admin screen, a palette block,
and no runtime. Listings, discussions and feedback are the dynamic `typeName` types
(`components.ts:536`) and appear only when `componentData` carries rows.

Each needs a runtime, or an explicit `noRuntime()` if it is genuinely presentational — and
`noRuntime()` is available to none of them, because a subscribe form that does not subscribe
and a listing grid that lists nothing are not presentational.

The dynamic registration is worth keeping. Registering a block only when the tenant has rows is
`availability.requires` ([02](02-module-registry.md) §2.5) invented early and by hand; the
registry should absorb it rather than replace it.

## 7.6 Order of work

Constrained, not preferred:

1. **[03](03-editor-architecture.md) — extract seams.** Everything else edits `initEditor()`.
2. **[02](02-module-registry.md) — the registry and its consistency test**, with the three
   working modules migrated first (§7.3). Regressions are detectable here and nowhere else.
3. **[05](05-admin-module-configuration.md) §5.5 — the reverse index.** Before instance-selects
   exist, so pickers and delete-refusal ship against a populated table.
4. **[04](04-publish-and-serve.md) — compile step**, which §7.4.3's publish refusal needs.
5. **The six inert modules**, in cost order: direct payments (revenue), appointments (whole
   module unreachable), subscribers, listings, discussions, feedback.
6. **[05](05-admin-module-configuration.md) §5.3 — the Modules index and the Site Users panel.**
7. **§7.4.2 — page-type/block inheritance**, last: it is the only item that changes the meaning
   of pages already published.

Step 5 is where the request's *"drag and drop the modules and configure them"* becomes true.
Steps 1–4 are what make step 5 one file per module instead of a fourth hand-written copy.

## 7.7 Acceptance

| # | Check | Expect |
|---|---|---|
| A1 | Every installed module | Has a palette block — ten of ten (**B17**) |
| A2 | Drag each, configure, publish, load | **Every one behaves** — four of ten today |
| A3 | Every reference trait | Instance picker, not free text |
| A4 | Block on a bound page, trait empty | Inherits the page binding, shown as inherited (§7.4.2) |
| A5 | Block whose instance does not resolve | Editor error; **publish refused** (§7.4.3) |
| A6 | `module-commerce` | Gone, with published pages migrated (**B18**) |
| A7 | The three migrated modules | Byte-identical published output before and after (§7.3) |
| A8 | Site Users visibility trait | Available on **every** block, not only site_users blocks (§7.5.6) |
| A9 | A module with no `builder_output` rows | Block hidden, not shown-and-broken (§7.5.7) |
| A10 | Add the eleventh module | One file, one registry line, no other edit (**B6**) |

## 7.8 Re-audit, 2026-09-01 — after 02–05

§7.2 was read on 2026-08-31, before the registry, the compile step and the instance pickers
existed. Four of its columns are now answered and two of its rows were wrong about what the
work *is*.

### 7.8.1 Where every module stands now

| Module | Palette block | Runtime | Instance picker | Registry | Gap that remains |
|---|---|---|---|---|---|
| **forms** | ✅ `module-form` | ✅ | ✅ `instance-select` | ✅ | block registration is still hand-written |
| **ecommerce** | ✅ 7 kinds | ✅ | ✅ | ✅ | page-type rule (§7.4); `module-commerce` (**B18**) |
| **events** | ✅ 3 kinds | ✅ | ✅ | ✅ | page-type rule |
| **site_users** | ✅ 3 kinds | ✅ | ✅ | ✅ | visibility trait; Account/Reset blocks |
| **direct payments** | ⚠️ `module-payment` | ❌ **inert** | ✅ (on the checkout block) | ❌ | **runtime**; declaration |
| **subscribers** | ⚠️ `module-subscriber` | ❌ **inert** | — | ❌ | **runtime**; declaration |
| **appointments** | ❌ **none** | ❌ | — | ❌ | **everything** (**B17**) |
| **listings** | ⚠️ dynamic | ❌ **inert** | — | ❌ | **runtime**; declaration |
| **feedback** | ⚠️ dynamic | ❌ **inert** | — | ❌ | **runtime**; declaration |
| **discussions** | ⚠️ dynamic | ❌ | — | ❌ | **there is no module** — see §7.8.3 |

Closed since §7.2 was written: every "picker" cell, and site_users' admin UI (**B19**).

### 7.8.2 What is actually published, which changes the risk

Read from `laravel_builder_published` — 37 rows across all versions, 2 active:

| Block | Rows containing it | Active rows |
|---|---|---|
| `module-form` | — | **1** |
| `module-subscriber` | 2 | 0 |
| `module-payment` | 0 | 0 |
| `module-commerce` | **0** | 0 |
| `module-listing` / `-discussion` / `-feedback` | 0 | 0 |

**B18's migration risk is nil on this deployment.** §7.5.1 item 2 warns that *"pages published
with `module-commerce` exist, and deleting the type without a migration blanks them"*. None do.
The type is also only registered when `componentData.commerce` is non-empty, and
`laravel_builder_output` holds exactly one row — a form. So `module-commerce` does not currently
reach the palette either.

The retirement still has to happen, and still needs the migration written, because *this*
database is not every database. But it is a low-risk phase, not the delicate one §7.5.1
implies.

### 7.8.3 B26 — discussions is not an inert module; it is not a module

§7.5.7 groups subscribers, listings, discussions and feedback as sharing one shape: *"a
`builder_output` row, an admin screen, a palette block, and no runtime."* Discussions does not
share it.

```
routes matching /discussion/  : NONE
app/Http/Controllers/*discuss*: none
app/Models/*discuss*          : none
```

There is a palette block, a nav item, and an admin view id. There is **no controller, no model
and no route** — nothing for a runtime to call, and nothing an `instance-select` could list.

> **B26 — the discussions module has a builder block and an admin nav entry, and no backend at
> all.** §7.5.7 asks for a runtime for it. There is nothing to write one against.

`noRuntime()` is the honest declaration here, and its reason is not "presentational" — it is
that the block injects stored HTML from `laravel_builder_output` and nothing more. That is a
real, if small, thing: it is the same mechanism the forms block used before it had an API.

### 7.8.4 B25 — three more modules expose unauthenticated CRUD

Looking for endpoints to write runtimes against found the same shape as **B20**:

```
GET    /portal/api/v1/subscribers            → 200 unauthenticated
GET    /portal/api/v1/listings               → 200 unauthenticated
GET    /portal/api/v1/feedback/forms         → 200 unauthenticated
GET    /portal/api/v1/subscribers/categories → 200 unauthenticated
```

Reads only were probed. The same route groups also declare
`DELETE /portal/api/v1/listings/{listing_id}`, `DELETE /portal/api/v1/listings/{id}/items/{item_id}`
and `DELETE /portal/api/v1/subscribers/{subscriber_id}` with the same middleware — which is to
say none.

> **B25 — subscribers, listings and feedback expose full CRUD, including deletes, with no
> authentication.** **B20** is not a builder-specific lapse; it is a pattern.

Two of these groups do contain genuinely public endpoints a runtime needs —
`POST /subscribers/subscribe` and `POST /feedback/submit` — so the fix is not "authenticate the
group", it is to separate the public surface from the administrative one, the way ecommerce,
events and appointments already do with their `storefront` prefixes. **That is not this step's
work**, and this step adds nothing to those groups: the runtimes below call only the endpoints
that are meant to be public.

## 7.9 Phases

Ordered by §7.6, with the constraint it states kept: the two items that change the meaning of
pages **already published** land last.

| Phase | Contents | Why here |
|---|---|---|
| **P0** | Registry-driven block registration — the four working modules register *from* their declaration (§7.3) | **First.** Until the registry drives registration it only describes it, and every later phase would add a fifth hand-written copy |
| **P1** | Direct Payments: runtime + declaration (§7.5.4) | Cost order — the one module where an inert block loses money rather than layout |
| **P2** | Subscribers: runtime + declaration | Has a genuinely public `subscribe` endpoint; smallest real runtime |
| **P3** | Appointments: blocks, runtime, declaration (§7.5.3, **B17**) | The largest gap. Its storefront API is complete, so this is a builder-side gap only |
| **P4** | Listings and feedback: runtimes + declarations; discussions declared `noRuntime()` (§7.8.3) | Needs P0's registration path; **B26** is settled here |
| **P5** | The site_users visibility trait, on every block (§7.5.6 item 2, A8) | A universal trait — 02 §2.9.3's mechanism, first real user |
| **P6** | Retire `module-commerce` with a migration (**B18**, A6) | Changes published pages. Low risk here (§7.8.2), not everywhere |
| **P7** | Page-type → block instance inheritance (§7.4.2, A4) | §7.6: *last, it is the only item that changes the meaning of pages already published* |
| **P8** | UNRESOLVED — editor error state and publish refusal (§7.4.3, A5) | Needs P7: a block is only unresolved once inheritance has been given its chance to resolve it |

> ### P0 is the phase that decides whether the registry was worth building
>
> [02](02-module-registry.md) shipped `BuilderModule` and four declarations, and the editor
> still registers blocks from `commerce_components.ts`, `events_components.ts`,
> `site_users_components.ts` and `components.ts` by hand. The declarations are checked by
> `check-modules.mjs` and read by the publish compiler and the instance pickers — but nothing
> in the editor reads them to *create* a block.
>
> That is **B6** and **B16** still open: a module is still three hand-edited files, and the
> registry is a fifth place that has to agree with them. Every phase after P0 adds a module;
> doing P0 first is what makes each of those one file instead of four edits, which is A10.
