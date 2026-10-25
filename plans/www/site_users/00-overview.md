# 00 — Overview

## 1. What this is for

A tenant deploys a website through Appfactory Studio and turns on modules: ecommerce, events,
appointments, forms, discussions. Every one of those has an end user — a shopper, an
attendee, a booker — and today each would have to invent its own notion of who that is.

**Site Users is the shared answer.** One account system per tenant, one user record, one
login, consumed optionally by every other module. A shopper who books an appointment is the
same person, with one password and one profile.

It must work in two places:

| Where | How |
|---|---|
| **Inside a tenant deployment** | The builder renders login/registration components; deployed modules read the signed-in user |
| **On any other website** | A plain HTML page loads the externals SDK from a script tag and calls `window.Portal.siteUsers` — **no builder involved** |

The second is not a nice-to-have. It is the reason the system is built in the externals API
rather than as tenant-internal code ([07](07-externals-api.md) §7.1).

## 2. Goals

1. Rename **System Users → Site Users** across code, routes, tables, UI and the externals
   SDK, without breaking a live deployment ([02](02-the-rename.md)).
2. Close the four exploitable defects in [01](01-current-state-audit.md) §1.2 **before**
   building anything else.
3. Give the module a real identity — a modules table with settings, replacing the sentinel
   row ([04](04-database-schema.md)).
4. Real sessions: hashed, multi-device, expiring, revocable ([05](05-authentication.md)).
5. Real SSO: an authorization-code exchange verified against the provider
   ([06](06-sso.md)).
6. The full lifecycle a login system needs — email verification, password reset, rate
   limiting ([05](05-authentication.md)).
7. A standalone externals SDK, `window.Portal.siteUsers`, usable on a site that never
   touched the builder, with `portal.d.ts` kept current ([07](07-externals-api.md)).
8. **A site user can be equated with a processor `customer_id`**, so a Direct Payment
   charge — subscription or single-pay — is attributable to an account
   ([08](08-direct-payments-link.md)).
9. Optional, per-module adoption by ecommerce, events, appointments and the rest, through
   configuration rather than code changes ([09](09-app-integrations.md)).

## 3. Non-goals

- **Not Checkpoint.** Site Users does not authenticate platform members, and never issues
  a Checkpoint token. §3.2 of [03](03-architecture.md) draws the line.
- **Not an identity provider for other tenants.** A site user belongs to one tenant's
  module. Cross-tenant SSO is out of scope and would need a different trust model.
- **Not roles and permissions.** A flat user record with an `is_active` flag. Per-module
  authorisation belongs to the module. If a shared role model is wanted later it is additive.
- **Not MFA** in this pass. The schema leaves room ([04](04-database-schema.md) §4.3); the
  flows do not implement it.
- **Not a migration of existing member accounts.** `brain_memberships` is a different system
  for a different audience.

## 4. Glossary

| Term | Meaning |
|---|---|
| **Site Users** | This module. Formerly "System Users" |
| **Module instance** | One deployment of Site Users, identified by `site_users_id` (formerly `systemsusers_id`, formerly a `sys-XXXX` reference) |
| **Site user** | An end user of a tenant's site — a shopper, attendee, booker. **Not** a platform member |
| **Member** | An Appfactory Studio customer, in `brain_memberships`, authenticated by Checkpoint. A different population |
| **Session** | A token-backed login, one row per device ([05](05-authentication.md) §5.2) |
| **Identity** | A way a user can authenticate: a password, or a linked SSO provider account ([04](04-database-schema.md) §4.4) |
| **Externals SDK** | `window.Portal.siteUsers`, loadable on any site ([07](07-externals-api.md)) |

## 5. The decisions

| # | Decision | Why | Detail |
|---|---|---|---|
| **N1** | **Security remediation gates the plan.** Nothing else is built until **M1**–**M4** are closed | Two of the four are exploitable without credentials, on a module that is already deployed. Building features on an unauthenticated admin API compounds the exposure | [11](11-rollout-and-testing.md) §11.2 |
| **N2** | **Rename by adding, not by mutating.** New tables and routes carry the new name; the old names remain as views/aliases until the deprecation window closes | A live tenant deployment is mid-flight. A rename that drops the old route in the same release breaks every page a tenant already published | [02](02-the-rename.md) §2.3 |
| **N3** | **A real `laravel_site_user_modules` table** replaces the `__systemsusers_marker__` sentinel row | The module needs a name, settings, allowed origins and state. A credentials table cannot carry those, and the sentinel abuses a unique index that was not designed for it | [04](04-database-schema.md) §4.2 |
| **N4** | **Sessions get their own table**, one row per device, token stored **hashed** | The current one-token-per-user column means a second login silently kills the first, and plaintext storage makes a table read a total compromise | [05](05-authentication.md) §5.2 |
| **N5** | **Identities get their own table** — one row per (user, provider) | A user should be able to have a password *and* Google *and* Apple. The current `provider`/`provider_id` columns on the user row allow exactly one, and force a duplicate account for the second | [04](04-database-schema.md) §4.4 |
| **N6** | **SSO is an authorization-code exchange performed server-side**, never a client-asserted `provider_id` | **M3** is an authentication bypass. The client must never be the source of "who this is" | [06](06-sso.md) §6.2 |
| **N7** | **The externals API is the primary interface**; the builder is one consumer of it | The stated requirement is standalone use on a non-builder site. Anything the builder can do that the SDK cannot is a design error | [07](07-externals-api.md) §7.1 |
| **N8** | **The user↔customer link is its own table, owned by Site Users** | A processor customer is a payments-layer fact; a site user is an identity-layer fact. Neither should carry a column pointing at the other — and a user may legitimately have customers on several processors | [08](08-direct-payments-link.md) §8.2 |
| **N9** | **Module adoption is configuration, not code.** A module declares it supports Site Users; a tenant switches it on per module | Nine modules × a bespoke integration each is how this ends up half-adopted and inconsistent. One contract, one toggle | [09](09-app-integrations.md) §9.2 |
| **N10** | **Tokens are opaque and server-validated**, not JWTs | A JWT that cannot be revoked is the wrong default for a system whose whole job is letting a tenant kick someone out. Opaque + a sessions table gives revocation for free | [05](05-authentication.md) §5.3 |
| **N11** | **The SDK stops putting tokens in `localStorage` by default** | **M15**. This SDK runs on sites the tenant does not control. Default to an httpOnly cookie where same-origin, and make `localStorage` an explicit opt-in with the tradeoff documented | [07](07-externals-api.md) §7.4 |
| **N12** | **Route protection runs in `BuilderPublishedController::show()`, not in route middleware** | Routes are database rows, unknown until the lookup. Every dynamic page already passes through one server-side function, so a protected page's HTML is simply never sent — real access control, not a client-side redirect | [12](12-builder-integration.md) §12.5 |
| **N13** | **Protection lives on the route, not on the published version** | `laravel_builder_published` is versioned and "Republish" restores old rows. A flag stored there would be silently reverted by a content rollback — a security setting must not be undone by an unrelated undo | [12](12-builder-integration.md) §12.5.1 |
| **N14** | **API protection is one config-driven middleware, and fails open** | The requirement is no Site Users code in ecommerce, events or appointments — met by matching request paths against `laravel_api_guards`. Failing open is deliberate: this ships onto deployments whose APIs are public today, and default-closed would break every existing tenant on deploy | [12](12-builder-integration.md) §12.8 |

## 6. What "done" looks like

A tenant with a plain static site, no builder, adds:

```html
<script src="https://tenant.example.com/external/portal.js"></script>
<div id="login"></div>
<script>
  const su = window.Portal.siteUsers.create('su-a1b2');
  su.loginFlow().mount('#login', { onSuccess: u => location.href = '/account' });
</script>
```

…and their ecommerce checkout, on the same site, sees the same signed-in user — with the
order attributed to a Direct Payment charge whose processor `customer_id` resolves back to
that account.
