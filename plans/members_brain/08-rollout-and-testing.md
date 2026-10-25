# 08 — Rollout and Testing

## 8.1 Phases

| Phase | Contents | Docs | Depends on |
|---|---|---|---|
| **P0** | **Security and configuration gate** — apply `portal.admin.auth`, add `portal.level99`, resolve the principal, fix `CHECKPOINT_APP_ID`, one Parse initialiser, the audit table | [02](02-security-and-access.md) | nothing |
| **P1** | **Data foundation** — empty the provisioning template, regenerate it from the registry, correct `conventions.tier_resolution`, the save endpoint | [03](03-the-data-model.md), [07](07-save-semantics.md) §7.4 | P0 |
| **P2** | **The form** — the generator, the layout, rows, period sub-lines, domain and project cards, the unscoped section | [04](04-form-generation.md) | P1 |
| **P3** | **Save semantics** — validation, the diff, the merge, `ac_*` pairing, concurrency | [07](07-save-semantics.md) | P1 |
| **P4** | **Modes** — one state, the representability check, the switch warnings, orphan-counter removal | [06](06-editor-modes.md) | P2, P3 |
| **P5** | **Overrides** — the table, the resolver, the entitlement field, the grant dialog | [05](05-limit-overrides.md) | P0, P2 |
| **P6** | **Polish** — bulk actions, filters, the provision-registry action | [04](04-form-generation.md) §4.8–§4.9, [07](07-save-semantics.md) §7.7 | P2, P3 |

**P0 ships alone**, on its own branch, with no feature work attached. A security fix that
arrives inside a feature release is a security fix that waits for the feature.

### Order that is not negotiable

1. **P0 before everything.** The API is open (**BR1**) and the section is gated by CSS
   (**BR3**).
2. **`CHECKPOINT_APP_ID` before anything is tested.** Every `alloc_v2` call in `www` currently
   authenticates against an application id the Parse server does not know (**BR13**). Testing
   allocation before that is fixed measures the wrong thing and will produce a second round of
   findings that are all the same finding.
3. **P3 before P2 is usable by anyone.** The form makes it *easier* to write to a member's
   registry. Shipping it in front of a blind overwrite (**BR17**) makes a known hazard
   convenient. If they must be split, ship P3 behind the existing JSON editor first.
4. **P1's template fix before P2.** Otherwise the form's first job is rendering a
   `--Example_NAME--` card for every member and explaining it (**BR6**).
5. **P5 after P2.** An override control needs a row to hang off.

## 8.2 The security gate

P0 does not ship until every one of these is demonstrated, by test:

| # | Gate |
|---|---|
| 1 | Unauthenticated `GET /portal/api/v1/brain/members` → **401** |
| 2 | Unauthenticated `GET /portal/api/v1/brain/dns/servers` → **401** |
| 3 | Authenticated, `level = 0` → **404** on every `api/v1/brain/*` route |
| 4 | `level` supplied in the request body is ignored; the database row decides |
| 5 | `portal.admin.auth` returns **401 JSON** for `api/v1/*`, still redirects for HTML |
| 6 | `grep -rn "portal.admin.auth" routes/` is non-empty |
| 7 | `www/.env` `CHECKPOINT_APP_ID` === `auth/.env` `PARSE_APP_ID`, character for character |
| 8 | No Parse application id appears in any `.ts` file |
| 9 | A second `Parse.initialize` with different configuration **throws** |
| 10 | Every allocation write produces exactly one audit row |
| 11 | An operator cannot open their own member's Allocations tab |
| 12 | A decrease without a reason is rejected **at the endpoint**, not only in the UI |

## 8.3 Migrating what exists

Two data problems, both from [03](03-the-data-model.md) §3.4.

**`--Example_NAME--` rows.** Every member created so far has one domain and one project by that
name. A command reports them and removes them where the container has **all-zero counters and no
matching `ac_*` fields**; anything else is listed for manual review, because a placeholder that
has accumulated usage is no longer a placeholder and deleting it destroys real counts.

```
php artisan brain:alloc-scaffolding [--dry-run] [--fix]
```

**Reports by default.** The same rule as every other reconciler in the estate
([../billing/16](../billing/16-allocation.md) §16.8).

**Missing resources.** Ten of twenty-nine were never provisioned (**BR7**). **Nothing needs
backfilling** — `readUsage()` returns 0 for a missing entry, which is the correct value, and the
form creates entries on first edit. Regenerating the template fixes it going forward; existing
members are already correct.

## 8.4 The template is generated, not edited

`parse_member_query_v4.json` is the provisioning template (**BR5**), and it drifted from the
registry by ten resources because it is maintained by hand.

```
php artisan checkpoint:build-member-template
```

Reads `checkpoint_registry_v4.json`, emits every resource into its scope's container with
`used: 0` and — for `monthly`/`daily` resources — a `periodStart` placeholder that
`MemberInitController` already rewrites. Emits `"domains": []` and `"pm_projects": []`.

A consistency test asserts the committed file matches the generator's output, the same way
`afs_connect_sources.php` is guarded in coordinator. **That test is what stops the drift
recurring**, and it is more valuable than the generator.

## 8.5 The test matrix

### Backend

| | |
|---|---|
| Auth | unauthenticated → 401; level 0 → 404; level 99 → 200; body-supplied level ignored |
| Self-edit | operator's own member reference → 404 |
| Merge | untouched paths unchanged after a save that alters one |
| Concurrency | modify the object between load and save → 409 naming the fields |
| Per-path | one stale `before` → that change rejected, others offered |
| Validation | each rule in [07](07-save-semantics.md) §7.2, refused with a pointer |
| Force | value errors forceable with a reason; shape errors not |
| Pairing | usage change writes nested **and** `ac_*`, in one save |
| Schema rejection | locked schema → reported, not swallowed |
| Audit | one row per save; a failed audit write fails the save |
| Overrides | scope required for scoped resources; no reason → rejected; `enabled: false` not overridable; expired ignored but retained |
| Entitlement | `GET /entitlement/{member}` carries overrides and matches the browser |

### Front-end

| | |
|---|---|
| Generation | 34 rows; adding a registry resource adds a row with no form change |
| Tier | ceiling and label by index; tier as a string still resolves (`Number()`) |
| States | `enabled: false`, `-1`, over-ceiling, disagreement, not-provisioned, overridden |
| Period | `[reset now]`; expired window disables the input |
| Containers | N domains → N cards; add; remove takes the `ac_*` fields with it |
| Scaffolding | `--Example_NAME--` collapses and is not counted |
| Modes | edit → switch → switch back, once; pending count stable across the toggle |
| Unrepresentable | warned, listed, persistent banner, preserved byte-identical through a form save |
| Filters | never hide a pending or warning row |
| Discard | restores `pristine`, clears `touched` |

### End-to-end

1. Operator opens a tier-2 member, sees 3 domains and 34 resources, spots `4 of 3` processors.
2. Corrects it to 1, sees the diff **including the `ac_*` write**, gives a reason, saves.
3. The member's own portal stops showing the limit dialog — **the actual pass condition**, and
   the one that fails today (**BR25**).
4. The audit row names the operator, the diff and the reason.
5. A second operator with the same member open gets a **409** on save.
6. An override of 50 → 75 changes the ceiling in the member's browser **and** in the entitlement
   endpoint, and does not change usage.

## 8.6 What is deliberately not here

- **Cross-member bulk operations.** *"Reset every monthly counter on the platform"* is a
  scheduled job with a dry run, not a control on a detail page
  ([00](00-overview.md) §0.3).
- **Server-side enforcement of allocation.** [../alloc-v2-parse-direct/](../alloc-v2-parse-direct/).
  This plan makes the console honest; it does not make the limit real
  ([../allocation.md](../allocation.md) §7).
- **Fixing the four orphan scopes.** Named on the screen ([04](04-form-generation.md) §4.7),
  designed elsewhere.

## 8.7 Definition of done

- Every gate in §8.2 passes.
- An operator can read a member's complete allocation state — 34 resources, real ceilings, real
  tier — on one screen without opening the registry file.
- A counter correction **changes what the member experiences** (**BR25**).
- No save can revert a concurrent consume (**BR17**, **BR18**).
- Every write is attributed, reasoned where it decreases, and reconstructable from the audit
  table.
- The raw JSON editor is still there, still able to edit anything, and can no longer silently
  destroy anything.
- A resource added to `checkpoint_registry_v4.json` appears in the form, in the provisioning
  template and in the consistency test, with **no change to this feature's code**.
