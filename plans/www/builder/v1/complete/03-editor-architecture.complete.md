# 03 — Editor architecture (complete)

## Status

**`initEditor()`: 2,242 → 19 lines. 90 tests, from zero (B5). 3.16 MB of dead vendored code
removed (B11).**

All nine phases of §3.5 are done. `core/imp.ts` is a re-export shim.

| Phase | Contents | State | `initEditor()` after |
|---|---|---|---|
| **P0** | Characterization tests (§3.4) | ✅ `node:test` + jsdom, **no new dependencies** | 2,242 |
| **P1** | `ui/` tooltips | ✅ the seam, proved on the least entangled region | 2,174 |
| **P2** | `panels/` device, undo/redo, tabs, toggles | ✅ 4 phases, **plus §3.3.2 — A7/A8 pass** | 2,014 |
| **P3** | `preview/` | ✅ preview, clear canvas, confirm dialog, locale | 1,811 |
| **P4** | `templates/` | ✅ **§3.2.2's split, reunited** | 1,592 |
| **P5** | `pages/` | ✅ the centre — snapshots, frame styles, the switch | 1,368 |
| **P6** | `pageTypes/` | ✅ largest; holds the editor's only outbound `fetch` | 944 |
| **P7** | `publish/` | ✅ last by design — [04](../04-publish-and-serve.md) rewrites it next | 446 |
| **P8** | `createEditor.ts` + `index.ts` | ✅ composition only | **19** |

## Acceptance — §3.8

| # | Check | Result |
|---|---|---|
| A1 | Characterization suite before any extraction | ✅ written first, at P0 |
| A2 | The same suite green after every phase | ✅ run after each; 90/90 |
| A3 | `imp.ts` gone or a shim | ✅ **19-line re-export** |
| A5 | `main.ts` unchanged | ✅ never edited |
| A7 | Renaming a required DOM id throws at startup, naming it | ✅ `resolveDom()` |
| A8 | Removing an optional element degrades quietly | ✅ same |
| A9 | Any phase's code in isolation | ✅ every test does exactly this |
| A10 | Exactly one `grapesjs.init` | ✅ `createEditor.ts` |
| A11 | Build after deleting the vendored copies | ✅ green, bundle unchanged |
| A12 | One phase per commit | ⚠️ **not met — nothing is committed** |
| A4 | Largest function under 150 lines | ⚠️ **not met** — see Residue |
| A6 | Publish output byte-identical before and after | ⚠️ **not proven** — see Residue |

## What the code said that the plan did not

**§3.2.2's interleaving is the shape of the file, not one bad patch.** The plan named the
template feature. P2 found the same thing in the right sidebar — bindings at line 769, handler
at 1216, **447 lines apart** with the entire page-type feature between. Two of the first four
phases hit one. Enumerate every reference to a binding before moving it; that grep is what
makes a reunification provable rather than hopeful.

**The plan's file map is a sketch, and three times the code disagreed.**
`extractInlineStylesFromHtml` is listed under `templates/` but four of its five callers are
page lifecycle, so it went to `html/`. The confirm dialog and locale selector came out of the
preview region but are a shared modal and editor chrome, so they went to `ui/`. Follow the call
sites.

**Four methods the local `Editor` type never declared.** `UndoManager.add`, `getCss(opts)`,
`on(event, handler(...args))` and `BlockManager.render` — all real GrapesJS API, all used by
code that compiled only because it sat in `imp.ts`, where `editor` is the type
`grapesjs.init()` returns rather than the local one. Each surfaced as a `tsc` error the moment
its caller moved out. `UndoManager.add` is the one that matters: it is what makes clear-canvas
recoverable, and it was untyped.

**`preview/surveyForms` is [02](../02-module-registry.md) §2.7's `preview`, already built.** It
is the only place a module's block becomes interactive before publish — it decodes a survey
definition from the canvas iframe and renders a working form. §2.7 asks for exactly that
separation; forms has an answer worth reading before writing it. It also loads three files from
unpkg.com at preview time, pinned to survey-core 1.12.23, which nothing had recorded and which
constrains §2.7 and [04](../04-publish-and-serve.md)'s CSP work.

**`renderStoreTemplate` calls the live ecommerce API from the editor.** In `pageTypes/`, the
one outbound `fetch` §3.2.3 counted. That is the editor talking to a module's production API
during design — the thing §2.7 exists to stop. Preserved unchanged; it is now a single call
site instead of 437 lines of closure.

**Two behaviours preserved rather than fixed**, both documented in place: `.dropdown-item` is
matched document-wide rather than scoped to `#deviceDropdown`, and `setTimeout(…, 350)` in
`panels/collapse` is coupled to a CSS transition duration with nothing enforcing the pair.

**One near-miss worth recording.** Extracting `publish/` I named its dependency `projectRef`;
the original reads `options?.projectId`. `tsc` caught it. Had the field been typed loosely it
would have silently disabled publishing for every tenant — the exact class of defect a
"behaviour-preserving" move is supposed to make impossible, arriving through a rename.

## §3.3.2 — the DOM contract

`core/editor/dom.ts` declares 33 of the 69 ids, each with `required` and a note saying what it
drives. `resolveDom(document)` resolves them once, before any phase mounts, and throws listing
**every** missing required id — not the first, because someone who renamed a wrapper has
usually renamed several.

```
[builder] the page is missing 3 required element(s):
  #blocksPanel   — the block palette — the editor is unusable without it
  #stylesPanel   — GrapesJS StyleManager mount point
  #leftSidebar   — left sidebar container
```

That closes §3.2.3: a renamed id and a switched-off feature used to be written identically
(`?.`) and were indistinguishable at runtime.

Eighteen are optional, and a test pins that list so promoting or demoting one is a deliberate
edit to a failing test. The judgement worth knowing: the confirm dialog's eight ids are
optional because it **fails closed** — a missing modal resolves `false`, so the destructive
action it guards does not run. Requiring them would take the whole editor down to protect a
step that already degrades safely.

## The tests

**90, from zero.** `npm run test:builder`.

| File | | Pins |
|---|---|---|
| `tooltips.test.ts` | 11 | positioning, keyboard parity, teardown, and the documented "not dynamic" limitation |
| `dom.test.ts` | 6 | A7, A8, and the optional list |
| `panels.test.ts` | 18 | device keys, the 447-line reunification, the 350 ms deferral |
| `preview.test.ts` | 16 | undo registered **before** the clear; the confirm failing closed |
| `templates.test.ts` | 21 | the `template-*` stamping window opening and shutting |
| `pages.test.ts` | 18 | the snapshot beating the model; spinners never persisted |

Three findings from writing them:

**One test was wrong and the code was right.** The dropdown-closes-on-document-click test
failed because the harness dispatched non-bubbling events. Real `click` bubbles; `mouseenter`
and `focus` do not. `fire()` models that per event type now — a characterization suite that
misreports correct code is worse than none.

**Async work outliving a test reads as a failed file with no failed assertion.** `pages/`
chains a 25 ms timeout and *then* a `requestAnimationFrame`; a 30 ms wait let the timeout run
and closed the jsdom window with the rAF still queued. Result: 18 passing tests and exit code 1
with nothing on stderr. Every test that starts async work now drains it before `cleanup()`.

**The harness learned three Vite conveniences**, each a few lines in `tests/resolve-ts.mjs`:
extensionless imports, `resolveJsonModule`, and `?raw` file imports. The alternative each time
was editing source files across the builder to suit a test runner.

There is a fourth it deliberately does not solve: **type-only imports written as value
imports**. Type stripping cannot elide those and no hook can tell a type from a value. It hit
twice — `core/config.ts` and `templates_data/business.ts` — and both times the answer was to
stop the phase importing that module at all, injecting the flag and then the catalogue. Both
are better designs than the imports were. **It will not always work out that way**, and when it
does not the fix is `import type` in a handful of source files.

## Step 0's harness adds nothing to `package.json`

**B5** was literal: no JavaScript test runner existed anywhere in `www`. Choosing vitest or jest
is a dependency decision belonging to whoever owns the build, so:

| Need | Used | Already there |
|---|---|---|
| runner | `node:test` | built into Node 22 (this machine runs v22.18) |
| TypeScript | `--experimental-strip-types` | built in |
| DOM | `jsdom` | already in `node_modules` |

```bash
npm run test:builder
```

`tsconfig.json` was changed during P0 and **reverted** — it is untouched. The one repo-wide
config change is the `test:builder` script.

## B11 — 3.16 MB removed

| File | Bytes | md5 |
|---|---|---|
| `core/dist/grapes.min.js` | 1,111,040 | `cbf84a83b1cc` |
| `staticbuild/grapes.min.js` | 1,111,040 | `cbf84a83b1cc` — **identical** |
| `core/dist/index.d.ts` | 469,410 | |
| `staticbuild/index.d.ts` | 469,410 | |

Nothing imported them; `createEditor.ts` imports `grapesjs` from npm (`^0.22.14`). Deleted, and
the build is green with an unchanged bundle (**A11**).

## Residue

**A4 is not met.** `publish.ts` (582 lines) and `pageTypes.ts` (514) are single functions well
over 150. That is deliberate: §3.4's rule is *move verbatim, improve in a separate commit*, and
splitting them during the move would have made the diff unreviewable. `publish/` is
[04](../04-publish-and-serve.md)'s to rewrite; `pageTypes/` should be split once
[07](../07-module-handoffs.md) §7.4 decides how page-type bindings and block references
resolve.

**A6 is not proven.** Nothing compares a published `html`/`css`/`js` payload before and after.
The phases are covered, but the end-to-end publish output is not, and A6 is the criterion that
would catch a whole-pipeline regression. **The fixture that proves it is
[04](../04-publish-and-serve.md)'s first requirement** — it changes that payload deliberately
and needs a baseline to change *from*.

**A12 is not met — nothing is committed.** The extraction is one working tree, not nine
commits. A regression will not bisect to a phase.

**Two back-channels survive.** `__clearTemplateCss` and `__setTemplateCssGroup` are still
properties bolted onto the editor instance because `preview/clearCanvas` calls the first.
§3.3.3's bus is where they go.

**`document` is shadowed in `pageTypes/` and `publish/`.** `const document = deps.doc` — so
those two bodies stay byte-identical to what was in `imp.ts`, which is what made the moves
reviewable. Threading the document properly is a follow-up, not a defect.

**`imp.ts` remains as a 19-line shim** so `main.ts` was never edited (**A5**). Deleting it is a
rename commit once importers move.

## Verification

```bash
cd www
npm run test:builder     # 90 pass
npx tsc --noEmit         # clean, exit 0
npm run build            # built in 2m 47s, exit 0
```

All three were run after every phase, not only at the end.

**Added** — `core/editor/`: `index.ts`, `createEditor.ts`, `context.ts`, `dom.ts`,
`html/inlineStyles.ts`, `ui/{tooltips,confirmDialog,localeSelector,saveButton}.ts`,
`panels/{index,device,history,sidebarTabs,collapse}.ts`,
`preview/{index,preview,clearCanvas,surveyForms}.ts`,
`templates/{index,templateCss,templatePicker}.ts`, `pages/{index,pages}.ts`,
`pageTypes/{index,pageTypes}.ts`, `publish/{index,publish}.ts`,
`canvas/contextMenu.ts`, `ai/aiChatPanel.ts`.
`tests/`: `harness.ts`, `fixtures.ts`, `register.mjs`, `resolve-ts.mjs`, and six `.test.ts`.

**Changed** — `core/imp.ts` (2,242 → 19), `core/types.ts` (four missing methods declared),
`package.json` (`test:builder`).

**Deleted** — `core/dist/`, `staticbuild/` (**B11**).

## Next

[07](../07-module-handoffs.md) §7.6 orders the rest of the folder. With this step done,
[02](../02-module-registry.md) is unblocked: the registry has a seam to register into, and
`initEditor()` is 19 lines instead of 2,242.
