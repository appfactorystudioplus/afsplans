# 00 — Overview

## 0.1 What this is for

A platform operator opens **Brain → Memberships → a member → Allocations** for one of three
reasons:

1. *"They say they can't create another processor — what does their registry actually say?"*
2. *"Their counter is wrong because a delete didn't release. Fix it."*
3. *"We agreed to give this customer 5 extra Direct Payments without moving them a tier."*

Today the screen answers **(1)** badly and **(2)** dangerously, and **(3)** is not possible at
all — there is nowhere in the data model to put a per-member ceiling.

The screen currently renders `registryObj.toJSON()` into a `vanilla-jsoneditor` and, on Save,
writes whatever is in the buffer back over the whole object. That is a competent 90-minute
implementation of a debugging tool, and it is being used as an administrative surface.

This plan keeps it — a raw editor is the right escape hatch — and puts a real one beside it.

## 0.2 Goals

- **Keep the JSON editor**, unchanged in capability, as one of two modes on one state.
- **Add a generated form** covering every resource the registry declares, laid out by scope,
  showing usage against the member's actual tier ceiling.
- **Make the ceiling visible.** *"3 of 3 processors, Professional"* is the sentence the
  operator is trying to read. Today they read `{"used": 3}` and go and look up the tier
  themselves.
- **Make "grant this member more" possible** without moving their tier
  ([05](05-limit-overrides.md)).
- **Stop the overwrite.** Field-level merge, concurrency detection, a diff the operator
  confirms.
- **Attribute every write.** Who, when, what changed, why.
- **Close the open Brain API** in front of all of it.

## 0.3 Non-goals

| Not building | Why |
|---|---|
| Editing the tier ceilings themselves | Those live in `checkpoint_registry_v4.json`, are versioned with the deployment, and apply to every member on that tier. Editing them from a member's detail page is a pricing change wearing a support ticket's clothes. Per-member deviation is [05](05-limit-overrides.md) |
| Server-side enforcement of allocation | The store is publicly writable and there are no Cloud Functions ([../allocation.md](../allocation.md) §11). That is [../alloc-v2-parse-direct/](../alloc-v2-parse-direct/)'s work. This plan makes the *console* honest; it does not make the *limit* real |
| A registry editor for the JSON file | A separate tool with a separate blast radius. Out of scope |
| Bulk operations across members | "Reset every member's monthly counters" is a scheduled job, not a button on one member's page ([08](08-rollout-and-testing.md) §8.6) |
| Replacing `vanilla-jsoneditor` | It works, it is already a dependency, and rule 1 says the raw editor stays |
| Fixing the four orphan scopes' storage | `per_store`, `per_graph`, `per_document` and `per_design_project` have no container in the data model and no client support ([03](03-the-data-model.md) §3.3). Naming that is in scope; designing their storage is not |

## 0.4 Glossary

| Term | Means |
|---|---|
| **Registry (the file)** | `auth/public/checkpoint/checkpoint_registry_v4.json` — the catalogue: 34 resources, their scope/period/unit, and five positional tier ceilings each |
| **Registry (the object)** | The member's `MemberRegistry` row in Parse, holding **usage**. One per member, addressed by `member.parse_alloc` |
| **`parse_member_query_v4.json`** | Despite the name, the **live provisioning template**: `MemberInitController::init()` reads this file and writes it verbatim as every new member's registry. Hand-maintained, and missing ten of the twenty-nine resources ([03](03-the-data-model.md) §3.4) |
| **Tier** | `brain_memberships.membership`, an integer 0–4, indexing `tiers[]` positionally |
| **Scope** | Which bucket a resource is counted in: `member`, `per_domain`, `per_project`, and four more with nowhere to live |
| **Ceiling / limit** | `resources[id].tiers[tier].value`. `-1` is unlimited; `enabled: false` means the feature does not exist at that tier |
| **Override** | A per-member deviation from the tier ceiling. Does not exist today — [05](05-limit-overrides.md) |
| **The operator** | A level-99 platform staff account. Not the member |

## 0.5 The six decisions

### D1 — The form is generated from the registry, never hand-written

`checkpoint_registry_v4.json` already declares every field the form needs: the resource id, a
human `label`, a `description`, a `unit`, a `scope`, a `period`, an `overage` behaviour, a
`warn_at_pct`, and five tier ceilings. A form component that reads that file renders all 34
resources — and 30 when someone adds one.

The alternative is 34 hand-written field groups that are correct on the day they are written.
The estate already has three separate hand-maintained lists of the same resource ids
(`AllocV2Resources`, `RegistryResourceIds`, `parse_member_query_v4.json`) and **two of the three
have already drifted** ([01](01-current-state-audit.md) §1.4). A fourth is not a good idea.

**`AllocV2Resources` (`checkpoint/resources.ts`) is the closest to parity, but it is no longer
in parity** — 29 of the registry's 34, no phantoms. It is missing `domain_chat`,
`domain_discussions`, `domain_feedback`, `domain_listings` and `domain_sponsorships`.

> **Verified 2026-08-27, and it sharpens the argument rather than weakening it.** All three
> lists are now missing **the same five resources** — `AllocV2Resources`,
> `parse_member_query_v4.json`, and `RegistryResourceIds`. When registry v4 gained five
> resources, none of the three followed. That is what a hand-maintained list does, and it is
> the reason this form is generated from the registry rather than becoming a fourth
> ([01](01-current-state-audit.md) **BR11**).

Use `AllocV2Resources` for the id constants — it is still the cleanest of the three — but use
the registry JSON for everything else, and expect to add the missing five to it.

### D2 — Usage and limits are different things, and the form must not blur them

Parse stores `used`. The registry stores the ceiling. The Allocations tab is looking at Parse,
so **everything it can currently edit is usage**.

This matters because "adjust this member's allocation" means the *opposite* of what the screen
does. An operator who wants to give a customer more room and instead sets `used` to 0 has
granted a one-time credit that the next consume starts eating, not a higher ceiling.

So the form shows both, in one row, visually distinct — the ceiling **read-only, from the
tier**, and the usage editable — and granting more room is a separate, explicit control backed
by the override layer ([05](05-limit-overrides.md)).

### D3 — JSON and Form are two projections of one state, not two screens

A toggle that swaps between two independently-held buffers loses edits, and which one wins on
Save becomes a coin flip.

One `registryDraft` object is the state. The form reads and writes paths into it; the JSON
editor reads and writes the whole of it. Switching modes re-renders from the draft; it never
copies between two buffers ([06](06-editor-modes.md) §6.2).

**The JSON editor is the wider surface.** It can express things the form cannot — an unknown
resource id, a hand-added field, a malformed date. Switching *to* the form when the draft holds
something the form cannot represent must **not** silently drop it ([06](06-editor-modes.md)
§6.4).

### D4 — Every save is a field-level merge against a re-read, behind a diff

`SaveRegistry()` today sends the whole object. Three separate failures follow from that, and
they are all invisible:

- a consume that happened while the editor was open is reverted;
- a field the operator never looked at is rewritten with a stale value;
- a typo in an untouched part of the JSON is saved with equal confidence.

Instead: track which paths the operator actually changed, re-read the object at save time,
refuse if `updatedAt` moved, and write only those paths. The operator confirms a diff first
([07](07-save-semantics.md)).

### D5 — The four orphan scopes are surfaced, not invented

The registry declares seven scopes. The storage shape has three containers, and the alloc_v2
client understands two scope keys plus member.

`domain_ecommerce_items_per_store` (`per_store`), `dreamgraph_graph_nodes` (`per_graph`),
`pdf_editor_document_pages` (`per_document`) and `design_flow_project_assets`
(`per_design_project`) therefore have **no correct place to be stored and no way to be
consumed**. `parse_member_query_v4.json` files the first of them under `domains[].resources`,
which enforces one items-limit per *domain* rather than per *store* — a different limit from
the one the pricing page describes.

The form renders them in a clearly-marked **"Unscoped — not enforceable"** section, read-only,
with the reason. It does not invent a `stores[]` container, because that is a data-model change
with reach into four other apps and it needs to be decided once, in
[../alloc-v2-parse-direct/](../alloc-v2-parse-direct/), not implied by a form layout.

Making the gap visible on the one screen an operator looks at is how it gets fixed. Hiding the
four resources would make the form look complete and be wrong.

### D6 — Per-member ceilings live on the Brain, never in Parse

"Give this customer 5 more" needs somewhere to put a per-member ceiling, and the obvious place —
an `overrides` map inside the `MemberRegistry` object, edited by this form like everything else —
is the wrong one. That class is **publicly writable** ([../allocation.md](../allocation.md) §11.2,
**G2**), so a member could grant themselves an unlimited ceiling from the browser console.

A forged *usage* is transient and shows against `count(*)` in any reconciler. A forged **ceiling**
is durable, reconciles against nothing, and is indistinguishable from a legitimate support grant.

So overrides are a MySQL table on the Brain, delivered alongside the tier and applied at the same
point in the client — which is also where [../billing/09](../billing/09-entitlement-integration.md)
§9.4 says entitlement belongs. [05](05-limit-overrides.md).

## 0.6 What "done" means for this screen

An operator can open a member, see in one screen every resource that member has, what they have
used, what their tier allows, and which counters are near their ceiling; correct a wrong counter
and know exactly what they changed; grant a documented exception without moving the member's
tier; and drop into raw JSON the moment the form cannot express what they need — with no risk
that pressing Save reverts somebody's afternoon.
