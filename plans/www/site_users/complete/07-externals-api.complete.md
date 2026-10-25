# 07 — The Externals API (complete)

**Plan:** `plans/www/site_users/07-externals-api.md`
**Rollout phase:** P6
**Repo:** `www`
**Date:** 2026-08-31, completed 2026-09-05

## §7.3 — the surface

`window.Portal.siteUsers.create('su-a1b2')` in `resources/js/external/api/site_users/`.
Every §7.3 method now exists on `SiteUsersClient` **and** is declared in `portal.d.ts`:

| Method | Endpoint |
|---|---|
| `init()` / `config()` | `GET /public/config` (cached) |
| `register` / `login` | `POST /public/{register,login}` |
| `logout` / `logoutAll` | `POST /public/{logout,logout-all}` |
| `me()` | `GET /public/me` |
| **`updateProfile()`** *(new)* | `PATCH /public/me` |
| **`changePassword()`** *(new)* | `POST /public/me/password` |
| **`sessions()` / `revokeSession(id)`** *(new)* | `GET` / `DELETE /public/me/sessions[/{id}]` |
| `requestPasswordReset` / `resetPassword` | `POST /public/password/{forgot,reset}` |
| `verifyEmail(token)` | `POST /public/email/verify` |
| **`resendVerification()`** *(new)* | `POST /public/email/resend` (fetches `me()` for the address) |
| `sso(p).start()` | `GET → 302 /public/sso/{p}/start` |
| **`sso(p).complete()`** *(new)* | reads `code`/`state` from the URL, `POST /public/sso/{p}/callback` |
| `isAuthenticated()` | local |
| `loginFlow()` / `registerFlow()` | mountable, validation driven by `config()` (M17) |
| `on(event, handler)` | `login` \| `logout` \| `register` \| `session:expired` |

**`ssoExchange()` does not exist** — no client method asserts an identity (M3).

## §7.4 — token storage

Default `cookie` (httpOnly, set server-side); `memory`; `localStorage` (opt-in, `console.warn`s
once). `create('su-a1b2', { tokenStorage: 'localStorage' })`.

## §7.6 — `portal.d.ts`, and the namespace unification

- `PortalSiteUsersInstance` carries the full §7.3 surface (was missing 5 methods).
- **`systemsusers` and `siteUsers` now point at the SAME interface** (`PortalSiteUsersNamespace`),
  `systemsusers` marked `@deprecated`. The old divergent `PortalSystemsUsers*` interfaces are
  deleted — "one interface, two names, so the two can never describe different shapes" (§2.7).
- At runtime `window.Portal.systemsusers` is a `Proxy` over the same object as `siteUsers` that
  `console.warn`s once per page (`legacySystemsUsersNamespace`). `SystemsUsersClient` is no
  longer bundled into `sdk.js` — closing `02-the-rename.complete.md`'s open item.
- `SiteUsersClient` accepts `{ systemsusers_id }` as a legacy alias for `{ module }`.

## §2.4 — build output

The stale compiled `systemsusers` declaration copies (`build/systemsusers`,
`build/external/systemsusers`, `build/external/api/systemsusers` — M18's "two", grown to
three) are deleted. Webpack still emits fresh `.d.ts` for the `systemsusers/` **source** dir,
which is unimported by `main.ts` and removed wholesale in P10.

## Acceptance — §7.8

| Criterion | State |
|---|---|
| Standalone page from a foreign allowed origin works; refused otherwise | ⚠️ enforced by `SiteUsersOriginMiddleware`; the §7.7 fixture page is **not yet written** (deferred) |
| No SDK method sends `provider_id` | ✅ |
| Default storage `cookie`; `localStorage` warns once | ✅ |
| Every §7.3 method declared in `portal.d.ts` | ✅ |
| `grep "from '.*external/site_users'"` in consumers is empty | ✅ no consumer imports the source |
| `registerFlow` reflects `password_min_length` with no redeploy | ✅ validation reads `config()` |
| `Portal.systemsusers` and `.siteUsers` resolve to the same object | ✅ same object via the Proxy |

## Deferred

| # | Work |
|---|---|
| 1 | `www/public/playground/site-users/standalone/` — the single-file, no-build acceptance fixture for §7.1/§7.7 |
| 2 | Update `samples/systemsusers-example.js`, `SAMPLES_GUIDE.md`, `main-example.ts` to the new namespace |
| 3 | Refresh the `portal.d.ts` copies in consuming apps (none consumes Site Users yet — §7.6) |
| 4 | Delete the `resources/js/external/api/systemsusers/` **source** dir (P10) |

## Verification

```bash
cd www
npm run build-external-webpack   # compiles clean (size warnings only)
grep -c "SystemsUsersClient" public/external/api/sdk.js   # 0
```
