# 15 — Developer scaffolding and local development

## Completion document

When this plan step is completed, write
`complete/15-developer-scaffolding-and-local-development.complete.md`.

---

## 1. The problem this fills

Every other document in this plan describes either the **shape** a plugin must arrive in
([04](04-manifest-and-package-format.md)) or what happens to it **after** a developer submits it
([10](10-review-and-publishing-pipeline.md)). Nothing so far describes how a developer actually
*writes* a plugin — day to day, on their own machine, iterating on a hook handler or a settings
panel — before there is anything worth submitting at all. Without this, "build a plugin" means
hand-assembling the exact directory layout in [04 §1](04-manifest-and-package-format.md) from
scratch, guessing at the capability-SDK method signatures from
[05 §4](05-security-and-sandboxing.md) with nothing to run them against, and finding out about a
rejected pattern only after a real submission round-trip through
[10](10-review-and-publishing-pipeline.md) — slow, and a poor first impression of the platform.

Laravel itself solves the equivalent problem with `laravel new` / `composer create-project`: one
command produces a working local project, with the framework's own conventions already in place,
runnable and testable before a single line of the developer's own code exists. This document is
that, for a plugin.

## 2. What the scaffold produces

A single command generates a local project matching
[04 §1](04-manifest-and-package-format.md)'s package layout exactly, plus development-only
additions that never ship:

```
my-plugin/
├── plugin.json                  # pre-filled skeleton: slug, empty capabilities/hooks/data_schema
├── backend/
│   ├── src/                     # namespaced Plugins\{Slug}\..., one sample class
│   └── hooks/                   # one example hook file (e.g. order_completed.php), stubbed
├── frontend/
│   ├── src/                     # DEV-ONLY — the developer's TS source
│   └── dist/                    # build output — what actually ships (04 §1)
├── assets/
│   ├── icon.png                 # placeholder
│   └── screenshots/
├── tests/                       # DEV-ONLY — the local harness, §4 below
│   ├── mock-sdk/                # local stand-in for the capability SDK (05 §4)
│   └── mock-host/               # local stand-in for the plugin-host contract (08 §5)
├── dev.fixtures.json            # DEV-ONLY — sample tenant data the mocks read from
├── CHANGELOG.md
└── (composer.json / package.json — DEV-ONLY tooling, §6)
```

Every directory marked DEV-ONLY is stripped by the packaging step (§5) and never appears in what
gets submitted — the submission zip is always exactly [04 §1](04-manifest-and-package-format.md)'s
layout, nothing more.

## 3. The mock capability SDK — developing against the real shape, not the real trust boundary

The core of "develop locally before integrating": a local, in-process implementation of the
**same method signatures** as [05 §4](05-security-and-sandboxing.md)'s capability table
(`$plugin->orders()->find(...)`, `$plugin->storage()->get()/put()`, `$plugin->http()->request(...)`,
and so on), backed by fixture data the developer supplies in `dev.fixtures.json` instead of a real
tenant's data.

- **The mock enforces the same scoping rules as the real SDK**, not a permissive stand-in — a
  `plugin_data.write` to a collection not declared in `plugin.json`'s `data_schema`
  ([04 §4](04-manifest-and-package-format.md)) fails locally exactly as it would in production, so
  a developer discovers a manifest mistake in their own terminal, not three days later in a
  rejection email from [10 §2](10-review-and-publishing-pipeline.md).
- **It is not, and must never be mistaken for, the real Plugin Runtime**
  ([05 §3](05-security-and-sandboxing.md)). It has no invocation token (05 §10), no sandbox, no
  resource limits — because it isn't a trust boundary, it's a development convenience running
  entirely on the developer's own machine with the developer's own fixture data. Nothing about
  passing every local mock check substitutes for, or shortcuts, the authoritative server-side
  checks in [10](10-review-and-publishing-pipeline.md) — stated explicitly so the local tool is
  never treated as a rubber stamp, by the developer or by anyone reviewing this plan later.
- Kept in lockstep with the real capability catalog the same discipline
  [08 §5](08-frontend-runtime-and-dynamic-loading.md) already applies to `plugin-host.d.ts`: when
  [05 §4](05-security-and-sandboxing.md)'s capability table changes, the mock SDK changes in the
  same pass, or a developer's local tests pass against a shape production no longer offers.

## 4. The mock Plugin Host and local frontend dev server

For a plugin with a `frontend` entry (04 §2): a local dev server renders the developer's
`frontend/src` build output inside a **real iframe with the same sandbox attributes and CSP** as
the production Plugin Host ([08 §3](08-frontend-runtime-and-dynamic-loading.md)) — not a relaxed
"trust everything" local mode. `postMessage` calls the frontend makes are relayed to the mock SDK
(§3), through the same `plugin-host.d.ts` contract shape
([08 §5](08-frontend-runtime-and-dynamic-loading.md)) the real Plugin Host uses.

This matters for the same reason the SDK mock matters: a plugin's frontend code that assumes
`window.top` access, or tries a `fetch` the real CSP would block, fails the moment the developer
runs it locally — not after it's built, submitted, and rejected by
[10 §3](10-review-and-publishing-pipeline.md)'s frontend static-analysis stage for exactly that
reason.

**For a [public site slot](06-extension-points-and-hooks.md) §2.1** (added for
[16](16-example-plugin-cookie-consent.md)): the local dev server also renders a static fixture
page standing in for a tenant's published website, with the `site.global` slot's iframe embedded
in it the same way it would be embedded in a real page — so a developer building a toast sees it
the way an actual site visitor would, not only the way an admin would see a settings panel. The
mock SDK for this mode only exposes whatever capabilities are marked
`reachable_from_public_site` ([05 §12](05-security-and-sandboxing.md)) — the same restriction the
real public endpoint enforces, not the fuller admin-side mock from §3.

## 5. Packaging: closing the loop back into the existing pipeline

One command strips every DEV-ONLY path (§2), builds `frontend/src` into `frontend/dist/main.js`
if a frontend entry exists, and produces exactly the zip [04 §1](04-manifest-and-package-format.md)
describes — ready for [10 §1](10-review-and-publishing-pipeline.md)'s upload stage. A developer
never hand-assembles a submission; the same tool that scaffolded the project also produces what
gets uploaded.

## 6. Local linting — mirrors the pipeline, doesn't replace it

A local check command runs the **advisory equivalent** of
[10 §3](10-review-and-publishing-pipeline.md)'s static analysis: the same forbidden-construct
categories (PHP: `eval`, `exec`-family, direct framework class references; TypeScript: `eval`,
`new Function`, out-of-sandbox API references), the manifest schema validation
([04 §2](04-manifest-and-package-format.md)), the declared-vs-used capability cross-check
(04 §3), and the `requires_parameters` check ([03 §1](03-database-schema.md)) for `http.egress`.

This is deliberately **advisory only** — passing it locally is a strong signal, not a guarantee,
because the authoritative version runs server-side regardless, against the actual submitted
artifact, by [10 §2–§3](10-review-and-publishing-pipeline.md), which this local tool does not have
the authority to skip or weaken. The two checks are kept in lockstep the same way the mock SDK is
(§3) — a new forbidden pattern added to the real pipeline is added to the local linter in the same
pass, so the local tool doesn't quietly fall behind what will actually be enforced.

## 7. What is dev-only tooling versus what the manifest governs

[04 §7](04-manifest-and-package-format.md) restricts what a plugin's **shipped** backend code may
depend on (a small allowlist of pure-logic libraries, no network/filesystem/process libraries).
That restriction governs `backend/src`'s own dependencies — it says nothing about, and does not
apply to, whatever the developer's local tooling uses to scaffold, bundle, or test the project
(a bundler for `frontend/src` → `frontend/dist`, a local test runner, the mock SDK/host
themselves). None of that tooling is present in, or relevant to, the submitted zip; §5's packaging
step is precisely the boundary that guarantees this — nothing under a DEV-ONLY path in §2 can leak
into what gets uploaded.

The scaffold's generated `frontend/dist/main.js` starter is a trivial placeholder the developer
replaces entirely — the scaffold has no way to guarantee whatever the developer eventually writes
stays within [08 §1](08-frontend-runtime-and-dynamic-loading.md)'s build constraints (single entry
point, size cap, no `eval`); that remains [10 §3](10-review-and-publishing-pipeline.md)'s job at
submission time. §6's local lint narrows the gap, it doesn't close it.

## 8. Where this lives, and why it isn't part of the security boundary

This scaffold/CLI is a **convenience tool**, not part of the trust model
[05](05-security-and-sandboxing.md) establishes — nothing in that document changes because of
this one. Its correctness affects developer experience and how quickly a submission clears
automated intake on the first try; it has no bearing on tenant safety, because every guarantee in
[05](05-security-and-sandboxing.md) and [10](10-review-and-publishing-pipeline.md) is re-checked
authoritatively, server-side, regardless of what a developer's local tool told them beforehand. It
is shipped and documented from the developer portal ([09 §6](09-admin-dashboard-ux.md)), as a
downloadable CLI (npm package and/or Composer `create-project` template — either or both, a
tooling choice with no bearing on anything else in this plan).

## 9. Non-goals

- **No hosted cloud development environment** in the first delivery — this is a local CLI tool
  running on the developer's own machine, consistent with
  [00 §3](00-overview.md)'s general first-delivery scope discipline.
- **No live connection to a real marketplace, real tenant data, or the real Plugin Runtime**
  during local development — that separation from the production trust boundary is the entire
  point of §3's mock SDK, not a gap to close later.
- **No automatic submission from the CLI.** Packaging (§5) produces the zip; uploading it still
  goes through the developer portal / submission API
  ([10 §1](10-review-and-publishing-pipeline.md)) as a deliberate, separate action — a developer
  should not be able to submit a plugin by accident from a build script.

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | Project scaffold generator producing exactly the layout in §2 | Running the scaffold command produces a project whose non-DEV-ONLY paths, once packaged (§5), pass [04 §1](04-manifest-and-package-format.md)'s structure validation unmodified |
| **P1** | Mock capability SDK (§3), kept in lockstep with [05 §4](05-security-and-sandboxing.md) | A hook handler calling an undeclared capability, or writing to an undeclared `plugin_data` collection, fails locally the same way it would in production |
| **P2** | Local dev server with the real iframe sandbox/CSP and mock Plugin Host (§4) | A frontend bundle attempting a CSP-blocked action (e.g. a `fetch` outside the RPC model) fails locally, not only after submission |
| **P3** | Packaging command (§5) and local lint (§6), both kept in lockstep with [10 §2–§3](10-review-and-publishing-pipeline.md) | A project passing the local lint clears automated intake ([10 §2](10-review-and-publishing-pipeline.md)) on first submission in the overwhelming majority of cases; the packaged zip never contains a DEV-ONLY path |
