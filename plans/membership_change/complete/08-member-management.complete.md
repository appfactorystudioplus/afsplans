# 08 — Member management: completion record

## Status

Done, within a scope this document states explicitly. **MM1's second half** (`level`),
**MM2/MM3/MM5/MM7** (tier vs. status split, audit trail, stale-write guard), and **MM8/MM9**
(paged, searchable list) are fixed, tested, and wired into the Brain console's UI. **MM4 and
everything downstream of the retention engine (§8.5 P2/P3) are deliberately not built** — the
engine this document's own README calls "planning only" elsewhere in this folder does not
exist as a caller anywhere in this estate, and building a second, console-only version of it
would be exactly the mistake §8.4 D6 rules out. See "What's left, explicitly" below.

## What the plan doc got right, and what had already moved

Re-reading the live code before writing anything (not trusting the plan doc's own description)
found the ground had shifted since 08 was written:

- **MM1's first half — the unauthenticated route — was already fixed.** A prior session
  ([07](../07-signup-flow.md) §S2) moved the whole memberships admin route group, including
  `PUT /{ref}`, behind `ApiAuthMiddleware`. `MembershipUpdateIgnoresIdentityFieldsTest`
  already had a passing test proving this (`test_the_endpoint_refuses_an_unauthenticated_request`).
  **MM1's second half — `level` still in the write-allow-list — was not fixed**, and is what
  this pass actually closes.
- **A live bug the plan document never named**: `adminStore()` (built during the 07 pass,
  after this document was written) took `level` directly from its request body too —
  `'level' => isset($data['level']) ? (int) $data['level'] : 0`. Any caller with a valid
  `UserToken` — not necessarily an operator; see OQ-A below — could mint a platform superuser
  membership for themselves through the authenticated admin-create endpoint. Closed the same
  way as `update()`: the field is gone from the write path entirely, not validated or capped.

## What shipped

### `level` is not writable anywhere on this controller (D2, closes MM1, MM10)

Removed from `update()`'s allow-list and from `adminStore()`'s `Membership::create()` call.
Every new membership gets the column's own default (0); every existing one can no longer have
it changed through this API. The "Level" `<input>` was removed from both the create form
(`brain_memberships_create_content.html`) and the member-detail form
(`brain_memberships_detail_content.html`), not just ignored server-side — a field that visibly
does nothing is worse than no field (MM10's own reasoning).

### Tier and status are separate operations (D3, D4, D5 — closes MM2, MM3, MM5)

`update()` used to write `account_status`, `account_close_date`, `membership`, and `level`
from one form with one Save button. It is now three:

- **`PUT /{ref}`** — `firstname`/`lastname` only. What was always inert stays here.
- **`PUT /{ref}/status`** — `account_status` (validated `in:1,2,3`) and `account_close_date`.
  Never touches `membership` — suspending a member cannot be used as a billing action or the
  reverse (MM5).
- **`PUT /{ref}/tier`** — `membership` (validated against the known tier ids) plus a
  **mandatory** `reason` (min 3 chars). Rejects a no-op write (same tier) with 422 rather than
  recording a meaningless audit row. Sets `tier_source = 'manual'` ([07](../07-signup-flow.md)
  §S20) — this is this document's concrete answer to **§8.7 OQ-B**: an operator MAY assert a
  tier no subscription backs, but it is tagged so billing enforcement and reconciliation can
  tell an operator override from a paid tier.

Each of the three is its own card in the Brain member-detail view now
(`brain_memberships_detail_content.html`), each with its own Save button, matching D4's "separate
controls with separate confirmations."

### The audit trail reuses `brain_membership_events`, not a new table (D5 — closes MM3)

The obvious move was a new `MembershipAudit`-style table, mirroring `members_brain`'s
`BrainAllocAudit`. Reading `w/www/app/Models/BrainMembershipEvent.php` first found something
better already in place: `brain_membership_events` is DunningService's own audit log for
billing-driven tier changes, already carrying `source` (`scheduler`/`webhook`/`admin` —
`admin` was already a documented value with zero writers), and event constants
`EVENT_MEMBERSHIP_DOWNGRADED`/`EVENT_MEMBERSHIP_RESTORED`. It had no column for WHO or WHY,
because nothing but the scheduler had ever written to it.

A migration
(`2026_09_23_000003_add_actor_and_reason_to_brain_membership_events.php`) adds
`actor_user_id`, `actor_username`, `reason` — nullable, so DunningService's existing writes
are untouched. Two new event constants were added to the model:
`EVENT_ADMIN_TIER_CHANGE` and `EVENT_ACCOUNT_STATUS_CHANGED`. `updateTier()` and
`updateStatus()` both write to this same table with `source = 'admin'`.

**Why this matters beyond "one less table":** acceptance criterion **A5** requires an
operator-triggered tier change and a billing-triggered one to be provably the same kind of
event. Writing to the same table with the same vocabulary makes that true by construction —
a `SELECT * FROM brain_membership_events WHERE member_reference_num = ?` is the whole
timeline, admin and automated rows together, without joining two audit systems that could
silently drift apart. [09-billing-tier-change-hook.md](../09-billing-tier-change-hook.md),
whenever it is built, writes to the exact same table this pass extended.

### Stale-write guard (MM7)

All three write endpoints accept an optional `expected_updated_at`. When present, it is
compared (via `strtotime()`, matching `BrainAllocationController`'s own concurrency-check
convention in `members_brain`) against the row's actual `updated_at`; a mismatch returns 409
with `{code: 'STALE_RECORD'}` and applies nothing. The client (three save handlers in
`brain_membership.ts`) captures `member.updated_at` from the list-load snapshot once per page
view and refreshes it from each successful save's response, so a second save in the same tab
after the first succeeds is checked against the new value, not the stale one.

### The list is paged and searchable (D8 — closes MM8, MM9)

`MembershipController::index()` now accepts `search` (matched against `email`,
`reference_num`, `username`, `direct_payment`) and `page`/`per_page` (capped at 200), returning
a `pagination` block alongside `memberships`. `brain_membership.ts`'s `loadMembershipList()`
forwards a debounced search box and renders Prev/Next controls reading that block. **Domain
lookup (MM9's other named case) is NOT included** — it requires a join into the per-member
domain table (`initMembershipDomainsView`'s own subject), a different resource, out of scope
for this pass.

## What's left, explicitly

- **§8.7 OQ-A is not resolved, and this pass does not attempt to.** Reading
  `ApiAuthMiddleware` directly confirms it proves only "a valid `UserToken` (or
  `SponsorUser` session) was presented" — nothing about role, staff status, or operator
  intent. This is not a gap this document introduced: every other admin section in
  `routes/portal.php` (Money, Documents, Forms, Sponsorships) is gated by the exact same
  bare `ApiAuthMiddleware`, and `PortalLevelNinetyNineMiddleware` — the one thing that
  ever checked a per-user level — was deliberately disabled 2026-08-29 for being the wrong
  mechanism (`level` identifies an application, not a person; see that middleware's own
  comment and `routes/portal.php` around the disabled `api/v1/brain` groups). Inventing a
  one-off role model for just this document's three routes would add a second,
  inconsistent authorisation concept to a codebase that already has this same open
  question everywhere else. **D1 in the plan doc ("the admin API is authenticated and
  operator-scoped") is therefore only half true**: authenticated, yes; operator-scoped,
  no — and it wasn't before this pass either. Whoever answers OQ-A answers it once, for
  every admin section in this file, not per-document.
- **MM4 is still true, on purpose.** `updateTier()` writes the column and the audit row —
  nothing more. `w/members/app/Checkpoint/RetentionEngine.php` has a `preview()` method
  and it has zero callers anywhere in `w/members`; its own `cascadeRetirement()` isn't even
  wired into `preview()` yet (`cascade` is hardcoded to `[]`). That is
  [02-architecture.md](../02-architecture.md)'s and
  [09-billing-tier-change-hook.md](../09-billing-tier-change-hook.md)'s undone work. §8.4 D6
  ("the console never computes entitlement itself... a second implementation is a second
  answer") rules out building a parallel, console-only enforcement path here to paper over
  that — so a downgrade through this console does exactly what a downgrade through
  `DirectPaymentSubscriptionUpdateMembership` already does: changes the integer, retires
  nothing. **§8.5 P2 and P3, and acceptance criteria A4/A5/A6/A9/A12, stay open** until the
  engine exists to render.
- **§8.3.2's Holdings/Retirement/Identity panels are not built**, for the same reason —
  they render output from the same unbuilt engine.
- **§8.3.3 (Allocations tab) and §8.3.4's broader operator actions** (resend mail, re-run a
  failed Checkpoint/Parse provisioning, grant a ceiling override, extend a grace window,
  force a reconcile) are unbuilt. They are either explicitly owned by
  [members_brain](../../members_brain/README.md) (§8.3.3) or require live Checkpoint/Parse
  calls this sqlite-only verification environment cannot exercise honestly (§8.3.4) — the
  same class of deliberate deviation [07](../07-signup-flow.md)'s completion doc already
  made for its own two-step registration API.
- **§8.7 OQ-C, OQ-D, OQ-E, OQ-F** are genuine policy/product questions this pass does not
  answer: whether an operator downgrade earns grace, whether a purge can be reversed, what
  happens to the admin create-tab now that [07](../07-signup-flow.md) already split
  `adminStore()` out (OQ-E is arguably answered by that split already having happened —
  worth a follow-up read, not re-litigated here), and whether this console belongs in the
  Brain at all.
- **MM11** (`account_close_date` writable with no modelled `account_status = 3` lifecycle)
  is unchanged — still writable through `updateStatus()`, still not backed by any estate-wide
  implementation. Out of scope; [../handoff.md](../../handoff.md) OQ-E owns it.

## Files changed

**`w/www`** (Laravel):

| File | Change |
|---|---|
| `database/migrations/2026_09_23_000003_add_actor_and_reason_to_brain_membership_events.php` | New. `actor_user_id`, `actor_username`, `reason` on `brain_membership_events` |
| `app/Models/BrainMembershipEvent.php` | New fillable columns; new `EVENT_ADMIN_TIER_CHANGE`/`EVENT_ACCOUNT_STATUS_CHANGED` constants |
| `app/Http/Controllers/Portal/Admin/MembershipController.php` | `index()` paged+searchable; `update()` trimmed to firstname/lastname; new `updateStatus()`, `updateTier()`, `staleResponse()`, `logMembershipEvent()`; `adminStore()` no longer reads `level` from the request |
| `routes/portal.php` | New `PUT /{ref}/status`, `PUT /{ref}/tier`; route-list comment updated |
| `resources/js/portal/admin/controllers/brain/brain_membership.ts` | `initMembershipDetailView()` rewritten (three save handlers, `expected_updated_at`); `loadMembershipList()` paged+searchable, Level column dropped; `submitMembershipForm()` no longer sends `level` |
| `resources/js/portal/admin/html/views/html/modules/brain/brain_memberships_detail_content.html` | Level input removed; Membership+Status split into their own cards (Change Plan / Account Status) |
| `resources/js/portal/admin/html/views/html/modules/brain/brain_memberships_create_content.html` | Level input removed |
| `resources/js/portal/admin/html/views/html/modules/brain/brain_memberships_content.html` | Search input added to the list card header |
| `resources/js/afs/memberships/index.ts` | `AdminMembershipCreationPayload` no longer has a `level` field |
| `tests/Feature/Membership/MembershipTierAndStatusTest.php` | New — 11 tests covering `updateTier()`, `updateStatus()`, the stale-write guard, and `adminStore()`'s `level` fix |
| `tests/Feature/Membership/MembershipUpdateIgnoresIdentityFieldsTest.php` | Updated: `membership` is now also asserted unchanged through `PUT /{ref}` (D4) |

No other repo under `w/` was touched for this step.

## Verification performed

- `php artisan test --filter=Membership` (from `w/www`): **30 passed**, 0 failed (101
  assertions) — includes all 11 new tests plus every pre-existing membership test.
- `php artisan test` (full `w/www` suite): **559 passed, 11 failed** — the same 11
  pre-existing, unrelated failures already present before this pass (`ModulePagePathsTest`,
  `AppointmentCommunicationServiceTest`, `EcommerceReceiptServiceTest`,
  `EventCommunicationServiceTest`, `BuilderModuleRefsBackfillTest`,
  `FormsExternalSdkStaticTest` — domain-config and unrelated-module issues, none touching
  memberships).
- `npx tsc --noEmit` (from `w/www`): clean, no errors.
- `php -l` on every changed PHP file: clean.
- Not run (environment limitation, per `DEVELOPMENT_MODE.md` = vagrant): the migration
  against a real MySQL database, and the new UI in a browser — this environment's tools
  cannot reach the vagrant lab's live services. The migration only adds three nullable
  columns to an existing table and has no data to backfill, which is the lowest-risk shape
  of schema change available.

## Lab verification — 2026-09-24

`2026_09_23_000003_add_actor_and_reason_to_brain_membership_events` was applied to the vagrant
lab (`php artisan migrate --force` in `/vagrant/afs-eco/w/www`) and ran clean, resolving the
"migration not run against a real MySQL database" item in Verification above. The new UI has
still **not** been exercised in a browser.
