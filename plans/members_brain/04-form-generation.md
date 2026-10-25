# 04 — Form Generation

The second editing mode. **The form is generated from the registry** — nothing in this document
describes a hand-written field.

## 4.1 The generator

```ts
/**
 * AFS_BRAIN_ALLOC_MODIFIER_ADDITION · AFS_ALLOC_V2_TAG
 * plans/members_brain/04-form-generation.md §4.1
 *
 * Build the editable model for one member's allocations.
 *
 * Generated from checkpoint_registry_v4.json rather than enumerated, because
 * the estate already keeps three hand-maintained lists of the same resource
 * ids and two of them have drifted (01 §1.4, BR11). A fourth would drift too,
 * and its drift would be invisible: a missing field just does not render.
 *
 * `tier` indexes tiers[] POSITIONALLY. Number() is not decoration: a string
 * tier indexes the array as undefined and resolves every ceiling to 0.
 *
 * (v3's conventions block claimed tiers resolve by name via an `index` field —
 * there is no such field. v4 states the positional rule correctly, so the
 * warning that used to live here is no longer needed. Verified 2026-08-28.)
 */
function buildAllocationModel(
    registryFile: AllocV2Registry,      // checkpoint_registry_v4.json
    parseObject: Record<string, any>,   // registryObj.toJSON()
    tier: number,                       // brain_memberships.membership
): AllocationModel
```

For each of the 34 entries in `registryFile.resources` it produces one **row**:

| Field | Source |
|---|---|
| `id`, `label`, `description`, `unit` | the registry entry |
| `scope`, `period`, `overage`, `warnAtPct` | the registry entry |
| `limit` | `entry.tiers[tier].value` — `-1` unlimited |
| `enabled` | `entry.tiers[tier].enabled` |
| `used` | `ac_*` if finite, else nested `entry.used`, else 0 ([03](03-the-data-model.md) §3.5) |
| `counterValue` / `nestedValue` | both, separately, so §4.4's disagreement warning can render |
| `periodStart` | the nested entry, either encoding |
| `container` | derived from `scope` — which card the row belongs to |
| `override` | [05](05-limit-overrides.md), if present |

Rows are then grouped into cards by container, and **one card is instantiated per domain and per
project**, so a member with three sites gets three copies of the fifteen `per_domain` rows — which is
what `per_domain` means and what the JSON view has always shown less legibly.

## 4.2 The layout

```
┌─ Allocations ─────────────────────────────────────── [ Form │ JSON ] ─┐
│  jane@example.com · Professional (tier 2) · parse_alloc aB3xY9…       │
│  Registry read 14:02:11 · updatedAt 2026-08-25 11:47:03               │
│                                                                        │
│  [ search resources…        ]  ☑ hide zero-usage  ☐ show overrides only│
│                                                                        │
│ ┌─ Member ──────────────────────────────────── 14 resources ─────────┐│
│ │  Websites                    ▓▓▓▓▓░░░░░  [    3 ] of 10   domains  ││
│ │  Storage                     ▓▓▓▓▓▓▓▓▓░  [ 41200] of 51200 KB  ⚠   ││
│ │  Standalone Forms            ░░░░░░░░░░  [    0 ] of 100  forms    ││
│ │  Standalone Form Submissions ▓▓░░░░░░░░  [  980 ] of 50000         ││
│ │      ↳ monthly · resets 1 Sep · window opened 1 Aug   [reset now]   ││
│ │  …                                                                  ││
│ └────────────────────────────────────────────────────────────────────┘│
│                                                                        │
│ ┌─ Domain: example.com ───────── status 1 · added 12 Feb ── [remove] ─┐│
│ │  Email Accounts              ▓▓▓▓▓▓▓░░░  [    7 ] of 10  accounts  ││
│ │  Payment Processors          ▓▓▓░░░░░░░  [    1 ] of 3  processors ││
│ │      ↳ ⚠ counter 1 · stored 4 — these disagree           [details]  ││
│ │  Direct Payments             ░░░░░░░░░░  [    0 ] of 50            ││
│ │  …                                                                  ││
│ └────────────────────────────────────────────────────────────────────┘│
│                                                                        │
│ ┌─ Domain: --Example_NAME-- ──────────────────────────────────────────┐│
│ │  ⓘ Provisioning scaffolding, not a real site (03 §3.4).  [remove]   ││
│ └────────────────────────────────────────────────────────────────────┘│
│                                                       [+ add domain]  │
│                                                                        │
│ ┌─ Project: Website ─────────────────────────── 1 resource ──────────┐│
│ │  Kanban Boards               ▓▓░░░░░░░░  [    2 ] of 10   boards   ││
│ └────────────────────────────────────────────────────────────────────┘│
│                                                                        │
│ ┌─ Graph: G1 ─────────────────────────────────── 1 resource ─────────┐│
│ │  Graph Nodes                 ▓▓▓░░░░░░░  [  150 ] of 500   nodes   ││
│ └────────────────────────────────────────────────────────────────────┘│
│                                                                        │
│ ┌─ Documents ───────────────────────────── none yet · 1 resource ────┐│
│ │  Document Pages                                        (no documents)││
│ └────────────────────────────────────────────────────────────────────┘│
│                                                                        │
│  3 changes pending                        [ Discard ]  [ Review & Save ]│
└────────────────────────────────────────────────────────────────────────┘
```

Five things that layout is doing on purpose:

- **The tier is in the header**, with its label and its index. Every ceiling on the page derives
  from it, and *"of 10"* is meaningless without it.
- **`updatedAt` is in the header**, not hidden. It is what [07](07-save-semantics.md)'s
  concurrency check compares, and an operator who can see it moving knows to reload.
- **The ceiling is beside the counter, not in a tooltip.** *"3 of 10"* is the sentence the
  operator came to read.
- **`--Example_NAME--` cards collapse to a one-line explanation.** Rendering fifteen zeroed
  counters for a domain that does not exist is noise that looks like data.
- **Deep-scope containers get a card even when empty** (§4.7). A member with no documents should
  see that documents exist and they have none; omitting the card reads as a form that forgot.
  Stores render *inside* their domain's card, never at top level.
- **Save is `Review & Save`.** There is a diff step ([07](07-save-semantics.md) §7.3); the button
  says so.

## 4.3 A row

```
  Payment Processors            ▓▓▓░░░░░░░  [    1 ] of 3   processors
  └ label                       └ gauge     └ input  └ceiling └ unit
```

| Element | Behaviour |
|---|---|
| **Label** | `entry.label`. `entry.description` on hover — it is written and currently unused |
| **Gauge** | `used / limit`. Amber at `warn_at_pct`, red at 100%. **Hidden entirely when the limit is `-1`** — a progress bar against infinity is a decoration that implies a ceiling |
| **Input** | Integer, `min=0`, no max. Editing usage above the ceiling is legal and sometimes correct (a wrong `consume` really did run); the row shows red and saves |
| **Ceiling** | Read-only, from the tier. Clicking it opens the override control ([05](05-limit-overrides.md)) — the one place "give them more" lives |
| **Unit** | `entry.unit`, verbatim: `processors`, `kilobytes`, `payment_links` |

### The states a row can be in

| State | Renders |
|---|---|
| Normal | as above |
| `enabled: false` at this tier | *"Not available on Free"* in place of the ceiling, input **disabled**. A limit of 0 and a feature that does not exist are different facts ([../allocation.md](../allocation.md) §10) |
| `limit: -1` | *"Unlimited"*, no gauge, input still editable |
| Over the ceiling | ceiling in red, `used > limit` — the member is blocked; this is the row the operator came for |
| Counter/nested disagree | ⚠ with **both** values and a `[details]` link ([03](03-the-data-model.md) §3.5) |
| No entry in Parse | `0`, in muted text, marked *"not yet provisioned"*. Editing it creates the entry |
| Overridden | ceiling shown as *"3 → **8** (override)"* with the reason on hover |

## 4.4 Controls, by field

| Field | Control | Rule |
|---|---|---|
| `used` | number input | integer ≥ 0. Rejects `"abc"`, `1.5`, `-1` — none of which the JSON editor currently rejects (**BR19**) |
| `periodStart` | date picker | Accepts both encodings, writes an ISO string ([03](03-the-data-model.md) §3.6). Constrained to a period boundary: for `monthly`, the 1st at 00:00 **UTC** |
| domain `name` | text, **read-only after creation** | It is the lookup key (`alloc_v2.ts:1306`). Renaming orphans every counter under it. Renaming is delete-plus-create, and the form says so |
| domain `status` | select | `1` active. Values beyond that are not defined anywhere in the client; the control offers what the data contains and does not invent a vocabulary |
| domain `createdAt` | read-only | |
| project `name` | text, read-only after creation | same reason as domain |
| `objectId` / `createdAt` / `updatedAt` / `ACL` | **not rendered** | Stripped on save today, so showing them is a lie (**BR22**). `updatedAt` appears in the header as a fact, not a field |

## 4.5 The period sub-line

Monthly and daily resources get a second line:

```
  ↳ monthly · resets 1 Sep · window opened 1 Aug          [reset now]
```

- **"resets"** is computed the way the client computes it — UTC month boundary
  (`alloc_v2.ts:588`) — and rendered in the operator's local time with the UTC instant on hover.
- **`[reset now]`** sets `used = 0` and `periodStart` to the current period start. That is the
  single most common legitimate edit on this screen and it should not require typing a zero and
  picking a date.
- If `periodStart` is **already in a previous period**, the row shows *"window expired — reads as
  0"* and the input greys, because `periodHasRolledOver()` returns 0 regardless of the stored
  number. Letting an operator carefully set a value that the client ignores is the worst thing
  this form could do.

> ### ⚠ BR26 — `periodHasRolledOver()` cannot read the encoding this screen sees
>
> Found while building §4.1 (2026-08-28). The function does:
>
> ```ts
> const started = periodStart instanceof Date ? periodStart : new Date(String(periodStart));
> if (Number.isNaN(started.getTime())) return false;
> ```
>
> It handles a `Date` and an ISO string. It does **not** handle Parse's
> `{ __type: 'Date', iso: … }` encoding — `String()` on that yields `"[object Object]"`, the
> Date is Invalid, and the guard returns **`false`**: *never rolled over*, for every value.
>
> That encoding is precisely what this screen receives. The provisioning template stores it,
> and `registryObj.toJSON()` — which the allocations view calls — serialises a Parse Date back
> into it. The live `alloc_v2` path reads through `Parse.Object.get()`, which hydrates a real
> `Date`, so **it never meets this**, which is why the gap has gone unnoticed.
>
> The form normalises before calling (03 §3.6 already requires it to accept both encodings), so
> this is closed for this screen. It is left recorded because **anything else that passes
> `toJSON()` output into `periodHasRolledOver()` gets a silent wrong answer** — and the wrong
> answer is "the window is still open", which reads last period's total as current.

## 4.6 Domain and project cards

> **NOT BUILT (2026-08-28).** The cards render; `[+ add domain]` and `[remove]` do not exist —
> there is no `addDomain`/`removeDomain` in `brain_membership.ts`. The `ac_*` cleanup below is
> the part that must not be skipped when it is built: a removed domain whose flat counters
> survive brings them back as the authority if the domain is ever re-added.

**Add.** `[+ add domain]` takes a name and appends `{ name, status: 1, createdAt: now,
resources: {} }`. Counters are created on demand, so an empty `resources` is correct and matches
what `alloc_v2` does.

**Remove.** Deletes the array entry **and every `ac_*` field whose scope token matches it** —
otherwise the flat counters outlive the container and reappear as the authority if the domain is
re-added ([03](03-the-data-model.md) §3.5). This is the strongest argument for deriving field
names through `counterField()` rather than by hand.

**`--Example_NAME--`.** Recognised by exact string match, collapsed to one line, offered for
removal. Not auto-removed: this screen does not delete data the operator did not ask it to, and
a member could conceivably have a real site by that name — improbably, but the cost of asking is
one click.

## 4.7 The deep-scope cards

> **Rewritten 2026-08-28.** This section used to be called *"The unscoped section"* and
> described four resources as read-only and inert, with *"no container in the storage shape, no
> scope key in the client"*. **That is no longer true.** Registry v4 declares seven scopes and
> the provisioning template now carries a container for each; the template was regenerated from
> the registry on 2026-08-27 (`03` completion doc). Rendering them as inert would misreport
> four resources as broken when they have homes.

Four scopes sit below the member/domain/project level, and each gets a card of the same shape as
§4.6's — one instance per thing the member owns:

| Scope | Container | Resource | Card is one per… |
|---|---|---|---|
| `per_graph` | `dreamgraph_graphs` | `dreamgraph_graph_nodes` | graph |
| `per_document` | `pdf_editor_documents` | `pdf_editor_document_pages` | document |
| `per_design_project` | `design_flow_projects` | `design_flow_project_assets` | design project |
| `per_store` | `domains[].stores` | `domain_ecommerce_items_per_store` | store — **nested inside its domain's card**, not at top level |

`per_store` is the one that differs: it has no top-level container because a store belongs to a
domain, so its rows render inside the domain card that owns it (§4.6). That nesting is what
registry v4 fixed — filed flat, one item allowance was shared across every store on a domain
(**BR10**).

**A container with no elements renders as an empty card with its resource names greyed**, not as
a missing section. A member with no graphs should see that graphs exist and they have none —
a form that omits the card entirely reads as a form that forgot.

## 4.8 Bulk actions, deliberately few

> **NOT BUILT (2026-08-28).** Neither action exists. Both are conveniences over edits an
> operator can already make by hand, so nothing is blocked — but §4.5's `[reset now]` is built
> and is the per-row version of the first one, which is the more common need.

| Action | Scope |
|---|---|
| **Reset all monthly counters** | this member, every `period: monthly` row across every container |
| **Zero this domain's counters** | one domain card |

Both are conveniences over edits the operator could make by hand, both produce a normal diff
that must be confirmed ([07](07-save-semantics.md) §7.3), and both require a reason
([02](02-security-and-access.md) §2.6) because both decrease counters.

**No "reset everything" and nothing that crosses members.** A button whose blast radius is a
whole account is a button that will eventually be clicked by accident, and cross-member work is
a job with a dry run, not a control on a detail page ([00](00-overview.md) §0.3).

## 4.9 Finding a row

34 resources across N+M+2 cards is a lot of page. Three filters, all client-side:

- **Search** — matches the label, the resource id and the unit, so both *"processors"* and
  *"domain_processors_count"* find the row.
- **Hide zero-usage** — on by default. Most rows are 0 for most members, and the ones that are
  not are why the operator opened the page.
- **Show overrides only** — [05](05-limit-overrides.md).

Filters never hide a row with **pending changes** or a row in a **warning state**. A filter that
can hide an unsaved edit is a filter that loses one.

## 4.10a State — what is built, and what is not

Audited 2026-08-28 against `brain_membership.ts`, where this step is implemented.

**Built and verified** — generation from registry v4, all 34 resources across all seven scopes,
card grouping, per-store nesting inside its domain, empty-container cards, the seven §4.3 row
states, the §4.5 period sub-line with `[reset now]`, §4.9's three filters, BR19's integer
validation, and the draft/diff model §4.2's *Review & Save* consumes.

**Three defects found during that audit and fixed:**

| # | Defect | Why it mattered |
|---|---|---|
| **1** | Four of seven scopes fell through to *"Unscoped / Unsupported"* with `used: 0` hardcoded, and `getResourceEntry()` returned `null` for them | Four resources reported as broken when they have homes. **The code was faithful to this document** — §4.7 said they were inert, which was true under v3 and stopped being true when the template was regenerated from v4 |
| **2** | **BR26** — `periodHasRolledOver()` returned `false` for every value | *Never rolled over*, always: an expired window renders as live and the operator sets a number the client ignores, which §4.5 calls the worst thing this form could do |
| **3** | **BR25** — the `ac_*` counters were never read | `03` §3.5 calls it *"the finding that decides what the form has to write"*. The form showed the projection, so a correction to it is a silent no-op |

**Not built:**

- §4.6 `[+ add domain]` / `[remove]`, and the `ac_*` cleanup a removal requires
- §4.8 both bulk actions
- §4.4's domain-name read-only-after-creation rule and its explanation

**Not verified** — reasoned from the code, not observed running, because each needs a live Parse
object and a browser: a real member end to end; which of `ac_*` / nested actually exists in
production (the form handles both, so it is correct either way — `03` §3.5); `[reset now]`
writing both fields in one edit; and filters never hiding a row with an unsaved change.

**Carried forward:** `alloc_v2.ts` now exports `counterField`, `scopeToken` and
`periodHasRolledOver` so this form derives names through the one implementation (§4.6). It is a
synced file — `sync-checkpoint-client.sh` must run or the other eight apps never see them. The
local `periodHasRolledOver` copy in `brain_membership.ts` should become an import; two copies of
one rule is **BR11** in miniature, and the copy is why BR26 existed in two places at once.

## 4.10 Acceptance

- All **34** registry resources render — 14 member, 15 per-domain, 1 per-project, and one each for per_store, per_graph, per_document and per_design_project. **None are unscoped**: registry v4 declares seven scopes, so the form needs a container for each (verified 2026-08-27).
- Adding a resource to `checkpoint_registry_v4.json` makes it appear with **no form code
  change** (§4.1).
- A tier-2 member shows ceiling **3** and label **"Professional"**; a tier-0 member shows *"Not
  available on Free"* for `domain_processors_count` with the input disabled.
- A `-1` ceiling shows *"Unlimited"* and **no gauge**.
- A member with three domains renders **three** domain cards, each with its own fifteen rows,
  and a domain holding two stores renders those two **nested inside it** (§4.7).
- A container with no instances renders an **empty card**, not a missing section (§4.7).
- `[reset now]` sets usage to 0 and `periodStart` to the current UTC period start, in one edit.
- A row whose `periodStart` is in a past period is **disabled** with *"window expired"* (§4.5).
- Removing a domain also removes its `ac_*` fields, and re-adding it starts at zero (§4.6).
- A `--Example_NAME--` card collapses to one line and is not counted in the member's domains.
- Typing `abc`, `1.5` or `-1` into a usage input is **rejected before save** (**BR19**).
- A domain name is not editable after creation, and the form explains why (§4.4).
- `objectId`, `createdAt`, `updatedAt` and `ACL` appear **nowhere as fields** (**BR22**).
- Filtering never hides a row with an unsaved change (§4.9).
