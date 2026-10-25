# 05 — Admin module configuration: instances, and the reference that binds them

> **STUB, FOR AUDIT.** No application code has been written.

Closes **B19**, and answers the question [02](02-module-registry.md) leaves open: a block's
traits ask for `data-store-ref`, `data-event-ref`, `data-siteusers-module`, `data-dp-ref` —
**where does the tenant get that value?**

---

## 5.1 The half of configuration that is not in the builder

A module block is configured in two places, and the plan so far only describes one:

```
  ADMIN                                    BUILDER
  ─────                                    ───────
  create the INSTANCE                      drag the BLOCK
  a store, an event, a Direct Payment,     set its traits
  a Site Users module                         └ data-store-ref ──┐
       └ reference_num ────────────────────────────────────────┘
  configure the instance                   publish
  (tiers, tax, SSO, notifications)
```

Traits ([02](02-module-registry.md) §2.2) are the right-hand column. This document is the
left-hand one, and the arrow between them.

**Without the arrow the block cannot work**, which is a second and quieter way for a module to
be inert: not because it has no `runtime` (**B1**) but because the reference it needs names
nothing. **B1 is an omission in code; this is an omission in the tenant's workflow**, and it
produces the same published page that renders and does nothing.

## 5.2 Where instances are configured today

Read from `routes/portal.php` and `resources/js/portal/admin/controllers/` on 2026-08-31.

| Module | Instance record | Admin API | Admin UI | Block reference trait |
|---|---|---|---|---|
| ecommerce | store | `EcommerceController` (+ `EcommerceSettingsController`, `EcommercePromotionsController`) | ✅ `admin/controllers/ecommerce` | `data-store-ref` |
| events | event | `EventController` | ✅ `admin/controllers/events` | `data-event-ref` |
| direct payments | `DirectPayment.reference_num` | `/portal/api/v1/directpayments` — tiers, items, connect, subscriptions, notifications, advanced | ✅ `direct_payments/directpayments.ts` (1,280 lines) | `data-dp-ref` |
| forms | form | `FormsController` | ✅ `admin/controllers/forms` | form ref (in `module-form` traits) |
| appointments | appointment | `AppointmentsController` | ✅ `admin/controllers/appointments` | **none — no block (B17)** |
| subscribers | `builder_output` row | `SubscribersController` | ✅ `admin/controllers/subscribers` | **none — block inert (B1)** |
| listings | `builder_output` row | `ListingsController` | ✅ `admin/controllers/listings` | **none — block inert (B1)** |
| **site_users** | module (`referenceNum`) | `/portal/api/siteusers/*` | ❌ **none** | `data-siteusers-module` |

> **B19, stated as a workflow.** The Site Users blocks — Login, Register, User Menu — each ask
> for a "Site Users module id". `SiteUsersAdminController` can `activate` a module and
> `updateModule` one. There is no screen in the portal that does either, and no screen that
> lists the ids. The tenant is asked for a value the product never shows them.

**Every other module already works this way and nobody wrote it down.** Direct Payments has the
richest instance configuration in the estate — tiers, connected accounts, subscription
settings, notification templates, an advanced panel behind its own middleware — and its
relationship to the builder is one undocumented trait string on a commerce checkout block.

## 5.3 The decision: one Modules section, per-module panels

Do **not** build a new admin area per module. There are eight already and they are good; the
problem is that nothing presents them *as modules* or connects them to the builder.

```
Portal Admin
  └ Modules                         ← NEW: the index
      ├ Ecommerce        [enabled]  ← existing admin/controllers/ecommerce, unchanged
      ├ Events           [enabled]  ← existing
      ├ Appointments     [enabled]  ← existing
      ├ Forms            [enabled]  ← existing
      ├ Direct Payments  [enabled]  ← existing (1,280 lines, unchanged)
      ├ Subscribers      [enabled]  ← existing
      ├ Listings         [enabled]  ← existing
      └ Site Users       [enabled]  ← NEW panel (B19)
```

The index owns three things the per-module screens cannot:

1. **Installed / enabled state** — the input to `availability.requires` ([02](02-module-registry.md) §2.5).
2. **Tier state** — what the plan allows, read through `alloc_v2`, so the upgrade prompt lives
   where the tenant is deciding rather than where they are blocked.
3. **The reverse index** — §5.5, which is the part that prevents broken published pages.

**D9 — an existing module admin screen is reached, not replaced.** The Modules index links to
`admin/controllers/<module>`. Direct Payments in particular is not rebuilt, re-skinned or
wrapped; it gains an entry in an index and a "used by" panel.

## 5.4 The instance contract

Every module that a builder block can reference must expose the same three things. This is the
admin-side counterpart of `BuilderModule`, and the consistency test ([02](02-module-registry.md)
§2.4) gains a check for it.

```ts
interface ModuleInstanceProvider {
    /** Matches BuilderModule.id. */
    moduleId: string;

    /** What a block's reference trait stores. Stable, never reused after delete. */
    refField: 'reference_num' | string;

    /** Instances this tenant can reference, for the trait's picker. §5.6 */
    list(): Promise<Array<{ ref: string; label: string; status: 'active' | 'draft' | 'disabled' }>>;

    /** Deep link to this instance's existing admin screen. §5.3 D9 */
    adminUrl(ref: string): string;
}
```

`status` is on the list deliberately. A tenant referencing a draft store from a published page
is a real mistake with no current warning, and the picker is where it costs nothing to say so.

## 5.5 The reverse index: what breaks if this is deleted

**This is the part that has to exist before any of the rest is safe.**

Today a tenant can delete a store, an event or a Direct Payment that a published page
references. Nothing checks. The page keeps serving, the block keeps rendering, and its runtime
gets a 404 from an API — on a live site, with no notification to anyone.

```sql
-- every published block that names an instance of this module
SELECT page_ref, subpaths, module_id, instance_ref
FROM   laravel_builder_module_refs
WHERE  module_id = ? AND instance_ref = ?
```

Populated at publish time ([04](04-publish-and-serve.md) §4.2 already walks every component to
compile the runtime — this is one more thing that walk records), so it costs no extra pass.

Three uses, in increasing order of importance:

| Surface | Behaviour |
|---|---|
| Module admin, per instance | *"Used on 3 published pages"*, linked |
| Builder trait picker | Instances already in use are marked, not hidden |
| **Delete an instance** | **Refuse, and list the pages** — with an explicit override that says what will break |

> **The refusal is the feature.** [../../allocation.md](../../allocation.md) G7's distinction
> applies again: "this would break three live pages" and "you cannot do this" are different
> messages, and only the first lets the tenant act. Do not make it a silent cascade and do not
> make it an unexplained error.

## 5.6 The trait picker, and why the reference is not a text box

Today every reference trait is a free-text field:

```ts
{ name: 'data-siteusers-module', label: 'Site Users module id', type: 'text' }
{ name: 'data-store-ref',        label: 'Store reference',      type: 'text' }
```

A tenant types a `reference_num` from memory, or pastes one from a URL, or gets it wrong and
finds out after publishing. [02](02-module-registry.md) §2.2 already sketches a `store-select`
trait type; §5.4's `list()` is what makes it implementable **once, for every module**:

```ts
{ name: 'store_ref', label: 'Store', type: 'instance-select', module: 'ecommerce', required: true }
```

One trait type, driven by `moduleId`. It renders a picker of that module's instances, shows
`status`, links to `adminUrl(ref)` for "configure this store", and offers *"Create a store"*
when the list is empty — which is the moment a tenant is most likely to be stuck and least
likely to know where to go.

**An empty list is a first-run state, not an error.** A tenant who has just enabled Ecommerce
and dragged a Product Grid has done everything right and has nothing to select. That path must
lead somewhere.

## 5.7 Direct Payments, specifically

Called out because it is the module the request named, it is the most configured thing in the
estate, and its builder story is currently the weakest of any module that has one.

**Today:**

- `module-payment` — palette block "Direct Payments", `components.ts:388`, **no `script`,
  inert** (**B1**). The single most likely block for a tenant to drag when they want to take
  a payment, and it does nothing.
- `data-dp-ref` — a trait on the *commerce checkout* block. Direct Payments' only working
  builder presence is as a field on another module's block.
- The admin UI is complete and unconnected: 1,280 lines covering payments, invoices, tiers,
  connect accounts, subscriptions, notifications and an advanced panel.

**What this plan requires of it:**

| # | Requirement | Where |
|---|---|---|
| 1 | `module-payment` gains a `runtime`, or is deleted | [02](02-module-registry.md) §2.2.1 — `noRuntime()` is not available to it; a payment block that renders and does not take payment is not presentational |
| 2 | Its reference trait becomes `instance-select` over `DirectPayment` | §5.6 |
| 3 | It appears in the Modules index, linking to the existing screen | §5.3 D9 |
| 4 | Deleting a `DirectPayment` referenced by a published page refuses | §5.5 |
| 5 | Its instance list exposes `status` | §5.4 — a DP with no connected processor account must not look ready |

Requirement 5 is the one that is specific to this module. A `DirectPayment` with no
`DirectPaymentConnect` row cannot take money, and today nothing between the admin screen and
the published page says so. `status: 'draft'` is where that surfaces.

> **Payment is where an inert block costs the most.** Every other inert module wastes a
> tenant's layout work. This one silently fails to collect revenue on a page the tenant
> believes is selling — and the tenant's own testing will not catch it, because the block
> renders.

## 5.8 Phases

Ordered so nothing reads a table before it is populated, and so the phase that can refuse a
tenant's delete lands only once it can be right about what it is refusing.

| Phase | Contents | Why here |
|---|---|---|
| **P0** | `laravel_builder_module_refs` and populating it at publish (§5.5) | **First.** Every later phase reads it, and a reverse index that is only correct for pages published *after* it existed is worse than none — see the backfill note below |
| **P1** | Refuse a delete that would break a live page (§5.5) | Needs P0 populated. The phase a tenant can feel, so it lands alone |
| **P2** | `ModuleInstanceProvider` — `list()`, `status`, `adminUrl()` (§5.4) | The contract every module implements. No UI yet |
| **P3** | `instance-select` (§5.6) — **and [02](02-module-registry.md)'s P8** | Unblocks the phase 02 closed as gated. Needs P2's `list()` |
| **P4** | The Modules index (§5.3) | Links to the seven admin screens that already exist. D9: reached, not replaced |
| **P5** | The Site Users admin panel (**B19**, §5.2) | The module with routes and no UI. Needs P4's index to live in |
| **P6** | Direct Payments' `status` from its connected account (§5.7 item 5) | Needs P2's contract; specific to the module where an inert block costs revenue |

> ### The backfill is P0's real work
>
> `laravel_builder_published.modules` already records **which** modules a page contains
> ([04](04-publish-and-serve.md) P2) — but not **which instance** each block points at, which
> is the whole question §5.5 asks. The compiler has to collect the reference traits too.
>
> And pages published before P0 have no entry at all. A delete-refusal that silently permits
> deleting a store used by every page published last year is worse than no refusal, because it
> is *trusted*. P0 therefore backfills from the stored `html` of every active row, and P1 must
> not ship until it has.

## 5.9 Acceptance

| # | Check | Expect |
|---|---|---|
| A1 | Portal admin → Modules | Every installed module listed with enabled and tier state |
| A2 | A module the tier excludes | Shown with the upgrade reason, consistent with the palette (§5.3, [02](02-module-registry.md) §2.5) |
| A3 | Site Users panel | Activate a module, list modules, edit one, read its id — all without the API (**B19**) |
| A4 | Any reference trait | A picker of real instances, not a text box (§5.6) |
| A5 | Reference trait with no instances | Offers "create one", links to that module's admin |
| A6 | Delete an instance referenced by a published page | **Refused**, with the pages listed (§5.5) |
| A7 | Delete an unreferenced instance | Proceeds |
| A8 | Publish a page with module blocks | `laravel_builder_module_refs` reflects it exactly |
| A9 | A Direct Payment with no connected account | Shows as `draft` in the picker (§5.7) |
| A10 | Drag Direct Payments, pick an instance, publish, pay | **It takes a payment** — the check it fails today |

## 5.10 Reconciliation — what building it changed (2026-09-01)

Five places where the document above was incomplete or wrong. Recorded here rather than edited
into the sections, so the difference between what was designed and what the code met stays
readable.

### 5.10.1 `module_id` is the module of the INSTANCE, not of the block

§5.5 assumes one reference trait per module: `data-store-ref` names a store on an ecommerce
block. §5.7 item 4 then asks for a Direct Payment delete to refuse — and Direct Payments'
*only* builder presence is `data-dp-ref`, **a trait on the ecommerce checkout block**.

Filed under the block's module, that row would make the ecommerce delete screen answer a
question nobody asked and leave the Direct Payments one still silently permitting. So
`laravel_builder_module_refs.module_id` is the module that **owns the instance**, and
`BuilderModule` gained `foreignRefTraits` — trait name → owning module — to say which trait
belongs to whom.

This is also what lets Direct Payments have a `ModuleInstanceProvider` without a fake
`BuilderModule` declaration, which §5.7 item 1 leaves to [02](02-module-registry.md) §2.2.1.

### 5.10.2 The backfill had to learn legacy markup, and that was the whole phase

§5.8's P0 note says the backfill reads *"the stored `html` of every active row"*. The first
version read `data-afs-module` — the attribute [02](02-module-registry.md) §2.6's version
stamping writes — and reported:

```
Scanning 2 active published page(s)…
Would write 0 reference(s) across 0 page(s).
```

against a database whose live `/form` page carries a real form block with a real
`data-form-ref`. **Version stamping is not yet wired into block registration, so no page
published to date has that attribute.** P1 on that index would have answered "nothing uses this
form" and allowed the delete — the confidently-wrong index §5.8 warns about, built by following
§5.8.

The command now also matches on component class (`module-form`, `module-commerce*`,
`module-events*`, `module-siteusers*`) and reads the reference off the **same element** — not
from anywhere in the document, because the serialised runtime scripts in `html` (**B3**) contain
the literal string `getAttribute("data-form-ref")` seven times on that one page.

### 5.10.3 `ModuleInstanceProvider` needed a fifth member

§5.4 lists four. §5.6 then requires that an empty list *"must lead somewhere"* — and
`adminUrl('')` would satisfy that by accident, breaking the first time a module validates its
own reference. `createUrl()` is explicit.

### 5.10.4 The picker's "already in use" mark needed an endpoint

§5.5's middle row — *"instances already in use are marked, not hidden"* — has no data source in
the document. `GET /portal/api/builder/module-refs?module=…` returns per-instance counts over
active published pages.

It is registered in the **authenticated** admin group, not beside the builder's other routes,
because of **B20** ([01](01-current-state-audit.md) §1.11.1): that group has no
`ApiAuthMiddleware`, and this endpoint reports which page of which project uses which instance.

### 5.10.5 §5.7 item 5's readiness signal is not "has a connect row"

Reading `laravel_direct_payment_connect` changed the answer twice:

- it is the **CONNECT pay-type's config row**, not evidence of a connected account — a
  `SINGLE_PAY` payment never has one and charges perfectly well
- **`exposed_account_refs: null` means *every* connected account**, per the model's own
  docblock. Only an empty array exposes nothing

And a stronger signal sits on the parent row: **`processor_reference_num`**. A Direct Payment
with no processor cannot charge under any pay type — a wider net than §5.7's example that
catches it too.

### 5.10.6 What is still open

| # | Item | Where it belongs |
|---|---|---|
| 1 | `module-payment` gains a `runtime` or is deleted (§5.7 item 1) | [02](02-module-registry.md) §2.2.1 — explicitly deferred there, not here |
| 2 | A10 — drag Direct Payments, pick an instance, publish, **pay** | Blocked on item 1; the block is still inert (**B1**) |
| 3 | **B20** — the builder API group has no `ApiAuthMiddleware` | [04](04-publish-and-serve.md); nothing new was added to that group |
| 4 | **B23** — nothing records per-tenant module installation | Needs a decision about where that state lives before `availability.requires` means anything |
| 5 | **B24** — `/form` references a form that no longer exists | A data question for whoever owns that site, not a code fix |
