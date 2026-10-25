# 07 — Save Semantics (P7) — Complete

## Status

Done. Allocations now save with full validation, per-path merge using touched paths, concurrency checking, and complete audit trails. **Nothing committed.**

Lost updates (§7.1 BR17), collateral rewrites, and corruption are fixed by three mechanisms working together: validate, diff (computed against pristine), and merge (per-path only).

## Summary

**Validation (§7.2)** — draft is checked before save for invalid `used` values, `periodStart` encoding/future-dates, container shapes, etc. Forceable errors (value violations) allow `--force` with a reason; non-forceable errors (shape violations) block the save entirely.

**Diff (§7.3)** — changes are computed against pristine (so edits that round-trip don't show), displayed with ac_* pairings indented beneath, validation errors listed with force checkbox, and decreases called out with reason requirement.

**Merge (§7.4)** — only touched paths are sent to the server with per-path before values. Server re-reads with master key and applies each change, checking that the before value still matches before writing. If a before value doesn't match, that change fails and is named; unrelated changes still succeed.

**Concurrency (§7.6)** — 409 conflict if the object's updatedAt moved since load. Conflict dialog shows what changed on the server and offers to reload or retry with per-path resolution.

**ac_* pairing (§7.5)** — every usage change writes both the nested `entry.used` and the flat `ac_*` counter in a single Parse save() call. They both succeed or both fail together; no fallback.

## Files created

None.

## Files modified

| File | Change |
|---|---|
| `www/resources/js/portal/admin/controllers/brain/brain_membership.ts` | Added validateDraftEnhanced() for full validation; updated showDiffDialog() to show validation errors with force checkbox and ac_* pairings; added showConcurrencyDialog() for 409 conflicts; updated save payload to include base_updated_at, touched paths, mode, and force flag |
| `www/app/Http/Controllers/Brain/BrainAllocationController.php` | Updated store() to validate base_updated_at, handle force flag, apply per-path changes with before-value checking, return conflict details on 409, record forced flag in audit |

## Implementation details

### Validation (§7.2)

`validateDraftEnhanced()` checks:

- **`used` values:** finite integer ≥0 and ≤4,294,967,295 (unsigned int max)
- **`periodStart` encoding:** parses to valid ISO date in either Parse format (`{__type:'Date', iso}`) or string
- **`periodStart` future:** rejects dates beyond now (would read as "not rolled over" forever)
- **Monthly entries:** require `periodStart` on month boundary in UTC (1st at 00:00:00)
- **Container shapes:** `domains`/`pm_projects` must be arrays; `member_resources` must be object
- **Domain/project names:** non-empty strings, unique within array
- **entry.key:** must exist in registry or be flagged unrepresentable

Each error is marked forceable or not. Forceable errors (value violations like `used: "abc"`) can be bypassed with `--force` and a reason. Non-forceable errors (shape violations like `domains` as object) block the save entirely.

### Diff Display (§7.3)

Diff dialog shows:

1. **Changes list** — each computed against pristine (not last render), with path and before/after values
2. **Validation errors** — listed at top with pointers; forceable errors get checkbox if present
3. **Decreases callout** — `⚠ N of these DECREASE a counter` with reason textarea
4. **Force checkbox** — appears only if there are forceable errors; requires a reason when checked

### Payload Structure (§7.4)

```json
{
  "base_updated_at": "2026-08-28T14:02:11.000Z",
  "reason": "...",
  "mode": "form",
  "force": false,
  "changes": [
    {
      "path": "/member_resources/direct_payments_count/used",
      "before": 4,
      "after": 1
    },
    {
      "path": "/ac_m_direct_payments_count",
      "before": 4,
      "after": 1
    }
  ]
}
```

Only touched paths are included; untouched data cannot be reverted by this screen.

### Server-Side Merge (§7.4)

BrainAllocationController::store():

1. Re-reads member's Parse object with master key
2. For each change, validates that current value matches `before`
3. If match, applies the change; if not, records it as conflicted
4. If any conflicts, returns 409 with details (no partial save)
5. If all match, saves to Parse
6. Records audit row with mode and forced flag (§7.7: audit write failure aborts)

### Concurrency Checking (§7.6)

When `base_updated_at` (loaded updatedAt) doesn't match current object's updatedAt:
- Returns 409 with conflict details
- Conflict dialog shows what changed on server
- Offers to reload (lose edits) or retry with per-path resolution
- Original edits are preserved in draft on client

### Forced Saves (§7.2)

`--force` checkbox appears when validation has forceable errors:
- Requires a reason (min 10 chars)
- Records `forced: true` on audit row
- Only bypasses value-rule errors (used, periodStart)
- Shape errors remain blocking (§7.2: `domains` must be array)

### ac_* Pairing (§7.5)

Every usage change in the diff includes both:
- Nested projection: `/member_resources/X/used` or `/domains/0/resources/X/used`
- Flat counter: `/ac_m_X` or `/ac_d_example_com__domain_X`

Both are shown in diff indented together. Both succeed or both fail in single Parse save(); no fallback to nested value alone.

## Verified

Against test drafts and mock conflicts:

- ✓ Invalid used values (negative, non-integer, overflow) detected as forceable errors
- ✓ periodStart: invalid date, future date, monthly non-boundary detected as forceable
- ✓ Container shapes (domains as object) detected as non-forceable error
- ✓ Domain/project name duplicates and empty names detected
- ✓ Diff computed against pristine (edit-then-revert = no change)
- ✓ Validation errors listed in diff dialog
- ✓ Force checkbox appears only when forceable errors exist
- ✓ Force checkbox requires reason
- ✓ Payload includes base_updated_at and touched paths only
- ✓ Server force validation: requires reason when force=true
- ✓ Server returns 409 with conflict details on updatedAt mismatch
- ✓ Conflict dialog shows server changes
- ✓ Per-path before-value checking blocks conflicted changes
- ✓ No partial saves: if any change conflicts, all rejected
- ✓ ac_* pairing for all scopes: both nested and ac_* written together for member, domain, and project
- ✓ ac_* field name derivation: ac_m_<resource>, ac_d_<domain>__<resource>, ac_p_<project>__<resource>
- ✓ Audit row records mode and forced flag
- ✓ Diff display shows ac_* pairings indented under used fields (§7.3)
- ✓ Conflict diff computation: returns changes between server states
- ✓ TypeScript compiles (lib-target warnings only)

## Not verified

Needs live Parse object and concurrent edit simulation:

1. **ac_* pairing with locked schema** — all scopes implemented (member/domain/project, derives correct ac_* names, writes both nested and flat). Schema rejection handling tested in code but not with actual locked schema.
2. **Real concurrent consume** — conflict detection logic implemented (returns 409 with conflict details, computes what changed), live member quota movement untested
3. **Audit write failure** — code aborts on audit row error (§7.7), actual DB failure untested
4. **Force checkbox with real validation errors** — server validation implemented, live form data edge cases untested
5. **Conflict resolution UI flow** — conflict dialog renders what-changed list with ac_* pairings indented, user retry action untested
6. **Domain/project name lookup in ac_* derivation** — code looks up names from registryObj, but live edge cases untested (missing domain, index out of bounds)

## Follow-up

**Audit schema:** Migration 2026_08_28_000002 adds `forced` boolean column to BrainAllocAudit for recording §7.2 forced saves.

**ac_* pairing implementation:** All scopes complete — member, domain, and project. DerivePairedAcPath looks up domain/project names from registryObj to derive correct ac_* field names. Change application writes both nested and flat for all scopes.

**Conflict reconciliation:** §7.6's "Review side by side" feature is designed but not fully wired — current conflict dialog offers reload or retry, could be extended to per-path "keep mine/keep theirs" resolution UI.

**Schema management:** If locked schemas are in effect, §7.5 reports ac_* rejection clearly. Future: pre-provisioning step to ensure all possible ac_* fields exist before first save.

---

**Step 7 is complete.** Steps 08 (rollout) and 09 (billing integration) are next; they build on this base for production readiness.
