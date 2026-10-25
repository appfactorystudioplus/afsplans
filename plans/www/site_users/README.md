# site_users — Planning Index

**Site Users** is the tenant-deployable end-user authentication system: a website owner
turns it on, and every other module they deploy — ecommerce, events, appointments, forms,
discussions — gets a shared account system with password and SSO login, reachable from
inside the builder *and* from any external site through the externals API.

It is currently called **System Users**, and this plan renames it
([02-the-rename.md](02-the-rename.md)) because that name collides with an unrelated concept.

> ### ✅ Status (2026-09-05)
>
> **P0–P9 are landed.** The security gate (M1–M4), schema, sessions, lifecycle (incl. real
> email), SSO (Google verified end to end; Apple/Facebook built), the externals SDK, the
> payments link, and the config-driven app-adoption registry are all done and tested
> (~100 assertions). See [`complete/`](complete/) — `11-rollout-and-testing.complete.md` is
> the index.
>
> **Outstanding:** the P7 admin **SPA screen** (backend is complete) and P10 rename cleanup
> (gated on an empty deprecation log). The 🔴 banner below is historical — the four gating
> defects are closed.
>
> [`builder/v2/08-site-users-pages.md`](../builder/v2/08-site-users-pages.md) ([complete](../builder/v2/complete/08-site-users-pages.complete.md))
> superseded [12](12-builder-integration.md) §12.3's Login/Register/User Menu **blocks** —
> site_users is now a page-level module (Login/Register/Dashboard/Forgot/Reset pages), and the
> deferred "Account and Password-reset blocks" item is resolved as pages rather than blocks.

> ### 🔴 (historical) Stop before building features
>
> This module was **live and unauthenticated**. Four defects, detailed in
> [01-current-state-audit.md](01-current-state-audit.md), had to be closed before anything
> in this plan was built on top:
>
> 1. **`routes/auth1.php` carries no middleware.** The full admin API — list users, read a
>    user, delete a user, read and write SSO client secrets — is publicly reachable at
>    `/auth1/api/systemsusers/*` (**M1**).
> 2. **`ssoExchange()` trusts a client-supplied `provider_id`.** No OAuth code exchange, no
>    token verification. Post any provider id and receive a valid session for that user, or
>    create one (**M3**).
> 3. **Issued tokens authenticate nothing.** `auth_token` is written on login and read by no
>    code anywhere in the application. There is no `/me`, no logout, no middleware (**M2**).
> 4. **Tokens are stored in plaintext** (**M4**).
>
> The system does not currently provide authentication. It provides a user table and a login
> form that returns a string.

Read in this order:

| Step | Document | Contents |
|---|----------|----------|
| — | [README.md](README.md) | This index |
| 00 | [00-overview.md](00-overview.md) | What this is for, goals, non-goals, glossary, the decisions |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | Every file, table and route that exists today, and the 21 defects |
| 02 | [02-the-rename.md](02-the-rename.md) | System Users → Site Users: the full surface, the order, and what must not be renamed |
| 03 | [03-architecture.md](03-architecture.md) | The module model, multi-tenancy, where identity lives, how apps consume it |
| 04 | [04-database-schema.md](04-database-schema.md) | The real schema — modules, users, sessions, identities, verification |
| 05 | [05-authentication.md](05-authentication.md) | Tokens, sessions, password policy, verification, reset, rate limiting |
| 06 | [06-sso.md](06-sso.md) | Google, Facebook and Apple done correctly — the code exchange **M3** is missing |
| 07 | [07-externals-api.md](07-externals-api.md) | `window.Portal.siteUsers` — the standalone SDK, and `portal.d.ts` |
| 08 | [08-direct-payments-link.md](08-direct-payments-link.md) | Equating a processor `customer_id` with a site user |
| 09 | [09-app-integrations.md](09-app-integrations.md) | Optional per-module adoption by ecommerce, events, appointments and the rest |
| 10 | [10-admin-dashboard.md](10-admin-dashboard.md) | The portal admin surface |
| 11 | [11-rollout-and-testing.md](11-rollout-and-testing.md) | Phases, the security gate, the test matrix |
| 12 | [12-builder-integration.md](12-builder-integration.md) | The website builder — auth components, **route protection** (pages are never served), the **API guard** (config-driven, no per-module code), and the handoff to ecommerce |
| 13 | [13-identity-and-email-change.md](13-identity-and-email-change.md) | **`reference_num` becomes the identifier every other module stores**, its 12-character format, and letting a user change the email they sign in with |

## First consumer

[`../ecommerce/01-site-users-guard.md`](../ecommerce/01-site-users-guard.md) is the first
module to adopt this system, and the worked example of §12.8's guard in practice. It also
records an unauthenticated destructive vulnerability in the ecommerce API (**E1**) that is a
**prerequisite** for guarding it, not a consequence — it depends on nothing here and should
ship immediately.

## Connecting the other modules

[`../connections/`](../connections/README.md) (2026-09-12) is the plan that turns adoption
into something a tenant operates and a user benefits from: a `laravel_site_user_connections`
row per (module ↔ instance), a Connections tab in this module's admin screen, a per-connection
**guest checkout** switch that guards ecommerce checkout, event registration and appointment
booking, Direct Payments connected so saved cards follow the account, and a Dashboard group
per connected module.

It re-points two decisions recorded here — [09](09-app-integrations.md) §9.2's per-instance
mode column and its "configure in the module's own admin section" placement — and closes
[08](08-direct-payments-link.md)'s deferred read side. Both source documents carry a note
saying so.

## Completion documents

Each numbered document gets a completion document in [`complete/`](complete/) when its work
lands — source basename plus `.complete.md`, e.g. `05-authentication.complete.md`. Include
status, summary, files changed, verification steps and follow-up work.

## Code documentation standard

Document all new and changed code thoroughly using block comments — PHPDoc for PHP, JSDoc
for TypeScript. Every file, exported symbol, public function, non-obvious private helper,
route handler, migration and non-trivial type must explain purpose, parameters, return
values, side effects and plan references. Inline `//` comments are for brief local
clarification only.

New and changed code carries the `AFS_SITE_USERS_MODIFIER_ADDITION` marker (see
[../../markers.md](../../markers.md)) followed by the plan reference, e.g.
`AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/site_users/05-authentication.md §5.3`.
Where a file already carries a marker from an earlier effort, **add alongside; never
replace.**

## Two rules that shape everything here

**The externals API is the product, not an afterthought.** Site Users must work on a
website that never used the builder — a plain HTML page loading the SDK from a script tag.
The builder is one consumer of the same API, not the primary path. Anything that only works
inside a tenant deployment is a design error ([07](07-externals-api.md) §7.1).

**`portal.d.ts` is updated in the same pass as any SDK change.** Consumers never import the
externals TypeScript source; they copy `www/resources/js/external/portal.d.ts` into
`<app>/resources/js/checkpointHelper/static/`. It is hand-maintained, so an undeclared method
is invisible to every consumer's build ([07](07-externals-api.md) §7.6).

## One-paragraph summary

A tenant turns on Site Users and receives an end-user account system their other deployed
modules can share: registration, password login, Google/Facebook/Apple SSO, sessions, and a
user record every module can key off. Today that system exists as two controllers, two
tables, an admin screen and an externals SDK — but its admin API is exposed without
authentication, its SSO exchange accepts an unverified identity claim, and the tokens it
issues are never checked by anything, so **step 01's security work gates the entire plan.**
On that foundation this plan renames the module, replaces the sentinel-row module marker with
a real modules table, splits sessions out of the users table so a user can be signed in on
more than one device, adds email verification and password reset, implements the OAuth code
exchange the SSO flow is missing, links a site user to a processor `customer_id` so a
Direct Payment charge can be attributed to an account, and exposes the whole thing through
`window.Portal.siteUsers` so a site that never touched the builder can use it standalone.
