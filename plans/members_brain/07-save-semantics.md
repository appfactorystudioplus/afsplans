# 07 — Save Semantics

Today: `registryObj.save(cleanData)` with whatever is in the buffer (**BR17**). No merge, no
validation, no concurrency check, and a green success message either way.

## 7.1 The three failures behind one line

```ts
registryObj.save(cleanData)      // registry.ts:262
```

| Failure | Why it is invisible |
|---|---|
| **Lost update** | The buffer is a snapshot from when the editor opened. Every `consume` since is reverted, and the operator is told it saved — because it did |
| **Collateral rewrite** | Fields the operator never looked at are written with stale values, indistinguishably from ones they meant to change |
| **Corruption** | `used: "abc"`, a negative counter, a deleted container — all save identically (**BR19**) |

All three are fixed by the same three things: validate, diff, merge — against a **re-read**.

## 7.2 Validation

Runs on the draft before the diff, in both modes ([06](06-editor-modes.md) §6.6).

| Rule | Applies to |
|---|---|
| `used` is a finite integer ≥ 0 | every entry, every container |
| `used` fits `unsignedInteger` | ≤ 4 294 967 295 — the counters are unsigned in every store that holds them |
| `periodStart` parses to a valid date | either encoding ([03](03-the-data-model.md) §3.6) |
| `periodStart` is not in the future | a future window start reads as "not rolled over" forever |
| A `monthly` entry's `periodStart` is a month boundary in UTC | matching `alloc_v2.ts:588` |
| `entry.key` is one of the registry's 34, **or** flagged unrepresentable | never silently accepted as a new resource |
| `domains` and `pm_projects` are arrays; `member_resources` is an object | shape |
| Domain and project `name` are non-empty and unique within their array | `name` is the lookup key |
| Containers are present | removing `member_resources` entirely is refused ([02](02-security-and-access.md) §2.7) |

Validation produces **warnings** as well as errors. An entry whose `used` exceeds the tier
ceiling is legal and sometimes correct; it warns. An entry with `used: -1` is refused.

### `--force`

The raw editor exists so an operator can write something the form cannot. Occasionally that
includes something validation rejects — recovering a half-written entry, matching a shape a
future version expects.

So the confirmation dialog carries a **`--force` checkbox**, disabled unless there are validation
*errors*, which requires a reason and records `forced: true` on the audit row. Shape rules
(containers must be the right type) are **not** forceable: a `domains` that is not an array makes
the client throw on read for every subsequent load, and that is not a recovery, it is a second
outage.

## 7.3 The diff

Nothing saves without this screen.

```
┌─ 4 changes to jane@example.com ────────────────────────────┐
│  example.com                                                │
│    Payment Processors            used   4  →  1             │
│      └ counter ac_d_example_com__domain_processors_count    │
│                                  4  →  1                    │
│    Email Accounts                used   7  →  6             │
│                                                             │
│  Member                                                     │
│    Standalone Form Submissions   used 980  →  0             │
│                                  periodStart                │
│                                  2026-07-01 → 2026-08-01    │
│                                                             │
│  ⚠ 3 of these DECREASE a counter. Reason required.          │
│  Reason  [ counters left stale by the delete bug, ticket … ]│
│                                                             │
│  ☐ Force (2 validation errors)                              │
│                              [ Back ]  [ Save 4 changes ]   │
└─────────────────────────────────────────────────────────────┘
```

- **Computed against `pristine`**, not against the last render, so a value edited and edited back
  is not a change.
- **The paired `ac_*` write is shown**, indented under its row. It is a second field being
  written and the operator should see it — that pairing is the fix for **BR25** and hiding it
  would make the most important write on the page invisible.
- **Decreases are called out** and carry the reason requirement
  ([02](02-security-and-access.md) §2.6).
- **The diff is what gets stored** in `laravel_brain_alloc_audit.changes`. The operator confirms
  a list; the audit row is that list.

## 7.4 The merge

Only `touched` paths are written ([06](06-editor-modes.md) §6.2).

```
POST /portal/api/v1/brain/member/{reference_num}/allocation
{
  "base_updated_at": "2026-08-25T11:47:03.000Z",
  "reason": "…",
  "mode": "form",
  "force": false,
  "changes": [
    { "path": "/domains/0/resources/processors_count/used", "before": 4, "after": 1 },
    { "path": "/ac_d_example_com__domain_processors_count", "before": 4, "after": 1 }
  ]
}
```

The handler re-reads the object with the master key, applies each change **by path**, and saves.
A field nobody touched is never in the payload and therefore cannot be reverted by this screen.

**`before` is sent and checked per path.** If the current value differs from `before`, that one
change is rejected and reported — a finer-grained version of §7.6's whole-object check, and the
one that lets a save partially succeed intelligibly rather than failing wholesale because an
unrelated counter moved.

## 7.5 The write, and the `ac_*` pairing

Every usage change writes **two** fields ([03](03-the-data-model.md) §3.5):

```php
/**
 * AFS_BRAIN_ALLOC_MODIFIER_ADDITION · AFS_ALLOC_V2_TAG
 * plans/members_brain/07-save-semantics.md §7.5
 *
 * A usage correction writes the nested projection AND the flat ac_* counter.
 *
 * alloc_v2.ts:1329 states that the top-level counter is the authority and
 * readUsage() prefers it, so writing only the nested value changes nothing the
 * member experiences — the screen's whole purpose, failing silently (BR25).
 *
 * The field name comes from the same derivation the client uses. Domain names
 * carry dots and hyphens that Parse field names may not, so a hand-concatenated
 * name differs by one character and creates a second counter nobody reads.
 */
```

Two failure modes, and neither may half-succeed:

- **Schema rejection.** `lockSchemas: true` + `strict: true` on the Parse server rejects an
  `ac_*` column that does not exist yet ([../allocation.md](../allocation.md) §11.3). `alloc_v2`
  handles this by falling back to the nested value alone. **This screen does not fall back** — it
  reports *"the counter field could not be written; the stored value was updated but the
  authoritative counter was not"* and names §11.3. A silent fallback here recreates the exact
  disagreement the screen was opened to repair.
- **Partial save.** Both fields go in one `save()`, so Parse applies them together or not at all.

## 7.6 Concurrency

`base_updated_at` is the object's `updatedAt` at **load** ([06](06-editor-modes.md) §6.2).

```
re-read → updatedAt !== base_updated_at → 409, with what changed
```

```
┌─ This member's registry changed while you were editing ───┐
│  Since you opened it (14:02:11), 2 fields changed:         │
│    Direct Payments     used   12  →  13                    │
│    ac_d_example_com__domain_direct_payments_count  12 → 13 │
│                                                            │
│  Your 4 changes have not been saved.                       │
│      [ Reload and lose my changes ]  [ Review side by side ]│
└────────────────────────────────────────────────────────────┘
```

**409, never a silent retry.** A retry that re-applies the buffer over the newer object is the
original bug with an extra round trip. The member consuming their own quota while an operator is
mid-edit is the *normal* case on a busy account, not an exception.

**Review side by side** offers a per-path resolution — keep theirs, keep mine — which is exactly
the per-path `before` check from §7.4 surfaced as a choice.

## 7.7 Failure modes

| Situation | Behaviour |
|---|---|
| Parse unreachable on load | The error is **shown**. `GetMemberRegistry()` today has no `.catch`, so the screen sits on its spinner forever (**BR20**) — fixed as part of this |
| Parse unreachable on save | 502 from the endpoint, changes retained in the draft, nothing lost |
| `updatedAt` moved | 409, §7.6 |
| One path's `before` no longer matches | That change is rejected and named; the rest are offered |
| Validation error | Blocked, with the pointer and the rule. `--force` for value rules only (§7.2) |
| `ac_*` column rejected by the schema | Reported, not swallowed (§7.5) |
| No `parse_alloc` on the member | Offer to **provision one** — `POST /portal/api/v1/checkpoint/member/init` already does exactly this and is currently reachable only from the creation flow (**BR21**) |
| Audit row fails to write | **The save fails.** An unattributed write to someone else's quota is the thing [02](02-security-and-access.md) §2.5 exists to prevent, and "the log was down" is not a reason to make one |

That last row is the one asymmetry worth defending. Everywhere else in the estate a logging
failure is non-fatal ([../allocation.md](../allocation.md) §5.3). Here the log **is** the
control.

## 7.8 Acceptance

- A save writes **only** the paths in `changes`; a field edited by a `consume` during the session
  and not touched by the operator is **unchanged** afterwards (**BR17**).
- A concurrent consume produces a **409** naming what moved, and the operator's changes survive
  in the draft (**BR18**).
- `used: "abc"`, `-1`, `1.5`, and a deleted `member_resources` are each **refused**, with the
  pointer and the rule (**BR19**).
- `--force` is available only for value errors, requires a reason, and records `forced: true`.
- A `domains` that is not an array is **not** forceable (§7.2).
- Every usage change writes the nested value **and** the `ac_*` counter, and both appear in the
  diff (**BR25**).
- An `ac_*` write rejected by a locked schema is **reported**, and the row still shows the
  disagreement afterwards (§7.5).
- No save proceeds without the diff being displayed.
- A decrease without a reason is rejected at the endpoint, not only in the UI.
- A failed audit write fails the save (§7.7).
- A Parse error on load renders an error, not a spinner (**BR20**).
- A member with no `parse_alloc` gets a **Provision registry** action that succeeds (**BR21**).
