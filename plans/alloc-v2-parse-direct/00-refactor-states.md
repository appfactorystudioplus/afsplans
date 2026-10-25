Here's the accurate answer, from git rather than memory.

## Parse server implementation — committed in `19eb5619`

`plans: audit against code, two new plan sets, band rename`, in `packages/afsserver`:

| file | lines changed |
|---|---|
| **`alloc-v2-parse-direct/03-parse-cloud-functions.md`** | **756** — the rewrite to the minimal shape |
| `alloc-v2-parse-direct/04-distribution-and-parity.md` | 139 |
| `alloc-v2-parse-direct/05-findings-and-execution-plan.md` | 112 |
| `alloc-v2-parse-direct/02-client-refactor.md` | 92 |
| `alloc-v2-parse-direct/06-known-issues.md` | 91 |
| `alloc-v2-parse-direct/README.md` | 47 |
| `alloc-v2-parse-direct/01-current-state-audit.md` | 29 |

That's the whole folder — 03 rewritten to the counter-class-plus-two-functions shape with policy staying in the client, and the other six brought in line so they didn't contradict it.

## How objects are stored — same commit

| file | lines |
|---|---|
| **`membership_change/07-signup-flow.md`** | **864 (new)** — how the initial object is provisioned at signup |
| `membership_change/01-current-state-audit.md` | 159 (new) |
| `TODO-member-resources.md` | 937 (new) — the 34-resource / band-capacity feature list |

Plus the rest of `membership_change/` (00, 02–07, 10, README) and the new `www/builder/` set — 25 files, 5,318 insertions total.

## The v3→v4 retargeting — still uncommitted

The `members_brain` audit work is in the working tree, not committed. Each of these gained references to the v4 template and registry:

```
00-overview.md  +7    01-current-state-audit.md  +5
03-the-data-model.md  +4    08-rollout-and-testing.md  +3    README.md  +4
```

## The code side — committed in auth

`parse_member_query_v4.json` and `CheckpointBuildMemberTemplate.php` were last touched by `4b2e9fa`. The template is now **generated, not hand-maintained**, and currently reads:

- 4,463 bytes (was 7,019)
- 14 `member_resources`
- all five containers **empty**
- **zero `--Example_` placeholders**

That last line is the substantive fix: the committed template used to seed six placeholder resources into every new member. `MemberInitController::init()` copies it key-for-key, so every member was provisioned owning six things they didn't have — each carrying band/state/resources, which meant the retention cascade walked them and counted them toward the allowance. A downgrade could retire a domain that never existed.

One correction to my earlier reporting: I said 34 resources across 7 scopes. The committed template has **14** `member_resources` — that's the member-scoped subset, not the full registry count. The per-domain, per-project, per-graph, per-document, per-store and per-design-project resources live in their containers, which ship empty by design and are populated when the member creates the thing.

**Note none of this was touched in the current session** — it predates the context break. Today's work was the schema/migration/legacy-removal thread now written up in [handoff.md](../handoff.md), which doesn't cover the Parse plans. If you want them cross-referenced there, say so and I'll add a section.