# 08 — Member management: the Allocations tab leftover — Complete

## Status

Done. [08-member-management.md](../08-member-management.md) §8.3.3's two requirements from
`members_brain` — the generated form replaces the JSON textarea, and per-member ceiling
overrides are visible here — are now both genuinely true, verified against the current code
rather than trusted from `members_brain`'s own completion docs. Getting there surfaced and
fixed one live authorization gap and two functional bugs, none of which any prior completion
doc's "Verified"/"Not verified" split had caught, because none of `BrainAllocOverrideController`
or the allocation-override data path had ever had a test written against it before this pass.

## Summary

**The authorization gap (found first, fixed before anything else — user directive).**
`portal.level99` was disabled estate-wide on 2026-08-29 (`plans/TODOS.md`, "Brain admin API:
portal.level99 disabled") because it broke two internal HTTP callers and blocked sibling apps
that can't hold a level-99 user row. That left `.../member/{ref}/allocation` (GET/POST) and
`.../member/{ref}/overrides` (GET/POST/DELETE) gated only by `portal.admin.auth` — "is this
any logged-in portal user," not "is this staff." Reading `BrainAllocOverrideController`
directly confirmed the only authorization check left was "not your own record": any
authenticated member could grant, revoke, or overwrite *any other member's* ceiling overrides
and allocation registry. Verified no other caller depends on this before re-enabling
`portal.level99` on exactly these 5 routes (not the other 34 in the first `api/v1/brain`
group, which is a separate, still-open question — see "What's left").

**Two functional bugs, found by writing this route's first-ever test.**
`BrainAllocOverrideController::store()` accessed `$validated['scope_value']` and
`$validated['expires_at']` directly. Both are `nullable` in the validation rules, and
Laravel's `validate()` omits an absent key from its return array rather than filling it with
`null`. `brain_membership.ts`'s own override modal sends `scope_value: undefined` for the
`member` scope type (JSON drops it) and never sets `expires_at` at all for the "never expires"
option — meaning the plain per-member override (no domain/project scope, no expiry), the
simplest and most likely first thing an operator would try, has thrown `Undefined array key`
and 500'd since this endpoint shipped. Fixed with null-coalescing on both fields.

**The overrides-invisible bug.** `initMembershipAllocationsView`'s `member` parameter is
whatever the *list* view (`getMembers()`) loaded, passed tab-to-tab as a `CustomEvent` param
(the same stale-snapshot shape MM7 already names). `getMembers()` never attaches
`alloc_overrides` — only the singular `getMember()`/`getMemberByEmail()` do
(`members_brain/05-limit-overrides.md` §5.4). So `hydrateRowsWithOverrides(rows,
member.alloc_overrides)` read `undefined` every time a member was reached through the normal
list → detail → Allocations tab path, its own early-return (`if (!overrides ...) return;`)
making this fail silently rather than visibly. §8.3.3's second requirement was never actually
true in the one page state this screen produces. Fixed by fetching the member fresh (with
overrides) alongside the allocation rows, falling back to the stale snapshot only if that
fetch itself fails.

## Files changed

| File | Repo | Change |
|---|---|---|
| `routes/portal.php` | www | `portal.level99` re-added to the allocation+overrides route group (only) |
| `app/Http/Controllers/Brain/BrainAllocOverrideController.php` | www | `store()`: null-coalesce `scope_value` and `expires_at` instead of direct array access |
| `resources/js/portal/admin/controllers/brain/brain_membership.ts` | www | New `fetchMemberWithOverrides()`; `initMembershipAllocationsView()` fetches fresh member data alongside rows and passes it (not the stale tab-navigation snapshot) to `renderAllocationForm()` |
| `tests/Feature/Brain/BrainAllocationAuthGateTest.php` | www | New. 8 tests |
| `plans/TODOS.md` | — | Updated the 2026-08-29 entry: narrowed to the remaining 34 routes, points here for detail |

No new database table, no new signing mechanism.

## Design decisions not fully spelled out in the plan text

- **Scoped the re-enabled gate to 5 routes, not all 39.** The original disable was
  estate-wide because *some* of those 39 routes have legitimate non-staff callers (sibling
  apps, per the TODO). Re-adding the gate everywhere would repeat exactly the mistake already
  made and reverted once. Verified specifically for these 5 (no reference anywhere in
  members/coordinator/auth's `resources/js`, and the two callers that broke last time were
  already independently fixed) before touching anything — this does not resolve OQ-A's
  broader question for the other 34, and isn't meant to.
- **The stale-member-overrides fix fetches a second small payload rather than widening
  `getMembers()`.** Adding `alloc_overrides` to the list endpoint would mean an N-override
  lookup on every list load, for a field only the Allocations tab uses. A dedicated fetch,
  parallel with the rows fetch already happening, costs one more small request exactly when
  it's needed and nowhere else — the same shape `fetchAllocationRows()` itself already uses
  rather than trusting the tab-navigation snapshot for rows.
- **The member re-fetch degrades to the stale snapshot on failure, not to a blocked
  screen.** Overrides are supplementary to what this screen exists to show (the allocation
  rows); a failed override-refresh should not prevent an operator from seeing and editing
  rows at all.

## Verification performed

- `php -l` on every changed PHP file — clean.
- New test suite (`BrainAllocationAuthGateTest`, 8 tests) — all pass: unauthenticated is 401
  on both route groups, authenticated-non-staff is 404 (never 403) on both, a real level-99
  principal reaches both controllers, a level-99 override grant actually persists a row (this
  is what caught both `Undefined array key` bugs — the test failed with exactly that error
  until each field was fixed), and a non-staff member's override-grant attempt is rejected
  with zero rows written.
- `www`'s full test suite (`php artisan test`, no path filter) — 573 passed, 11 failed. Same
  11 pre-existing, unrelated `Appointments`/`Builder`/`Ecommerce`/`Events`/`Forms`
  environment-domain failures plans 09 and 10 both already found — up from 565 passed before
  this pass (the 8 new tests), no regressions.
- `npx tsc --noEmit` against `www`'s own `tsconfig.json` (unlike `members`, this one runs
  without needing a workaround) — 0 errors, project-wide.
- `npm run build` (Vite) — succeeded, exit 0, `public/build` regenerated.
- **Not** exercised against the live vagrant-lab database — this environment's MySQL was
  unreachable from here (`SQLSTATE[HY000] [2002] connection refused` on
  `php artisan migrate:status`) at the time of this pass, so whether
  `2026_08_28_000001_create_brain_alloc_overrides_table.php` has actually been run against
  the real shared database remains genuinely unverified, exactly as `members_brain/05`'s own
  completion doc already flagged. The user (or a session with DB access) needs to run
  `php artisan migrate --force` in `www` before any of this is live.
- **Not** exercised in a real browser — same limitation as plan 10's own completion doc: the
  server-side contract and the client code are each proven independently (feature tests for
  the former, type-checking and a clean build for the latter), not by an actual click-through
  with a real operator session.

## What's left, explicitly

- **OQ-A's broader question is still open.** The other 34 routes in the first `api/v1/brain`
  group (`getMember`, `getMembers`, DNS server/zone management, HTTP server/tenant
  management) remain gated by `portal.admin.auth` alone — any authenticated portal user, not
  specifically staff, can read every member's profile and manage DNS/HTTP server
  infrastructure through them. This was true before this pass and is unchanged by it,
  deliberately (see "Design decisions" above). `plans/TODOS.md`'s entry has the full
  candidate direction (`afs_connect.service` for machine callers, `portal.admin.auth` +
  a real capability check for staff) whenever that gets picked up.
- **The migration's real-database status is unverified**, as above — first thing to confirm
  once there's DB access.
- **08-member-management.md's own remaining scope (MM4, §8.5 P2/P3) is unchanged** — the
  retention engine still has no caller from this console, and that was never this pass's
  scope; `../10-preview-endpoint.md`'s completion doc covers the engine's own preview() side
  separately.
- A live click-through in the vagrant lab, once the DB is reachable, is the one thing that
  would make this fully proven rather than tested-in-isolation.
