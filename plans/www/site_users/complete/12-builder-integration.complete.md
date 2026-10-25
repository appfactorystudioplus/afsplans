# 12 — Builder Integration (complete)

**Plan:** `plans/www/site_users/12-builder-integration.md`
**Rollout phase:** P8b / P8c / P8d
**Repo:** `www`
**Date:** 2026-08-31, extended 2026-09-05

## §12.3 — auth components

`resources/js/portal/builder/core/site_users_components.ts` +
`core/component_scripts/site_users_component_script.ts` +
`core/modules/site_users.module.ts`. Blocks registered: **Login**, **Register**, **User Menu**.

Both guards are shared, not copy-pasted: `core/component_scripts/portal_helpers.ts` exposes
`isEditorMode()` and `waitForPortal(namespace, cb)` (§12.10 step 1 — "exists once").

> **Superseded 2026-09-11** — [`builder/v2/08-site-users-pages.md`](../../builder/v2/08-site-users-pages.md)
> ([complete](../../builder/v2/complete/08-site-users-pages.complete.md)) retired all three
> blocks and moved site_users to a page-level module (Login/Register/Dashboard/Forgot/Reset
> pages), the same move v2 already made for ecommerce/events/appointments. The two files above
> are deleted; `core/modules/site_users.module.ts` is rewritten, not extended.
> `retired.ts` migrates a page still carrying one of the three old blocks onto the matching
> page view.

## §12.5 — route protection (P8c)

`BuilderPublishedController::show()` runs the guard **before any HTML is read** — a protected
route's markup is never sent.

`laravel_builder_routes` — columns now match the plan verbatim (04-database-schema.complete.md
"Schema reconciliation"): `requires_auth`, `unauthenticated_behaviour` (`redirect` |
`not_found`), `is_public` (the §12.5.2 escape hatch), `site_users_id`, `require_verified_email`,
`login_route`, `verify_route`.

### §12.5.1 — protection belongs to the route, not the version

The row is keyed on the slug, unversioned, and survives republish / rollback. It is deleted
only by an explicit "clear protection" act.

### §12.5.2 — inheritance (new 2026-09-05)

`BuilderRoute::forSlug()` now resolves **most-specific-first with inheritance**: a descendant
may tighten but not loosen; an exact-match `is_public` row short-circuits before inheritance.
`BuilderRoute::assertHierarchyAllows()` is the **save-time guard** — a child row that would
turn protection off under a protected parent is rejected. Both verified in
`SiteUsersRouteGuardFeatureTest`.

## §12.7 / §12.8.7 — the settings backend (new 2026-09-05)

`SiteUsersRouteProtectionController` under the `siteusers` admin group:

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/routes?subpaths=` | effective + own + **inherited-from** state (§12.7) |
| `PUT` | `/routes` | upsert; runs `assertHierarchyAllows` |
| `DELETE` | `/routes` | clear protection |
| `GET/PUT` | `/api-guards` | list / upsert guard rows |
| `DELETE` | `/api-guards/{id}` | remove; flushes the registry cache |

Route/guard config saves **independently of publishing**. The builder page-settings **panel
UI** (radio buttons, "inherited" notice, removal confirmation) is deferred — this is its API.

## §12.8 — the API guard (P8d)

`laravel_api_guards` columns now match the plan: `requires_auth` (bool), `site_users_id`.
`SiteUsersApiGuard` middleware wraps the `/portal/*` API group in `bootstrap/app.php`,
**fails open** (no matching row → passes through), and sets `$request->attributes->set('site_user', …)`
for a module to read. Cached via `ApiGuardRegistry` (flushed on save).

## Acceptance — §12.11 / §12.8.8

| Criterion | State |
|---|---|
| Protected route returns **no page HTML** to a guest | ✅ `SiteUsersRouteGuardFeatureTest` (asserts redirect before render) |
| `not_found` behaviour → 404 like a nonexistent slug | ✅ |
| Protecting `/members` protects `/members/orders` without a second row | ✅ **now** (`forSlug` inheritance) |
| A child cannot declare itself unprotected under a protected parent — rejected at save | ✅ **now** (`assertHierarchyAllows`) |
| Site-wide guard + `public: true` on `/login` → tenant can still sign in | ✅ `is_public` short-circuit |
| Republishing an older version does not unprotect a route | ✅ row is unversioned, keyed on slug |
| `curl` a guarded API path, no session → 401 | ✅ `SiteUsersRouteGuardFeatureTest` |
| Unguarded path unaffected | ✅ fail-open |
| `waitForPortal` / `isEditorMode` defined once | ✅ `portal_helpers.ts` |
| Sign in after redirect lands on the intended URL (`su_intended`) | ✅ flashed to session in `show()` |

## Deferred

| # | Work | Status |
|---|---|---|
| 1 | The page-settings **panel UI** in `core/pageManager.ts` (§12.7) — three things it must get right: show inherited state, explain `not_found` in words, confirm removal. Backend is ready. | Open |
| 2 | **Account** and **Password-reset** builder blocks (§12.3) — follow the Login block's shape. | **Resolved 2026-09-11**, but as pages, not blocks — Dashboard and Reset password in [builder/v2/08](../../builder/v2/complete/08-site-users-pages.complete.md) |
| 3 | The **`su_visibility` trait** (§12.6) — `always` / `signed_in` / `signed_out`, published as `visibility:hidden` then revealed. Presentation only. | Already shipped as `data-siteusers-visible-to` (v2 P0, `433bc40cf`) — this table was never updated |
| 4 | Editor-canvas placeholder polish for all blocks (§12.4). | Moot — no site_users blocks exist any more after [builder/v2/08](../../builder/v2/complete/08-site-users-pages.complete.md) |

## Verification

```bash
cd www
php artisan test tests/Feature/SiteUsers/SiteUsersRouteGuardFeatureTest.php
```
