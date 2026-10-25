# 11 — Permissions and tenant model

## Completion document

When this plan step is completed, write
`complete/11-permissions-and-tenant-model.complete.md`.

---

Two distinct permission questions exist and this plan keeps them separate: **what can a plugin
do** (capabilities, [05 §4](05-security-and-sandboxing.md) — already fully specified) and **which
of a tenant's own portal users can manage or use a given plugin** (this document).

## 1. The existing model, unchanged

`config/portal_permissions.php` stays exactly as it is
([01 §4](01-current-state-audit.md)) — a static, fifteen-key CRUD permission array for
first-party modules, checked against `users.permissions`. This plan does not edit that file's
structure, and does not add plugin keys into its static `$modules` array, because that array is
compiled into every build and a plugin's permission key does not exist at build time.

## 2. A parallel, dynamic layer for plugin permissions

- Each installed plugin implicitly gets one **plugin-scoped permission key**, derived from its
  slug (`plugin:{slug}`), independent of the fifteen static keys.
- A new local table, `laravel_plugin_user_access` (same local database as
  `laravel_plugin_installs`, [03 §2](03-database-schema.md)) — records, per install, which of
  this deployment's own portal users may access that plugin's screens at all, and whether they
  may also manage its settings/grants versus only use its nav-linked view:

| Column | Type | Notes |
|---|---|---|
| `install_id` | fk → `laravel_plugin_installs.id` | |
| `portal_user_id` | fk → this deployment's own portal users table | |
| `access_level` | enum(`use`,`manage`) | `manage` includes settings, permissions, update, disable, uninstall; `use` is nav-linked view access only |

- **Default:** whichever portal user installs a plugin gets `manage`; a super-admin-level portal
  user (the existing `level == 99` check `main.ts` already uses for Brain-nav gating,
  [01 §2](01-current-state-audit.md)) can grant `use` or `manage` to other portal users from the
  Installed screen ([09 §4](09-admin-dashboard-ux.md)).
- `utils/permissions.ts`'s `setViewPermissionMap`/`applyPermissionsToNav`
  ([01 §4](01-current-state-audit.md)) is extended, not replaced: after applying the static map,
  it applies a second, dynamic pass reading this deployment's own local
  `laravel_plugin_user_access` table — a plugin's nav item is hidden from a portal user with no row
  here at all, the same fail-closed default the static map already uses for an unrecognized view
  key.

## 3. Why this is additive, not a rewrite

Every one of the fifteen existing callers of `portal_permissions.php`'s static structure —
`view_permission_map`, the CRUD defaults, the labels used in whatever admin screen manages portal
user permissions today — continues to see exactly the same shape it always has. The dynamic layer
is consulted **only** for a view id that the plugin nav-generation step
([08 §2](08-frontend-runtime-and-dynamic-loading.md)) produced, never for one of the fifteen
static keys, so there is no path by which this change could alter an existing permission decision
for a first-party module.

## 4. Tenant isolation, restated in permission terms

Corrected 2026-09-27 alongside the rest of this plan ([00 §0](00-overview.md)): there is no
`tenant_reference_num` column anywhere in [03](03-database-schema.md)'s local tables, because
tenant isolation here isn't a column to check — it's a property of the deployment itself. A
portal user account, `plugin_user_access` row, and every `install_id` it references all live in
*one* tenant's own database. There is no cross-tenant permission concept to even state as a rule,
because there is no shared table two tenants' rows could ever appear in together — a portal user's
access to Plugin X on one tenant's deployment says nothing about any other deployment, not because
a check forbids it, but because that other deployment's database is simply somewhere this portal
user account doesn't exist at all (an estate-wide, cross-tenant portal-user identity is out of
scope entirely; D2 already scopes this plan to what exists today).

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | `plugin_user_access` table and default `manage`-on-install grant | Installing a plugin makes it immediately usable by the installer and invisible to every other portal user until explicitly granted |
| **P1** | `utils/permissions.ts` dynamic-pass extension | A portal user with a `use`-only grant sees the plugin's nav item but not its Settings/Permissions/Update/Disable/Uninstall controls |
