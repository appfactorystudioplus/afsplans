# 06 — Guarding Checkout, Registration and Booking

The user-facing half of `required` mode, and the guest-checkout choice as a visitor meets it.
The server side is [04](04-connection-registry-and-enforcement.md) §4.5; nothing here is
load-bearing for security. It exists so that a mode a tenant can enforce is also a mode a
visitor can *use*.

## 6.1 What a visitor experiences

| Connection | Guest checkout | Cart / ticket / slot chosen → checkout step |
|---|---|---|
| none | forced on | Today's flow. No sign-in offered, no account mentioned |
| yes | on | The checkout shows an optional **"Sign in for faster checkout"** row above the email field. Signed in: email prefilled, saved cards offered ([07](07-direct-payments-connection.md)), order attributed |
| yes | off | The checkout step is replaced by a **sign-in gate**: the login form inline, *Create account*, and **no** *Continue as guest*. On success the checkout continues in place, cart intact |

The gate is the same component on all three storefronts, so a tenant's visitors learn it
once, and so [builder/v2/09](../builder/v2/09-single-source-ui.md)'s rule holds — the
login form's markup exists once, in `external/ui/site_users/ui.ts` (`buildLoginMarkup`), and
the gate imports it.

## 6.2 Guest checkout, as the UI reads it (K4)

The UI never decides; it reads `site_users.guest_checkout` from the instance's public config
([04](04-connection-registry-and-enforcement.md) §4.6) and shows the matching surface. If the
UI is wrong or old, the server still refuses — that is the test in §6.8 that runs from
`curl`.

```ts
// external/ui/shared/authGate.ts

/** The `site_users` block every connected instance's public config carries (02 §2.7). */
export interface SiteUsersBlock {
    connected: boolean;
    guest_checkout: boolean;
    module?: string;
    login_path?: string | null;
    register_path?: string | null;
    dashboard_path?: string | null;
}

export type GateOutcome =
    | { kind: 'signed_in'; user: PortalSiteUserPublic; su: PortalSiteUsersInstance }
    | { kind: 'guest' }                 // allowed only when block.guest_checkout
    | { kind: 'not_connected' };        // block.connected === false — behave exactly as today

/**
 * Decide, without rendering anything, what the checkout may do right now.
 * Cheap: one `me()` when connected, nothing when not.
 */
export async function gateState(block: SiteUsersBlock): Promise<GateOutcome> {
    if (!block.connected || !block.module) return { kind: 'not_connected' };
    const su = window.Portal.siteUsers.create(block.module);
    try {
        const user = await su.me();
        return { kind: 'signed_in', user, su };
    } catch {
        return block.guest_checkout ? { kind: 'guest' } : { kind: 'guest' /* caller must render the gate */ };
    }
}
```

The second `guest` return is deliberate: `gateState()` reports *state*; `requireSignIn()`
below enforces *policy*. Keeping them apart lets the events registration form show the
optional sign-in row (state: guest, policy: allowed) and the ecommerce checkout show the
hard gate (state: guest, policy: refused) from one primitive.

## 6.3 `requireSignIn()` — the gate

```ts
export interface GateOptions {
    /** Called once a session exists (already, or after the inline sign-in). */
    onSignedIn(user: PortalSiteUserPublic, su: PortalSiteUsersInstance): void | Promise<void>;
    /** Called when the visitor chooses to continue as a guest. Only invoked if the block allows it. */
    onGuest(): void | Promise<void>;
    /** Copy for the heading; the module supplies its verb — "to complete your order", "to register", "to book". */
    purpose: string;
}

/**
 * Renders the gate INTO `host` when required, or calls straight through when
 * not. Idempotent per host. Never navigates away unless the block gives a
 * `login_path` AND the inline form cannot mount (no Portal.siteUsers).
 */
export async function requireSignIn(host: HTMLElement, block: SiteUsersBlock, opts: GateOptions): Promise<void> {
    const state = await gateState(block);
    if (state.kind === 'not_connected') { await opts.onGuest(); return; }
    if (state.kind === 'signed_in')     { await opts.onSignedIn(state.user, state.su); return; }

    const su = window.Portal.siteUsers.create(block.module!);
    const config = await su.init();
    host.innerHTML = buildGateMarkup(config, block, opts.purpose);       // §6.4 — pure

    const form = host.querySelector('form[data-su-login]') as HTMLFormElement;
    wireLoginForm(form, su, async () => {                                // the same wiring renderLogin() uses, exported
        const user = await su.me();
        host.innerHTML = '';
        await opts.onSignedIn(user, su);
    });

    host.querySelector('[data-su-gate-guest]')?.addEventListener('click', async (e) => {
        e.preventDefault();
        host.innerHTML = '';
        await opts.onGuest();
    });

    const returnTo = window.location.pathname + window.location.search;
    (host.querySelector('[data-su-gate-register]') as HTMLAnchorElement | null)
        ?.setAttribute('href', (block.register_path || '#') + '?redirect=' + encodeURIComponent(returnTo));
}
```

`wireLoginForm()` is the existing submit handler inside `renderLogin()` in
`site_users/ui.ts`, lifted into an exported function so the gate and the Login page share
one implementation of "submit → `su.login()` → error copy".

## 6.4 The gate's markup — pure, exported (K14)

```ts
// external/ui/site_users/ui.ts  (exported so shared/authGate.ts and the builder preview import it)

/**
 * The inline sign-in gate a connected checkout shows. Composes buildLoginMarkup()
 * — the ONLY login form markup in the codebase — inside a panel that states why,
 * offers registration, and offers guest checkout ONLY when the block allows it.
 */
export function buildGateMarkup(config: PortalSiteUsersConfig, block: SiteUsersBlock, purpose: string): string {
    const guest = block.guest_checkout
        ? '<button type="button" class="su-btn-secondary" data-su-gate-guest>Continue as guest</button>'
        : '';
    const register = config.allow_registration
        ? '<a data-su-link data-su-gate-register href="#">New here? Create an account</a>'
        : '';
    return '<div data-su-gate>'
        + '<section data-afs-hero><h1>Sign in ' + esc(purpose) + '</h1>'
        + '<p data-afs-sub>' + (block.guest_checkout
            ? 'Sign in to save your details and see this in your account, or continue as a guest.'
            : 'An account is required here. It only takes a minute to create one.') + '</p></section>'
        + buildLoginMarkup(config)
        + '<div data-su-gate-actions>' + guest + register + '</div>'
        + '</div>';
}
```

The `data-afs-hero` / `[data-su-panel]` hooks mean the tenant's chosen design theme styles
the gate for free on any page whose CSS carries the Site Users chrome; on a store page
(commerce chrome) the fallback `injectChrome()` in `site_users/ui.ts` is invoked by the gate
so the form is never unstyled.

### Registration and the return trip

*Create an account* links to the module's register page with `?redirect=`. The Register page
(`renderRegister()`) already redirects to the dashboard on success; it gains one rule: **if
`?redirect=` is a same-origin path, go there instead**. Same-origin is checked by
constructing `new URL(redirect, location.origin)` and comparing `origin` — an absolute URL to
another host is ignored. The visitor lands back on the checkout, now signed in, cart intact
(the cookie cart merges into the account cart on the next request —
`EcommerceCartService::resolveCartForShopper()`, already built).

When `register_path` is `null` (pages never published), the gate hides the register link and
shows *"Ask the site owner for an account"* — honest, and rare.

## 6.5 Handling the refusal codes — defence in depth

Every storefront wraps its anonymous-sensitive calls so that a `401` whose code ends in
`_E_AUTH_REQUIRED` (or equals `SU_E_AUTH_REQUIRED`) re-renders the gate, using the
`module` hint from the error when the page's block is stale or missing:

```ts
// external/ui/shared/authGate.ts
export function isAuthRequired(err: unknown): { module?: string } | null {
    const e = err as { code?: string; module?: string; status?: number } | undefined;
    if (!e || typeof e.code !== 'string') return null;
    if (e.code === 'SU_E_AUTH_REQUIRED' || /_E_AUTH_REQUIRED$/.test(e.code)) return { module: e.module };
    return null;
}
```

The SDK clients (`commerce/api.ts` `fetchJson`, `events/api.ts`, `appointments/api.ts`)
already throw on non-2xx; they gain `code`, `module` and `status` on the thrown error object
so this check has something to read. That is a **`portal.d.ts` change** (`PortalApiError`)
in the same pass.

Why both the block *and* the code path: the block is read once at mount; a tenant may flip
guest checkout off while a visitor has the page open. The server refuses; the UI must show
the gate, not "Checkout failed".

## 6.6 Per-storefront wiring

### ecommerce — `external/ui/ecommerce/ui.ts` `renderCheckout()`

```ts
async function renderCheckout(root: HTMLElement, shop: CommerceShop, dpRef: string): Promise<void> {
    const store = await shop.init();                         // now includes store.site_users
    const block = (store.site_users ?? { connected: false, guest_checkout: true }) as SiteUsersBlock;
    const gateHost = ensureGateHost(root);                    // a div above the form

    await requireSignIn(gateHost, block, {
        purpose: 'to complete your order',
        onSignedIn: (user, su) => mountCheckoutForm(root, shop, dpRef, { email: user.email ?? '', su }),
        onGuest:    ()         => mountCheckoutForm(root, shop, dpRef, { email: '', su: null }),
    });
}
```

`mountCheckoutForm()` is today's body of `renderCheckout()` with two additions: the email
input is prefilled and read-only when signed in, and when `su` is present **and the store's
Direct Payment is connected**, the Direct Payments instance is handed the customer resolver
([07](07-direct-payments-connection.md) §7.3) before `mountPayment()`. The *Begin checkout*
and *Pay* handlers wrap their calls with `isAuthRequired()` → `requireSignIn()` again.

In `guest` mode with a guest visitor, the form shows a one-line
*"Have an account? Sign in for saved cards and order history"* link that opens the same
gate in "optional" copy — `buildGateMarkup()` with `guest_checkout: true` already renders the
*Continue as guest* button, so no second markup is needed.

### events — `renderRegister()`

The hold happens on ticket selection, before the form. In `required` mode the **hold** is
also refused ([04](04-connection-registry-and-enforcement.md) §4.5), so the gate runs before
the ticket picker is interactive:

```ts
const detail = await api.event(eventRef);                    // now includes site_users
await requireSignIn(gateHost, detail.site_users, {
    purpose: 'to register',
    onSignedIn: (user) => mountRegisterForm(root, detail, { prefill: user }),
    onGuest:    ()     => mountRegisterForm(root, detail, { prefill: null }),
});
```

Prefill writes `attendee_name` / `attendee_email` from the account into the form; the values
are still **copied onto the registration row** (snapshots — [events/02](../events/02-site-users-guard.md) §2.4).

### appointments — `renderBooking()`

Same shape around the service select and slot list; the hold on slot click is gated
server-side, and the form is prefilled (`user_name`, `user_email`).

## 6.7 Cart merge on sign-in — what already exists and one addition

`EcommerceCartService::resolveCartForShopper()` merges the cookie cart into the account's
active cart when a request arrives with both. The gate's `onSignedIn` therefore just calls
`shop.cart()` again and re-renders totals. One addition: the merge must use the
**store-scoped** user reference from `attributableUserReference('ecommerce', $store, …)`
([04](04-connection-registry-and-enforcement.md) §4.2) — a user of module B signing in on a
module-A store must **not** have their cookie cart merged into a module-B account cart that
this store can never check out.

## 6.8 Standalone sites and the session cookie (X19)

The gate above works because a builder-published storefront and
`/portal/api/siteusers/public/*` are **the same origin**. On a site the tenant hosts
elsewhere — the case the externals SDK exists for
([site_users/07](../site_users/07-externals-api.md) §7.1) — it does not, and the reason is
worth stating precisely rather than discovering during a pilot.

`SiteUsersPublicController::authResponse()` sets:

```php
$response->cookie('su_session', $token, 60 * 24 * 30, '/', null, false, true, false, 'Lax');
//                                              path ─┘  domain ─┘  secure ─┘  httpOnly ─┘  raw ─┘  sameSite ─┘
```

`SameSite=Lax` is not sent on a cross-site `fetch`, whatever `credentials: 'include'` says. So
on an external site the SDK's default `tokenStorage: 'cookie'` yields a login that succeeds
and a `me()` that immediately 401s — the gate would loop.

### The rule

| Where the page is served | `tokenStorage` | Notes |
|---|---|---|
| A builder-published page on this deployment | `cookie` **(default)** | httpOnly, unreadable by page scripts. What every surface in this plan assumes |
| A single-page external site | `memory` | Safest for a non-builder site, but the token dies on navigation |
| A multi-page external site | `localStorage` | The only option that survives navigation. **N11's tradeoff applies** and must be stated to the tenant, not defaulted into |

Two consequences for the code in this document:

1. **`requireSignIn()` must accept an existing instance.** `gateState()` and `requireSignIn()`
   as written call `Portal.siteUsers.create(module)` themselves, which produces a
   cookie-mode client. They gain an optional `su` parameter, and every storefront passes the
   instance the page already configured:

   ```ts
   export interface GateOptions { /* …existing… */
       /** The page's own Site Users client. Omit only on a same-origin builder page. */
       su?: PortalSiteUsersInstance;
   }
   ```

   An external site's page constructs
   `Portal.siteUsers.create(module, { tokenStorage: 'localStorage' })` once and hands the same
   object to the gate, to `paymentsResolver()` and to the dashboard. Without this the three
   surfaces would each hold a different token store and disagree about who is signed in.

2. **Do not "fix" this by setting `SameSite=None`.** It would need `Secure` (correct anyway —
   `secure` is `false` today, which is wrong on any HTTPS deployment) **and** a CSRF defence,
   because `routes/portal.php` carries none: `bootstrap/app.php`'s `validateCsrfTokens`
   excepts a handful of paths, and the portal route file is outside the `web` group entirely,
   so nothing verifies a token there. `SameSite=Lax` is currently the only thing preventing a
   cross-site `POST`/`DELETE` from riding a logged-in user's cookie — including, after
   [07](07-direct-payments-connection.md), `DELETE /me/payment-methods/{id}`.

   Raising `secure` to `true` (behind `config('session.secure')` or `app()->isProduction()`)
   **is** in scope for K5 and is a one-line change with no CSRF implication. Changing
   `SameSite` is not, and is [11](11-rollout-and-testing.md) §11.7 follow-up 11.

## 6.9 Acceptance

- With guest checkout **off**, `curl -X POST …/checkout/begin` with no cookie → `401`
  `EC_E_AUTH_REQUIRED` with `module`; the page shows the gate with no *Continue as guest*.
- With guest checkout **on**, the same `curl` → `200`; the page shows the optional sign-in
  row only.
- Not connected: the checkout page is byte-identical in structure to today's (no gate host
  content, no sign-in row).
- Signing in through the gate keeps the cart: line count before == after.
- Registering via *Create an account* with `?redirect=/shop-checkout` returns to
  `/shop-checkout`; `?redirect=https://evil.example/x` is ignored and lands on the dashboard.
- Flipping guest checkout off while a checkout page is open: the next *Begin checkout* shows
  the gate (via `isAuthRequired`), not an error string.
- The gate's login form is `buildLoginMarkup()` — `grep -c "data-su-login" resources/js/external/ui` counts one definition.
- A `required` event's ticket picker does not issue a hold until signed in; `curl` to
  `/hold` → `401`.
- Prefilled attendee/booking fields are editable and are stored on the row, not referenced.
- The gate uses the instance it is given: a page that constructs its client with
  `tokenStorage: 'localStorage'` and passes it in signs in once, and `me()` still resolves
  after a navigation (§6.8).
- `su_session` is issued with `secure: true` on an HTTPS deployment, and still
  `SameSite=Lax`.
