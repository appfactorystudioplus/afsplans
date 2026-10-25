# 01 — Current State Audit

Everything below was read from the repository. Findings are numbered `M#` so later documents
can cite them.

## 1.1 What exists

| Layer | File | LOC |
|---|---|---|
| Migration | `www/database/migrations/2026_04_02_000001_create_laravel_systemsusers_tables.php` | 68 |
| Models | `app/Models/Externals/SystemsUsers/SystemsUsersUser.php`, `SystemsUsersSsoIntegration.php` | 92 / 64 |
| Admin API | `app/Http/Controllers/Externals/SystemsUsers/SystemsUsersAdminController.php` | 271 |
| Public API | `app/Http/Controllers/Externals/SystemsUsers/SystemsUsersPublicController.php` | 234 |
| Routes | `routes/portal.php` (authenticated), `routes/auth1.php` (**not** authenticated) | — |
| Admin UI | `resources/js/portal/admin/controllers/systems_users/systems-users.ts` | 663 |
| Admin views | `resources/js/portal/admin/views/systemsUsersView.ts` | — |
| **Externals SDK** | `resources/js/external/systemsusers/` — 8 files | 860 |
| Type declarations | `resources/js/external/portal.d.ts` — `PortalSystemsUsersNamespace` | — |
| Sample | `resources/js/external/samples/systemsusers-example.js` | — |

So the shape is already in place across every layer, including a working externals SDK
registered on `window.Portal.systemsusers`. This is not a greenfield build; it is a rename, a
security remediation, and a completion.

### The two tables

```php
laravel_systemsusers_sso_integrations
    id, systemsusers_id, provider, client_id, client_secret_encrypted,
    redirect_uri, is_active, timestamps
    unique(systemsusers_id, provider)

laravel_systemsusers_users
    id, systemsusers_id, reference_num (unique), username, email,
    password_hash, provider, provider_id, auth_token, token_expires_at,
    is_active, timestamps
    index(systemsusers_id), index(email)
```

## 1.2 Security — the four that gate everything

| # | Finding | Consequence |
|---|---|---|
| **M1** | **`routes/auth1.php` has no middleware.** `bootstrap/app.php` registers it as `Route::prefix('auth1')->group(base_path('routes/auth1.php'))` — no `->middleware(...)`. That file contains the **admin** routes: `/status`, `/activate`, `/sso` (GET **and** POST), `/sso/{id}` (DELETE), `/users`, `/users/{id}`, `/users/{id}` (DELETE). The same controller's routes in `routes/portal.php` *are* inside `Route::middleware(ApiAuthMiddleware::class)` | **Unauthenticated user enumeration, user deletion, and read/write of OAuth client secrets** at `/auth1/api/systemsusers/*`. The most severe defect in this module |
| **M2** | **The issued token authenticates nothing.** `auth_token` is written in exactly two places (`login()`, `ssoExchange()`) and **read by no code in the application** — no middleware, no `/me`, no logout, no guard | The module issues a 64-character string and calls it a session. Nothing downstream can verify a logged-in user, which is why no other module consumes this system yet |
| **M3** | **`ssoExchange()` trusts a client-supplied `provider_id`.** It validates `required|string` and then looks up or creates a user on it. There is **no** authorization-code exchange with Google/Facebook/Apple, and no verification of an ID token | **Authentication bypass.** Post `{systemsusers_id, provider_id}` for a known subject and receive a session as that user; post a new one and create an account. `ssoConfig()` hands out the `client_id` needed to look plausible |
| **M4** | `auth_token` is stored **plaintext** in `laravel_systemsusers_users.auth_token` | Read access to the table is full account takeover for every user, permanently. Tokens must be stored hashed |

**M1 and M3 are exploitable without credentials.** Nothing else in this plan should be built
until both are closed — [11](11-rollout-and-testing.md) §11.2 makes that a gate, not a
recommendation.

## 1.3 Architecture — the module identity is a sentinel row

| # | Finding | Consequence |
|---|---|---|
| **M5** | **There is no modules table.** A module's existence is a row in the *SSO credentials* table with `provider = '__systemsusers_marker__'` and every credential column null. `activate()` creates it; `status()` reads it | The module has no name, no settings, no created-by, no state, and no place to put any. Every future setting — password policy, allowed origins, verification requirements — has nowhere to go. And a credentials table is carrying non-credential rows that its own `unique(systemsusers_id, provider)` index only accidentally protects |
| **M6** | **The admin API ignores `systemsusers_id` entirely.** `status()`, `listSso()`, `listUsers()` and the rest all do `SsoIntegration::where('provider','__systemsusers_marker__')->value('systemsusers_id')` — the **first** marker row in the table | Single-module assumption hardcoded into an explicitly multi-module schema (`systemsusers_id` is on every row and every index). A second module is unreachable through the admin API |
| **M7** | `register()` permits `password` **and** `provider` to both be null — and `email` and `username` are both nullable too | A row that cannot authenticate by any means, and may carry no identifier at all. The uniqueness guards are also **conditional on the field being present**, so two users with no email are fine and a third with an email collides |
| **M8** | Sessions live **on the user row** — one `auth_token` and one `token_expires_at` per user | A user can be signed in on exactly one device; logging in on a second silently revokes the first. Not a bug today because nothing checks the token (**M2**), but it is a schema shape that has to change before sessions mean anything |

## 1.4 Missing lifecycle

| # | Finding |
|---|---|
| **M9** | **No email verification.** An account is usable the instant it is created, with an unverified address |
| **M10** | **No password reset.** No forgot-password, no reset token, no flow. `updateUserPassword` is admin-only |
| **M11** | **No rate limiting** on `login`, `register` or `ssoExchange`. No lockout, no backoff, no attempt log |
| **M12** | **No logout.** A token cannot be revoked by the user who holds it |
| **M13** | **`token_expires_at` is set to +30 days and never read** — a consequence of **M2**, listed separately because expiry checking is not the same work as introducing a session guard |
| **M14** | **Route drift between the two route files.** `routes/portal.php` exposes `POST /users/{id}/password`; `routes/auth1.php` does not. Two hand-maintained lists of the same controller, already diverged |

## 1.5 The externals SDK

`resources/js/external/systemsusers/` — `SystemsUsersClient`, `RegistrationFlow`,
`LoginFlow`, and `Google`/`Facebook`/`Apple` SSO integrations, exported through `index.ts`
and registered in `main.ts` as `Portal.systemsusers`. Declared in `portal.d.ts` as
`PortalSystemsUsersNamespace`.

| # | Finding |
|---|---|
| **M15** | `SystemsUsersClient`'s own docblock: *"Handles fetching public config and token management (**localStorage**)"*. A bearer token in `localStorage` is readable by any XSS on the page, and this SDK is explicitly designed to run on **third-party sites** the tenant does not control ([07](07-externals-api.md) §7.4 revisits this) |
| **M16** | The SDK has `register`, `login`, `ssoExchange`, `getConfig`, `getSSOConfig` — and **no** `logout`, `me`, `refresh`, `requestPasswordReset` or `verifyEmail`, because none of those endpoints exist |
| **M17** | `RegistrationFlow` and `LoginFlow` render their own DOM into a mount target. That is the right shape for a drop-in SDK, but it means the module's password policy and error copy live in **client** code where a tenant cannot change them and an attacker can read them |
| **M18** | `resources/js/external/build/` contains **two** stale copies of the compiled `systemsusers` declarations (`build/systemsusers/` and `build/external/systemsusers/`) | Renaming will leave a third unless the build output is cleaned rather than renamed ([02](02-the-rename.md) §2.4) |

## 1.5b The builder and the module APIs

Full treatment in [12](12-builder-integration.md); the findings are defined here with the
rest.

| # | Finding | Consequence |
|---|---|---|
| **M19** | `resources/js/portal/builder/` has **no Site Users presence at all** — no block, no component, no notion of a signed-in visitor | Everything in [12](12-builder-integration.md) is new. The three-layer extension model is proven and the SDK already loads on every published page, so it is new work on solid ground |
| **M20** | `payments_default` (`module_component_defaults.ts:87`) is **pure static HTML** — Stripe-styled markup with `<input placeholder="1234 1234 1234 1234">` and no `script` | The Direct Payments builder component is a **visual mockup**, not a working integration. Copying it as the pattern produces another mockup; `forms_component_script.ts` is the real one |
| **M21** | Public module APIs (`/api/ecommerce/*`, `/api/v1/appointments/*` and siblings) have **no authentication or authorisation layer**. `Portal.httpUrl` is `window.location.origin` used as a fetch prefix (`external/main.ts:109`); `PortalCorsMiddleware` is an explicit no-op deferring to Nginx | Neither restricts anything a `curl` can do, so **route protection ([12](12-builder-integration.md) §12.5) is bypassable** by calling the API the protected page would have called. Closed by §12.8's config-driven guard |

## 1.6 What is *not* connected

- **No module consumes it.** Ecommerce, events, appointments, forms and discussions have no
  reference to `systemsusers_id`, no foreign key to a user, and no notion of a signed-in
  end user. The system is deployed but unused ([09](09-app-integrations.md)).
- **No link to Direct Payments.** `laravel_processor_customers` and
  `laravel_direct_payment_invoices` have no user column, and
  `laravel_systemsusers_users` has no `customer_id`. The request to equate a processor
  `customer_id` with a user is genuinely unbuilt ([08](08-direct-payments-link.md)).
- **No link to Checkpoint.** Site Users is deliberately *not* Checkpoint: Checkpoint
  authenticates **members** (platform customers) across the ecosystem; Site Users
  authenticates a **tenant's own end users** on that tenant's site. They must not be
  conflated ([03](03-architecture.md) §3.2).

## 1.7 Summary — what must change before the rest of this plan

1. Middleware on `routes/auth1.php`, and one route list rather than two — **M1**, **M14**.
2. A real session guard, hashed tokens, expiry enforcement, logout — **M2**, **M4**,
   **M12**, **M13**.
3. A real OAuth code exchange — **M3**.
4. A modules table replacing the sentinel row, and admin endpoints scoped by module id —
   **M5**, **M6**.
5. Sessions in their own table — **M8**.

Items 1–3 are the security gate. Items 4–5 are the schema the rest of the plan builds on.
