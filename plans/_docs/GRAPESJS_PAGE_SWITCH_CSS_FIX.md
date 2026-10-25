# GrapesJS Multi-Page CSS Fix — Page Switch Styles Not Applied

## Problem

In the GrapesJS multi-page builder, switching between ecommerce pages (Products → Cart → Checkout and back) caused CSS styles to disappear. The most visible symptom was that the `.checkout-grid` CSS class (a CSS Grid layout used by `checkoutTemplate`) stopped being applied after one or two page switches, causing the layout to collapse.

---

## Root Cause Analysis

### 1. GrapesJS CSS is global, not per-page

GrapesJS's `CssComposer` maintains a **single shared rule collection** (`allRules`) across all pages. There is no native per-page CSS isolation. When you call `editor.setStyle(css)`, you are replacing the entire global ruleset.

### 2. Iframes are reused per page — no reload event on revisit

Each page in GrapesJS owns a `Frames` collection containing one `Frame` model. That frame's underlying `<iframe>` is created once and **reused** on every revisit. The `canvas:frame:load:body` event only fires **once** per iframe lifetime (the first time it loads). On subsequent visits to a page, the iframe's document already exists and no load event fires.

### 3. `editor.Canvas.getWindow()` was broken for frame switching

`editor.Canvas.getWindow()` internally calls `canvasView.frame.getWindow()`, where `canvasView.frame` is a **`FrameWrapView`** — a wrapper object. `FrameWrapView` has **no `getWindow()` method**, so the call silently returned `undefined`. Any attempt to inject CSS directly into the iframe document using this path failed silently on re-visits.

The correct path to reach a page's iframe `window` is:

```
page.getMainFrame()   →  Frame model
  .view               →  FrameView (the actual iframe wrapper)
    .getWindow()      →  iframe's window object
```

### 4. CssRulesView updates its `<style>` element reference lazily

GrapesJS's `CssRulesView` is responsible for keeping the `<style>` tag inside the iframe in sync with `allRules`. During a page switch, GrapesJS fires `page:select` synchronously while it is still wiring `CssRulesView` to the new frame's `<style>` element. If `editor.setStyle()` is called **inside** the `page:select` handler (the same JavaScript task), the CSS is written into the **old frame's** `<style>` element — which is now detached from the visible canvas. The new frame renders with no styles.

### 5. `saveCurrentPageState` was overwriting styles with loading-spinner HTML

`renderStoreTemplate` injects a loading spinner (`<div data-gjs-loading="true">…</div>`) while the async product fetch is in flight. If `saveCurrentPageState` ran during this window (triggered by `page:select:before`), it captured the spinner HTML and the empty CSS as the page's permanent state, destroying the real template content.

---

## Solution

### External Snapshot Store + Deferred Hard Reinit

The fix introduces an **external snapshot store** — a `Map<pageId, { html, css }>` that lives completely outside GrapesJS's internal model — and a **`hardReinitPageFromSnapshot`** function that restores a page's content using a `setTimeout(fn, 50)` delay.

```
pageSnapshotStore: Map<string, { html: string; css: string }>
```

#### Why an external store?

GrapesJS's page model (`page.get('component')` / `page.get('styles')`) is readable, but calls to `editor.getHtml()` and `editor.getCss()` reflect what is currently in the **canvas**, not necessarily what was last saved to the model. During a frame switch these values can be stale or wrong. The external `Map` is written to only at explicit, controlled points in the code, making it a reliable source of truth.

#### Why `setTimeout(fn, 50)`?

`page:select` fires synchronously during GrapesJS's frame-switch bookkeeping. `CssRulesView` reassigns its internal `<style>` element reference **lazily, asynchronously**. By pushing the CSS restore into a `setTimeout` callback (a new macrotask), we guarantee that GrapesJS has finished wiring the view to the new frame before we call `editor.setStyle()`. Without this delay, `setStyle` writes into the old, now-detached `<style>` element.

#### Belt-and-suspenders: direct frame injection

Even with the deferred `setStyle`, the `<style>` element managed by CssRulesView could theoretically be replaced by subsequent GrapesJS activity. As a final safety net, after the `setTimeout` fires, a `requestAnimationFrame` callback injects the CSS directly into the iframe's `<head>` as a `<style id="gjs-page-injected-css">` element, obtained via the correct `page.getMainFrame().view.getWindow()` path.

---

## Code Overview (`resources/js/portal/builder/core/imp.ts`)

### Data structure

```typescript
interface PageSnapshot { html: string; css: string; }
const pageSnapshotStore = new Map<string, PageSnapshot>();
```

### Snapshot capture points

| Function | When it runs | What it does |
|---|---|---|
| `renderPageFromModel(page)` | Initial editor load | Seeds the store for the first page |
| `saveCurrentPageState(page)` | `page:select:before` | Reads live canvas state, writes to model AND store |
| `capturePageSnapshot(page)` | `page:select:before` (second call) | Belt-and-suspenders read from model into store |
| `renderStoreTemplate` fetch `.then()` | After async product fetch resolves | Writes freshly-fetched ecommerce content to store |

### Snapshot restore

```typescript
const hardReinitPageFromSnapshot = (page: any): void => {
    // 1. Read from external store (fall back to GrapesJS model if missing).
    const snap = pageSnapshotStore.get(page.id);
    let html = snap?.html ?? page.get('component') ?? '';
    let css  = snap?.css  ?? page.get('styles')    ?? '';

    // 2. Strip any accidental inline <style> blocks, merge into css.
    if (html.includes('<style')) { /* extractInlineStylesFromHtml */ }

    // 3. Sync cleaned values back to the model and store.
    page.set('component', html);
    page.set('styles', css);
    pageSnapshotStore.set(page.id, { html, css });

    // 4. Wipe the canvas immediately (blank during the frame switch).
    editor.setStyle('');
    editor.setComponents('');

    // 5. Restore after GrapesJS has rewired CssRulesView to the new frame.
    setTimeout(() => {
        if (css) editor.setStyle(css);
        editor.setComponents(html || '');

        // 6. Also inject directly into the iframe <head> as a fallback.
        if (css) {
            requestAnimationFrame(() => injectFrameStyles(css, undefined, page));
        }
    }, 50);
};
```

### Page switch event handlers

```typescript
editor.on('page:select:before', () => {
    const outgoing = editor.Pages.getSelected?.();
    saveCurrentPageState(outgoing); // writes to model AND snapshot store
    capturePageSnapshot(outgoing);  // belt-and-suspenders second capture
});

editor.on('page:select', (page: any) => {
    hardReinitPageFromSnapshot(page); // deferred restore (50 ms + rAF)
    loadPageType();

    if (page?.get?.('pageType') === 'e-commerce' && !page.get?.('component')) {
        renderCurrentCommercePage(); // only for pages with no content yet
    }
});
```

### Direct frame injection helpers

```typescript
// Correct path to get a page's iframe window object.
const getFrameWindowForPage = (page: any): Window | undefined => {
    const frameModel = page?.getMainFrame?.();   // Frame model
    return frameModel?.view?.getWindow?.();       // FrameView → iframe window
};

// Replace/create <style id="gjs-page-injected-css"> in the iframe <head>.
const injectFrameStyles = (css: string, frameWindow?: Window, page?: any): void => {
    const win = frameWindow
        || getFrameWindowForPage(page)
        || getFrameWindowForPage(editor.Pages?.getSelected?.())
        || (editor.Canvas as any).getWindow?.();
    const doc = win?.document;
    if (doc) injectIntoDoc(css, doc);
};
```

### Loading spinner guard

`renderStoreTemplate` marks its loading state with a data attribute:

```html
<div data-gjs-loading="true" …>Loading store products…</div>
```

`saveCurrentPageState` checks for this marker and **bails out** if found, preventing the spinner from being persisted as the page's real content:

```typescript
if (currentHtml.includes('data-gjs-loading="true"')) return;
```

---

## Sequence Diagram — Page Switch

```
User clicks page tab
        │
        ▼
page:select:before
  ├─ saveCurrentPageState(outgoing)   →  writes canvas HTML+CSS → model + store
  └─ capturePageSnapshot(outgoing)    →  reads model → store (belt-and-suspenders)
        │
        ▼  (GrapesJS swaps frame reference, begins wiring CssRulesView to new iframe)
        │
page:select
  └─ hardReinitPageFromSnapshot(incoming)
       ├─ reads store (or model fallback)
       ├─ editor.setStyle('')         →  wipe (synchronous, still old frame — harmless)
       ├─ editor.setComponents('')    →  wipe
       └─ setTimeout(50 ms) ──────────────────────────────────────────────────┐
                                                                               │
                                           GrapesJS finishes frame wiring      │
                                                                               ▼
                                                             editor.setStyle(css)   →  new frame ✓
                                                             editor.setComponents(html)
                                                             requestAnimationFrame()
                                                               └─ injectFrameStyles()
                                                                    └─ page.getMainFrame().view.getWindow()
                                                                         └─ inject <style> into iframe <head> ✓
```

---

## Files Changed

| File | Change |
|---|---|
| `resources/js/portal/builder/core/imp.ts` | All logic described above |
| `resources/js/portal/builder/core/ecommerceTemplates.ts` | Moved `.checkout-grid` CSS out of inline `<style>` in `render()` into `checkoutTemplate.styles` property so it is captured by `effectiveStyles` in `renderStoreTemplate` |
