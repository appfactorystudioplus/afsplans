# members_brain — Planning Index

Turning the Brain member **Allocations** tab from a raw JSON textarea into a real allocation
console: keep the JSON editor, add a generated form that knows every resource, every scope and
every tier ceiling, and make saving something other than a blind overwrite.

Scope is one screen — `brain:memberships:allocations`, rendered by
`initMembershipAllocationsView()` (`brain_membership.ts:1176`) — and the data path behind it.

> ### 🔴 Five things are true today, and the first one gates everything
>
> **1. The entire Brain admin API is unauthenticated.**
> `Route::prefix('api/v1/brain')` (`routes/portal.php:273`) sits inside
> `Route::middleware('portal.cors')`, and `PortalCorsMiddleware::handle()` is
> `return $next($request);`. That group contains `GET /members` — **every member on the
> Brain** — plus `GET /member`, the DNS server management routes and the HTTP tenant routes.
> The level-99 check that hides this section is `main.ts:596`, and it hides a **sidebar link**.
>
> **2. Two different Parse application ids are used on one page load.**
> `registry.ts:62` hardcodes `"1PM4yMxbQ0ihyNObm4uQAsG4Zkpl7ZLH5lyquDMT"` in the
> `RegistryMemberManager` constructor. `www/.env:209` says
> `CHECKPOINT_APP_ID="1PM4yMxbQ0ihyNObm4uQAsG4Zkpl7ZLH5lyquDMT22"` — the same string **plus
> `22`** — and that is what `createAllocV2()` uses. One of them is wrong, and the Allocations
> tab reads Parse through the hardcoded one.
>
> **3. Saving is a blind full-object overwrite.**
> `SaveRegistry()` (`registry.ts:257`) does `registryObj.save(cleanData)` with whatever the
> editor holds. No merge, no `updatedAt` check, no validation. An editor opened ten minutes ago
> silently reverts every consume since.
>
> **4. `parse_member_query_v4.json` is not an example — it is the live provisioning template,**
> and its placeholders become real rows. `MemberInitController::init()` reads that file and
> writes it verbatim as every new member's registry, so **every member ever created carries a
> domain named `--Example_NAME--`** and a project of the same name. The template is also missing
> ten of the registry's twenty-nine resources.
>
> **5. And the one that decides what this feature has to write.**
> `alloc_v2` keeps the real counter in a **flat `ac_*` field**, and its own source says so:
> *"the top-level counter is the authority, and `readUsage` prefers it"* (`alloc_v2.ts:1329`).
> Every hand-editing surface — this screen included — edits the **nested `used`** projection
> instead. So correcting a counter here changes nothing the member experiences, silently
> ([03](03-the-data-model.md) §3.5, **BR25**).
>
> See [01-current-state-audit.md](01-current-state-audit.md). Phase **P0**
> ([02](02-security-and-access.md)) closes 1 and 2 and depends on nothing else here.

Read in this order:

| Step | Document | Contents |
|---|----------|----------|
| — | [README.md](README.md) | This index |
| 00 | [00-overview.md](00-overview.md) | What this is for, goals, non-goals, glossary, and the six decisions that shape everything after |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | Every file, field and route in the path, and the 25 findings |
| 02 | [02-security-and-access.md](02-security-and-access.md) | **P0** — the open Brain API, the app-id mismatch, who may edit whose allocation, and the audit trail |
| 03 | [03-the-data-model.md](03-the-data-model.md) | The v3 shape, the **seven** scopes, what `parse_member_query_v4.json` actually is, and the ten resources it omits |
| 04 | [04-form-generation.md](04-form-generation.md) | The form is **generated**, not written — registry × shape, and what each control is |
| 05 | [05-limit-overrides.md](05-limit-overrides.md) | Usage is not the ceiling. The per-member override layer that makes "give this customer 5 more" possible |
| 06 | [06-editor-modes.md](06-editor-modes.md) | JSON ↔ Form, one state, round-trip fidelity, and what happens when the JSON is not form-representable |
| 07 | [07-save-semantics.md](07-save-semantics.md) | Field-level merge, optimistic concurrency, validation, and the diff the operator confirms |
| 08 | [08-rollout-and-testing.md](08-rollout-and-testing.md) | Phases, the gate, the test matrix |

## Completion documents

Each numbered document gets a completion document in [`complete/`](complete/) — source basename
plus `.complete.md`. Include status, summary, files changed, verification steps and follow-up
work.

## Code documentation standard

Document all new and changed code thoroughly with block comments — PHPDoc for PHP, JSDoc for
TypeScript. Every file, exported symbol, public function, route handler and non-trivial type
must explain purpose, parameters, return values, side effects and plan references.

New code carries **`AFS_BRAIN_ALLOC_MODIFIER_ADDITION`** and **`AFS_ALLOC_V2_TAG`**, both
registered in [../markers.md](../markers.md). Where a file already has a marker, **add
alongside; never replace.**

## The five rules this plan is built on

**1. The JSON editor stays.** It is the escape hatch. Any generated form has a shape it cannot
express, and the moment an operator meets one, the raw editor is the difference between a
support call and a fix. [06](06-editor-modes.md) makes the two views one state rather than two
screens.

**2. The form is generated from `checkpoint_registry_v4.json`, never hand-written.**
The registry is the catalogue of what exists — 34 resources, each with a scope, a period, a
unit and five positional tier ceilings. A hand-written form is a second catalogue that starts
drifting the day a resource is added. `parse_member_query_v4.json` supplies the *shape*, not the
list; it is missing ten of the twenty-nine ([03](03-the-data-model.md) §3.4).

**3. Usage and limits are different things and live in different places.**
Parse holds `used`. The registry holds the ceiling, by tier. Editing Parse can only ever change
what a member has *consumed* — not what they are *allowed*. Anything that reads as "give this
member more" needs the override layer in [05](05-limit-overrides.md), or it is not what the
operator thinks they clicked.

**4. Every save is a merge and a diff.**
The operator sees exactly what will change before it changes, the write touches only the fields
they touched, and a concurrent consume is detected rather than trampled
([07](07-save-semantics.md)).

**5. Every write is attributed.**
This screen edits *other people's* quotas from an account that bypasses its own
([../allocation.md](../allocation.md) §6). "Who set this member's usage to zero, and when"
must have an answer that is not a guess ([02](02-security-and-access.md) §2.5).
