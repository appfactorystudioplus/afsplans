# 05 — Admin module configuration: instances, and the reference that binds them (complete)

## Status

**All seven phases done. 229 builder tests** (was 175 after
[04](04-publish-and-serve.complete.md)) **plus 24 PHP tests**, two of them new files.

**The finding that mattered most was not in the plan, again.** P0's backfill — the phase
§5.8 calls "P0's real work" — reported *zero* references across a database whose live page
carries a real form block with a real reference. The index P1 was about to trust was empty, and
following §5.8 exactly is what would have produced it.

| Phase | Contents | State |
|---|---|---|
| **P0** | `laravel_builder_module_refs`, populated at publish, **backfilled** | ✅ — and the backfill is where the phase actually was |
| **P1** | Refuse a delete that would break a live page | ✅ four delete endpoints |
| **P2** | `ModuleInstanceProvider` — `list()`, `status`, `adminUrl()` | ✅ five providers |
| **P3** | `instance-select`, **and [02](02-module-registry.complete.md)'s gated P8** | ✅ five traits converted; **B21** found |
| **P4** | The Modules index | ✅ D9 — every card links out |
| **P5** | The Site Users admin panel | ✅ **B19** |
| **P6** | Direct Payments `status` from its connected account | ✅ — the signal was not the one §5.7 named |

Five new findings, **B20**–**B24**, recorded in
[01](../01-current-state-audit.md) §1.11. One is Critical.

## P0 — the backfill reported zero, and it was right to

The command's first version looked for `data-afs-module`, which is what
[02](02-module-registry.complete.md) §2.6's version stamping writes:

```
Scanning 2 active published page(s)…
Would write 0 reference(s) across 0 page(s).
```

A direct query said why:

| subpaths | has `data-afs-module` | has `module-form` | has `data-form-ref` |
|---|---|---|---|
| index | 0 | 0 | 0 |
| form | **0** | **1** | **1** |

**Version stamping is not yet wired into block registration.** No page published to date
carries that attribute, so the reverse index was empty for every page that already exists — the
exact state §5.8 warns is *worse than none*, because a refusal built on it is trusted:

> A delete-refusal that silently permits deleting a store used by every page published last
> year is worse than no refusal, because it is trusted.

`BuilderBackfillModuleRefs` now also matches on component class and reads the reference off the
**same element**. That last part is not incidental: `/form` contains `data-form-ref` seven
times, six of them inside the runtime scripts GrapesJS serialised into `html` (**B3**). Reading
the attribute from anywhere in the document would have indexed
`getAttribute("data-form-ref")||""` as an instance reference.

After the fix, on live data:

```
Scanning 2 active published page(s)…
  projeKEnNosrOA3M/form → forms = vuls3jcsaya8
Wrote 1 reference(s) across 1 page(s).
```

Run twice: still one row. It replaces a page's rows rather than accumulating.

## B24 — §5.5's premise is not hypothetical

`vuls3jcsaya8` is in `laravel_analytics_events` and one `laravel_mail_handlers` row. It is in
**neither** `laravel_forms` nor the legacy `forms` table.

> A live published page on this deployment is serving a form block bound to a form that does not
> exist, and nothing has ever said so.

That is §5.5's opening paragraph, found in the wild by the first thing that ever asked the
question.

## The `module_id` column is the module of the INSTANCE

§5.5 assumes one reference trait per module. §5.7 item 4 then asks for a *Direct Payment*
delete to refuse — and Direct Payments' only builder presence is `data-dp-ref`, **a trait on
the ecommerce checkout block**.

Filed under the block's module, that row would make the ecommerce delete screen answer a
question nobody asked, and leave the Direct Payments one still silently permitting. So
`BuilderModule` gained `foreignRefTraits` (trait → owning module), and the compiler and the
backfill both file the row under the module that owns the instance.

It is also what lets Direct Payments have a `ModuleInstanceProvider` without a fake
`BuilderModule` declaration — §5.7 item 1's fate is [02](02-module-registry.complete.md)
§2.2.1's decision, not this document's.

Verified end to end. One checkout block naming a store *and* a payment:

```
projA8REFPROBE  a8refprobe  ecommerce        st_a8   3
projA8REFPROBE  a8refprobe  direct_payments  dp_a8   1
projA8REFPROBE  a8refprobe  forms            f_a8    1
```

## P1 — the refusal is the message, so it lives in one place

`PublishedModuleReferences` is wired into four delete endpoints — `EcommerceController::
deleteStore`, `EventController::destroy`, `FormsController::deleteForm`,
`DirectPaymentsController::destroy`. `site_users` has no module-delete endpoint at all, so
there is nothing to guard; only its SSO configs and its users can be deleted.

One service, not four checks, because §5.5 is explicit that the wording *is* the feature:

> "this would break three live pages" and "you cannot do this" are different messages, and only
> the first lets the tenant act.

Against live data:

```json
409 {
  "code": "MODULE_INSTANCE_IN_USE",
  "message": "This form is used by 1 published page. Deleting it will leave that page showing
              an error to visitors. Remove the block from that page and republish, or confirm
              to delete anyway.",
  "pages": [{ "project": "projeKEnNosrOA3M", "slug": "form", "label": "form" }],
  "override_field": "confirm_break_published_pages"
}
```

The override is named rather than `force`. A generic force flag gets added to a client's retry
logic once and then defeats the refusal forever; this one reads wrong in that position.

The lookup **inner-joins** to active published rows, and that is load-bearing rather than
tidy: an unpublished page stops matching, so it cannot refuse a delete on behalf of a page
nobody can visit. Unpublish and project-delete clear their rows anyway, so a query written
against the table directly does not mislead.

## P3 — B21: the Forms block could not select a tenant's form

Converting the reference traits found that `module-form`'s "Form Type" dropdown was a `select`
over `surveyFormOptions` — **hardcoded sample forms**, whose own description says one *"will
render but has not functionality"*. Nothing in the builder ever called
`addSurveyFormModel()` or `refreshSurveyFormOptions()`.

> A tenant could drag the Forms block, configure it, publish it, and get a form that collects
> nothing. That is worse than the free-text boxes the other three modules had — those could at
> least name a real form.

All five reference traits are now `instance-select`: `data-store-ref`, `data-event-ref`,
`data-siteusers-module`, `data-dp-ref`, and `survey_form_id`. The form block now resolves a real
form's definition from the site-scoped admin endpoint, with the samples kept as a synchronous
first try so pages already built on one render unchanged.

`data-site-ref` deliberately stays free text: it names a website, not an instance of a module —
the same distinction `instanceRefTrait` exists to record.

## B22 — a tier gate that could never fire

`forms.module.ts` declared `availability.tier: 'forms_count'`. The allocation registry and
`resources/js/checkpoint/resources.ts` both call it `domain_forms_count`. `forms_count` is in
neither.

`paletteState()` fails open on an unknown tier **by design**, so the gate resolved to
"available" every time and nothing looked wrong.

[02](02-module-registry.complete.md) §2.4 recorded its *check 4* as out of reach because it
"needs `checkpoint_registry_v4.json`, which is fetched at runtime". It does not:
`resources.ts` is in this repo and is what every other allocation caller already uses.
`check-modules.mjs` now refuses an unknown id, and caught this on its first run:

```
Module tier gates name unknown resources:
  - module "forms" gates on tier resource "forms_count", which is not in AllocV2Resources
    — the gate would silently never fire
```

## B20 — the builder API is unauthenticated

Found while deciding where P3's usage endpoint should live. Verified against the running site:

```
GET /portal/api/v1/builder/stores    → 200      (every store, every tenant, no filter)
GET /portal/api/v1/builder/projects  → 200
GET /portal/api/ecommerce/stores     → 401      (the scoped equivalent)
GET /portal/api/v1/forms             → 401
```

The same group holds `POST /publish`, `/publish/activate`, `/publish/unpublish`,
`DELETE /publish/delete` and `DELETE /projects/{ref}`. Those were **not** probed
unauthenticated — that is not a diagnostic, it is an incident. Their middleware is read from
`route:list` and is identical.

Two consequences here, both deliberate: §5.4's providers read the **scoped** admin endpoints
even though `/builder/stores` was closer to hand, and P3's usage endpoint — which reports which
page of which project uses which instance — was registered in the authenticated group. It
returns 401 unauthenticated. Fixing the group belongs with
[04](04-publish-and-serve.complete.md); nothing new was added to it.

## P6 — the readiness signal was not the one §5.7 named

§5.7 item 5 says a Direct Payment with no `DirectPaymentConnect` row cannot take money. Reading
the schema changed that twice:

- the connect row is the **CONNECT pay-type's config**, not evidence of a connected account. A
  `SINGLE_PAY` payment never has one and charges perfectly well — marking those `draft` would
  cry wolf on every Direct Payment in the estate
- **`exposed_account_refs: null` means *every* connected account**, per the model's own
  docblock — the marketplace's actual usage. Only an empty array exposes nothing

And a stronger signal sits on the parent row: **`processor_reference_num`**. No processor, no
charge, under any pay type — a wider net than §5.7's example that catches it too.

## P4 and P5 — reached, not replaced

The Modules index (`modules`) lists the four registered modules through the builder's own
`paletteStates()`, so A2's "consistent with the palette" is structural rather than maintained.
The four with admin screens and no builder declaration — Direct Payments, Appointments,
Subscribers, Listings — are listed too, because the index presents the *tenant's* modules, not
the registry's.

Every card links out. Direct Payments' 1,280 lines are untouched; it gained an index entry and
a used-by count.

The Site Users panel (`siteusers`) closes **B19**: activate a module, list them, rename one,
toggle it, and **read its reference** — the value the builder asks for and the product never
showed. Every endpoint it calls already existed.

**B23 surfaced here.** `availability.requires`'s first gate is "installed and enabled on this
tenant", and nothing records that: `subdomain_properties` holds domains and certificates,
`checkpoint_feature_flags` holds one row. The index treats every module as installed and lets
the tier gate — which *is* backed by data — do the work, stated in `modulesIndex.ts` where
someone will hit it.

## Verification

| Check | Result |
|---|---|
| `npm run build` | ✅ exit 0 |
| `tsc --noEmit` | ✅ clean |
| Builder tests | **229 pass, 0 fail** (was 175) |
| PHP tests | **24 pass** — `BuilderModuleRefsBackfillTest`, `PublishedModuleReferencesTest` |
| `check-modules.mjs` | ✅ — and proven to fail on a bad tier id and a missing provider |
| Backfill on live data | ✅ 1 reference, idempotent |
| A6 — delete a referenced store / payment / form | ✅ 409, page listed, for all three |
| A7 — delete an unreferenced instance | ✅ permitted |
| A8 — publish with module blocks | ✅ 3 rows, incl. the cross-module one |
| Republish without a block | ✅ its rows removed, not accumulated |
| Unpublish | ✅ rows removed |
| `/form` still serves | ✅ 200 |
| `/portal/api/builder/module-refs` unauthenticated | ✅ 401 |

Probe project `projA8REFPROBE` was deleted afterwards; the live index holds one row, the real
one.

## Acceptance

| # | Check | Result |
|---|---|---|
| A1 | Modules index lists every module with enabled and tier state | ✅ — tier reads "unknown" until an `allocV2` lookup is passed in, which fails open by design |
| A2 | A tier-excluded module shows the upgrade reason, consistent with the palette | ✅ structurally — same `paletteStates()` |
| A3 | Site Users panel without the API | ✅ |
| A4 | Any reference trait is a picker | ✅ all five |
| A5 | Empty list offers "create one" | ✅ |
| A6 | Delete a referenced instance | ✅ refused, pages listed |
| A7 | Delete an unreferenced instance | ✅ proceeds |
| A8 | Publish reflects refs exactly | ✅ |
| A9 | A Direct Payment with no connected account shows as draft | ✅ — and P6's rule is broader than A9 asks |
| A10 | Drag Direct Payments, pick an instance, publish, **pay** | ❌ **still fails** — `module-payment` is inert (**B1**), and whether it gains a runtime is [02](02-module-registry.complete.md) §2.2.1's decision, not this document's |

## Residue

| # | Item | Where it belongs |
|---|---|---|
| 1 | A10 — the payment block is still inert | [02](02-module-registry.complete.md) §2.2.1 |
| 2 | **B20** — the builder API group has no `ApiAuthMiddleware` | [04](04-publish-and-serve.complete.md). **Critical, and the largest thing this step found** |
| 3 | **B23** — nothing records per-tenant module installation | Needs a decision about where that state lives before `availability.requires` means anything |
| 4 | **B24** — `/form` names a form that no longer exists | A data question for whoever owns that site |
| 5 | Version stamping is still not wired into block registration | [02](02-module-registry.complete.md) §2.6 — the reason the backfill exists |
| 6 | The Modules index passes no allocation lookup | One line, left out because an advisory read that is wrong in the *restrictive* direction is worse than one that is absent |

## Next

**06 is not written yet** — `06-editor-ux-and-styling.md` is listed in
[README](../README.md) as *to write*, and it owns **B13**, the tenant-facing error surface. This
step added three places that need one: the picker's "not found" option, the form block's "could
not be loaded", and the delete refusal's 409. All three currently speak through the screen they
are on, which is adequate and is not a surface.

Otherwise [07](../07-module-handoffs.md), whose §7.5.1 and §7.6 both depend on this step having
landed.
