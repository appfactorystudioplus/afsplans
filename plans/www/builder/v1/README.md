# builder v1 — Planning Index (LEGACY)

> # ⛔ LEGACY — do not reference this folder
>
> **Superseded by [`../v2/`](../v2/README.md) (2026-09-01).** v2 rebuilt the builder around
> page-level modules; the block-module model this folder plans no longer describes the
> codebase.
>
> - **Do not link to any file in `v1/` from a new or updated plan.** Cite
>   [`../v2/`](../v2/README.md) instead, or state the point without a citation where v2 has no
>   equivalent section.
> - **Do not implement from these documents.** The numbered files describe intent that was
>   either superseded or landed differently.
> - [`complete/`](complete/) is the **historical record of what was actually built** in v1 —
>   the compile loop, the instance providers and the reverse index that v2 still publishes
>   through. Read it as history, and cite v2 for how those work today.
>
> Its own internal links are stale by one directory level — these documents were written at
> `www/builder/` before v2 moved them into `v1/`, and they are **not** being repaired: a
> legacy folder nobody should cite does not need working cross-references.
>
> Everything below is preserved unchanged, as the record of what v1 intended.


> **STATUS: STUB, FOR AUDIT.** Nothing in this folder is built. Written from reading
> `www/resources/js/portal/builder/`, `routes/web.php`, `routes/portal.php`,
> `BuilderPublishedController`, `components.ts` and `imp.ts` directly.
>
> **Re-audited 2026-08-31.** Ecommerce, Events and Site Users each shipped a working builder
> surface after this plan was drafted — independently, in three separate files, each
> reinventing the same pattern. Findings **B15**–**B19** and
> [01](01-current-state-audit.md) §1.9 are that re-audit.

The GrapesJS page builder in `www/resources/js/portal/builder/`, and the publish→serve loop
behind it. A tenant composes a page, drops in modules, configures them, publishes; a visitor
hitting the slug is served the stored `html`, `css` and `js`.

> ### 🔴 The finding that this plan exists for
>
> **Six of ten modules cannot be dragged onto a page and made to work.** Direct Payments,
> Subscribers, Listings, Discussions and Feedback render markup and **do nothing** on a
> published page — no error, no warning, the block is present, styled and inert. Appointments
> has no block at all: nine models, fourteen completion documents, and the only way onto a
> site is to make the entire page an appointments page.
>
> Forms, Ecommerce, Events and Site Users work. Each arrived by a different route.
>
> The `js` column that exists to carry page behaviour is hardcoded:
>
> ```ts
> js: '',       // Reserved for future page-level JS support
> ```
>
> Forms work only because GrapesJS serialises a component's `script` into the **HTML** blob,
> which is also why the schema's "separate columns so they can be queried, sized and diffed"
> rationale is not real for JS.
>
> See [01](01-current-state-audit.md) **B1**–**B3**.

## The builder is the `www/` programme's integration point

Fifty documents across five module plans describe integrating with it:

| Plan | Docs referencing the builder |
|---|---|
| [forms](../forms/README.md) | 16 |
| [site_users](../site_users/README.md) | 10 — including [12-builder-integration.md](../site_users/12-builder-integration.md), 32 KB about nothing else |
| [events](../events/README.md) | 9 |
| [ecommerce](../ecommerce/README.md) | 8 |
| [appointments](../appointments/README.md) | 7 |

Until this folder the builder had no plan of its own, so all fifty were written against an
unspecified surface. [02](02-module-registry.md) is that surface.

## Documents

| # | Document | Contents | State |
|---|---|---|---|
| 00 | [00-overview.md](00-overview.md) | What it is, ten goals, non-goals, the ten decisions, and what "robust" is being held to mean | **written** |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | The loop as built, findings **B1**–**B19**, §1.8 — what is good and is not being rewritten — and §1.9, the 2026-08-31 re-audit | **written** |
| 02 | [02-module-registry.md](02-module-registry.md) | **One module, one file.** Required `runtime`, traits, availability, versioning, editor preview, the consistency test that turns **B1** into a build failure, and §2.9's reconciliation with the three surfaces that shipped | ✅ **complete** — P0–P8; **P8 landed with 05 P3**, which is what its gate was waiting for |
| 03 | [03-editor-architecture.md](03-editor-architecture.md) | **Giving `initEditor()` seams.** The measured map of its 2,190 lines, the three properties that make it hard to change, the context/phase shape, and a behaviour-preserving order of phases. **Prerequisite for 02 and 04** | ✅ **complete** — all nine phases; `initEditor()` 2,242 → 19 lines |
| 04 | [04-publish-and-serve.md](04-publish-and-serve.md) | **Publish as a compile step.** Generating the runtime into the `js` column, nested paths, CSP and sanitisation, and the activation flip | ✅ **complete** — all seven phases; **B8 withdrawn**, B8a found and fixed |
| 05 | [05-admin-module-configuration.md](05-admin-module-configuration.md) | **The other half of configuring a module.** Instances and the reference that binds them to a block, the Modules index, the reverse index that stops a delete breaking a live page, `instance-select`, and Direct Payments specifically | ✅ **complete** — all seven phases; **B20–B24** found, B20 Critical |
| 06 | 06-editor-ux-and-styling.md | The token system, panel and canvas design, empty and error states, and the tenant-facing error surface (**B13**) | *to write* |
| 07 | [07-module-handoffs.md](07-module-handoffs.md) | **Every module, draggable and configurable.** Where all ten stand, the block/page-type rule (**B15**), per-module handoffs, and the order of work | ✅ **complete** — all nine phases; **ten of ten modules declared**, B17 and B18 closed, **B25–B27** found |
| 08 | 08-rollout-and-testing.md | Phases, the test suite that does not exist today, and the ordering constraint in [04](04-publish-and-serve.md) §4.3.2 | *to write* |

**03 is written and lands first.** 02 and 04 both describe things that have to be wired into
`initEditor()`, and doing that inside a 2,190-line function is how this state was arrived at.
[07](07-module-handoffs.md) §7.6 is the constrained order across all of them.

> **05 was added and the old 05 became 08.** Admin module configuration had no document, and
> [01](01-current-state-audit.md) §1.9.6 is why it needs one: a block's traits ask for a
> reference to an instance, and for Site Users there is no screen in the product that creates
> or lists those instances. A configurable block whose reference names nothing is inert for a
> second, quieter reason than **B1**.

## Findings, ranked

| # | Finding | Severity |
|---|---|---|
| **B1** | Four of six types in `components.ts` have no `script` — configured, published, inert | **Critical** |
| **B2** | The `js` column is never written | **Critical** |
| **B3** | Module JS ships inside the `html` blob instead | High |
| **B4** | `initEditor()` is 2,242 lines with no seam | High |
| **B5** | No tests, anywhere | High |
| **B6** | A module means hand-editing three unlinked files | High |
| **B7** | Fifty documents assume a contract that is undeclared | High |
| **B15** | Three mechanisms put a module on a page; their relationship is undefined | **High** |
| **B16** | The registry pattern is implemented three times, unshared | **High** |
| **B17** | Appointments is fully built and has no block — whole-page binding only | **High** |
| **B18** | Inert `module-commerce` ships beside seven working Commerce blocks | **High** |
| **B19** | Site Users has module admin routes and no admin UI to reach them | **High** |
| **B8** | Published pages cannot be nested — `/about` works, `/about/team` 404s | Medium |
| **B9** | Raw `<style>`/`<script>` injection, no CSP | Medium → High on multi-editor tenants |
| **B10** | The `is_active` flip has no concurrency or rollback contract | Medium |
| **B13** | No error surface for the tenant | Medium |
| **B14** | Component defaults are versionless | Medium |
| **B11** | 3.1 MB of unimported vendored duplicates beside an npm dependency | Low |
| **B12** | Template markup lives in TypeScript source | Low |

**B15**–**B19** are from the 2026-08-31 re-audit. Four of the five are consequences of module
surfaces landing without a builder contract to land against — **B7**, now with worked examples
rather than a prediction.

## This is extraction and contracts, not a rewrite

[01](01-current-state-audit.md) §1.8 lists what already works and is not being replaced: the
block/type/trait model (`module-form` proves the whole path end to end), publish history and
republish, i18n, the AI chat surface, and `pageManager`'s separation of pages from the editor.

The plan adds a contract where there is none, extracts seams so the contract can be enforced,
and compiles what the browser receives so it can be trusted.

## Code documentation standard

Document all new and changed code thoroughly with block comments — JSDoc for TypeScript,
PHPDoc for PHP. Every module file, exported symbol, generated artifact and non-obvious trait
must explain purpose, parameters, side effects and plan references.

New and changed code carries a builder marker followed by the plan reference, e.g.
`AFS_BUILDER_MODIFIER_ADDITION — plans/www/builder/02-module-registry.md §2.2`. Register it in
[../../markers.md](../../markers.md) before first use — it is not there yet.

**Markers coexist — never replace one.** Most files this plan touches already carry a marker
from an earlier effort. Add alongside; do not reword the comment it belongs to.

## Completion documents

Each numbered document gets a completion document in [`complete/`](complete/) when its work
lands — source basename plus `.complete.md`, with status, summary, files changed, verification
steps and follow-up work.

**One record per document, however many steps its work takes.** A document whose work lands
over several steps keeps one record and grows it, with a status table saying which steps are
done. That is the convention every other plan in `plans/www/` follows.
