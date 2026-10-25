# 01 — Current state audit

> **Originally written 2026-08-27. RE-AUDITED 2026-08-31 against the code as it now
> stands.** Everything was read in `www/resources/js/portal/builder/`, `routes/web.php`,
> `routes/portal.php`, `BuilderPublishedController`, `components.ts` and the three module
> component files added on 2026-08-31. **Extended 2026-09-01** while executing
> [05](05-admin-module-configuration.md) and again on 2026-09-01 while executing
> [07](07-module-handoffs.md); §1.11 and §1.12 hold what those turned up. Findings are
> **B1**–**B31**.
>
> **The situation changed materially between the two readings, and not only for the better.**
> Ecommerce, Events and Site Users each grew a working builder surface — and each did it by
> writing its own copy of the same pattern, in its own file, under its own palette category,
> with no shared type. §1.9 is what the re-audit found, and it is the section to read first
> if you read this document before.
>
> **Nothing in this plan folder has been implemented.** The changes described in §1.10 came
> from the module plans, not from here.

---

## 1.1 The loop, as built

```
  BUILD                          PUBLISH                        SERVE
  ─────                          ───────                        ─────
  portal/builder                 POST /portal/api/v1/           GET /{subpaths}
    main.ts                        builder/publish                 ↓
      └ initEditor()  ──────▶    per page:                    BuilderPublishedController
         (core/imp.ts)             { subpaths, html,               ↓
                                     css, js }                 laravel_builder_published
  blocks + component types                ↓                     WHERE is_active = 1
  traits configure a module        laravel_builder_published        ↓
                                   is_active flips              <style>{css}</style>
                                                                <script defer>{js}</script>
                                                                view('index', …)
```

This works. A page built in the editor is reachable at its slug. The audit below is about
what happens at the edges of that loop, and what happens to a **module** as it travels
through it.

## 1.2 The module pipeline is broken at the last step

This is the finding that matters most, and it is exactly the thing the builder exists to do.

Six component types are registered in `core/components.ts`. **Two of them have a `script`,
and that is still true after the 2026-08-31 work:**

| Type | Registered | `script` | Behaviour on a published page |
|---|---|---|---|
| `module-form` | `:288` | ✅ `formsComponentScript` | Renders and works |
| `script_runner` | `:484` | ✅ `ScriptRunner` (analytics) | Works |
| `module-payment` | `:388` | ❌ | **Markup only** |
| `module-subscriber` | `:437` | ❌ | **Markup only** |
| `module-commerce` | `:603` | ❌ | **Markup only** |
| dynamic DB-driven types (`typeName`) | `:536` | ❌ | **Markup only** |

> **B1 — four of six types in `components.ts` produce no runtime behaviour.** A tenant can
> drag Direct Payments or Subscribers onto a page, configure it through its traits, publish,
> and get a page that renders the block and does nothing. There is no error and no warning —
> the block is present, styled, and inert.

**B1 is unchanged in this file, and that is the point.** Ecommerce, Events and Site Users
each got a working surface on 2026-08-31 — in *new* files, beside these. The inert types were
not fixed, replaced or removed. `module-commerce` still sits in the palette doing nothing,
now next to seven Commerce blocks that work (**B18**).

**B2 — the `js` column is never written.** The publish payload hardcodes it:

```ts
js: '',       // Reserved for future page-level JS support
```

`BuilderPublishedController` reads `$page->js` and emits `<script defer>…</script>`, and the
schema comment says CSS and JS are *"stored in separate columns so they can be queried, sized,
and diffed independently."* The column, the injection and the rationale all exist. Nothing
populates it.

**B3 — module JavaScript ships inside the `html` column instead.** GrapesJS serialises a
component's `script` into the exported markup, so `module-form`'s behaviour reaches the page
as an inline `<script>` inside the HTML blob. That is why forms work despite **B2**.

Three consequences worth stating separately, because they are usually discovered one at a
time:

- The separation the schema was designed for is not real for JS. It cannot be diffed, sized
  or content-hashed.
- Page-level JS has nowhere to live, so anything not attached to a single component has no
  home at all.
- The inline script is inside the HTML the editor round-trips, so an edit to the markup can
  silently alter shipped behaviour.

## 1.3 `initEditor()` is one function of 2,190 lines

`core/imp.ts` exports three symbols. One of them is the entire editor:

```
imp.ts  :26    export function initEditor(options?, saveCallback?)
        …
        :2215  export function setProjectName(name)
        :2227  export function getProjectName()
```

**B4** — canvas setup, block registration, component types, traits, panels, the publish
button, publish history, republish, i18n wiring and toast handling all live inside that one
closure, sharing scope. Nothing inside it is reachable from a test, and every module
integration makes it longer.

This is the single biggest obstacle to the word "robust". Not because long functions are
inherently wrong, but because **there is no seam** — a module cannot be added, a panel cannot
be replaced, and a publish step cannot be verified without editing a 2,190-line body.

**B5 — there are no tests.** None for the builder, none for the publish payload, none for a
component's `script`. 39,439 lines, zero coverage.

## 1.4 Adding one module means hand-editing three files

There is no module registry. A module's builder surface is assembled by hand across:

| File | What is added by hand | Size |
|---|---|---|
| `core/blocks/modules.ts` | the palette block | 2.5 KB |
| `core/components.ts` | the component type, traits, and `script` | 27.5 KB |
| `core/module_component_defaults.ts` | default content and settings | 36 KB |

**B6** — nothing links the three. A block whose type was never registered renders as a
generic component; a type whose defaults are missing renders empty; a type whose `script` was
forgotten renders inert — which is **B1**, and it happened four times.

> This is the same shape afs-connect hit and solved: eleven hand-maintained registration
> sites became one **source registry**
> ([../../afs-connect/12-source-registry.md](../../afs-connect/12-source-registry.md)), with a
> CI consistency test. The builder needs the same answer, and §02 is it.

**B7 — the module→builder contract is undeclared, and five plans assume it.**
`appointments` (7 docs), `ecommerce` (8), `events` (9), `forms` (16) and `site_users` (10)
all reference the builder — fifty documents — and
[../site_users/12-builder-integration.md](../site_users/12-builder-integration.md) is a
32 KB document entirely about integrating with it. **The builder has no plan of its own**
until this folder, so every one of those documents describes an integration against an
unspecified surface.

## 1.5 Publishing and serving

> **B8 — WITHDRAWN 2026-09-01. It is not a defect.** Kept here with its correction because
> the reasoning that produced it is a trap worth marking.
>
> The claim was that `{subpaths}` cannot match `/`, so `/about/team` 404s. **That is true of
> Laravel's default placeholder and false once a `where()` pattern replaces it** — and this
> route has one. `^(?!portal|auth1|_portal_).*$` permits `/` like any other character.
>
> Tested against live data: publishing `b8flat` and `b8deep/child` and requesting both returns
> **200 for each**, with the nested page's own markup. `/portal/api/v1/builder` still routes to
> the portal (**A8**). Nesting works and always did.
>
> The audit read the comment — *"any single-segment path"* — and the placeholder's default
> behaviour, and did not request a nested page. **The comment is what is wrong**, not the code.

**B8a — every published page returned 500, and no finding said so.** This is what looking for
B8 actually found.

`BuilderPublishedController::show()` passes `afssdk` to the `index` view. `index.blade.php`
renders `<x-layout>` — and **a Blade component does not inherit the calling view's data**, it
receives only the attributes passed to it. So `$afssdk` was undefined inside the layout and
every published page died with `Undefined variable $afssdk`.

Verified on live data before the fix: `/form` — project `projeKEnNosrOA3M`, version 23, 162 KB
of published HTML — returned **HTTP 500**. So did `/`.

**The serve half of the loop was broken for all content**, while the audit was cataloguing
nesting, CSP and concurrency on the assumption that pages served at all. Nothing in **B1**–
**B19** covers it, because every finding came from reading the code and none from requesting a
page.

**B9 — published output is injected raw, and the controller says so.**

```php
if ($page->css) { $stylesScripts .= '<style>' . $page->css . '</style>'; }
if ($page->js)  { $stylesScripts .= '<script defer>' . $page->js . '</script>'; }
```

Its own comment: *"This is safe because the content originates from the builder canvas
(user-controlled but authenticated)."* That reasoning holds for a single-operator tenant and
fails as soon as a tenant has two editors, or a site user with any authoring surface — it
becomes stored XSS with a `<script>` tag already provided. No CSP, no sanitisation, no
allowlist.

**B10 — the `is_active` flip has no stated concurrency behaviour.** Publish deactivates the
previous version and activates the new one. Two publishes racing, or a partial failure
between the two writes, can leave zero active rows for a slug — which renders as a 404 on a
site that was live a moment earlier.

> `site_users/12` **N13** already depends on this table's versioning semantics: it puts route
> protection on the route rather than the published version, *"because Republish restores old
> rows"* and a security flag must not be undone by a content rollback. That reasoning needs
> the publish/rollback contract written down, and it is not.

## 1.6 Weight and duplication

**B11 — 3.1 MB of vendored files that nothing imports.**

| File | Size | Imported? |
|---|---|---|
| `core/dist/grapes.min.js` | 1,111,040 | ❌ |
| `staticbuild/grapes.min.js` | 1,111,040 — **byte-identical** | ❌ |
| `core/dist/index.d.ts` | 469,410 | ❌ |
| `staticbuild/index.d.ts` | 469,410 — **byte-identical** | ❌ |

`imp.ts:9` is `import grapesjs from 'grapesjs'`, and `package.json` carries
`"grapesjs": "^0.22.14"`. The vendored copies are a snapshot of unknown vintage sitting
beside an npm dependency, which is how a debugging session ends up reading the wrong source.

**B12 — `ecommerceTemplates.ts` is 38 KB of markup in a TypeScript file**, and
`templates_data/` holds more. Template content in source means a copy change is a deploy.

## 1.7 Smaller, but load-bearing

**B13 — the editor has no error surface.** Failures reach `console.debug` / `console.warn`
(`script_runner.ts`, `forms_component_script.ts`) and a toast helper in `main.ts`. A tenant
whose module failed to configure sees nothing.

**B14 — `module_component_defaults.ts` is versionless.** A component published last month
carries whatever defaults existed then; the runtime that renders it is whatever ships today.
There is no version on either side, so a defaults change silently reinterprets every page
already published.

## 1.8 What is genuinely good, and should not be rewritten

An audit that only lists defects would misrepresent this codebase.

- **The block/type/trait model is the right one.** GrapesJS's `addType` + traits is exactly
  how a configurable module surface should work, and `module-form` proves the whole path —
  block → type → traits → `script` → published page — functions end to end.
- **The publish-history and republish feature is real** and more than most builders ship.
- **i18n exists** (`core/i18n/`, with locales) rather than being retrofitted later.
- **The AI chat surface** (`core/ai_chat/`, with an MCP client) is a genuine differentiator.
- **`pageManager.ts` already separates pages from the editor**, which is the seam §02's
  registry can build on.

The plan in this folder is **extraction and contracts, not a rewrite.** Everything in §1.8
survives it.

## 1.9 What changed on 2026-08-31, and what it means

Three module plans shipped builder surfaces four days after this audit was written. They are
real, they work, and they were built without this plan — which makes them the best available
evidence for whether it is proposing the right thing.

### 1.9.1 What landed

| File | Lines | Blocks | Palette category | `script` |
|---|---|---|---|---|
| `core/commerce_components.ts` | 110 | 7 — Product Grid, Product Detail, Cart, Cart Badge, Checkout, Order Confirmation, My Orders | **Commerce** | ✅ `commerceComponentScript` |
| `core/events_components.ts` | 88 | 3 — Event List, Event Detail, Registration | **Events** | ✅ `eventsComponentScript` |
| `core/site_users_components.ts` | 83 | 3 — Login, Register, User Menu | **Site Users** | ✅ `siteUsersComponentScript` |

Plus `core/component_scripts/portal_helpers.ts`, shared helper code for the three runtimes.

Documented in [`../ecommerce/complete/09-storefront-and-builder.complete.md`](../ecommerce/complete/09-storefront-and-builder.complete.md)
and [`../site_users/complete/12-builder-integration.complete.md`](../site_users/complete/12-builder-integration.complete.md).

### 1.9.2 They independently converged on this plan's design

Read the three files side by side and they are the same file three times:

```
const KINDS = [ { type, kind, label, icon }, … ]     ← a module declaration list
function placeholder(label, icon, ref): string       ← §2.7's `preview`
domc.addType(type, { … script: xComponentScript })   ← §2.2's `runtime`
    traits: [ { name: 'data-…-ref', label: '…' } ]   ← §2.2's `traits`
bm.add(type, { category: 'X' })                      ← §2.3's registration
```

**Three teams, no shared plan, same shape.** That is the strongest argument [02](02-module-registry.md)
could have: the registry is not being imposed on the codebase, it is being extracted from what
the codebase already does. §2.2's `BuilderModule` is these three files' common denominator with
the fields they each left implicit made explicit.

It is also the argument for doing it **now**. The pattern has been copied three times; the
fourth copy is cheaper to prevent than the first three were to write.

> **B16 — the registry pattern exists three times, unshared.** Each copy re-implements kinds,
> placeholder, type registration, trait wiring and block registration. A change to how modules
> declare traits is now three edits, and nothing checks they agree — which is **B6** again, at
> a larger grain and with more of it to come.

### 1.9.3 There are now THREE ways a module reaches a page

This is the finding that most changes the plan's shape, because [02](02-module-registry.md) as
written assumes one.

| # | Mechanism | Where | Granularity | Modules using it |
|---|---|---|---|---|
| 1 | **Blocks in `components.ts`** | `core/components.ts` | a block on a page | forms ✅, payment ❌, subscriber ❌, commerce ❌, dynamic ❌ |
| 2 | **Per-module component files** | `core/*_components.ts` | a block on a page | ecommerce ✅, events ✅, site_users ✅ |
| 3 | **Page-type binding** | `core/imp.ts` `:1032–1250` | **the whole page** | ecommerce, events, appointments |

Mechanism 3 was not in the original audit and is not in [02](02-module-registry.md). A page has
a `pageType` — `normal`, `e-commerce`, `events`, `appointments` — and choosing one reveals a
select that binds *the page* to a store, event or appointment reference, plus template and
subpage pickers (`cart`, `checkout`) for commerce:

```ts
const isSpecial = ['e-commerce', 'events', 'appointments'].includes(pageType);
if (commerceSection)  commerceSection.style.display  = pageType === 'e-commerce'  ? '' : 'none';
if (eventsSection)    eventsSection.style.display    = pageType === 'events'      ? '' : 'none';
if (appointmentsSection) appointmentsSection.style.display = pageType === 'appointments' ? '' : 'none';
if (authSection)      authSection.style.display      = isSpecial ? '' : 'none';
```

> **B15 — three mechanisms, no stated relationship.** Nothing says what a Product Grid block
> on a page whose `pageType` is already `e-commerce` and bound to a *different* store should
> do. Nothing says whether a block overrides the page binding, inherits it, or conflicts with
> it. A tenant can construct that state today in a few clicks.

**Both mechanisms are legitimate and the plan needs both.** Page-type binding answers "this
whole page IS the store", which is a real thing a tenant wants and which blocks express badly.
Blocks answer "put a cart badge in the header". [02](02-module-registry.md) must say which is
which, and what happens when they meet — see [07](07-module-handoffs.md) §7.4.

### 1.9.4 Appointments has no block surface at all

Fourteen completion documents, an extensive schema (`Appointment`, `AppointmentBooking`,
`AppointmentSchedule`, `AppointmentService`, `AppointmentSlot`, `AppointmentStaff`,
`AppointmentHold`, `AppointmentException`, `AppointmentCalendarConnection`), a public
`AppointmentStorefrontController`, an admin controller — and **not one builder block**.

> **B17 — a fully built module is unreachable from the builder except as a whole page.** The
> only way to put appointments on a site is mechanism 3: make the entire page an appointments
> page. There is no booking-widget block to drop into an existing page.

`grep -ril appointment` across `builder/core/` returns `imp.ts` and `pageManager.ts` — the
page-type path — and nothing else.

### 1.9.5 Two competing ecommerce surfaces in one palette

> **B18 — `module-commerce` and the seven Commerce blocks both ship.** `module-commerce`
> (`components.ts:603`, category *Module Components*, **inert**) is registered whenever
> `componentData.commerce` is non-empty — the same condition under which the working Commerce
> category appears. A tenant browsing the palette sees "E-Commerce Store" and "Product Grid"
> and has nothing to tell them apart except that one of them silently does nothing.

The old type is not deprecated, not hidden, and not mentioned in the ecommerce completion. It
is the single most likely thing for a tenant to drag first, because it is the one named after
the module.

### 1.9.6 No admin surface for module instances

The blocks that landed all bind to an instance by reference:

```ts
{ name: 'data-store-ref',        label: 'Store reference' }        // commerce
{ name: 'data-event-ref',        label: 'Event reference' }        // events
{ name: 'data-siteusers-module', label: 'Site Users module id' }   // site users
{ name: 'data-dp-ref',           label: 'Direct Payment ref' }     // commerce checkout
```

So a block is inert-by-configuration as well as by omission: it needs a reference to an
instance that must already exist. Where the tenant creates that instance varies by module, and
for two of them it is nowhere:

| Module | Instance record | Admin API | Admin UI |
|---|---|---|---|
| ecommerce | store | ✅ `EcommerceController` | ✅ `admin/controllers/ecommerce` |
| events | event | ✅ `EventController` | ✅ `admin/controllers/events` |
| direct payments | `DirectPayment` (`reference_num`) | ✅ `/portal/api/v1/directpayments` | ✅ `direct_payments/directpayments.ts` (1,280 lines) |
| forms | form | ✅ `FormsController` | ✅ `admin/controllers/forms` |
| **site_users** | module (`referenceNum`) | ✅ `/portal/api/siteusers/modules/{ref}` | ❌ **none** |
| **appointments** | appointment | ✅ `AppointmentsController` | ✅ | — but no block to reference it (**B17**) |

> **B19 — the Site Users module has admin routes and no admin UI.** `SiteUsersAdminController`
> exposes `status`, `activate`, `updateModule`, SSO CRUD and user management under
> `/portal/api/siteusers/`, and `resources/js/portal/admin/controllers/` has no `site_users`
> directory. The builder block asks the tenant for a "Site Users module id" that the portal
> gives them no screen to find.

[`../site_users/complete/11-rollout-status.complete.md`](../site_users/complete/11-rollout-status.complete.md)
records P7 Admin as "✓ partial" and defers *"Admin UI rename to Site Users branding + module
settings panel"*. That deferral is what **B19** is.

**Two dangling references, found while checking that.** The same rollout-status document cites
`10-admin-dashboard.complete.md` and `02-the-rename.complete.md`; neither file exists in
`../site_users/complete/`. A phase marked done points at a record of it that was never written.

## 1.10 Findings index

| # | Finding | Severity |
|---|---|---|
| **B1** | Four of six module types have no `script` — configured, published, inert | **Critical** |
| **B2** | The `js` column is never written (`js: ''`) | **Critical** |
| **B3** | Module JS ships inside the `html` blob instead | High |
| **B4** | `initEditor()` is 2,190 lines with no seam | High |
| **B5** | No tests, anywhere | High |
| **B6** | A module means hand-editing three unlinked files | High |
| **B7** | Fifty documents in five plans assume a contract that is undeclared | High |
| **B8** | Published pages cannot be nested | Medium |
| **B9** | Raw `<style>`/`<script>` injection, no CSP | Medium → High on multi-editor tenants |
| **B10** | `is_active` flip has no concurrency or rollback contract | Medium |
| **B11** | 3.1 MB of unimported vendored duplicates | Low |
| **B12** | Template markup lives in TypeScript source | Low |
| **B13** | No error surface for the tenant | Medium |
| **B14** | Component defaults are versionless | Medium |
| **B15** | Three mechanisms put a module on a page; their relationship is undefined | **High** |
| **B16** | The registry pattern is implemented three times, unshared | **High** |
| **B17** | Appointments is fully built and has no block — whole-page binding only | **High** |
| **B18** | Inert `module-commerce` ships beside seven working Commerce blocks | **High** |
| **B19** | Site Users has module admin routes and no admin UI to reach them | **High** |
| **B20** | The whole builder API — publish, unpublish, delete — requires no authentication; `/builder/stores` is unscoped | **Critical** |
| **B21** | The Forms block listed forms from `builder_output`, a side-table, rather than from the forms API — *narrowed 2026-09-01, see §1.11.2* | Medium |
| **B22** | A module tier gate named a non-existent resource and failed open silently | Medium |
| **B23** | `availability.requires` reads an installed/enabled state nothing records | Medium |
| **B24** | A live published page already references a form that no longer exists | **High** |
| **B25** | Subscribers, listings and feedback expose full CRUD, deletes included, with no authentication | **Critical** |
| **B26** | The discussions module has a builder block and an admin nav entry, and no backend at all | **High** |
| **B27** | `resources/js/checkpoint/resources.ts` had drifted from the allocation registry — five resources missing | Medium |
| **B28** | `/portal/admin1` returned 500 for every request — **B8a in the admin views** | **Critical** |
| **B29** | `adminUrl()` produced a URL nothing could open, and navigated away from unsaved builder work | **High** |
| **B30** | The admin SPA never called `startRouter()`, so the URL it arrived with was ignored | **High** |
| **B31** | `ViewSubViewRouter` matched routes by exact string — a `:param` route could never fire, and `_update_url()` wrote the template back into the address bar | **High** |

**B25–B27 are from executing [07](07-module-handoffs.md) (§1.12).** **B25** is **B20** again in
three more modules, which is what makes it a pattern rather than a lapse.

**B20–B24 are from executing [05](05-admin-module-configuration.md) on 2026-09-01 (§1.11).**
None were visible from reading; each surfaced from building against the running system. **B24**
in particular is the first evidence that §5.5's premise was not a prediction.

**B15–B19 are all from the 2026-08-31 re-audit (§1.9).** Four of the five are consequences of
module surfaces landing without a builder contract to land against — which is **B7**, now with
worked examples instead of a prediction.

## 1.11 What executing 05 turned up (2026-09-01)

Five findings, none of them predicted, all of them from building the thing rather than reading
about it. Two are the same shape as **B1**: a mechanism that exists, runs, and quietly does
nothing.

### 1.11.1 The builder API is unauthenticated

`routes/portal.php:444` opens `Route::prefix('api/v1/builder')->group(…)` with **no
`ApiAuthMiddleware`**. Inherited from the enclosing group it carries `portal.cors` and
`site_users.api.guard` and nothing else. Verified against the running local site:

```
GET /portal/api/v1/builder/stores    → 200      (every store, every tenant, no tenant filter)
GET /portal/api/v1/builder/projects  → 200
GET /portal/api/ecommerce/stores     → 401      (the scoped equivalent)
GET /portal/api/v1/forms             → 401
```

The group also holds `POST /publish`, `POST /publish/activate`, `POST /publish/unpublish`,
`DELETE /publish/delete` and `DELETE /projects/{ref}`. Those were **not** probed — an
unauthenticated write test against a live database is not a diagnostic, it is the incident.
Their middleware list is read from `php artisan route:list` and is identical to the two that
were probed.

> **B20 — the entire builder API, including publish and delete, requires no authentication.**
> `/portal/api/v1/builder/stores` additionally returns every row of
> `laravel_ecommerce_stores` with no tenant filter at all: it is a route-file closure, not a
> controller method.

This is why [05](05-admin-module-configuration.md) §5.4's instance providers read the *scoped*
admin endpoints even though `/builder/stores` was closer to hand, and why P3's usage endpoint
was registered in the authenticated group instead of beside its own controller's other routes.
Fixing the group belongs with [04](04-publish-and-serve.md), not 05 — but nothing new was added
to it.

### 1.11.2 The Form block's picker was fed by a side-table, not the forms API

> **CORRECTED 2026-09-01.** As first written this said the block *"offered only demo forms"* and
> that *"nothing in the builder ever called `addSurveyFormModel()` or
> `refreshSurveyFormOptions()`"*. **That was wrong**, and wrong because of how it was checked:
> the search was for those two function names, and the one actually used is
> `createSurveyFormModel()`. `builder/main.ts`'s `registerFormModels()` called it for every
> forms row at editor boot, so real forms *did* reach the dropdown.
>
> Found when a tenant reported the console errors that this section's real finding produces.

`components.ts` gave the `module-form` block a `select` trait over `surveyFormOptions`. That
list held the hardcoded samples from `surveyModels.ts` **plus** whatever `registerFormModels()`
had loaded — and what it loaded is the finding:

```ts
for (const row of formRows) {              // laravel_builder_output, typename = 'forms'
    fetch(`/portal/api/v1/forms/${row.typeid}`)
```

**The picker's contents came from `laravel_builder_output`, a side-table maintained separately
from `laravel_forms`.** A tenant's form appeared only if something had written a row for it, and
a row that outlived its form was fetched at every editor load forever.

> **B21 — the Forms block listed forms from a side-table rather than from the forms API.** A
> form with no `builder_output` row was unselectable; a row whose form was gone produced a 404
> on every editor load. Both are invisible from the dropdown, which shows a plausible list
> either way.

That second half is not hypothetical here: `laravel_builder_output` holds exactly one row —
`vuls3jcsaya8`, *"Become a Board Member"* — and no such form exists (§1.11.5).

### 1.11.3 A tier gate that could never fire

`forms.module.ts` declared `availability.tier: 'forms_count'`. The allocation registry
(`checkpoint_registry_v4.json`) and `resources/js/checkpoint/resources.ts` both call it
`domain_forms_count`; `forms_count` appears in neither.

`paletteState()` **fails open on an unknown tier by design** — a broken lookup must not empty a
tenant's palette — so the gate resolved to "available" every time and nothing looked wrong.

> **B22 — a module's tier gate named a resource that does not exist, and failed open in
> silence.** Exactly the failure [02](02-module-registry.md) §2.4's *check 4* predicted and
> recorded as out of reach.

It is no longer out of reach: `resources.ts` is in this repo and is what every other allocation
caller in the portal already uses. `tests/check-modules.mjs` now refuses an unknown id, and its
first run caught this one.

### 1.11.4 `availability.requires` has no backing store

[02](02-module-registry.md) §2.5's first gate is *"installed and enabled on this tenant"*.
Nothing records that. `subdomain_properties` holds domains, certificates and usernames;
`checkpoint_feature_flags` holds one row (`afs_connect`); there is no per-tenant module table.

> **B23 — the installed/enabled state `availability.requires` reads does not exist anywhere.**
> The check is written, tested and unfed.

05 §5.3's Modules index therefore treats every module as installed and lets the *tier* gate —
which is backed by real data — do the work. Stated in `modulesIndex.ts` where someone will hit
it, because the alternative reading (treat everything as not installed) empties the page whose
whole purpose is to list them.

### 1.11.5 A live page already references a form that does not exist

The active `/form` page names form `vuls3jcsaya8`. That reference appears in
`laravel_analytics_events` and one `laravel_mail_handlers` row — and in **neither**
`laravel_forms` nor the legacy `forms` table.

> **B24 — the failure §5.5 describes is not hypothetical; it has already happened on this
> deployment.** A published page is serving a block bound to an instance that is gone, and
> nothing has ever said so.

Found by the P0 backfill, which is the first thing that ever asked the question.

**The mechanism became visible on 2026-09-01.** The legacy `forms` table holds
`form808965595437`, named *"Become a Board Member"* — the same form, under the pre-migration
reference scheme. `laravel_builder_output` and the published page both name `vuls3jcsaya8`.

So the form was migrated to a new reference, the builder side-table and the published markup
recorded it, and **the row in `laravel_forms` was never created** (or was later removed). Three
places agree on a reference that the authoritative table has never had.

That is why the builder logs two 404s on every load of that project: one from the block
resolving its form, one from the side-table prefetch (**B21**).

## 1.12 What executing 07 turned up (2026-09-01)

Three findings. One is a repeat of **B20** in three more modules, one is a module that does not
exist, and one was caught by a build gate this plan added two steps earlier.

### 1.12.1 B25 — the unauthenticated-CRUD pattern is not builder-specific

Looking for endpoints to write runtimes against found **B20**'s shape again. Verified
unauthenticated against the running local site — reads only:

```
GET /portal/api/v1/subscribers            → 200
GET /portal/api/v1/subscribers/categories → 200
GET /portal/api/v1/listings               → 200
GET /portal/api/v1/feedback/forms         → 200
```

The same route groups declare, with the same middleware — which is to say none:

```
DELETE /portal/api/v1/listings/{listing_id}
DELETE /portal/api/v1/listings/{listing_id}/items/{item_id}
DELETE /portal/api/v1/subscribers/{subscriber_id}
```

> **B25 — subscribers, listings and feedback expose full CRUD, including deletes, without
> authentication.** With **B20** that is four module groups, which makes it a pattern in how
> route groups are written rather than one oversight.

**The fix is not "authenticate the group."** Two of these groups contain genuinely public
endpoints a published page needs — `POST /subscribers/subscribe` and `POST /feedback/submit`.
The separation ecommerce, events and appointments already have, with a `storefront` prefix for
the public half, is what these need. That is not 07's work, and 07's runtimes call only the
endpoints meant to be public.

### 1.12.2 B26 — discussions is not a module

[07](07-module-handoffs.md) §7.5.7 groups subscribers, listings, discussions and feedback as
sharing one shape — *"a `builder_output` row, an admin screen, a palette block, and no
runtime"* — and asks for a runtime for each.

```
routes matching /discussion/   : NONE
app/Http/Controllers/*discuss* : none
app/Models/*discuss*           : none
```

> **B26 — the discussions module has a builder block, an admin nav entry and an admin view id,
> and no backend at all.** There is nothing for a runtime to call and nothing an
> `instance-select` could list.

It is the only module in the estate where `noRuntime()` is honest, and its declared reason says
what it actually is: the block inserts content the tenant composed in the admin. Declaring it is
what makes the gap visible in one place instead of absent from everywhere.

### 1.12.3 B27 — the allocation resource list had drifted

Declaring listings, feedback and discussions tripped the build gate added in
[05](05-admin-module-configuration.md) (§1.11.3's **B22** fix):

```
Module tier gates name unknown resources:
  - module "listings" gates on tier resource "domain_listings", which is not in
    AllocV2Resources — the gate would silently never fire
```

`domain_listings` **is** in `checkpoint_registry_v4.json`. It was missing from
`resources/js/checkpoint/resources.ts`, the estate's own transcription of that file. Diffing the
two found five:

| Missing from `resources.ts` |
|---|
| `domain_listings` |
| `domain_feedback` |
| `domain_discussions` |
| `domain_chat` |
| `domain_sponsorships` |

> **B27 — `resources.ts` is a hand-maintained copy of a JSON document in another repo, and
> nothing checks it in that direction.** Nothing was in `resources.ts` and missing from the
> registry, so the drift is one-way: new resources are added to the registry and not
> transcribed.

The five are now present. The gate that found them compares modules against `resources.ts`; it
still cannot see this class of drift, and doing so needs the registry file, which lives in the
auth repo.

## 1.13 Two more, from a tenant clicking a link (2026-09-01)

Reported from the running builder: *"there's a configure link that opens a dead link"*. Both
findings below came out of that one click, and neither was reachable from reading the code.

### 1.13.1 B29 — the link this plan added went nowhere, and cost the tenant their work

[05](05-admin-module-configuration.md) P2's `ModuleInstanceProvider.adminUrl()` returns
`/portal/admin1?view=forms:edit&reference_num=…`, and its own docblock said the URL was real:

> It exists so the link is real: a middle-click, a copy-link, or a bookmark has to arrive
> somewhere, and a `href="#"` that only works when clicked is the kind of half-thing this plan
> keeps finding.

**Nothing in the admin SPA read that query.** `main.ts` parses no query string at boot, so the
app loaded and rendered its default view. Every "Configure" and "Create one" link in the
builder's trait panel arrived at the dashboard.

The second half is worse than the first. The anchor was `target="_top"`, and the builder is a
*view inside the same single-page document* — so the click navigated the whole document and
**discarded the tenant's unsaved page** on its way to the wrong screen.

> **B29 — a link the plan introduced was documented as working, was not, and destroyed unsaved
> work when used.** The docblock asserting it was real is what stopped anyone checking.

The Modules index had the same class of defect from a different direction: it derived its card
destination from `adminUrl('')`, which names an instance *detail* view, so the Ecommerce card
opened a store dashboard for no store. Its buttons were also `href="#"`, so middle-click did
nothing.

### 1.13.2 B28 — the entire portal admin was returning 500

Verifying the deep link meant requesting `/portal/admin1`, which answered **HTTP 500** — with
no query, unauthenticated, every time:

```
local.ERROR: Undefined variable $afssdk
  (View: resources/views/portal/admin/index.blade.php)
```

`portal/admin/index.blade.php` and `portal/admin/login.blade.php` both interpolate `$afssdk`
into an SDK `<script src>`, and neither route passed it.

> **B28 — `/portal/admin1` returned 500 for every request.** The builder, the Modules index and
> every module admin screen are views inside that document.

**This is B8a exactly**, one document further along. [04](04-publish-and-serve.md) P4 found the
same undefined `$afssdk` taking down every *published* page and fixed it in
`resources/views/index.blade.php`. The admin views have the same bug and were untouched by that
fix, because nothing connected the two.

Introduced by commit `b717c5664`, not by this plan. Found by it, twice, in the same variable.

> **The lesson is about the check, not the bug.** 04's completion document recorded B8a as
> fixed and verified `/form` and `/` returning 200. Neither this plan nor that one ever
> requested an *admin* URL — so a Critical defect sat one route away from a page that was
> tested, in the same variable, for as long as it took a tenant to click something.

## 1.14 The router, which was never started (2026-09-01)

**B29's fix was wrong**, and the tenant reported it a second time: the "Configure" link still
went to the dashboard. Analysing `_sources/src/afslibs/afs/appfactory3.ts` — the estate's own
view library — found that the admin has a **hash router** which the link was not using, and two
defects in the router underneath it.

### 1.14.1 The URL shape was invented, not adopted

`ViewSubViewRouter` is hash-based. Every view declares a `route`, and `handleRoute()` splits the
hash on `?` and hands the parsed query to the view as its `params`:

```
/portal/admin1#/forms/edit?reference_num=vuls3jcsaya8
                └ route ─┘ └──── the view's params ────┘
```

[05](05-admin-module-configuration.md)'s `adminUrl()` instead produced
`?view=forms:edit&reference_num=…` on the **document** query — a convention that exists nowhere
in the library. B29's first fix then added a parser for that invented convention, which made two
routers where there had been one, and left the real one still unstarted.

The providers now emit the library's own routes, read from the `newSubView({ route: … })`
declarations, and `adminLinks.test.ts` parses those declarations rather than keeping a copy — a
hand-maintained list is how the invented shape passed review in the first place: it agreed with
itself.

### 1.14.2 B30 — `startRouter()` was never called

The library's own docblock says:

> Call `view.startRouter()` after all `newSubView` registrations so the current URL is resolved.

Nothing in the portal did. `ViewSubViewRouter`'s constructor attaches a `hashchange` listener, so
changing the hash *after* load worked — but the hash a page **arrived with** was never resolved,
and the app fell through to its init view.

> **B30 — every deep link into the admin has been ignored on arrival.** Not only the builder's:
> any bookmarked or shared `#/…` URL for any of the ~40 routed views.

That is the whole of the reported symptom, and it predates this plan.

### 1.14.3 B31 — `:param` routes could never match

`addRoute()` stored a route under `routeKey(path)`, an exact string, and `handleRoute()` looked
it up the same way. So `ecommerce:store-dashboard`, declared as
`/ecommerce/store/:store_reference_num`, was stored under that literal text and no real URL ever
equalled it.

`_update_url()` had the mirror-image defect: it wrote the route **template** into the address
bar, so rendering a store dashboard left the URL reading `#/ecommerce/store/:store_reference_num`
— a URL naming no store, which reloads to a dashboard for the parameter's own name.

> **B31 — the one view in the admin that declares a path parameter has been unreachable by URL
> since it was written, and rendering it corrupted the address bar.** Both halves fail silently:
> an unmatched route falls back to the init view, which looks exactly like "the link went to the
> dashboard".

Both are fixed in the library: patterns are matched after an exact key misses, and
`_fill_route_params()` substitutes values before the URL is written.

> ### Why none of this was caught by a test
>
> `ViewComponent` requires jQuery and a global `ApplicationContextManager`, and constructing that
> manager under Node starts timers that never settle — so the router cannot be driven from the
> test suite without either hanging it or reimplementing the class, and a test of a copy protects
> nothing. What `adminLinks.test.ts` asserts instead is that the three fixes are still present in
> the source, because each defect was invisible at runtime: **the link just went to the
> dashboard**, three different times, for three different reasons.
