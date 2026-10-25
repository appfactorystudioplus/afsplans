# 01 — Current State Audit

Everything in the path between an operator clicking **Allocations** and a number changing in
Parse, and the 25 findings.

**BR25** is defined in [03](03-the-data-model.md) §3.5 rather than here, because it only makes
sense once the `ac_*` counter mechanism has been described — and it is the finding that decides
what this feature has to write.

## 1.1 What exists

### The screen

| File | What |
|---|---|
| `www/resources/js/portal/admin/views/brainView.ts:268` | Registers the sub-view `brain:memberships:allocations` at `/brain/memberships/allocations` |
| `…/html/views/html/modules/brain/brain_memberships_allocations_content.html` | The template — a card with `#allocations_viewer_container` and a spinner |
| `…/controllers/brain/brain_membership.ts:1176` | `initMembershipAllocationsView()` — 110 lines, the whole feature |
| `…/controllers/brain/BrainController.ts` | `getMember()` — proxies `GET /portal/api/v1/brain/member` |
| `www/resources/js/registry.ts:57` | `RegistryMemberManager` — the Parse client the screen uses |

`initMembershipAllocationsView()` in full: read `member.parse_alloc`; if absent, render a
warning; otherwise call `registryManager.getMemberRegistry()`, `toJSON()` the result into a
`vanilla-jsoneditor`, and wire one **Save Changes** button to `saveMemberRegistry()`.

### The data path

```
Allocations tab
  └─ RegistryMemberManager.getMemberRegistry(member, cb)      registry.ts:70
       └─ new Parse.Query('MemberRegistry').get(member.parse_alloc)
  … operator edits JSON …
  └─ RegistryMemberManager.saveMemberRegistry(member, data, cb)   registry.ts:84
       └─ query.get(parse_alloc) → registryObj.save(cleanData)     registry.ts:257
```

Parse is configured in the `RegistryMemberManager` **constructor** (`registry.ts:62`), which
`main.ts:535` runs on every portal boot.

### Where the registry object comes from

Not from this screen. A member's `MemberRegistry` is created during membership creation:

```
brain_membership.ts:224   await createParseAllocation()
  └─ afs/memberships/index.ts:309
       └─ POST /portal/api/v1/checkpoint/member/init
            └─ www MemberInitController (cURL proxy)
                 └─ auth MemberInitController::init()      auth/…/Checkpoint/MemberInitController.php:50
                      ├─ reads public/checkpoint/parse_member_query_v4.json
                      ├─ rewrites every periodStart to this UTC month
                      └─ writes it verbatim as a new MemberRegistry (master key)
```

**That last step is the single most important fact in this document** and it is covered in
§1.3.

## 1.2 Security — the gate

| # | Finding | Consequence |
|---|---|---|
| **BR1** | The Brain admin API is **unauthenticated**. `Route::prefix('api/v1/brain')` (`routes/portal.php:273`) is inside `Route::middleware('portal.cors')`, and `PortalCorsMiddleware::handle()` is `return $next($request);` with a docblock saying CORS is Nginx's job | Every route in that group is open. It contains the member lookups, the **DNS server and zone management** routes, and the HTTP tenant routes |
| **BR2** | `GET /portal/api/v1/brain/members` is in that group | One unauthenticated request enumerates **every member on the platform** |
| **BR3** | The only gate on this section is `main.ts:596`: `if (app.window.member?.level == 99) { brainNav.style.display = 'block'; }` | It sets a **CSS property on a sidebar link**. The view is registered for everyone, the route resolves for everyone, and the API answers everyone. It is a menu, not a permission |
| **BR4** | **No audit trail exists for any registry write.** `SaveRegistry()` writes and returns a boolean | This screen edits *other members'* quotas. "Who zeroed this member's usage, and when" has no answer — not a poor one, none |

**BR1 and BR3 together are the finding.** The section is treated as protected because it is
hidden, and it is hidden by a line of CSS.

## 1.3 The provisioning template

| # | Finding | Consequence |
|---|---|---|
| **BR5** | **`parse_member_query_v4.json` is not an example — it is the live provisioning template.** `MemberInitController::init()` reads it from disk, refreshes `periodStart`, and writes it verbatim | Every property of that file is a property of every member's registry. Editing it is a schema change to the whole platform, made in a file whose name says "query" |
| **BR6** | The template's placeholder rows are written as **real data**. `domains[0].name` is the literal string `"--Example_NAME--"`, and so is `pm_projects[0].name` | **Every member ever created has a domain called `--Example_NAME--`** and a project of the same name, holding a full set of zeroed counters. The form will render a card for it unless something decides not to |
| **BR7** | **Partly fixed by registry v4, and re-opened by it.** The ten originally missing — `notes_items_count`, `notes_notebooks_count`, `dreamgraph_graphs_count`, `dreamgraph_graph_nodes`, `pdf_editor_documents_count`, `pdf_editor_document_pages`, `design_flow_projects_count`, `design_flow_project_assets`, `afs_connect`, `secondary_users` — are all present in `parse_member_query_v4.json`. It now covers **29 of the registry's 34**, missing the five v4 added: `domain_chat`, `domain_discussions`, `domain_feedback`, `domain_listings`, `domain_sponsorships` (verified 2026-08-27) | Ten resources have no bucket at provisioning time. They read as 0, which is right, but the template is the shape contract and it does not describe the shape |
| **BR8** | `periodStart` is written by the server as a Parse date — `{"__type":"Date","iso":"…"}` — and by the client as a **plain ISO string** (`alloc_v2.ts:588`, `currentPeriodStart()` returns `.toISOString()`) | Both are readable, so nothing breaks. But the stored type changes the first time a client rolls a period over, so one member's JSON shows an object where another's shows a string. To an operator reading raw JSON that looks like corruption, and any form control has to accept both |

## 1.4 Scopes, and the ids that describe them

| # | Finding | Consequence |
|---|---|---|
| **BR9** | The registry declares **seven** scopes — `member`, `per_domain`, `per_project`, `per_store`, `per_graph`, `per_document`, `per_design_project`. The storage shape has **three** containers, and `alloc_v2`'s scope type accepts **two** keys plus member (`counterField()`, `alloc_v2.ts:623`) | Four resources have nowhere correct to be stored and no way to be consumed: `domain_ecommerce_items_per_store`, `dreamgraph_graph_nodes`, `pdf_editor_document_pages`, `design_flow_project_assets` |
| **BR10** | ~~`parse_member_query_v4.json` files `ecommerce_items_per_store` under `domains[].resources`~~ **FIXED in registry v4** (verified 2026-08-27) | v4 nests a `stores[]` array inside each domain and files the resource there, so each store now has its own counter. The original finding stands as the reason: stored per domain it enforced *one* item allowance shared across every store, which is not the limit the pricing description states |
| **BR11** | **Worse than first recorded** (verified 2026-08-27). Three hand-maintained lists of the same resource ids exist and **all three have now drifted from the registry in the same direction**: every one is missing the five resources v4 added — `domain_chat`, `domain_discussions`, `domain_feedback`, `domain_listings`, `domain_sponsorships`. `RegistryResourceIds` (`registry.ts:103`) additionally carries a phantom `domain_ecommerce`; `parse_member_query_v4.json` covers 29 of 34 (**BR7**); `AllocV2Resources` covers 29 of 34 with no phantoms | When the registry gained five resources, **none of the three followed**. `AllocV2Resources` is still the cleanest and the list to build on, but it is no longer a parity reference. This is the argument against writing a fourth by hand, and it is now demonstrated rather than predicted |
| **BR12** | `conventions.tier_resolution` in the registry says *"Tiers are resolved by name. Use the 'index' field to map a numeric tier rank to its name key."* **There is no `index` field**, and resource `tiers[]` entries carry only `{value, enabled}` — positionally | The convention text describes a v2 shape. Anyone building tier resolution from the file's own documentation will look for a field that is not there and guess. Tiers are **positional**; the top-level `tiers[]` supplies labels by the same index ([../allocation.md](../allocation.md) §2.1) |

## 1.5 Parse configuration

Three copies of one application id, and they do not agree.

| Where | Value |
|---|---|
| `auth/.env:89` `PARSE_APP_ID` — the server that **creates** every registry | `1PM4yMxbQ0ihyNObm4uQAsG4Zkpl7ZLH5lyquDMT` |
| `www/resources/js/registry.ts:62` — hardcoded, used by the Allocations tab | `1PM4yMxbQ0ihyNObm4uQAsG4Zkpl7ZLH5lyquDMT` |
| `www/.env:209` `CHECKPOINT_APP_ID` — used by `createAllocV2()`, i.e. **all of alloc_v2** | `1PM4yMxbQ0ihyNObm4uQAsG4Zkpl7ZLH5lyquDMT`**`22`** |

| # | Finding | Consequence |
|---|---|---|
| **BR13** | `www/.env:209` carries a trailing **`22`** that neither other copy has | Every `alloc_v2` call in `www` authenticates against an application id the server does not know. This is the *"`.env.prod` Parse app-id mismatch"* noted in [../afs-connect/13](../afs-connect/13-step-60-findings-and-execution-plan.md) §318, with the values attached. **Fix the `.env`, not the code** — the hardcoded value is the correct one |
| **BR14** | The correct value reaches Parse by being **hardcoded in a `.ts` file** (`registry.ts:62`) rather than by configuration | It works today by coincidence. It cannot differ per environment, and it puts the id in the bundle and in version control |
| **BR15** | `registry.ts:62` passes `"YOUR_JAVASCRIPT_KEY"` as the JavaScript key | A placeholder. `alloc_v2.ts:449` makes the second argument genuinely optional for this reason; this call site did not |
| **BR16** | **Two independent `Parse.initialize` paths.** `alloc_v2.ts:424` `ensureParseInitialised()` guards against conflicting initialisation using its own module-level `parseInitState` — but `registry.ts:62` writes the global Parse configuration directly, without going through it | The guard cannot see the other writer, so the conflict it exists to catch is exactly the one it misses. On a portal boot both run and the later call wins for both, silently |

## 1.6 The save path

| # | Finding | Consequence |
|---|---|---|
| **BR17** | `SaveRegistry()` (`registry.ts:257`) is a **blind full-object overwrite**: `registryObj.save(cleanData)` with every top-level key from the editor buffer | An editor opened ten minutes ago holds a ten-minute-old snapshot. Saving it reverts every consume since — invisibly, with a green "Changes saved successfully!" |
| **BR18** | **No concurrency check.** `updatedAt` is read into the editor and stripped before save; nothing compares it | The lost update above is undetectable, by anyone, afterwards |
| **BR19** | **No validation of any kind.** `used: "abc"`, a negative counter, a deleted `member_resources`, an unknown resource id — all save identically | The one screen that can corrupt a member's quota has fewer guardrails than the form that creates them |
| **BR20** | `GetMemberRegistry()` (`registry.ts:136`) is `query.get(...).then(cb)` with **no `.catch` and no error callback** | A failed fetch never calls back. The view's own failure branch (`if (!registryObj)`, `brain_membership.ts:1205`) is therefore **unreachable**, and the screen sits on its spinner permanently. Given **BR13**, a Parse auth failure is not hypothetical |

## 1.7 The view

| # | Finding | Consequence |
|---|---|---|
| **BR21** | A member with no `parse_alloc` gets *"No Parse allocation ID found for this member"* and **no way to create one**. `createParseAllocation()` exists and is wired only into the membership **creation** flow (`brain_membership.ts:224`) | The one situation where an operator most needs to act has no action |
| **BR22** | The editor renders `objectId`, `createdAt`, `updatedAt` and `ACL` — which `SaveRegistry()` then silently strips | Four fields that look editable and are not. `updatedAt` in particular is the field that would make **BR18** solvable, presented as decoration |
| **BR23** | `registry.ts:307` holds a **second, dead** `createParseAllocation()` that writes the **v2 shape** — `domains: 0`, `domain_processors: {default: 0}`, `domain_sponsorships` | Reachable only through the unused static `RegistryMemberManager.createParseAllocation()`. Harmless today; a landmine for anyone who finds it and calls it, because it provisions a shape nothing reads |
| **BR24** | The level-99 bypass in `RegistryUpdate()` and `RegistryCheck()` is **commented out** (`registry.ts:150`, `:178`) | Someone started it and stopped. [../allocation.md](../allocation.md) §6 specifies where it belongs — the factory — and these two methods are legacy paths that `alloc_v2` supersedes anyway |

## 1.8 What is genuinely good

- **`vanilla-jsoneditor` was the right call.** It is already a dependency, it handles large
  documents, and it is a better escape hatch than a `<textarea>`. Keeping it is rule 1.
- **`member.parse_alloc` is a clean handle.** One id, on the member record, addressing one
  object. Nothing about the data path is convoluted.
- **`AllocV2Resources` was in exact parity with the registry** — 29 for 29 when this audit was taken; it is **29 of 34** now, missing the five resources v4 added (verified 2026-08-27). Somebody kept it
  current, and it is the reason [04](04-form-generation.md) can generate rather than enumerate.
- **`alloc_v2.ts` is careful code.** `ensureParseInitialised()`, the `ac_*` atomic counters,
  `periodHasRolledOver()`, `validateResourceId()` — the newer client anticipates most of what
  this audit found in the older one. Several findings here are *"the old path never learned what
  the new path knows"*.
- **Server-side provisioning through `MemberInitController` is the right shape**: master key,
  server side, one endpoint. The template it reads is the problem, not the mechanism.
- **The tab structure already exists.** Six tabs, consistent markup, a working sub-view router.
  This plan adds to one panel; it does not restructure navigation.

## 1.9 A stale note elsewhere

[../alloc-v2-parse-direct/01-current-state-audit.md](../alloc-v2-parse-direct/01-current-state-audit.md)
§1.2 describes `RegistryMemberManager` as *"scratch code … not wired into any application"*,
confirmed with the author.

That is no longer true. It is constructed on every portal boot (`main.ts:535`), used for the
"Not Authorized" dialog in Direct Payments (`directpayments.ts:160`), and is **the entire data
path for the screen this plan is about** (`brain_membership.ts:1204`, `:1257`).

It should be corrected there, because the description is what makes it look safe to delete.

## 1.10 Summary

| Group | Findings | Phase |
|---|---|---|
| Security and access | **BR1**–**BR4** | **P0** — ships first, depends on nothing |
| Parse configuration | **BR13**–**BR16** | **P0** — one `.env` character, and the initialisation path |
| Provisioning template | **BR5**–**BR8** | **P1** — the shape everything else reads |
| Scopes and id drift | **BR9**–**BR12** | **P1**, surfaced by the form in **P2** |
| The save path | **BR17**–**BR20** | **P3** — [07](07-save-semantics.md) |
| The view | **BR21**–**BR24** | **P2**, **P4** |
| The counter that is actually read | **BR25** ([03](03-the-data-model.md) §3.5) | **P3** — the pairing in [07](07-save-semantics.md) §7.5 |

Two of these are worth fixing before anything is built on top. **BR13** is a two-character edit
to a `.env` that currently disables allocation across `www`. **BR1** is the reason none of the
rest of this plan is safe to ship: a better console for editing other people's quotas, in front
of an API that never asks who is calling, is a larger hole rendered more conveniently.
