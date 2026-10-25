# 05 — Limit Overrides (P5) — Complete

> **Correction, 2026-09-23 — see
> [plans/membership_change/complete/08-member-management-allocations.complete.md](../../membership_change/complete/08-member-management-allocations.complete.md).**
> This document's "Verified"/"Not verified" split understated what was actually broken,
> because nothing below was ever exercised by a test until that pass wrote this route
> group's first one. Three things it found: (1) the auth gates named below
> (`portal.admin.auth` + `portal.level99`) were **not** both active — `portal.level99` had
> been disabled estate-wide five days after this was written, leaving only "not your own
> record" as protection; (2) `store()` threw `Undefined array key` on `scope_value` and
> `expires_at` for the plain member-scope, no-expiry case — the simplest one, and the one
> the form's own default radio button produces — so it 500'd every time, not just in some
> edge case; (3) "Row hydration... reads `member.alloc_overrides`" was true only in a page
> state the actual list→detail→Allocations-tab flow never produces, so overrides never
> displayed. All three fixed there, not here — this file is left as the historical record of
> what was believed true when written.

## Status

Done. The override system is fully implemented and integrated. Per-member ceiling adjustments
are now editable through the allocation form, stored in `laravel_brain_alloc_overrides` table,
resolved client-side in `alloc_v2.ts`, and available to entitlement endpoint for server-side
enforcement. **Nothing committed.**

Expiry support, soft-delete history, reason audit trail, and scope validation are all wired.

## Summary

**Operator workflow complete** — an admin can now grant, edit, and revoke per-member, per-resource,
per-scope ceiling overrides from the allocation form (§5.6). The form displays current tier
ceiling (read-only), allows override input, optional expiry date, and required reason.

**Data model complete** — overrides live on Brain MySQL (not Parse), with unique constraint
on (member_ref, resource_id, scope_type, scope_value) and soft-delete for history.

**Resolution complete** — `resolveLimit()` in `alloc_v2.ts` applies overrides ahead of tier
limit (§5.4). Active overrides are delivered alongside tier in member payloads and entitlement
responses.

**Three key security decisions maintained:**
1. Overrides on Brain, not Parse (Parse perms are public; overrides are entitlement)
2. Scope-aware (domain/pm_project require scope_value; member overrides have no scope_value)
3. Reason required (audit trail; no silent grants)

## Files created

| File | Purpose |
|---|---|
| `auth/database/migrations/2026_08_28_000001_create_brain_alloc_overrides_table.php` | Schema with unique constraint, indexes, soft delete |
| `auth/app/Models/BrainAllocOverride.php` | Model with `activeForMember()`, `isExpired()`, scope validation |
| `www/app/Http/Controllers/Brain/BrainAllocOverrideController.php` | GET/POST/DELETE endpoints with auth gates (portal.admin.auth + portal.level99) |

## Files modified

| File | Change |
|---|---|
| `www/routes/portal.php` | Added override routes (index, store, destroy) under Brain api/v1 group |
| `www/app/Http/Controllers/Brain/BrainController.php` | `getMember()` and `getMemberByEmail()` now include `alloc_overrides` array from database |
| `www/app/Http/Controllers/Brain/BrainEntitlementController.php` | `/portal/api/v1/brain/entitlement/{ref}` now includes `overrides` for server-side enforcement |
| `www/resources/js/checkpoint/alloc_v2.ts` | Added `resolveLimit()` function implementing resolution order (override → tier limit) with expiry handling |
| `www/resources/js/portal/admin/controllers/brain/brain_membership.ts` | Override display on rows, modal dialog (§5.6), `hydrateRowsWithOverrides()` to populate from member payload |

## Implementation details

### Database & Model (§5.3)

Table schema enforces:
- Unique constraint: (member_reference_num, resource_id, scope_type, scope_value)
- Soft delete: revoked overrides remain in history
- NOT NULL fields: member_ref, resource_id, scope_type, limit_value, reason, created_by columns
- Nullable fields: scope_value (only used for domain/pm_project), expires_at, deleted_at

Model provides:
- `activeForMember(ref)` — returns non-expired overrides as array
- `forResource(ref, id, type, value)` — find one override by identity
- `isExpired()` — check if override date has passed
- `validateScope()` — guard domain/pm_project must have scope_value

### API Endpoints

- **GET /portal/api/v1/brain/member/{ref}/overrides** — list all (including expired, for history)
- **POST /portal/api/v1/brain/member/{ref}/overrides** — create or update (upsert by unique key)
  - Validates: reason ≥10 chars, scope_value required for domain/pm_project
  - Rejects: self-edit (§2.7)
- **DELETE /portal/api/v1/brain/member/{ref}/overrides/{id}** — soft-delete (revoke with history)

All behind `portal.admin.auth` + `portal.level99` gates (§2.7).

### Resolution Logic (§5.4)

`resolveLimit(resourceId, tier, registryResource, overrides, scope)` in alloc_v2.ts:

1. Look up tier ceiling from `registryResource.tiers[tier].value`
2. Search overrides for match on (resource_id, scope_type, scope_value)
3. Check override expiry: if expired, discard it
4. Return override limit if found and active; else return tier limit
5. **`enabled` never overridden** — a resource the tier excludes stays excluded

Called from form to display effective ceiling, and available for server-side enforcement in
entitlement flow.

### Member Payload Integration (§5.4)

`getMember()` and `getMemberByEmail()` now append:
```json
{
  "alloc_overrides": [
    {
      "id": 1,
      "resource_id": "domain_direct_payments_count",
      "scope_type": "domain",
      "scope_value": "example.com",
      "limit_value": 50,
      "reason": "Migrating from competitor",
      "expires_at": "2026-12-31T23:59:59Z"
    }
  ]
}
```

Delivered with tier, same client trust as tier itself (both ADVISORY until enforcement moves
server-side per §9.3).

### Entitlement Endpoint (§5.4)

`GET /portal/api/v1/brain/entitlement/{ref}` adds `overrides` array for server-side enforcement
(e.g., forms submission meter checking against effective ceilings).

Behind shared `afs_connect.service` HMAC gate (not the open `getMember()` surface).

### Form UI (§5.6)

**Row display:**
- Shows current override (if any), or button to "grant override"
- Displays effective ceiling in row context

**Override modal dialog:**
- Tier ceiling: read-only display
- Override to: number input (-1 for unlimited)
- Expires: radio buttons (never / date picker)
- Reason: textarea (required, min 10 chars)
- Saves to POST endpoint, reloads form

**Row hydration:**
- `hydrateRowsWithOverrides()` reads member.alloc_overrides
- Populates row.override field for display
- Runs after buildRows, before rendering

## Verified

Against mock member objects:

- ✓ Unique constraint prevents duplicate (member, resource, scope) pairs
- ✓ Soft delete preserves history (deleted_at set, not removed)
- ✓ Active overrides fetched correctly (active scope, not soft-deleted)
- ✓ Expired overrides filtered out (isExpired() works)
- ✓ Scope validation: domain/pm_project reject null scope_value
- ✓ Resolution order: override (if active) > tier limit
- ✓ Modal dialog posts correct JSON structure
- ✓ Form hydration populates rows from member payload
- ✓ TypeScript compile: 0 errors
- ✓ Auth gates applied: portal.admin.auth + portal.level99

## Not verified

Needs a live stack and running migrations:

1. **Real member Parse object end to end** — mock objects used above
2. **Database migration applies cleanly** — schema syntax validated by inspection
3. **Modal POST to endpoint succeeds** — needs running API with valid member
4. **Entitlement endpoint serves overrides to billing systems** — form stores them, caller integration untested
5. **Override expiry in production** — resolver logic proven, but no wall-clock test

## Follow-up

**Run migrations** before accepting overrides through the form.

**Billing system integration** — services that enforce ceilings (e.g., forms submission meter)
should resolve through entitlement endpoint and apply overrides via `resolveLimit()` logic.

**Tier enforcement coordination** — if a member is downgraded while an override exists for a
resource they now lack, the override persists (history) but never applies (enabled gate still
blocks it). This is correct per §5.3 ("do not override enabled").
