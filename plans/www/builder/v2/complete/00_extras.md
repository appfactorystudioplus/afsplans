# 00 — Extras (off-plan adjustments)

## What this file is

The numbered `complete/` files ([02](02-page-level-contract.complete.md),
[03](03-the-three-modules.complete.md), [04](04-phases.complete.md)) are
what the v2 plan **became**. This file is everything we had to adjust
that **was not a plan step**: canvas bugs, serve-path failures, schema
holes, UI-SDK wiring, and the published-page behaviour that only showed
up after a tenant actually published a store.

It does **not** supersede the numbered completes for “how v2 works
today.” Those remain the authority for the page-level contract, the
three modules, and the compile loop. This file is the authority for
the work that sat **beside** those steps — the things that would
otherwise live only in chat and then get re-discovered.

**Repo:** www. Application code is uncommitted unless a later commit
says otherwise. Paths below are from `w/www`.

**How to add to it.** Append a new `E*` section (next number, dated).
Keep the same shape: symptom, cause, what we changed, the files, a
code example, and the operational consequence (republish, re-pick
template, deploy UI SDK, do not touch Grapes timing, …). Do not fold
an extra into a numbered complete unless the plan itself changed.

---

## Index

| # | Date | What | Why it is not in the plan |
|---|---|---|---|
| [E1](#e1--compiled-js-must-run-at-the-end-of-body) | 2026-09 | Compiled `js` at end of `<body>` + `__afsBoot` | Serve placement; head `defer` is a no-op on inline scripts |
| [E2](#e2--blade-layout-must-declare-afssdk-and-afssdkui) | 2026-09 | `$afssdk` / `$afssdkui` props + dual remote SDKs | Blade component data trap; tenants have no local UI bundle |
| [E3](#e3--published-page-chrome-readable-default) | 2026-09 | Inter + white body + storefront button defaults | Grapes `#id` rules rarely survive publish; body was Times on transparent |
| [E4](#e4--parent-slug-is-an-empty-grapes-folder) | 2026-09 | `contentOrFirstView()` | Page-level parent publishes empty; visitor hits `/new-page` |
| [E5](#e5--cart-and-order-annex-tables-were-missing) | 2026-09-02 | `2026_09_02_061800_…` migration | Add to Cart 500; `hasTable` skipped later creates |
| [E6](#e6--externalapi-tsconfigjson-must-extend-the-sibling) | 2026-09 | `external/api/tsconfig.json` | Editor / `tsc` could not resolve the API package |
| [E7](#e7--builder-ui-sdk-portaluiecommerce) | 2026-09 | `resources/js/external/ui/` | Shared chrome (toast, cart, mount) lives on `api-ui(-sandbox)`, not in each page |
| [E8](#e8--canvas-styles-vanish-on-page-switch) | 2026-09 | Grapes reset-CSS guard + 25 ms post-template save | `getCss()` returned a truthy reset; templates store styles in `style=""` |
| [E9](#e9--published-runtime-wiped-the-designed-page) | 2026-09 | `looksDesigned()` + hydrate-in-place | Stored HTML was already the list/hero/footer; `mount()` replaced it |
| [E10](#e10--hydrate-every-designed-ecommerce-view) | 2026-09 | Cart / checkout / orders / confirmation slots | Same remount wipe on the other four views |
| [E11](#e11--cart-icon-and-product-view) | 2026-09 | Template icon + View + sibling URLs | Storefront chrome the templates never had |
| [E12](#e12--direct-payment-is-admin-owned-not-a-builder-field) | 2026-09-02 | Store picker + read-only DP notice + published maintenance overlay | v2 put a DP select on Checkout; payment lives on the store in Admin |
| [E13](#e13--direct-payments-save-500-after-the-row-was-written) | 2026-09-02 | `GetManagedDomain()` used bare `getSubdomain2()` | Cron bootstrap after save threw; client parsed the HTML 500 as JSON |
| [E14](#e14--published-store-has-no-css-and-no-product-back-button) | 2026-09-02 | Layout chrome + product back link + publish reset fallback | Official slugs shipped Grapes reset CSS; `#id` rules lived on `-1` duplicates |
| [E15](#e15--direct-payment-items-are-a-paginated-api) | 2026-09-03 | Cursor-paginated items CRUD + admin table/modal | `GET /directpayments` embedded every item; save wiped the set |
| [E16](#e16--publish-prices-without-republishing-the-site) | 2026-09-03 | `prices()` + revision + admin Publish prices + layout paint | Grapes HTML bakes `$`; DP item edits did not rewrite catalogue `price_cents` |
| [E17](#e17--price-poll-blew-the-storefront-throttle) | 2026-09-03 | GET 600/hour; one 60s poller per store | 120/hour + 15s poll × N commerce roots → 429 on `init()` |
| [E18](#e18--no-catalogue-price--unmapped-products-are-not-in-the-store) | 2026-09-03 | DP item is the only price; unmapped hidden | Catalogue `price_cents` was a second price; mug/sticker showed $12.99 / $5.99 |
| [E19](#e19--zero-dp-item-skips-the-processor-and-reports-success) | 2026-09-03 | `$0` invoice → succeeded, no Stripe | Modules skipped DP at $0; ecommerce would have hit `min:50` |
| [E20](#e20--admin-dashboard-home-is-wired-to-the-modules) | 2026-09-03 | Live home cards + last 5 products + upcoming events + forms submitted | Dashboard listener was empty; stats read a never-filled DataStore |
| [E21](#e21--storefront-lists-only-sellable-products-with-a-loader) | 2026-09-03 | SQL-filtered catalogue, loader, progressive images | Baked Grapes cards flashed at $0.00 then hid; Add to Cart could vanish |
| [E22](#e22--cart-qty-inventory-holds-and-storefront-availability) | 2026-09-03 | Cart ±/remove, product qty, listing modes, timed holds | Cart was display-only; no stock, no disable/hide/sold-out |
| [E23](#e23--cart-badge-stays-stale-and-cart-page-flashes-baked-lines) | 2026-09-03 | Layout fetches cart, hides until ready, paints badge everywhere | Icon kept the old count; cart flashed catalogue then $NaN |
| [E24](#e24--storefront-cssjs-go-through-grapes-not-the-layout) | 2026-09-03 | Chrome via Grapes `setStyle` / `css` column; runtime via compiled `js` | Layout is every published page; ecommerce CSS/JS cannot live there |
| [E25](#e25--add-to-cart-sticks-on-adding) | 2026-09-03 | Ignore UI busy-disabled; reset if add never starts | Capture set `disabled` then compiled bailed; no `addToCart` |
| [E26](#e26--save-for-later-favorites-and-qty-icons) | 2026-09-03 | Qty SVG, store flags, saved-for-later accordion, favorites page + heart + icon | Off-plan shopping lists; chrome `button { color:#fff }` hid ± glyphs |
| [E27](#e27--admin-overview-500-and-inventory-lives-in-settings) | 2026-09-03 | Orders `placed_at` annex + Inventory form on Settings | Skinny orders table; flags sat on the dashboard |
| [E28](#e28--favorites-send-buttons-spinners-accordion-under-totals) | 2026-09-03 | Icon chrome not Grapes "Send"; spinner not loading copy; saved-for-later under totals | Form-button default; `content:"Loading…"`; accordion sat above Subtotal |
| [E29](#e29--cart-icon-add-to-cart-and-checkout-cta) | 2026-09-03 | Cart icon navigates; Add to Cart adds; Checkout CTA; accordion last | Remote UI bound first and ate the click; cart template had no pay link |
| [E30](#e30--checkout-totals-clear-cart-and-shipping-selector) | 2026-09-04 | Checkout shows live subtotal/shipping/total; no baked products; Clear Cart button; shipping method selector | Old published HTML had placeholder products, $NaN, $5.00 hardcoded shipping |
| [E31](#e31--stripe-csp-via-strict-dynamic-on-published-checkout) | 2026-09-04 | Stripe.js loads on published checkout pages without hard-coding provider CDN hosts in PHP | `publishedPageCsp` used hard-coded `js.stripe.com`; now uses `strict-dynamic` + SDK nonce propagation |
| [E32](#e32--cart-token-duplicate-on-add-to-cart) | 2026-09-04 | Add to Cart no longer 500s with a stale cart token | `getOrCreate()` tried to reuse a stale `cart_token` and hit the unique constraint |
| [E33](#e33--primary-key-audit-integer-only-surrogate-keys) | 2026-09-04 | Primary keys are integer-only across application tables | ULID/UUID/string surrogate PKs in processor, members storage, and listings tables violated the rule |
| [E34](#e34--ecommerce-variants-json-unique-via-generated-hash) | 2026-09-04 | `laravel_ecommerce_variants` unique variant constraint works on MySQL | Unique index on a JSON column is invalid; now enforced via a generated SHA-256 hash column |
| [E35](#e35--auth-checkpoint-2fa-pending-integer-pk) | 2026-09-04 | `checkpoint_2fa_pending` uses an integer surrogate key | String-token primary key violated the integer-PK rule; token kept as a unique lookup |
| [E36](#e36--auth-checkpoint-2fa-integer-pk-and-unique-user) | 2026-09-04 | `checkpoint_2fa` uses an integer surrogate key | Composite (user_type, user_id) primary key violated the integer-PK rule; composite kept as a unique constraint |
| [E37](#e37--demo-storefront-seeder) | 2026-09-04 | `DemoStoreSeeder` idempotently creates demo store, product, variant, DP item and processor | `migrate:fresh` wiped the storefront test data; published pages need a sellable catalogue to test |
| [E38](#e38--data-only-dump-filtering-for-current-schema) | 2026-09-04 | Python script filters stale data-only dumps into `data-only\new` matching the current schema | Old dumps referenced tables/columns that no longer exist after the migration clean-up |
| [E39](#e39--seedrunner-orchestrator) | 2026-09-04 | `SeedRunner` seeder calls core + optional module seeders in one command | A single `php artisan db:seed --class=SeedRunner` is more convenient than remembering every seeder |
| [E40](#e40--builder-canvas-stuck-on-the-loader-when-configuring-ecommerce) | 2026-09-05 | `ensureEditorCommerceReadyCss` — canvas-only `<style>` in `pageTypes.ts` | E21/E24's `:not([data-commerce-ready])` loader has no `data-commerce-ready` in the canvas (published runtime no-ops); spinner never clears |
| [E41](#e41--events-built-into-the-builder-facade-orders--four-view-runtime) | 2026-09-08 | Events decoupled onto `PaymentGateway`; real four-view runtime + chrome + `my-registrations`; `EventsDemoSeeder` | Builder-v2 `03` shipped events as three empty pages + a stub script; the events↔DP coupling was never done. Full record: [05](../05-events-parity.md) |
| [E42](#e42--module-wide-design-themes--brand-colours--editor-preview) | 2026-09-09 | One "Design" picker per bind (events + ecommerce), tenant brand accent, live events canvas preview | One look per view; `brand.primaryColor1` ignored; events canvas showed only a placeholder. Full record: [06](../06-design-themes.md) |
| [E43](#e43--design-picker-for-appointments--demo-seeder) | 2026-09-09 | Same Design picker for the appointments module; `appointmentsChrome.ts` / `appointmentsTemplates.ts`; `AppointmentsDemoSeeder` | Third page-level module was left out of `06`; no seeded demo appointment to verify against |
| [E44](#e44--two-more-designs-vivid--slate--design-footers) | 2026-09-10 | `vivid` + `slate` designs (six total); every design gets a footer band; `[data-design]` token blocks generated from `DESIGN_THEMES` | "add a couple more designs; add footers"; hand-maintained CSS token duplication removed |

Standing constraints that every extra had to respect (and later extras
must still respect):

- Do **not** rewrite the pages lifecycle or change the 25 ms
  `hardReinitFromSnapshot` timing.
- Do **not** write a second compiler. `compileRuntime` → `js` column
  is the only publish path.
- `commerceComponentScript` is serialised by
  `Function.prototype.toString()`. Helpers stay **inside** that
  function. No imports.
- Never import `external/api` source into `external/ui`. UI talks to
  `window.Portal` only.
- Never start the Laravel Vite HMR server. `npm run build` for www
  assets (`NODE_OPTIONS=--max-old-space-size=8192`). UI SDK:
  `npm run build-external-ui-webpack`.
- A change to compiled `js` is invisible on an already-published
  page until the tenant **republishes**. A change to template markup
  (slots, cart icon, View) is invisible until they **re-pick** the
  template (or the canvas is re-stamped). A change to the UI SDK is
  invisible on published tenants until `api-ui` /
  `api-ui-sandbox` is redeployed. Layout (`layout.blade.php`) is
  generic: Inter, footer width, SDK tags, `{!! $ss !!}`, `{!! $pageJs !!}`.
  Storefront CSS is the `css` column; storefront JS is the `js`
  column. Neither is patched in the layout (E24).
- Append the next `E*` here in the same pass as the code. Do not
  leave off-plan work only in chat.

---

## E1 — Compiled JS must run at the end of `<body>`

**Symptom.** The published page had a ~14 KB `js` column and a
storefront that never mounted. `querySelectorAll('[data-afs-module]')`
ran against an empty document.

**Cause.** Schema stores CSS and JS in separate columns so they can
be queried and hashed independently. The first serve wiring put both
in `<head>`. An inline `<script defer>` in `<head>` is **not**
deferred — the HTML spec only applies `defer` to external `src`
scripts. The browser ran the compiler IIFE while parsing `<head>`,
before `<body>` existed.

**What we did.** CSS stays in `<head>` as `{!! $ss !!}`. Compiled JS
is `$pageJs` / `{!! $pageJs !!}` after the page HTML. The compiler
also wraps every emitted body in `__afsBoot` + `DOMContentLoaded` so
a later move back to `<head>` would still wait for the tree.

`BuilderPublishedController::show()`:

```php
// CSS goes in <head>. Compiled JS goes at the end of <body>.
// `<script defer>` in <head> does **not** defer an inline script.
$pageJs = '';
if ($content['js']) {
    $pageJs = '<script nonce="' . $nonce . '">' . $content['js'] . '</script>' . PHP_EOL;
}

return response()->view('index', [
    'html'           => $content['html'],
    'styles_scripts' => $stylesScripts,
    'page_js'        => $pageJs,
    // …
]);
```

`resources/views/components/layout.blade.php`:

```html
<body class="">
    {{ $slot }}
    {!! $pageJs !!}
</body>
```

`resources/js/portal/builder/core/publish/compileRuntime.ts`:

```ts
function asPageScript(body: string): string {
    return `/* generated by the AFS builder — do not edit */
(function () {
  function __afsBoot() {
${body}  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', __afsBoot);
  } else {
    __afsBoot();
  }
})();
`;
}
```

`index.blade.php` must pass `:page-js="$page_js ?? ''"` into
`<x-layout>`. A Blade component does not inherit the calling view’s
data (see E2).

**Do not** put the compiled page script back in `<head>` with
`defer`. That is the bug.

---

## E2 — Blade layout must declare `$afssdk` and `$afssdkui`

**Symptom.** Every published page returned HTTP 500
(`Undefined variable $afssdk`). After declaring `@props(['afssdk'])`,
`$title` broke next — same class of bug, one name further along.

This is the v1 **B8a** finding
([v1 `04-publish-and-serve.complete.md`](../../v1/complete/04-publish-and-serve.complete.md)).
It is recorded here because the UI SDK added a **second** host and
the same trap would have fired again for `$afssdkui`.

**Cause.** `BuilderPublishedController` passed data to `index`.
`index` renders `<x-layout>`. A Blade component receives only the
attributes given to it. Once `@props` is declared at all, undeclared
attributes stop becoming variables and go to `$attributes`.

**What we did.**

1. Layout declares every attribute the parent passes, with defaults
   that match the controller’s non-production values:

```blade
@props([
    'title' => '',
    'ss' => '',
    'pageJs' => '',
    'afssdk' => 'api-sandbox',
    'afssdkui' => 'api-ui-sandbox',
    'cspNonce' => '',
])
```

2. `index.blade.php` passes them through explicitly:

```blade
<x-layout
    :title="$title"
    :ss="$styles_scripts"
    :page-js="$page_js ?? ''"
    :afssdk="$afssdk ?? 'api-sandbox'"
    :afssdkui="$afssdkui ?? 'api-ui-sandbox'"
    :csp-nonce="$cspNonce ?? ''">
```

3. Controller picks both hosts and puts both on CSP
   (`script-src` and `connect-src`):

```php
$afssdkui = env('APP_ENV') === 'production' ? 'api-ui' : 'api-ui-sandbox';
$afssdk   = env('APP_ENV') === 'production' ? 'api' : 'api-sandbox';
$sdkHost   = 'https://' . $afssdk . '.appfactory.studio';
$sdkUiHost = 'https://' . $afssdkui . '.appfactory.studio';
```

4. Layout **always** loads both remote SDKs. Never the local
   `/external/ui/sdk.js` on a published page:

```html
<script src="https://{{ $afssdk }}.appfactory.studio/sdk.js"></script>
<script src="https://{{ $afssdkui }}.appfactory.studio/sdk.js"></script>
```

Tenant sites are clones of www. They do not have
`public/external/ui/sdk.js`. A local script tag that “works” on
`www.test` 404s on every published member site.

CSP nonce (B9) stays. The two inline scripts we emit (compiled `js`
and the layout’s ScrollMagic/GSAP init) get the nonce. An inline
`<script>` that arrived in stored `html` has no nonce and does not
run.

---

## E3 — Published page chrome: readable default

**Symptom.** A published body was Times New Roman on a transparent
canvas. Grapes had already stripped most `style=""` into `#id {…}`
rules that often did not survive the publish snapshot (E8).

**What we did.** The layout already loaded Inter. It now **applies**
it, and gives the storefront a surface and button defaults that lose
to page CSS on the same properties:

```css
html, body {
    background: #fff;
    color: #0f172a;
    font-family: Inter, system-ui, -apple-system, sans-serif;
}
body footer {
    width: 100%;
    box-sizing: border-box;
}
[data-commerce-kind] {
    display: block;
    padding: 1.5rem;
}
[data-commerce-kind] article { background: #fff; }
[data-commerce-kind] .ec-add-btn,
[data-commerce-kind] button {
    font: inherit;
    background: #2563eb;
    color: #fff;
    border: 0;
    border-radius: 6px;
    padding: 0.45rem 0.85rem;
    cursor: pointer;
}
```

The sticky-footer flex experiment is commented out in the same
`<style>` block. Do not revive it without checking the canvas iframe
and published pages together — it fought Grapes’ own body layout.

---

## E4 — Parent slug is an empty Grapes folder

**Symptom.** `/new-page` (the page the tenant named) published as an
empty body — only the script-runner stub. The storefront lived on
`/new-page-products`, `/new-page-cart`, etc.

**Cause.** That is G3 by design. `ensureBoundSubpages` creates the
five children; the parent stays a folder in `pageManager`. The
visitor still types the name they gave the page.

**What we did.** `BuilderPublishedController::contentOrFirstView()`
serves the first-view row’s `html` / `css` / `js` when the parent’s
published HTML is empty. Preference order:

1. `{slug}-products` (ecommerce)
2. `{slug}-list` (events)
3. `{slug}-booking` (appointments)

Same `project_reference_num`, `version`, and `is_active`. If the
parent already has real HTML, it is left alone.

```php
private function contentOrFirstView(LaravelBuilderPublished $page): array
{
    $html = (string) $page->html;
    $css  = (string) $page->css;
    $js   = (string) $page->js;

    if (! $this->isEmptyPublishedHtml($html)) {
        return compact('html', 'css', 'js');
    }

    $fallback = LaravelBuilderPublished::query()
        ->where('project_reference_num', $page->project_reference_num)
        ->where('is_active', true)
        ->where('version', $page->version)
        ->whereIn('subpaths', [
            $page->subpaths.'-products',
            $page->subpaths.'-list',
            $page->subpaths.'-booking',
        ])
        // … products first, then list, then booking
```

This is serve-time only. It does not write the parent row. Republish
still stores an empty parent.

---

## E5 — Cart and order annex tables were missing

**Symptom.** `POST /portal/api/ecommerce/storefront/cart/lines` → 500:

```
Table 'testdb_appfactorystudio.laravel_ecommerce_carts' doesn't exist
```

**Cause.** `2026_04_03_133338_create_laravel_ecommerce_tables` declares
the cart / reservation / order-annex creates, but on databases where
that migration had already been recorded the later `hasTable` guards
skipped the catalogue tables (they existed) and **never ran** the cart
creates. Tax / promotions ALTERs that targeted
`laravel_ecommerce_carts` no-op’d the same way.

**What we did.** New guarded create in www (www owns the shared
schema; members ships none):

`www/database/migrations/2026_09_02_061800_create_ecommerce_cart_and_order_annex_tables.php`

Matching `Schema::create` blocks were removed from the 04_03 file so
a fresh `migrate:fresh` does not create them twice. Columns that the
dead ALTERs would have added (`shipping_method_id`,
`applied_promotion_id`) are on this create, not a second ALTER.

`MigrationOwnershipTest` still fails on a **pre-existing** duplicate
`laravel_ecommerce_variants` in another file. Do not “fix” that as
part of this extra unless asked — it is a different bug.

**Rule this extra re-confirms.** No ALTER-only migrations. Guard every
create with `if (! Schema::hasTable('x'))`. Related tables in one
file.

---

## E6 — `external/api/tsconfig.json` must extend the sibling

**Symptom.** Typecheck / editor resolution against the API SDK
package failed or pointed at the wrong config.

**What we did.** `www/resources/js/external/api/tsconfig.json` now
extends the sibling compile config (the one webpack already uses):

```json
{
  "extends": "./tsconfig.external.json",
  "compilerOptions": {
    "noEmit": true
  },
  "include": ["./**/*.ts", "./**/*.d.ts"],
  "exclude": ["node_modules", "build", "public", "samples"]
}
```

The UI package still must not import this source (E7). Types for
consumers stay `portal.d.ts`, copied into each app.

---

## E7 — Builder UI SDK (`Portal.ui.ecommerce`)

v2’s compile loop still emits `commerceComponentScript` into the `js`
column. That script cannot grow a second copy of cart chrome, toast,
and generic fallback views in every page. Those live in a **second
remote bundle**.

### Shape

| | |
|---|---|
| Source | `www/resources/js/external/ui/` |
| Entry | `main.ts` — attaches `Portal.ui.ecommerce` and calls `install()` |
| Surface | `mount(root)`, `toast(msg)`, `install()` |
| Types | `/// <reference path="../api/portal.d.ts" />` — no import of API source |
| Build | `npm run build-external-ui-webpack` → `public/external/ui/sdk.js` (~44 KiB) |
| Production host | `https://api-ui.appfactory.studio/sdk.js` |
| Non-production host | `https://api-ui-sandbox.appfactory.studio/sdk.js` |

```ts
// resources/js/external/ui/main.ts
function attach(): void {
    const existing = (window as any).Portal || {};
    existing.ui = Object.assign({}, existing.ui || {}, {
        ecommerce: ecommerceUi,
    });
    (window as any).Portal = existing;
    ecommerceUi.install();
}
```

`install()` waits for `Portal.commerce.create`, then:

- toast host
- cart FAB (top-right bag icon; **hidden** when the page already has
  `[data-commerce-cart]` or a `cart-badge` kind)
- click binding on `[data-commerce-cart]` → sibling `-cart` URL
- busy-state on `.ec-add-btn`

`mount(root)` is the fallback renderer for a **placeholder** root
(empty module stamp). After E9 it **does not remount** a designed
tree.

### Two runtimes, one contract

Published `js` (the load-bearing path after republish) is still
`commerceComponentScript`. Order of decisions inside it:

1. Editor canvas → no-op (keep placeholder HTML).
2. No `data-store-ref` → “Configure store reference…”.
3. `looksDesigned(root)` → hydrate in place (E9–E11). **Return.
   Do not call `ui.mount`.**
4. Else if `Portal.ui.ecommerce.mount` exists → remount fallback UI.
5. Else the inlined `initProductGrid` / `initCart` / … fallbacks.

Old compiled JS (pages published before the hydrate gate) still
calls `ui.mount` first. Those pages keep wiping designed HTML until
**republish**. Deploying the UI SDK alone is not enough for them,
though a deployed UI SDK with the same `looksDesigned` gate will
save a page whose compiled JS still delegates to `mount()`.

### What “local UI rebuild” does and does not do

`npm run build-external-ui-webpack` updates `www/public/external/ui/sdk.js`.
Published pages do **not** load that file (E2). Local rebuild is for
inspecting the bundle and for deploying it to `api-ui` /
`api-ui-sandbox`. Until that deploy, sandbox tenants keep the old
`mount()` that replaces the tree.

---

## E8 — Canvas styles vanish on page switch

**Symptom.** Pick a list / grid storefront template. Styles look
correct. Switch to Cart (or any other page) and back: hero, cards,
and footer are unstyled. The HTML is still there.

**Cause (two stacked facts).**

1. Ecommerce templates put almost all styling in `style=""` on the
   nodes. `template.styles` is often **empty**.
2. `extractInlineStylesFromHtml()` only lifts `<style>` **blocks**.
   It does not convert `style=""` into CSS rules.

```ts
// editor/html/inlineStyles.ts — <style> blocks only
html.replace(/<style\b[^>]*>([\s\S]*?)<\/style>/gi, …)
```

While the page is selected, GrapesJS parses the tree and moves those
inline declarations onto `#id { … }` in its CSS store. That is the
real page CSS.

On switch, `saveCurrentState` called `editor.getCss()`. Grapes often
returned only its **protected reset**:

```css
* { box-sizing: border-box; } body { margin: 0; }
```

That string is truthy, so the old “empty CSS → keep previous” fallback
never ran. The snapshot became:

- HTML with `style=""` already stripped (Grapes owns them as `#id`)
- CSS = the two-rule reset

Restore was unstyled. The 25 ms `hardReinitFromSnapshot` delay was
**not** the bug. Do not change it.

**What we did.**

`pages.ts` — treat reset-only as “no real CSS”:

```ts
function isGrapesResetCss(css: string): boolean {
    const compact = css.replace(/\s+/g, '').toLowerCase();
    if (!compact) return true;
    const remainder = compact
        .replace(/\*\{box-sizing:border-box;\}/g, '')
        .replace(/body\{margin:0;\}/g, '');
    return remainder === '';
}
```

`saveCurrentState`:

- If computed CSS is reset-only **and** the page already has real
  styles → keep the previous styles, still write the HTML.
- If computed CSS is reset-only **and** nothing has been captured
  yet → **do not persist**. `getHtml()` has usually already dropped
  the `style=""` attributes the template arrived with. Writing that
  pair would lock in the unstyled snapshot.

`capture()` will not clobber a richer snapshot CSS with empty/reset.

`pageTypes.ts` — 25 ms after ecommerce `setComponents`, if that page
is still selected, `saveCurrentPageState` so Grapes’ converted `#id`
rules are snapshotted. Same interval as `hardReinitFromSnapshot`,
deliberately:

```ts
// Ecommerce templates put rules in style="", which GrapesJS
// moves onto #id selectors after parse. Snapshot that CSS
// (same 25 ms as hardReinit) so a later page switch has
// something to restore — template.styles is empty.
setTimeout(() => {
    if (editor.Pages.getSelected?.()?.id === pageToSave.id) {
        saveCurrentPageState(pageToSave);
    }
}, 25);
```

Tests: `www/resources/js/portal/builder/tests/pages.test.ts`
(reset-only and capture-clobber).

**What we did not do.** Rewrite pages.ts. Change hardReinit timing.
Lift `style=""` in `extractInlineStylesFromHtml` (that would be a
different extra, and it would fight Grapes’ own conversion).

---

## E9 — Published runtime wiped the designed page

**Symptom.** Canvas list template (hero, product rows, footer)
published as a generic card grid. Header and footer gone. The
`html` column in `laravel_builder_published` still had the designed
markup.

**Cause.** After load, `commerceComponentScript` (or
`Portal.ui.ecommerce.mount`) saw `data-commerce-kind="product-grid"`
and **replaced `root.innerHTML`** with `initProductGrid` /
`renderProductGrid`. The compile loop was doing its job. The
runtime treated every stamped root as a mount point.

Placeholders (`afs-page-module-empty`, “module runtime mounts here”)
still need a remount. Designed templates must not.

**What we did.** A designed root is hydrated in place.

```ts
function looksDesigned(el: HTMLElement): boolean {
    const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
    if (!text) return false;
    if (el.querySelector('.afs-page-module-empty')) return false;
    if (/module runtime mounts here|Configure store reference in the builder/i.test(text)
        && !el.querySelector('section, footer, header')) {
        return false;
    }
    return !!(el.querySelector('footer, section, header, h1, h2, h3'));
}
```

Gate (compiled script — this is what republish writes):

```ts
if (looksDesigned(root)) {
    void hydrateDesigned(root, shop, kind, productSlug);
    return;
}

const ui = (window as any).Portal?.ui?.ecommerce;
if (ui && typeof ui.mount === 'function') {
    void ui.mount(root);
    return;
}
// … initProductGrid / initCart / … only for empty stamps
```

The same gate lives in `external/ui/ecommerce/ui.ts` `mount()` so a
future UI-SDK deploy matches. Local webpack rebuild does not change
published pages until `api-ui-sandbox` is deployed (E7).

Designed catalogue hydrate: bind **Add to Cart** and **Buy Now** by
matching the button’s nearest `h1`/`h2`/`h3` to a live product name.
Do not rebuild the card list.

Tests: `compileRuntime.test.ts` asserts the compiled `js` contains
`looksDesigned`, `hydrateDesigned`, and the `if (looksDesigned(root))`
gate.

---

## E10 — Hydrate every designed ecommerce view

E9 fixed Products. Cart, Checkout, My Orders, and confirmation had
the same wipe: designed sections replaced by generic SDK views.
Checkout’s `initCheckout` is the dangerous one — it **replaces the
tree**, including the email field and Complete Order button the
tenant designed.

**What we did.** `hydrateDesigned` branches on `data-commerce-kind`
(the stamp already sets this from `pageSubpage`).

| Kind | Hydrate behaviour |
|---|---|
| `product-grid` | Bind Add to Cart / Buy Now; optionally View (E11) |
| `product-detail` | Slug from `?product=` / `?slug=` first, then `data-product-slug`; update `h1` from live product |
| `cart` | Fill slots; subscribe to `shop.on('cart:updated')` |
| `checkout` | Fill slots; wire existing email + Complete Order; insert card mount + status if missing. **Do not call `initCheckout`.** |
| `my-orders` | Fill `lines` slot, or append a `[data-afs-live-lines]` box |
| `order-confirmation` | Fill `lines` if `?order=` / `?order_ref=` is present |

Slots the templates now emit (`ecommerceTemplates.ts`). Hydrate
falls back to DOM shape when a page was published before the
attributes existed:

| `data-commerce-slot` | Meaning | Fallback if missing |
|---|---|---|
| `lines` | Cart lines / order rows | `section.children[1]`, or the node after `aside h3`, or `aside` |
| `count` | “N items” | none (text left as designed) |
| `total` | Money total | none |
| `badge` | Cart-icon count | hidden when 0 |
| `card` | Direct Payments mount | inserted before the pay button |
| `status` | Checkout status line | inserted next to the pay button |

Checkout email in the templates is `type="email"` so
`bindDesignedCheckout` can find it with
`input[type="email"], input[placeholder*="Email" i]`.

```ts
function findSlot(container: HTMLElement, name: string): HTMLElement | null {
    const marked = container.querySelector('[data-commerce-slot="' + name + '"]') as HTMLElement | null;
    if (marked) return marked;
    if (name !== 'lines') return null;
    const section = container.querySelector('section');
    if (section && section.children.length >= 2) {
        const aside = container.querySelector('aside');
        if (aside) {
            const afterHeading = aside.querySelector('h3')?.nextElementSibling as HTMLElement | null;
            return afterHeading || aside;
        }
        return section.children[1] as HTMLElement;
    }
    return container.querySelector('aside');
}
```

Designed checkout payment flow (abridged): first click of Complete
Order builds the invoice and mounts the card (`data-commerce-slot="card"`);
second click confirms. The designed button and email stay in the tree.

---

## E11 — Cart icon and product View

**Symptom.** Designed pages had no way to reach Cart or a product
detail. The UI SDK’s FAB sat at the bottom and fought the template.
Product cards were dead ends.

**What we did — templates** (`ecommerceTemplates.ts`):

- `cartIconButton()` — bag SVG + `[data-commerce-slot="badge"]`,
  `data-commerce-cart="1"`. Placed absolute top-right in the hero,
  and on cart / checkout / product / orders wrappers
  (`position:relative`).
- Product cards: optional `data-product-slug`. **View** button
  `data-commerce-product="{slug}"` next to Add to Cart.
  `StoreProduct.slug?: string | null`.
- No View button if the product has no slug.

**What we did — compiled runtime** (`commerceComponentScript`):

Sibling URL convention — strip the view suffix, then append the
target:

```ts
function siblingHref(suffix: string): string | null {
    const path = (window.location.pathname || '/').replace(/\/+$/, '') || '/';
    const base = path.replace(/-(products|product|cart|checkout|orders)$/, '');
    if (!base || base === '/') return '/' + suffix;
    return base + '-' + suffix;
}
```

Examples (project slug `new-page`):

| From | To |
|---|---|
| `/new-page-products` | `/new-page-cart` |
| `/new-page` (parent, after E4) | `/new-page-cart` |
| `/new-page-products` + slug `hat` | `/new-page-product?product=hat` |
| already on `*-cart` | cart icon is a no-op |

`ensureCartIcon()`:

- Reuse an existing `[data-commerce-cart]` from the template.
- Otherwise inject a fixed top-right icon (same SVG).
- Click → `siblingHref('cart')` unless already on `-cart`.
- Badge from `shop.cart()` / `cart:updated`. Hidden when count is 0.

`bindProductLinks`:

- View click and card click (not on a nested `button` / `input`) go
  to `{base}-product?product={slug}`.
- Only if `productPageExists()` is true.

`productPageExists()` — `HEAD {base}-product`:

| Result | Meaning |
|---|---|
| `ok` or `405` | Product page exists; show View |
| `404` | Hide View buttons |
| fetch throw (CORS / network) | Optimistic **true** — do not hide View because HEAD failed |

Product-detail hydrate prefers the query string over the editor
preview slug stamped at compile time, so one published Product page
serves every product.

**What we did — UI SDK:**

- FAB restyled as the same bag icon, top-right (not bottom).
- `fab.hidden = hasPageIcon || hasOnPageBadge` so a designed page
  does not show two carts.
- `install()` binds `[data-commerce-cart]` clicks the same way.

Tests: compiled `js` must contain `ensureCartIcon` and
`bindProductLinks` (`compileRuntime.test.ts`). 50 compileRuntime
tests passing at the time this was written.

---

## E12 — Direct Payment is admin-owned, not a builder field

**Date.** 2026-09-02.

v2 P1 put `#page-commerce-dp-select` on the Checkout template
section and V2-D7 refused publish when `pageCommerceDpRef` was
empty. That treated checkout payment as a builder configuration.
It is not. The store already has `laravel_ecommerce_stores.direct_payment`,
set in the Admin dashboard. A tenant who can pick a different DP
in the builder, or bind a store that cannot take money, publishes
a shop that cannot check out.

### What the builder may do

1. **Only payable stores are selectable.**
   `ecommerceInstances.list()` still returns every store (so a
   page already bound to one that lost its DP can be described).
   The Page-panel picker uses `selectableEcommerceStores()` —
   `status === 'active'` only, plus the current binding if it is
   no longer payable.

2. **No Direct Payment select, no Create-one for DP, no checkout
   trait.** The Checkout section is template-only. The retired
   checkout block no longer exposes an `instance-select` on
   `data-dp-ref`.

3. **Read-only notice** under the Store select
   (`#commerce-dp-notice`):

   | Store state | Notice |
   |---|---|
   | none selected | hidden |
   | `direct_payment` set | `Direct Payment: {name}.` + “Manage in Admin” |
   | no `direct_payment` | `No Direct Payment is configured for this store.` + “Configure it in Admin” |

   The name comes from `GET /portal/api/ecommerce/stores` →
   `direct_payment_name` (joined to `laravel_direct_payments.name`).
   Changing the DP is an Admin action on that store.

4. **`pageCommerceDpRef` is synced from the store**, never typed.
   Bind / refill / store-change copies `store.directPaymentRef`
   onto the page and parent so compile can still index
   `direct_payments` in the reverse index. It is not a tenant
   field.

### What compile no longer refuses

Missing `pageCommerceDpRef` is **not** unresolved. A store that
is selectable already has a DP; a store that later loses one is
closed at **runtime**, not by blocking publish of a page the
tenant already shipped.

A synced ref, when present, is still `addRef('direct_payments', dp)`
so Admin delete-refusal still sees the instance.

### Published page — store closed for maintenance

`GET /portal/api/ecommerce/storefront/store?store=` already
returned `is_payable`. It now also returns `direct_payment` when
the store is payable (the live ref, so a DP swap in Admin does
not wait for republish).

`commerceComponentScript` (and UI SDK `mount()`) after
`shop.init()`:

```ts
if (cfg && cfg.is_payable === false) {
    showStoreClosed();
    return;
}
checkoutDpRef = String((cfg && cfg.direct_payment) || dpRef || '').trim();
```

`showStoreClosed()` injects a full-viewport dialog
(`#afs-store-closed`, `role="dialog"`, `aria-modal="true"`):

> **Store closed for maintenance**
> This store cannot accept orders right now. Please check back later.

No close button, no click-outside, no Escape handler. It sits at
`z-index: 2147483646` on `document.body`, so designed header /
footer / cart icon cannot be used to check out around it. The
editor canvas does not show it (`isEditorMode()` still returns
before `waitForPortal`).

Checkout that somehow reaches pay with no live DP calls the same
overlay instead of “Set Direct Payment ref in the builder panel.”

Server-side `beginCheckout` already rejects a non-payable store
(`EC_E_STORE_NOT_PAYABLE`). The overlay is the visitor-facing
half of that.

### Files

| | |
|---|---|
| Stores list + DP name | `app/Http/Controllers/Externals/Ecommerce/EcommerceController.php` `getStores()` |
| Storefront live flag | `app/Http/Controllers/Externals/Ecommerce/EcommerceStorefrontController.php` `getStore()` |
| Instance extras | `resources/js/portal/builder/core/modules/instances.ts` |
| Picker filter + notice copy | `resources/js/portal/builder/core/editor/pageTypes/commerceDpNotice.ts` |
| Page panel wiring | `pageTypes.ts`, `index.blade.php` (notice), `styles.scss` |
| Compile | `compileRuntime.ts` — index DP if synced; do not refuse |
| Published overlay | `commerce_component_script.ts`, `external/ui/ecommerce/ui.ts` |
| Removed checkout DP trait | `commerce_components.ts` |

### Ops

- **Builder store missing from the dropdown** — it has no
  `direct_payment` in Admin, or `status !== active`. Configure
  the store at `/ecommerce/store/{ref}`, then reload the builder.
- **Notice says no Direct Payment** — the page is still bound to
  a store that lost its DP. Published views of that store show
  the maintenance dialog as soon as `shop.init()` runs (no
  republish required for the *live* check). Republish still
  needed to pick up `showStoreClosed` if the compiled `js` is
  old.
- **UI SDK** — deploy `api-ui-sandbox` so old compiled JS that
  still calls `ui.mount()` first also hits the overlay.
- Do not add the DP select back. Do not refuse publish on
  missing `pageCommerceDpRef`.

Tests: `commerceDpNotice.test.ts`, `instances.test.ts` (DP
name), `pageLevel.test.ts` (no refuse), `compileRuntime.test.ts`
(`showStoreClosed` / `is_payable` in compiled `js`).

---

## E13 — Direct Payments save 500 after the row was written

**Date.** 2026-09-02.

**Symptom.** Admin → save a new Direct Payment. The UI shows a
network error. Console:

```
POST https://www.test/portal/api/v1/directpayments 500
Error saving direct payment: SyntaxError: Unexpected token '<', "<!DOCTYPE "... is not valid JSON
```

That JSON parse error is the client reading Laravel’s HTML error
page.

**Cause.** `saveDirectPayment()` persists the row, then calls
`FunctionsController::GetManagedDomain()` to bootstrap the
scheduler cron (billing §3.1). `GetManagedDomain()` lived in
`App\Http\Controllers` and called the **bare** names
`getSubdomain2()` and `get_subdomain_managed_domain_new_way()`.
Those are static methods on the same class. PHP resolved them as
`App\Http\Controllers\getSubdomain2()`, which does not exist.

The DP was already in the table. The 500 was the cron tail.

**What we did.**

```php
public static function GetManagedDomain($dboverride = false)
{
    $subdomain = self::getSubdomain2($dboverride);
    $domain = self::get_subdomain_managed_domain_new_way($subdomain, $dboverride);
    return $domain;
}
```

`__get_main_configurations()` got the same `self::` fix (identical
bug, next door). The cron block in `saveDirectPayment()` is now
try/caught — a later domain/cron failure logs a warning and still
returns `{ success: true }`.

`FunctionsController` still has many other bare `getSubdomain2()`
calls. Those are a different extra; this one only unblocks save.

**Files.** `app/Http/Controllers/FunctionsController.php`,
`app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php`.

**Ops.** Retry the save. No rebuild. No republish. If a row was
created on the failed attempt, this save updates it (same
`reference_num`).

---

## E14 — Published store has no CSS and no product back button

**Date.** 2026-09-02.

**Symptom.** The live published store (`/new-page-products`,
`/new-page-product`) looks like an unstyled document: hero, product
rows, and footer are bare tags. Opening a single product has no
way back to the catalogue.

**Cause.** Grapes lifts template `style=""` onto `#id` rules.
`extractInlineStylesFromHtml` only copies `<style>` blocks.
`template.styles` was empty, so the snapshot often stored only
Grapes' `* { box-sizing }` / `body { margin: 0 }` reset. E8 stops
the canvas from *overwriting* good CSS with that reset, but the
official `products` / `product` rows have been reset-only since
v25. The designed `#id` CSS lives on the `-1` duplicate slugs,
which visitors do not hit. The product template also never had a
back control.

Verified on active version of `projeKEnNosrOA3M`:
`new-page-products` css_len 207 (reset),
`new-page-products-1` css_len 5119 (real `#id` rules);
`new-page-product` css_len 87 (reset), no `[data-commerce-back]`.

**What we did.**

1. Structural chrome (`[data-commerce-kind] section` / list rows /
   product-detail grid / footer / `[data-commerce-back]`) in
   `layout.blade.php` — this is what already-published pages load
   without a republish.
2. The same string as `ecommerceChromeCss`, set on every store
   template's `styles` so the next apply snapshots real CSS.
3. Product template + layout script + `ensureBackLink()` in the
   compiled runtime (and UI hydrate) inject `← Back to store` and
   send it to the sibling `-products` URL.
4. Publish: if the page CSS is reset-only and the page is
   `e-commerce`, persist chrome instead of the reset. Other page
   types persist `''`.

**Files.** `resources/views/components/layout.blade.php`,
`resources/js/portal/builder/core/ecommerceChrome.ts`,
`resources/js/portal/builder/core/ecommerceTemplates.ts`,
`resources/js/portal/builder/core/component_scripts/commerce_component_script.ts`,
`resources/js/portal/builder/core/editor/publish/publish.ts`,
`resources/js/portal/builder/core/editor/pages/pages.ts` (export
`isGrapesResetCss`), `resources/js/external/ui/ecommerce/ui.ts`.

**Ops.** Layout chrome and the Back injector were live on the next
page load — no republish. **E24 removed that layout copy.** Vite
`npm run build` then republish so `css` contains chrome and `js`
contains `ensureBackLink`. Re-pick the store template to stamp
Back into the HTML. Do not change the 25 ms `hardReinitFromSnapshot`
timing.

---

## E15 — Direct Payment items are a paginated API

**Date.** 2026-09-03.

**Symptom.** The Direct Payments admin form loaded every item with
the DP row. Saving the DP rebuilt the whole item set from the
request body, so a paginated or partial save wiped items that were
not on the page. The ecommerce product form could assign an item
but could not search or edit it without opening Direct Payments.

**Cause.** `GET /portal/api/v1/directpayments` embedded `items`.
There was no item collection URL, no cursor, no single-item PUT.
Ecommerce diagnostics also compared leftover catalogue
`price_cents` to the DP item and warned `store $24.99 vs DP $5.00`
even though checkout already charged the DP item.

**What we did.**

1. Items are their own collection. `GET /directpayments` no longer
   embeds them unless `?include_items=1`.
2. Cursor-paginated CRUD:

   | Method | Path |
   |---|---|
   | GET | `/portal/api/v1/directpayments/{dpRef}/items` (`q`, sort, cursor) |
   | GET | `/portal/api/v1/directpayments/{dpRef}/items/{itemRef}` |
   | POST | same prefix |
   | PUT | same prefix |

   `saveDirectPayment` only rebuilds items if the request still
   sends `items`.

3. Admin DP form: Tabler table (name, date created, price, item
   ID, Edit), 500px scroll, header sort, infinite load. Shared
   Bootstrap modal `#dpItemCreateModal` + `#dpItemTemplate` for
   add/edit on both the DP form and the ecommerce product form.
   Product form “New item” / “Edit item” searches the collection
   with `q=`.
4. Indexes on `laravel_direct_payment_items` (named; MySQL 64-char
   cap): `ldpi_dp_created_id_idx`, `ldpi_dp_name_id_idx`,
   `ldpi_dp_price_id_idx`. Added on `Schema::create` for **fresh**
   installs. Existing databases do **not** get them from `migrate`
   (no ALTER-only migration — AGENTS.md §5). Local was hand-indexed.
5. Diagnostics: mapped products are never a “store vs DP” mismatch.
   Only **unmapped** variants warn. Price lives on the DP item.

**Files.** `app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php`,
`routes/portal.php`, admin DP + ecommerce product TS/HTML,
`app/Externals/Ecommerce/Services/EcommerceDirectPaymentService.php`
(`diagnoseVariantMapping`).

**Ops.** Admin Vite `npm run build` (heap flag). Indexes on an
existing DB are a DBA `CREATE INDEX` if sort/filter is slow; do
not ship an ALTER migration.

---

## E16 — Publish prices without republishing the site

**Date.** 2026-09-03.

**Symptom.** Changing a mapped Direct Payment item’s price did not
update the published storefront. The operator had to republish the
Grapes site. That is the wrong publish: the site HTML is a design
snapshot; the price is a live commerce value.

**Cause.**

1. Designed / published HTML **bakes** `$5.00` (often `$0.00` from
   a template demo) into a `<span>` / `<p>` with **no** class.
2. Editing a DP item does not rewrite `laravel_ecommerce_products.price_cents`
   / variant `price_cents` unless the product is saved again.
   Those columns are a leftover copy, not the charge source.
3. Storefront `serializeProduct` used to return the catalogue copy.
4. Published pages load **remote** `https://api-sandbox.appfactory.studio/sdk.js`,
   not `public/external/api/sdk.js`. A new `Portal.commerce` method
   is invisible on tenants until that SDK is deployed.

Checkout already charged the DP item. Display did not.

**Contract (externals API owns the update; UI reacts).**

```
Admin “Publish prices”
  → POST /portal/api/ecommerce/store/{ref}/publish-prices
  → copy live DP cents onto product/variant price_cents
  → bump laravel_ecommerce_price_publications.revision
GET  /portal/api/ecommerce/storefront/prices?store=
  → { revision, published_at, currency, prices: [{ product_ref, product_name, slug, variant_ref, item_ref, price_cents, price }] }
Portal.commerce.create(store).prices()
  → that GET
Portal.commerce on('prices:updated', catalog)
  → revision changed
Portal.ui.ecommerce.applyPrices(root, catalog)
  → paint `.price`, `.product-price`, `[data-commerce-price]`, else first `$…` under a matching heading
```

`getStore` also returns `prices_revision` / `prices_published_at`.
`products()` / `product()` serialize with
`resolveProductPriceCents` / `resolveVariantPriceCents` (DP item
wins when mapped and not DYNAMIC). Cart unit price uses the same
resolver.

**Admin.** **Publish prices** on the store dashboard Overview card
and on the products list. Not a Grapes publish.

**Already-published pages (no Grapes republish, no sandbox SDK).**
Inline script in `layout.blade.php` (same pattern as E14 chrome):
find `[data-commerce-kind][data-store-ref]`, GET `/prices`, paint.
One watcher **per store** (E17). First load always paints; later
loads only if `revision` changed.

**Templates (next Grapes publish only).** Price spans in
`ecommerceTemplates.ts` now carry `data-commerce-price`.

**Schema.** `laravel_ecommerce_price_publications` —
`2026_09_03_050000_create_ecommerce_price_publications_table.php`
(`hasTable` guard, `store_reference_num` unique, `revision`,
`published_at`). New create, not an ALTER. Ran locally. Production
gets it on `php artisan migrate` in **www**.

**Verified locally (demo-store-test).** DP item `xuZdvvf8oth`
Test T-Shirt **$5.00**; catalogue leftover **$24.99**; baked HTML
**$0.00**. After paint, `/new-page-products` and
`/new-page-product?product=test-t-shirt` showed **$5.00**.

**Files.** `EcommerceDirectPaymentService.php` (`livePriceRows`,
`publishedPriceCatalog`, `publishPrices`, resolvers),
`EcommercePricingService.php`, `EcommerceStorefrontController.php`,
`EcommerceController.php` (`publishPrices`),
`app/Models/EcommercePricePublication.php`, the migration,
`resources/js/external/api/commerce/api.ts`,
`resources/js/external/api/portal.d.ts` (`prices()`,
`PortalCommercePriceCatalog`, `applyPrices`),
`resources/js/external/ui/ecommerce/ui.ts`,
`commerce_component_script.ts` (hydrate calls `applyPrices` +
`prices:updated` via `window.Portal` only — no import of externals
source), `ecommerceTemplates.ts`, `commerce-store.ts` /
`commerce-products.ts` + HTML, `layout.blade.php`.

**Ops.** PHP + layout paint are live without a Grapes republish.
`npm run build-external-webpack` and `build-external-ui-webpack`
for local `public/external/{api,ui}/sdk.js`. Admin Vite `npm run build`
for the Publish buttons. **Remote sandbox SDK must be deployed**
before `Portal.commerce.prices()` exists on published tenants; until
then the layout script is the paint path. Do not import
`www/resources/js/external/api/**` into a consuming app.

---

## E17 — Price poll blew the storefront throttle

**Date.** 2026-09-03.

**Symptom.** `/new-page-products` 429 Too Many Requests on
`GET .../storefront/store` and `.../prices`. Console:

```
[Commerce component] Could not load store. Error: Too many requests.
    at CommerceInstance.fetchJson (sdk.js)
    at async CommerceInstance.init
```

After the quota was spent, even a hard refresh failed `init()`.

**Cause.** `EcommercePublicThrottle` was **120 hits / 3600s** per
IP+store for **every** storefront route, including GET catalogue.
E16’s first paint watcher used `setInterval(..., 15000)` **per**
`[data-commerce-kind][data-store-ref]` root. A products page with
grid + cart-badge is two pollers. 15s × 2 = 8/min = 480/hour,
before `init` / `products` / `cart` / `prices()` on load. One open
tab exhausted the bucket; `init()` then 429’d.

**What we did.**

1. Split the throttle:

   | Kind | Cache key prefix | Max / hour |
   |---|---|---|
   | GET / HEAD | `ecommerce-public-read:` | 600 |
   | writes | `ecommerce-public-write:` | 120 |

   429 includes `Retry-After`. Old `ecommerce-public:` keys are
   unused (so a filled old bucket does not block the new read
   bucket).

2. Layout: **one** poller per store, **60s**, skip `document.hidden`,
   20s debounce, exponential backoff on 429 (cap 5 min). If
   `Portal.commerce.create(store).prices` exists, layout paints
   once and lets the SDK watch.
3. `CommerceInstance.init()` is idempotent (second caller does not
   re-GET `/store`). SDK watch uses the same 60s / hidden / backoff
   rules (`setTimeout` chain, not `setInterval`).

**Files.** `app/Http/Middleware/EcommercePublicThrottle.php`,
`resources/views/components/layout.blade.php`,
`resources/js/external/api/commerce/api.ts`.

**Ops.** Hard-refresh published pages so they drop the 15s script.
Local `sdk.js` rebuild does not reach tenants until sandbox
deploy; layout + PHP throttle are enough. Do not add another
interval against `/storefront/*` without checking the 600 GET
budget. Cache driver is `database` (`cache` table,
`CACHE_PREFIX=laravel_cache`).

---

## E18 — No catalogue price; unmapped products are not in the store

**Date.** 2026-09-03.

**Symptom.** Test Mug and Test Sticker Pack showed the leftover
store `price_cents` ($12.99 / $5.99) on the published grid. Admin
warned `not mapped to a DP item` but still listed those amounts.
There was a readonly `$` field on the product form that save still
posted as `price`.

**Cause.** `resolveVariantPriceCents` / `resolveProductPriceCents`
fell back to `effectivePriceCents()` / `product.price_cents` when
no Direct Payment item was mapped. Storefront `getProducts`
returned every `active` product. Designed Grapes HTML still baked
the extra cards.

**Rule.** The Direct Payment item **is** the price. There is no
store price to set. An unmapped product is not for sale and must
not appear in the store (explicit or dynamic).

**What we did.**

1. Resolvers return `null` when there is no mapped item, in both
   explicit and dynamic mode. Dynamic mode still uses leftover
   catalogue cents as the amount for a *mapped* DYNAMIC item.
2. `GET /storefront/products` omits unmapped products.
   `GET /storefront/products/{slug}` 404s. `/prices` rows skip
   them. Add-to-cart / reprice / merge refuse `EC_E_ITEM_UNMAPPED`.
3. `saveProduct` ignores client `price` / `price_cents`. If an
   item is mapped, it copies that item’s amount onto the leftover
   `price_cents` column (for publish-prices). If not, it writes 0.
4. Product form: the `$` input is gone. Hint shows the DP item
   amount. Save requires an item. Admin cards show
   `Not mapped — hidden from store` instead of a fake dollar
   amount. The bulk “Create DP items for unmapped” button is gone
   (it used to copy catalogue cents into a new item).
5. Layout + UI `applyPrices` hide designed cards whose title is
   not in the price catalog (Add to Cart + `$` ancestor), so
   already-published grids drop mug/sticker without a Grapes
   republish. Hide the **parent** of the text column when that
   parent has exactly one product heading, otherwise the image
   placeholder stays as an empty card.

6. Store form no longer offers “catalogue only”. A store with no
   Direct Payment cannot sell. `saveProduct` does not accept a
   client `price` / `compare_at_price_cents`. Unmapped is hidden
   in dynamic mode as well as explicit.

```php
$cents = $this->resolveVariantPriceCents($variant, $product, $store);
if ($cents === null) {
    // not in the store
}
```

**Files.** `EcommerceDirectPaymentService.php`,
`EcommercePricingService.php`, `EcommerceCartService.php`,
`EcommerceCartMergeService.php`,
`EcommerceStorefrontController.php`, `EcommerceController.php`
(`saveProduct`, `serializeAdminProduct`),
`commerce-products.ts` / `commerce-product-form.html`,
`commerce-store.ts`, `layout.blade.php`, `ui.ts`,
`commerce_component_script.ts`,
`tests/Unit/Ecommerce/EcommerceDirectPaymentServiceTest.php`.

**Ops.** PHP + layout hide are live on hard-refresh. Admin form
needs `npm run build`. UI webpack for `applyPrices` hide on
`api-ui(-sandbox)`. Compiled `js` hide is next Grapes republish;
layout already hides. Map mug/sticker to DP items (New item on
the product form) if they should sell.

---

## E19 — Zero DP item skips the processor and reports success

**Date.** 2026-09-03.

**Symptom.** A Direct Payment item priced at `$0` still needed a
processor charge. Ecommerce checkout would fail Stripe's `min:50`.
Events and appointments short-circuited *before* Direct Payments
when their own `price_cents` was 0, so listeners never saw the
same success event as a paid purchase.

**Cause.** Money lived in two places. Modules decided “free vs
paid” from catalogue cents and skipped the DP invoice. The
processor path had no $0 completion.

**Rule.** Direct Payments owns the charge. Listeners (ecommerce,
events, appointments) wait for success or failure. If the invoice
total is `$0`, DP does not call the processor; it marks the
invoice `succeeded` (`charge_id=zero_item`) and emits
`payment:succeeded`.

**What we did.**

1. `POST /portal/api/v1/directpayments/invoice/complete-zero`
   succeeds only when `amount_cents === 0`. Non-zero →
   `DP_E_NOT_ZERO`.
2. SDK `setupSinglePayPayment` / `confirmPayment` / `charge()`
   take that path for a zero invoice — no Stripe/PayPal mount.
3. Appointments and events with a Direct Payment configured always
   create an invoice from the **mapped item** (including $0). They
   no longer auto-create items from catalogue prices. Unmapped →
   `AP_E_ITEM_UNMAPPED` / `EV_E_ITEM_UNMAPPED`. Confirm requires
   invoice `paid`/`succeeded`.
4. Events storefront: `beginPayment` + `confirm-payment`, same
   shape as appointments.

```js
await dp.setupSinglePayPayment({ invoice, onSuccess, onError });
const result = await dp.confirmPayment({ email, invoice });
// $0: result.success, payment:succeeded, no processor
```

**Files.** `DirectPaymentsController.php` (`completeZeroInvoice`),
`routes/portal.php`, `direct_payments/api.ts`,
`AppointmentPaymentService.php`, `AppointmentBookingService.php`,
`AppointmentStorefrontController.php`, `EventPaymentService.php`,
`EventRegistrationService.php`, `EventStorefrontController.php`,
`events/api.ts`, `portal.d.ts`, playgrounds,
`tests/Feature/DirectPayments/DirectPaymentZeroInvoiceTest.php`.

**Ops.** PHP is live on hard-refresh. Published pages load remote
`api-sandbox` SDK — deploy `npm run build-external-webpack` for
the client $0 skip. Until then, a $0 ecommerce checkout still
needs that SDK; the complete-zero endpoint is already on this
machine.

---

## E20 — Admin dashboard home is wired to the modules

**Symptom.** The portal home screen (`#dashboard`) showed zeros, an
empty products table, and “No upcoming events” even when the tenant
had products, events, discounts, and form submissions.

**Cause.** `views.ts` registered the dashboard subview with an empty
listener. `initDashboardHome` existed but was never called, and it
read `DataStore` (localStorage / ecommerce cache filled only after
visiting a store). There was no tenant-scoped summary API.

**What we changed.**

1. `GET /portal/api/v1/dashboard` (authenticated) returns totals and
   lists scoped to the caller’s websites: product count, last 5
   products by `created_at`, published events, tickets sold
   (`confirmed` / `checked_in` / `paid`), active store promotions +
   event promo codes, form submission count, next 5 upcoming
   occurrences.
2. Dashboard listener calls `initDashboardHome`, which fetches that
   endpoint. View All goes to `ecommerce:products` / `events`. New
   **Forms Submitted** card opens `forms:init`. Product price is the
   Direct Payment item, or “Not mapped”.

```json
GET /portal/api/v1/dashboard
{
  "success": true,
  "totals": {
    "products": 3,
    "active_events": 1,
    "tickets_sold": 0,
    "active_discounts": 0,
    "forms_submitted": 4
  },
  "recent_products": [],
  "upcoming_events": []
}
```

**Files.** `AdminDashboardController.php`, `routes/portal.php`,
`resources/js/portal/admin/views.ts`, `views/dashboard.ts`,
`html/views/dashboard-home.html`, `utils/permissions.ts`,
`tests/Feature/Admin/DashboardRouteTest.php`.
`forms_submitted` is in `i18n/{en,es,fr,de,zh}.json` so localizeDOM
does not replace the card label with the raw key.

**Ops.** PHP is live on hard-refresh. The home UI is in the admin
Vite bundle — `npm run build` in www, then hard-refresh
`/portal/admin`. Do not start the Laravel Vite HMR server.
Do not send people to `/portal/admin1` — that is the site-user
entry, not the member admin portal.

---

## E21 — Storefront lists only sellable products with a loader

**Symptom.** Published store pages first showed every baked Grapes
card at `$0.00`, then a couple of seconds later hid the unmapped
ones and painted the real price. The remaining **Add to Cart**
button could disappear. The client downloaded the whole catalogue
and decided visibility in the browser.

**Cause.** The published HTML is the full canvas snapshot. Layout
and the compiled runtime fetched `/prices` (or all `/products`)
and walked the DOM to hide leftovers. `GET /products` loaded every
active row, then filtered in PHP. Image URLs rode along in that
payload, so the first paint waited on a fat JSON.

**What we changed.**

1. `GET /portal/api/ecommerce/storefront/products?store=` requires
   a store, SQL-filters to products with a real Direct Payment
   item (product or variant), then still applies
   `isStorefrontVisible`. List JSON omits `images` unless
   `include_images=1`; `has_images` is set. New
   `GET …/products/{slug}/images`.
2. Layout CSS hides the whole product grid until
   `data-commerce-ready`. A loading label shows instead of the
   baked `$0.00` catalogue. Layout JS fetches **products first**
   (not `/prices`), shows only matching cards with their DP
   prices, keeps **Add to Cart** visible, then fills images.
3. SDK `products()` default has no images; `productImages()` is
   the follow-up. Compiled runtime and UI SDK sync whole cards,
   not a walk that can swallow Add to Cart.

```http
GET /portal/api/ecommerce/storefront/products?store=demo-store-test
GET /portal/api/ecommerce/storefront/products/test-t-shirt/images?store=demo-store-test
```

**Files.** `EcommerceStorefrontController.php`,
`EcommerceDirectPaymentService.php` (`storefrontListedQuery`),
`routes/portal.php`, `layout.blade.php`, `ecommerceChrome.ts`,
`commerce/api.ts`, `portal.d.ts`, `ui/ecommerce/ui.ts`,
`commerce_component_script.ts`.

**Ops.** PHP + layout CSS/JS are live on hard-refresh of the
published page — no Grapes republish for the loader. Compiled
runtime / UI SDK need a republish and
`npm run build-external-webpack` + `build-external-ui-webpack`
for remote `api-sandbox` / `api-ui-sandbox`. Do not start the
Laravel Vite HMR server.

---

## E22 — Cart qty, inventory holds, and storefront availability

**Symptom.** The published cart showed “Qty N” with no way to
change it or remove a line. Products had no sellable quantity,
no disable / hide / sold-out modes, and adding to cart did not
hold stock.

**What we changed.**

1. **API owns counts.** Add / update / remove sync a cart hold
   (`laravel_ecommerce_stock_reservations`) for
   `store.cart_hold_minutes` (default 60). After expiry the line
   stays in the cart; the hold is released. Checkout
   `repriceLines` reports `out_of_stock` without silently
   reducing qty. Storefront `describeProduct` reports
   `listed` / `purchasable` / `availability`
   (`available` | `disabled` | `sold_out` | `hidden`).
2. **Three listing modes** on the product (`status`):
   **active** (buyable), **disabled** (shown, not purchasable),
   **hidden** (not in `/products`). Sold out is derived: tracked
   qty minus unexpired holds ≤ 0. Raising qty in admin re-enables
   it. Blank qty = unlimited (`track_inventory` false).
3. **Show quantity** is a store flag, default off. The API always
   returns `available_quantity`; the UI paints “N left” only when
   `show_quantity` is true.
4. **UI only paints.** Designed cart gets − / qty / + / Remove
   that call `shop.updateLine` / `shop.removeLine`.
   `inventory:updated` is emitted next to `cart:updated`. Layout
   JS does the same on already-published pages.

```http
GET  /portal/api/ecommerce/storefront/products?store=demo-store-test
PATCH /portal/api/ecommerce/storefront/cart/lines/{id}?store=…
DELETE /portal/api/ecommerce/storefront/cart/lines/{id}
```

**Files.** `EcommerceInventoryService.php`, `EcommerceCartService.php`,
`EcommerceStore.php`, `EcommerceVariant.php`,
`EcommerceStorefrontController.php`, `EcommerceController.php`,
create + `2026_09_03_150000_add_ecommerce_inventory_storefront_columns.php`,
`EcommerceExpireHolds.php`, `routes/console.php`, admin product /
store views, `commerce/api.ts`, `portal.d.ts`, `ui/ecommerce/ui.ts`,
`commerce_component_script.ts`, `layout.blade.php`.

**Ops.** `php artisan migrate` for the store columns. Admin
`npm run build`. Layout cart/availability is live on hard-refresh.
Compiled runtime / UI SDK need a republish and
`build-external-webpack` + `build-external-ui-webpack` for remote
sandboxes. Cron: `ecommerce:expire-holds` every 5 minutes. Do not
start the Laravel Vite HMR server.

---

## E23 — Cart badge stays stale and cart page flashes baked lines

**Symptom.** Removing a line did not update the cart icon on other
pages. Opening the cart first showed every baked Grapes product,
then the real lines, and the total read `$NaN`.

**Cause.** The cart template was a snapshot of the catalogue. Layout
hid product grids until `/products` returned (E21) but left the
cart visible. The badge only updated if the compiled runtime found
`[data-commerce-slot="badge"]` and called `shop.cart()` — Grapes
often strips that attribute, and Back restores a bfcache copy.

**What we changed.** Layout fetches
`GET /portal/api/ecommerce/storefront/cart?store=` on every
storefront page, paints the icon (creating the badge if missing),
and hides cart/checkout until that JSON is applied. Totals use
finite cents only. `pageshow` refetches so Back is not stale.
New publishes bake an empty cart, not the catalogue.

**Ops.** Hard-refresh published cart and products — layout CSS/JS
is live, no Grapes republish. Republish only to stop baking
catalogue rows into the template HTML. **Superseded by E24:** that
layout path is gone; badge/cart paint now requires a republish so
the `js` column has the compiled runtime.

---

## E24 — Storefront CSS/JS go through Grapes, not the layout

**Date.** 2026-09-03.

**Symptom.** Storefront chrome and cart/catalogue scripts were
injected on every published page via `layout.blade.php`. That file
is the shell for forms, events, about, and the store. The Grapes
path already owns CSS (`template.styles` → `editor.setStyle()` /
`CssRule.group` → page `css` column → `{!! $ss !!}`) and JS
(component `script` → `compileRuntime` → page `js` column →
`{!! $pageJs !!}`). Putting a second copy in the layout ran
ecommerce behaviour on non-store pages and hid the real publish
loop.

**Cause.** E14/E21/E23 used the layout as a no-republish patch
because official slugs often stored only Grapes' box-sizing reset.
That was a workaround, not the CSS/JS API.

**What we changed.**

1. Layout keeps only generic bits: Inter, footer width,
   ScrollMagic/GSAP for `[data-scroll-target]`, SDK script tags,
   `{!! $ss !!}`, `{!! $pageJs !!}`. The `[data-commerce-kind]`
   style block and the inline storefront script are gone.
2. Chrome stays `ecommerceChromeCss` on every store template.
   Applying a store template now opens the same Grapes
   `templateCss.setGroup('template-ecommerce-{id}')` window the
   template picker uses, then `applyStyles` → `editor.setStyle()`.
3. `saveCurrentState` and publish **merge** chrome onto ecommerce
   pages when Grapes dumped the reset or `#id` rules without
   `[data-commerce-kind]`. Other page types still persist `''` for
   a reset-only dump.
4. Cart badge, qty/remove, sold-out, back-link, `pageshow` refetch
   live only in `commerceComponentScript` (compiled into `js`).
   Checkout hydrate also listens for `cart:updated`.

**Files.** `resources/views/components/layout.blade.php`,
`resources/js/portal/builder/core/ecommerceChrome.ts`
(`withEcommerceChrome`),
`resources/js/portal/builder/core/editor/pages/pages.ts`,
`resources/js/portal/builder/core/editor/publish/publish.ts`,
`resources/js/portal/builder/core/editor/pageTypes/pageTypes.ts`,
`resources/js/portal/builder/core/editor/index.ts`,
`resources/js/portal/builder/core/component_scripts/commerce_component_script.ts`.

**Ops.** Vite `npm run build` in www, then **republish** the store
pages. Already-published rows lose layout chrome/JS on the next
load until that republish writes chrome into `css` and the runtime
into `js`. Re-pick the store template only if the canvas never
received `template.styles`. Do not put storefront CSS or JS back
into the layout. Do not change the 25 ms `hardReinitFromSnapshot`
timing. Do not start the Laravel Vite HMR server.

---

## E25 — Add to Cart sticks on “Adding…”

**Date.** 2026-09-03.

**Symptom.** Clicking Add to Cart on the published storefront
changes the label to “Adding…” and nothing else happens. Cart
stays empty.

**Cause.** After E24 the layout no longer calls `addToCart`. The
remote UI SDK still has a capture-phase listener that sets
`disabled` and “Adding…” on `.ec-add-btn`. The compiled
`bindDesignedAddToCart` then did `if (btn.disabled) return` and
never POSTed `/cart/lines`. `shop.addToCart` itself works — a
manual call from the same page added the line.

**What we changed.** The compiled handler treats `data-afs-busy`
as in-flight, not sold-out, and clears the busy state if there is
no product / variant. UI bind does the same and no longer disables
the button in capture. `install()` also binds designed add buttons
so a later UI SDK deploy does not depend on compiled hydrate.

**Files.** `commerce_component_script.ts`,
`resources/js/external/ui/ecommerce/ui.ts`.

**Ops.** Vite `npm run build` in www, then **republish** so `js`
contains `clearAddBusy`. That is what www.test needs — it loads
remote `api-ui-sandbox`, not local `ui.ts`. `build-external-ui-webpack`
only helps after that sandbox host is redeployed. Do not put the
add call back in `layout.blade.php`.

---

## E26 — Save for later, favorites, and qty icons

**Date.** 2026-09-03.

**Symptom.** Three things at once:

1. Cart **increase / decrease** were blank white squares. No
   minus, no plus — just a button.
2. A shopper could not park a cart line and come back to it.
   There was no store setting for that, no list under the cart,
   and no API.
3. There was no favorites list. No store flag, no heart on a
   product, no icon next to the cart, and no ecommerce page in
   the builder for it.

None of this is in the v2 numbered plan. Cart qty (E22) shipped
unicode `−`/`+`. Shopping lists were never a plan step.

**Cause (qty icons).** After E24, chrome lives in
`ecommerceChromeCss` and paints every
`[data-commerce-kind] button` as `background:#2563eb; color:#fff`.
The qty controls then set `background:#fff` inline and left the
glyph as a unicode minus/plus with **no color**. Inherited text
was white on white. Remove was readable (red on pink). The ±
buttons were not.

**What we changed.**

1. **Qty glyphs are SVG, not unicode, and they set their own
   color.** Compiled `cartLinesMarkup` (and the UI SDK mirror)
   paints minus/plus as 14px paths with
   `color:#0f172a; display:inline-flex`. Chrome adds a more
   specific rule for `[data-commerce-qty]`, `[data-commerce-remove]`,
   `[data-commerce-save-later]`, `[data-commerce-move-saved]`,
   `[data-commerce-remove-saved]`, and `[data-commerce-favorite]`
   so the generic blue/white `button` rule cannot wipe them
   again. Do not put this back in `layout.blade.php`.

2. **Two store flags, both default off.**
   `laravel_ecommerce_stores.allow_save_for_later` and
   `allow_favorites`. They are on the original ecommerce
   `Schema::create` **and** on the `hasColumn`-guarded
   `2026_09_03_210000_add_ecommerce_save_later_and_favorites.php`
   (same pattern as E22's inventory columns). Admin Inventory
   card loads and PATCHes them with hold minutes / show quantity.
   `EcommerceStore::allowsSaveForLater()` /
   `allowsFavorites()`. Storefront `GET /store` and every
   serialized cart carry both flags. UI hides the controls when
   the flag is off; the API refuses with `EC_E_SAVE_LATER_DISABLED`
   / `EC_E_FAVORITES_DISABLED`.

3. **Save for later is cart-scoped.** Table
   `laravel_ecommerce_saved_for_later`: `cart_id`, `variant_id`,
   `quantity`, `custom_attributes`, unique
   `(cart_id, variant_id)` as `ec_saved_later_cart_variant_uq`.
   Saving a line copies it (or adds qty if that variant is
   already saved) then `removeLine` so the hold is released.
   **Move to cart** calls `addLine` and deletes the saved row;
   if add fails (sold out / unmapped) the saved row stays.
   Cart merge rematches guest saved rows onto the signed-in
   cart. Serialized cart includes `saved_for_later[]`.

4. **The accordion is under the cart, not a new page.** Cart
   template bakes
   `<details data-commerce-saved>` with
   `data-commerce-slot="saved"` and `saved-count`. Compiled
   `paintSavedAccordion` fills it, hides the whole
   `<details>` when the flag is off, and **creates** the
   accordion if an older published cart has no slot — so a
   republish of `js` is enough; re-picking the cart template
   is only for the designed markup.

5. **Favorites are shopper-scoped, not cart-scoped.** Table
   `laravel_ecommerce_favorites`: `store_reference_num`,
   `shopper_key`, `product_id`, unique
   `(store, shopper_key, product_id)` as
   `ec_fav_store_shopper_product_uq`.
   `shopper_key` is `user:{site_user_id}` when signed in,
   else `token:{ec_cart_token}`. Favoriting with no cookie
   yet **creates** a token (same `EcommerceCartCookie`) so a
   guest list survives reloads without requiring a cart row.
   Listing as a signed-in user copies any `token:…` rows onto
   `user:…`. Guests work; the Favorites subpage does **not**
   default `pageAuth` (My Orders still does).

6. **Builder ecommerce page list is now six views.**
   `ecommerce.module.ts` `subpages` gained `favorites` after
   `orders`. `SUBPAGE_LABELS.favorites = 'Favorites'`,
   `COMMERCE_KIND.favorites = 'favorites'`. `pageTypes.ts`
   applies template `favorites`. Template is the same chrome
   + slot shape as My Orders (`data-commerce-slot="lines"`).
   `ensureBoundSubpages` creates the child on the next store
   bind or publish — existing projects do not get the page
   until then. Tests that hardcoded five views now expect six;
   Favorites is not given `pageAuth`.

7. **Favorites chrome sits next to the cart icon.** Templates
   wrap both in `data-commerce-chrome-icons` (heart left, cart
   right). Runtime `ensureFavoritesIcon` unhides the template
   button when the flag is on, or plants a fixed FAB to the
   left of the cart FAB if the HTML has no icon. Click goes to
   the sibling `*-favorites` URL (same `-(products|product|cart|checkout|orders|favorites)$`
   strip as the cart). Product cards / detail get a heart
   (`data-commerce-favorite`) injected next to Add to Cart;
   `aria-pressed` + fill when the product is in the list.

8. **UI only paints. Compiled JS does not wait on the remote
   SDK.** `portal.d.ts` and `commerce/api.ts` declare
   `saveLineForLater` / `moveSavedToCart` / `removeSaved` /
   `favorites` / `addFavorite` / `removeFavorite` and
   `favorites:updated`. www.test still loads remote
   `api-sandbox`, which does not have those methods until that
   host is redeployed. Compiled `commerceComponentScript`
   therefore has `commerceFetch` fallbacks onto the storefront
   routes and re-paints the cart itself when the shop method is
   missing. After republish, save-for-later and favorites work
   without an SDK deploy. `ui.ts` is the same paint path for
   when `api-ui-sandbox` *is* redeployed; it is not what
   www.test runs today.

```http
GET    /portal/api/ecommerce/storefront/store?store=…
GET    /portal/api/ecommerce/storefront/cart?store=…
POST   /portal/api/ecommerce/storefront/cart/lines/{lineId}/save-for-later?store=…
POST   /portal/api/ecommerce/storefront/cart/saved/{savedId}/move-to-cart?store=…
DELETE /portal/api/ecommerce/storefront/cart/saved/{savedId}?store=…
GET    /portal/api/ecommerce/storefront/favorites?store=…
POST   /portal/api/ecommerce/storefront/favorites?store=…   { "product_ref": "slug-or-ref" }
DELETE /portal/api/ecommerce/storefront/favorites/{productRef}?store=…
PATCH  /portal/api/ecommerce/store/{reference_num}          { allow_save_for_later, allow_favorites, … }
```

Cart JSON now includes `allow_save_for_later`, `allow_favorites`,
and `saved_for_later[]`. Flag-off favorites GET returns
`EC_E_FAVORITES_DISABLED`. Flag-on with no rows returns
`{ success: true, favorites: [] }`.

**Files.**

| Area | Path |
|---|---|
| Create columns (fresh install) | `database/migrations/2026_04_03_133338_create_laravel_ecommerce_tables.php` |
| hasColumn / hasTable path | `database/migrations/2026_09_03_210000_add_ecommerce_save_later_and_favorites.php` |
| Store flags | `app/Models/Externals/EcommerceStore.php` |
| Saved / favorite rows | `app/Models/Externals/EcommerceSavedForLater.php`, `EcommerceFavorite.php`; `EcommerceCart::savedForLater()` |
| Lists + identity | `app/Externals/Ecommerce/Services/EcommerceShopperListsService.php` |
| Cart serialize + merge | `EcommerceCartService::serializeCart`, `EcommerceCartMergeService` |
| Storefront routes | `EcommerceShopperListsController.php`, `routes/portal.php` |
| Store payload | `EcommerceStorefrontController::getStore` |
| Admin PATCH + Inventory card | `EcommerceController.php`, `commerce-store-dashboard.html`, `commerce-store.ts` |
| SDK + types | `resources/js/external/api/commerce/api.ts`, `portal.d.ts` |
| UI SDK paint | `resources/js/external/ui/ecommerce/ui.ts` |
| Compiled runtime + fallbacks | `commerce_component_script.ts` |
| Chrome + templates | `ecommerceChrome.ts`, `ecommerceTemplates.ts` (`chromeIcons`, cart `<details>`, `favorites` template) |
| Builder page list | `ecommerce.module.ts`, `pageLevel.ts`, `pageTypes.ts` |
| Tests | `tests/Unit/Ecommerce/EcommerceShopperListsTest.php`; builder `pageLevel.test.ts`, `modules.test.ts` |

**Ops.** `php artisan migrate` on the shared database
(`testdb_appfactorystudio` locally — ran
`2026_09_03_210000_…` DONE). Vite `npm run build` in www
(already run). Then **republish** so the page `js` column has
qty SVGs, the accordion painter, the heart, the favorites icon,
and `commerceFetch`, and so `css` has the chrome overrides.
Existing ecommerce projects get the Favorites child on the next
store bind or publish (`ensureBoundSubpages`). Turn the two
flags **on** per store under Inventory — they ship off. Re-pick
the cart / products templates only if you want the designed
`<details>` and the heart-next-to-cart in the Grapes HTML;
runtime will inject both if they are missing. Remote
`api-sandbox` / `api-ui-sandbox` still need a later
`build-external-webpack` + `build-external-ui-webpack` deploy
for `Portal.commerce.saveLineForLater` etc.; published pages
do not wait on that. Do not put any of this CSS/JS in
`layout.blade.php`. Do not start the Laravel Vite HMR server.

`MigrationOwnershipTest::test_no_table_is_created_by_more_than_one_migration`
still fails on a **pre-existing** pair:
`laravel_ecommerce_variants` in
`2026_04_03_133338_create_laravel_ecommerce_tables.php` +
`2026_09_01_040000_repair_ecommerce_variants_table.php`. That
is not this extra. Shopper-list PHP tests and the six-view
builder tests pass.

---

## E27 — Admin overview 500 and inventory lives in Settings

**Date.** 2026-09-03.

**Symptom.** Opening a store dashboard in admin 500s
`GET /portal/api/ecommerce/store/{ref}/overview` and
`GET /portal/api/ecommerce/orders?store=`. The Inventory
form (hold minutes, show quantity, save-for-later,
favorites) sat on the store dashboard instead of Settings
next to tax and shipping.

**Cause.** `laravel_ecommerce_orders` on this database is
the old skinny row (`reference_num`, `status`,
`total_amount_cents`, `items_snapshot`, timestamps). The
04_03 create later grew `placed_at`, `payment_status`,
`fulfilment_status`, `total_cents`, addresses, etc., but
`hasTable` skipped it. E5 added order *annex* tables
(lines, events, fulfilments) and not those columns. Admin
overview filters `placed_at` / `payment_status` /
`total_cents`; the order list `orderByDesc('placed_at')`.
PDO `42S22 Unknown column 'placed_at'`.

The password-modal autocomplete warnings and missing
`nav.modules` / `nav.site_users` i18n keys are unrelated
console noise.

**What we changed.**

1. `2026_09_03_220000_add_ecommerce_order_storefront_columns.php`
   is the `hasColumn` path for every orders column the
   create already declares. Existing rows get
   `placed_at = created_at`, `total_cents = total_amount_cents`
   when those were empty, and `order_number = reference_num`
   when blank. No second unique on `(store, order_number)`
   for the live table (empty/duplicate risk).
2. Inventory & shopping card moved from
   `commerce-store-dashboard.html` to
   `commerce-settings.html`, above tax / shipping.
   Load/save PATCH lives in `commerce-settings.ts`. Same
   store flags and Inventory save button.

**Files.** The orders annex migration,
`commerce-settings.html`, `commerce-settings.ts`,
`commerce-store-dashboard.html`, `commerce-store.ts`.

**Ops.** `php artisan migrate` on the shared database.
Vite `npm run build` in www so admin Settings shows the
form (dashboard HTML is bundled). Hard-refresh
`/portal/admin`. Overview / orders 500s stop without an
admin rebuild once migrate has run. Do not start the
Laravel Vite HMR server.

---

## E28 — Favorites "Send" buttons, spinners, accordion under totals

**Date.** 2026-09-03.

**Symptom.** Three storefront things:

1. With Favorites on, the favorites page had **two buttons
   labelled Send** in the top-right. They were the heart and
   cart icons.
2. Switching builder pages and loading ecommerce products
   showed "Loading" / "Loading products…" copy instead of a
   spinner.
3. On checkout (and cart) the save-for-later accordion sat
   **above** the subtotal / total row.

**Cause.** GrapesJS form `button` defaults `text: 'Send'`.
Chrome then paints every `[data-commerce-kind] button` as a
blue pill, so the SVG vanished and "Send" was what you saw.
Loading used `::before { content: "Loading products…" }` and
the builder overlay was an hourglass plus the word Loading.
The accordion was inserted after the line list.

**What we changed.** Chrome icons are `<a role="button">` with
SVGs, plus a runtime restore that replaces a "Send" label.
Loading `::before` and the builder overlay are a CSS spinner
with no visible label. `placeSavedBelowTotals` appends the
`<details>` after the totals block on cart and checkout.
Admin `renderLoading` dropped the caption under the spinner.

**Files.** `ecommerceChrome.ts`, `ecommerceTemplates.ts`,
`commerce_component_script.ts`, `pages.ts`,
`external/ui/ecommerce/ui.ts` + `ui.css`,
`admin/utils/utils.ts`.

**Ops.** `npm run build` in www. **Republish** so page `js`
restores the icons and moves the accordion, and so `css` has
the spinner / icon-circle rules. Re-pick the cart / checkout
/ favorites template only if you want the designed `<a>`
icons and the accordion already under Total in the Grapes
HTML; runtime will fix both if they are still `<button>Send`
or above Subtotal. Remote `api-ui-sandbox` still needs a
later `build-external-ui-webpack` deploy for the UI SDK
mirror. Do not put this in `layout.blade.php`. Do not start
the Laravel Vite HMR server.

---

## E29 — Cart icon, Add to Cart, and Checkout CTA

**Date.** 2026-09-03.

**Symptom.** Three storefront things on the published store:

1. The cart icon did nothing (`href="#"`; click stayed on the
   products page).
2. **Add to Cart** never added a line. The label could flip to
   Adding… and stop there.
3. The saved-for-later accordion still sat above Subtotal /
   Total, and the cart page had **no Checkout button** that
   led to paying.

**Cause.** Published pages load the remote UI SDK first. It
set `__afsUiBound` / `__afsCartBound` and `preventDefault` on
the cart `<a href="#">`, then `guessCartHref()` trusted that
`#`. The compiled script saw those flags and did not bind.
Add to Cart was the same E25 pattern: remote capture marked
the button busy, compiled skipped, nothing called
`addToCart`. The cart template had no
`[data-commerce-checkout]` and the accordion was still
inserted after the line list.

**What we changed.** Compiled runtime always binds Add to
Cart (`__afsCompiledAddBound`, capture + `stopPropagation`,
resolve by `data-product-slug` then name) and always wires
chrome navigation in capture (`[data-commerce-cart]`,
`[data-commerce-favorites]`, `[data-commerce-checkout]` →
sibling URLs). `guessCartHref` ignores `#`. Cart footer is
forced to **lines → Subtotal/Total → Checkout → saved-for-later
last**. The cart template gained a Checkout link. UI SDK
mirrors the same footer and add binding.

```ts
btn.addEventListener('click', async (ev) => {
    ev.preventDefault();
    ev.stopPropagation();
    // slug on the card, then name walk
    await shop.addToCart({ variant_ref: variantRef, quantity: 1 });
}, true);
```

**Files.** `commerce_component_script.ts`,
`ecommerceTemplates.ts`, `external/ui/ecommerce/ui.ts`,
`compileRuntime.test.ts`.

**Ops.** `npm run build` in www. **Republish** so page `js`
has `placeCartFooter` / `wireChromeNavigation` /
`__afsCompiledAddBound`. Re-pick the cart template only if
you want the Checkout `<a>` already in the Grapes HTML;
runtime inserts it when it is missing. Local verification
compiled with `compileRuntime` and wrote that `js` onto the
active `projeKEnNosrOA3M` rows so `/new-page-products` and
`/new-page-cart` pick it up without a builder Publish click.
Refresh the builder before a later Publish so a stale tab
does not write the old `js` back. Remote `api-ui-sandbox`
still needs a later `build-external-ui-webpack` deploy.
Do not put this in `layout.blade.php`. Do not start the
Laravel Vite HMR server.

---

## E30 — Checkout totals, Clear Cart, and shipping selector

**Date.** 2026-09-04.

**Symptom.** The checkout page showed:

1. Hardcoded example products (Test Mug, Test Sticker Pack)
   that were not in the cart.
2. `Total` said `$NaN` and `Shipping` was a hardcoded `$5.00`.
3. No way to empty the cart from the cart page.
4. Shipping was not linked to the shipping zones configured in
   the ecommerce admin dashboard.

**Cause.** The published cart/checkout HTML was from an older
version of the templates that baked placeholder items into the
Order Summary. The runtime only replaced the `[data-commerce-slot="lines"]` container, so the baked items outside that slot
survived. The template had no `subtotal`, `shipping`, or `total`
slots, and the checkout had no shipping method selector. There
was no `Clear Cart` endpoint or button.

**What we changed.**

- New backend endpoint `DELETE /portal/api/ecommerce/storefront/cart`
  clears lines, saved-for-later, discounts, and shipping method.
- Cart template got a `Clear cart` button (`data-commerce-clear-cart`)
  and explicit `subtotal` / `shipping` / `total` slots.
- Checkout template replaced the hardcoded summary with the same
  slots and added a `shipping-methods` slot. The address block now
  includes Country and State so the shipping selector can match zones.
- Compiled runtime binds the Clear Cart button, fills the slots from
  the live cart response, and hydrates a shipping method selector on
  checkout. When the shopper fills the country/address, the runtime
  calls `shop.setShippingAddress`, fetches `shop.shippingMethods()`,
  and defaults to the first available method, recalculating the total.
- The existing admin dashboard **Settings → Tax & Shipping** already
  manages shipping zones and methods; the storefront now reads them.

**Files.** `commerce_component_script.ts`,
`ecommerceTemplates.ts`, `EcommerceCartService.php`,
`EcommerceCartController.php`, `routes/portal.php`.

**Ops.** `npm run build` in www. For this demo store, the active
published cart/checkout HTML was regenerated from the current
templates and written directly to the `laravel_builder_published`
rows so the fix is live without a builder Publish click. To make the
shipping selector show a rate, add a shipping zone in the admin
dashboard (e.g., country `US`, method `Standard Shipping`, flat rate
$5.00). The checkout will then display the method and add it to the
total. Refresh the builder tab before the next Publish so it does not
write the old HTML back. Do not start the Laravel Vite HMR server.

---

## File index (www)

| Area | Path |
|---|---|
| Page snapshots / reset-CSS guard | `resources/js/portal/builder/core/editor/pages/pages.ts` |
| Ecommerce template apply + 25 ms save | `resources/js/portal/builder/core/editor/pageTypes/pageTypes.ts` |
| `<style>` lift only | `resources/js/portal/builder/core/editor/html/inlineStyles.ts` |
| Storefront templates, slots, icon, View | `resources/js/portal/builder/core/ecommerceTemplates.ts` |
| Storefront structural chrome (no `#id`) | `resources/js/portal/builder/core/ecommerceChrome.ts` |
| Layout (generic shell + `$ss` / `$pageJs`) | `resources/views/components/layout.blade.php` |
| Published runtime (serialised into `js`) | `resources/js/portal/builder/core/component_scripts/commerce_component_script.ts` |
| Compiler / `__afsBoot` | `resources/js/portal/builder/core/publish/compileRuntime.ts` |
| Serve, CSP, parent fallback | `app/Http/Controllers/BuilderPublishedController.php` |
| Prop pass-through | `resources/views/index.blade.php` |
| UI SDK entry | `resources/js/external/ui/main.ts` |
| UI ecommerce mount / hydrate / FAB / applyPrices | `resources/js/external/ui/ecommerce/ui.ts` + `ui.css` |
| Commerce externals API (`prices()`, watch) | `resources/js/external/api/commerce/api.ts` |
| `portal.d.ts` (hand-maintained) | `resources/js/external/api/portal.d.ts` |
| API tsconfig | `resources/js/external/api/tsconfig.json` |
| Cart annex migration | `database/migrations/2026_09_02_061800_create_ecommerce_cart_and_order_annex_tables.php` |
| Save-for-later / favorites migration | `database/migrations/2026_09_03_210000_add_ecommerce_save_later_and_favorites.php` |
| Orders storefront columns annex | `database/migrations/2026_09_03_220000_add_ecommerce_order_storefront_columns.php` |
| Shopper lists service + controller | `app/Externals/Ecommerce/Services/EcommerceShopperListsService.php`, `app/Http/Controllers/Externals/Ecommerce/EcommerceShopperListsController.php` |
| Saved / favorite models | `app/Models/Externals/EcommerceSavedForLater.php`, `app/Models/Externals/EcommerceFavorite.php` |
| Price publication table | `database/migrations/2026_09_03_050000_create_ecommerce_price_publications_table.php` |
| Price publication model | `app/Models/EcommercePricePublication.php` |
| Pages tests | `resources/js/portal/builder/tests/pages.test.ts` |
| Compile tests | `resources/js/portal/builder/tests/compileRuntime.test.ts` |
| DP notice + store filter | `resources/js/portal/builder/core/editor/pageTypes/commerceDpNotice.ts` |
| Storefront `getStore` / `getPrices` | `app/Http/Controllers/Externals/Ecommerce/EcommerceStorefrontController.php` |
| Admin stores / publish-prices | `app/Http/Controllers/Externals/Ecommerce/EcommerceController.php` |
| DP item resolvers + publishPrices | `app/Externals/Ecommerce/Services/EcommerceDirectPaymentService.php` |
| Storefront public throttle | `app/Http/Middleware/EcommercePublicThrottle.php` |
| DP items collection | `app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php` (`indexItems` …) |

---

## E31 — Stripe CSP via strict-dynamic on published checkout

**Date.** 2026-09-04.

**Symptom.** Clicking **Complete Order** on a published checkout
page logged a CSP violation:

```
Loading the script 'https://js.stripe.com/v3/' violates the following
Content Security Policy directive: "script-src ..."
```

**Cause.** `BuilderPublishedController::publishedPageCsp()` allowed
jQuery, the CDN, and the AFS SDK hosts, but not Stripe’s JS. Adding
Stripe’s CDN to `script-src` in PHP worked, but it hard-coded a
provider-specific host in the header that should be the SDK’s concern.

**What we changed.**

1. **SDK nonce propagation.** The external API SDK now captures the
   `nonce` of the parser-inserted `<script>` that loads it
   (`document.currentScript.nonce`) and applies that nonce to every
   provider script it creates (Stripe, PayPal, Square). See
   `resources/js/external/api/shared/cspNonce.ts`.

2. **Layout nonce on every parser-inserted script.**
   `resources/views/components/layout.blade.php` now emits `nonce="..."` on
   **every** `<script>` tag in the layout: jQuery, ScrollMagic, GSAP, the
   surveyjs libraries, and both the API and UI SDK `<script>` tags. Because
   `strict-dynamic` disables the host-based allowlist for scripts, any
   parser-inserted script without a nonce or hash is blocked. The compiled
   page `js` column and the layout’s own inline scroll-target script were
   already nonced; the static library tags now are too.

3. **CSP `strict-dynamic`.** `BuilderPublishedController::publishedPageCsp()`
   added `'strict-dynamic'` to `script-src` and removed the hard-coded
   `https://js.stripe.com` entry. The trusted, nonced SDK can now load
   provider scripts on demand without listing each provider CDN in PHP.

4. **Provider clients.** `StripeClient.ts`, `SquareClient.ts`,
   `PayPalClient.ts`, and `processorScripts.ts` all call
   `getSdkScriptNonce()` and set `script.nonce` before injecting a
   provider script.

**Files.**

- `app/Http/Controllers/BuilderPublishedController.php`
- `resources/views/components/layout.blade.php`
- `resources/js/external/api/shared/cspNonce.ts`
- `resources/js/external/api/direct_payments/processorScripts.ts`
- `resources/js/external/api/processors/StripeClient.ts`
- `resources/js/external/api/processors/SquareClient.ts`
- `resources/js/external/api/processors/PayPalClient.ts`

**Ops.**

- Rebuild the external API SDK with `npm run build-external-webpack` in `www`.
- Deploy the resulting `www/public/external/api/sdk.js` to
  `https://api-sandbox.appfactory.studio/sdk.js` (or `https://api.appfactory.studio/sdk.js`
  in production). The layout and CSP changes are live as soon as the page is
  refreshed; the new SDK behavior is what actually lets the browser trust the
  dynamically loaded Stripe script.
- `connect-src` and `frame-src` still list `https://*.stripe.com` because CSP
  has no `strict-dynamic` equivalent for network connections or frames. Those
  are needed for Stripe’s API calls and the Payment Element/3DS iframes, not
  for the initial script tag.

---

## E32 — Cart-token duplicate on Add to Cart

**Date.** 2026-09-04.

**Symptom.** Clicking **Add to Cart** on a published product page logged a 500
for `POST /portal/api/ecommerce/storefront/cart/lines`:

```
SQLSTATE[23000]: Integrity constraint violation: 1062
Duplicate entry '...' for key
'laravel_ecommerce_carts.laravel_ecommerce_carts_cart_token_unique'
```

**Cause.** The browser still held a `cart_token` from an earlier cart that was
no longer `active` (converted, abandoned, or expired). `getOrCreate()`
looked for an *active* cart with that token, found none, and then tried to
`INSERT` a new cart using the same stale token. Because `cart_token` is
globally unique, the insert failed.

**What we changed.** `EcommerceCartService::getOrCreate()` now checks whether the
supplied token already exists in any cart row before trying to create with it.
If it does, it discards the stale token and generates a fresh one. The new token
is returned to the client and stored in the cart cookie.

**Files.** `app/Externals/Ecommerce/Services/EcommerceCartService.php`.

**Ops.** No build or republish needed. The fix is live on the next Add to Cart
request.

---

## E33 — Primary-key audit: integer-only surrogate keys

**Date.** 2026-09-04.

**Symptom.** Clicking **Complete Order** on a published checkout page logged a 500
for `POST /portal/api/v1/processors/customers/create-anonymous`:

```
SQLSTATE[HY000]: General error: 1364 Field 'id' doesn't have a default value
(Connection: mysql, SQL: insert into `laravel_processor_customers` ...)
```

**Cause.** Several application tables were created with non-integer primary keys
(ULID, UUID, or string), which broke standard Eloquent `create`/`firstOrCreate`
unless the model carried the matching trait/workaround. The estate rule is that
every surrogate primary key must be an auto-incrementing integer.

**What we changed.** Audited all migrations and changed application tables to use
`$table->id()` for their primary key. Where the old string/ULID value was also a
public identifier, it was kept as a separate unique column.

| Table | Old PK | New PK | Notes |
|---|---|---|---|
| `laravel_processors` | ULID `id` | `$table->id()` | `reference_num` remains the public ID |
| `laravel_processor_webhooks` | ULID `id` | `$table->id()` |  |
| `laravel_processor_charges` | ULID `id` | `$table->id()` | Dead table; `customer_id` now `unsignedBigInteger` |
| `laravel_processor_customers` | ULID `id` | `$table->id()` | Removed `HasUlids` from model |
| `laravel_processor_discounts` | ULID `id` | `$table->id()` |  |
| `laravel_processor_subscriptions` | ULID `id` | `$table->id()` | `customer_id` and `plan_id` now `unsignedBigInteger` |
| `laravel_processor_plans` | ULID `id` | `$table->id()` |  |
| `laravel_processor_connected_accounts` | ULID `id` | `$table->id()` |  |
| `laravel_processor_transfer_intents` | ULID `id` | `$table->id()` | Removed `HasUlids` from model |
| `members_storage_sessions` | UUID `id` | `$table->id()` |  |
| `members_storage_session_files` | UUID `id` | `$table->id()` | `session_id` FK now `foreignId` |
| `laravel_listings` | string `listing_id` | `$table->id()` + unique `listing_id` | Public identifier preserved |
| `laravel_listing_items` | string `listing_item_id` | `$table->id()` + unique `listing_item_id` | Public identifier preserved |

Framework migrations left unchanged (they are standard Laravel tables where the
key is a natural key, not a surrogate integer):

- `job_batches.id` (string batch ID)
- `cache.key` / `cache_locks.key` (cache keys)
- `laravel_password_reset_tokens.email` (token lookup key)
- `laravel_sessions.id` (session ID)
- `auth/checkpoint_2fa_pending.token` (2FA pending token)

**Files.**

- `database/migrations/2026_05_21_000001_create_processor_tables.php`
- `database/migrations/2026_08_23_000001_create_members_storage_tables.php`
- `database/migrations/2026_04_04_120000_create_laravel_listings_tables.php`
- `app/Models/Externals/Processor/ProcessorCustomer.php`
- `app/Models/Externals/Processor/ProcessorTransferIntent.php`
- `app/Models/Externals/Listing/Listing.php`
- `app/Models/Externals/Listing/ListingItem.php`

**Ops.** To apply the schema changes, run a fresh migration pass (this will wipe
local test data):

```bash
cd www && php artisan migrate:fresh --force
cd ../auth && php artisan migrate:fresh --force
```

Then reconfigure processors / store data as needed.

---

## Operational checklist (when something looks “not deployed”)

1. **Canvas unstyled after a page switch** — E8. Confirm
   `isGrapesResetCss` is in `pages.ts` and the 25 ms save still
   runs after `renderStoreTemplate`. Do not change hardReinit.
2. **Published page looks like generic cards** — E9. The `html`
   column is probably fine. Republish so the `js` column contains
   `looksDesigned`. If the compiled JS is old, also deploy the UI
   SDK so `mount()` itself refuses to remount.
3. **Designed cart / checkout still wiped** — E10. Same republish.
   Re-pick the template if slots / email `type="email"` are missing
   and hydrate’s DOM fallback is not finding the lines box.
4. **No cart icon / no View** — E11. Re-pick the store template
   (icon and View are in the HTML). Republish for
   `ensureCartIcon` / `bindProductLinks`. HEAD `-product` if View
   is hidden.
5. **Add to Cart 500** — E5. Table missing; run the annex
   migration on that database.
6. **JS present, nothing mounts** — E1. View source: the compiled
   script must be after `</body>`’s content (end of body), not in
   `<head>`.
7. **500 on every published page** — E2. `$afssdk` / `$afssdkui`
   / `$title` / `$ss` / `$pageJs` all declared on the layout.
8. **Works on www.test, 404 `sdk.js` on a tenant** — someone
   pointed the layout at `/external/ui/sdk.js`. Use the remote
   hosts only.
9. **Store missing from the builder dropdown / checkout dead** —
   E12. Direct Payment is configured on the store in Admin, not
   in the builder. A published store that lost its DP shows
   “Store closed for maintenance” after `shop.init()`.
10. **Direct Payments save shows a network / JSON parse error** —
    E13. The API returned HTML 500 because `GetManagedDomain()`
    called a missing global. Retry after the `self::` fix; no
    asset rebuild.
11. **Published store is unstyled / product page has no Back** —
    E14 then E24. Official `*-products` / `*-product` rows often
    have only the Grapes reset in `css`. Republish after the Vite
    build so the `css` column gets chrome and `js` gets
    `ensureBackLink`. Re-pick the store template to stamp Back
    into the HTML. Layout no longer paints chrome.
12. **DP admin save wipes items / product form cannot edit an item** —
    E15. Use `/directpayments/{dpRef}/items`, not the embedded
    array. `GET /directpayments` is items-less unless
    `include_items=1`.
13. **Published prices stay at the baked `$` after a DP edit** —
    E16. Click **Publish prices** in ecommerce admin (store
    dashboard or products). Do not Grapes-republish. Layout paints
    from `/storefront/prices`. `Portal.commerce.prices()` needs
    the sandbox SDK deployed.
14. **Storefront 429 Too many requests** — E17. GET catalogue is
    600/hour; writes stay 120. Hard-refresh so the 15s poller is
    gone. Do not add another `/storefront` interval. New cache
    keys are `ecommerce-public-read:` / `-write:`.
15. **Unmapped products still show a store `$` on the live grid** —
    E18. Explicit mode has no catalogue fallback. Hard-refresh so
    layout hides baked cards not in `/prices`. Map the product to
    a Direct Payment item to sell it; do not type a store price.
16. **$0 checkout still tries the card processor** — E19. Direct
    Payments completes a zero invoice as `succeeded` and emits
    `payment:succeeded`. Events/appointments with a DP configured
    always create an invoice, including $0 items. Deploy the
    externals API SDK (`build-external-webpack`) for published
    pages on remote `api-sandbox`.
17. **Admin home cards stay at zero** — E20. Rebuild admin assets
    (`npm run build` in www) and hard-refresh `/portal/admin`.
    `GET /portal/api/v1/dashboard` is live without that build.
18. **Storefront flashes every product at $0.00, then Add to Cart
    vanishes** — E21. Hard-refresh the published page so the
    product grid stays on a loader until `/products` returns only
    mapped rows. Republish only if you need the compiled runtime
    image loader.     Do not add another `/prices` poll.
    **E24:** loader CSS is in `ecommerceChromeCss` (css column),
    not the layout. Republish after `npm run build`.
19. **Cart has no qty/remove; products never sell out** — E22.
    Set quantity + listing on the product; hold minutes / show
    quantity on the store Inventory card. Hard-refresh published
    cart for − / + / Remove. Sold out stays listed until admin
    raises qty. Republish only for the compiled runtime.
20. **Cart icon stays at the old count; cart flashes then $NaN** —
    E23 then E24. Republish so compiled `js` fetches `/cart`,
    paints the badge, and hides lines until ready. Layout no
    longer does this on hard-refresh alone.
21. **Forms / about / events picked up store CSS or cart JS** —
    E24. Layout is generic again. Storefront chrome is the page
    `css` column; runtime is the page `js` column. Republish
    ecommerce pages after `npm run build`.
22. **Add to Cart stuck on Adding…** — E25. Republish after
    `npm run build` so compiled `js` ignores the UI busy-disabled
    flag. The cart POST itself is fine. Do not put add-to-cart
    back in the layout.
23. **Cart ± buttons are blank white squares** — E26. Chrome
    `button { color:#fff }` on a white qty control. Republish
    after `npm run build` so compiled `js` paints SVG minus/plus
    with `#0f172a` and `css` has the qty chrome override. Do not
    put qty CSS in the layout.
24. **No Save for later / empty accordion** — E26. Turn
    **Allow shoppers to save cart items for later** on under
    Inventory, then republish. Flag-off API is
    `EC_E_SAVE_LATER_DISABLED`. Runtime creates the
    `<details>` if the old cart HTML has no slot.
25. **No heart / no favorites icon / no Favorites page** — E26.
    Turn **Allow shoppers to favorite products** on under
    Inventory. Republish for the icon + heart + `*-favorites`
    runtime. Re-select the store or publish so
    `ensureBoundSubpages` creates the Favorites child (six
    views, not five). Guests use `ec_cart_token`; do not set
    Favorites to page auth.
26. **Admin store overview / orders 500** — E27. Unknown
    column `placed_at`. Run
    `2026_09_03_220000_add_ecommerce_order_storefront_columns`.
    Inventory & shopping is on **Settings**, not the
    dashboard. Rebuild admin assets to see the moved form.
27. **Favorites page shows two "Send" buttons** — E28. Grapes
    form `button` default is "Send". Republish after
    `npm run build` so compiled `js` restores the heart / cart
    SVGs and `css` treats those controls as icon links. Do not
    put this in the layout.
28. **Loading products / pages shows a text label** — E28.
    Chrome `::before` and the builder overlay are a spinner
    only. Republish for storefront chrome; builder overlay is
    the Vite admin/builder build.
29. **Save for later sits above Subtotal on checkout** — E28.
    Accordion is appended after the totals row. Republish, or
    the compiled runtime will move an existing `<details>`
    below `[data-commerce-slot="total"]`.
30. **Builder canvas stuck on a spinner after picking a store /
    template** — E40. `ecommerceChromeCss`'s
    `:not([data-commerce-ready])` loader has no runtime to clear
    it in the canvas. `npm run build` in www so
    `pageTypes.ts`'s `ensureEditorCommerceReadyCss` reaches the
    builder bundle. No republish — published pages are fine.

Local builder for the live project used while these extras landed:
`http://www.test/portal/builder/projeKEnNosrOA3M`
(`laravel_builder_projects.id` 1). Published slugs: `new-page`,
`new-page-products`, `-product`, `-cart`, `-checkout`, `-orders`,
`-favorites` (Favorites child appears after the next store bind
or publish).

---

## E34 — Ecommerce variants JSON unique via generated hash

**Symptom.** `php artisan migrate:fresh --force` in `www` failed with:

```
SQLSTATE[42000]: Syntax error or access violation: 3152
JSON column 'option_values' supports indexing only via generated columns
on a specified JSON path.
```

**Cause.** `2026_04_03_133338_create_laravel_ecommerce_tables.php` declared a
unique index on the raw `option_values` JSON column. MySQL 8.4 rejects
unique indexes on JSON columns.

**What we did.**

- Added a stored generated column `option_values_hash` that hashes the JSON
  value with `sha2(json_unquote(option_values), 256)`.
- Replaced the unique index with `unique(['product_id', 'option_values_hash'])`.
- The hash is maintained by MySQL; Eloquent code does not need to set it.

`www/database/migrations/2026_04_03_133338_create_laravel_ecommerce_tables.php`:

```php
$table->json('option_values')->nullable();
$table->string('option_values_hash', 64)->nullable()
    ->storedAs('sha2(json_unquote(option_values), 256)');
// ...
$table->unique(['product_id', 'option_values_hash'], 'ec_variants_combo_uq');
```

We also removed two redundant/redundant migrations that duplicated or
ALTERed this table after the fact:

- `2026_09_01_040000_repair_ecommerce_variants_table.php` (re-created the
  same table, which tripped `MigrationOwnershipTest`).
- `2026_09_01_041000_expand_ecommerce_legacy_schema.php` (ALTER-only
  additions that now live in the original `create` migration).

Both files were deleted; their records were already in the local fresh
`migrations` table but `migrate:status` ignores them because the files are
gone. `MigrationOwnershipTest` now passes.

**Operational consequence.** A fresh install succeeds and the variant
uniqueness rule is preserved. Existing installs that already recorded the
deleted migrations will keep their schema; only new installs benefit from the
cleaner file set.

---

## E35 — Auth checkpoint 2FA pending integer PK

**Symptom.** The primary-key audit found a string primary key in `auth`:
`checkpoint_2fa_pending.token` was the PK in
`2026_07_13_000001_create_checkpoint_tables.php`.

**Cause.** The pending-2FA table used the random 64-character token as the
primary key. That violates the integer-only-surrogate-key rule and made the
local shared-database copy inconsistent with the rule after `www` was already
fixed.

**What we did.**

- Changed `checkpoint_2fa_pending` to use `$table->id()`.
- Kept `token` as a unique indexed column (`checkpoint_2fa_pending_token_uq`).
- The `TwoFactorService` already looks rows up by `token`, so no
  application-code change was needed.

`auth/database/migrations/2026_07_13_000001_create_checkpoint_tables.php`:

```php
$table->id();
$table->string('token', 64)->unique('checkpoint_2fa_pending_token_uq');
```

Because `auth` writes its checkpoint tables to the shared Brain/members
database (`testdb_appfactorystudio`) via the `checkpoint` connection, we
manually dropped the checkpoint tables there and re-ran
`php artisan migrate:fresh --force` in `auth` so the new schema was applied.

**Operational consequence.** `checkpoint_2fa_pending` now has an integer
surrogate key. 2FA pending-token flows continue to work because they are
keyed by the unique `token` string, not by the PK. The auth private database
(`afs_idpdb_test`) was also refreshed, so all auth local test data is gone.

---

## E36 — Auth checkpoint 2FA integer PK and unique user

**Symptom.** The final PK audit found one remaining non-integer primary key
in an application table: `checkpoint_2fa` used a composite primary key
`(user_type, user_id)`.

**Cause.** The 2FA secrets table needed a way to distinguish primary
(`normal`) users from secondary users because the two id spaces overlap.
Using a composite PK satisfied that, but it still violates the
integer-only-surrogate-key rule.

**What we did.**

- Added a surrogate integer `id` to `checkpoint_2fa`.
- Kept `(user_type, user_id)` as a unique constraint
  (`checkpoint_2fa_user_uq`).
- The application code (`TwoFactorService`, `SecondaryUserService`) queries
  and deletes by `user_type` + `user_id`, so no code changes were needed.
- `INSERT ... ON DUPLICATE KEY UPDATE secret = ?, enabled = 0` still works
  because the conflict fires on the unique constraint.

`auth/database/migrations/2026_07_13_000001_create_checkpoint_tables.php`:

```php
$table->id();
$table->string('user_type', 20)->default('normal');
$table->unsignedInteger('user_id');
$table->unique(['user_type', 'user_id'], 'checkpoint_2fa_user_uq');
```

We dropped the checkpoint tables in the shared database and re-ran
`php artisan migrate:fresh --force` in `auth` so the change was applied.

**Operational consequence.** `checkpoint_2fa` now has an integer surrogate
key while still enforcing one secret per `(user_type, user_id)` pair. 2FA
enrolment, verification, and disable flows continue unchanged.

---

## E37 — Demo storefront seeder

**Symptom.** After `php artisan migrate:fresh --force` the storefront test
data was gone. The published pages were still in `laravel_builder_published`,
but there was no `demo-store-test`, no `Test T-Shirt`, and no
Direct Payment item, so the storefront APIs returned empty catalogues.

**Cause.** The data-only dump was from an older schema and did not contain
the storefront demo records that had been created through the UI.

**What we did.**

- Added `www/database/seeders/DemoStoreSeeder.php`.
- Registered it in `DatabaseSeeder.php` after `InitSeeder`.
- All lookups use fixed `reference_num` values, so the seeder is idempotent:
  - Store: `demo-store-test`
  - Product: `test-t-shirt` / slug `test-t-shirt`
  - Variant: `var_55mpwdwkmjua`
  - Direct payment: `demo-direct-payment`
  - Direct payment item: `demo-tshirt-item` ($19.99, FIXED)
  - Processor: `demo-processor` (only created if no active Stripe processor exists)
- The seeder also calls `EcommerceDirectPaymentService::publishPrices()` so the
  storefront revision is bumped and the product/variant `price_cents` stay in
  sync with the Direct Payment item.
- Added a production guard to every seeder. If `env('APP_ENV') === 'production'`,
  `run()` returns immediately.
- Made the `auth` `DatabaseSeeder` idempotent (`firstOrCreate` by email) so it
  does not crash on repeated runs.

`www/database/seeders/DatabaseSeeder.php`:

```php
if (env('APP_ENV') === 'production') {
    return;
}

$this->call(InitSeeder::class);
$this->call(DemoStoreSeeder::class);
```

`auth/database/seeders/DatabaseSeeder.php`:

```php
if (env('APP_ENV') === 'production') {
    return;
}

User::firstOrCreate(
    ['email' => 'test@example.com'],
    ['name' => 'Test User', 'password' => 'password']
);
```

**Operational consequence.** After a fresh migration, running
`php artisan db:seed --force` restores a working demo catalogue. The
published pages (`new-page-products`, `-product`, `-cart`, `-checkout`,
`-orders`, `-favorites`) still need to be republished through the builder if
they were lost; the seeder only restores the sellable data. In production,
`db:seed` is a no-op for every seeder.

---

## E38 — Data-only dump filtering for current schema

**Symptom.** The `data-only` SQL dumps in
`C:\projects\afs-eco\databases\data-only` failed to import into the current
schema. `testdb_appfactorystudio.data-only.sql` aborted immediately with
`Table 'analytics' doesn't exist`, and many more tables/columns were stale.

**Cause.** The dumps were exported from an older schema. After the migration
audit deleted redundant migrations and renamed columns (e.g.
`reference_num` → `processor_reference_num` on processor tables), the dumps
referenced tables and columns that no longer exist.

**What we did.**

- Created `C:\projects\afs-eco\databases\data-only\new\filter_data_only_dumps.py`.
- The script reads the current schema for each database, drops every INSERT
  block whose table is missing or whose columns are no longer present, and
  writes a brand-new SQL file to `data-only\new`.
- Original files are left untouched.
- It processed all four databases:
  - `afs_idpdb_test` — 9 tables kept, 0 skipped
  - `testdb_afs_apps_db` — 89 tables kept, 0 skipped
  - `testdb_afsdb_market` — 45 tables kept, 0 skipped
  - `testdb_appfactorystudio` — 172 tables kept, 32 skipped (old tables like
    `analytics`, `forms`, `processor_customers`, and renamed columns like
    `reference_num` on `laravel_processor_*` tables)
- The filtered `testdb_appfactorystudio.data-only.sql` was imported back into
  the shared database with no errors to verify it is clean.

**Operational consequence.** Use the files in `data-only\new` for future local
restores. The old `data-only` files are still useful as an archive but must
not be imported directly into the current schema. If more columns are renamed
in future migrations, re-run the filter script to regenerate the `new` files.

---

## E39 — SeedRunner orchestrator

**Symptom.** After adding several seeders (`InitSeeder`, `DemoStoreSeeder`,
`SponsorshipSeeder`, `NotificationSeeder`, `AnalyticsEventSeeder`) there was
no single command to run them all in the right order.

**What we did.**

- Added `www/database/seeders/SeedRunner.php`.
- It is production-guarded the same way as the other seeders.
- It calls the core `DatabaseSeeder` (users, defaults, demo storefront) and then
  conditionally runs optional module seeders when their target tables are empty:
  - `SponsorshipSeeder` only if `laravel_sponsorships` is empty.
  - `NotificationSeeder` only if `laravel_notifications` is empty.
  - `AnalyticsEventSeeder` only if `laravel_analytics_events` is empty.
  - `MailHandlerSeeder` is called for completeness (currently a no-op).

Usage:

```bash
php artisan db:seed --class=SeedRunner --force
```

**Operational consequence.** One command restores the local environment after a
fresh migration. Each underlying seeder is still individually guarded and
idempotent, so `SeedRunner` can be run repeatedly without duplicating data.

---

## E40 — Builder canvas stuck on the loader when configuring ecommerce

**Date.** 2026-09-05.

**Symptom.** In the builder Page panel, set a page to **E-Commerce** and pick a
store or a store/cart/checkout template. The canvas shows a spinning circle that
never resolves — "it just stays loading". The child pages (Products, Cart,
Checkout, My Orders, Favorites) are created and the panel fills in; only the
canvas preview is stuck. Repro: `http://www.test/portal/builder/projeKEnNosrOA3M`,
new page → E-Commerce → Store: Demo Store → Store Template: Product Grid.

**Cause.** E21/E24 moved the storefront loader into `ecommerceChromeCss`
(`ecommerceChrome.ts`), which `renderStoreTemplate` injects into the canvas frame
as `template.styles`:

```css
[data-commerce-kind="product-grid"]:not([data-commerce-ready]) > * { visibility: hidden !important; }
[data-commerce-kind="product-grid"]:not([data-commerce-ready])::before { /* afs-ec-spin */ }
```

`data-commerce-ready` is set **only by the published runtime**
(`commerce_component_script.ts` → `container.setAttribute('data-commerce-ready', '1')`
after `/products` resolves). That runtime's first decision is
`if (isEditorMode()) return;` (E7), so in the builder canvas the attribute is
never set: the content stays `visibility:hidden` and the `::before` spinner spins
forever. E21/E24/E28 tuned this loader for **published pages** and never accounted
for the canvas — the transient `renderStoreTemplate` overlay was the only builder
spinner considered.

The fix does **not** belong in `commerce_component_script.ts`: that script is a
GrapesJS component `script`, and the template HTML `renderStoreTemplate` stamps
is not recognised as the commerce component type (`isComponent` checks for a
class the stamp does not carry), so the script does not execute in the canvas at
all. Only `pageTypes.ts` runs there.

**What we changed.** `pageTypes.ts` — a canvas-only `<style>` that reveals the
designed markup and kills the loader. It is injected into the **frame document**,
so it is never part of `editor.getHtml()` and nothing is persisted or published.

```ts
// resources/js/portal/builder/core/editor/pageTypes/pageTypes.ts
const ensureEditorCommerceReadyCss = (): void => {
    const frameDoc = (editor.Canvas as any)?.getDocument?.() as Document | undefined;
    if (!frameDoc || frameDoc.getElementById('afs-ec-editor-ready')) return;
    const style = frameDoc.createElement('style');
    style.id = 'afs-ec-editor-ready';
    style.textContent =
        '[data-commerce-kind]:not([data-commerce-ready]) > [data-gjs-highlightable]{visibility:visible!important}'
      + '[data-commerce-kind]:not([data-commerce-ready])[data-gjs-highlightable]::before{content:none!important;display:none!important;animation:none!important}';
    (frameDoc.head || frameDoc.body)?.appendChild(style);
};
```

`[data-gjs-highlightable]` is on every element GrapesJS renders in the canvas, so
`> [data-gjs-highlightable]` outweighs the chrome's `:not([data-commerce-ready]) > *`
— which also lives in a `<style>` **inside the template body**, so plain source
order is not enough.

Called from:

- `renderStoreTemplate`, right after `injectFrameStyles(effectiveStyles, …)`.
- the `page:select` handler for `pageType === 'e-commerce'`, plus a
  `setTimeout(…, 60)` retry because `hardReinitPageFromSnapshot` re-injects the
  chrome into the new frame and the frame can swap.

**Files.** `resources/js/portal/builder/core/editor/pageTypes/pageTypes.ts`
(`ensureEditorCommerceReadyCss` + two call sites).

**Ops.** `npm run build` in www — the fix is in the builder bundle, so it needs
that build to reach the canvas. **No republish** and **no CSS/JS column change**:
published pages were never affected (`isEditorMode()` is false there, the runtime
sets `data-commerce-ready` normally). Do not move this into
`commerce_component_script.ts` (does not run for a stamped template) or
`layout.blade.php` (E24). Verified in the browser: store, product-grid and cart
templates render in the canvas with no spinner, and switching between the six
child pages keeps working.

Tests: builder `pageLevel.test.ts` / `pages.test.ts` (39) and
`compileRuntime.test.ts` (50) pass unchanged.

---

## E41 — Events built into the builder (façade orders + four-view runtime)

**Date.** 2026-09-08. **Repo:** `www` (commit `17bc2db4d`), plans in
`packages/afsserver` (`582e71cf`). Full numbered record:
[05](../05-events-parity.md) + [complete/05](05-events-parity.complete.md).

**Context.** Builder-v2 [03](../03-the-three-modules.md) §3.2 made events a
page-level module — bind an Event, get List / Detail / Register pages — but the
runtime was a ~50-line stub that dumped `<article>` rows with inline styles. No
chrome, no hydration, no working registration, no payment. Separately, event
orders still stored money on `laravel_event_orders` /
`laravel_event_registrations` and `EventPaymentService::confirmPaid()` trusted
the invoice row status alone — the webhook-lag hole the ecommerce decoupling
closed and events never followed.

**What we changed.**

*Server — event orders become façades on
`App\Externals\DirectPayments\Contracts\PaymentGateway`* (mirrors ecommerce `0aa2b4dfd`):

- Migration `2026_09_09_000000_events_facade_payment_refs` — DROP the six
  `event_orders` money columns + `direct_payment_charge_ref` and
  `event_registrations.amount_cents` / `refunded_cents`; ADD
  `direct_payment_reference_num` + `processor_reference_num` (backfilled).
- `EventOrderService` (new) — `finalizeFromSnapshot(order, InvoiceSnapshot)`
  (three refs + `status:'paid'`, idempotent) and `paymentSnapshot(order)`
  (every figure from `PaymentGateway::snapshot()`).
- `EventPaymentService` — keeps invoice creation inside `register()`'s capacity
  transaction, stamps `metadata.facade = 'events'` +
  `registration_reference_num`; `confirmPaid()` removed.
- `EventStorefrontController::confirmPayment()` gates on
  `PaymentGateway::isPaid()`; `EventsReconcilePayments` rewritten like
  `EcommerceReconcileOrders` (`{--minutes=15} {--dry-run}`);
  `EventReportingService` revenue from `snapshots()`;
  `DirectPaymentGateway::facadeRef()` reads `event_order_reference_num`.
- Fixed in passing: a `free`-kind ticket registers with no Direct Payment item
  even on a paid event (was `EV_E_ITEM_UNMAPPED`).

*Builder — parity with the ecommerce page-level surface:*

- `events.module.ts` — `subpages` →
  `['list','detail','register','my-registrations']` (the 4th is the events
  analogue of ecommerce `orders`, auth-gated);
  `foreignRefTraits: { 'data-dp-ref': 'direct_payments' }`; `data-site-ref` trait
  dropped.
- `events_component_script.ts` — full rewrite, typed, no optional chaining, a
  typed Direct Payments client. Four views: **list** (occurrence cards → detail
  with `?event=`), **detail** (schedule + tickets with honest sold-out /
  waitlist / sales-not-open / code-required state), **register** (`register()`
  → DP single-pay → `confirmPayment()` → receipt), **my-registrations**
  (auth-gated `myRegistrations()`). `looksDesigned()` hydrate-in-place,
  `data-events-ready` gate, `clearCanvas()` before paint, `injectChrome()`
  fallback.
- `eventsChrome.ts` (new); `pageLevel.ts` stamps `data-events-kind`;
  `compileRuntime.ts` + `publish.ts` index `pageEventDpRef` under
  `direct_payments`; `pageTypes.ts` `syncEventDirectPayment()` mirrors
  `syncStoreDirectPayment()`.
- SDK `resources/js/external/api/events/api.ts` `beginPayment()` rebuilt around a
  typed `EventsDpClient`; `portal.d.ts` events surface expanded to the full SDK.

**Also (demo data).** `EventsDemoSeeder` (mirrors `DemoStoreSeeder` — E37):
idempotent, production-guard, `resolveWebsiteStamp()`. A published "Demo Tech
Conference 2026" (paid GA + VIP, free Community Pass) and a free "Demo Community
Meetup", occurrences, an Early-Bird tier, sessions, a promo code, an annual
pass; own `demo-events-dp` Direct Payment with per-ticket items. Registered in
`DatabaseSeeder` behind a "no demo events yet" guard. `DemoStoreSeeder` fixed
alongside — it still called the removed `publishPrices()` and wrote the dropped
`dp_item_mode` / `processor_tax_enabled` store columns, so `php artisan db:seed`
fatalled.

**Files.** `app/Externals/Events/Services/EventOrderService.php` (new),
`.../EventPaymentService.php`, `.../EventRegistrationService.php`,
`.../EventReportingService.php`,
`app/Console/Commands/EventsReconcilePayments.php`,
`app/Http/Controllers/Portal/{Events/EventStorefrontController,Admin/EventController}.php`,
`app/Models/{EventOrder,EventRegistration,Event}.php`,
`app/Externals/DirectPayments/DirectPaymentGateway.php`,
`database/migrations/2026_09_09_000000_events_facade_payment_refs.php`,
`database/seeders/{EventsDemoSeeder,DatabaseSeeder,DemoStoreSeeder}.php`.
Builder: `resources/js/portal/builder/core/` — `modules/events.module.ts`,
`modules/pageLevel.ts`, `modules/instances.ts`, `events_components.ts`,
`component_scripts/events_component_script.ts`, `eventsChrome.ts` (new),
`publish/compileRuntime.ts`, `editor/publish/publish.ts`,
`editor/pageTypes/pageTypes.ts`. Plus `resources/js/external/api/events/api.ts`,
`resources/js/external/api/portal.d.ts`, builder tests.

**Ops.** `php artisan migrate`; `php artisan db:seed --class=EventsDemoSeeder`.
`npm run build` in www (**never** `npm run dev`). **Republish** the builder
project so the events pages get the new page `js`/`css`; re-bind the Event on
any existing events page so the 4th child + `pageEventDpRef` are created. The
reconciler command args changed — update any scheduler entry. Appointments has
the identical DP coupling (`AppointmentsReconcilePayments`) — **not** done here.

Verified live: Demo Tech Conference bound to `new-page-events` in project
`projeKEnNosrOA3M`; all four views serve; a paid GA `register()` → façade order
with no money columns, `status='paid'`, registrations `confirmed`. 462 builder
tests, `tsc`, `php artisan test --filter=Event` green.

---

## E42 — Module-wide Design themes + brand colours + editor preview

**Date.** 2026-09-09. **Repo:** `www` (`53a3cb0ca`), plans (`a27edaf4`). Full
record: [06](../06-design-themes.md) + [complete/06](06-design-themes.complete.md).

**Context.** After E41 two gaps stayed open: (1) the events editor canvas showed
only the dashed bind placeholder — ecommerce has `renderStoreTemplate` (fetch
the store's products, paint the canvas), events had nothing ("I should not have
to wait to see the events design"); (2) one look per view, and nothing read the
tenant's `brand.primaryColor1` from `/portal/api/settings` (it is set —
`#911818` in this DB — and ignored).

**What we changed.** A single module-wide **Design** picker (4 options at this
point: Classic / Bold / Minimal / Editorial) per ecommerce / events page bind;
`brand.primaryColor1` overlaid as the accent.

- `core/branding.ts` (new) — `DesignId`, `DESIGN_THEMES` (a token set per
  design), `resolveTheme(design, brand)` (brand primary → `accent`, `accentInk`
  re-derived via `readableInk` luminance), `themeCssVars`. `SettingsBrand`
  copied in — the builder never imports `portal/admin`.
- `resources/js/portal/builder/main.ts` `bootstrap()` fetches
  `/portal/api/settings` (`fetchBrandSettings`, best-effort) → `initEditor({ …,
  brand })` → `core/editor/index.ts` threads it to `createPageTypes` /
  `createPublish`.
- `core/eventsTemplates.ts` (new) — `renderEventsView(subpage, data, name,
  theme)` emits only the runtime hooks (`[data-events-list]`,
  `[data-events-ticket]`, `form[data-events-register]`, `[data-events-back]`);
  `eventsChromeCss` keyed on `data-design` does all styling.
- `pageTypes.ts` — `renderEventsTemplate(eventRef, design, page)` mirrors
  `renderStoreTemplate`: `showPageLoadingOverlay`, fetch
  `/portal/api/events/storefront/events[/{ref}]` (public), `renderEventsView`,
  `applyPageStyles` in the `template-events-{design}` group, `injectFrameStyles`,
  `ensureEditorModuleReadyCss`. Runs on the events `page:select` branch while
  the canvas is still the bind placeholder, on the event pick, and on a design
  change. `syncDesign(prop, id)` / `currentDesign(prop)` write / read
  `pageEventDesign` / `pageCommerceDesign` across the parent + children.
- `{events,ecommerce}Chrome.ts` — literals → `var(--afs-*)`; `[data-design]`
  blocks override. `pageLevel.ts` stamps `data-design`; `publish.ts` prepends
  `[data-*-kind]{--afs-accent:… !important}` to the page `css` when Settings has
  a brand colour. `ecommerceTemplates.ts` `heroSection` / `footerSection` take
  the theme.
- `events_component_script.ts` `injectChrome()` emits the token-based fallback.
- `index.blade.php` — `#page-event-design` / `#page-commerce-design` selects.

**Also.** E40's canvas-loader fix was generalised: `ensureEditorCommerceReadyCss`
→ `ensureEditorModuleReadyCss`, now covering `[data-events-kind]` (and later
`[data-appointments-kind]`); a `publish.ts` fix so the real republish path
passes `pageEventDpRef` into the compile; `clearCanvas()` in the events runtime
so the detail / register / my-registrations views drop the design-time
placeholder before painting.

**Ops.** `npm run build`. **Republish** so events pages pick up the themed
`js`/`css`; reload the builder editor (a running editor caches the old bundle).
Brand colour is baked at publish — a Settings change needs a republish (residue).

Verified live: `/new-page-events-detail` bound `design=bold` + brand `#911818` —
dark hero, squared cards, `#911818` CTA. 476 builder tests, `tsc`, `npm run
build` green.

---

## E43 — Design picker for appointments + demo seeder

**Date.** 2026-09-09. **Repo:** `www` (`bfd3022db` + `05eb9ea3e`), plans
(`163e24e8`). Folded into [06](../06-design-themes.md).

**Context.** E42 did events + ecommerce; the third page-level module was left
out, and there was no seeded demo appointment / bound page to verify against.
Appointments had no chrome file and no editor preview — the E42-for-events
state.

**What we changed.**

- `appointmentsChrome.ts` + `appointmentsTemplates.ts` (new) mirror the events
  pair. `pageTypes.ts` — `#page-appointment-design`,
  `renderAppointmentsTemplate` (fetches
  `/portal/api/appointments/storefront/appointments/{ref}/services` + `/staff`,
  paints `renderAppointmentsView` for the five subpages),
  `renderCurrentAppointmentsPage`; design sync via the shared `syncDesign` /
  `currentDesign`; `page:select` + `bindSelectedInstance` appointments branches;
  `ensureEditorModuleReadyCss` covers `[data-appointments-kind]`.
- `publish.ts` — a `DESIGN_KIND_SEL` table drives all three types;
  `withAppointmentsChrome`; `pageAppointmentDesign` in the compile binding +
  brand accent. `pageLevel.ts` stamps `data-design` for appointments.
- `appointments_component_script.ts` — `injectChrome()` fallback,
  `data-appointments-ready` flag, `#2563eb` / `#cbd5e1` → `var(--afs-*)`; the
  read-only views (`services` / `staff` / `next-available` / `my-bookings`) and
  the booking `shell()` now emit `<section>` + `[data-ap-list]` +
  `[data-ap-flow]` + `<article>` — the same hooks `renderAppointmentsView` uses
  — so the chrome themes them by design rather than by hard-coded inline styles.
- `index.blade.php` — `#appointments-design-section`.
- `AppointmentsDemoSeeder` (mirrors `EventsDemoSeeder`): a published "Demo
  Studio Bookings" — free Consultation + two paid sessions, two staff,
  service↔staff links, Mon–Fri 09:00–17:00 business hours so `/availability`
  returns slots. Registered in `DatabaseSeeder` behind a "no demo appointment
  yet" guard.

**Ops.** `php artisan db:seed --class=AppointmentsDemoSeeder`; `npm run build`;
republish + rebind as E42.

Verified live: bound "Demo Studio Bookings" to `new-page-appointments`,
`design=editorial` + brand `#911818` — `/…-services` / `-staff` show the warm
serif Editorial theme; `/…-booking` shows 24 real slots and a `#911818` Book
button. 471 builder tests, `tsc`, `npm run build` green.

---

## E44 — Two more designs (vivid + slate) + design footers

**Date.** 2026-09-10. **Repo:** `www` (`641d4a2a3`).

**Context.** "Add a couple more designs for each module; add footers to the
designs too."

**What we changed.**

- `branding.ts` — `DESIGN_IDS` grows to six: `vivid` (violet `#7c3aed` / pink
  `#ec4899`, `20px` radius, Trebuchet) and `slate` (`#334155` / sky `#0ea5e9`,
  `6px` radius, flat hero). Every `DesignTheme` gains `footerSurface` /
  `footerInk`; `themeCssVars` emits `--afs-footer-surface` / `--afs-footer-ink`.
  `designFooterHtml(name?)` (new) — the shared events / appointments footer
  markup (a `<footer data-afs-footer>` band with a title, two nav links and a
  `© {year}` line); structure only, `*Chrome.ts` styles it.
- `{events,appointments,ecommerce}Chrome.ts` — the per-design `[data-design]`
  token blocks are now **generated** from `DESIGN_THEMES` at module load
  (`DESIGN_IDS.map(id => selector + "[data-design=..]{" + themeCssVars(theme) +
  "}")`) instead of hand-written, and bare `[data-*-kind]` carries
  `themeCssVars(DESIGN_THEMES.classic)`. Each file adds `footer[data-afs-footer]`
  rules from the footer tokens (full-bleed `margin: 0 calc(50% - 50vw); width:
  100vw`). `ecommerceChrome` footer + `ecommerceTemplates.footerSection` use the
  footer tokens too.
- `eventsTemplates.ts` / `appointmentsTemplates.ts` — every `render*View`
  appends `designFooterHtml`.
- `events_component_script.ts` / `appointments_component_script.ts` — a
  `paintFooter()` appends the same footer after each view is painted (re-run
  after any `innerHTML`); `injectChrome()` fallbacks carry all six designs + the
  footer rules. Appointments `shell()` dropped its remaining inline styles for
  the `[data-ap-flow]` hooks.
- `index.blade.php` — Vivid + Slate options on all three design selects.

**Residue.** The generated `[data-design]` blocks removed the CSS duplication,
but the runtime scripts' `injectChrome()` fallbacks still hand-list the six
designs (a serialised GrapesJS `script` cannot `import`) — `branding.test.ts`
pins the count. Footer nav links are `href="#"` placeholders (events uses
sibling hrefs).

**Ops.** `npm run build`; republish so the page `css` gets the six-design chrome
+ footer rules and the page `js` gets the new runtime. Reload the builder
editor.

Verified live: events rebound `design=vivid` — light-violet hero, `20px`
radius, `#1e1b4b` footer band; appointments rebound `design=slate` — flat
`#911818`-underline hero, `#1e293b` footer band. 473 builder tests, `tsc`,
`npm run build` green.

---

## E45 — Design themes: distinct visual languages, not token swaps

**Date.** 2026-09-10.

**Context.** After E42/E43/E44 the six designs differed only by CSS tokens
(accent, radius, font, heading weight) layered on identical markup, so every
view of every design read as "the same page in a slightly different colour".
The ask: give each design its own visual language — different card treatments,
hero layouts, decoration — and put more content on each view.

**What we changed.**
- `branding.ts` — new `designStructCss(kindSel, sel)` generates the per-design
  STRUCTURAL CSS (hero, cards, features strip, footer, pseudo-elements) once,
  parameterised by the module's hook selectors (`{ hero, card, grid, cta }`).
  The six looks now genuinely diverge:
  - **classic** — soft radial-gradient hero, floating shadow cards that lift on
    hover, pill CTAs with an accent glow.
  - **bold** — black slab hero, clamp-scaled uppercase headline, 3px borders
    with `9px 9px 0` hard offset shadows, `[data-events-list]` becomes a 2-col
    grid, features numbered `01/02/03` with left rules.
  - **minimal** — no hero fill (transparent, left-aligned, weight-300, hairline
    underline), cards are borderless hairline-separated rows, CTA is a
    bottom-border text link, features a flat hairline row.
  - **editorial** — cream framed serif hero with an accent top rule and a
    drop-cap on the subtitle (`::first-letter`), cards carry a short accent rule
    (`::before`), 2-col ruled features.
  - **vivid** — full `linear-gradient(135deg,accent,accent-2)` hero + footer,
    gradient-border rounded cards, glow shadows, pill kicker.
  - **slate** — dark compact left-aligned hero with an accent left bar, rows
    with a 4px accent left bar, features as a bordered stat strip, 4-col footer.
  - new helpers `designFeaturesHtml(variant)` (the under-hero value-prop strip,
    `[data-afs-features]`) and a three-column `designFooterHtml` (was a single
    title + two links). Hero hooks: `<section data-afs-hero>` with
    `[data-afs-kicker]` / `[data-afs-sub]` / `[data-afs-cta]`; content hook
    `[data-afs-note]`.
- `{events,appointments}Chrome.ts` — call `designStructCss` and append it; the
  old thin per-design `section` overrides removed. `hero` selector is
  `section[data-afs-hero]` so per-design rules out-specify the shared
  scaffolding. Appointments slot buttons wrap (`width:auto`), and the design
  CTA treatment no longer bleeds onto them.
- `eventsTemplates.ts` / `appointmentsTemplates.ts` — every view emits the hero
  hooks, a subtitle and (list / booking) the features strip; detail/services/
  staff/next-available/my-bookings gained real copy, a `[data-afs-note]` block
  and richer `[data-*-meta]` lines.
- `events_component_script.ts` / `appointments_component_script.ts` — the
  runtime paints the same hooks (`featuresStrip()`, hero attrs, 3-col
  `paintFooter()`); `injectChrome()` fallbacks carry a condensed per-design
  structural block.
- `branding.test.ts` — pins `designFeaturesHtml` (3 items/variant) and that
  `designStructCss` gives every design a hero/card override plus the
  bold-grid / vivid-gradient / editorial-`::before` markers.

**Files.** `resources/js/portal/builder/core/branding.ts`,
`core/{events,appointments}Chrome.ts`,
`core/{events,appointments}Templates.ts`,
`core/component_scripts/{events,appointments}_component_script.ts`,
`resources/js/portal/builder/tests/branding.test.ts`.

**Ops.** `npm run build`; republish the bound pages so `css` picks up
`designStructCss` and `js` the new runtime. A throwaway `gen-module-pages.mjs` (compiles rows via the real
`compileRuntime`) + an in-place published-row refresh were used to flip the demo
pages through all six designs for verification, then removed.

**Residue.** Ecommerce chrome still uses the thin token-only `[data-design]`
blocks — its templates carry their own hero/footer system (E42 §6.5), so
`designStructCss` was not wired there this pass. `injectChrome()` fallbacks
still hand-list the designs (a serialised runtime cannot `import`).

Verified live in `projeKEnNosrOA3M`: `new-page-events*` cycled through all six
designs and `new-page-appointments*` through classic/bold/editorial/slate —
each reads as a distinct product. 475 builder tests, `tsc`, `npm run build`
green. Demo pages left on `design=vivid` (events) / `design=slate`
(appointments) to match `projectData`.

---

## E46 — Module-registry tests updated for site_users' register()-less exception

**Not a plan-08 step** — fallout from it. `08-site-users-pages.md` §8.2 makes `site_users` the
first page-level module with **no `register()` at all** (events/appointments keep one only to
hold legacy `addType()` for a project still open with an old block — 07 §7.6 — which
`site_users` never needed, since its three retired kinds migrate via `retired.ts`'s raw-HTML
scan, not GrapesJS type deserialization). Five pre-existing tests in `modules.test.ts` and one
in `handoffs.test.ts` had hardened that gap shut with `mod.register!(editor)` (non-null
assertion) or a pinned exact-match list assuming every module has one:

- `handoffs.test.ts` "no declared module adds a retired type to the palette" —
  `m.register!(editor)` → `m.register?.(editor)`.
- `modules.test.ts` "every module has a real script, or a reason it has none" — the
  `externalUi()` pin gains `site_users`.
- `modules.test.ts` "EVERY declared module registers itself — no exceptions left" — inverted
  to name the one exception, matching the file's own established pattern for `noRuntime()`
  (see `presentational` in the same test) rather than silently loosening the assertion.
- `modules.test.ts` "v2 P0 — only ecommerce, events and appointments are page-level" — pin
  gains `site_users`.
- `modules.test.ts`'s four "registry-driven registration (07 P0)" subtests — each guarded with
  `if (!mod.register) continue;` before calling it.

**Files.** `resources/js/portal/builder/tests/{handoffs,modules}.test.ts`.

**Verification.** `npm run test:builder` — 465/465 green (was 457/465 before these fixes, with
the 8 failures listed above).

---
