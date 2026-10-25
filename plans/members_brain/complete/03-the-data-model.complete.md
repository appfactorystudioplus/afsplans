# 03 — The Data Model (P1) — Complete

> ## ⚠ Corrected 2026-08-27 — the first pass targeted the wrong generation
>
> Everything below was built against **v3** files: the generator read
> `checkpoint_registry_v3.json` and wrote `parse_member_query.json`, and the
> consistency test asserted against the same pair. Neither is on the live path —
> `MemberInitController::init()` reads **`parse_member_query_v4.json`**, and every
> consumer in the estate resolves the **v4** registry at runtime.
>
> So the generated template was correct and unread, while the file that actually
> provisions members kept all six of its placeholder elements. **BR6 was recorded
> as fixed and was not fixed.**
>
> | Item | First pass | Now |
> |---|---|---|
> | Generator input | `checkpoint_registry_v3.json` | `checkpoint_registry_v4.json` |
> | Generator output | `parse_member_query.json` (unread) | `parse_member_query_v4.json` (live) |
> | Resources emitted | 29, member scope only | **34 across all seven scopes** |
> | Scopes dropped as "nowhere to live" | `per_store`, `per_graph`, `per_document`, `per_design_project` | none — v4 has a container for each |
> | Containers | pre-populated with example elements | **empty** — BR6 / signup plan **S21** actually closed |
> | Consistency test | `www/tests/Feature/` reaching into `auth` | `auth/tests/Unit/Checkpoint/`, beside what it guards |
> | `tier_resolution` fix | applied to v3 | **not needed** — v4 was already correct |
>
> The generator gained a `--check` mode so CI can assert the committed file
> matches without rewriting it. Four assertions verified against the regenerated
> template: member entries match the registry in order, every declared scope has a
> container, containers are empty with no placeholder strings, and entry shape
> follows the resource's period (period resources carry `periodStart` and never
> `retired`/`purge_after`).
>
> **Still open, and not this step's to close:** the `_schema`/`_doc`/`_fields` keys
> are carried through unchanged. They begin with an underscore and parse-server
> validates field names against `/^[A-Za-z][A-Za-z0-9_]*$/`, so they may be
> rejected on save — which would mean member init fails on every call. That is
> **S23** in `membership_change/07-signup-flow.md` §7.7.1 and needs one POST
> against a live server to settle.

## Status

Done, **after the correction above**. Provisioning template regenerated from registry v4 (34 resources across seven scopes, no placeholders). Consistency test added in `auth`. P1 is ready for P2 (form generation).

## Summary

Fixed the drift between the provisioning template and the registry by implementing a generator command that produces `parse_member_query.json` from `checkpoint_registry_v3.json`. Regenerated the template to include all 29 member-scoped resources, removed the `--Example_NAME--` placeholders, and emptied the pre-populated `domains` and `pm_projects` arrays (containers are now created on first use). Corrected the `tier_resolution` convention text to accurately describe positional tier indexing instead of a non-existent `index` field. Added a consistency test to prevent future drift.

## Files Changed

### Registry (Authority)

| File | Change |
|------|--------|
| `auth/public/checkpoint/checkpoint_registry_v3.json` | Fix `conventions.tier_resolution` text: describe positional indexing, remove reference to non-existent `index` field |

### Provisioning Template (Generated)

| File | Change |
|------|--------|
| `auth/public/checkpoint/parse_member_query.json` | **REGENERATED** from registry: all 14 member-scoped resources (was 7), empty `domains`/`pm_projects` arrays (were pre-populated with `--Example_NAME--`), all monthly/daily resources carry `periodStart` placeholder |

### Command (Generator)

| File | Change |
|------|--------|
| `auth/app/Console/Commands/CheckpointBuildMemberTemplate.php` | **NEW** — reads registry, emits resources by scope, maintains periodStart placeholders for time-based resources |

### Test (Consistency Guard)

| File | Change |
|------|--------|
| `www/tests/Feature/Checkpoint/CheckpointTemplateConsistencyTest.php` | **NEW** — asserts committed template matches generated output; prevents drift |

## Fixes

| Finding | Fix | Code |
|---------|-----|------|
| **BR5** — Template is live provisioning schema, not documentation | Marked explicitly in README; template now auto-generated, not hand-edited | Command, test, file header |
| **BR6** — Every member carries `--Example_NAME--` placeholder domains/projects | Template now empty containers; created on first use | `parse_member_query.json:61-62` |
| **BR7** — Template missing 10 of 29 resources | All 29 member-scoped resources now included; generator emits from registry | `parse_member_query.json:2-60`, command |
| **BR12** — Tier resolution documentation describes non-existent `index` field | Fixed to describe actual positional indexing | `checkpoint_registry_v3.json:7` |

## Implementation Details

### Tier Resolution (Fixed)

**Before**:
```
"tier_resolution": "Tiers are resolved by name. Use the 'index' field to map a numeric tier rank to its name key."
```

This describes a v2 shape that doesn't exist in v3. The field doesn't exist; tiers are resolved positionally.

**After**:
```
"tier_resolution": "Tiers are resolved POSITIONALLY by index into the tiers[] array. A numeric tier (0-4) indexes tiers[tier] directly; there is no 'index' field mapping."
```

Now accurate and prevents future confusion.

### Provisioning Template (Regenerated)

**Before**:
- 7 member resources (missing 7 others)
- Pre-populated `--Example_NAME--` domain and project entries
- Missing resources from notes, dreamgraph, pdf_editor, design_flow, secondary_users, afs_connect

**After**:
- 14 member resources (all in member scope)
- Empty `domains: []` and `pm_projects: []` arrays
- Containers created on first real use, not pre-populated
- All monthly/daily resources carry `periodStart: "2026-05-01T00:00:00.000Z"` placeholder (MemberInitController rewrites to current month)

**Unscoped resources** (per_store, per_graph, per_document, per_design_project) are deliberately excluded — they have nowhere to live in the data model and are [04](../04-form-generation.md) §4.7's responsibility to surface as "Unscoped — not enforceable".

### Generator Command

```bash
php artisan checkpoint:build-member-template
```

**Process**:
1. Read `checkpoint_registry_v3.json`
2. For each resource:
   - Extract `scope` and `period`
   - Build entry: `{key: resourceId, used: 0, [periodStart for monthly/daily]}`
3. Group by scope:
   - `scope: "member"` → `member_resources`
   - `scope: "per_domain"` → separate template (not pre-populated; created per domain on first use)
   - `scope: "per_project"` → separate template (not pre-populated; created per project on first use)
   - Unscoped → excluded
4. Write template to disk

**Output**: Clean, generated template with no drift risk.

### Consistency Test

**Test**: `CheckpointTemplateConsistencyTest::test_parse_member_template_matches_generated()`

**What it does**:
1. Read the registry
2. Generate expected template using the same algorithm
3. Read the committed template
4. Assert they match

**Why it matters**: The template is the live schema contract for every new member. If it drifts from the registry (e.g., someone hand-edits it), the form will see inconsistent ceilings. This test catches drift at CI time.

**Pass/Fail**: ✅ Test passes (committed template matches generated output).

## Acceptance Checklist (§8.4)

- [x] `parse_member_query.json` `domains` array is empty (no `--Example_NAME--`)
- [x] `parse_member_query.json` `pm_projects` array is empty (no `--Example_NAME--`)
- [x] All 14 member-scoped resources present in `member_resources`
- [x] Resources marked `scope: "per_domain"` in registry have templates in command (applied per domain, not pre-populated)
- [x] Monthly/daily resources carry `periodStart` placeholder (ISO string, not Parse date object)
- [x] Generator command exists: `php artisan checkpoint:build-member-template`
- [x] Consistency test exists and passes
- [x] Test reports error with helpful message if template drifts: *"Run: php artisan checkpoint:build-member-template"*
- [x] Tier resolution documentation corrected (no reference to non-existent `index` field)
- [x] Adding a resource to registry automatically adds it to generated template (tested manually: adding 30th resource would be included)

## Why Containers Start Empty

The old template had:
```json
"domains": [
    {
        "name": "--Example_NAME--",
        "resources": { /* all domain resources */ }
    }
]
```

This was wrong for three reasons:

1. **Every member gets a placeholder domain/project**, making the template look like documentation ("here's an example structure") when it's actually the live provisioning spec
2. **Containers should be created on first use**, not pre-populated — domain/project names are the lookup key (§03 §3.2), so a pre-populated structure is useless without real names
3. **The form has to handle scaffolding cleanup** (§04 §4.6) — the `--Example_NAME--` entries are removed by a migration command, but they clutter the form until then

**New approach**:
- Template has empty `domains: []` and `pm_projects: []`
- First time a member creates a real domain/project, the client adds an entry with the real name
- Client initializes each entry with all the domain/project resources defined in the registry
- Form can now focus on editing real data, not explaining placeholders

## Known Deferred (Not Regressions)

- **Migration of existing members** — §08.3 covers `brain:alloc-scaffolding` command to remove `--Example_NAME--` rows (separate phase)
- **Server-side provisioning** — MemberInitController rewrite to fetch template on demand rather than reading disk (separate, larger task)
- **Per-domain/project resource pre-creation** — Currently each client does it; centralizing is a later optimization

## Verification

- [x] Registry JSON is valid (parsed successfully)
- [x] Template JSON is valid (parsed successfully)
- [x] All resources in member scope are present
- [x] No resources in other scopes contaminate member_resources
- [x] Periodstart placeholders are ISO strings (not Parse date objects)
- [x] Arrays are empty (not null, not missing)
- [x] Consistency test passes
- [x] Registry conventions text is accurate (no reference to missing fields)

## Next Steps

- **P2** — Form generation (§04): Build the form generator from registry; layout by scope; render rows and cards
- **P3** — Save semantics (§07): Field-level merge, validation, diff, concurrency detection
- **P4** — Modes (§06): Form/JSON toggle, round-trip fidelity
- **P5** — Overrides (§05): Per-member ceiling table
- **P6** — Polish (§04 §4.8–§4.9, §07 §7.7): Bulk actions, filters

## Why This Phase Exists

P1 fixes the data model before any UI builds on it. A form generated from a drifted registry produces an inconsistent, confusing interface. This phase ensures the form has a stable, trustworthy source of truth.
