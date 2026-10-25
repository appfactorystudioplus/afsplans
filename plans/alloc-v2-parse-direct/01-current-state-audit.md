# 01 — Current State Audit

> ### ⚠ Historical — re-verified 2026-08-31
>
> This audit was taken before doc 02 shipped and before the storage model changed twice. The
> structural findings still explain *why* the design is what it is, but several statements are
> no longer descriptions of the estate:
>
> | Section | Then | Now |
> |---|---|---|
> | §1.1 transport | `managerUrl` → a PHP proxy that does not exist | **Shipped.** All nine copies talk to Parse directly |
> | §1.2 registry shapes | three incompatible shapes | **One served file, v4**, fetched at runtime by all eight factories |
> | §1.3 permissions | `MemberRegistry` wide open | **`MemberRegistry` is deleted.** `MemberResource` is wide open instead — same hole, one class along |
> | §1.4 caller-supplied `tier` | finding stands | Still true, still deliberate — [03](03-parse-cloud-functions.md) §3.4.3 |
> | §1.5 no allocation logic in Parse | finding stands | Still true. `cloud/functions.ts` is the stock scaffold |
> | §1.6 client drift | seven apps, drifted | **Nine copies, byte-identical**, kept so by `bin/sync-checkpoint-client.sh` |
>
> Read it for the reasoning, not for the current state. [03](03-parse-cloud-functions.md) §3.9
> has the delta.

Everything below was read from the repo, not assumed. Where something is missing or contradictory, that is stated as a finding rather than smoothed over.

---

## 1.1 The transport that only works inside one app

`alloc_v2.ts`'s `AllocV2Config.managerUrl` is documented as:

> URL of the `AllocationV2Manager` PHP endpoint on **this** server. Can be a relative path (e.g. `/alloc/v2`)… Use a relative path when the PHP manager is mounted on the same origin as the page — this guarantees the same-origin fetch and prevents CORS errors.

Members' factory (`resources/js/checkpointHelper/static/registry.ts`) supplies `'/portal/api/v1/alloc/v2'`.

Three consequences:

| Consequence | Detail |
|---|---|
| **Non-web clients are excluded** | A desktop or mobile client has no same-origin PHP endpoint and no way to discover which app server to address. There is no configuration that makes `managerUrl` meaningful for them. |
| **The proxy does not exist** | Searching every app finds only `AllocationV2Controller.php` (in `auth` and `www`). `AllocationV2Manager.php` is named in the docblocks of both `alloc_v2.ts` and `AllocationV2Controller.php` and is implemented nowhere. Any app adopting alloc_v2 today must first write it. |
| **Three hops for one object read** | browser → app server → auth → Parse. |

The module docblock argues the proxy exists to keep Parse credentials out of the browser and to avoid Parse's `allowedOrigins` CORS list. Both are real concerns and both are addressed in [03](03-parse-cloud-functions.md) — an Application ID is not a secret (the master key is, and never leaves the server), and CORS is a Parse-server configuration item, not a reason to require a PHP hop per application.

## 1.2 Three incompatible registry shapes

This is the finding most likely to cost a day if discovered during implementation. **Three different pictures of the same data exist:**

**A — the Parse server's declared schema** (`apps/auth/public/checkpoint/parse/cloud/schema.ts`):

```ts
className: 'Registry',                    // ← not "MemberRegistry"
fields: {
  name: { type: 'String' },
  domains: { type: 'Number' },            // ← a count, not a list
  domain_email_accounts: { type: 'Object' },
  domain_processors: { type: 'Object' },
  domain_forms: { type: 'Object' },
  domain_subscribers: { type: 'Object' },
  domain_events: { type: 'Object' },
  domain_ecommerce: { type: 'Object' },
  domain_sponsorships: { type: 'Object' },
  sync_storage: { type: 'Number' },
}
```

**B — the scratch client** (`members/…/checkpointHelper/static/registry.ts`, `RegistryMemberManager`): class `MemberRegistry`, fields as in A, with `domain_processors` treated as a map keyed by the member's `SystemUser` subdomain property.

**C — what `AllocationV2Controller` and `parse_member_query.json` describe**: class `MemberRegistry`, fields

```jsonc
{
  "member_resources": { "<resourceId>": { "key": "...", "used": 0, "periodStart"?: {...} } },
  "domains":     [ { "name": "...", "status": 1, "createdAt": "...", "resources": {...} } ],
  "pm_projects": [ { "name": "...", "createdAt": "...", "resources": {...} } ]
}
```

with resource ids matching `checkpoint_registry_v3.json` (`domain_email_sends_monthly`, `pm_project_kanban_boards`, …) and mirrored in each app's `checkpoint/resources.ts` as `AllocV2Resources`.

**C is the target.** It is the shape the v3 registry, the controller, the sample query, and `resources.ts` all agree on. A and B are earlier iterations. Note the class name differs between A (`Registry`) and B/C (`MemberRegistry`) — deploying the declared schema as-is creates a class nothing reads.

**`RegistryMemberManager` was written as scratch code** — confirmed with the author, to play with the Parse API — but it is **no longer unwired**. It is constructed on every portal boot (`www/resources/js/portal/admin/main.ts:535`), supplies the "Not Authorized" dialog in Direct Payments (`directpayments.ts:160`), and is the entire data path for the Brain member Allocations tab (`brain_membership.ts:1204`, `:1257` — see [../members_brain/01-current-state-audit.md](../members_brain/01-current-state-audit.md) §1.1). Treat it as live code with scratch-code provenance, which is the more dangerous combination: it looks disposable and is not. It is valuable as a worked example of the SDK calls (`Parse.initialize`, `Parse.Object.extend`, `Parse.Query.get`, `save`) and as the source of two facts used throughout this plan — the Parse domain, and that **`member.parse_alloc` holds the member's `MemberRegistry` objectId** — but nothing in it should be ported wholesale, and its field handling is shape B.

## 1.3 Class-level permissions are wide open

```ts
classLevelPermissions: {
  find:   { '*': true },
  count:  { '*': true },
  get:    { '*': true },
  update: { '*': true },
  create: { '*': true },
  delete: { '*': true },
}
```

Public read *and* write on the class that stores every member's usage counters. Today the exposure is limited because no shipped client holds Parse credentials. **The moment a client initialises Parse directly, this becomes: anyone with an Application ID and an objectId can read or rewrite any member's allocation** — reset their usage to zero, or exhaust a competitor's quota. `find` being public compounds it: an attacker does not even need to know an objectId, they can enumerate.

Locking this down is a hard prerequisite of the refactor, not a follow-up ([03](03-parse-cloud-functions.md) §3.3).

## 1.4 `tier` is caller-supplied

`AllocationV2Controller`'s docblock is explicit:

> This controller performs no auth-DB lookups. The caller … must include two fields in every request: `parseObjectId`, `tier` (0-4).

With a PHP proxy in front, "the caller" was a server. With a direct client, "the caller" is
the browser or the desktop app, and a self-declared tier is worth nothing for anything
security-bearing.

> **Amended.** An earlier version of this section required `tier` to be **derived
> server-side**, and [03](03-parse-cloud-functions.md) §3.4 was where that would happen.
> **That is no longer the design.** Tier resolution stays in `alloc_v2`, which reads the
> registry and passes the resulting ceiling down to Parse as a `limit` parameter — see
> [03](03-parse-cloud-functions.md) §3.5 for the split and §3.4.3 for what it leaves open.
>
> The finding above is unchanged and still accurate: **`tier` is caller-supplied, and
> allocation is therefore advisory** ([../allocation.md](../allocation.md) §7). What changed
> is that closing that is no longer scoped to this folder. The server-derived answer lives at
> [../billing/09-entitlement-integration.md](../billing/09-entitlement-integration.md) §9.3,
> and anything that must not be forgeable should call it rather than trusting a tier from a
> browser.

## 1.5 No allocation logic exists in Parse

`cloud/functions.ts` is 25 lines of scaffolding — a `hello` function, a `helloAsyncFunction`, and a `beforeSave('TestObject')` hook. `cloud/main.ts` is three lines. There is **no** check/consume/release logic on the Parse side today; all of it is in `AllocationV2Controller`.

What has to exist on the Parse side is narrower than "the logic": it is the **write**. A
client can decide whether a member may create one more, but it cannot compare and increment
in a single operation, so two callers can both pass the check and both write. That one step
is what [03](03-parse-cloud-functions.md) builds — a conditional increment and nothing else.
The check itself stays in `alloc_v2`.

## 1.6 Client drift across the seven apps

`resources/js/checkpoint/` is duplicated in `members`, `coordinator`, `dream_graph`, `notes`, `pdf_editor`, `flow`, and `www`, and is meant to be identical.

| App | `alloc_v2.ts` | Notes |
|---|---|---|
| members, coordinator, dream_graph, notes, pdf_editor, flow | 754 lines, identical hash | The de-facto canonical version |
| **www** | **752 lines, different hash** | Drifted |

The www delta is small but real:

```ts
// six apps (TS strict / exactOptionalPropertyTypes safe)
if (statusCode !== undefined) {
    this.statusCode = statusCode;
}

// www
this.statusCode = statusCode;
```

**www is also missing `app-names.ts`** entirely, which the other six carry.

Worth stating plainly: the refactor target named for this work is www's copy, and www's copy is the outlier. Reconcile first — take the six-app version as the base, apply the refactor to it, then distribute (see [04](04-distribution-and-parity.md)). Doing it the other way round propagates the drift to six apps instead of removing it from one.

## 1.7 What is already in place

Not everything needs building:

- **The Parse JS SDK is a dependency in all seven apps** (`"parse": "^8.1.0"`). No new dependency, no bundle-size argument to have.
- **`resources.ts` exists in all seven** with the v3 resource ids as `AllocV2Resources` — the constant map the refactored client should keep using.
- **`checkpoint_registry_v3.json`** is the tier/limit source of truth, with `tiers[0..4]` per resource, `-1` for unlimited, and `enabled: false` for "not available at this tier". **(Since this audit: `checkpoint_registry_v4.json` is the served file and every consumer resolves it at runtime — see [03](03-parse-cloud-functions.md) §3.8.1. The structure described here is unchanged in v4.)**
- **`member.parse_alloc`** already carries the `MemberRegistry` objectId, and `member.membership` the tier index.
