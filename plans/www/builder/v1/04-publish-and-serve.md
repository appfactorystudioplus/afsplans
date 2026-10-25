# 04 — Publish and serve: what reaches the browser

> **STUB, FOR AUDIT.** No application code has been written.

Closes **B2**, **B3**, **B8**, **B9**, **B10**. This is the second half of the loop — what
`initEditor()` hands over, and what a visitor actually receives.

---

## 4.1 The `js` column, and why it is empty

```ts
js: '',       // Reserved for future page-level JS support
```

The column exists. `BuilderPublishedController` already emits
`<script defer>{$page->js}</script>`. The schema's own comment explains the intent — CSS and
JS in separate columns *"so they can be queried, sized, and diffed independently."*

Nothing writes it, and module behaviour reaches the page a different way: GrapesJS serialises
each component's `script` into the exported markup, so it lands inside the **`html`** column
(**B3**). Forms work because of this. It also means:

- JS cannot be diffed, sized or content-hashed — the thing the separate column was for.
- Page-level JS has no home at all.
- The script sits inside markup the editor round-trips, so a layout edit can alter behaviour.

## 4.2 Publish becomes a compile step

Publishing stops being "serialise the canvas" and becomes a small, testable pipeline:

```
   editor pages
        │
        ├── html   getHtml()             markup only, component scripts stripped
        ├── css    getCss()              + a hash
        └── js     compileRuntime(page)  ← NEW: the module runtime bundle
                        │
                        ├─ collect [data-afs-module] instances on this page
                        ├─ resolve each to its registry entry + version  (02 §2.6)
                        ├─ emit ONE dispatcher + only the runtimes used
                        └─ hash it
```

**One dispatcher, not one script per instance.** Ten product grids on a page produce one
ecommerce runtime and ten mount points:

```js
// generated — do not edit
(function () {
  var R = {
    ecommerce: { 3: function (el, cfg) { /* … */ } },
    forms:     { 1: function (el, cfg) { /* … */ } }
  };
  document.querySelectorAll('[data-afs-module]').forEach(function (el) {
    var m = el.dataset.afsModule, v = el.dataset.afsModuleVersion;
    var fn = R[m] && R[m][v];
    if (!fn) return console.error('[AFS] no runtime for ' + m + '@' + v);
    try { fn(el, JSON.parse(el.dataset.afsConfig || '{}')); }
    catch (e) { console.error('[AFS] ' + m + ' failed', e); }
  });
})();
```

Four properties this buys, none of which hold today:

| | |
|---|---|
| **The column is populated** | **B2** closed — and the "queried, sized, diffed" rationale becomes real |
| **Markup and behaviour separate** | **B3** closed — editing layout cannot change behaviour |
| **One runtime per module per page** | Not one copy per instance |
| **A failing module is contained** | The `try` means one broken block does not stop the rest of the page |

### 4.2.1 Every page is a build artifact

`html`, `css` and `js` each get a hash, and publish records the module ids and versions the
page contains. That gives three things the current pipeline cannot do: tell whether a
republish actually changed anything, find every live page using `ecommerce@2` before retiring
that version, and answer "what did this page ship last Tuesday" from the history table
already there.

## 4.3 Serving

### 4.3.1 Nested paths — B8 **WITHDRAWN**

> **Verified 2026-09-01: nesting already works.** Publishing `b8flat` and `b8deep/child` and
> requesting both returns 200, with the nested page's own markup; `/portal/...` still routes to
> the portal. The `where()` pattern replaces the placeholder's default and permits `/`.
>
> What P4 actually found is **B8a**: every published page returned 500, because
> `index.blade.php` renders `<x-layout>` and a Blade component does not inherit the calling
> view's data, so `$afssdk` was undefined. Fixed. See
> [01](01-current-state-audit.md) §1.5.
>
> The two paragraphs below are the original text. They are wrong about the symptom and right
> about the two consequences — the reserved-prefix list and full-path slug uniqueness are
> load-bearing whether or not the change was needed, and both are now documented at the route.


```php
Route::get('/{subpaths}', …)->where('subpaths', '^(?!portal|auth1|_portal_).*$');
```

Laravel's `{subpaths}` does not match `/`, so `/about` resolves and `/about/team` 404s. The
column is `subpaths`, plural; the comment says *"any single-segment path"*. The intent and the
implementation disagree and a tenant cannot build a nested site.

`->where('subpaths', '...')` with the pattern allowing `/` fixes the match. Two things have to
be settled with it, and neither is automatic: the reserved-prefix list becomes load-bearing at
every depth, and slug uniqueness has to be enforced on the **full path** rather than one
segment.

### 4.3.2 Output safety — B9

The controller's comment is honest about its own assumption:

> *"This is safe because the content originates from the builder canvas (user-controlled but
> authenticated)."*

That holds for a single-operator tenant. It stops holding the moment a tenant has two
editors, or any authoring surface reachable by a site user — at which point a `<script>` tag
is already provided and the payload is stored.

The compile step in §4.2 is what makes a real answer cheap, because generated JS is
predictable in a way hand-authored JS is not:

| Control | Effect |
|---|---|
| **Generated `js` only** | The column is written by the compiler, never accepted from a client |
| **Hash + CSP** | `script-src 'self' 'sha256-…'` using the hash from §4.2.1 — inline scripts that were not compiled do not execute |
| **Sanitise `html` on publish** | Strip `<script>`, `on*` handlers and `javascript:` URLs. After §4.2 the markup has no legitimate reason to carry script |
| **Report violations** | A CSP report endpoint turns "someone tried" into a signal instead of nothing |

> **Sequencing matters.** Sanitising `html` **before** §4.2 moves module JS out of it would
> strip the only thing making forms work. §4.2 first, then sanitise. The rollout in
> [08](08-rollout-and-testing.md) puts them in that order for this reason.

### 4.3.3 The activation flip — B10

Publish deactivates the previous version and activates the new one. Two publishes racing, or
a failure between the two writes, can leave **zero** active rows for a slug — a live page
returning 404.

One transaction, and a partial ordering that never has a gap:

```sql
BEGIN;
  INSERT … (subpaths, html, css, js, …, is_active = 0);
  UPDATE … SET is_active = 0 WHERE subpaths = ? AND is_active = 1;
  UPDATE … SET is_active = 1 WHERE id = <new>;
COMMIT;
```

A partial unique index on `(subpaths) WHERE is_active = 1` makes "two active versions"
unrepresentable rather than merely unlikely.

> **`site_users/12` **N13** depends on this.** It puts route protection on the route rather
> than the published version *because "Republish" restores old rows* — a security flag must
> not be undone by a content rollback. That reasoning needs the publish/rollback contract
> written down; §4.3.3 is where it is. Republish activates an old **content** row and must
> never touch protection, and this is the document that has to keep saying so.

## 4.4 Deleting a page, and other missing verbs

Publish and republish exist. Unpublish does not, so a page cannot be taken down without
deleting rows — and deleting rows destroys the history the republish feature depends on.

`is_active = 0` for every version of a slug is "unpublished", distinct from "never existed".
The 404 path should be able to tell the difference, because a tenant asking *"where did my
page go"* deserves a better answer than the one a stranger gets.

## 4.5 Phases

| Phase | Contents | Why here |
|---|---|---|
| **P0** | `compileRuntime()` — the dispatcher, the used runtimes, the hashes. Pure, wired to nothing | A pure function over page HTML is the one part of this document testable without a database or a browser. Everything else depends on its output being right |
| **P1** | Write `js`; strip component scripts from `html` | **B2** and **B3**. The first phase a visitor can observe |
| **P2** | Record hashes and the module/version manifest per page (§4.2.1) | Needs P0's hashes. Makes a no-op republish recognisable (**A6**) and "find every page on `ecommerce@2`" answerable |
| **P3** | The activation flip: one transaction, partial unique index (§4.3.3) | **B10**. Independent of P0–P2 and the only phase that can leave a live page 404ing, so it lands on its own |
| **P4** | Nested paths (§4.3.1) | **B8**. Independent; needs the reserved-prefix list and full-path slug uniqueness settled with it |
| **P5** | Sanitise `html`; CSP with the P2 hashes (§4.3.2) | **B9**. **Must follow P1** — see below |
| **P6** | Unpublish (§4.4) | Needs P3's flip contract to say what "no active version" means |

> ### ⚠ P5 cannot come before P1
>
> §4.3.2 says it and it is the one ordering mistake in this document that would break a live
> feature: **sanitising `html` before P1 moves module JS out of it strips the only thing making
> forms work today** (**B3**). Forms is the one module whose whole path works end to end, and
> it works *because* its script rides inside the `html` blob.
>
> P1 first, then P5.

## 4.6 Acceptance

| # | Check | Expect |
|---|---|---|
| A1 | Publish a page containing any module | `js` column **non-empty** (B2) |
| A2 | The `html` column of that page | Contains **no** `<script>` (B3) |
| A3 | Ten instances of one module on a page | One runtime in `js`, ten mount points |
| A4 | One module's runtime throws on the live page | The others still initialise (§4.2) |
| A5 | A page whose module version has no runtime | Console error naming module and version; the rest of the page works |
| A6 | Publish twice with no changes | Hashes identical; recognisable as a no-op (§4.2.1) |
| A7 | `GET /about/team` for a nested published page | 200 (B8) |
| A8 | `GET /portal/...` after the nesting change | Still routed to the portal, not the builder |
| A9 | Save `<script>alert(1)</script>` into page content, publish, load | Does not execute (B9) |
| A10 | Inline script injected into `html` post-publish | Blocked by CSP — hash mismatch |
| A11 | Two concurrent publishes of one slug | Exactly one active row; never zero (B10) |
| A12 | Kill the process mid-publish | Previous version still active and serving |
| A13 | Republish an older version | Content reverts; route protection **unchanged** (`site_users/12` N13) |
| A14 | Unpublish a page | 404 to visitors; history intact; distinguishable from never-published (§4.4) |
