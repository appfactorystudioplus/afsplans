# 13 — Step 60 Findings & Execution Plan

Written after auditing the actual `apps/coordinator`, `apps/members`, `apps/auth`
code against `JoystickEngine_v2/plan/steps/60_afs_connect_platform.md` and
against specs 05/11/12 in this directory. Docs 11 and 12 already describe the
target state accurately and were written in an earlier pass of this session —
they are not being rewritten. This document is the delta: what the audit
confirmed, what it found that isn't in any plan document yet, and the build
order.

Scope decision carried over from the alloc-v2-parse-direct work: the Parse
server is out of scope. `AllocationGateway` (§60.4.4 / doc 11 §11.4) is
implemented against the direct-to-Parse client already built there.

---

## Confirmed accurate (read from the actual files)

| Claim | File | Verified |
|---|---|---|
| `SourceAppAfsConnectClient::SOURCE_APPS` is a bare 4-entry const, silently excluding every `www_*` source | `coordinator/app/Services/AfsConnect/SourceAppAfsConnectClient.php:17` | ✅ `['notes', 'flow', 'dream_graph', 'pdf_editor']` |
| `config('afs_connect.source_apps')` has 12 entries including all `www_*` | `coordinator/config/afs_connect.php` | ✅ — confirms the two lists already disagree today |
| `afsconnect_settings` has 12 boolean `source_*` columns + `disabled_website_domains` as `json` | `create_afsconnect_tables.php` | ✅ exact column list |
| Four independent config escape hatches | `config/afs_connect.php` | ✅ `global_enabled_override`, `dev_plan_entitled`, `require_checkpoint_global_for_access`, `min_membership_tier` |
| `AfsConnectEntitlementService` is the 4-layer gate today | `AfsConnectEntitlementService.php` | ✅ matches doc 05's description exactly |
| `secondary/register` has zero quota/allocation checks | `auth/app/Services/Checkpoint/SecondaryUserService.php:44` | ✅ only email/username/password validation |
| `afsconnect_links` has no FK to `afsconnect_index` and no orphan handling | schema + `AfsConnectIndexService::deleteItem` | ✅ — worse than "no defined behavior": deletion touches `afsconnect_links` **not at all** |
| No `afsconnect_source_status` table exists | migrations | ✅ — §60.6 is net-new |
| `afs_connect` / `secondary_users` already in `checkpoint_registry_v3.json`, positional tiers, no `index` field | confirmed during alloc-v2 work | ✅ |

---

## New findings — not in step 60 or docs 05/11/12

### A. A *third* hardcoded source list, in a different app, that silently drops write access to 8 of 12 sources

`apps/members/app/Http/Controllers/AfsConnectSettingsController.php` (the
proxy members' frontend actually calls — separate from coordinator's own
`Api/Internal/AfsConnectSettingsController.php`) has:

```php
private const MEMBER_SOURCE_KEYS = ['notes', 'dream_graph', 'flow', 'pdf_editor'];
```

and its `update()` validation only declares rules for those four
`sources.*` keys. Laravel's `$request->validate()` returns **only** fields
with rules, so even after `afsConnectSettings.ts` (§60.5.1) is fixed to
render and submit `www_*` toggles, this PHP layer silently strips them
before they ever reach coordinator — which, notably, **already accepts all
12** (`Api/Internal/AfsConnectSettingsController::update` has explicit rules
for every `www_*` key). The bug is entirely in this one proxy file, not in
coordinator.

**Consequence for the plan:** §60.5.1 as written only mentions the
TypeScript file. Fixing just that file ships a UI that renders 12 toggles
and silently fails to persist 8 of them. This controller must be fixed in
the same change.

### B. `afs_connect_sources_count` appears in doc 11 §11.5 but not in the registry or step 60

Doc 11's meter table lists `afs_connect_sources_count` (consumed on
settings save, released when a source flips off) without the `(if
adopted)` qualifier its siblings carry, and step 60 §60.4.5 doesn't mention
it at all. It is **not** in `checkpoint_registry_v3.json`'s 29 resources.
Treating it as adopted-but-forgotten would be a registry edit outside this
step's mandate (pricing sign-off, per §60.4.2). Treating it as a doc typo
means doc 11 needs a one-line fix.

**Resolution for this pass:** treat it as unadopted, same as the other
capacity meters. Not implementing a meter check for it. Flagging the doc
inconsistency rather than silently picking one reading.

### C. Coordinator's internal settings endpoint already does more than step 60 assumes

`Api/Internal/AfsConnectSettingsController::update()` already re-derives
`planIncludesAfsConnect` server-side before persisting `enabled: true` (not
trusting the caller) — this is the layer-2 gate already partially working.
The registry-driven rewrite (§60.5) replaces the *source* of that boolean
(`AllocationGateway::isEnabled('afs_connect')` instead of
`planIncludesAfsConnect()`), not the gating structure itself. This narrows
the change more than the plan's prose implies — good news, less to rewrite.

### D. `brain_membership_tier` is only trusted from `www` (Brain admin), never from a member's own request

`CoordinatorSettingsClient::fetchSettings` sends `brain_membership_tier` as
a query param, but coordinator's `isBrainAdminRequest()` only accepts it
when the signed sender app is `www`. For an ordinary member request via
`members`, coordinator re-resolves tier itself via `BrainMembershipLookup`
(local `brain_memberships` table or www Brain API fallback, 5-minute
cache). This is the correct trust boundary already in place — `tierIndexFor`
in `AllocationGateway` should use the same `BrainMembershipLookup`, not
re-derive tier from request input.

---

## Decisions carried from the earlier AskUserQuestion round

- **§60.4.3 secondary users: full enforcement as specified.** Implementing
  hard blocking directly. Per the plan's own text I still run the
  pre-enforcement count (below) so the number is on record, but the user
  has already decided not to gate shipping on it.
- **Capacity meters (§60.4.2): deferred**, per step 60 §60.10 — the access
  flag (`afs_connect`) ships; `afs_connect_index_rows` etc. do not.
- **Parse/Cloud Functions: out of scope**, per the alloc-v2-parse-direct
  scope decision. `AllocationGateway`'s Parse calls go through the
  direct-to-Parse TypeScript client's PHP equivalent — see Phase 3 below.

## Pre-enforcement count (secondary users)

Not yet run — needs DB access to `auth`'s secondary-users table, which I
have not queried yet. Recorded as an open item; will run before or
immediately after wiring the block, and report the number regardless of
outcome (the user's instruction was to enforce as specified, not to gate on
this, but the number should still be on record per the plan's own text).

---

## Execution order

Registry first — every other layer reads from it, so building top-down
means later phases don't get rewritten.

### Phase 1 — Source registry (§60.1)

1. `coordinator/config/afs_connect_sources.php` — the one array.
2. `SourceRegistry` service class — `keys()`, `keysByGroup()`,
   `keysByKind()`, `baseUrl()`.
3. Repoint `SourceAppAfsConnectClient::SOURCE_APPS` /
   `SourceAppAfsConnectClientRegistry` to derive from it.
4. Consistency test (§60.1.1).
5. `kind: hosted` support in the registry shape (used by step 61, not
   wired to anything yet — just the field existing).

### Phase 2 — Settings: JSON columns (§60.3)

6. Migration: add `sources_enabled` / `sources_allowed` JSON columns,
   **dual-read** — do not drop the 12 boolean columns yet.
7. Backfill: `sources_enabled[key] = source_<key>` for every existing row;
   `sources_allowed` computed from current tier + registry `min_tier_index`.
8. `AfsConnectSetting` model: add JSON-backed accessors alongside the
   existing boolean ones (dual-read window).

### Phase 3 — `AllocationGateway` (§60.4)

9. PHP `AllocationGateway` interface + a Parse-direct implementation, HTTP
   calls to the same Parse server the TypeScript client talks to (server
   URL / app id from the same config source, not re-derived).
10. `tierIndexFor()` via `BrainMembershipLookup` — reuse, not reinvent
    (finding D above).
11. Fail-safe-not-open on transport error, 60s per-member cache.

### Phase 4 — Entitlement rewrite (§60.5) + members fixes (§60.5.1)

12. `AfsConnectEntitlementService`: layer 2 reads
    `AllocationGateway::isEnabled($member, 'afs_connect')` instead of
    `planIncludesAfsConnect()`. Layers 4/5 added (allowed vs enabled).
    Collapse the four config escape hatches to
    `AFS_CONNECT_ENTITLEMENT_OVERRIDE`.
13. `GET /api/afs-connect/status` — expanded shape (tier, sources with
    allowed/enabled/upgrade_label).
14. `GET /api/afs-connect/sources` — registry projection for the frontend
    label map.
15. **`members/app/Http/Controllers/AfsConnectSettingsController.php`** —
    fix the finding-A validation whitelist to accept all registry keys.
16. `members/resources/js/settings/afsConnectSettings.ts` — render from
    `GET /sources`, three-state row rendering, tier-named upgrade copy.

### Phase 5 — Secondary users (§60.4.3)

17. Run the pre-enforcement count query.
18. Wire `AllocationGateway::check/consume` into
    `SecondaryUserService::register`, release on delete.

### Phase 6 — Staleness (§60.6)

19. `afsconnect_source_status` migration + model.
20. Write on every reconcile attempt (success or failure) from the
    aggregator.
21. Circuit breaker + PM "last synced" surface.

### Phase 7 — Link integrity (§60.7)

22. `orphaned_at` column on `afsconnect_links`.
23. `AfsConnectIndexService::deleteItem` orphans rather than ignores links.
24. Re-appearance clears `orphaned_at`.
25. Badge counts exclude orphaned; drawer shows greyed state.

### Phase 8 — Docs

26. Doc 11 §11.5: resolve finding B (drop or annotate
    `afs_connect_sources_count`).
27. `03-database-schema.md` / `04-api-contracts.md`: update once the
    schema/API described above actually exist, not before.

Each phase is independently functional — Phase 1 alone fixes the silent
`www_*` exclusion from reconcile even with nothing else built.

---

## What was actually built (Phases 1–5) and how it was verified

**Nothing has been committed or staged in any repo** — all three (`auth`,
`coordinator`, `members`) remain on `main` with working-tree-only changes,
per the standing instruction for this session.

### Phase 1 — Source registry ✅ built, tested against the real app

- `coordinator/config/afs_connect_sources.php` (new) — the one array.
- `SourceRegistry` service class.
- `SourceAppAfsConnectClient::sourceApps()` (was the `SOURCE_APPS` const)
  and its two call sites now derive from the registry.
- `tests/Unit/AfsConnect/SourceRegistryConsistencyTest.php` — 4 tests, 160
  assertions, run against the real booted app (not mocked): confirms the
  registry-derived provider list is byte-identical to the old hand-written
  const, and that clearing a provider's base URL is actually caught.

### Phase 2 — Settings JSON columns, dual-read ✅ built, tested with real data

- Migration adds `sources_enabled`/`sources_allowed`, backfills from the 12
  booleans + tier. Run twice against a seeded SQLite DB — once with no
  `brain_memberships` table (proved the fail-closed path), once with one
  (proved tier-based `sources_allowed` computes correctly at tiers 0/1/4).
- `AfsConnectSetting` model: `isSourceEnabled`/`setSourceEnabled`/
  `isSourceAllowed`/`recomputeSourcesAllowed`/`isSourceLive`, all dual-write.
- `tests/Unit/AfsConnect/AfsConnectSettingDualReadTest.php` — 6 tests, real
  Eloquent + RefreshDatabase, including "JSON wins over legacy column when
  both present" and "unknown key fails closed, not an exception."

### Phase 3 — AllocationGateway (coordinator) ✅ built, tested with Http::fake()

- `AllocationGateway` interface + `ParseAllocationGateway` — direct-to-Parse,
  app-id-only (no master key — see the class's own docblock on why),
  `ac_m__<resourceId>` atomic counter matching the TS client's contract
  exactly, 60s read cache for `check()`/`isEnabled()`, never for
  `consume()`/`release()`.
- `tests/Unit/AfsConnect/Allocation/ParseAllocationGatewayTest.php` — 9
  tests: tier-gate denies before touching Parse, limit-exceeded pre-check
  denies without a PUT, successful consume increments and reads back,
  transport failure denies a block resource, release clamps at zero,
  interface signature has no tier parameter.

### Phase 4 — Entitlement rewrite + members fixes ✅ built, tested

- `AfsConnectEntitlementService` rewritten: 5 layers, layer 1 (global) now
  unconditional — **a real pre-existing gap**: the old
  `require_checkpoint_global_for_access` config defaulted to `false`, which
  meant the "kill switch" did nothing unless separately opted into. Fixed by
  removing the opt-in, not by migrating it.
- Four config escape hatches → one `AFS_CONNECT_ENTITLEMENT_OVERRIDE`.
- `tests/Unit/AfsConnect/AfsConnectEntitlementServiceTest.php` — 9 tests
  against real DB rows: all 5 layers individually, the layer-4-vs-layer-5
  reason distinction, `sources` map reflecting live (allowed && enabled)
  status, and `dev_allow_all`'s scope (bypasses layer 2 only, never layer 1).
- `GET /api/afs-connect/sources` (new route) + expanded `/status` response
  (tier, per-source allowed/enabled/upgrade_label).
- **Finding A fixed**: `members/app/Http/Controllers/AfsConnectSettingsController.php`'s
  own 4-key validation whitelist (separate bug from anything in coordinator)
  — now generates rules from the full 12-key list, matching coordinator's
  own internal endpoint which already accepted all 12. Regression test
  written (`AfsConnectSettingsSourceKeysTest.php`) but **could not run** —
  this environment is missing `members/public/checkpoint/server/php/vendor/`
  entirely (confirmed pre-existing: the stock `ExampleTest.php` fails
  identically). Verified instead via `php -l` and a reflection probe
  confirming the corrected 12-key constant.
- `members/resources/js/settings/afsConnectSettings.ts` rewritten to render
  from the registry endpoint with three-state rows (on / off / disabled-with-
  upgrade-label + "Paused" copy for a downgraded-but-still-enabled source).
  Typechecked against the real project tsconfig; the "possibly null" errors
  present are confirmed pre-existing (diffed byte-for-byte against the
  original file's own typecheck output) — not introduced by this change.

### Phase 5 — Secondary users (auth) ✅ built, partially tested

- `auth`'s own `AllocationGateway`/`AllocationVerdict` (necessarily a
  separate copy — no shared PHP package across these repos, same reason the
  JS client is file-synced). Uses the Parse PHP SDK + master key (auth
  legitimately holds it; coordinator does not) rather than raw REST.
- **Two real bugs caught by reading the vendored SDK source before trusting
  the code**: `ParseObject::save()` takes one argument, not
  `(null, $useMasterKey)` as first written; `ParseObject::_encode()` returns
  a JSON **string**, not an array, so the original `?array` return type on
  `fetchRegistryObject()` would have type-errored at the first real call.
  Both fixed; `fetchRegistryObject()` now returns the `ParseObject` directly.
  A third bug (`\Log::` — rooted global reference, only resolves inside a
  booted Laravel app) was caught by the test suite itself failing with
  "Class Log not found" under plain PHPUnit, fixed with a proper `use`
  import matching this app's own existing convention.
- Wired into `SecondaryUserService::register()` (check before the INSERT,
  consume after) and `deleteUser()` (release). Controller returns 402, not
  400, for the quota-exceeded case specifically — distinguishing "upgrade
  your plan" from "fix your input," per step 60 §60.4.3.
- `tests/Unit/Checkpoint/AllocationGatewayRegistryTest.php` — 4 tests
  verifying registry-driven limits against the REAL
  `checkpoint_registry_v3.json` in this app (afs_connect disabled at tier 0,
  secondary_users' 3/10/50/unlimited ladder, unknown-resource fails closed,
  tier clamping). **Not verified**: `check()`/`consume()`/`release()`
  end-to-end, and the full `SecondaryUserService` constructor chain —
  both require a live MySQL connection this environment doesn't have
  (`MemberLookupService`'s constructor eagerly opens one; confirmed via a
  direct `PDOException` when attempting to construct it through Tinker).
- **Pre-enforcement count**: not run — same MySQL constraint. The user's
  instruction was to enforce as specified regardless; this is recorded per
  the plan's own text that the count should exist, not as a blocker.

### Not done in this pass

- **Phase 6 (staleness, §60.6)** and **Phase 7 (link integrity, §60.7)** —
  not started. `AfsConnectIndexService::deleteItem()` still does not touch
  `afsconnect_links` at all (confirmed during the audit — worse than "no
  defined behavior," it's total silence).
- **Doc 11's `afs_connect_sources_count` inconsistency** (finding B) — not
  resolved either way.
- **The aggregator's www-module gap** — `AfsConnectAggregatorService`'s Brain
  pull (`wwwBrain->changes()`) is unconditional; it does not filter by any
  individual `www_*` source's live status the way the 4 creative sources'
  client loop does. This predates this step and was not fixed — flagged
  during the audit, out of scope for what was actually touched.
- **`.env.prod` Parse app-id mismatch** (alloc-v2-parse-direct finding,
  restated here because it also blocks this step in production): unresolved,
  explicitly out of scope per the Parse-server-is-off-limits decision.
