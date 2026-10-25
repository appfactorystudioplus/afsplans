# 00 — Overview

> **STUB, FOR AUDIT.** No application code has been written.

## 0.1 What the builder is

`www/resources/js/portal/builder/` — 113 files, 39,439 lines, GrapesJS at its centre. A tenant
composes pages on a canvas, drops in **modules** (forms, payments, subscribers, ecommerce,
events, appointments), configures each through traits, and publishes. Publishing writes
`html`, `css` and `js` per page into `laravel_builder_published`; a visitor hitting a slug is
served those three columns by `BuilderPublishedController`.

**It is the integration point for the entire `www/` module programme.** Fifty documents across
five module plans — appointments, ecommerce, events, forms, site_users — describe integrating
with it, including `site_users/12`, a 32 KB document about nothing else. Until this folder,
the builder had no plan of its own, so all fifty were written against an unspecified surface.

## 0.2 The problem, in one line

**A module can be configured in the builder, published, and do nothing on the live page — and
nothing anywhere reports it.**

Four of six module component types have no `script` ([01](01-current-state-audit.md) **B1**).
They render markup and are inert. The `js` column that exists to carry page behaviour is
hardcoded to `''` (**B2**). Forms work only because GrapesJS smuggles their script inside the
HTML blob (**B3**).

That is the gap between what this builder is for and what it does, and closing it is what this
plan is.

## 0.3 Goals

| # | Goal | Where |
|---|---|---|
| **G1** | A module cannot be registered without declaring how it behaves at runtime | [02](02-module-registry.md) §2.2.1 |
| **G2** | Adding a module is one file and one line, not three hand-edited files | [02](02-module-registry.md) §2.3 |
| **G3** | Publishing generates the runtime and writes it to the `js` column | [04](04-publish-and-serve.md) §4.2 |
| **G4** | A tenant never sees a block they cannot use, and always sees why | [02](02-module-registry.md) §2.5 |
| **G5** | A page published today still works after the module changes | [02](02-module-registry.md) §2.6 |
| **G6** | The editor is composed of testable phases, not one 2,190-line function | [03](03-editor-architecture.md) |
| **G7** | Published output cannot execute script the compiler did not generate | [04](04-publish-and-serve.md) §4.3.2 |
| **G8** | The builder looks like a product a tenant is glad to spend an afternoon in | [06](06-editor-ux-and-styling.md) |
| **G9** | A tenant whose module misconfigures is told, in the editor | [06](06-editor-ux-and-styling.md) |
| **G10** | Nested page paths work | [04](04-publish-and-serve.md) §4.3.1 |

## 0.4 Non-goals

- **Not a rewrite.** GrapesJS stays; the block/type/trait model stays; publish history stays.
  [01](01-current-state-audit.md) §1.8 lists what is genuinely good, and none of it is
  replaced. This plan is **extraction and contracts**.
- **Not a new page-serving architecture.** `BuilderPublishedController` and the three-column
  schema are the right shape. §4 fixes what is injected, not how.
- **Not the modules themselves.** Each module's own plan owns its features. This folder owns
  the surface they plug into.
- **Not the AI chat surface.** `core/ai_chat/` is untouched here beyond being moved intact
  when [03](03-editor-architecture.md) splits `imp.ts`.
- **Not the tenant-side runtime SDK.** `window.Portal.*` is the externals SDK, owned
  elsewhere; this plan consumes it.

## 0.5 The decisions

| # | Decision | Why |
|---|---|---|
| **D1** | **`runtime` is a required field on every module** | **B1** is four separate instances of one omission. A required field turns it into a compile error, and `noRuntime('reason')` makes "presentational" a decision someone wrote down rather than a thing that was forgotten |
| **D2** | **One module = one file, generated into blocks, types and defaults** | Three hand-maintained files with nothing linking them is how a block reaches the palette with no type behind it. The same problem afs-connect solved with a source registry, solved the same way |
| **D3** | **Publish compiles the runtime; it does not serialise the canvas** | The `js` column exists and is empty; module JS hides in the HTML blob. Compiling means the columns mean what the schema says, and CSP becomes affordable because generated script is hashable |
| **D4** | **Every published instance carries its module version** | Otherwise a defaults change silently reinterprets every page already live — a change to published sites that nobody published |
| **D5** | **Editor preview and published runtime are different code** | Both current scripts detect editor mode and bail, so the tenant designs against an empty box. Preview renders fixtures; runtime talks to the API; neither pretends to be the other |
| **D6** | **Tier gating in the palette is advisory** | [../../allocation.md](../../allocation.md) §7: `alloc_v2` is not a security boundary. A disabled block is a better upgrade prompt than a support ticket, and the module's own endpoints stay responsible for refusing |
| **D7** | **`initEditor()` is split by extraction, behaviour-preserved** | 2,190 lines with no seam is the reason nothing here is testable. Splitting it is the prerequisite for D1–D3, not a tidy-up to do afterwards |
| **D8** | **Styling is a token system, not a theme file** | **G8**. A builder is judged on whether it feels precise. That comes from consistent spacing, type and state, which is a token system — not from restyling panels one at a time |
| **D9** | **Existing module admin screens are reached, not replaced** | Eight of them exist and are good; Direct Payments alone is 1,280 lines covering tiers, connect accounts, subscriptions and notifications. What is missing is an index that presents them as modules and connects them to the builder — not a rewrite ([05](05-admin-module-configuration.md) §5.3) |
| **D10** | **A block that resolves to no instance fails in the editor, and blocks the publish** | The other way to be inert: the `runtime` exists, the reference names nothing, and the page renders and does nothing. Refusing the publish is strong, and it is the only place the tenant can still fix it ([07](07-module-handoffs.md) §7.4.3) |

D9 and D10 were added by the 2026-08-31 re-audit. Both concern the half of "configure a module"
that happens outside the builder, which the first eight decisions did not model.

## 0.6 What "robust" means here

The word is doing real work, so it is worth pinning to five testable properties:

1. **A module that does not work cannot be published** — because it cannot be registered
   (D1) and the consistency test fails the build ([02](02-module-registry.md) §2.4).
2. **A failure is contained and visible** — one module throwing does not stop the page
   ([04](04-publish-and-serve.md) §4.2), and the tenant is told in the editor (**G9**).
3. **A published page is stable** — it keeps working when the module changes (D4), and a
   republish restores content without touching protection.
4. **The editor is changeable** — a panel, a block or a publish step can be modified without
   editing a 2,190-line function, and there is a test that says whether it still works.
5. **The output is trustworthy** — the browser executes what the compiler produced and
   nothing else (**G7**).

## 0.7 Reading order

[01](01-current-state-audit.md) is evidence and can be read alone — start at §1.9 if you have
read it before. [02](02-module-registry.md) and [04](04-publish-and-serve.md) are the two
halves of the loop and are the substance of this plan. [03](03-editor-architecture.md) is what
makes both of them possible, and [06](06-editor-ux-and-styling.md) is what makes the result
worth using.

**To answer "can a tenant drag every module on and configure it?" read
[07](07-module-handoffs.md)** — it holds the per-module matrix, the block/page-type rule, and
the order of work across every other document. [05](05-admin-module-configuration.md) is its
prerequisite: a block is configured half in the builder and half in module admin, and only the
builder half was planned before 2026-08-31.
