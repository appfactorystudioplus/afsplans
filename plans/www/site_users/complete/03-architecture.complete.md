# 03 — Architecture (complete)

**Plan:** `plans/www/site_users/03-architecture.md`
**Repo:** `www`
**Date:** 2026-08-31

The shape the module was built to. Recorded because §3.4's decision was a **deletion** with a
security consequence, and a record of an architecture nobody wrote down is how the next
person re-derives it from the routes.

## §3.4 — where the API lives

This is the section that changed behaviour rather than describing it.

**Before:** the module answered on two prefixes, one authenticated and one not.

| Prefix | Content | Auth |
|---|---|---|
| `/portal/api/systemsusers/*` | admin | `ApiAuthMiddleware` ✅ |
| `/auth1/api/systemsusers/*` | admin **and** public | **none** ❌ |

`auth1` is a *published-site* route group. The module's API landed inside it because that is
where the login page lived — §3.4's *"a coincidence of layout, not a design"*.

**After**, as built:

| Prefix | Content | Auth |
|---|---|---|
| `/portal/api/siteusers/*` | admin only | `ApiAuthMiddleware` |
| `/portal/api/siteusers/public/*` | public only | `site_users.origin`, `site_users.public.throttle` |
| `/auth1/api/siteusers/public/*` | public only | same |

**The `auth1` admin routes were deleted, not aliased.** `routes/auth1.php` records why, in
place:

> *"auth1.php previously registered the SystemsUsers **admin** API with no middleware — user
> enumeration, deletion, and OAuth secret read/write were publicly reachable at
> `/auth1/api/systemsusers/*`."*

That is **M1**, and it is the one item in this plan that had to ship before anything else —
[`11-rollout-and-testing.complete.md`](11-rollout-and-testing.complete.md).

Deleting rather than aliasing is the whole decision. An alias would have preserved a
publicly-reachable admin surface for the length of a deprecation window, which for these
endpoints means preserving user enumeration and OAuth secret disclosure. §3.4 says they
*"should never have been there"*, and a route that should never have existed has no callers
worth protecting.

## §3.2 — Site Users is not Checkpoint

Two identity systems, deliberately, and the distinction is load-bearing:

| | Checkpoint | Site Users |
|---|---|---|
| Who | the **tenant** — the person who owns the website | the tenant's **visitors** |
| Store | shared Brain database, `ext_member_*` | `laravel_site_user*` |
| Scope | the estate | one module instance on one tenant |

A site user is never a Checkpoint user. Confusing them would let a visitor to a tenant's
storefront hold an identity in the system that bills the tenant — see
builder **v1** **B9** ([legacy](../../builder/v1/README.md); [v2](../../builder/v2/README.md) is current),
where the same "one tenant, one operator" assumption fails once a second identity type exists.

## §3.3 — three layers of scope

Every query carries the module reference:

```
tenant (the www instance)
  └ module instance   site_users_id / reference_num
      └ user          SiteUser.site_users_id
```

Verified in the admin controller: **all eleven endpoints take `string $referenceNum`** and
scope on it — `SiteUser::where('site_users_id', $referenceNum)->where('id', $id)` rather than
`find($id)`. That is what makes §10.7's *"a second module can be created, listed and operated
independently"* (**M6**) true at the data layer even though no screen creates one
([`10-admin-dashboard.complete.md`](10-admin-dashboard.complete.md)).

## §3.5 — the module contract other apps depend on

Services under `app/SiteUsers/`:

| Class | Responsibility |
|---|---|
| `SiteUsersAuthService` | register, login, password, verification |
| `SiteUsersSessionService` | sessions; `revokeAllForUser` |
| `SiteUsersModuleService` | module lifecycle — activate, update |
| `SiteUsersGateway` | the seam other modules call |
| `SiteUsersVerificationService` | email verification and reset tokens |
| `ApiGuardRegistry` | the API guard, fail-open |

Both the `SiteUsers` and the legacy `SystemsUsers` controllers construct these same services,
which is what makes the rename an alias rather than a fork
([`02-the-rename.complete.md`](02-the-rename.complete.md)).

## §3.6 — failure posture

`SiteUsersApiGuard` is **fail-open**, recorded in
[`12-builder-integration.complete.md`](12-builder-integration.complete.md).

Worth stating explicitly, because it is the opposite of the posture taken on the Checkpoint
edge, where a missing member now denies (`plans/00_ISSUES.md` **I8**). The two are consistent:
**Checkpoint identifies the paying tenant, and an unidentifiable tenant must not get a
session. The site-users guard decorates a visitor's request, and a guard outage must not take
a tenant's public website down.** Different blast radius, opposite default. They must not
share a helper.

## Verification

```bash
cd www
php artisan route:list | grep -E "siteusers|systemsusers"   # no admin route outside ApiAuthMiddleware
grep -rn "systemsusers" routes/auth1.php                     # the M1 comment, public routes only
ls app/SiteUsers/
php artisan test --filter=SiteUsers
```

Read directly on 2026-08-31: `routes/auth1.php`, `routes/portal.php` (`:158–240`),
`app/SiteUsers/*`, `app/Http/Controllers/Externals/SiteUsers/SiteUsersAdminController.php`.
