# 10 — The Admin Dashboard (complete)

**Plan:** `plans/www/site_users/10-admin-dashboard.md`
**Rollout phase:** P7 — admin
**Repo:** `www`
**Date:** 2026-08-31, extended 2026-09-05, SPA screen built 2026-09-11

> ### The SPA screen is built, merged with the old "Systems Users" screen
>
> **Every admin endpoint exists, is scoped, and is correct** (unchanged since the last
> revision of this doc). `resources/js/portal/admin/controllers/site_users/site-users.ts` now
> exists — one file, three views (module list, module detail with Settings/SSO/Users tabs, and
> per-user detail) — replacing BOTH the legacy `controllers/systems_users/systems-users.ts`
> screen (module-naive, called the deprecated `/portal/api/systemsusers/*` alias) and the
> narrower `controllers/modules/siteUsersPanel.ts` module-list panel (§5.2/B19) that had been
> living alongside it. There were, as a result, **two side-nav entries both rendering "Site
> Users"** pointing at two different, overlapping screens — this pass merges them into one nav
> entry, one screen, at `/site-users`.
>
> The screen is reached from the Modules index per
> builder **v1** ([legacy](../../builder/v1/README.md))
> §5.3, as intended, and also has its own top-level nav entry (§5.2, B19's own reasoning: one
> fewer click to the id the builder asks for).

## What was built — backend

`app/Http/Controllers/Externals/SiteUsers/SiteUsersAdminController.php`, all under `ApiAuthMiddleware`,
all taking an explicit module reference (M6). **12 endpoints** (was 11):

| § | Endpoint | Notes |
|---|---|---|
| 10.2 | `GET /status`, `POST /activate`, `PATCH /modules/{ref}` | `updateModule` now validates `check_breached_passwords` and **rejects `allowed_origins` entries that carry a path** (§10.2) with a message naming the expected form |
| 10.4 | `GET /modules/{ref}/sso` | returns `redirect_uri_helper` — the exact callback URI per provider to paste into the provider console (§10.4) |
| 10.4 | `POST /modules/{ref}/sso` | accepts `apple_team_id` / `apple_key_id` → `provider_meta`; `client_secret` for Apple is the `.p8` body |
| 10.4 | `DELETE /modules/{ref}/sso/{id}` | |
| 10.3 | `GET /modules/{ref}/users`, `GET …/users/{id}` | `getUser` now returns `email_verified_at`, `identities`, active `sessions`, and `customer_links` |
| 10.3 | `POST …/users/{id}/password` | revokes all sessions after (§5.2) |
| 10.3 | `DELETE …/users/{id}` | revokes all sessions, then deletes |
| 10.3 | `POST …/users/{id}/revoke-sessions` | all sessions |
| 10.3 | **`DELETE …/users/{id}/sessions/{sessionId}`** *(new)* | one device — "someone else is in my account" fixable without ending the user's own session |

`SystemsUsersAdminController` aliases the same service layer.

## Acceptance — §10.7

| Criterion | State | Evidence |
|---|---|---|
| Every admin endpoint takes an explicit module reference | ✅ | method signatures |
| No admin response contains `client_secret` / `password_hash` / `token_hash` | ✅ | `toAdminArray()` → `has_secret`; `SiteUsersAdminFeatureTest` |
| Setting a password from admin revokes sessions | ✅ | `updateUserPassword` |
| An origin with a path is rejected, naming the expected form | ✅ **now** | `SiteUsersAdminFeatureTest::test_update_module_rejects_an_origin_with_a_path` |
| The redirect-URI helper's value is available | ✅ **now** | `listSso` → `redirect_uri_helper`; `SiteUsersAdminFeatureTest` |
| Sessions panel lists two devices; revoking one leaves the other working | ✅ **now** — the user-detail view's Sessions card, per-row Revoke + a Revoke all button |
| A second module can be created, listed, operated independently (M6) | ✅ **now** — the module-list screen's Activate button + Settings tab, module-scoped throughout |

**Both the security and the UI criteria pass.**

## §10.6 — rename (done 2026-09-11)

Nav label `SystemsUsers` → **Site Users** (`i18n/en.json`, `html/main.html`) — done earlier,
unchanged. The screen itself is now actually renamed and rebuilt, not just relabeled:
`controllers/systems_users/systems-users.ts` and `controllers/modules/siteUsersPanel.ts` are
both **deleted**; `controllers/site_users/site-users.ts` (new) is the one screen both merged
into. View ids `systems-users` / `systems-users:detail` → `site-users` / `site-users:detail`
(+ new `site-users:user` for the per-user screen the old `:detail` id actually was). Route
`/systems-users` → `/site-users` (+ `/site-users/detail`, `/site-users/user`). One side-nav
entry now, not two.

Cross-repo: `w/www`'s builder app (`core/modules/instances.ts`) had `siteUsersInstances
.adminUrl()` / `.createUrl()` pointed at the old `/siteusers` panel route (plans/www/builder/
v2's page-level-module work, 2026-09-11) — repointed to `/site-users/detail` /
`/site-users` in the same pass so the builder's "Configure" / "Create one" links keep working.

## Deferred

None — both items this table previously listed are done (module settings/SSO/sessions screen
built; the screen internals renamed). i18n: only `en.json` was updated for the new
`site-users*` keys; the other four locale files (`es`, `fr`, `de`, `zh`) still carry the old,
now-dead `systems-users.*` keys and fall back to the raw key for the new ones — pre-existing
practice in this codebase (`nav.site_users` was already missing from every locale file before
this pass), not a regression, but worth a pass if those locales are ever actually served.

## Verification

```bash
cd www
php artisan test tests/Feature/SiteUsers/SiteUsersAdminFeatureTest.php
php artisan route:list --path=portal/api/siteusers
npx tsc --noEmit
npm run check:modules
npm run test:builder
npm run build
```
