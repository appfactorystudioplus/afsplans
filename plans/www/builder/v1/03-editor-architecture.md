# 03 — Editor architecture: giving `initEditor()` seams

> **STUB, FOR AUDIT.** No application code has been written.

Closes **B4** and makes **B5** possible. **Write and land this step before
[02](02-module-registry.md) and [04](04-publish-and-serve.md)** — both describe things that
have to be wired into `initEditor()`, and wiring them into a 2,190-line function is how the
current state was arrived at.

---

## 3.1 What is actually in there

`core/imp.ts` exports three symbols. One is the whole editor: `initEditor()`, lines **26–2215**.

Measured inside that body:

| | Count |
|---|---|
| Distinct DOM ids read via `getElementById` | **69** |
| `querySelector` / `querySelectorAll` calls | 16 |
| `addEventListener` calls | 50 |
| `editor.on(...)` handlers | 11 |
| `fetch(...)` calls | **6** |

Approximate regions, in source order:

| Lines | ~Size | Concern |
|---|---|---|
| 26–60 | 34 | `grapesjs.init()` and its config |
| 60–101 | 41 | `editor.on('load')` |
| 101–204 | 103 | Tooltips |
| 204–212 | 8 | **Template modal — element lookups** |
| 214–228 | 14 | Inline-style extraction from template HTML |
| 229–530 | **301** | **Page lifecycle** — snapshot store, frame style injection, loading overlay, hard reinit, save state, apply styles, render from model, CSS mount |
| 530–707 | **177** | **Template picker — rendering, confirm, cancel** |
| 707–750 | 43 | Device dropdown |
| 749–771 | 22 | Undo / redo |
| 771–852 | 81 | Left and right sidebar tabs |
| 852–1289 | **437** | **Page type** — subpage create/select, footer templates, commerce product fetch and render, auth radios |
| 1289–1330 | 41 | Sidebar toggles, canvas sizing |
| 1330–1370 | 40 | `getProjectData()`, save button |
| 1369–1500 | 131 | Clear canvas, preview, exit preview |
| 1498–1594 | 96 | Locale selector, survey forms in preview |
| 1594–2215 | **621** | **Publish, history, republish** |

## 3.2 Three properties make it hard to change, and only one is the length

**Length is the symptom.** These are the causes, and a split that does not address them just
produces smaller files with the same problem.

### 3.2.1 One shared closure

Every region reads and writes the same enclosing scope: `editor`, `pageSnapshotStore`,
`pendingTemplateContent`, `pendingTemplateId`, `pendingTemplateStyles`,
`currentTemplateCssGroup`, and 69 DOM handles. Nothing declares what it depends on, so
nothing can be moved without reading all 2,190 lines to find out what else touches it.

### 3.2.2 Features are interleaved, not sequential

The template feature is the clearest case:

```
204   template modal elements looked up
214   extractInlineStylesFromHtml()
229   ┌── page lifecycle — 301 lines, a different concern entirely
530   └──
530   template picker rendered
642   confirmTemplateBtn handler          ← 438 lines after its own element lookup
686   cancelTemplateBtn handler
```

The template feature's declarations and its handlers are separated by the entire page
lifecycle. Reading either one in isolation is impossible, and an edit to page rendering sits
inside what a reader is treating as "the template code".

### 3.2.3 The function *is* the DOM contract

69 `getElementById` calls against a Blade template that is not referenced anywhere in the
TypeScript. A renamed id in the view fails silently at runtime — the `?.` optional chaining
throughout means a missing element is indistinguishable from a feature that is switched off.

Six `fetch` calls sit in here too, including the commerce product fetch inside the page-type
block at ~942 — so the editor talks to live APIs from inside the same closure that manages
canvas state. That is the coupling [02](02-module-registry.md) **D5** separates.

## 3.3 The target shape

Not a rewrite, and not "one file per region". A small explicit context, and phases whose
code declares what it needs.

```
core/editor/
  createEditor.ts        grapesjs.init + config          ← the only grapesjs.init in the codebase
  context.ts             BuilderContext — the shared surface, explicit
  index.ts               initEditor(): compose the phases, in order

  pages/                 snapshot store, render, styles, overlay   (was 229–530)
  templates/             picker, apply, inline-style extraction    (was 204–228, 530–707)
  panels/                device, undo/redo, sidebar tabs, toggles  (was 707–852, 1289–1330)
  pageTypes/             subpages, footer templates, auth radios   (was 852–1289)
  preview/               preview, exit preview, survey init        (was 1369–1594)
  publish/               publish, history, republish, activate     (was 1594–2215)
  ui/                    tooltips, toasts, loading overlay
```

### 3.3.1 The context is the seam

```ts
export interface BuilderContext {
    editor:   Editor;
    project:  { id: string | null; name: string | null; ref: string | null };
    dom:      BuilderDom;        // §3.3.2 — resolved once, validated once
    modules:  BuilderModuleRegistry;   // 02
    bus:      EditorBus;         // §3.3.3
}

export interface EditorPhase {
    id: string;
    /** Wire this phase's code up. Returns a teardown so it can be tested and disposed. */
    mount(ctx: BuilderContext): () => void;
}
```

`initEditor()` becomes the composition:

```ts
export function initEditor(options?: InitOptions, saveCallback?: SaveCallback) {
    const ctx = createContext(options);
    const teardowns = PHASES.map(p => p.mount(ctx));
    return { editor: ctx.editor, destroy: () => teardowns.forEach(fn => fn()) };
}
```

A phase's code is testable because `mount(ctx)` takes everything it needs and returns
everything it holds. That is the property **B5** has been waiting for — not "we should write tests", but a
shape a test can address.

### 3.3.2 The DOM contract becomes explicit

```ts
/** Every element the editor binds to. One place, validated at startup. */
export const BUILDER_DOM = {
    publishBtn:      '#publishBtn',
    saveBtn:         '#saveBtn',
    leftSidebar:     '#leftSidebar',
    …  // all 69
} as const;

export function resolveDom(root: Document): BuilderDom;   // throws, listing what is missing
```

**§3.2.3's silent failure becomes a startup error naming the missing ids.** Optional elements
are marked optional deliberately, so "this feature is off" and "someone renamed the id" stop
being the same observation.

### 3.3.3 A bus, only where phases genuinely talk

Publish needs the current page state; templates need to clear page CSS; page-type needs to
trigger a render. Today these reach across the closure and call each other's local functions.

A small typed bus — `page:before-save`, `page:rendered`, `template:applied`,
`publish:started`, `publish:completed` — keeps the coupling visible and testable. **Only
where a real cross-phase dependency exists.** A bus used for everything is the same closure
with more ceremony.

## 3.4 How to extract without breaking it

There are no tests, so "it still works" currently means "someone clicked around". That has to
be fixed *first*, or every step is a guess.

**P0 — characterization tests, before touching anything.** Not tests of what the code
*should* do; tests that pin what it *does*, so a behaviour change during extraction is
visible:

- initialise the editor against a fixture DOM and assert the block palette's contents
- load a project fixture, select each page, assert the rendered HTML and CSS
- apply a template, assert the resulting components and the CSS group
- click publish against a stubbed API, assert the exact payload per page
- undo/redo across a page switch, assert canvas state

The publish payload assertion is the one that matters most: [04](04-publish-and-serve.md)
changes that payload deliberately, and this is what proves nothing else changed with it.

**Then, one phase at a time:**

1. Move the code verbatim. No renames, no cleanup, no "while I'm here".
2. Add the parameters it needs to `BuilderContext`, and nothing else.
3. Characterization tests green.
4. Commit. One phase, one commit — a regression bisects to a single move.
5. *Then* clean up inside that phase, as a separate commit.

The rule is **behaviour-preserving first, improvement second, never in the same commit.** The
temptation to fix things during a move is exactly how a 2,190-line function gets a 2,190-line
rewrite that nobody can review.

## 3.5 Phases

Least entangled first, so the method is proven before it meets the hard parts:

| Phase | Contents | ~Lines | Why here |
|---|---|---|---|
| **P0** | Characterization tests (§3.4) | — | Nothing can move safely until a behaviour change is visible |
| **P1** | `ui/` tooltips | 103 | Almost no shared state. Proves the harness |
| **P2** | `panels/` device, undo/redo, tabs, toggles | ~190 | DOM-bound, little cross-talk |
| **P3** | `preview/` | 131 | Self-contained; one dependency on page state |
| **P4** | `templates/` | ~200 | **Reunites §3.2.2's split halves** — the first real payoff |
| **P5** | `pages/` | 301 | The centre. Everything else touches it, so it moves once its callers already moved |
| **P6** | `pageTypes/` | 437 | Largest, and where a `fetch` moves behind [02](02-module-registry.md)'s preview seam |
| **P7** | `publish/` | 621 | Last, because [04](04-publish-and-serve.md) rewrites it next and moving it twice is wasted work |
| **P8** | `createEditor.ts` + `index.ts` | ~75 | What remains is composition |

**P7 is deliberately last.** [04](04-publish-and-serve.md) turns publish into a compile step;
extracting it first means extracting it, then rewriting it.

## 3.6 What must not change

An extraction that alters behaviour is a rewrite wearing a disguise. Explicitly out of scope:

- **GrapesJS stays**, at its current version, with the same config.
- **The published output is byte-identical** at every step of this document. §04 changes it;
  §03 does not.
- **The save and publish payloads keep their exact shape.**
- **`core/ai_chat/` moves intact**, unread.
- **`pageManager.ts` is not merged in** — it already separates pages from the editor, and
  `pages/` is where it finally has somewhere to belong.
- **No renames** of exported symbols. `initEditor`, `setProjectName`, `getProjectName` keep
  their names and signatures; `main.ts` should not need editing.

## 3.7 Two cleanups this unblocks, and one it does not

**Unblocked and worth doing in the same pass**, because both are found by touching the files:

- **B11** — delete `core/dist/` and `staticbuild/`'s vendored GrapesJS (2 × 1,111,040 bytes,
  byte-identical) and the two 469 KB `index.d.ts` copies. `imp.ts:9` imports `grapesjs` from
  npm; nothing imports the vendored files. Verify with a build, then remove.
- **B12** — `ecommerceTemplates.ts` (38 KB of markup in TypeScript) moves alongside
  `templates_data/` as data, so a copy change stops being a deploy.

**Not unblocked:** **B13**, the tenant-facing error surface. Phases make it *possible* to
report failures consistently, but deciding what a tenant sees is
[06](06-editor-ux-and-styling.md)'s. Do not invent an error UI during an extraction.

## 3.8 Acceptance

| # | Check | Expect |
|---|---|---|
| A1 | Characterization suite, before any extraction | Green, and pinning the publish payload |
| A2 | After every phase | The **same** suite green, unmodified |
| A3 | `imp.ts` | Gone, or a re-export shim only |
| A4 | Largest function in `core/editor/` | Under 150 lines |
| A5 | `main.ts` | **Unchanged** — same import, same call (§3.6) |
| A6 | Publish a fixture project before and after extraction | `html`, `css`, `js` byte-identical |
| A7 | Rename a required DOM id in the Blade view | Startup **throws**, naming the id (§3.3.2) — not a silently dead button |
| A8 | Remove an optional element | Editor starts; that feature is absent; no error |
| A9 | Any phase's code, in isolation | `mount(ctx)` with a stub context, assert, call teardown |
| A10 | `grep -rn "grapesjs.init"` | Exactly one hit — `createEditor.ts` |
| A11 | Build after deleting the vendored copies | Succeeds; bundle size unchanged (**B11**) |
| A12 | `git log` for the extraction | One phase per commit, each independently revertible |
