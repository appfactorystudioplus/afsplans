# 03 — Architecture

## 3.1 The shape

```
                        ┌──────────────────────────────────────┐
   any website  ───────▶│   window.Portal.siteUsers  (SDK)     │
   (no builder)         └──────────────────┬───────────────────┘
                                           │  HTTPS, module id + origin
   builder-published ──────────────────────┤
   tenant page                             ▼
                        ┌──────────────────────────────────────┐
                        │  /api/siteusers/public/*          │  ← public: register,
                        │  (rate-limited, origin-checked)      │    login, sso, verify
                        └──────────────────┬───────────────────┘
                                           │
                        ┌──────────────────▼───────────────────┐
                        │  laravel_site_user_*              │
                        │  modules · users · identities        │
                        │  sessions · verifications · links    │
                        └──────────────────┬───────────────────┘
                                           │ session guard
        ┌──────────────────────────────────┼──────────────────────────────┐
        ▼                  ▼               ▼              ▼               ▼
   ecommerce           events        appointments      forms        discussions
   (optional, per-module toggle — 09-app-integrations.md)
                                           │
                                           ▼
                        ┌──────────────────────────────────────┐
                        │  site user ↔ processor customer   │  08-direct-payments-link.md
                        └──────────────────────────────────────┘
```

## 3.2 Site Users is not Checkpoint

The single most important boundary in this document, because the two are both "auth" and
conflating them would be architecturally fatal.

| | Checkpoint (`auth`) | Site Users |
|---|---|---|
| **Authenticates** | Appfactory Studio **members** — the platform's own customers | A **tenant's end users** — that tenant's shoppers, attendees, bookers |
| **Population** | `brain_memberships`, one per paying customer | `laravel_site_users`, thousands per tenant |
| **Scope** | Ecosystem-wide; one member across coordinator, notes, flow, members | One tenant's module instance. A user of `shop-a.com` is unknown to `shop-b.com` |
| **Token** | Checkpoint bearer, per-app, validated against `auth` | Opaque session token, validated against the tenant's own database |
| **Owns** | Tier, allocation, entitlement | Nothing but identity |

**A site user never receives a Checkpoint token, and a Checkpoint token never
authenticates a site user.** They do not share a table, a token format or a trust
boundary.

The one legitimate relationship is *ownership*: a member owns a website, that website hosts a
Site Users module, and the module contains end users. That is a containment relationship,
not a federation.

Concretely, the rule for implementers: **no file in `app/Http/Controllers/Externals/SiteUsers/` may
import anything from `App\Http\Controllers\Checkpoint` or read `brain_memberships`.** If it
needs to know which member owns the site, it is answering the wrong question — the module id
already scopes everything it is allowed to see.

## 3.3 Multi-tenancy — three layers of scope

Every deployment of `www` is a tenant with its own database, so tenant isolation is a
property of the deployment, not of this module. What this module *must* get right is the
layer below:

```
tenant deployment (own database)
└── module instance          site_users_id  — e.g. su-a1b2
    └── user                 reference_num    — usr-XXXXXXXX
        ├── identity         password | google | facebook | apple
        └── session          one per device
```

**Every query in this module filters on `site_users_id`.** Not because a tenant has many
today — most will have one — but because the schema was built for many (**M6** notes the
admin API forgot this) and because "there is only ever one" is the assumption that makes the
second one a rewrite.

The rule is enforceable: a model-level global scope, plus a test that asserts no query
against `laravel_site_users` executes without a `site_users_id` predicate.

## 3.4 Where the API should live

Today the module's API is reachable at two prefixes, one authenticated and one not
(**M1**, **M14**):

| Prefix | Content | Auth |
|---|---|---|
| `/portal/api/systemsusers/*` | Admin | `ApiAuthMiddleware` ✅ |
| `/auth1/api/systemsusers/*` | Admin **and** public | **none** ❌ |

`auth1` is a *published-site* route group — `Auth1PublishedController` serves a builder-built
site there. The module's API ended up inside it because that is where the login page lived.
That is a coincidence of layout, not a design.

**The target:**

| Prefix | Content | Auth |
|---|---|---|
| `/portal/api/siteusers/*` | **Admin only** | `ApiAuthMiddleware` |
| `/api/siteusers/public/*` | **Public only** — register, login, sso, verify, reset | Rate limit + origin check ([05](05-authentication.md) §5.6) |

Two prefixes, two audiences, **one route list each**, and the admin surface is never
reachable from an unauthenticated prefix. `/auth1/api/siteusers/*` survives as a
deprecated alias of the public routes only ([02](02-the-rename.md) §2.3) — the admin routes
in that file are **deleted in Phase A**, not aliased, because they should never have been
there.

That last point is the fix for **M1** and it is not a rename: it is a deletion, and it can
ship before the rename does.

## 3.5 The module contract other apps depend on

Everything a consuming module needs, and nothing more:

```php
interface SiteUsersGateway
{
    /** The signed-in user for this request, or null. Never throws. */
    public function currentUser(Request $request): ?SiteUser;

    /** Is Site Users on for this module instance and this consuming app? */
    public function isEnabledFor(string $appKey): bool;

    /** Resolve a user by reference, scoped to the active module. */
    public function find(string $referenceNum): ?SiteUser;
}
```

**Deliberately not on it:** anything that creates, mutates or authenticates a user. A
consuming module reads identity; it never issues it. If ecommerce could create a user, there
would be five registration paths with five password policies.

`currentUser()` returning `null` rather than throwing is what makes adoption optional
([09](09-app-integrations.md)): a module that does not care simply never calls it, and one
that does can treat absence as "guest" without a try/catch.

## 3.6 Failure posture

| Situation | Behaviour |
|---|---|
| Module not activated | Public endpoints `404`. Not `403` — an unactivated module should not confirm it exists |
| Session token invalid or expired | `currentUser()` → `null`. No exception, no redirect; the consuming module decides |
| SSO provider unreachable during exchange | Fail the login with a retryable error. **Never** fall back to trusting the client's claim — that is **M3** |
| Origin not allowed for this module | `403` before any credential is read ([05](05-authentication.md) §5.6) |
| Rate limit exceeded | `429` with `Retry-After`, and the same response shape for "wrong password" and "no such user" |

The last row is a deliberate anti-enumeration choice and it costs nothing: login already
cannot distinguish the two cases usefully for the user, and distinguishing them for an
attacker is how a user list leaks.
