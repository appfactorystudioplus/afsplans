# 03 — The Data Model

What is actually stored in a `MemberRegistry`, what reads it, and the four places where the
document, the client and the registry file disagree. Everything in
[04](04-form-generation.md) is generated from this.

## 3.1 Three containers

```jsonc
{
  "member_resources": { "<name>": { "key": "<resource_id>", "used": 0, "periodStart"?: … } },
  "domains":     [ { "name": "example.com", "status": 1, "createdAt": …, "resources": { … } } ],
  "pm_projects": [ { "name": "Website",     "createdAt": …,              "resources": { … } } ],

  "ac_m__member_domains_count": 3,                     // §3.5 — the real counters
  "ac_d_example_com__domain_processors_count": 1
}
```

`member_resources` is a **map**; `domains` and `pm_projects` are **arrays of named objects**.
`alloc_v2` finds a domain by `d.name === scope.domain` (`alloc_v2.ts:1306`) — a linear scan on a
string match, which means **the domain name is the key** and renaming a site orphans its
counters.

## 3.2 An entry, and which part of it is the identity

```jsonc
"email_accounts": { "key": "domain_email_accounts", "used": 3 }
```

The outer map key (`email_accounts`) is **cosmetic**. `findEntryByKey()` (`alloc_v2.ts:641`)
tries a direct hit on the full resource id first, then falls back to scanning for an entry whose
**`key` field** matches. So `entry.key` is the identity and the map key is a label that happens
to have been short.

The form must therefore:

- match on `entry.key`, never on the map key;
- **preserve the existing outer key** when writing an entry that already exists, because
  changing it is a visible diff in the JSON view for no behavioural gain;
- write new entries under the full resource id, since that is the path
  `findEntryByKey` finds without scanning.

## 3.3 Seven scopes, three containers (BR9, BR10)

`checkpoint_registry_v4.json` declares seven scopes. Here is where each one's resources can
actually live:

| Scope | Container | Client support | Resources |
|---|---|---|---|
| `member` | `member_resources` | ✅ | 14 |
| `per_domain` | `domains[].resources` | ✅ `{ domain }` | 10 |
| `per_project` | `pm_projects[].resources` | ✅ `{ pm_project }` | 1 |
| **`per_store`** | — | ❌ | `domain_ecommerce_items_per_store` |
| **`per_graph`** | — | ❌ | `dreamgraph_graph_nodes` |
| **`per_document`** | — | ❌ | `pdf_editor_document_pages` |
| **`per_design_project`** | — | ❌ | `design_flow_project_assets` |

`counterField()` (`alloc_v2.ts:623`) accepts `scope.domain`, `scope.pm_project`, or nothing.
There is no third key, so the bottom four resources **cannot be consumed against the right
bucket by any existing code**.

`parse_member_query_v4.json` files `ecommerce_items_per_store` under `domains[].resources`, which
enforces one item allowance shared across every store on the domain — not the per-store limit
the registry describes (**BR10**).

**The form surfaces these four in an "Unscoped — not enforceable" section, read-only, with the
reason** ([00](00-overview.md) **D5**). It does not invent containers. Making the gap visible on
the one screen an operator reads is how it gets fixed; hiding it would make the form look
complete and be wrong.

## 3.4 `parse_member_query_v4.json` is the provisioning template (BR5–BR7)

It is not documentation. `MemberInitController::init()`
(`auth/app/Http/Controllers/Checkpoint/MemberInitController.php:50`) reads this exact file from
disk, walks it rewriting every `periodStart` to the current UTC month, and saves it verbatim as
a new `MemberRegistry` under the master key.

Three consequences.

### Its placeholders become real rows (BR6)

```jsonc
"domains": [ { "name": "--Example_NAME--", "status": 1, "resources": { … } } ],
"pm_projects": [ { "name": "--Example_NAME--", … } ]
```

**Every member ever created carries a domain called `--Example_NAME--`** and a project of the
same name, each holding a full set of zeroed counters. When their real domain appears,
`alloc_v2` adds a second entry and the placeholder stays forever.

The template must be emptied — `"domains": []`, `"pm_projects": []` — and containers created on
first use. Until then, **the form treats a `--Example_NAME--` entry as removable scaffolding**
and offers to delete it, rather than rendering it as a site the member has
([04](04-form-generation.md) §4.6).

### It is missing ten of twenty-nine resources (BR7)

Absent: `notes_items_count`, `notes_notebooks_count`, `dreamgraph_graphs_count`,
`dreamgraph_graph_nodes`, `pdf_editor_documents_count`, `pdf_editor_document_pages`,
`design_flow_projects_count`, `design_flow_project_assets`, `afs_connect`, `secondary_users`.

They read as `0`, which is the right *number* — `readUsage()` returns 0 for a missing entry — so
nothing is broken. What is broken is that the template is the shape contract and does not
describe the shape. **The template is regenerated from the registry** rather than hand-edited
([08](08-rollout-and-testing.md) §8.4), which is the same rule as the form: one catalogue, many
projections.

### Editing it is a platform-wide schema change

Made in a file whose name says *query*, in `public/`, with no migration and no version. Worth a
comment at the top of the file saying so, because the name will mislead the next person exactly
as it misled this audit.

## 3.5 The `ac_*` counters are the authority (BR25)

**This is the finding that decides what the form has to write.**

`alloc_v2` maintains a **flat top-level field per counter** —
`ac_d_<domain_token>__<resource_id>`, `ac_p_<project>__<id>`, `ac_m__<id>` — because a nested
path like `member_resources.x.used` cannot be atomically incremented
([../allocation.md](../allocation.md) §11.3). The nested `used` is written alongside as a
projection.

`alloc_v2.ts:1329` says it in the source:

> *"the top-level counter is the authority, and `readUsage` prefers it."*

And `readUsage()` (`alloc_v2.ts:1298`) does exactly that: it checks `ac_*` first and only falls
back to `entry.used` when there is no finite number there.

| # | Finding | Consequence |
|---|---|---|
| **BR25** | The Allocations tab, the JSON editor, and any form written against the v3 shape all edit **`entry.used`** — the projection, not the authority | **An operator who corrects a counter changes nothing the member experiences**, whenever an `ac_*` field exists for that resource and scope. The save succeeds, the JSON reflects it, the member is still blocked. This is the screen's entire purpose failing silently |

There is a second-order twist. Per [../allocation.md](../allocation.md) §11.3, the `ac_*` write
is probably **rejected** by the Parse server's `lockSchemas: true` + `strict: true`
configuration, and `alloc_v2` falls back to the nested value alone. In that case editing `used`
*does* work.

**So whether this screen functions depends on a server setting the operator cannot see.** That
is not a state to build a form on top of.

> ### Updated 2026-08-27 — the twist is very likely the normal case, and it is being designed out
>
> Two things have moved since this section was written, and together they change what the form
> should be built against.
>
> **First, the rejection is probable rather than hypothetical.** `parse/config.ts` sets
> `lockSchemas: true` and `strict: true`, and an `ac_*` name embeds the domain so it cannot be
> declared in advance. The first save of a new one is a schema modification against a locked
> schema. So the fallback is most likely the *only* path that has ever run, which would mean
> **the `ac_*` counters have never been the authority in practice** — `readUsage()` prefers a
> field that is not there, and `entry.used` is what exists. One save against staging settles
> it, and it is worth doing before building either behaviour.
>
> **Second, the mechanism is being replaced.**
> [../alloc-v2-parse-direct/03](../alloc-v2-parse-direct/03-parse-cloud-functions.md) §3.2.2
> moves counters out of the member object entirely, into a declared `MemberRegistryCounter`
> class — one row per `(member, resource, scope, period)` with `used` as a top-level number.
> The open `ac_*` field set disappears, `lockSchemas` stops mattering, and the member object
> becomes a **display projection that is allowed to drift**.
>
> **What that means for this form.** The write-both rule below is correct *today* and becomes
> wrong once the counter class lands: at that point the form must write through the same
> conditional-increment function every consume goes through, and must **not** write counters
> into the member object at all — that object is then a projection, and editing a projection is
> exactly the silent no-op BR25 describes, in a new place.
>
> Sequencing: if the counter class lands first, skip the write-both rule and build against the
> function. If this screen ships first, build write-both and expect to remove it. Decide which
> before starting **P3**, because it is the difference between one implementation and two.

### What the form does about it

**Write both, always, as one save.** Setting a counter writes `entry.used` **and**
`ac_<scope>__<resource_id>` to the same value, whether or not the `ac_*` field currently exists.

- If the counter field exists, the authority is corrected.
- If it does not, one is created — and if the schema rejects it, the save reports that rather
  than half-succeeding ([07](07-save-semantics.md) §7.5).

**And show the disagreement.** When `ac_*` and `entry.used` differ on load, the row renders with
both values and a warning. That state means either a schema rejection mid-write or a previous
edit through this very screen, and it is worth an operator's attention rather than a silent
pick.

`counterField()`'s token rules matter here: domain names contain dots and hyphens, Parse field
names may not, so the scope token is sanitised (`alloc_v2.ts:600`). **The form must derive the
field name with the same function, never by string concatenation** — a hand-built name that
differs by one character creates a second counter nobody reads.

## 3.6 `periodStart` has two encodings (BR8)

| Written by | Encoding |
|---|---|
| `MemberInitController` (provisioning, server, PHP SDK) | `{"__type":"Date","iso":"2026-08-01T00:00:00.000Z"}` |
| `alloc_v2.ts:588` `currentPeriodStart()` (client, on rollover) | `"2026-08-01T00:00:00.000Z"` — a plain string |

Both are readable: `periodHasRolledOver()` accepts a `Date` instance or anything `String()`-able
into a valid date. Nothing is broken.

What is broken is legibility. One member's JSON shows an object where another's shows a string,
for the same field, and neither the operator nor a form control can assume which.

**The form renders both as a date picker and writes the plain ISO string**, matching the client
— which is also the encoding that survives `toJSON()` unchanged, so what the JSON view shows is
what is stored.

## 3.7 Tier resolution is positional, whatever the file says (BR12)

```jsonc
"tiers": [ {"name":"free"}, {"name":"starter"}, {"name":"professional"},
           {"name":"business"}, {"name":"enterprise"} ],           // top level: labels

"resources": { "domain_processors_count": {
    "tiers": [ {"value":0,"enabled":false}, {"value":1,"enabled":true},
               {"value":3,"enabled":true},  {"value":10,"enabled":true},
               {"value":-1,"enabled":true} ] } }                    // per resource: values
```

The member's `membership` (0–4) indexes **both arrays at the same position**. `tiers[2].label`
is `"Professional"`; `resources.domain_processors_count.tiers[2].value` is `3`. That is the whole
mechanism.

`conventions.tier_resolution` in that same file says:

> *"Tiers are resolved by name. Use the 'index' field to map a numeric tier rank to its name key."*

**There is no `index` field anywhere in the document**, and resource tier entries carry no name.
The convention text describes a v2 shape. Anyone implementing from the file's own documentation
will look for `index`, not find it, and guess — which is how a tier gets resolved by matching
`brain_memberships`' labels (`Free/Pro/Business/Organization/Enterprise`) against the registry's
(`free/starter/professional/business/enterprise`), two sets that do not correspond.

**Correct the convention text** in the same pass. It is one string, and it is actively
misleading.

## 3.8 Where the form reads each thing

| The form needs | From | Note |
|---|---|---|
| Which resources exist | `checkpoint_registry_v4.json` `resources` | 34. The catalogue |
| Ids as constants | `checkpoint/resources.ts` `AllocV2Resources` | The only list in parity (**BR11**) |
| Label, description, unit, period, overage, `warn_at_pct` | the registry entry | All present; none need inventing |
| Which container a resource belongs in | the entry's `scope` | §3.3 |
| The ceiling | `resources[id].tiers[member.membership].value` | positional (§3.7) |
| Whether the feature exists at this tier | `…tiers[m].enabled` | `false` ≠ a limit of 0 |
| The tier's display name | `tiers[member.membership].label` | same index |
| The member's tier | `brain_memberships.membership` | via the Brain member payload |
| Usage | `ac_*` first, `entry.used` as fallback | §3.5 — and write **both** |
| Period window | `entry.periodStart` | either encoding (§3.6) |
| Domains and projects | `domains[]`, `pm_projects[]` by `name` | plus the `--Example_NAME--` scaffolding (§3.4) |

**Nothing in that table is hand-maintained by this plan.** That is the point of
[00](00-overview.md) **D1**: the form has no list of its own to drift.

## 3.9 Acceptance

- The form renders **34** resources for a member — not 19, and not the 29 the provisioning template still covers (**BR7**).
- A resource whose scope has no container renders in the **Unscoped** section, read-only, with
  the reason (**BR9**).
- Setting a counter writes **both** `entry.used` and the `ac_*` field, and the `ac_*` name is
  produced by `counterField()` (**BR25**).
- A member whose `ac_*` and `entry.used` disagree renders a **warning showing both**, and does
  not silently pick one.
- An entry whose outer map key differs from its `key` field is matched on `key`, and its outer
  key survives the save unchanged (§3.2).
- `periodStart` renders in a date control for **both** encodings and writes an ISO string
  (**BR8**).
- A tier-2 member shows a ceiling of **3** for `domain_processors_count` and the label
  **"Professional"** — both resolved by index (§3.7).
- A newly created member has **no `--Example_NAME--` entries** once the template is emptied
  (**BR6**), and existing ones are offered for removal.
- `conventions.tier_resolution` no longer refers to a non-existent `index` field (**BR12**).
