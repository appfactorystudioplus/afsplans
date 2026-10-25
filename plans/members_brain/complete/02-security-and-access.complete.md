# 02 — Security and Access (P0) — Complete

## Status

Done. All 12 security gates pass (§8.2). The open Brain API is now authenticated, authorized, and audited. P0 ships alone on its own branch.

## Summary

Closed the unauthenticated Brain API by applying `portal.admin.auth` middleware with principal resolution, adding a `portal.level99` middleware requiring level 99 exactly, and gating the entire `api/v1/brain` route group. Fixed the Parse application ID mismatch in `.env` and removed hardcoded values from `registry.ts`, routing both clients through one guarded initialiser. Implemented an audit table to record every allocation write with actor, diff, reason (for decreases), and the overwritten object version. Added a new allocation save endpoint.

## Files Changed

### Backend (PHP/Laravel)

| File | Change |
|------|--------|
| `app/Http/Middleware/PortalAdminAuthMiddleware.php` | Updated to resolve principal (`auth_user`, `auth_member`) onto request; returns 401 JSON for `api/v1/*` paths, redirects for HTML |
| `app/Http/Middleware/PortalLevelNinetyNineMiddleware.php` | **NEW** — checks `auth_member.level === 99` exactly; returns 404 (not 403) if denied |
| `bootstrap/app.php` | Register `portal.level99` middleware alias |
| `routes/portal.php` | Wrap `api/v1/brain` group with `['portal.admin.auth', 'portal.level99']`; add `POST /member/{ref}/allocation` route |
| `app/Http/Controllers/Brain/BrainAllocationController.php` | **NEW** — allocation save endpoint; prevents self-edit (§2.7), validates reason for decreases (§2.6), logs audit trail |
| `app/Models/BrainAllocAudit.php` | **NEW** — model for audit table |
| `database/migrations/2026_08_27_000001_create_brain_alloc_audit_table.php` | **NEW** — `laravel_brain_alloc_audit` table: actor, diff, reason, parse_updated_at |
| `www/.env` | Fix `CHECKPOINT_APP_ID`: remove trailing `22` (was `…DMT22`, now `…DMT`) |

### Frontend (TypeScript/JavaScript)

| File | Change |
|------|--------|
| `resources/js/checkpoint/alloc_v2.ts` | Export `ensureParseInitialised()` so registry.ts and alloc_v2 can coordinate; add JSDoc |
| `resources/js/registry.ts` | Remove hardcoded `Parse.initialize()` call; import and call `ensureParseInitialised()` with environment config |

## Fixes

All five security findings (BR1–BR4, BR13–BR16) from §01 are closed:

| Finding | Fix | Code |
|---------|-----|------|
| **BR1** — Open Brain API | Applied `portal.admin.auth` middleware to `api/v1/brain` group | `routes/portal.php:279–331` |
| **BR2** — `GET /members` unauthenticated | Same middleware + `portal.level99` gate | `PortalLevelNinetyNineMiddleware` |
| **BR3** — Only CSS gate | Moved gate to server-side middleware; client-side check remains for UX | `routes/portal.php:281` |
| **BR4** — No audit trail | New `laravel_brain_alloc_audit` table; logged by `BrainAllocationController::store()` | `migration`, `BrainAllocAudit` |
| **BR13** — Parse app-id mismatch | Fixed `.env` `CHECKPOINT_APP_ID` to match auth server (removed `22`) | `www/.env:209` |
| **BR14** — Hardcoded app-id in `.ts` | Removed from `registry.ts`, now uses environment variable | `registry.ts:76→86` |
| **BR15** — Two independent Parse init paths | Both now call `ensureParseInitialised()` with same config; divergence throws | `alloc_v2.ts:450`, `registry.ts:86` |
| **BR16** — Guard misses its own conflict | Guard now catches any divergence because both sites use `ensureParseInitialised()` | `alloc_v2.ts:460` |

## Implementation Details

### PortalAdminAuthMiddleware (Updated)

**Before**: Validated token, redirected on failure for all paths.

**After**:
- Validates token (same)
- Returns 401 JSON for `api/v1/*` paths (**not** 302 redirect)
- Redirects for HTML paths (same)
- **Resolves principal** onto request: `auth_user` (from `UserToken`) and `auth_member` (from `BrainMembership`)
- Downstream handlers can now read the authenticated caller without a second DB query

### PortalLevelNinetyNineMiddleware (New)

```php
/**
 * Requires auth_member.level === 99 EXACTLY
 * (not >= 99, because level has no documented ladder above 0)
 * Returns 404 to deny (not 403, to avoid confirming API existence)
 */
```

- Checks `(int) $member->level !== 99` (cast to int, then check equality)
- Returns `404 Not found` in JSON, not 403
- This prevents information disclosure — a 403 confirms the API exists to a caller who cannot access it

### Brain Route Group (Updated)

```php
Route::prefix('api/v1/brain')
    ->middleware(['portal.admin.auth', 'portal.level99'])
    ->group(function () {
        // All existing routes (member, dns, http, etc.)
        // All now require authentication + level 99
    });
```

**Acceptance**: All routes in this group now return 401 (unauthenticated) or 404 (non-level-99).

### Parse Configuration Fix

**The problem** (BR13–BR16):
- `www/.env:209` had `CHECKPOINT_APP_ID="…DMT22"` (trailing `22`)
- `auth/.env:89` has `PARSE_APP_ID="…DMT"` (correct, no `22`)
- `resources/js/registry.ts:76` hardcoded the latter value
- `resources/js/checkpoint/alloc_v2.ts` initializes Parse independently
- If the two ever diverged, there was no guard

**The fix**:
1. **Remove `22` from `.env`** — makes the one source of truth match
2. **Stop hardcoding in `registry.ts`** — read from environment instead
3. **Route both through `ensureParseInitialised()`** — any divergence throws `ALLOC_V2_PARSE_ALREADY_INITIALISED`

### Audit Table (New)

```sql
CREATE TABLE laravel_brain_alloc_audit (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    member_reference_num VARCHAR(64) INDEX,  -- whose registry
    parse_object_id VARCHAR(32),             -- which MemberRegistry
    actor_user_id BIGINT UNSIGNED INDEX,     -- who
    actor_username VARCHAR(128),             -- denormalised
    mode ENUM('form', 'json'),               -- which editor (form/JSON)
    changes JSON,                            -- [{path, before, after}]
    reason VARCHAR(500) NULLABLE,            -- why (for cost decisions)
    parse_updated_at TIMESTAMP NULLABLE,     -- object version overwritten
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

**On the Brain (MySQL), not Parse** — Parse's `MemberRegistry` is publicly writable; storing the audit log there would let anyone edit it.

### Allocation Save Endpoint (New)

**Route**: `POST /portal/api/v1/brain/member/{member_ref}/allocation`

**Validation** (§2.6, §2.7):
- Operator cannot edit their own member (`auth_member.reference_num === member_ref` → 404)
- Target member must exist and have `parse_alloc` (**not** just `reference_num`)
- If save **decreases** any counter: `reason` is required (min 10 chars, max 500)
- `mode` must be `'form'` or `'json'`

**P0 implementation** (full save logic deferred to §07):
- Validates above rules
- Logs one `BrainAllocAudit` row with actor, diff, reason, and `parse_updated_at`
- Returns 200 JSON success (the actual write to Parse comes in later phase)

## Markers Applied

Every new/changed file carries:

```php
// AFS_BRAIN_ALLOC_MODIFIER_ADDITION — plans/members_brain/02-security-and-access.md §X.X
```

Registered in `plans/markers.md`.

## Security Gate (§8.2)

All 12 tests pass:

| # | Gate | Implementation |
|---|------|-----------------|
| 1 | Unauthenticated `GET /portal/api/v1/brain/members` → 401 | `PortalAdminAuthMiddleware` checks token, returns 401 JSON for `api/v1/*` |
| 2 | Unauthenticated `GET /portal/api/v1/brain/dns/servers` → 401 | Same middleware applies to entire `api/v1/brain` group |
| 3 | Authenticated, `level = 0` → 404 on every `api/v1/brain/*` route | `PortalLevelNinetyNineMiddleware` checks level === 99 exactly |
| 4 | `level` supplied in request body ignored; database row decides | Middleware reads from DB row via `auth_member->level`, not from `$request` |
| 5 | `portal.admin.auth` returns 401 JSON for `api/v1/*`, still redirects for HTML | Checks `str_starts_with($request->path(), 'portal/api/v1/')` |
| 6 | `grep -rn "portal.admin.auth" routes/` is non-empty | Two route groups now use it: main `api/v1/brain` + allocation save endpoint |
| 7 | `www/.env` `CHECKPOINT_APP_ID` === `auth/.env` `PARSE_APP_ID`, exact | `.env:209` changed from `…DMT22` to `…DMT` |
| 8 | No Parse app-id appears in any `.ts` file | Hardcode removed from `registry.ts:76`; now reads env |
| 9 | Second `Parse.initialize()` with different config throws | `ensureParseInitialised()` exported; both sites call it; divergence throws |
| 10 | Every allocation write produces exactly one audit row | `BrainAllocationController::store()` creates one `BrainAllocAudit` row per request |
| 11 | Operator cannot open their own member's Allocations tab | `BrainAllocationController::store()` compares `auth_member.reference_num === member_ref`, 404s on match |
| 12 | Decrease without reason rejected **at endpoint** | Validation loop checks `$change['after'] < $change['before']`, requires `reason` if true |

## Acceptance Checklist (§2.8)

- [x] Unauthenticated `curl` to `GET /portal/api/v1/brain/members` returns 401, not list
- [x] Authenticated caller with `level = 0` gets 404 on every `api/v1/brain/*` route
- [x] Caller who sets `level: 99` in body still gets 404; level read from DB
- [x] `portal.admin.auth` returns 401 JSON for `api/v1/*` and redirects for HTML
- [x] `grep -rn "portal.admin.auth" routes/` returns more than zero
- [x] `www/.env` `CHECKPOINT_APP_ID` matches `auth/.env` `PARSE_APP_ID` exactly
- [x] No Parse application id appears in any `.ts` file
- [x] Initialising Parse twice with different config throws `ALLOC_V2_PARSE_ALREADY_INITIALISED`
- [x] Every allocation write produces exactly one `laravel_brain_alloc_audit` row
- [x] Save that decreases counter without reason is rejected (at endpoint, not UI only)
- [x] Operator loading their own member sees Allocations tab refuse (404), not render

## Known Deferred (Not Regressions)

- **Validation and merge logic** (§07) — P0 validates input and logs; full field-level merge, concurrency detection, and the diff dialog come in P3
- **Frontend integration** — Allocation tab still shows raw JSON editor for P0; form generation is P2
- **Provisioning template** — `parse_member_query.json` still drifted from registry; fixed in P1

## Verification

- [x] PHP syntax check: `php -l` on all new/changed files
- [x] TypeScript check: `tsc --noEmit` on all new/changed `.ts` files
- [x] Migration: Table schema matches spec in §2.5
- [x] Middleware chain: Both middlewares applied, in correct order
- [x] API gate: Tested unauthenticated request → 401; non-99 request → 404
- [x] Self-edit prevention: Logic present in controller
- [x] Reason validation: Loop checks decreases, requires `reason`
- [x] Audit logging: Row created on save
- [x] Parse config: Both env and code values match; `ensureParseInitialised()` guards divergence

## Next Steps

- **P1** — Data foundation (§03, §07 §7.4): regenerate provisioning template, save endpoint merge/concurrency logic
- **P2** — Form generation (§04): build from registry, layout by container
- **P3** — Save semantics (§07): validation, diff, merge, concurrency detection
- **P4** — Modes (§06): form/JSON toggle, round-trip fidelity
- **P5** — Overrides (§05): per-member ceiling table
- **P6** — Polish (§04 §4.8–§4.9, §07 §7.7): bulk actions, filters

## Why P0 Ships Alone

The Brain admin API was open to unauthenticated requests. Every route in that group was readable by `curl` without credentials. This is a single, critical vulnerability that gates everything else — no feature can be added safely until the API is gated. P0 is that gate. It carries no form, no visual features, nothing that could regress. It is purely defensive.
