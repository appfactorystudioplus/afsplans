# 02 — The rename (Phase A complete)

**Plan:** `plans/www/site_users/02-the-rename.md`
**Rollout phase:** P5 — rename
**Repo:** `www`
**Date:** 2026-08-31 (record written retrospectively — see *Why this document was missing*)

> **Phase A of four.** §2.3's order is **add → migrate → deprecate → remove**. Add is done and
> verified below. Deprecate is partial. Migrate and remove are not started, and P10 in
> [`11-rollout-and-testing.complete.md`](11-rollout-and-testing.complete.md) is where they live.

## Why this document was missing

`11-rollout-and-testing.complete.md` records P5 as *"✓ partial (aliases live)"* and cites this
file, which did not exist. So did P7, citing `10-admin-dashboard.complete.md`. Both phases were
marked done against records nobody had written — which is how a reader learns that "done" here
meant "the code landed", with no statement of *how much* of the document it satisfied.

Both records now exist. **Neither claims more than the code does.**

This document is the backend/SDK rename only (namespaces, routes, the externals SDK). The
admin-UI rename it names as "in scope" (§2.1's naming table, §2.6's surface inventory) landed
2026-09-11, merged with a second screen that had drifted alongside it — recorded in
[`10-admin-dashboard.complete.md`](10-admin-dashboard.complete.md) §10.6, not here.

## What was built

### The aliases are real aliases, not a second implementation

This was worth checking, because a "rename" that copies a controller produces two
implementations that drift. It did not:

```php
/**
 * SystemsUsersPublicController
 *
 * Legacy public API — delegates to Site Users services (site_users_id = systemsusers_id).
 *
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/site_users/02-the-rename.md Phase A alias.
 */
```

`SystemsUsersPublicController` and `SystemsUsersAdminController` both construct the same
`SiteUsersAuthService` / `SiteUsersModuleService` the new controllers use. There is one
implementation with two doors, which is what §2.3 Phase A asks for.

### Routes

| Prefix | Controller | Middleware |
|---|---|---|
| `/portal/api/siteusers/public/*` | `SiteUsersPublicController` | `site_users.origin`, `site_users.public.throttle` |
| `/portal/api/systemsusers/public/*` | `SystemsUsersPublicController` (alias) | same |
| `/portal/api/siteusers/*` | `SiteUsersAdminController` | `ApiAuthMiddleware` |
| `/portal/api/systemsusers/*` | `SystemsUsersAdminController` (alias) | `ApiAuthMiddleware` |
| `/auth1/api/siteusers/public/*` | `SiteUsersPublicController` | public middleware |
| `/auth1/api/systemsusers/public/*` | `SystemsUsersPublicController` (alias) | public middleware |

**The `auth1` admin routes are deleted, not aliased** — §3.4's M1 fix, and the one part of this
document that is a deletion rather than a rename. `routes/auth1.php` says so in place:

> *"auth1.php previously registered the SystemsUsers **admin** API with no middleware — user
> enumeration, deletion, and OAuth secret read/write were publicly reachable at
> `/auth1/api/systemsusers/*`. Admin routes now exist only under `/portal/api/systemsusers`
> behind `ApiAuthMiddleware`."*

That is the P0 security gate, recorded separately in
[`11-rollout-and-testing.complete.md`](11-rollout-and-testing.complete.md).

### Externals SDK (§2.7)

`portal.d.ts` declares both namespaces:

```ts
siteUsers:    PortalSiteUsersNamespace;
systemsusers: PortalSystemsUsersNamespace;
```

and `resources/js/external/site_users/index.ts` warns once per page, not once per call:

```ts
let deprecatedWarned = false;
…
if (!deprecatedWarned) {
    deprecatedWarned = true;
    console.warn('[Portal] window.Portal.systemsusers is deprecated. Use window.Portal.siteUsers.');
}
```

## Acceptance — §2.8, item by item (updated 2026-09-05)

| § 2.8 criterion | State |
|---|---|
| `Portal.systemsusers` and `Portal.siteUsers` both resolve; the old warns once per page | ✅ now the **same object** — `legacySystemsUsersNamespace` is a Proxy over `siteUsersNamespace` that warns once |
| A module created before the rename keeps its `sys-` id | ✅ aliases pass `systemsusers_id` through as `site_users_id` |
| An old published page calling `/auth1/api/systemsusers/public/login` still works | ✅ route present, delegating |
| …**and its call appears in the deprecation log with a `Referer`** | ✅ **now** — `laravel_site_user_deprecations`, `SiteUsersDeprecationLog` middleware; `SiteUsersDeprecationLogTest` |
| `grep -ri "systemsusers"` returns only aliases, each commented with its removal phase | ✅ both route groups and both route files now carry "removed in P10" comments |
| `build/` contains one set of declarations under the new name | ✅ `sdk.js` no longer bundles `SystemsUsersClient` (`grep -c` → 0); stale `build/*/systemsusers` declaration copies deleted. The `systemsusers/` **source** dir remains, unimported, for P10 deletion |
| `wwwafsportal_v1`'s externals copy re-synced | ⛔ **out of scope** — that package is excluded estate-wide (`AGENTS.md` §1) |

**Six of seven pass; the seventh is permanently out of scope.**

## Phase status

| Phase | State |
|---|---|
| A — add | ✅ new tables, namespaces, routes, SDK namespace |
| B — migrate | ✅ one implementation — both controller sets construct the same services; the SDK ships one client |
| C — deprecate | ✅ deprecation log built; `Deprecation` + `Sunset` headers on every legacy route; SDK warns once |
| D — remove | **not started — gated on `siteusers:deprecation-report` returning exit 0** for a full release cycle |

## What P10 (Phase D) removes

- `SystemsUsers{Public,Admin}Controller` and both `systemsusers` route prefixes
- `resources/js/external/api/systemsusers/` source dir + `legacySystemsUsersNamespace`
- the `@deprecated systemsusers` line in `portal.d.ts`
- the `laravel_systemsusers_*` tables (once the backfill is confirmed and the deprecation log clean)

## Verification

```bash
cd www
php artisan siteusers:deprecation-report        # exit 0 = P10 is safe
grep -rn "P10" routes/portal.php routes/auth1.php
grep -c "SystemsUsersClient" public/external/api/sdk.js   # 0
php artisan test --filter="SiteUsers"
```
