# 11 — Rollout and testing (status)

**Plan:** `plans/www/site_users/11-rollout-and-testing.md`
**Repo:** `www` (code) · `packages/afsserver` (these records)
**Date:** 2026-08-31, rewritten 2026-09-05

> Consolidates the former `11-rollout-p0-security-gate.complete.md` and
> `11-rollout-status.complete.md` into one record per plan file (the completion-doc
> convention). The P0 detail is §11.2 below.

## Phase checklist

| Phase | Status | Record |
|---|---|---|
| **P0** Security gate | ✅ | §11.2 |
| **P1** Schema | ✅ | [`04-database-schema.complete.md`](04-database-schema.complete.md) |
| **P2** Sessions | ✅ | [`05-authentication.complete.md`](05-authentication.complete.md) |
| **P3** Lifecycle | ✅ | [`05-authentication.complete.md`](05-authentication.complete.md) — email delivery now real; rate-limit table now per §5.6; breach check built (opt-in) |
| **P4** SSO | ✅ (Google verified; Apple/Facebook built, not locally verifiable) | [`06-sso.complete.md`](06-sso.complete.md) |
| **P5** Rename | ✅ Phases A–C | [`02-the-rename.complete.md`](02-the-rename.complete.md) — aliases live, one implementation, deprecation log now built |
| **P6** Externals SDK | ✅ | [`07-externals-api.complete.md`](07-externals-api.complete.md) — all §7.3 methods, `portal.d.ts` unified, stale build copies removed |
| **P7** Admin | ⚠️ **backend complete, SPA screen not built** | [`10-admin-dashboard.complete.md`](10-admin-dashboard.complete.md) |
| **P8** Payments link | ✅ | [`08-direct-payments-link.complete.md`](08-direct-payments-link.complete.md) |
| **P8b/c/d** Builder auth / route protection / API guard | ✅ core; settings UI + visibility trait deferred | [`12-builder-integration.complete.md`](12-builder-integration.complete.md) |
| **P9** App adoption | ✅ registry is load-bearing; 5 modules registered; ecommerce/events/appointments enforce | [`09-app-integrations.complete.md`](09-app-integrations.complete.md) |
| **P10** Rename cleanup | **not started — gated on an empty deprecation log** | §11.5 |
| — Architecture | ✅ | [`03-architecture.complete.md`](03-architecture.complete.md) |

## 11.2 The security gate (P0, N1)

| Finding | Fix | Verified by |
|---|---|---|
| **M1** — unauthenticated admin API on `auth1` | All admin routes removed from `routes/auth1.php`; admin exists only under `/portal/api/{siteusers,systemsusers}` behind `ApiAuthMiddleware` | `SiteUsersSecurityRouteTest` |
| **M3** — `ssoExchange` bypass | Route unregistered in both files; controller method returns `503 SU_SSO_DISABLED` | `SiteUsersSecurityRouteTest::test_sso_exchange_route_is_not_registered` |
| §11.2 item 3 — a route file registering an admin controller without middleware | **Now a written check** — `test_no_route_file_exposes_a_site_users_admin_controller_without_auth` iterates every registered route, not just the known ones | ✅ |

**Manual check still required against a running instance** (§11.2): `curl` the old admin
paths unauthenticated and confirm 404 — a unit test asserting a route is absent does not
prove the deployed router agrees.

## 11.3 Migration / cutover

- Everyone is logged out at the P2 cutover (plaintext `auth_token` values authenticate
  nothing).
- Migrated users are unverified; migrated **modules** default `require_email_verification`
  to `false` (§4.10 / §11.3).
- Users with no identity are logged by the backfill (`logger()->warning`, "M7").

## 11.4 Test matrix — coverage

| Section | Automated coverage |
|---|---|
| Security (P0) | `SiteUsersSecurityRouteTest` (7 cases) |
| Sessions (P2) | `SiteUsersSessionServiceTest`, `SiteUsersAuthFeatureTest` |
| Lifecycle (P3) | `SiteUsersVerificationFeatureTest` (mail, single-use, `sent_to`, 202-always) |
| SSO (P4) | `SiteUsersSsoFeatureTest` (state, `aud`, PKCE/nonce, linking), `JwtToolsTest` (RS256 verify, ES256 sign, `alg:none`, expiry) |
| Externals (P6) | SDK compiles (`build-external-webpack`); method surface in `portal.d.ts` |
| Payments link (P8) | `08-direct-payments-link.complete.md` |
| Builder (P8b–d) | `SiteUsersRouteGuardFeatureTest` |
| App adoption (P9) | `SiteUsersConsumerRegistryTest` |
| Rename (P5) | `SiteUsersDeprecationLogTest`, `SiteUsersSecurityRouteTest` |

~100 SiteUsers-related assertions passing as of 2026-09-05
(`php artisan test --filter="SiteUsers|JwtTools|ConsumerRegistry"`).

Not covered by automated tests: the admin SPA screen (P7 — not built), Apple/Facebook live
SSO (no provider apps on this machine), the exact §5.6 rate-limit *counts* end to end.

## 11.5 Follow-up

| Item | Blocks |
|---|---|
| **The deprecation log must be empty for a full release cycle** before P10. `php artisan siteusers:deprecation-report` is the gate (exit 0 = safe). | P10 |
| **Local DB needs `php artisan migrate:fresh`** to pick up the 2026-09-05 schema edits (renamed builder-route / api-guard columns, `provider_meta`, `check_breached_passwords`, `laravel_site_user_deprecations`). Tests use the in-memory schema helper and already reflect it. | running instance |
| **P7 admin SPA screen** — see [`10-admin-dashboard.complete.md`](10-admin-dashboard.complete.md). | tenant self-service; builder's module-id trait |
| **`portal.d.ts` copies** in `members`, `market`, the creative apps still declare the old shape (none consumes Site Users yet — §7.6). | consumer builds, cosmetic |
| **`wwwafsportal_v1` re-sync** — out of scope per `AGENTS.md` §1, but a real post-P10 gap for freshly provisioned tenants. | — |
| Apple/Facebook live verification | confidence, not correctness |

## 11.6 Rollback

Unchanged from the plan's §11.6 table. P0 and P10 are not rollbackable by design; P8c/P8d
reversion **un-protects live routes** — treat as an incident action.
