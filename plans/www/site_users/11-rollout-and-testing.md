# 11 — Rollout and Testing

## 11.1 Build order

| Phase | Contents | Ships behind |
|---|---|---|
| **P0 — security gate** | Delete the admin routes from `routes/auth1.php` (**M1**); disable `ssoExchange` (**M3**). See §11.2 | Nothing. **Not optional, not schedulable** |
| **P1 — schema** | [04](04-database-schema.md): modules, users, identities, sessions, verifications, sso_integrations. Backfill (§4.10) | New tables, unread |
| **P2 — sessions** | [05](05-authentication.md) §5.2–§5.3: hashed tokens, the guard, `/me`, logout (**M2**, **M4**, **M8**, **M12**, **M13**) | Old `auth_token` path removed in the same step — it authenticated nothing |
| **P3 — lifecycle** | [05](05-authentication.md) §5.4–§5.6: password policy, verification, reset, rate limiting, origin checks (**M9**–**M11**) | Per-module settings, defaults on |
| **P4 — SSO** | [06](06-sso.md): the code exchange, JWKS verification, linking (**M3** properly closed) | Re-enables SSO, which P0 disabled |
| **P5 — rename** | [02](02-the-rename.md) Phases A–C | Old names aliased and logged |
| **P6 — externals SDK** | [07](07-externals-api.md): the new surface, token storage, `portal.d.ts` **and the copies**, the standalone fixture | Additive; old namespace aliased |
| **P7 — admin** | [10](10-admin-dashboard.md): module settings, identities, sessions, corrected SSO screen | |
| **P8 — payments link** | [08](08-direct-payments-link.md): the link table, checkout linking, the reconciler | `link_payment_customers`, default off |
| **P8b — builder auth** | [12](12-builder-integration.md) §12.3–§12.4: shared helpers, login/register components | Components only appear once a module exists. Depends on P6 |
| **P8c — route protection** | [12](12-builder-integration.md) §12.5, §12.7: `laravel_builder_routes`, the `show()` guard, the page-settings panel | Empty table = every route public |
| **P8d — API guard** | [12](12-builder-integration.md) §12.8: `laravel_api_guards`, `SiteUsersApiGuard`, the data-protection panel | Fails **open** — no rule, no change. **Ships with P8c**: a protected page whose API is open looks finished and is not |
| **P9 — app adoption** | [09](09-app-integrations.md): registry, gateway, per-module columns | Each module `off` by default |
| **P10 — rename cleanup** | [02](02-the-rename.md) Phase D: drop aliases, old routes, old tables. Re-sync `wwwafsportal_v1` | Only when the deprecation log is empty |

**P0 first, and it does not wait for the rename.** Deleting an unauthenticated admin API is
not coupled to naming.

P4 depends on P1 (identities table). P6 depends on P2–P4 (there is nothing to expose
otherwise). P8 depends on P2 (the link endpoint needs a session).

## 11.2 The security gate (**N1**)

Nothing past P0 is built until these are true:

- [ ] `routes/auth1.php` contains **no admin routes**. `/auth1/api/systemsusers/{status,activate,sso,users}`
      returns 404 (**M1**).
- [ ] `ssoExchange` is unreachable. Disabled outright until P4 replaces it (**M3**).
- [ ] A written check that no other route file registers a controller without middleware —
      **M1** was a route-file mistake, not a controller mistake, and the same mistake can
      recur in any of the four route files.

The third item is the one that stops this happening twice. `bootstrap/app.php` registers
`web`, `api`, `portal` and `auth1`; only `auth1` had no middleware, and nothing would have
caught it.

**Disabling SSO breaks tenants currently using it.** That is the correct trade: the flow
authenticates anyone as anyone. Notify affected tenants, disable, ship P4 promptly.

Verify **M1** by hand against a running instance, unauthenticated, before and after — a unit
test asserting a route is absent does not prove the deployed router agrees.

## 11.3 Migration and cutover

**Everyone is logged out at the P2 cutover.** Existing `auth_token` values are plaintext and
authenticate nothing, so there is nothing to preserve
([04](04-database-schema.md) §4.10 step 4).

**Migrated users are unverified** (§4.10 step 2). Modules with `require_email_verification`
on should run a confirm-your-address campaign rather than have the flag lie. Consider
defaulting *migrated* modules to `false` and letting the tenant turn it on after that
campaign — a defensible departure from the new-module default, and better than either lying
or locking people out.

**Users with no identity** (**M7**) are reported by the backfill, not dropped. Someone decides
what those accounts are before P2.

## 11.4 Test matrix

### Security — P0

| Case | Expected |
|---|---|
| Unauthenticated `GET /auth1/api/systemsusers/users` | **404** (**M1**) |
| Unauthenticated `DELETE /auth1/api/systemsusers/users/1` | **404** |
| Unauthenticated `GET /auth1/api/systemsusers/sso` | **404** — no client secrets |
| `POST .../sso/google` with a `provider_id` | Endpoint gone (**M3**) |
| Every route file | Registered with middleware, or explicitly documented as public |

### Sessions — P2

| Case | Expected |
|---|---|
| Token from `login` | Authenticates `GET /me` (**fails today** — **M2**) |
| Tampered token | 401 |
| Stored `token_hash` | Never equals a returned token (**M4**) |
| Two devices | Both sessions valid (**M8**) |
| Expired session | 401 (**M13**) |
| Logout | That session dead, others alive |
| Password change | Other sessions dead, current alive |
| Password reset | All dead |
| Admin sets password | All dead |

### Lifecycle — P3

| Case | Expected |
|---|---|
| 6 failed logins / 15 min, one email | 429 with `Retry-After` |
| `password/forgot`, real vs fake address | Same status, same shape, same timing |
| Reset token: reused / expired / superseded | Rejected in all three cases |
| Reset, change email, redeem old link | **Fails** ([04](04-database-schema.md) §4.6 `sent_to`) |
| 10-char password against a 12-char module | Rejected **server-side**, SDK bypassed |
| Disallowed `Origin` | 403 before any credential read |
| `curl` with no `Origin`, origins configured | Refused |

### SSO — P4

| Case | Expected |
|---|---|
| Mismatched `state` | Fail |
| ID token with wrong `aud` | Fail |
| Unknown signing key | Fail; succeeds after JWKS refresh |
| Replayed `code` | Fail on second use |
| Google login, verified matching email | Identity added, user count unchanged |
| Same, **unverified** account | **No link** ([06](06-sso.md) §6.5) |
| Facebook with no email | Session issued, `email = null` |
| Apple `client_secret` | Generated at call time, not a config literal |

### Externals — P6

| Case | Expected |
|---|---|
| Standalone HTML page, foreign origin, allowed | Works ([07](07-externals-api.md) §7.7) |
| Same page, origin not allowed | Refused |
| Default token storage | `cookie`; `localStorage` warns once (**M15**) |
| Every §7.3 method | Declared in `portal.d.ts`; consumer type-checks without `(window as any)` |
| `grep "from '.*external/site_users"` in consumers | Empty |
| Module `password_min_length` changed | `registerFlow` reflects it with no client redeploy (**M17**) |

### Payments link — P8

| Case | Expected |
|---|---|
| Signed-in second purchase | Same provider customer reused |
| Saved cards, new device | Present |
| `user_id` in the link request body | Ignored — session decides |
| Customer already linked elsewhere | 409, both untouched |
| Re-post existing link | No-op |
| `link_payment_customers = false` | Nothing written; reconciler refuses |
| `--dry-run` | Reports; ambiguous and unverified not linked |
| Processor-layer grep | No `SiteUser` reference ([08](08-direct-payments-link.md) §8.2) |

### Builder — P8b

| Case | Expected |
|---|---|
| Drag Login, pick a module, publish | Working login, no code |
| Editor canvas | Placeholder shown, **no** API calls (`isEditorMode`) |
| No module selected | Says so, in canvas and published |
| Slow `sdk.js` | Still resolves (`waitForPortal`) |
| **`curl` a guarded API path, no session** | **401** — no browser, no `Origin`, no cookie (**M21**) |
| Same path with a valid session cookie | Returns data |
| An **unguarded** API path | Unchanged — no behaviour change where nothing is configured |
| `grep "SiteUsers\|site_user" app/Http/Controllers/Ecommerce*` | Only `$request->attributes->get('site_user')` — no auth logic in the module |
| Guarded orders endpoint | Returns **only the caller's** orders — the module's filter, not the guard's |
| **Protected route, not signed in** | **No page HTML in the response body** — assert the body, not just the status |
| `not_found` behaviour | 404 indistinguishable from a nonexistent slug |
| Sign in after redirect | Lands on the originally requested URL (`su_intended`) |
| Protect `/members` | `/members/orders` protected without a second row (§12.5.2) |
| Child declaring itself unprotected under a protected parent | Rejected at save with a message |
| Site-wide guard + `public: true` on `/login` | Tenant can still sign in |
| **Republish an older version** | Route **stays protected** (§12.5.1) |
| Route config caching | Protected site adds zero queries in steady state |
| `su_visibility: signed_in` | **Not visible before** the check resolves |
| Same content | **Present in view-source** — asserted, so nobody mistakes it for protection |
| `waitForPortal` / `isEditorMode` | Defined **once** ([12](12-builder-integration.md) §12.8) |

### Rename — P5/P10

| Case | Expected |
|---|---|
| Published page on the old public route | Works through Phase C, logged with `Referer` |
| Pre-rename module | Keeps its `sys-` id, functions unchanged |
| `Portal.systemsusers` vs `.siteUsers` | Same object; old warns once |
| `build/` | Exactly one set of declarations (**M18**) |
| Freshly provisioned tenant, post-P10 | Exposes `siteUsers` (§11.5) |

## 11.5 Follow-up: the tenant template

`packages/wwwafsportal_v1` carries its own copy of the externals build. **It must be
re-synced after P10**, or newly provisioned tenants ship the old namespace while existing
ones have moved.

Worth checking how that copy is kept current generally — if it is a manual step, this rename
will not be the last thing it silently misses.

## 11.6 Rollback

| Phase | Rollback |
|---|---|
| P0 | **None.** Restoring an unauthenticated admin API is not a rollback |
| P1 | Drop the new tables; nothing reads them |
| P2 | Not clean — the old `auth_token` path is gone by design. Roll forward |
| P3 | Per-module settings back to permissive; rate limits are config |
| P4 | Disable SSO again. **Do not** restore `ssoExchange` |
| P5 | Aliases already keep both live; revert the new names |
| P6 | Old SDK namespace still registered through the window |
| P7 | Admin revert; endpoints are additive |
| P8 | `link_payment_customers = false`; drop the table |
| P8b | Remove the blocks; published pages keep working — the components degrade to their placeholder |
| P8c | Truncate `laravel_builder_routes` — every route becomes public again. **Reverting this un-protects live routes**; treat it as an incident action, not a routine rollback |
| P8d | Remove the middleware registration; guard rows become inert. Same warning as P8c |
| P9 | Per-module `off`; the columns stay nullable and unused |
| P10 | Not revertible — that is why it waits for an empty deprecation log |
