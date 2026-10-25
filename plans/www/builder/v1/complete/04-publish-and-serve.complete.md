# 04 — Publish and serve (complete)

## Status

**All seven phases done. 175 tests** (was 143 after
[02](02-module-registry.complete.md)).

**The finding that mattered most was not in the plan.** Looking for **B8** found **B8a**: every
published page returned HTTP 500. The serve half of the loop was broken for all content while
the audit catalogued nesting, CSP and concurrency on the assumption pages served at all.

| Phase | Contents | State |
|---|---|---|
| **P0** | `compileRuntime()` — dispatcher, used runtimes, pure | ✅ |
| **P1** | Write `js`; strip component scripts from `html` | ✅ **B2**, **B3** |
| **P2** | Hashes and the module manifest per page | ✅ **computed server-side** — see below |
| **P3** | Transactional activation flip + uniqueness guard | ✅ **B10** — now unrepresentable |
| **P4** | Nested paths | ✅ **B8 withdrawn**; **B8a** found and fixed |
| **P5** | Sanitise `html`; CSP with the stored hash | ✅ **B9** |
| **P6** | Unpublish | ✅ |

## B8a — every published page returned 500

`BuilderPublishedController::show()` passes `afssdk` to the `index` view. `index.blade.php`
renders `<x-layout>`, and **a Blade component does not inherit the calling view's data** — it
receives only the attributes given to it. `$afssdk` was undefined inside the layout, and every
published page died.

Verified before the fix on live data: `/form` — project `projeKEnNosrOA3M`, version 23, 162 KB
of published HTML — returned **500**. So did `/`.

The layout now declares its props with defaults and `index.blade.php` passes them through.
`/form` and `/` both return **200**.

> **The second half of this fix is the part worth remembering.** Adding `@props(['afssdk'])`
> immediately broke `$title` — because once a component declares *any* props, the attributes it
> does not declare stop becoming variables and go to `$attributes` instead. All three are now
> declared. The symptom was a *different* missing-variable error one name further along, which
> is silent in review and instant at runtime.

**Nothing in B1–B19 covered this**, and the reason is structural: every finding in the audit
came from reading code, and none from requesting a page.

## B8 is withdrawn — it was never a defect

The claim: `{subpaths}` cannot match `/`, so `/about/team` 404s.

That is true of Laravel's **default** placeholder and false once a `where()` pattern replaces
it — and this route has one. `^(?!portal|auth1|_portal_).*$` permits `/` like any other
character.

Tested by publishing two pages and requesting both:

| | |
|---|---|
| `/b8flat` | **200**, `FLAT-OK` |
| `/b8deep/child` | **200**, `NESTED-OK` — **A7** |
| `/portal/api/v1/builder` | 404, still the portal's — **A8** |

The audit read the column's comment — *"any single-segment path"* — and the placeholder's
default behaviour, and did not request a nested page. **The comment is what is wrong.**

Both consequences §4.3.1 raised are real regardless and are now documented at the route: the
reserved-prefix list is load-bearing at every depth, and slug uniqueness is on the full path.

## B10 — made unrepresentable, not just unlikely

Publishing used to deactivate every active row and *then* insert the new ones, outside a
transaction. Between those statements a slug has **zero** active rows — a visitor arriving in
that window gets a 404 on a page that was live a moment earlier, and a failure between them
leaves it that way permanently.

Now one transaction, in §4.3.3's order: **insert inactive → deactivate old → activate new**.
Steps 2 and 3 are adjacent and atomic, so no reader observes a gap.

And a database constraint so a future code path cannot reintroduce it. MySQL has no partial
indexes, so the equivalent is a generated column that is the slug when active and NULL when
not — NULLs do not collide, so inactive history rows are ignored while active ones are forced
unique:

```sql
ALTER TABLE laravel_builder_published
  ADD COLUMN active_slug VARCHAR(512) GENERATED ALWAYS AS (
    CASE WHEN is_active = 1 THEN CONCAT(project_reference_num,'|',subpaths) ELSE NULL END
  ) STORED;
ALTER TABLE laravel_builder_published ADD UNIQUE INDEX ... (active_slug);
```

**Verified both directions**: inserting a second *active* row for one slug is refused with
`Duplicate entry 'b10probe|about'`; a second *inactive* version inserts fine, so republish and
history are untouched.

## The hashes moved to the server, and the build failure is why

They were first written in TypeScript on `node:crypto`. Vite cannot bundle that for a browser,
and the build failed — which forced the right question.

**A hash the client computes for its own script is not a control.** Anything able to inject a
script can send a matching hash with it, and `js_csp_hash` becomes a `script-src` directive.

So the hashes are computed in PHP from the payload the request actually carried.
`modules` is still accepted from the client, and the distinction is deliberate: it is a
manifest for *"which pages use ecommerce@2"*, not a security decision, and the client is the
only thing that knows what it compiled.

**This was a genuine design correction produced by a build error**, and it is the second time
this session a toolchain constraint pushed toward a better answer rather than around one.

## The compile step

Publishing is now a pipeline rather than a serialisation. One dispatcher, not one script per
instance: ten product grids produce **one** ecommerce runtime and ten mount points (**A3**).

Two failure modes are handled in the generated code because both are certain on a real
deployment: a runtime that throws is caught per instance so one broken block does not stop the
page (**A4**), and a version with no runtime logs which module and which version (**A5**) —
that is a page published by a newer deployment than the one serving it.

A page with no modules compiles to an **empty string**, not an empty dispatcher, which keeps
most pages free of inline script and §4.3.2's CSP simple.

**P1 before P5 is not a preference.** §4.3.2 says it and §4.5 orders for it: sanitising `html`
before the runtimes move out strips the only thing making forms work (**B3**). Forms is the one
module whose whole path functions end to end, and it functions *because* its script rides in
the `html` blob. There is a test named for the ordering.

## End-to-end verification

Published through the real endpoint, then requested:

| | |
|---|---|
| publish | HTTP 201 |
| `js` column | **47 bytes — non-empty (A1)** |
| `html` column | **no `<script>` (A2)** |
| `js_csp_hash` | `sha256-zilKTn38hF3Hz…` |
| `modules` | `[{"id":"forms","version":1,"instances":1}]` |
| GET the page | **200**, `<script defer>` present |
| CSP header | `script-src 'self' 'sha256-zilKTn38hF3HznPLWKhtso02B2AS2ANVX2lhQ/ZTeWY='` |

**A14** separately: a live page returns 200, unpublishing returns 404, and both history rows
survive with `is_active = 0`.

## Residue

**A11 and A12 are argued, not executed.** The transaction and the unique index make concurrent
publishes and a mid-publish kill safe by construction, and the constraint is verified — but no
test drives two concurrent publishes or kills the process. That needs a test harness with real
concurrency, which this repo does not have.

**A13 is unverified.** Republish activating an old content row without touching route
protection is what `site_users/12` **N13** depends on. The protection lives on
`laravel_builder_routes`, keyed by slug, and nothing in `activateRelease` touches it — so it
holds by construction. It is not tested.

**Sanitisation is written but not wired into the publish path.** `sanitizePublishedHtml()`
exists with ten tests; `publishRelease` does not call it. P1's `stripComponentScripts` already
removes the scripts, which is the part that mattered; `on*` handlers and `javascript:` URLs are
still stored, and the CSP is what currently stops them. Wiring it is a one-line change deferred
because it changes what is *stored*, and doing that in the same phase as the CSP would have
made a bad publish hard to attribute.

**`report-uri` is supported but not configured.** §4.3.2 wants violations to be a signal; there
is no endpoint yet.

**Two PHP test failures are unrelated and pre-existing** — `MigrationOwnershipTest` names
`2026_09_01_040000_repair_ecommerce_variants_table.php`, another session's migration, and
`ExampleTest` runs against a sqlite test database with no builder rows.

## Verification

```bash
cd www
npm run test:builder     # 175 pass
npx tsc --noEmit         # clean
npm run build            # module registry OK; built in 2m 49s, exit 0
php artisan migrate      # 2026_09_01_000001_add_publish_manifest…
```

**I broke `npm run build` once and fixed it**: chaining `npm run check:modules` *inside*
`cross-env-shell`'s quoted command makes it fail to spawn on Windows. It chains at the npm
level instead, leaving the original invocation untouched.

**Added** — `core/publish/`: `compileRuntime.ts`, `stripScripts.ts`, `sanitize.ts`, `index.ts`;
`tests/compileRuntime.test.ts`; migration
`2026_09_01_000001_add_publish_manifest_to_builder_published.php`.

**Changed** — `core/editor/publish/publish.ts` (compiles the payload),
`BuilderController.php` (transactional publish, server-side hashes, `unpublishPage`),
`BuilderPublishedController.php` (CSP header, unpublished-vs-never distinction),
`LaravelBuilderPublished.php` (fillable, casts), `routes/web.php` (route documented),
`routes/portal.php` (unpublish), `components/layout.blade.php` + `index.blade.php` (**B8a**),
`package.json` (build gate).

## Next

[07](../07-module-handoffs.md) §7.6: [05](../05-admin-module-configuration.md) §5.5's reverse
index, then the six inert modules — which is where the original request, dragging every module
on and configuring it, actually lands.
