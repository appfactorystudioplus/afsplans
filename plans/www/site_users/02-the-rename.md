# 02 — The Rename

System Users → **Site Users**. 227 occurrences across 46 files, plus two tables, two route
prefixes and a published SDK namespace.

## 2.1 The naming table

| Kind | From | To |
|---|---|---|
| Product name | System Users | **Site Users** |
| PHP namespace | `App\Http\Controllers\Externals\SystemsUsers` | `App\Http\Controllers\Externals\SiteUsers` |
| PHP namespace | `App\Models\Externals\SystemsUsers` | `App\Models\Externals\SiteUsers` |
| Classes | `SystemsUsersAdminController`, `SystemsUsersPublicController` | `SiteUsersAdminController`, `SiteUsersPublicController` |
| Models | `SystemsUsersUser`, `SystemsUsersSsoIntegration` | `SiteUser`, `SiteUsersSsoIntegration` |
| Tables | `laravel_systemsusers_users` | `laravel_site_users` |
| Tables | `laravel_systemsusers_sso_integrations` | `laravel_site_user_sso_integrations` |
| Column | `systemsusers_id` | `site_users_id` |
| Module ref prefix | `sys-XXXX` | `su-XXXX` |
| Route prefix | `/portal/api/systemsusers` | `/portal/api/siteusers` |
| Route prefix | `/auth1/api/systemsusers` | `/auth1/api/siteusers` |
| SDK namespace | `window.Portal.systemsusers` | `window.Portal.siteUsers` |
| SDK directory | `resources/js/external/systemsusers/` | `resources/js/external/site_users/` |
| SDK classes | `SystemsUsersClient` | `SiteUsersClient` |
| Admin controller | `controllers/systems_users/systems-users.ts` | `controllers/site_users/site-users.ts` |
| Admin view ids | `systems-users`, `systems-users:detail` | `site-users`, `site-users:detail` |
| Admin route | `/systems-users` | `/site-users` |

**`SiteUser` singular for the model**, against the plural everywhere else: it is one row,
one user, and `SiteUsersUser` is the kind of name that comes from mechanical renaming
rather than reading. The table stays plural because tables are.

## 2.2 What must **not** be renamed

| Leave alone | Why |
|---|---|
| `laravel_user_tokens`, `App\Models\UserToken` | Portal **admin** auth. Unrelated system, and the collision this rename exists to fix |
| `App\Models\User`, `users` table | Laravel's own |
| `brain_memberships`, `Membership` | Platform members ([00](00-overview.md) §4) |
| `laravel_systemsusers_*` **rows** already written | Data migrates; content does not change |
| `routes/auth1.php`'s non-API routes | `Auth1PublishedController` serves the published auth1 site; that is a builder concern, not this module |

The last one matters. `auth1` is **not** an alias for System Users — it is a published-site
route group that happens to also host this module's API. Renaming the module does not rename
`auth1` ([03](03-architecture.md) §3.4 covers why the API should move out of it anyway).

## 2.3 The order — add, migrate, deprecate, remove (**N2**)

A live tenant has published pages calling `/auth1/api/systemsusers/public/login`. A rename
that moves the route in one release breaks them. So:

**Phase A — add.** New tables created empty. New namespaces, classes and routes added
alongside the old. New SDK namespace registered **in addition to** the old:

```ts
// main.ts
siteUsers:  { … },              // new
systemsusers:  { … },              // deprecated alias, same objects
```

**Phase B — migrate.** Copy rows into the new tables (§2.5). Old controllers become thin
delegates to the new ones, so there is exactly one implementation from this point.

**Phase C — deprecate.** Old routes respond with a `Deprecation` header and log every call
with its `Referer`, so the remaining callers are *known* rather than assumed. Old SDK
namespace `console.warn`s once per page.

**Phase D — remove.** Only when the deprecation log has been empty for a full release cycle.
Drop the old routes, alias, tables and namespace.

**Do not compress this.** The temptation is to rename in one commit because the grep is
clean; the thing the grep cannot see is a tenant's published HTML calling the old endpoint
from a page nobody has opened this month.

## 2.4 The build-output trap (**M18**)

`resources/js/external/build/` already contains **two** compiled copies of the module's
declarations:

```
build/systemsusers/*.d.ts
build/external/systemsusers/*.d.ts
```

Renaming the source produces a third set under the new name while both old sets remain, and
`.d.ts` files in a build directory are exactly the kind of thing that gets picked up by a
stale `tsconfig` include and silently resolves the old namespace.

**Delete `build/` and regenerate it. Do not rename inside it.** Then confirm no `tsconfig`
references the removed paths — and note the duplication itself (`build/systemsusers` *and*
`build/external/systemsusers`) is a pre-existing config smell worth a separate look.

## 2.5 The data migration

Two tables, straightforward, but with one decision in it:

```php
// AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/site_users/02-the-rename.md §2.5
//
// Copies rather than renames the table, so Phase A/B can run with both live and
// a rollback is a config flip rather than a reverse migration. The old table is
// dropped in Phase D once the deprecation log is clean.
//
// reference_num values are PRESERVED. The `sys-` prefix stays on existing module
// ids; only newly minted ones use `mu-`. Rewriting them would invalidate every
// published page, every stored SDK config and every external integration that
// hardcoded the id — which is exactly the breakage this phased rename exists to
// avoid. A cosmetic prefix is not worth it.
```

That last point is the one to hold: **existing `sys-XXXX` module ids are not rewritten.**
They are opaque identifiers that tenants have embedded in third-party sites. New modules get
`su-XXXX`; both forms remain valid forever. Anything that parses the prefix to infer meaning
is a bug.

User `reference_num`s (`usr-XXXXXXXX`) are unchanged — they were never branded.

## 2.6 Surface inventory

From a full-repository grep, the rename touches:

| Area | Files | Notes |
|---|---|---|
| Migrations | 1 | Plus the new ones from [04](04-database-schema.md) |
| Models | 2 | |
| Controllers | 2 | |
| Routes | 2 files, 28 route lines | `portal.php` (12), `auth1.php` (16) |
| Seeder | `database/seeders/InitSeeder.php` | 6 occurrences |
| Factory | `database/factories/SystemsUsersUserFactory.php` | 5 |
| Admin UI | `controllers/systems_users/systems-users.ts` (17), `views/systemsUsersView.ts` | |
| Externals SDK | 8 source files | 860 LOC |
| `portal.d.ts` | 18 occurrences | §2.7 |
| Samples & docs | `samples/systemsusers-example.js`, `SAMPLES_GUIDE.md`, `main-example.ts` | |
| Build output | 2 stale copies | §2.4 — delete, do not rename |
| Playground | `public/playground/portal-temp.php` | 12 |

`packages/wwwafsportal_v1` — the tenant template — carries its own copy of the externals
build. **It must be re-synced after Phase D**, or newly provisioned tenants ship the old
namespace ([11](11-rollout-and-testing.md) §11.5).

## 2.7 `portal.d.ts`

`PortalSystemsUsersNamespace` → `PortalSiteUsersNamespace`, and the `Portal` interface
gains `siteUsers` while keeping `systemsusers` marked deprecated until Phase D:

```ts
interface Portal {
    /** @deprecated Renamed to `siteUsers`. Removed after the deprecation window — plans/www/site_users/02 §2.3. */
    systemsusers: PortalSiteUsersNamespace;
    siteUsers: PortalSiteUsersNamespace;
}
```

One interface, two names, so the two can never describe different shapes. And per
[07](07-externals-api.md) §7.6, this file changes **in the same pass as the SDK** and the
copies in consuming apps are refreshed — a rename that lands in `api.ts` but not in
`portal.d.ts` leaves every consumer silently on `(window as any)`.

## 2.8 Acceptance

- `grep -ri "systemsusers\|system users"` over `app/`, `routes/`, `resources/js/` returns
  **only** the deprecation aliases, each with a comment naming its removal phase.
- An existing published page calling `/auth1/api/systemsusers/public/login` still works
  through Phase C, and its call appears in the deprecation log with a `Referer`.
- A module created before the rename keeps its `sys-` id and functions unchanged (§2.5).
- `window.Portal.systemsusers` and `window.Portal.siteUsers` are the same object through
  Phase C; the old one warns once per page.
- `build/` contains exactly one set of declarations, under the new name (§2.4).
- `wwwafsportal_v1`'s externals copy is re-synced; a freshly provisioned tenant exposes
  `siteUsers`.
