# 07 — The Externals API

`window.Portal.siteUsers` — the interface that makes this system usable on a website that
never touched the builder.

## 7.1 Why this is the primary interface (**N7**)

The requirement is explicit: Site Users must work as **a standalone implementation on
another website, without using the builder**. That inverts the usual priority.

The builder is a *consumer* of this SDK, not a privileged path into the system. Concretely:

- **Anything the builder can do, the SDK can do.** A builder-rendered login component calls
  the same endpoints as a `<script>` tag on a static site.
- **No endpoint requires builder context.** No hidden field the builder injects, no
  session the builder establishes first.
- **The module id is the only handle.** `su-a1b2` in the page source, plus an allowed origin
  ([04](04-database-schema.md) §4.7), is the whole integration contract.

If a feature only works inside a tenant deployment, it is a design error — and the test for
it is [11](11-rollout-and-testing.md)'s standalone fixture: a plain HTML file, no build step,
served from a different origin.

This follows the pattern the processors and Direct Payments APIs already established in
`resources/js/external/` — instance-based, resolved once, provider-agnostic to the caller.

## 7.2 What exists today

`resources/js/external/systemsusers/`, 860 LOC across 8 files, registered on
`window.Portal.systemsusers`:

| File | Role |
|---|---|
| `SystemsUsersClient.ts` | `getConfig`, `register`, `login`, `ssoExchange`, `getSSOConfig` |
| `RegistrationFlow.ts` / `LoginFlow.ts` | Mountable DOM components |
| `Google`/`Facebook`/`AppleSSOIntegration.ts` | Per-provider client helpers |
| `SystemsUsersTypes.ts` | Shared types |

The shape is right. What is missing follows from the server gaps: no `logout`, no `me`, no
`refresh`, no password reset, no email verification (**M16**) — because none of those
endpoints exist. And `ssoExchange` is the client half of **M3**, so it is deleted rather
than renamed.

## 7.3 The target surface

Instance-based, matching `directPayments`:

```js
const su = window.Portal.siteUsers.create('su-a1b2');
await su.init();                      // fetches /public/config once
```

| Method | Notes |
|---|---|
| `su.config()` | Cached module config: allowed identity types, password policy, SSO providers |
| `su.register({ email, password, username?, display_name? })` | |
| `su.login({ email, password })` | |
| `su.logout()` / `su.logoutAll()` | **New** (**M12**) |
| `su.me()` | **New** — the gap behind **M2** |
| `su.updateProfile({ display_name?, username? })` | **New** |
| `su.changePassword({ current, next })` | **New** |
| `su.sessions()` / `su.revokeSession(id)` | **New** — device list |
| `su.requestPasswordReset(email)` / `su.resetPassword({ token, password })` | **New** (**M10**) |
| `su.resendVerification()` / `su.verifyEmail(token)` | **New** (**M9**) |
| `su.sso(provider).start()` | Redirects to the provider ([06](06-sso.md) §6.2) |
| `su.sso(provider).complete()` | Reads `code`/`state` from the URL, POSTs the callback |
| `su.isAuthenticated()` | Local, cheap — does a session exist |
| `su.on(event, handler)` | `login`, `logout`, `session:expired`, `register` |

**`ssoExchange()` is gone.** There is no client method that asserts an identity, because
there is no server endpoint that accepts one.

Mountable flows keep their shape — they are the reason a static site can adopt this in five
lines — but their validation is driven by `su.config()`'s server-supplied policy rather than
hardcoded (**M17**):

```js
su.loginFlow().mount('#login', { onSuccess: u => location.href = '/account' });
su.registerFlow().mount('#register', { onSuccess: u => … });
```

## 7.4 Token storage (**M15**, **N11**)

`SystemsUsersClient`'s docblock today: *"token management (localStorage)"*.

This SDK is designed to run on **third-party sites the tenant does not control**. A bearer
token in `localStorage` is readable by any script on the page — every analytics tag, every
chat widget, every dependency.

Three modes, and the default changes:

| Mode | Behaviour | When |
|---|---|---|
| `cookie` **(default)** | Server sets an httpOnly, Secure, SameSite=Lax `su_session` cookie. The SDK never sees the token | Same-origin — the builder case and most standalone use |
| `memory` | Held in a closure for the page's lifetime. Lost on reload | Cross-origin where a cookie will not work and persistence is not wanted |
| `localStorage` | Explicit opt-in, `console.warn`s once | Cross-origin SPA that must survive reload |

```js
window.Portal.siteUsers.create('su-a1b2', { tokenStorage: 'localStorage' });
```

Making the safe mode the default and the risky one a named opt-in is the whole change. The
warning names the tradeoff so it is a decision rather than an accident.

**Cookie mode requires the login response to set the cookie server-side** — the token is
still returned in the body for `memory` and `localStorage` mode, so the endpoint's shape does
not fork.

## 7.5 Errors

One error shape, so a caller never string-matches a message:

```js
try { await su.login({ email, password }); }
catch (e) {
  e.code;    // SU_E_INVALID_CREDENTIALS | SU_E_RATE_LIMITED | SU_E_EMAIL_UNVERIFIED
             // SU_E_ORIGIN_NOT_ALLOWED | SU_E_MODULE_INACTIVE | SU_E_NETWORK
  e.message; // human-readable, safe to display
  e.retryAfter;
}
```

`SU_E_INVALID_CREDENTIALS` is returned for both "no such user" and "wrong password"
([05](05-authentication.md) §5.6). The SDK must not try to be more helpful than the server —
that is where enumeration oracles get reintroduced.

## 7.6 `portal.d.ts` — updated in the same pass

**Consumers never import the externals TypeScript source.** They reach the SDK through
`window.Portal.*` at runtime and through `www/resources/js/external/portal.d.ts` at compile
time — a declaration file **copied** into each consuming project at
`<app>/resources/js/checkpointHelper/static/portal.d.ts`.

It is hand-maintained. A method added to the SDK and not declared there is invisible to every
consumer's build, and the consumer silently falls back to `(window as any)`.

So `portal.d.ts` is a **deliverable of every step that touches the SDK**, not a follow-up:

| Declared | Replaces |
|---|---|
| `PortalSiteUsersNamespace` with `create()` | `PortalSystemsUsersNamespace` with `Client`/`RegistrationFlow`/`LoginFlow` as separate constructors |
| `PortalSiteUsersInstance` — every method in §7.3 | `PortalSystemsUsersClientInstance`'s five |
| `PortalSiteUsersConfig` | `PortalSystemsUsersConfig` |
| `PortalSiteUsersError` with the §7.5 codes | *(nothing today)* |
| `PortalSiteUsersEventMap` | *(nothing today)* |

Both `siteUsers` and the deprecated `systemsusers` point at the **same interface**
([02](02-the-rename.md) §2.7) so they cannot drift.

**And the copies are refreshed.** Which consumers actually need it is a real question:
`members`, `market` and the four creative apps all carry a copy, but none consumes Site
Users today. The honest scope is **refresh the copies that the rename would otherwise leave
describing a namespace that no longer exists** — which is all of them, since they all declare
`systemsusers`.

## 7.7 A standalone worked example

`www/public/playground/site-users/standalone/` — a **single HTML file**, no build step,
no framework, served from a different origin than the API:

```html
<script src="https://tenant.example.com/external/portal.js"></script>
<div id="auth"></div>
<script>
  const su = window.Portal.siteUsers.create('su-a1b2');
  su.init().then(() => {
    if (su.isAuthenticated()) return su.me().then(renderAccount);
    su.loginFlow().mount('#auth', { onSuccess: renderAccount });
  });
</script>
```

This is the acceptance test for §7.1, not a demo. If it needs a build step, a framework or a
same-origin server, the standalone requirement is not met.

The existing `samples/systemsusers-example.js` and `SAMPLES_GUIDE.md` are updated alongside.

## 7.8 Acceptance

- The §7.7 page works from a different origin, with that origin in `allowed_origins`, and is
  **refused** when it is not.
- No SDK method sends a `provider_id` (**M3**).
- Default token storage is `cookie`; `localStorage` warns once (**M15**).
- Every §7.3 method is declared in `portal.d.ts`, and a consuming app type-checks a call to
  each without `(window as any)`.
- `grep -rn "from '.*external/site_users" <consumer>/resources/js/` is **empty** — no
  consumer imports the source.
- Password policy shown by `registerFlow` changes when the module's
  `password_min_length` changes, with no client redeploy (**M17**).
- Both `Portal.systemsusers` and `Portal.siteUsers` resolve to the same object through the
  deprecation window.
