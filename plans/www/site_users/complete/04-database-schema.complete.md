# 04 — Database schema (complete)

**Plan:** `plans/www/site_users/04-database-schema.md`
**Rollout phase:** P1 — schema
**Repo:** `www`
**Migration:** `www/database/migrations/2026_08_31_180000_create_site_users_schema.php`
**Date:** 2026-08-31, revised 2026-09-05

## What was built

Nine tables in one guarded migration, plus the `laravel_systemsusers_*` backfill. One
file rather than the ten §4.9 lists — `AGENTS.md` §5 ("related tables belong in one file")
takes precedence over the plan's file split, and `builder_routes` / `api_guards` are created
here even though the **builder** owns them, so the whole schema lands together (§4.9's own
note).

| Table | § | Notes |
|---|---|---|
| `laravel_site_user_modules` | 4.2 | All policy columns present. `password_min_length` default 12, `require_email_verification` default true, `link_payment_customers` default false (08 §8.6) |
| `laravel_site_users` | 4.3 | `email_verified_at`, `display_name`, `last_login_at`, `metadata` added. Composite `unique(site_users_id, email)` and `unique(site_users_id, username)` — the fix for M7's second half |
| `laravel_site_user_identities` | 4.4 | `unique(user_id, type)` and `unique(site_users_id, type, provider_id)` — the M3 race guard |
| `laravel_site_user_sessions` | 4.5 | `token_hash` char(64) **unique**, SHA-256, plaintext never stored |
| `laravel_site_user_verifications` | 4.6 | One table, `purpose` = `email_verify` \| `password_reset`, `sent_to` frozen at issue |
| `laravel_site_user_sso_integrations` | 4.8 | `scopes` added; **`provider_meta` json added** for Apple's team id / key id (06 §6.4) |
| `laravel_site_user_customer_links` | 08 §8.2 | `unique(processor_reference_num, provider_customer_id)` — the link is unique on the customer, not the user |
| `laravel_builder_routes` | 12 §12.5.1 | See "Schema reconciliation" |
| `laravel_api_guards` | 12 §12.8.3 | See "Schema reconciliation" |

### Backfill (§4.10)

- **Modules** — one row per distinct `systemsusers_id`, named `"Site Users"` when there is
  one, `"Site Users ({id})"` otherwise. `sys-` ids preserved verbatim (02 §2.5).
- **Migrated modules get `require_email_verification = false`** — §11.3's defensible
  departure: their addresses were never verified and the flag must not lie.
- **Users** — copied row-for-row, `email_verified_at` null for every one (§4.10 step 2).
- **Identities** — `password` where a hash exists, else the provider; **a user with neither
  is collected and logged** (`logger()->warning`, "M7") rather than dropped.
- **Sessions** — not migrated. Everyone is logged out at cutover (§4.10 step 4).

## Schema reconciliation (2026-09-05)

The first cut of `laravel_builder_routes` and `laravel_api_guards` shipped with column names
that diverged from the plan (`is_protected`, `visibility` enum, `mode` enum,
`site_users_module`). Per the decision on 2026-09-05 the **code was changed to match the plan
docs verbatim**, not the other way round:

| Table | Was | Now (plan §12.5.1 / §12.8.3) |
|---|---|---|
| `builder_routes` | `is_protected` (bool) | `requires_auth` (bool) |
| `builder_routes` | `visibility` enum `public\|signed_in\|not_found` | `unauthenticated_behaviour` `redirect\|not_found` **+** `is_public` (bool) for the §12.5.2 escape hatch |
| `builder_routes` | `site_users_module` | `site_users_id` |
| `api_guards` | `mode` enum `required\|optional` | `requires_auth` (bool) |
| `api_guards` | `site_users_module` | `site_users_id` |

Touched: the migration, `App\Models\Externals\SiteUsers\BuilderRoute`, `App\Models\Externals\SiteUsers\ApiGuard`,
`App\Http\Middleware\SiteUsersApiGuard`, `App\Http\Controllers\BuilderPublishedController`,
`tests/Support/CreatesSiteUsersSchema`, and three feature tests that build a stub table.

`BuilderRoute::forSlug()` now also implements **§12.5.2 inheritance** — most-specific-first,
a descendant may tighten but not loosen, an exact-match `is_public` row short-circuits before
inheritance — and `BuilderRoute::assertHierarchyAllows()` is the save-time guard against a
child punching a hole under a protected parent.

## Acceptance — §4 criteria that are testable at the schema layer

| Criterion | State |
|---|---|
| Two users with no email coexist; a third sharing an email collides | ✅ composite unique, NULL-distinct |
| A user with no identity row cannot be created in the normal flow | ✅ `SiteUsersAuthService` opens a transaction and writes an identity with the user |
| A provider subject id maps to at most one user per module | ✅ `unique(site_users_id, type, provider_id)` |
| Session `token_hash` is never a value returned to a client | ✅ verified in `SiteUsersSessionServiceTest` |
| `client_secret_encrypted` never leaves an endpoint | ✅ `SiteUserSsoIntegration::toAdminArray()` returns `has_secret` (see 10) |
| Backfilled users are unverified | ✅ `email_verified_at` hard-coded null in the backfill |
| Users with no identity are reported | ⚠️ logged, not surfaced in the admin — see 10 deferred #1 |

## Verification

```bash
cd www
php artisan test --filter="SiteUsers|MigrationOwnership"
```

Passing as of 2026-09-05 (20 tests). `MigrationOwnershipTest` covers the prefix rule, the
one-owner rule and the filename rule for the new tables.
