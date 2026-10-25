# 12 — Builder Integration

How Site Users appears in the website builder, and how a published page guards content.

## 12.1 What exists today

**Nothing for Site Users.** A grep of `resources/js/portal/builder/` for `systemsusers`,
`auth1`, `login` or `register` returns no integration — only unrelated matches
(`registerComponents`, `dialogInput`). There is no auth block, no auth component, no notion
of a signed-in visitor anywhere in the builder.

What *does* exist is a complete and well-shaped extension model, which this document uses
rather than invents.

### The three-layer component model

```
core/blocks/modules.ts          bm.add('module-forms', { content: { type: 'module-form' } })
        │                            the draggable tile in the left panel
        ▼
core/components.ts              domc.addType('module-form', { model: { defaults: {
        │                            traits, attributes, script } } })
        │                            the component type, its settings panel, its runtime
        ▼
core/component_scripts/*.ts     export function formsComponentScript() { … }
                                     runs on the PUBLISHED page, calls window.Portal.*
```

`script` on a component's `defaults` is the whole mechanism: GrapesJS serialises it into the
published HTML, and it executes there. `forms_component_script.ts` is the working reference —
102 lines that read a trait off the root element, clear the placeholder, and hand the
container to `window.Portal.forms`.

### Two conventions every component script follows

```ts
const editorMode = isEditorMode();      // parent has .gjs-editor, or .gjs-cv-canvas present
if (editorMode) return;                 // never run the real integration in the canvas

waitForPortal(init);                    // poll for window.Portal.<ns>, 20 × 100ms
```

Both matter and both are easy to omit. Without the first, the editor canvas starts making
authenticated API calls while a tenant is dragging boxes. Without the second, the component
races the SDK `<script>` tag and fails on slow connections roughly one load in ten.

### The published page already loads the SDK

`resources/views/components/layout.blade.php:23`:

```blade
<script src="https://{{ $afssdk }}.appfactory.studio/sdk.js"></script>
```

So `window.Portal` — and therefore `window.Portal.siteUsers` once
[07](07-externals-api.md) ships — is present on every published tenant page with no build
change. **The builder integration needs no new script loading.**

### Dynamic components come from `builder_output`

`registerDynamicComponents()` builds a select trait from
`GET /portal/api/v1/builder/components`, which returns `builder_output` rows grouped by
module — listings, discussions, feedback, commerce. `registerSimpleModuleComponent()` swaps
the component's inner HTML when the trait changes.

Site Users differs: there is nothing to *select*. A login form is a login form. Which is why
§12.3 registers static component types rather than dynamic ones.

Two findings, defined with the rest in [01](01-current-state-audit.md) §1.5b: there is **no
Site Users presence in the builder at all** (**M19**), and the Direct Payments component is a
**static HTML mockup** with no `script` (**M20**) — so it is the wrong thing to copy.
`forms_component_script.ts` is the real integration.

## 12.2 What the builder needs

Two separable things, and conflating them is the main design risk:

| | What it is | Where it lives |
|---|---|---|
| **Auth components** | Login, register, account, reset — visible UI a tenant drags onto a page | §12.3 |
| **Route protection** | "this route requires sign-in" — enforced server-side, before any HTML is sent | §12.5 |
| **Visibility** | Showing or hiding content *within* a page a visitor may already see | §12.6 |

The second and third are different things and must not be conflated. **Route protection is
access control**; visibility is presentation. A wrapper component would have been the obvious
design for both and is wrong for either: a tenant would have to nest their whole members area
inside a box, and anything dragged out of it silently loses protection.

## 12.3 Auth components

A new `Site Users` block category, registered from `core/blocks/modules.ts`:

| Block | Type | Renders |
|---|---|---|
| Login | `siteusers-login` | `su.loginFlow().mount(root)` |
| Register | `siteusers-register` | `su.registerFlow().mount(root)` |
| Account | `siteusers-account` | Profile, sessions, change password |
| Password reset | `siteusers-reset` | Request + redeem, driven by a URL token |
| User menu | `siteusers-menu` | Signed-in name + logout, or a login link |

All five follow `forms_component_script.ts`'s shape. The login one, in full outline:

```ts
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/site_users/12-builder-integration.md §12.3.
 *
 * Mounts the Site Users login flow on a published page. Modelled on
 * forms_component_script.ts, including both of its guards:
 *   - isEditorMode() so the canvas never authenticates while a tenant edits
 *   - waitForPortal() because sdk.js is a separate <script> and may not have
 *     executed when this component mounts
 *
 * The module id comes from a TRAIT, not a hardcoded value: one site may host
 * more than one Site Users module (03-architecture.md §3.3), and the tenant
 * picks which in the settings panel.
 */
export function siteUsersLoginScript() {
    const root = this as HTMLElement;
    const moduleId = root.getAttribute('site_users_module') || '';
    const redirect = root.getAttribute('redirect_to') || '';

    if (isEditorMode()) return;          // canvas shows the §12.4 placeholder
    if (!moduleId) { renderUnconfigured(root); return; }

    waitForPortal('siteUsers', async () => {
        const su = window.Portal.siteUsers.create(moduleId);
        await su.init();
        if (su.isAuthenticated()) { location.href = redirect || '/'; return; }
        su.loginFlow().mount(root, { onSuccess: () => location.href = redirect || '/' });
    });
}
```

Traits per component: **module** (select, from the modules list), **redirect after success**,
and **show SSO buttons**. Nothing that duplicates server-side policy — password rules come
from `su.config()` ([05](05-authentication.md) §5.4), so a tenant cannot set a weaker one in
the builder than the module enforces.

`waitForPortal` should be **lifted out of the individual scripts** into a shared helper. It
is currently copy-pasted into `forms_component_script.ts` and `script_runner.ts` with
slightly different retry counts, and adding five more copies is how the drift becomes
permanent.

## 12.4 The editor canvas

`isEditorMode()` returns early, so the canvas must render something. A blank div is what a
tenant reports as "the login block is broken".

Each component renders a static preview in the editor — the same shape
`registerSimpleModuleComponent()` already uses for unconfigured modules:

```
┌────────────────────────────────────┐
│              🔐                    │
│         Login (Site Users)         │
│   Module: Main Site Users (su-a1b2)│
│   Renders the live login form on   │
│   the published page.              │
└────────────────────────────────────┘
```

And when no module is selected, it says so and points at the settings panel — the pattern
`registerSimpleModuleComponent()` uses verbatim for its own unconfigured state.

## 12.5 Routes are data, and that is what makes protection real

The correction that changes this document's design.

### How a tenant site actually serves a page

The tenant's website **is** the root domain. `/portal` is the only reserved prefix, and
everything else falls through to a database lookup:

```
GET /account/orders
        │
routes/web.php   Route::get('/{subpaths}', [BuilderPublishedController::class, 'show'])
        │            ->where('subpaths', '^(?!portal|auth1|_portal_).*$')
        ▼
BuilderPublishedController::show($request, 'account/orders')
        │
        │   SELECT * FROM laravel_builder_published
        │   WHERE subpaths = 'account/orders' AND is_active = true
        ▼
view('index', ['html' => $page->html, …])
```

`GET /` is mapped explicitly to the `index` slug (`web.php:147`). The `.*` pattern spans
slashes, so `/route/protected` is one lookup on `subpaths = 'route/protected'` — nested
routes need no extra work.

The `www.appfactory.studio` and `www15.appfactory.studio` `Route::domain()` groups above it
are the **platform's own site**, not tenant behaviour. They are matched before the generic
routes by host and are irrelevant to a deployed tenant.

### Every dynamic route passes through one function

**That is the whole opportunity.** `show()` is a single chokepoint through which every
builder-served page flows, on the server, before any HTML is written to the response.

So a protected route is not a redirect layered over content that was already sent. It is
content that **is never sent**:

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/site_users/12-builder-integration.md §12.5.
 *
 * Route-level authentication for builder-published pages.
 *
 * Runs BEFORE the page HTML is read or rendered. An unauthenticated visitor to
 * a protected route receives a redirect (or a 404) and NOTHING ELSE — no
 * markup, no CSS, no page title. This is real access control, not the
 * visibility trait's presentation layer (§12.6).
 *
 * Routes are database rows, not code, so this cannot be Laravel route
 * middleware: the routes are unknown until the lookup that this check is part
 * of. The check therefore lives at the chokepoint every dynamic route already
 * passes through.
 */
public function show(Request $request, $subpaths)
{
    $page = LaravelBuilderPublished::where('subpaths', $subpaths)
        ->where('is_active', true)->first();

    if (! $page) abort(404);

    // ── route guard ────────────────────────────────────────────────
    $route = BuilderRoute::forSlug($subpaths);              // §12.5.2

    if ($route?->requires_auth) {
        $user = app(SiteUsersGateway::class)->currentUser($request);

        if (! $user) {
            return $route->unauthenticated_behaviour === 'not_found'
                ? abort(404)
                : redirect($route->login_route ?: '/login')
                    ->with('su_intended', $request->fullUrl());
        }

        if ($route->require_verified_email && ! $user->email_verified_at) {
            return redirect($route->verify_route ?: '/verify-email');
        }
    }
    // ───────────────────────────────────────────────────────────────

    // …existing render…
}
```

Two behaviours, both worth offering:

| `unauthenticated_behaviour` | Response | When |
|---|---|---|
| `redirect` *(default)* | 302 to the login route, with the intended URL preserved | Normal member areas — the visitor should be able to sign in and land where they meant to |
| `not_found` | 404, identical to a slug that does not exist | Routes whose **existence** is sensitive. `/admin-portal` returning 302-to-login confirms it is there |

`su_intended` in the session is what makes "sign in, then land on the page you asked for"
work. Without it every protected link dumps the visitor on the home page after login, which
is the single most common complaint about bolt-on auth.

### 12.5.1 Protection belongs to the route, not to the version

`laravel_builder_published` is **versioned** — one row per page per publish, with `is_active`
flipping between them, and "Republish" restoring an old version.

If `requires_auth` lived on that row, this would happen:

1. A tenant publishes `/account` (v3), then protects it.
2. Something breaks; they republish v2 to roll back.
3. **`/account` is public again**, because v2's row was written before the flag existed —
   and nothing in the rollback UI said so.

A content rollback must not silently revoke a security setting. So route configuration lives
in its own table, keyed on the slug, unversioned:

**`laravel_builder_routes`**

| Column | Type | Notes |
|---|---|---|
| `id` | `id` | |
| `subpaths` | `string(255)` **unique** | The slug. Matches `laravel_builder_published.subpaths` |
| `project_reference_num` | `string` null index | Which project owns it |
| `requires_auth` | `boolean` default `false` | |
| `site_users_id` | `string(32)` null | Which module ([03](03-architecture.md) §3.3) |
| `require_verified_email` | `boolean` default `false` | [05](05-authentication.md) §5.5 |
| `unauthenticated_behaviour` | `string(20)` default `redirect` | `redirect` \| `not_found` |
| `login_route` | `string(255)` null | Default `/login` |
| `verify_route` | `string(255)` null | Default `/verify-email` |
| `timestamps` | | |

A row exists only for a route with non-default configuration, so an unprotected site has an
empty table and the lookup is one indexed miss.

**The row survives republishing, unpublishing and version rollback.** It is deleted only when
the tenant deletes the route or clears its protection — both explicit acts, both in the
builder UI, both with a confirmation that names what is being unprotected.

### 12.5.2 Inheritance — `/route` and `/route/protected`

A tenant builds `/members`, then adds `/members/orders` and `/members/settings`. Protecting
each individually is how one gets missed.

`BuilderRoute::forSlug()` resolves **most-specific-first, with inheritance**:

```
/members/orders/42
  → exact       'members/orders/42'
  → ancestor    'members/orders'
  → ancestor    'members'
  → root        ''            (site-wide default)
  → null        (public)
```

The first match wins, and a descendant may **tighten** but not **loosen**: a child row with
`requires_auth = false` under a protected parent is rejected at save time with a message
saying so, rather than silently creating a hole under a protected tree.

That asymmetry is the point. Inheritance that can be turned off by a child is not
inheritance; it is a default, and someone will eventually create `/members/public-beta` and
punch a hole through a members area without realising.

A site-wide default is the empty slug — useful for a fully private site, where the *unprotected*
routes are then the exceptions (`/login`, `/register`, `/reset`). Those must be explicitly
public, which is why they are the one place the loosening rule is relaxed: a route may
declare itself `public: true`, which the builder surfaces prominently and which is checked
before inheritance runs.

**Without that escape hatch a site-wide guard locks the tenant out of their own login page**,
and they will discover it after publishing.

### 12.5.3 The cost

One extra query per page load, on an indexed unique column, returning zero rows for most
sites.

Cache it — the route table changes only on publish or on a settings save. A per-request
in-memory cache plus a tenant-wide cache keyed on a version stamp bumped by both operations
keeps a protected site at zero additional queries in steady state.

**Do not cache the authentication check itself**, only the route configuration. The
configuration is stable; who is signed in is per-request.

## 12.6 The visibility trait — presentation, and only that

§12.5 is access control. This is not, and the distinction has to survive contact with
whoever implements it.

Components carry an optional trait for showing and hiding content *within* a page a visitor
is already allowed to see:

| Trait | Values |
|---|---|
| `su_visibility` | `always` *(default)* · `signed_in` · `signed_out` |

`signed_out` earns its place: a Login block should disappear once someone is signed in, and a
"Create account" call-to-action should not follow people who have one.

```ts
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/site_users/12-builder-integration.md §12.6.
 *
 * Applies su_visibility on a published page.
 *
 * THIS IS PRESENTATION ONLY. The markup is already in the document; view-source
 * defeats it. It exists to make a page LOOK right for the visitor, not to
 * restrict anything.
 *
 * Real protection is either:
 *   - route-level (§12.5), where the HTML is never sent, or
 *   - module-level, where the API refuses the request
 *     (09-app-integrations.md §9.3)
 *
 * If you are reaching for this trait to protect something, you want §12.5.
 */
```

To avoid a flash of protected content, marked elements are published with
`style="visibility:hidden"` and **revealed** after resolution, rather than shown and then
hidden. A `<noscript>` rule keeps them hidden entirely.

### When to use which

| Goal | Use |
|---|---|
| A members-only page | **§12.5** route protection |
| A whole members area | **§12.5** on the parent route (§12.5.2) |
| A "Hi, Jane / Log out" header on a public page | §12.6 `signed_in` |
| Hiding a Login block from signed-in visitors | §12.6 `signed_out` |
| Member-only pricing on a public product page | **Neither** — the API must not serve those prices ([09](09-app-integrations.md) §9.3) |

The last row is the one that catches people, and it is exactly the ecommerce case
(§12.8).

## 12.7 Where a tenant configures it

Route settings belong with route creation. In the builder, pages are managed by
`core/pageManager.ts` — that is where a tenant names a page and gets its slug, so that is
where the protection panel goes:

```
Page settings — /members/orders
─────────────────────────────────────────────
  Name          Orders
  URL           /members/orders

  🔒 Require sign-in                    [ ✓ ]
     Site Users module   [ Main (su-a1b2) ▾ ]
     Require verified email             [   ]
     If not signed in    ( • ) Send to login page
                         (   ) Show "not found"
     Login page          [ /login ▾ ]

  ⓘ Inherited from /members — this page is already
    protected. Turning this off here is not permitted.
─────────────────────────────────────────────
```

Three things the panel must get right, because each corresponds to a way this goes wrong:

- **Show inherited state.** A tenant looking at `/members/orders` must see that it is already
  protected by `/members` (§12.5.2), or they will assume it is public and protect it again —
  or worse, assume it is protected when the parent row was deleted.
- **Explain `not_found` in words**, not as a mode name. *"Visitors who aren't signed in see a
  'page not found' error instead of being sent to the login page. Use this when the page's
  existence is itself private."*
- **Confirm removal.** Unchecking `Require sign-in` on a route that has it is the one action
  here that reduces security, and it deserves the same friction as deleting a page:
  *"/members/orders will become publicly visible to anyone with the link."*

Route settings save **independently of publishing**. A tenant protecting a route should not
have to republish the site for it to take effect — and conversely, publishing must never
reset it (§12.5.1). Saving writes `laravel_builder_routes` and bumps the cache stamp
(§12.5.3).

Site-wide defaults live in the project settings rather than per page, with the same
inheritance rules and a prominent list of which routes are currently public exceptions.

## 12.8 The API guard — protection without per-module integration

Route protection (§12.5) secures the **page**. This secures the **API calls the page makes**,
and it is the piece ecommerce depends on.

### 12.8.1 What protects an API endpoint today

Nothing.

| Mechanism | What it actually does |
|---|---|
| `Portal.httpUrl` | `window.location.origin` (`external/main.ts:109`), prefixed onto fetch URLs. A **base URL**, not an access control. It restricts nothing |
| `PortalCorsMiddleware` | An **explicit no-op** — its own docblock says CORS is handled by Nginx |
| Nginx CORS headers | A **browser** courtesy. `curl` ignores them completely |
| Client-side checks | Read the URL out of devtools once and they are gone |

So `GET /api/ecommerce/products` returns data to anyone, from anywhere, regardless of what
the page around it does. A members-only storefront whose product API is open leaks precisely
the data the page was hiding.

That is **M21**, defined in [01](01-current-state-audit.md) §1.5b.

### 12.8.2 The requirement this has to satisfy

**Site Users must not be integrated into each module's PHP.** Ecommerce, events,
appointments and the rest should not each grow their own auth check — that is how a system
ends up half-adopted, with five notions of "signed in" and four of them subtly wrong.

That requirement is right, and it does **not** imply client-side protection. It implies a
**generic, config-driven guard**: one enforcement point, no module changes.

### 12.8.3 The guard

Path patterns in a table, exactly like routes (§12.5.1), enforced by one middleware:

**`laravel_api_guards`**

| Column | Type | Notes |
|---|---|---|
| `id` | `id` | |
| `path_pattern` | `string(255)` | e.g. `api/ecommerce/products*`, `api/v1/appointments/*` |
| `methods` | `string(64)` null | `GET,POST`. Null = all. Read-open/write-closed is a common shape |
| `requires_auth` | `boolean` default `true` | |
| `site_users_id` | `string(32)` null | Which module |
| `require_verified_email` | `boolean` default `false` | |
| `is_active` | `boolean` default `true` | |
| `timestamps` | | |

```php
$table->unique(['path_pattern', 'methods'], 'api_guards_pattern_uq');
```

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/site_users/12-builder-integration.md §12.8.
 *
 * Config-driven authentication for module APIs.
 *
 * THE POINT: ecommerce, events and appointments contain NO auth code. They do
 * not import SiteUsersGateway, do not know this middleware exists, and do not
 * change when a tenant protects one of their endpoints. Protection is a row in
 * laravel_api_guards, enforced here.
 *
 * FAILS OPEN BY DEFAULT — a path with no matching guard row passes through
 * untouched. That is deliberate: this ships onto deployments whose APIs are
 * currently public, and a default-closed guard would break every existing
 * tenant site on deploy. Protection is opt-in per path.
 *
 * That default is the one thing to revisit later (§12.8.6).
 */
final class SiteUsersApiGuard
{
    public function handle(Request $request, Closure $next)
    {
        $guard = ApiGuardRegistry::match($request->path(), $request->method());
        if (! $guard) return $next($request);                 // no rule → open

        $user = app(SiteUsersGateway::class)->currentUser($request);

        if (! $user) {
            return response()->json([
                'error' => 'Authentication required.',
                'code'  => 'SU_E_AUTH_REQUIRED',
            ], 401);
        }

        if ($guard->require_verified_email && ! $user->email_verified_at) {
            return response()->json([
                'error' => 'Email verification required.',
                'code'  => 'SU_E_EMAIL_UNVERIFIED',
            ], 403);
        }

        $request->attributes->set('site_user', $user);        // §12.8.5
        return $next($request);
    }
}
```

Registered once on the API route groups in `bootstrap/app.php`. **No module's routes,
controllers or models are edited.**

`ApiGuardRegistry::match()` is cached like the route table (§12.5.3) — the rules change only
when a tenant saves them. Most sites have an empty table and pay one cache read.

### 12.8.4 Why the guard is server-side, stated once

The client cannot protect a request it makes. The URL, the headers and the payload are all
visible in devtools and reproducible with `curl`. Client-side checks make the **UI** correct;
they cannot make the **API** correct.

That is not an argument against client-side checks — the SDK should still hide what a visitor
cannot use, and §12.6 covers that. It is an argument that the client check is the *second*
layer, never the only one.

### 12.8.5 What a module gets for free

A module that wants to *use* the identity — rather than merely be protected by it — reads it
off the request without importing anything:

```php
$user = $request->attributes->get('site_user');   // ?SiteUser, null when unguarded
```

That is the entire integration surface for attribution: ecommerce writes
`site_user_id` from it ([09](09-app-integrations.md) §9.3) and is otherwise unchanged.

### 12.8.6 Defence in depth — worth adding, none of it load-bearing

The guard above is the control. These reduce the blast radius around it:

| Layer | What it buys | What it does not |
|---|---|---|
| **Origin/Referer check** on guarded paths | Stops trivial cross-site use of a stolen session cookie from a browser | Nothing against `curl` — the header is client-set |
| **`SameSite=Lax` session cookie** ([07](07-externals-api.md) §7.4) | Real CSRF protection, browser-enforced | Nothing outside a browser |
| **Rate limiting per session and per IP** on guarded paths | Turns a scrape into a slow scrape | Not authorisation |
| **Response scoping** — a guarded endpoint returns only the caller's rows | The strongest of these. A leak becomes one user's data, not everyone's | Requires the module to filter, so it is *not* zero-integration |
| **Audit log** of guarded-path denials | Tells you the guard is being probed | Prevention |

The fourth row is the honest exception to "no module integration": a guard can stop a
stranger reading `/api/ecommerce/orders`, but only ecommerce can ensure a signed-in user
reads *their own* orders. **Authentication is generic; authorisation is not.**

So the division to hold:

| Question | Who answers |
|---|---|
| Is anyone signed in? | The guard (§12.8.3) — generic, zero integration |
| Is this *their* data? | The module — per-module, unavoidable |

For ecommerce that is one `where('site_user_id', $user->id)` on the order queries, and
nothing on products.

### 12.8.7 Where a tenant configures it

Alongside route settings (§12.7), because a tenant thinks *"my members area is protected"*,
not *"my page and separately my API"*.

The builder's page-settings panel offers, when a page is protected:

```
  🔒 Also protect this page's data
     [ ✓ ] Ecommerce products & orders
     [   ] Appointments
     [   ] Events

  ⓘ Without this, the page is protected but the data it
    loads can still be read directly.
```

Each checkbox writes the module's declared API patterns into `laravel_api_guards`. The
patterns come from the module registry ([09](09-app-integrations.md) §9.2) — a module
declares which paths are guardable, so a tenant never types a path pattern.

That note under the checkboxes is the most important sentence in the panel, and it should not
be softened.

### 12.8.8 Acceptance

- `curl` to a guarded path with no session returns **401** — with no `Origin`, no cookie, no
  browser (**M21**).
- The same path with a valid session cookie returns data.
- An **unguarded** path is unaffected — no behaviour change on a deployment that configures
  nothing.
- `grep -rn "SiteUsers\|site_user" app/Http/Controllers/Ecommerce*` finds **only**
  `$request->attributes->get('site_user')` — no auth logic in the module (§12.8.2).
- Protecting a page's data from the builder panel writes guard rows and takes effect without
  a republish (§12.8.7).
- A guarded orders endpoint returns **only the caller's** orders — the module's own filter,
  not the guard's (§12.8.6).
- Guard rules are cached; a guarded site adds no per-request query in steady state.

## 12.9 What ecommerce needs from this — the handoff

The next piece of work guards ecommerce. Three layers now exist, and the split matters:

| Layer | Secures | Ecommerce's work |
|---|---|---|
| **Route protection** (§12.5) | The **page**. Never sent to a stranger | **None** |
| **API guard** (§12.8) | The **endpoints** the page calls. 401 without a session | **None** — it is a config row |
| **Ownership filtering** (§12.8.6) | *Whose* data comes back | **This one.** `where('site_user_id', …)` on orders |

So ecommerce's integration is genuinely small, and it is exactly the part that cannot be
generic:

```php
// The whole of it, in the order-listing path:
$user = $request->attributes->get('site_user');       // set by the guard
$orders = Order::where('site_user_id', $user->id)->get();
```

Plus attribution at checkout — writing `site_user_id` on the order — and the
[08](08-direct-payments-link.md) customer link, which is what makes saved cards follow the
account and is the visible payoff of the whole system.

**Authentication is generic; authorisation is not.** The guard can prove someone is signed
in without ecommerce knowing it exists. Only ecommerce can decide that *this* user may read
*that* order. Any design that claims otherwise has quietly moved ecommerce's business rules
into a middleware that does not understand them.

## 12.10 Build order

1. **Shared helpers** — lift `waitForPortal` and `isEditorMode` out of the existing scripts
   into `component_scripts/helpers.ts`. Small, and it stops five new copies (§12.3).
2. **Login + Register components** — the two that make the system reachable at all.
3. **`laravel_builder_routes` + the `show()` guard** (§12.5) — the page is never sent.
4. **`laravel_api_guards` + `SiteUsersApiGuard`** (§12.8) — the endpoints behind it. Without
   this, step 3 is bypassed by reading the URL out of devtools.
5. **Settings UI** for both (§12.7, §12.8.7).
6. **Account, reset, user menu**, and the visibility trait (§12.6).

Steps 1–4 are what ecommerce needs. **3 and 4 ship together** — route protection without the
API guard protects the page and not the data, which is the more dangerous state because it
looks finished.

## 12.11 Acceptance

- Dragging Login onto a page and selecting a module produces a working login on the published
  site, with no code.
- The editor canvas shows the §12.4 placeholder and makes **no** API calls (`isEditorMode`).
- A component with no module selected says so, in the canvas and on the published page.
- Removing `sdk.js` from the page makes the components fail visibly, not silently — and a
  slow SDK load still resolves via `waitForPortal`.
- **A protected route returns no page HTML to an unauthenticated visitor** — assert on the
  response body, not just the status code (§12.5).
- `unauthenticated_behaviour: not_found` returns a 404 **indistinguishable** from a
  nonexistent slug.
- Signing in after being redirected lands on the originally requested URL (`su_intended`).
- Protecting `/members` protects `/members/orders` without a second row (§12.5.2).
- A child route cannot declare itself unprotected under a protected parent — rejected at
  save time with a message.
- A site-wide guard plus `public: true` on `/login` leaves the tenant able to sign in.
- **Republishing an older version does not unprotect a route** (§12.5.1) — the regression
  this design exists to prevent.
- Route config is cached; a protected site adds zero queries in steady state (§12.5.3).
- `su_visibility: signed_in` content is **not visible before** the check resolves (§12.6).
- Same content is **present in view-source** — asserted, so nobody mistakes it for
  protection.
- `waitForPortal` and `isEditorMode` exist **once** (§12.10 step 1).
- A second Site Users module on the same site is selectable per component (§12.3).
